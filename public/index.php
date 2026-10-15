<?php
/**
 * k6 Load Testing — single entry point for all routes.
 *
 * Routes:
 *   GET  /                         main page: task trigger + runs table
 *   POST /  action=run_task        fire the loadtesting-k6 Upsun task
 *   GET  /k6-reports/              → redirect to /
 *   GET  /k6-reports/<run>/failures failures detail page
 *   GET  /k6-reports/<run>/<file>  serve report.html / summary.json / k6.log / etc.
 */

$_uri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$h      = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$appDir = rtrim((string) (getenv('PLATFORM_APP_DIR') ?: ''), '/');
$base   = realpath($appDir . '/var/k6-reports'); // string|false

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

// ── Shared CSS ────────────────────────────────────────────────────────────────
$CSS = <<<'CSS'
:root{
  --bg:#0E1113;--surface:#191C1E;--border:#2B3134;--border-hi:#41484D;
  --text:#E1E3E4;--text-muted:#B4BABD;--text-dim:#646F75;
  --purple:#6046FF;--purple-hover:#4D38CC;--purple-light:#806BFF;--purple-btn:#EFEDF5;
  --accent:#D5F800;
  --ok-bg:#1a2e1a;--ok-border:#2d5a2d;--ok-text:#86efac;
  --err-bg:#2e1a1a;--err-border:#5a2d2d;--err-text:#fca5a5;
  --warn-bg:#2a2510;--warn-border:#5a4f20;--warn-text:#fde68a;
  --font:'Space Grotesk','Instrument Sans',sans-serif;
  --mono:'Space Mono',ui-monospace,monospace
}
[data-theme="light"]{
  --bg:#F7F8F8;--surface:#fff;--border:#E1E3E4;--border-hi:#B4BABD;
  --text:#2B3134;--text-muted:#646F75;--text-dim:#8A9399;
  --purple:#4D38CC;--purple-hover:#3a2b99;--purple-light:#6046FF;
  --ok-bg:#d4edda;--ok-border:#a8d5b5;--ok-text:#155724;
  --err-bg:#f8d7da;--err-border:#f5c6c2;--err-text:#721c24;
  --warn-bg:#fff3cd;--warn-border:#ffc107;--warn-text:#856404
}
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
.site-header{background:var(--surface);border-bottom:1px solid var(--border);
  padding:18px 40px;display:flex;align-items:center;gap:16px;flex-shrink:0;
  transition:background .3s,border-color .3s}
.header-wordmark{display:flex;align-items:center;gap:10px;text-decoration:none}
.header-logo{height:26px;width:auto;display:block}
.header-title{font-size:17px;font-weight:500;color:var(--text);margin-right:auto;transition:color .3s}
.header-link{color:var(--text);font-size:14px;font-weight:500;padding:7px 14px;
  border-radius:8px;border:1px solid transparent;transition:all .2s;text-decoration:none}
.header-link:hover{background:var(--border);border-color:var(--border-hi);color:var(--text);text-decoration:none}
.theme-toggle{background:transparent;border:1px solid var(--border);border-radius:8px;
  padding:7px 12px;cursor:pointer;font-size:1.05rem;line-height:1;color:var(--text);
  transition:all .2s;flex-shrink:0}
.theme-toggle:hover{background:var(--border);border-color:var(--border-hi)}
.site-main{flex:1;padding:48px 40px 80px;max-width:960px;width:100%;margin:0 auto}
.site-footer{background:var(--surface);border-top:1px solid var(--border);
  padding:22px 40px;text-align:center;color:var(--text-dim);font-size:14px;
  flex-shrink:0;transition:background .3s,border-color .3s}
.site-footer .heart{color:var(--accent)}
.site-footer a{color:inherit}
.site-footer a:hover{text-decoration:underline;color:var(--text)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;
  padding:28px 32px;margin-bottom:24px;transition:background .3s,border-color .3s}
.card h2{font-size:19px;font-weight:700;color:var(--text);margin-bottom:8px}
.section-intro{color:var(--text-muted);font-size:14px;margin-bottom:22px;font-weight:400}
.page-title{font-size:40px;font-weight:900;line-height:1.2;margin-bottom:8px}
.page-sub{color:var(--text-muted);font-size:15px;margin-bottom:40px;font-weight:400}
h1{font-size:26px;font-weight:900;margin-bottom:6px}
h2{font-size:16px;font-weight:700;color:var(--text-muted);
  margin:28px 0 10px;padding-top:28px;border-top:1px solid var(--border)}
h2:first-of-type{margin-top:0;padding-top:0;border-top:none}
.breadcrumb{font-size:13px;color:var(--text-dim);margin-bottom:20px}
.breadcrumb a{color:var(--purple-light)}
.meta{color:var(--text-dim);font-size:13px;margin-bottom:24px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{padding:9px 12px;border-bottom:2px solid var(--border);color:var(--text-dim);
  font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;
  text-align:left;white-space:nowrap}
td{padding:11px 12px;border-bottom:1px solid var(--border);vertical-align:top}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(255,255,255,.02)}
.table-wrap{overflow-x:auto;margin-top:4px}
.runs-table td{vertical-align:middle;padding:12px}
.td-ts{font-family:var(--mono);font-size:12px;white-space:nowrap;color:var(--text-muted)}
.td-iters{font-size:12px;color:var(--text-muted)}
.td-fail{font-size:13px;text-align:center}
.td-files{display:flex;gap:8px;flex-wrap:wrap}
.td-files a{font-size:12px;padding:2px 8px;border:1px solid var(--border);
  border-radius:4px;color:var(--text-muted);white-space:nowrap;transition:all .15s}
.td-files a:hover{border-color:var(--purple);color:var(--purple-light);
  text-decoration:none;background:rgba(96,70,255,.08)}
.run-profile{font-size:12px}
.no-runs{color:var(--text-dim);font-style:italic;padding:20px 0;font-size:14px}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:.03em}
.badge-ok  {background:var(--ok-bg);color:var(--ok-text)}
.badge-err {background:var(--err-bg);color:var(--err-text)}
.badge-warn{background:var(--warn-bg);color:var(--warn-text)}
.run-ok,.ok{color:var(--ok-text)}
.run-bad,.bad{color:var(--err-text);font-weight:600}
.run-na,.muted{color:var(--text-dim)}
pre{background:var(--bg);border:1px solid var(--border-hi);border-radius:6px;
  padding:14px 18px;font-family:var(--mono);font-size:12px;color:var(--text);
  overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:6px 0}
.cli-pre{padding:18px 20px;font-size:13px;white-space:pre;margin-top:14px;
  background:var(--bg);border:1px solid var(--border-hi);border-radius:6px;
  font-family:var(--mono);color:var(--text);overflow-x:auto}
details{margin:4px 0}
summary{cursor:pointer;color:var(--text-muted);font-size:13px}
summary:hover{color:var(--text)}
.fail-row td:first-child{white-space:nowrap;font-size:12px;color:var(--text-dim)}
.fail-what{font-size:13px}
.fail-req{font-family:var(--mono);font-size:12px}
.exception{color:var(--err-text);font-weight:600}
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
  gap:12px 16px;margin-bottom:22px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group-wide{grid-column:1 / -1}
label{font-size:12px;font-weight:600;color:var(--text-muted);letter-spacing:.03em;text-transform:uppercase}
.label-hint{font-weight:400;text-transform:none;color:var(--text-dim)}
.form-control{background:var(--bg);border:1px solid var(--border-hi);border-radius:6px;
  color:var(--text);font-family:var(--mono);font-size:14px;padding:10px 13px;width:100%;
  transition:border-color .2s,background .3s;appearance:none}
.form-control:focus{outline:2px solid var(--purple);outline-offset:2px;border-color:var(--purple)}
.form-control::placeholder{color:var(--text-dim)}
.btn-row{display:flex;gap:12px;flex-wrap:wrap}
.btn-primary-alt{display:inline-block;padding:12px 26px;background:var(--purple);
  color:var(--purple-btn);font-family:var(--font);font-size:14px;font-weight:700;
  border:none;border-radius:48px;cursor:pointer;letter-spacing:.03em;transition:background .2s}
.btn-primary-alt:hover{background:var(--purple-hover);color:var(--purple-btn);text-decoration:none}
.btn-primary-alt:focus{outline:2px solid var(--purple);outline-offset:3px}
.btn-secondary{display:inline-block;padding:12px 26px;background:transparent;
  color:var(--text-muted);font-family:var(--font);font-size:14px;font-weight:600;
  border:1px solid var(--border-hi);border-radius:48px;cursor:pointer;
  letter-spacing:.03em;transition:all .2s}
.btn-secondary:hover{background:var(--border);color:var(--text);border-color:var(--border-hi)}
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
.notice{background:var(--bg);border:1px solid var(--border-hi);border-radius:8px;
  padding:16px 20px;font-size:13px;color:var(--text-muted);line-height:1.7;margin-top:14px}
.notice code{font-size:.82em}
@media(max-width:640px){
  .site-header{padding:14px 20px;gap:10px}.header-title{font-size:14px}
  .site-main{padding:28px 16px 60px}.page-title{font-size:26px}.card{padding:18px}
}
CSS;

// ── Shared page shell (for /k6-reports/* HTML responses) ─────────────────────
$shell = function (string $title, string $body) use ($h, $CSS): void {
    header('Content-Type: text/html; charset=utf-8');
    $t = $h($title);
    echo <<<HTML
    <!doctype html>
    <html lang="en" data-theme="dark">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>{$t}</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700;900&family=Space+Mono&display=swap" rel="stylesheet">
      <style>{$CSS}</style>
    </head>
    <body>
    <header class="site-header">
      <a href="/" class="header-wordmark" aria-label="k6 Load Testing">
        <svg class="header-logo" viewBox="0 0 80 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <text x="0" y="18" font-family="'Space Grotesk',sans-serif" font-size="20"
                font-weight="900" fill="currentColor" style="fill:var(--text)">upsun</text>
        </svg>
      </a>
      <span class="header-title">k6 Load Testing</span>
      <a href="/" class="header-link">&#x2190; All runs</a>
      <button class="theme-toggle" id="theme-toggle" aria-label="Toggle theme"><span id="theme-icon">🌙</span></button>
    </header>
    <main class="site-main">{$body}</main>
    <footer class="site-footer">
      made with <span class="heart">♥</span> by
      <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
    </footer>
    <script>
    (function(){var html=document.documentElement,btn=document.getElementById('theme-toggle'),icon=document.getElementById('theme-icon');
    function apply(t){html.setAttribute('data-theme',t);icon.textContent=t==='dark'?'🌙':'☀️';}
    apply(localStorage.getItem('theme')||'dark');
    btn.addEventListener('click',function(){var next=html.getAttribute('data-theme')==='dark'?'light':'dark';localStorage.setItem('theme',next);apply(next);});})();
    </script>
    </body></html>
    HTML;
};

// ── Helpers used by the runs table ────────────────────────────────────────────

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

        $failFile  = "$base/$run/failures.jsonl";
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

        $rows .= '<tr>'
            . '<td class="td-ts">' . $ts . '</td>'
            . '<td><code class="run-profile">' . $h($profile) . '</code></td>'
            . '<td>' . $result . '</td>'
            . '<td class="td-iters">' . $iters . '</td>'
            . '<td class="td-fail">' . $failCell . '</td>'
            . '<td class="td-files">' . implode('&ensp;', $links) . '</td>'
            . '</tr>';
    }

    return '<div class="table-wrap"><table class="runs-table">'
        . '<thead><tr><th>Run (UTC)</th><th>Profile</th><th>Result</th>'
        . '<th>Iterations</th><th>Failures</th><th>Files</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
}

// ── Route: /k6-reports/* ─────────────────────────────────────────────────────
if (str_starts_with($_uri, '/k6-reports')) {
    $path = trim(substr($_uri, strlen('/k6-reports')), '/');

    if ($path === '') {
        header('Location: /', true, 302);
        exit;
    }

    $inside = function (string $rel) use ($base): ?string {
        if ($base === false) return null;
        $file = realpath($base . '/' . $rel);
        return $file && str_starts_with($file, $base . '/') ? $file : null;
    };

    // Failures detail page
    if (preg_match('#^([^/]+)/failures$#', $path, $m)) {
        $runName = rawurldecode($m[1]);
        $dir     = $inside($runName);
        $file    = $dir ? "$dir/failures.jsonl" : null;

        if (!$dir || !is_file($file)) {
            http_response_code(404);
            $shell('Not found — k6', '<p class="muted">Run not found.</p>');
            exit;
        }

        $readJsonLines = function (string $file, int $limit = 1000): array {
            $rows = [];
            $fh   = @fopen($file, 'r');
            while ($fh && ($line = fgets($fh)) !== false && count($rows) < $limit) {
                $row = json_decode($line, true);
                if (is_array($row)) $rows[] = $row;
            }
            $fh && fclose($fh);
            return $rows;
        };

        $rows   = $readJsonLines($file);
        $pretty = fn($v) => $h(is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $byFlow = [];
        foreach ($rows as $r) {
            $key = (($r['flow'] ?? '') ?: ($r['scenario'] ?? '')) . ' · ' . ($r['name'] ?? '');
            $byFlow[$key] = ($byFlow[$key] ?? 0) + 1;
        }
        arsort($byFlow);

        $total     = count($rows);
        $truncated = $total >= 1000 ? ' <span class="muted">(first 1000 shown)</span>' : '';
        $rawLink   = '/k6-reports/' . rawurlencode($runName) . '/failures.jsonl';

        $summaryRows = '';
        foreach ($byFlow as $k => $n) {
            $summaryRows .= '<tr><td>' . $h($k) . '</td><td style="text-align:right">' . $n . '</td></tr>';
        }

        $detailRows = '';
        foreach ($rows as $r) {
            $req  = $r['request']  ?? [];
            $res  = $r['response'] ?? [];
            $isEx = ($r['type'] ?? '') === 'exception';

            $what = $isEx
                ? '<span class="exception">exception:</span> ' . $h($r['message'] ?? '')
                : '<strong>' . $h($r['name'] ?? '') . '</strong>'
                  . ($r['failed'] ?? [] ? '<br><span class="muted">' . $h(implode('; ', $r['failed'])) . '</span>' : '');

            $reqCell = isset($req['url'])
                ? '<code class="fail-req">' . $h(($req['method'] ?? '') . ' ' . $req['url']) . '</code>'
                  . ' <span class="badge ' . (($res['status'] ?? 0) >= 400 ? 'badge-err' : 'badge-ok') . '">'
                  . $h((string) ($res['status'] ?? '')) . '</span>'
                  . (!empty($res['error']) ? ' <span class="bad">' . $h($res['error']) . '</span>' : '')
                  . (isset($res['timings']['duration']) ? ' <span class="muted">' . round($res['timings']['duration']) . 'ms</span>' : '')
                : '';

            $detailCell = $isEx
                ? '<details><summary>stack trace</summary><pre>' . $h($r['stack'] ?? '') . '</pre></details>'
                : '<details><summary>request</summary><pre>' . $pretty($req) . '</pre></details>'
                  . '<details><summary>response</summary><pre>' . $pretty(array_diff_key($res, ['body' => 1])) . '</pre>'
                  . '<pre>' . $h($res['body'] ?? '') . '</pre></details>';

            $ts   = $h($r['time'] ?? '');
            $vu   = $h(($r['scenario'] ?? '') . ' vu ' . ($r['vu'] ?? '') . ' it ' . ($r['iteration'] ?? ''));
            $flow = $h($r['flow'] ?? $r['scenario'] ?? '');

            $detailRows .= '<tr class="fail-row">'
                . '<td><div>' . $ts . '</div><div class="muted">' . $vu . '</div></td>'
                . '<td><code>' . $flow . '</code></td>'
                . '<td class="fail-what">' . $what . '</td>'
                . '<td>' . $reqCell . '</td>'
                . '<td>' . $detailCell . '</td>'
                . '</tr>';
        }

        $summaryTable = $summaryRows
            ? '<h2>By flow and request</h2>'
              . '<table><thead><tr><th>Flow · request</th><th style="text-align:right">Count</th></tr></thead>'
              . '<tbody>' . $summaryRows . '</tbody></table>'
            : '';

        $body = '<div class="breadcrumb"><a href="/">All runs</a> / ' . $h($runName) . ' / failures</div>'
            . '<h1>Failures</h1>'
            . '<p class="meta">' . $total . ' records' . $truncated
            . ' &ensp;·&ensp; <a href="' . $rawLink . '">raw .jsonl</a></p>'
            . $summaryTable
            . '<h2>Records</h2>'
            . '<table><thead><tr>'
            . '<th>Time / VU</th><th>Flow</th><th>What failed</th><th>Request</th><th>Details</th>'
            . '</tr></thead><tbody>' . $detailRows . '</tbody></table>';

        $shell('Failures — ' . $runName, $body);
        exit;
    }

    // File serving
    $fileTypes = [
        'html'  => 'text/html; charset=utf-8',
        'json'  => 'application/json',
        'jsonl' => 'text/plain; charset=utf-8',
        'log'   => 'text/plain; charset=utf-8',
    ];
    $file = $inside(rawurldecode($path));
    $ext  = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';

    if (!$file || !is_file($file) || !isset($fileTypes[$ext])) {
        http_response_code(404);
        $shell('Not found — k6', '<p class="muted">File not found.</p>');
        exit;
    }

    header('Content-Type: ' . $fileTypes[$ext]);
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// ── Route: / (main page) ──────────────────────────────────────────────────────

session_start();
header('Content-Type: text/html; charset=utf-8');

$apiToken      = (string) (getenv('UPSUN_CLI_TOKEN') ?: '');
$environmentId = (string) (getenv('PLATFORM_ENVIRONMENT') ?: '');
$cliBin        = $appDir . '/bin/upsun';
$canTrigger    = $apiToken !== '' && is_executable($cliBin);
$reportsBase   = is_string($base) ? $base : '';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

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

$bannerHtml = '';
if ($triggerResult !== null) {
    if ($triggerResult['ok']) {
        $profile = $h($triggerResult['profile'] ?? '');
        $varList = implode('&ensp;', array_map(
            fn($k, $v) => '<code>' . $h($k) . '=' . $h($v) . '</code>',
            array_keys($triggerResult['vars'] ?? []), $triggerResult['vars'] ?? []
        ));
        $cliOut  = $triggerResult['output'] !== ''
            ? '<pre class="result-pre">' . $h($triggerResult['output']) . '</pre>' : '';
        $bannerHtml = '<div class="banner banner-ok">'
            . '<strong>Task queued</strong> — profile: <code>' . $profile . '</code><br>'
            . '<span class="banner-meta">' . $varList . '</span>'
            . $cliOut
            . '<span class="banner-hint">Follow in the '
            . '<a href="https://console.upsun.com/" target="_blank" rel="noopener">Upsun console</a>'
            . ' or <code>upsun activity:log</code>.'
            . ' Results appear in the runs table below once finished.</span></div>';
    } else {
        $out  = $h($triggerResult['output'] ?? 'Unknown error');
        $exit = isset($triggerResult['exitCode']) && $triggerResult['exitCode'] >= 0
            ? ' <span class="exit-code">exit ' . (int) $triggerResult['exitCode'] . '</span>' : '';
        $bannerHtml = '<div class="banner banner-err"><strong>Failed to start task' . $exit
            . '</strong><pre class="result-pre">' . $out . '</pre></div>';
    }
}

if ($canTrigger) {
    $envLabel = $environmentId !== '' ? ' on <code>' . $h($environmentId) . '</code>' : '';
    $optHtml  = implode('', array_map(
        fn($p) => '<option value="' . $p . '">' . $p . '</option>',
        ['combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke', 'cleanup']
    ));
    $triggerSection = <<<HTML
    <section class="card">
      <h2>Run a load test{$envLabel}</h2>
      <p class="section-intro">Triggers the <code>loadtesting-k6</code> task in its own container — the web UI stays responsive.</p>
      <form method="post" action="/" id="run-form">
        <input type="hidden" name="action" value="run_task">
        <input type="hidden" name="csrf"   value="{$csrf}">
        <div class="form-grid">
          <div class="form-group">
            <label for="f-profile">Profile</label>
            <select id="f-profile" name="profile" class="form-control" onchange="toggleFields(this.value)">{$optHtml}</select>
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
            <input id="f-duration" name="duration" type="text" class="form-control" value="3m" placeholder="3m / 10m / 1h" pattern="\\d+[smh]">
          </div>
          <div class="form-group form-group-wide">
            <label for="f-url">URL override <span class="label-hint">(optional)</span></label>
            <input id="f-url" name="url_override" type="url" class="form-control" placeholder="https://staging.yourshop.com">
          </div>
        </div>
        <div class="btn-row">
          <button type="submit" class="btn-primary-alt">Start task</button>
          <button type="button" class="btn-secondary" onclick="runCleanup()">Run cleanup</button>
        </div>
      </form>
      {$bannerHtml}
      <script>
      function toggleFields(p){
        var noLoad=['cleanup'],noAdmin=['storefront','crawler'],noDur=['smoke'];
        document.getElementById('fg-vus').style.display=noLoad.includes(p)?'none':'';
        document.getElementById('fg-admin-vus').style.display=(noLoad.includes(p)||noAdmin.includes(p))?'none':'';
        document.getElementById('fg-duration').style.display=(noLoad.includes(p)||noDur.includes(p))?'none':'';
      }
      function runCleanup(){document.getElementById('f-profile').value='cleanup';toggleFields('cleanup');document.getElementById('run-form').submit();}
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
  <style><?= $CSS ?></style>
</head>
<body>

<header class="site-header">
  <a href="/" class="header-wordmark" aria-label="k6 Load Testing">
    <svg class="header-logo" viewBox="0 0 80 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
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
  <p class="page-sub">Magento Performance Toolkit benchmark — runs as an on-demand Upsun task from its own container.</p>

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
    <p class="section-intro">Each run produces an HTML report, JSON metrics, a failure log and a console log. Reload after a task finishes to see new results.</p>
    <?= $runsHtml ?>
  </section>

</main>

<footer class="site-footer">
  made with <span class="heart">♥</span> by
  <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
</footer>

<script>
(function(){
  var html=document.documentElement,btn=document.getElementById('theme-toggle'),icon=document.getElementById('theme-icon');
  function apply(t){html.setAttribute('data-theme',t);icon.textContent=t==='dark'?'🌙':'☀️';}
  apply(localStorage.getItem('theme')||'dark');
  btn.addEventListener('click',function(){var next=html.getAttribute('data-theme')==='dark'?'light':'dark';localStorage.setItem('theme',next);apply(next);});
})();
</script>

</body>
</html>
