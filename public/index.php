<?php
/**
 * k6 Load Testing — task trigger
 *
 * Routes:
 *   GET  /                  landing page: run-test form + CLI reference + reports link
 *   POST /  action=run_task trigger the loadtesting-k6 Upsun task via CLI
 *
 * Requires one project-level env var (never committed here):
 *   upsun variable:create -l project --prefix env: --name UPSUN_CLI_TOKEN \
 *     --value "<api-token>" --sensitive true --visible-build false
 *
 * The Upsun CLI is installed into bin/upsun by the app's build hook.
 */

session_start();

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ── Upsun CLI helper ──────────────────────────────────────────────────────────

/**
 * Shell out to the Upsun CLI to trigger the task (fire-and-forget).
 * proc_open() with an array avoids shell injection entirely.
 * Returns ['ok' => bool, 'output' => string, 'exitCode' => int].
 */
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

$apiToken      = (string) (getenv('UPSUN_CLI_TOKEN') ?: '');
$environmentId = (string) (getenv('PLATFORM_ENVIRONMENT') ?: '');
$cliBin        = rtrim((string) (getenv('PLATFORM_APP_DIR') ?: ''), '/') . '/bin/upsun';
$canTrigger    = $apiToken !== '' && is_executable($cliBin);

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
                    $adminVus = max(0, min(50, (int) ($_POST['admin_vus'] ?? 2)));
                    $vars['env:ADMIN_VUS'] = (string) $adminVus;
                }

                if ($profile !== 'smoke') {
                    $duration = $_POST['duration'] ?? '3m';
                    $vars['env:DURATION'] = preg_match('/^\d+[smh]$/', $duration) ? $duration : '3m';
                }
            }

            $urlOverride = trim($_POST['url_override'] ?? '');
            if ($urlOverride !== '' && preg_match('#^https?://#', $urlOverride)) {
                $vars['env:URL'] = rtrim($urlOverride, '/');
            }

            $triggerResult             = runTaskViaCli($cliBin, 'loadtesting-k6', $vars);
            $triggerResult['profile']  = $profile;
            $triggerResult['vars']     = $vars;
        }
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$csrf = $h($_SESSION['csrf']);

// ── Build the page sections ───────────────────────────────────────────────────

// Result banner
$bannerHtml = '';
if ($triggerResult !== null) {
    if ($triggerResult['ok']) {
        $profile = $h($triggerResult['profile'] ?? '');
        $vars    = $triggerResult['vars'] ?? [];
        $varList = implode('&nbsp; ', array_map(
            fn($k, $v) => '<code>' . $h($k) . '=' . $h($v) . '</code>',
            array_keys($vars), $vars
        ));
        $cliOut  = $triggerResult['output'] !== ''
            ? '<pre class="result-pre">' . $h($triggerResult['output']) . '</pre>' : '';
        $bannerHtml = <<<HTML
        <div class="banner banner-ok">
          <strong>Task queued</strong> — profile: <code>{$profile}</code><br>
          <span class="banner-vars">{$varList}</span>
          {$cliOut}
          <p class="banner-hint">Follow progress in the
            <a href="https://console.upsun.com/" target="_blank" rel="noopener">Upsun console</a>
            or run <code>upsun activity:log</code>.
            Results appear in <a href="/k6-reports/">Reports</a> once the run finishes.</p>
        </div>
        HTML;
    } else {
        $output = $h($triggerResult['output'] ?? 'Unknown error');
        $exit   = isset($triggerResult['exitCode']) && $triggerResult['exitCode'] >= 0
            ? ' <span class="exit-code">exit ' . (int) $triggerResult['exitCode'] . '</span>' : '';
        $bannerHtml = <<<HTML
        <div class="banner banner-err">
          <strong>Failed to start task{$exit}</strong>
          <pre class="result-pre">{$output}</pre>
        </div>
        HTML;
    }
}

// Trigger form or setup notice
if ($canTrigger) {
    $profiles = ['combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke', 'cleanup'];
    $optHtml  = implode('', array_map(
        fn($p) => '<option value="' . $p . '">' . $p . '</option>',
        $profiles
    ));
    $envLabel = $environmentId !== '' ? ' on <code>' . $h($environmentId) . '</code>' : '';

    $formHtml = <<<HTML
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
                 value="3m" placeholder="e.g. 3m, 10m, 1h" pattern="\\d+[smh]">
        </div>
        <div class="form-group form-group-wide">
          <label for="f-url">
            URL override
            <span class="label-hint">(optional — uses default if blank)</span>
          </label>
          <input id="f-url" name="url_override" type="url" class="form-control"
                 placeholder="https://staging.yourshop.com">
        </div>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn-primary-alt">
          <span class="text">Start task</span>
        </button>
        <button type="button" class="btn-secondary" onclick="runCleanup()">
          Run cleanup
        </button>
      </div>
    </form>

    {$bannerHtml}

    <script>
    function toggleFields(profile) {
      var noLoad  = ['cleanup'];
      var noAdmin = ['storefront', 'crawler'];
      var noDur   = ['smoke'];
      document.getElementById('fg-vus').style.display       = noLoad.includes(profile)  ? 'none' : '';
      document.getElementById('fg-admin-vus').style.display = (noLoad.includes(profile) || noAdmin.includes(profile)) ? 'none' : '';
      document.getElementById('fg-duration').style.display  = (noLoad.includes(profile) || noDur.includes(profile))  ? 'none' : '';
    }
    function runCleanup() {
      document.getElementById('f-profile').value = 'cleanup';
      toggleFields('cleanup');
      document.getElementById('run-form').submit();
    }
    toggleFields(document.getElementById('f-profile').value);
    </script>
    HTML;

    $triggerSection = <<<HTML
    <section class="card">
      <h2>Run a load test{$envLabel}</h2>
      <p class="section-intro">
        Triggers the <code>loadtesting-k6</code> Upsun task in its own container —
        the web UI stays responsive. Results appear in
        <a href="/k6-reports/">Reports</a> once done.
      </p>
      {$formHtml}
    </section>
    HTML;

} else {
    $cliMissing   = !is_executable($cliBin);
    $tokenMissing = $apiToken === '';
    $why = match(true) {
        $cliMissing && $tokenMissing => 'the Upsun CLI is not installed and <code>UPSUN_CLI_TOKEN</code> is not set',
        $cliMissing                  => 'the Upsun CLI was not found at <code>' . $h($cliBin) . '</code> — redeploy to install it',
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
/* ── Reset & tokens ─────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:          #0E1113;
  --surface:     #191C1E;
  --border:      #2B3134;
  --border-hi:   #41484D;
  --text:        #E1E3E4;
  --text-muted:  #B4BABD;
  --text-dim:    #646F75;
  --purple:      #6046FF;
  --purple-hover:#4D38CC;
  --purple-light:#806BFF;
  --purple-btn-text: #EFEDF5;
  --accent:      #D5F800;
  --ok-bg:       #1a2e1a;
  --ok-border:   #2d5a2d;
  --ok-text:     #86efac;
  --err-bg:      #2e1a1a;
  --err-border:  #5a2d2d;
  --err-text:    #fca5a5;
  --font:        'Space Grotesk', 'Instrument Sans', sans-serif;
  --mono:        'Space Mono', ui-monospace, monospace;
}

[data-theme="light"] {
  --bg:         #F7F8F8;
  --surface:    #ffffff;
  --border:     #E1E3E4;
  --border-hi:  #B4BABD;
  --text:       #2B3134;
  --text-muted: #646F75;
  --text-dim:   #8A9399;
  --purple:     #4D38CC;
  --purple-hover:#3a2b99;
  --purple-light:#6046FF;
  --ok-bg:      #d4edda;
  --ok-border:  #a8d5b5;
  --ok-text:    #155724;
  --err-bg:     #f8d7da;
  --err-border: #f5c6c2;
  --err-text:   #721c24;
}

/* ── Base ───────────────────────────────────────────────────────────────── */
html { font-size: 16px; scroll-behavior: smooth; }

body {
  background-color: var(--bg);
  color: var(--text);
  font-family: var(--font);
  font-size: 16px;
  line-height: 1.6;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  transition: background-color .3s, color .3s;
}

a           { color: var(--purple-light); text-decoration: none; transition: color .2s; }
a:hover     { color: var(--purple); text-decoration: underline; }
strong      { color: var(--text-muted); font-weight: 700; }
code {
  font-family: var(--mono);
  font-size: .85em;
  background: var(--border);
  color: var(--text);
  padding: .1em .4em;
  border-radius: 3px;
}

/* ── Header ─────────────────────────────────────────────────────────────── */
.site-header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 18px 40px;
  display: flex;
  align-items: center;
  gap: 16px;
  flex-shrink: 0;
}

.header-wordmark {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
}

.header-wordmark svg { display: block; height: 28px; width: auto; }

.header-title {
  font-size: 18px;
  font-weight: 500;
  color: var(--text);
  margin-right: auto;
  transition: color .3s;
}

.header-link {
  color: var(--text);
  font-size: 15px;
  font-weight: 500;
  padding: 7px 14px;
  border-radius: 8px;
  border: 1px solid transparent;
  transition: all .2s;
  text-decoration: none;
}
.header-link:hover { background: var(--border); border-color: var(--border-hi); color: var(--text); text-decoration: none; }

.theme-toggle {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 7px 12px;
  cursor: pointer;
  font-size: 1.1rem;
  line-height: 1;
  color: var(--text);
  transition: all .2s;
  flex-shrink: 0;
}
.theme-toggle:hover { background: var(--border); border-color: var(--border-hi); }

/* ── Main ───────────────────────────────────────────────────────────────── */
.site-main {
  flex: 1;
  padding: 48px 40px 80px;
  max-width: 780px;
  width: 100%;
  margin: 0 auto;
}

.page-title {
  font-size: 42px;
  font-weight: 900;
  line-height: 1.2;
  margin-bottom: 8px;
}

.page-sub {
  color: var(--text-muted);
  font-size: 16px;
  margin-bottom: 40px;
}

/* ── Cards ───────────────────────────────────────────────────────────────── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 28px 32px;
  margin-bottom: 24px;
  transition: background-color .3s, border-color .3s;
}

.card h2 {
  font-size: 20px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}

.section-intro {
  color: var(--text-muted);
  font-size: 15px;
  margin-bottom: 24px;
  font-weight: 400;
}

/* ── Form ────────────────────────────────────────────────────────────────── */
.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
  gap: 12px 16px;
  margin-bottom: 24px;
}

.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group-wide { grid-column: 1 / -1; }

label {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted);
  letter-spacing: .02em;
}
.label-hint { font-weight: 400; color: var(--text-dim); }

.form-control {
  background: var(--bg);
  border: 1px solid var(--border-hi);
  border-radius: 6px;
  color: var(--text);
  font-family: var(--mono);
  font-size: 15px;
  padding: 10px 14px;
  width: 100%;
  transition: border-color .2s, background .3s;
  appearance: none;
}
.form-control:focus {
  outline: 2px solid var(--purple);
  outline-offset: 2px;
  border-color: var(--purple);
}
.form-control::placeholder { color: var(--text-dim); }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn-row { display: flex; gap: 12px; flex-wrap: wrap; }

.btn-primary-alt {
  display: inline-block;
  padding: 13px 28px;
  background: var(--purple);
  color: var(--purple-btn-text);
  font-family: var(--font);
  font-size: 15px;
  font-weight: 700;
  border: none;
  border-radius: 48px;
  cursor: pointer;
  letter-spacing: .03em;
  transition: background .2s;
  text-decoration: none;
}
.btn-primary-alt:hover { background: var(--purple-hover); color: var(--purple-btn-text); text-decoration: none; }
.btn-primary-alt:focus { outline: 2px solid var(--purple); outline-offset: 3px; }

.btn-secondary {
  display: inline-block;
  padding: 13px 28px;
  background: transparent;
  color: var(--text-muted);
  font-family: var(--font);
  font-size: 15px;
  font-weight: 600;
  border: 1px solid var(--border-hi);
  border-radius: 48px;
  cursor: pointer;
  letter-spacing: .03em;
  transition: all .2s;
}
.btn-secondary:hover { background: var(--border); color: var(--text); border-color: var(--border-hi); }

/* ── Banners ─────────────────────────────────────────────────────────────── */
.banner {
  margin-top: 24px;
  padding: 16px 20px;
  border-radius: 8px;
  font-size: 15px;
}
.banner strong { font-weight: 700; }

.banner-ok {
  background: var(--ok-bg);
  border: 1px solid var(--ok-border);
  color: var(--ok-text);
}
.banner-ok strong  { color: var(--ok-text); }
.banner-ok a       { color: var(--ok-text); text-decoration: underline; }
.banner-ok code    { background: rgba(255,255,255,.1); color: var(--ok-text); }

.banner-err {
  background: var(--err-bg);
  border: 1px solid var(--err-border);
  color: var(--err-text);
}
.banner-err strong { color: var(--err-text); }
.banner-err code   { background: rgba(255,255,255,.1); color: var(--err-text); }

.banner-vars { font-size: 13px; display: block; margin-top: 4px; }
.banner-hint { font-size: 13px; margin-top: 10px; font-weight: 400; color: inherit; opacity: .85; }
.banner-hint a { color: inherit; }
.exit-code { font-size: .85em; opacity: .7; }
.result-pre {
  margin-top: 10px;
  background: rgba(0,0,0,.25);
  border: none;
  border-radius: 5px;
  padding: 10px 14px;
  font-family: var(--mono);
  font-size: 13px;
  white-space: pre-wrap;
  word-break: break-all;
  color: inherit;
  opacity: .9;
}

/* ── Notice (setup instructions) ────────────────────────────────────────── */
.notice {
  background: var(--bg);
  border: 1px solid var(--border-hi);
  border-radius: 8px;
  padding: 18px 22px;
  font-size: 14px;
  color: var(--text-muted);
  line-height: 1.7;
  margin-top: 16px;
}
.notice code { font-size: .82em; }

/* ── CLI card ────────────────────────────────────────────────────────────── */
.cli-pre {
  background: var(--bg);
  border: 1px solid var(--border-hi);
  border-radius: 6px;
  padding: 18px 20px;
  font-family: var(--mono);
  font-size: 13px;
  color: var(--text);
  overflow-x: auto;
  white-space: pre;
  margin-top: 16px;
}

/* ── Reports nav card ───────────────────────────────────────────────────── */
.reports-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
}
.reports-link .btn-primary-alt { font-size: 14px; padding: 10px 22px; }

/* ── Footer ─────────────────────────────────────────────────────────────── */
.site-footer {
  background: var(--surface);
  border-top: 1px solid var(--border);
  padding: 24px 40px;
  text-align: center;
  color: var(--text-dim);
  font-size: 14px;
  flex-shrink: 0;
  transition: background-color .3s, border-color .3s;
}
.site-footer .heart { color: var(--accent); }
.site-footer a { color: inherit; }
.site-footer a:hover { text-decoration: underline; color: var(--text); }

/* ── Responsive ─────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
  .site-header { padding: 14px 20px; gap: 10px; }
  .header-title { font-size: 15px; }
  .site-main { padding: 32px 20px 60px; }
  .page-title { font-size: 28px; }
  .card { padding: 20px; }
}
  </style>
</head>
<body>

<header class="site-header">
  <a href="/" class="header-wordmark" aria-label="k6 Load Testing">
    <!-- Upsun wordmark SVG (inlined, colour-adaptive) -->
    <svg viewBox="0 0 80 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <text x="0" y="20" font-family="'Space Grotesk',sans-serif" font-size="22" font-weight="900"
            fill="currentColor" style="fill:var(--text)">upsun</text>
    </svg>
  </a>
  <span class="header-title">k6 Load Testing</span>
  <a href="/k6-reports/" class="header-link">Reports</a>
  <button class="theme-toggle" id="theme-toggle" aria-label="Toggle theme" title="Toggle light/dark">
    <span id="theme-icon">🌙</span>
  </button>
</header>

<main class="site-main">

  <h1 class="page-title">k6 Magento Load Testing</h1>
  <p class="page-sub">
    A k6 port of the Magento Performance Toolkit — runs as an on-demand Upsun task
    from its own container, keeping your store container free.
  </p>

  <?= $triggerSection ?>

  <section class="card">
    <h2>CLI commands</h2>
    <p class="section-intro">
      Run tasks directly with the Upsun CLI. Pass <code>--wait</code> to block until the task finishes.
    </p>
    <pre class="cli-pre"># Smoke test — one pass of every flow
upsun task:run loadtesting-k6 --variable env:PROFILE=smoke --wait

# Combined load (default config: 25 VUs, 3 min)
upsun task:run loadtesting-k6 --wait

# Custom run
upsun task:run loadtesting-k6 \
  --variable env:PROFILE=storefront \
  --variable env:VUS=30 \
  --variable env:DURATION=15m \
  --wait

# Cleanup k6 test data
upsun task:run loadtesting-k6 --variable env:PROFILE=cleanup --wait</pre>
  </section>

  <section class="card">
    <h2>Reports</h2>
    <p class="section-intro">
      Each run writes an HTML dashboard, JSON metrics, a failure log and a console log
      to shared storage. Browse all runs below.
    </p>
    <div class="reports-link">
      <a href="/k6-reports/" class="btn-primary-alt">
        <span class="text">Open Reports →</span>
      </a>
    </div>
  </section>

</main>

<footer class="site-footer">
  made with <span class="heart">♥</span> by <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
</footer>

<script>
// ── Light / dark toggle ──────────────────────────────────────────────────────
(function () {
  var html  = document.documentElement;
  var btn   = document.getElementById('theme-toggle');
  var icon  = document.getElementById('theme-icon');

  function apply(theme) {
    html.setAttribute('data-theme', theme);
    icon.textContent = theme === 'dark' ? '🌙' : '☀️';
  }

  var stored = localStorage.getItem('theme');
  apply(stored || 'dark');

  btn.addEventListener('click', function () {
    var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    localStorage.setItem('theme', next);
    apply(next);
  });
})();
</script>

</body>
</html>
