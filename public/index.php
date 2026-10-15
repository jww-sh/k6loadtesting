<?php
/**
 * k6 Load Testing — task trigger + inline run history
 *
 * Routes:
 *   GET  /                  landing page: form + CLI ref + all recent runs
 *   POST /  action=run_task trigger the loadtesting-k6 Upsun task via CLI
 *
 * Individual run files and the failures detail page are still served from
 * /k6-reports/<run>/* via k6/web/index.php.
 *
 * Task trigger requires one project-level env var:
 *   upsun variable:create -l project --prefix env: --name UPSUN_CLI_TOKEN \
 *     --value "<api-token>" --sensitive true --visible-build false
 */

session_start();

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ── Upsun CLI helper ──────────────────────────────────────────────────────────

function runTaskViaCli(string $cliBin, string $taskId, array $variables): array
{
    $cmd = [$cliBin, 'task:run', $taskId, '--yes'];
    foreach ($variables as $key => $value) {
        $cmd[] = '--variable';
        $cmd[] = "$key=$value";
    }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'output' => 'Could not start the Upsun CLI process.', 'exitCode' => -1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit   = proc_close($proc);
    $output = trim($stdout . ($stderr !== '' ? "\n$stderr" : ''));
    return ['ok' => $exit === 0, 'output' => $output, 'exitCode' => $exit];
}

// ── Capability detection ──────────────────────────────────────────────────────

$appDir        = rtrim((string) (getenv('PLATFORM_APP_DIR') ?: ''), '/');
$apiToken      = (string) (getenv('UPSUN_CLI_TOKEN') ?: '');
$environmentId = (string) (getenv('PLATFORM_ENVIRONMENT') ?: '');
$cliBin        = $appDir . '/bin/upsun';
$canTrigger    = $apiToken !== '' && is_executable($cliBin);
$reportsBase   = realpath($appDir . '/var/k6-reports') ?: '';

// ── CSRF ─────────────────────────────────────────────────────────────────────

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

// ── POST handler ──────────────────────────────────────────────────────────────

$triggerResult = null;

if ($canTrigger
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'run_task'
) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $triggerResult = ['ok' => false, 'output' => 'Invalid security token — please reload and try again.', 'exitCode' => -1];
    } else {
        $allowedProfiles = ['combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke', 'cleanup'];
        $profile = $_POST['profile'] ?? 'combined';

        if (!in_array($profile, $allowedProfiles, true)) {
            $triggerResult = ['ok' => false, 'output' => "Unknown profile '$profile'.", 'exitCode' => -1];
        } else {
            $vars = ['env:PROFILE' => $profile];
            if ($profile !== 'cleanup') {
                $vus = max(1, min(500, (int) ($_POST['vus'] ?? 25)));
                $vars['env:VUS'] = (string) $vus;
                if (in_array($profile, ['combined', 'graphql', 'admin', 'smoke'], true)) {
                    $vars['env:ADMIN_VUS'] = (string) max(0, min(50, (int) ($_POST['admin_vus'] ?? 2)));
                }
                if ($profile !== 'smoke') {
                    $dur = $_POST['duration'] ?? '3m';
                    $vars['env:DURATION'] = preg_match('/^\d+[smh]$/', $dur) ? $dur : '3m';
                }
            }
            $urlOverride = trim($_POST['url_override'] ?? '');
            if ($urlOverride !== '' && preg_match('#^https?://#', $urlOverride)) {
                $vars['env:URL'] = rtrim($urlOverride, '/');
            }
            $triggerResult = runTaskViaCli($cliBin, 'loadtesting-k6', $vars);
            $triggerResult['profile'] = $profile;
            $triggerResult['vars']    = $vars;
        }
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$csrf = $h($_SESSION['csrf']);

// ── Reports helper ────────────────────────────────────────────────────────────

function runsTable(string $base, callable $h): string
{
    if ($base === '' || !is_dir($base)) {
        return '<p class="no-runs">No runs yet — start a load test above.</p>';
    }

    $runs = array_values(array_filter(
        scandir($base, SCANDIR_SORT_DESCENDING),
        fn($d) => $d[0] !== '.' && $d !== 'latest' && is_dir("$base/$d")
    ));

    if (!$runs) {
        return '<p class="no-runs">No runs yet — start a load test above.</p>';
    }

    $rows = '';
    foreach ($runs as $run) {
        [$stamp, $profile] = array_pad(explode('-', $run, 2), 2, '');
        $ts   = preg_replace('/^(\d{4})(\d\d)(\d\d)T(\d\d)(\d\d)(\d\d)Z$/', '$1-$2-$3&nbsp;$4:$5:$6', $stamp);
        $info = is_file("$base/$run/run-info.json")
            ? json_decode(file_get_contents("$base/$run/run-info.json"), true) : null;

        if (is_array($info)) {
            $code   = (int) ($info['exit_code'] ?? -1);
            $result = match (true) {
                $code === 0  => '<span class="badge badge-ok">passed</span>',
                $code === 99 => '<span class="badge badge-warn">thresholds</span>',
                default      => '<span class="badge badge-err">failed&nbsp;(' . $code . ')</span>',
            };
            $interrupted = (int) ($info['iterations_interrupted'] ?? 0);
            $complete    = (int) ($info['iterations_complete'] ?? 0);
            $iters = $h((string) $complete) . '&nbsp;done';
            if ($interrupted) {
                $iters .= '&nbsp;<span class="run-bad">' . $h((string) $interrupted) . '&nbsp;interrupted</span>';
            }
        } else {
            $result = '<span class="run-na">—</span>';
            $iters  = '<span class="run-na">—</span>';
        }

        $failFile = "$base/$run/failures.jsonl";
        $failCount = is_file($failFile) ? count(file($failFile, FILE_SKIP_EMPTY_LINES)) : null;
        $failCell  = $failCount === null
            ? '<span class="run-na">—</span>'
            : ($failCount > 0
                ? '<a class="run-bad" href="/k6-reports/' . rawurlencode($run) . '/failures">' . $failCount . '</a>'
                : '<span class="run-ok">0</span>');

        $links = [];
        foreach (['report.html' => 'report', 'summary.json' => 'summary', 'k6.log' => 'log', 'run-info.json' => 'info'] as $f => $label) {
            if (is_file("$base/$run/$f")) {
                $links[] = '<a href="/k6-reports/' . rawurlencode($run) . '/' . $f . '">' . $label . '</a>';
            }
        }

        $rows .= <<<ROW
        <tr>
          <td class="td-ts">{$ts}</td>
          <td><code class="run-profile">{$h($profile)}</code></td>
          <td>{$result}</td>
          <td class="td-iters">{$iters}</td>
          <td class="td-fail">{$failCell}</td>
          <td class="td-files">{$h(implode(' ', []))}
        ROW;
        $rows .= implode('&ensp;', $links) . '</td></tr>';
    }

    return <<<HTML
    <div class="table-wrap">
      <table class="runs-table">
        <thead>
          <tr>
            <th>Run (UTC)</th><th>Profile</th><th>Result</th>
            <th>Iterations</th><th>Failures</th><th>Files</th>
          </tr>
        </thead>
        <tbody>{$rows}</tbody>
      </table>
    </div>
    HTML;
}

// ── Build page sections ───────────────────────────────────────────────────────

// Banner
$bannerHtml = '';
if ($triggerResult !== null) {
    if ($triggerResult['ok']) {
        $profile = $h($triggerResult['profile'] ?? '');
        $vars    = $triggerResult['vars'] ?? [];
        $varList = implode('&ensp;', array_map(
            fn($k, $v) => '<code>' . $h($k) . '=' . $h($v) . '</code>',
            array_keys($vars), $vars
        ));
        $cliOut  = $triggerResult['output'] !== ''
            ? '<pre class="result-pre">' . $h($triggerResult['output']) . '</pre>' : '';
        $bannerHtml = <<<HTML
        <div class="banner banner-ok">
          <strong>Task queued</strong> — profile: <code>{$profile}</code><br>
          <span class="banner-meta">{$varList}</span>
          {$cliOut}
          <span class="banner-hint">Follow in the
            <a href="https://console.upsun.com/" target="_blank" rel="noopener">Upsun console</a>
            or <code>upsun activity:log</code>.
            Results appear in the runs table below once finished.</span>
        </div>
        HTML;
    } else {
        $out  = $h($triggerResult['output'] ?? 'Unknown error');
        $exit = isset($triggerResult['exitCode']) && $triggerResult['exitCode'] >= 0
            ? ' <span class="exit-code">exit ' . (int) $triggerResult['exitCode'] . '</span>' : '';
        $bannerHtml = '<div class="banner banner-err"><strong>Failed to start task' . $exit . '</strong><pre class="result-pre">' . $out . '</pre></div>';
    }
}

// Trigger form or setup notice
if ($canTrigger) {
    $envLabel = $environmentId !== '' ? ' on <code>' . $h($environmentId) . '</code>' : '';
    $profiles = ['combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke', 'cleanup'];
    $optHtml  = implode('', array_map(fn($p) => '<option value="' . $p . '">' . $p . '</option>', $profiles));

    $triggerSection = <<<HTML
    <section class="card">
      <h2>Run a load test{$envLabel}</h2>
      <p class="section-intro">
        Triggers the <code>loadtesting-k6</code> task in its own container —
        the web UI stays responsive.
      </p>

      <form method="post" action="/" id="run-form">
        <input type="hidden" name="action" value="run_task">
        <input type="hidden" name="csrf"   value="{$csrf}">

        <div class="form-grid">
          <div class="form-group">
            <label for="f-profile">Profile</label>
            <select id="f-profile" name="profile" class="form-control" onchange="toggleFields(this.value)">
              {$optHtml}
            </select>
          </div>
          <div class="form-group" id="fg-vus">
            <label for="f-vus">VUs</label>
            <input id="f-vus" name="vus" type="number" class="form-control" min="1" max="500" value="25">
          </div>
          <div class="form-group" id="fg-admin-vus">
            <label for="f-admin-vus">Admin VUs</label>
            <input id="f-admin-vus" name="admin_vus" type="number" class="form-control" min="0" max="50" value="2">
          </div>
          <div class="form-group" id="fg-duration">
            <label for="f-duration">Duration</label>
            <input id="f-duration" name="duration" type="text" class="form-control"
                   value="3m" placeholder="3m / 10m / 1h" pattern="\\d+[smh]">
          </div>
          <div class="form-group form-group-wide">
            <label for="f-url">URL override <span class="label-hint">(optional)</span></label>
            <input id="f-url" name="url_override" type="url" class="form-control"
                   placeholder="https://staging.yourshop.com">
          </div>
        </div>

        <div class="btn-row">
          <button type="submit" class="btn-primary-alt"><span class="text">Start task</span></button>
          <button type="button" class="btn-secondary" onclick="runCleanup()">Run cleanup</button>
        </div>
      </form>

      {$bannerHtml}

      <script>
      function toggleFields(p) {
        var noLoad  = ['cleanup'];
        var noAdmin = ['storefront','crawler'];
        var noDur   = ['smoke'];
        document.getElementById('fg-vus').style.display       = noLoad.includes(p) ? 'none' : '';
        document.getElementById('fg-admin-vus').style.display = (noLoad.includes(p)||noAdmin.includes(p)) ? 'none' : '';
        document.getElementById('fg-duration').style.display  = (noLoad.includes(p)||noDur.includes(p)) ? 'none' : '';
      }
      function runCleanup() {
        document.getElementById('f-profile').value = 'cleanup';
        toggleFields('cleanup');
        document.getElementById('run-form').submit();
      }
      toggleFields(document.getElementById('f-profile').value);
      </script>
    </section>
    HTML;

} else {
    $cliMissing   = !is_executable($cliBin);
    $tokenMissing = $apiToken === '';
    $why = match (true) {
        $cliMissing && $tokenMissing => 'CLI not installed and <code>UPSUN_CLI_TOKEN</code> not set',
        $cliMissing                  => 'CLI not found at <code>' . $h($cliBin) . '</code> — redeploy to install it',
        default                      => '<code>UPSUN_CLI_TOKEN</code> is not set',
    };
    $triggerSection = <<<HTML
    <section class="card">
      <h2>Run a load test</h2>
      <p class="section-intro">Task triggering is disabled — {$why}.</p>
      <div class="notice">
        Create an API token in the
        <a href="https://console.upsun.com/" target="_blank" rel="noopener">Upsun console</a>
        (Profile &rarr; API Tokens), then store it as a project variable:<br><br>
        <code>upsun variable:create -l project --prefix env: --name UPSUN_CLI_TOKEN \<br>
        &nbsp;&nbsp;--value "&lt;token&gt;" --sensitive true --visible-build false</code><br><br>
        Then redeploy so the build hook installs the CLI.
      </div>
    </section>
    HTML;
}

$runsHtml = runsTable($reportsBase, $h);

?><!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>k6 Load Testing</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700;900&family=Space+Mono&display=swap" rel="stylesheet">
  <style>
/* ── Tokens ─────────────────────────────────────────────────────────────── */
:root {
  --bg:           #0E1113;
  --surface:      #191C1E;
  --border:       #2B3134;
  --border-hi:    #41484D;
  --text:         #E1E3E4;
  --text-muted:   #B4BABD;
  --text-dim:     #646F75;
  --purple:       #6046FF;
  --purple-hover: #4D38CC;
  --purple-light: #806BFF;
  --purple-btn:   #EFEDF5;
  --accent:       #D5F800;
  --ok-bg:        #1a2e1a;
  --ok-border:    #2d5a2d;
  --ok-text:      #86efac;
  --err-bg:       #2e1a1a;
  --err-border:   #5a2d2d;
  --err-text:     #fca5a5;
  --warn-bg:      #2a2510;
  --warn-border:  #5a4f20;
  --warn-text:    #fde68a;
  --font:         'Space Grotesk','Instrument Sans',sans-serif;
  --mono:         'Space Mono',ui-monospace,monospace;
}
[data-theme="light"] {
  --bg:          #F7F8F8;
  --surface:     #ffffff;
  --border:      #E1E3E4;
  --border-hi:   #B4BABD;
  --text:        #2B3134;
  --text-muted:  #646F75;
  --text-dim:    #8A9399;
  --purple:      #4D38CC;
  --purple-hover:#3a2b99;
  --purple-light:#6046FF;
  --ok-bg:       #d4edda; --ok-border:#a8d5b5; --ok-text:#155724;
  --err-bg:      #f8d7da; --err-border:#f5c6c2; --err-text:#721c24;
  --warn-bg:     #fff3cd; --warn-border:#ffc107; --warn-text:#856404;
}

/* ── Reset ──────────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--font);
     line-height:1.6;min-height:100vh;display:flex;flex-direction:column;
     transition:background .3s,color .3s}
a{color:var(--purple-light);text-decoration:none;transition:color .2s}
a:hover{color:var(--purple);text-decoration:underline}
strong{color:var(--text-muted);font-weight:700}
code{font-family:var(--mono);font-size:.84em;background:var(--border);
     color:var(--text);padding:.1em .4em;border-radius:3px}

/* ── Header ─────────────────────────────────────────────────────────────── */
.site-header{background:var(--surface);border-bottom:1px solid var(--border);
  padding:18px 40px;display:flex;align-items:center;gap:16px;flex-shrink:0;
  transition:background .3s,border-color .3s}
.header-wordmark{display:flex;align-items:center;gap:10px;text-decoration:none}
.header-logo{height:26px;width:auto;display:block}
.header-title{font-size:17px;font-weight:500;color:var(--text);
  margin-right:auto;transition:color .3s}
.header-link{color:var(--text);font-size:14px;font-weight:500;padding:7px 14px;
  border-radius:8px;border:1px solid transparent;transition:all .2s;text-decoration:none}
.header-link:hover{background:var(--border);border-color:var(--border-hi);
  color:var(--text);text-decoration:none}
.theme-toggle{background:transparent;border:1px solid var(--border);border-radius:8px;
  padding:7px 12px;cursor:pointer;font-size:1.05rem;line-height:1;color:var(--text);
  transition:all .2s;flex-shrink:0}
.theme-toggle:hover{background:var(--border);border-color:var(--border-hi)}

/* ── Layout ─────────────────────────────────────────────────────────────── */
.site-main{flex:1;padding:48px 40px 80px;max-width:900px;width:100%;margin:0 auto}
.page-title{font-size:40px;font-weight:900;line-height:1.2;margin-bottom:8px}
.page-sub{color:var(--text-muted);font-size:15px;margin-bottom:40px;font-weight:400}

/* ── Cards ───────────────────────────────────────────────────────────────── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;
  padding:28px 32px;margin-bottom:24px;transition:background .3s,border-color .3s}
.card h2{font-size:19px;font-weight:700;color:var(--text);margin-bottom:8px}
.section-intro{color:var(--text-muted);font-size:14px;margin-bottom:22px;font-weight:400}

/* ── Form ────────────────────────────────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
  gap:12px 16px;margin-bottom:22px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group-wide{grid-column:1 / -1}
label{font-size:12px;font-weight:600;color:var(--text-muted);letter-spacing:.03em;
  text-transform:uppercase}
.label-hint{font-weight:400;text-transform:none;color:var(--text-dim)}
.form-control{background:var(--bg);border:1px solid var(--border-hi);border-radius:6px;
  color:var(--text);font-family:var(--mono);font-size:14px;padding:10px 13px;width:100%;
  transition:border-color .2s,background .3s;appearance:none}
.form-control:focus{outline:2px solid var(--purple);outline-offset:2px;
  border-color:var(--purple)}
.form-control::placeholder{color:var(--text-dim)}

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn-row{display:flex;gap:12px;flex-wrap:wrap}
.btn-primary-alt{display:inline-block;padding:12px 26px;background:var(--purple);
  color:var(--purple-btn);font-family:var(--font);font-size:14px;font-weight:700;
  border:none;border-radius:48px;cursor:pointer;letter-spacing:.03em;
  transition:background .2s;text-decoration:none}
.btn-primary-alt:hover{background:var(--purple-hover);color:var(--purple-btn);
  text-decoration:none}
.btn-primary-alt:focus{outline:2px solid var(--purple);outline-offset:3px}
.btn-secondary{display:inline-block;padding:12px 26px;background:transparent;
  color:var(--text-muted);font-family:var(--font);font-size:14px;font-weight:600;
  border:1px solid var(--border-hi);border-radius:48px;cursor:pointer;
  letter-spacing:.03em;transition:all .2s}
.btn-secondary:hover{background:var(--border);color:var(--text);
  border-color:var(--border-hi)}

/* ── Banners ─────────────────────────────────────────────────────────────── */
.banner{margin-top:20px;padding:16px 20px;border-radius:8px;font-size:14px}
.banner strong{font-weight:700}
.banner-ok{background:var(--ok-bg);border:1px solid var(--ok-border);color:var(--ok-text)}
.banner-ok strong,.banner-ok a{color:var(--ok-text)}
.banner-ok code{background:rgba(255,255,255,.1);color:var(--ok-text)}
.banner-err{background:var(--err-bg);border:1px solid var(--err-border);color:var(--err-text)}
.banner-err strong{color:var(--err-text)}
.banner-meta{font-size:12px;display:block;margin-top:4px;opacity:.9}
.banner-hint{font-size:12px;display:block;margin-top:10px;opacity:.8}
.banner-hint a{color:inherit;text-decoration:underline}
.exit-code{font-size:.85em;opacity:.7}
.result-pre{margin-top:10px;background:rgba(0,0,0,.25);border:none;border-radius:5px;
  padding:10px 14px;font-family:var(--mono);font-size:12px;white-space:pre-wrap;
  word-break:break-all;color:inherit;opacity:.9}

/* ── Notice ──────────────────────────────────────────────────────────────── */
.notice{background:var(--bg);border:1px solid var(--border-hi);border-radius:8px;
  padding:16px 20px;font-size:13px;color:var(--text-muted);line-height:1.7;
  margin-top:14px}
.notice code{font-size:.82em}

/* ── CLI pre ─────────────────────────────────────────────────────────────── */
.cli-pre{background:var(--bg);border:1px solid var(--border-hi);border-radius:6px;
  padding:18px 20px;font-family:var(--mono);font-size:13px;color:var(--text);
  overflow-x:auto;white-space:pre;margin-top:14px}

/* ── Runs table ──────────────────────────────────────────────────────────── */
.table-wrap{overflow-x:auto;margin-top:4px}
.runs-table{width:100%;border-collapse:collapse;font-size:13px}
.runs-table th{padding:9px 12px;border-bottom:2px solid var(--border);
  color:var(--text-dim);font-weight:600;font-size:11px;letter-spacing:.06em;
  text-transform:uppercase;text-align:left;white-space:nowrap}
.runs-table td{padding:12px;border-bottom:1px solid var(--border);
  vertical-align:middle}
.runs-table tbody tr:last-child td{border-bottom:none}
.runs-table tbody tr:hover td{background:rgba(255,255,255,.02)}
.td-ts{font-family:var(--mono);font-size:12px;white-space:nowrap;color:var(--text-muted)}
.td-iters{font-size:12px;color:var(--text-muted)}
.td-fail{font-size:13px;text-align:center}
.td-files{display:flex;gap:8px;flex-wrap:wrap}
.td-files a{font-size:12px;padding:2px 8px;border:1px solid var(--border);
  border-radius:4px;color:var(--text-muted);white-space:nowrap;transition:all .15s}
.td-files a:hover{border-color:var(--purple);color:var(--purple-light);
  text-decoration:none;background:rgba(96,70,255,.08)}
.run-profile{font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;
  font-weight:700;letter-spacing:.03em}
.badge-ok  {background:var(--ok-bg);color:var(--ok-text)}
.badge-err {background:var(--err-bg);color:var(--err-text)}
.badge-warn{background:var(--warn-bg);color:var(--warn-text)}
.run-ok {color:var(--ok-text)}
.run-bad{color:var(--err-text);font-weight:600}
.run-na {color:var(--text-dim)}
.no-runs{color:var(--text-dim);font-style:italic;padding:20px 0;font-size:14px}

/* ── Footer ─────────────────────────────────────────────────────────────── */
.site-footer{background:var(--surface);border-top:1px solid var(--border);
  padding:22px 40px;text-align:center;color:var(--text-dim);font-size:14px;
  flex-shrink:0;transition:background .3s,border-color .3s}
.site-footer .heart{color:var(--accent)}
.site-footer a{color:inherit}
.site-footer a:hover{text-decoration:underline;color:var(--text)}

/* ── Responsive ─────────────────────────────────────────────────────────── */
@media(max-width:640px){
  .site-header{padding:14px 20px;gap:10px}
  .header-title{font-size:14px}
  .site-main{padding:28px 16px 60px}
  .page-title{font-size:26px}
  .card{padding:18px}
}
  </style>
</head>
<body>

<header class="site-header">
  <a href="/" class="header-wordmark" aria-label="k6 Load Testing">
    <svg class="header-logo" viewBox="0 0 72 22" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <text x="0" y="18" font-family="'Space Grotesk',sans-serif" font-size="20"
            font-weight="900" fill="currentColor" style="fill:var(--text)">upsun</text>
    </svg>
  </a>
  <span class="header-title">k6 Load Testing</span>
  <button class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">
    <span id="theme-icon">🌙</span>
  </button>
</header>

<main class="site-main">

  <h1 class="page-title">k6 Load Testing</h1>
  <p class="page-sub">
    Magento Performance Toolkit benchmark — runs as an on-demand Upsun task
    from its own container.
  </p>

  <?= $triggerSection ?>

  <section class="card">
    <h2>CLI commands</h2>
    <p class="section-intro">Run tasks directly — pass <code>--wait</code> to block until done.</p>
    <pre class="cli-pre"># Smoke test
upsun task:run loadtesting-k6 --variable env:PROFILE=smoke --wait

# Combined load (25 VUs, 3 min default)
upsun task:run loadtesting-k6 --wait

# Custom run
upsun task:run loadtesting-k6 \
  --variable env:PROFILE=storefront \
  --variable env:VUS=30 \
  --variable env:DURATION=15m \
  --wait

# Cleanup
upsun task:run loadtesting-k6 --variable env:PROFILE=cleanup --wait</pre>
  </section>

  <section class="card">
    <h2>Runs</h2>
    <p class="section-intro">
      Each run produces an HTML dashboard, JSON metrics, a failure log and a console log.
      Reload this page after a task finishes to see new results.
    </p>
    <?= $runsHtml ?>
  </section>

</main>

<footer class="site-footer">
  made with <span class="heart">♥</span> by
  <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
</footer>

<script>
(function () {
  var html = document.documentElement;
  var btn  = document.getElementById('theme-toggle');
  var icon = document.getElementById('theme-icon');
  function apply(t) {
    html.setAttribute('data-theme', t);
    icon.textContent = t === 'dark' ? '🌙' : '☀️';
  }
  apply(localStorage.getItem('theme') || 'dark');
  btn.addEventListener('click', function () {
    var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    localStorage.setItem('theme', next);
    apply(next);
  });
})();
</script>

</body>
</html>
