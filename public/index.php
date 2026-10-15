<?php
/**
 * k6 Load Testing — single entry point for all routes.
 *
 * Routes:
 *   GET  /                           main page: task trigger + runs table
 *   POST /  action=run_task          fire the loadtesting-k6 Upsun task
 *   GET  /k6-reports/                → redirect to /
 *   GET  /k6-reports/<run>           run detail: info + metrics + log + files
 *   GET  /k6-reports/<run>/failures  failures detail page
 *   GET  /k6-reports/<run>/<file>    serve report.html / summary.json / k6.log / etc.
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
  --mono:'Space Mono',ui-monospace,monospace;
  --shadow:0 1px 3px rgba(0,0,0,.4),0 1px 2px rgba(0,0,0,.3);
  --shadow-sm:0 1px 2px rgba(0,0,0,.3)
}
[data-theme="light"]{
  --bg:#F4F6F7;--surface:#fff;--border:#DDE1E3;--border-hi:#B4BABD;
  --text:#1C2023;--text-muted:#556067;--text-dim:#8A9399;
  --purple:#4D38CC;--purple-hover:#3a2b99;--purple-light:#6046FF;
  --ok-bg:#e6f4ea;--ok-border:#a8d5b5;--ok-text:#1a5c2a;
  --err-bg:#fce8e8;--err-border:#f5b8b8;--err-text:#8b1a1a;
  --warn-bg:#fef9e7;--warn-border:#f5d67a;--warn-text:#7a5500;
  --shadow:0 1px 4px rgba(0,0,0,.10),0 1px 2px rgba(0,0,0,.06);
  --shadow-sm:0 1px 2px rgba(0,0,0,.08)
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--font);
  line-height:1.6;min-height:100vh;display:flex;flex-direction:column;
  transition:background .25s,color .25s}
a{color:var(--purple-light);text-decoration:none;transition:color .15s}
a:hover{color:var(--purple);text-decoration:underline}
strong{color:var(--text-muted);font-weight:700}
code{font-family:var(--mono);font-size:.84em;background:var(--border);
  color:var(--text);padding:.1em .4em;border-radius:3px}

/* ── Header ── */
.site-header{background:var(--surface);border-bottom:1px solid var(--border);
  padding:0 40px;height:56px;display:flex;align-items:center;gap:16px;
  flex-shrink:0;transition:background .25s,border-color .25s;
  box-shadow:var(--shadow-sm)}
.header-wordmark{display:flex;align-items:center;gap:8px;text-decoration:none;
  flex-shrink:0}
.wordmark-text{font-size:18px;font-weight:900;color:var(--text);letter-spacing:-.02em;
  transition:color .25s}
.header-sep{color:var(--border-hi);font-weight:300;font-size:20px;user-select:none}
.header-product{font-size:14px;font-weight:500;color:var(--text-muted);
  transition:color .25s;white-space:nowrap}
.header-spacer{flex:1}
.header-link{color:var(--text-muted);font-size:13px;font-weight:500;padding:6px 12px;
  border-radius:6px;border:1px solid transparent;transition:all .15s;text-decoration:none;
  white-space:nowrap}
.header-link:hover{background:var(--border);border-color:var(--border-hi);
  color:var(--text);text-decoration:none}
.theme-btn{background:var(--surface);border:1px solid var(--border);border-radius:8px;
  padding:6px 10px;cursor:pointer;font-size:13px;font-weight:500;color:var(--text-muted);
  display:flex;align-items:center;gap:6px;transition:all .15s;flex-shrink:0;
  line-height:1;white-space:nowrap}
.theme-btn:hover{background:var(--border);border-color:var(--border-hi);color:var(--text)}
.theme-btn svg{width:14px;height:14px;flex-shrink:0}

/* ── Layout ── */
.site-main{flex:1;padding:48px 40px 80px;max-width:980px;width:100%;margin:0 auto}
.site-footer{background:var(--surface);border-top:1px solid var(--border);
  padding:20px 40px;text-align:center;color:var(--text-dim);font-size:13px;
  flex-shrink:0;transition:background .25s,border-color .25s}
.site-footer .heart{color:var(--accent)}
.site-footer a{color:inherit}
.site-footer a:hover{text-decoration:underline;color:var(--text)}

/* ── Cards ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;
  padding:28px 32px;margin-bottom:20px;box-shadow:var(--shadow);
  transition:background .25s,border-color .25s,box-shadow .25s}
.card h2{font-size:18px;font-weight:700;color:var(--text);margin-bottom:6px}
.section-intro{color:var(--text-muted);font-size:14px;margin-bottom:20px}

/* ── Main page typography ── */
.page-title{font-size:36px;font-weight:900;line-height:1.15;margin-bottom:6px;
  letter-spacing:-.02em}
.page-sub{color:var(--text-muted);font-size:15px;margin-bottom:36px}

/* ── Detail page typography ── */
h1{font-size:24px;font-weight:800;margin-bottom:4px;letter-spacing:-.01em}
h2{font-size:13px;font-weight:700;color:var(--text-dim);letter-spacing:.07em;
  text-transform:uppercase;margin:28px 0 10px;padding-bottom:6px;
  border-bottom:1px solid var(--border)}
.breadcrumb{font-size:13px;color:var(--text-dim);margin-bottom:18px}
.breadcrumb a{color:var(--text-muted)}
.breadcrumb a:hover{color:var(--purple-light)}
.run-title-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.run-title-row h1{margin:0}

/* ── Run info grid ── */
.run-meta{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));
  gap:8px;margin-bottom:4px}
.meta-item{background:var(--bg);border:1px solid var(--border);border-radius:8px;
  padding:10px 14px}
.meta-key{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--text-dim);display:block;margin-bottom:3px}
.meta-val{font-size:13px;color:var(--text);word-break:break-all;line-height:1.4}
.meta-val a{color:var(--purple-light);font-size:12px}

/* ── Metric stat boxes ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));
  gap:10px;margin-bottom:4px}
.stat-box{background:var(--bg);border:1px solid var(--border);border-radius:8px;
  padding:14px 16px}
.stat-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
  color:var(--text-dim);margin-bottom:6px}
.stat-val{font-size:20px;font-weight:700;font-family:var(--mono);color:var(--text);
  line-height:1.2}
.stat-sub{font-size:11px;color:var(--text-dim);margin-top:3px}
.stat-box.stat-err .stat-val{color:var(--err-text)}
.stat-box.stat-ok  .stat-val{color:var(--ok-text)}
.stat-box.stat-warn .stat-val{color:var(--warn-text)}

/* ── Log viewer ── */
.log-wrap{max-height:360px;overflow-y:auto;border:1px solid var(--border);
  border-radius:6px;background:var(--bg)}
.log-wrap pre{border:none;border-radius:0;margin:0;max-height:none;font-size:11px;
  line-height:1.5;padding:14px 16px}

/* ── File download links ── */
.file-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.file-link{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;
  background:var(--surface);border:1px solid var(--border);border-radius:8px;
  color:var(--text-muted);font-size:13px;font-weight:500;transition:all .15s;
  text-decoration:none;box-shadow:var(--shadow-sm)}
.file-link:hover{border-color:var(--purple);color:var(--purple-light);
  text-decoration:none;background:rgba(96,70,255,.06)}
.file-link.primary{background:var(--purple);border-color:var(--purple);
  color:var(--purple-btn);box-shadow:none}
.file-link.primary:hover{background:var(--purple-hover);border-color:var(--purple-hover);
  color:var(--purple-btn)}

/* ── Tables (generic + runs-table) ── */
table{width:100%;border-collapse:collapse;font-size:13px}
th{padding:8px 12px;border-bottom:2px solid var(--border);color:var(--text-dim);
  font-weight:700;font-size:10px;letter-spacing:.07em;text-transform:uppercase;
  text-align:left;white-space:nowrap}
td{padding:11px 12px;border-bottom:1px solid var(--border);vertical-align:top}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(128,107,255,.04)}
.table-wrap{overflow-x:auto}
.runs-table td{vertical-align:middle;padding:12px}
.td-ts{white-space:nowrap}
.td-ts a{font-family:var(--mono);font-size:12px;color:var(--text-muted);font-weight:500}
.td-ts a:hover{color:var(--purple-light);text-decoration:underline}
.td-iters{font-size:12px;color:var(--text-muted)}
.td-fail{text-align:center}
.td-files{display:flex;gap:6px;flex-wrap:wrap}
.td-files a{font-size:11px;padding:2px 8px;border:1px solid var(--border);
  border-radius:4px;color:var(--text-dim);white-space:nowrap;transition:all .12s}
.td-files a:hover{border-color:var(--purple);color:var(--purple-light);
  text-decoration:none;background:rgba(96,70,255,.07)}
.run-profile{font-size:12px}
.no-runs{color:var(--text-dim);font-style:italic;padding:20px 0;font-size:14px}

/* ── Badges ── */
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;
  font-weight:700;letter-spacing:.04em}
.badge-ok  {background:var(--ok-bg);color:var(--ok-text);border:1px solid var(--ok-border)}
.badge-err {background:var(--err-bg);color:var(--err-text);border:1px solid var(--err-border)}
.badge-warn{background:var(--warn-bg);color:var(--warn-text);border:1px solid var(--warn-border)}
.run-ok,.ok{color:var(--ok-text)}
.run-bad,.bad{color:var(--err-text);font-weight:600}
.run-na,.muted{color:var(--text-dim)}

/* ── Preformatted ── */
pre{background:var(--bg);border:1px solid var(--border);border-radius:6px;
  padding:14px 18px;font-family:var(--mono);font-size:12px;color:var(--text);
  overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:6px 0;
  line-height:1.55}
.cli-pre{padding:18px 20px;font-size:12px;white-space:pre;background:var(--bg);
  border:1px solid var(--border);border-radius:6px;font-family:var(--mono);
  color:var(--text);overflow-x:auto;line-height:1.6;margin-top:12px}
details{margin:4px 0}
summary{cursor:pointer;color:var(--text-muted);font-size:13px;user-select:none}
summary:hover{color:var(--text)}

/* ── Failure rows ── */
.fail-row td:first-child{white-space:nowrap;font-size:11px;color:var(--text-dim)}
.fail-what{font-size:13px}
.fail-req{font-family:var(--mono);font-size:12px}
.exception{color:var(--err-text);font-weight:600}

/* ── Form ── */
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
  gap:10px 14px;margin-bottom:20px}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group-wide{grid-column:1 / -1}
label{font-size:10px;font-weight:700;color:var(--text-dim);letter-spacing:.07em;
  text-transform:uppercase}
.label-hint{font-weight:400;text-transform:none;color:var(--text-dim)}
.form-control{background:var(--bg);border:1px solid var(--border-hi);border-radius:6px;
  color:var(--text);font-family:var(--mono);font-size:13px;padding:9px 12px;width:100%;
  transition:border-color .15s,box-shadow .15s;appearance:none}
.form-control:focus{outline:none;border-color:var(--purple);
  box-shadow:0 0 0 3px rgba(96,70,255,.2)}
.form-control::placeholder{color:var(--text-dim)}

/* ── Buttons ── */
.btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}
.btn-primary{display:inline-flex;align-items:center;padding:10px 22px;
  background:var(--purple);color:var(--purple-btn);font-family:var(--font);
  font-size:14px;font-weight:700;border:none;border-radius:6px;cursor:pointer;
  letter-spacing:.01em;transition:background .15s,transform .1s;text-decoration:none}
.btn-primary:hover{background:var(--purple-hover);color:var(--purple-btn);
  text-decoration:none;transform:translateY(-1px)}
.btn-primary:active{transform:none}
.btn-primary:focus{outline:2px solid var(--purple);outline-offset:3px}
.btn-ghost{display:inline-flex;align-items:center;padding:10px 22px;
  background:transparent;color:var(--text-muted);font-family:var(--font);
  font-size:14px;font-weight:600;border:1px solid var(--border-hi);border-radius:6px;
  cursor:pointer;letter-spacing:.01em;transition:all .15s}
.btn-ghost:hover{background:var(--border);color:var(--text);border-color:var(--border-hi)}
.btn-danger-sm{display:inline-flex;align-items:center;padding:4px 10px;
  background:transparent;color:var(--err-text);font-family:var(--font);
  font-size:11px;font-weight:600;border:1px solid var(--err-border);border-radius:5px;
  cursor:pointer;line-height:1;transition:all .15s}
.btn-danger-sm:hover{background:var(--err-bg);border-color:var(--err-text)}
.notice-admin{display:flex;align-items:flex-start;gap:8px;
  background:var(--warn-bg);border:1px solid var(--warn-border);border-radius:6px;
  padding:10px 14px;font-size:12px;color:var(--warn-text);line-height:1.5}
.notice-admin.admin-ok{background:var(--ok-bg);border-color:var(--ok-border);color:var(--ok-text)}
.notice-admin.admin-ok a,.notice-admin a{color:inherit;text-decoration:underline}
.form-check-row{display:flex;align-items:center;gap:8px;margin-top:2px}
.form-check-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--purple);
  cursor:pointer;flex-shrink:0}
.form-check-row label{font-size:13px;color:var(--text-muted);cursor:pointer;
  text-transform:none;letter-spacing:0;font-weight:400}
.form-group-inline{display:flex;flex-direction:column;gap:6px}

/* ── Banners ── */
.banner{margin-top:18px;padding:14px 18px;border-radius:8px;font-size:13px;
  line-height:1.5}
.banner-ok{background:var(--ok-bg);border:1px solid var(--ok-border);color:var(--ok-text)}
.banner-ok strong,.banner-ok a{color:var(--ok-text)}
.banner-ok code{background:rgba(255,255,255,.12);color:var(--ok-text)}
.banner-err{background:var(--err-bg);border:1px solid var(--err-border);color:var(--err-text)}
.banner-err strong{color:var(--err-text)}
.banner-meta{font-size:12px;display:block;margin-top:4px;opacity:.85}
.banner-hint{font-size:12px;display:block;margin-top:8px;opacity:.75}
.banner-hint a{color:inherit;text-decoration:underline}
.exit-code{font-size:.85em;opacity:.7}
.result-pre{margin-top:8px;background:rgba(0,0,0,.2);border:none;border-radius:5px;
  padding:8px 12px;font-family:var(--mono);font-size:11px;white-space:pre-wrap;
  word-break:break-all;color:inherit;opacity:.9}
.notice{background:var(--bg);border:1px solid var(--border);border-radius:8px;
  padding:14px 18px;font-size:13px;color:var(--text-muted);line-height:1.7;margin-top:12px}
.notice code{font-size:.82em}

@media(max-width:680px){
  .site-header{padding:0 16px;height:52px}
  .header-product{display:none}
  .header-sep{display:none}
  .site-main{padding:28px 16px 60px}
  .page-title{font-size:26px}
  .card{padding:18px 20px}
  .run-meta{grid-template-columns:1fr 1fr}
  .stats-grid{grid-template-columns:1fr 1fr}
}
CSS;

// ── Shared page shell (reports sub-pages) ─────────────────────────────────────
$shell = function (string $title, string $body, bool $backLink = true) use ($h, $CSS): void {
    header('Content-Type: text/html; charset=utf-8');
    $t = $h($title);
    $back = $backLink ? '<a href="/" class="header-link">&#x2190; All runs</a>' : '';
    echo <<<HTML
    <!doctype html>
    <html lang="en" data-theme="dark">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>{$t} — k6</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700;900&family=Space+Mono&display=swap" rel="stylesheet">
      <style>{$CSS}</style>
    </head>
    <body>
    <header class="site-header">
      <a href="/" class="header-wordmark">
        <span class="wordmark-text">upsun</span>
        <span class="header-sep">/</span>
        <span class="header-product">k6 Load Testing</span>
      </a>
      <div class="header-spacer"></div>
      {$back}
      <button class="theme-btn" id="theme-btn" aria-label="Toggle colour scheme">
        <svg id="theme-icon-sun" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><circle cx="10" cy="10" r="4"/><line x1="10" y1="2" x2="10" y2="0.5"/><line x1="10" y1="18" x2="10" y2="19.5"/><line x1="2" y1="10" x2=".5" y2="10"/><line x1="18" y1="10" x2="19.5" y2="10"/><line x1="4.2" y1="4.2" x2="3.1" y2="3.1"/><line x1="15.8" y1="15.8" x2="16.9" y2="16.9"/><line x1="4.2" y1="15.8" x2="3.1" y2="16.9"/><line x1="15.8" y1="4.2" x2="16.9" y2="3.1"/></svg>
        <svg id="theme-icon-moon" viewBox="0 0 20 20" fill="currentColor"><path d="M17.3 13.4A7 7 0 0 1 6.6 2.7a8 8 0 1 0 10.7 10.7z"/></svg>
        <span id="theme-label">Dark</span>
      </button>
    </header>
    <main class="site-main">
    {$body}
    </main>
    <footer class="site-footer">
      made with <span class="heart">♥</span> by
      <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
    </footer>
    <script>
    (function(){
      var html=document.documentElement,btn=document.getElementById('theme-btn'),
          sun=document.getElementById('theme-icon-sun'),moon=document.getElementById('theme-icon-moon'),
          lbl=document.getElementById('theme-label');
      function apply(t){
        html.setAttribute('data-theme',t);
        var dark=t==='dark';
        sun.style.display=dark?'none':'block';moon.style.display=dark?'block':'none';
        lbl.textContent=dark?'Dark':'Light';
      }
      apply(localStorage.getItem('theme')||'dark');
      btn.addEventListener('click',function(){
        var next=html.getAttribute('data-theme')==='dark'?'light':'dark';
        localStorage.setItem('theme',next);apply(next);
      });
    })();
    </script>
    </body></html>
    HTML;
};

// ── Named functions ───────────────────────────────────────────────────────────

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

function deleteRun(string $base, string $runName): array
{
    if ($base === '' || !is_dir($base)) {
        return ['ok' => false, 'error' => 'Reports directory not available.'];
    }
    $dir = realpath($base . '/' . $runName);
    if (!$dir || !str_starts_with($dir, $base . '/') || !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Run not found.'];
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $fileInfo) {
        $fileInfo->isDir() ? rmdir($fileInfo->getRealPath()) : unlink($fileInfo->getRealPath());
    }
    rmdir($dir);
    $latest = $base . '/latest';
    if (is_link($latest) && basename((string) readlink($latest)) === $runName) {
        unlink($latest);
    }
    return ['ok' => true, 'run' => $runName];
}

function runsTable(string $base, callable $h, string $csrf = ''): string
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
        $href = '/k6-reports/' . rawurlencode($run);
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
            $iters = number_format($complete);
            if ($interrupted) {
                $iters .= '&ensp;<span class="bad">' . number_format($interrupted) . '&thinsp;int.</span>';
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
                ? '<a class="bad" href="' . $href . '/failures">' . number_format($failCount) . '</a>'
                : '<span class="ok">0</span>');

        $links = [];
        foreach (['report.html' => 'report', 'k6.log' => 'log'] as $f => $label) {
            if (is_file("$base/$run/$f")) {
                $links[] = '<a href="' . $href . '/' . $f . '">' . $label . '</a>';
            }
        }

        $deleteBtn = $csrf !== ''
            ? '<form method="post" action="/" style="display:inline">'
              . '<input type="hidden" name="action" value="delete_run">'
              . '<input type="hidden" name="csrf"   value="' . $csrf . '">'
              . '<input type="hidden" name="run"    value="' . $h($run) . '">'
              . '<button type="submit" class="btn-danger-sm"'
              . ' onclick="return confirm(\'Delete run ' . $h($run) . '?\')">'
              . '&#x1F5D1; Delete</button>'
              . '</form>'
            : '';

        $rows .= '<tr>'
            . '<td class="td-ts"><a href="' . $href . '">' . $ts . '</a></td>'
            . '<td><code class="run-profile">' . $h($profile) . '</code></td>'
            . '<td>' . $result . '</td>'
            . '<td class="td-iters">' . $iters . '</td>'
            . '<td class="td-fail">' . $failCell . '</td>'
            . '<td class="td-files">' . implode('', $links) . '</td>'
            . '<td>' . $deleteBtn . '</td>'
            . '</tr>';
    }

    return '<div class="table-wrap"><table class="runs-table">'
        . '<thead><tr><th>Run (UTC)</th><th>Profile</th><th>Result</th>'
        . '<th>Iterations</th><th>Failures</th><th>Files</th><th></th></tr></thead>'
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
        $resolved = realpath($base . '/' . $rel);
        return $resolved && str_starts_with($resolved, $base . '/') ? $resolved : null;
    };

    // ── Run detail page: /k6-reports/<run> ───────────────────────────────────
    if (preg_match('#^([^/]+)$#', $path, $m)) {
        $runName = rawurldecode($m[1]);
        $dir     = $inside($runName);

        if ($dir && is_dir($dir)) {
            [$stamp, $profile] = array_pad(explode('-', $runName, 2), 2, '');
            $ts = preg_replace(
                '/^(\d{4})(\d\d)(\d\d)T(\d\d)(\d\d)(\d\d)Z$/',
                '$1-$2-$3 $4:$5:$6 UTC', $stamp
            );

            // Load data files
            $info    = is_file("$dir/run-info.json")
                ? (json_decode(file_get_contents("$dir/run-info.json"), true) ?: null) : null;
            $summary = is_file("$dir/summary.json")
                ? (json_decode(file_get_contents("$dir/summary.json"), true) ?: null) : null;

            // Badge
            $code  = is_array($info) ? (int) ($info['exit_code'] ?? -1) : -1;
            $badge = match (true) {
                $code === 0  => '<span class="badge badge-ok">passed</span>',
                $code === 99 => '<span class="badge badge-warn">thresholds</span>',
                $code >  0   => '<span class="badge badge-err">failed (' . $code . ')</span>',
                default      => '<span class="badge badge-warn">—</span>',
            };

            // ── Info grid ────────────────────────────────────────────────────
            $infoItems = [];
            if (is_array($info)) {
                $fmt = fn($iso) => $h(str_replace(['T', 'Z'], [' ', ''], $iso) . ' UTC');
                $fields = [
                    'Profile'  => $h($info['profile'] ?? '—'),
                    'URL'      => $info['url'] ?? '' ? '<a href="' . $h($info['url']) . '" target="_blank" rel="noopener">' . $h($info['url']) . '</a>' : '<span class="muted">—</span>',
                    'VUs'      => $h($info['vus'] ?? '—'),
                    'Duration' => $h($info['duration'] ?? '—'),
                    'Started'  => $fmt($info['started'] ?? ''),
                    'Finished' => $fmt($info['finished'] ?? ''),
                    'Complete' => number_format((int) ($info['iterations_complete'] ?? 0)) . ' iters'
                        . ((int) ($info['iterations_interrupted'] ?? 0) ? ' &ensp;<span class="bad">' . number_format((int) $info['iterations_interrupted']) . ' int.</span>' : ''),
                    'Failures' => (int) ($info['failure_records'] ?? 0) > 0
                        ? '<a class="bad" href="/k6-reports/' . rawurlencode($runName) . '/failures">'
                          . number_format((int) $info['failure_records']) . ' records</a>'
                        : '<span class="ok">0</span>',
                ];
                foreach ($fields as $k => $v) {
                    $infoItems[] = '<div class="meta-item"><span class="meta-key">' . $k . '</span>'
                        . '<span class="meta-val">' . $v . '</span></div>';
                }
            }
            $infoHtml = $infoItems
                ? '<div class="run-meta">' . implode('', $infoItems) . '</div>'
                : '';

            // ── Metrics ──────────────────────────────────────────────────────
            $statsHtml  = '';
            $threshHtml = '';
            if (is_array($summary) && is_array($summary['metrics'] ?? null)) {
                $metrics = $summary['metrics'];

                $stat = function (string $label, string $val, string $sub = '', string $cls = '') {
                    return '<div class="stat-box' . ($cls ? ' stat-' . $cls : '') . '">'
                        . '<div class="stat-label">' . $label . '</div>'
                        . '<div class="stat-val">' . $val . '</div>'
                        . ($sub ? '<div class="stat-sub">' . $sub . '</div>' : '')
                        . '</div>';
                };

                if ($d = $metrics['http_req_duration']['values'] ?? null) {
                    $p95 = isset($d['p(95)']) ? round($d['p(95)']) : null;
                    $cls = $p95 === null ? '' : ($p95 < 1000 ? 'ok' : ($p95 < 3000 ? 'warn' : 'err'));
                    $statsHtml .= $stat('p(95) duration', $p95 !== null ? $p95 . 'ms' : '—', 'http req', $cls);
                    $statsHtml .= $stat('avg duration', isset($d['avg']) ? round($d['avg']) . 'ms' : '—', 'http req');
                    $statsHtml .= $stat('max duration', isset($d['max']) ? round($d['max']) . 'ms' : '—', 'http req');
                }
                if ($d = $metrics['http_req_failed']['values'] ?? null) {
                    $rate = isset($d['rate']) ? round($d['rate'] * 100, 2) : null;
                    $cls  = $rate === null ? '' : ($rate < 1 ? 'ok' : ($rate < 5 ? 'warn' : 'err'));
                    $statsHtml .= $stat('error rate', $rate !== null ? $rate . '%' : '—', 'http_req_failed', $cls);
                }
                if ($d = $metrics['http_reqs']['values'] ?? null) {
                    $statsHtml .= $stat('requests', number_format((int) ($d['count'] ?? 0)),
                        round($d['rate'] ?? 0, 1) . ' req/s');
                }
                if ($d = $metrics['iterations']['values'] ?? null) {
                    $statsHtml .= $stat('iterations', number_format((int) ($d['count'] ?? 0)),
                        round($d['rate'] ?? 0, 2) . '/s');
                }

                // Thresholds
                $threshRows = '';
                foreach ($metrics as $mName => $metric) {
                    if (empty($metric['thresholds'])) continue;
                    foreach ($metric['thresholds'] as $expr => $result) {
                        $ok = (bool) ($result['ok'] ?? false);
                        $threshRows .= '<tr>'
                            . '<td><code>' . $h($mName) . '</code></td>'
                            . '<td><code>' . $h($expr) . '</code></td>'
                            . '<td>' . ($ok
                                ? '<span class="ok">&#x2713; ok</span>'
                                : '<span class="bad">&#x2717; failed</span>')
                            . '</td></tr>';
                    }
                }
                if ($threshRows) {
                    $threshHtml = '<h2>Thresholds</h2><table>'
                        . '<thead><tr><th>Metric</th><th>Expression</th><th>Result</th></tr></thead>'
                        . '<tbody>' . $threshRows . '</tbody></table>';
                }
            }
            $metricsSection = $statsHtml
                ? '<h2>Metrics</h2><div class="stats-grid">' . $statsHtml . '</div>' . $threshHtml
                : '';

            // ── Log ──────────────────────────────────────────────────────────
            $logSection = '';
            if (is_file("$dir/k6.log")) {
                $all      = file("$dir/k6.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $total    = count($all);
                $limit    = 300;
                $truncated = $total > $limit;
                $lines    = $truncated ? array_slice($all, -$limit) : $all;
                $truncNote = $truncated
                    ? '<p class="muted" style="font-size:11px;margin-bottom:4px">'
                      . 'Showing last ' . $limit . ' of ' . $total . ' lines &mdash; '
                      . '<a href="/k6-reports/' . rawurlencode($runName) . '/k6.log">download full log</a></p>'
                    : '';
                $logSection = '<h2>Log</h2>' . $truncNote
                    . '<div class="log-wrap"><pre>' . $h(implode("\n", $lines)) . '</pre></div>';
            }

            // ── Files ─────────────────────────────────────────────────────────
            $fileLinks = [];
            $fileMap = [
                'report.html'    => ['k6 HTML Report', 'primary'],
                'failures.jsonl' => ['Failures JSONL',  ''],
                'summary.json'   => ['Summary JSON',    ''],
                'k6.log'         => ['Full Log',        ''],
                'run-info.json'  => ['Run Info',        ''],
                'console.log'    => ['Console Log',     ''],
            ];
            if (is_file("$dir/failures.jsonl") && filesize("$dir/failures.jsonl") > 0) {
                $failHref = '/k6-reports/' . rawurlencode($runName) . '/failures';
                $fileLinks[] = '<a href="' . $failHref . '" class="file-link">&#x26A0; View Failures</a>';
            }
            foreach ($fileMap as $f => [$label, $cls]) {
                if (!is_file("$dir/$f")) continue;
                $href = '/k6-reports/' . rawurlencode($runName) . '/' . $f;
                $fileLinks[] = '<a href="' . $href . '" class="file-link ' . $cls . '">' . $label . '</a>';
            }
            $filesSection = $fileLinks
                ? '<h2>Files</h2><div class="file-links">' . implode('', $fileLinks) . '</div>'
                : '';

            // ── Assemble body ─────────────────────────────────────────────────
            $body = '<div class="breadcrumb"><a href="/">All runs</a> / ' . $h($runName) . '</div>'
                . '<div class="run-title-row">'
                . '<h1>' . $h($profile ?: $runName) . '</h1>'
                . $badge
                . '<span class="muted" style="font-size:13px;margin-left:4px">' . $h($ts) . '</span>'
                . '</div>'
                . $infoHtml
                . $metricsSection
                . $logSection
                . $filesSection;

            $shell('Run — ' . $runName, $body);
            exit;
        }
        // Not a valid run directory — fall through to file serving (will 404)
    }

    // ── Failures detail page: /k6-reports/<run>/failures ─────────────────────
    if (preg_match('#^([^/]+)/failures$#', $path, $m)) {
        $runName = rawurldecode($m[1]);
        $dir     = $inside($runName);
        $file    = $dir ? "$dir/failures.jsonl" : null;

        if (!$dir || !is_file($file)) {
            http_response_code(404);
            $shell('Not found', '<p class="muted">Run not found.</p>');
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
        $pretty = fn($v) => $h(is_string($v) ? $v
            : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $byFlow = [];
        foreach ($rows as $r) {
            $key = (($r['flow'] ?? '') ?: ($r['scenario'] ?? '')) . ' · ' . ($r['name'] ?? '');
            $byFlow[$key] = ($byFlow[$key] ?? 0) + 1;
        }
        arsort($byFlow);

        $total     = count($rows);
        $truncated = $total >= 1000 ? ' <span class="muted">(first 1000 shown)</span>' : '';
        $rawLink   = '/k6-reports/' . rawurlencode($runName) . '/failures.jsonl';
        $backLink  = '/k6-reports/' . rawurlencode($runName);

        $summaryRows = '';
        foreach ($byFlow as $k => $n) {
            $summaryRows .= '<tr><td>' . $h($k) . '</td>'
                . '<td style="text-align:right;font-family:var(--mono)">' . $n . '</td></tr>';
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
            ? '<h2>By flow and request</h2><table>'
              . '<thead><tr><th>Flow · request</th><th style="text-align:right">Count</th></tr></thead>'
              . '<tbody>' . $summaryRows . '</tbody></table>'
            : '';

        $body = '<div class="breadcrumb"><a href="/">All runs</a> / '
            . '<a href="' . $backLink . '">' . $h($runName) . '</a> / failures</div>'
            . '<h1>Failures</h1>'
            . '<p class="muted" style="font-size:13px;margin:6px 0 20px">'
            . $total . ' records' . $truncated
            . ' &ensp;·&ensp; <a href="' . $rawLink . '">raw .jsonl</a></p>'
            . $summaryTable
            . '<h2>Records</h2>'
            . '<table><thead><tr>'
            . '<th>Time / VU</th><th>Flow</th><th>What failed</th><th>Request</th><th>Details</th>'
            . '</tr></thead><tbody>' . $detailRows . '</tbody></table>';

        $shell('Failures — ' . $runName, $body);
        exit;
    }

    // ── File serving: /k6-reports/<run>/<file> ───────────────────────────────
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
        $shell('Not found', '<p class="muted">File not found.</p>');
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

// ── POST: run_task ────────────────────────────────────────────────────────────
if ($canTrigger
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'run_task'
) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $r = ['ok' => false, 'output' => 'Invalid security token — please reload.', 'exitCode' => -1];
    } else {
        $allowedProfiles = ['http', 'combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke', 'cleanup'];
        $profile = $_POST['profile'] ?? 'crawler';

        if (!in_array($profile, $allowedProfiles, true)) {
            $r = ['ok' => false, 'output' => "Unknown profile '$profile'.", 'exitCode' => -1];
        } else {
            $vars = ['env:PROFILE' => $profile];

            $urlOverride = trim($_POST['url_override'] ?? '');
            if ($urlOverride !== '' && preg_match('#^https?://#', $urlOverride)) {
                $vars['env:URL'] = rtrim($urlOverride, '/');
            }
            $hasLoad = !in_array($profile, ['cleanup', 'smoke'], true);
            $hasVus  = !in_array($profile, ['cleanup', 'smoke', 'admin'], true);
            if ($hasVus) {
                $vars['env:VUS'] = (string) max(1, min(500, (int) ($_POST['vus'] ?? 10)));
            }
            if (in_array($profile, ['combined', 'graphql', 'admin'], true)) {
                $vars['env:ADMIN_VUS'] = (string) max(0, min(50, (int) ($_POST['admin_vus'] ?? 2)));
            }
            if ($hasLoad) {
                $dur = $_POST['duration'] ?? '10m';
                $vars['env:DURATION'] = preg_match('/^\d+[smh]$/', $dur) ? $dur : '10m';
                $ramp = $_POST['ramp'] ?? '';
                if ($ramp !== '' && preg_match('/^\d+[smh]$/', $ramp)) {
                    $vars['env:RAMP'] = $ramp;
                }
            }
            if ($profile === 'http') {
                $httpMethod = strtoupper(trim($_POST['http_method'] ?? 'GET'));
                if (in_array($httpMethod, ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true)) {
                    $vars['env:HTTP_METHOD'] = $httpMethod;
                }
                $httpPath = trim($_POST['http_path'] ?? '/');
                if ($httpPath !== '' && $httpPath !== '/') $vars['env:HTTP_PATH'] = $httpPath;
                $httpBody = trim($_POST['http_body'] ?? '');
                if ($httpBody !== '') $vars['env:HTTP_BODY'] = $httpBody;
            }
            if (in_array($profile, ['combined', 'graphql', 'storefront', 'crawler'], true)) {
                if (($_POST['fpc'] ?? 'ON') === 'OFF') $vars['env:FPC'] = 'OFF';
            }
            if (in_array($profile, ['combined', 'graphql', 'admin', 'smoke', 'cleanup'], true)) {
                $adminPath = trim($_POST['admin_path'] ?? '');
                if ($adminPath !== '' && preg_match('#^[a-zA-Z0-9_/\-]+$#', $adminPath)) {
                    $vars['env:ADMIN_PATH'] = $adminPath;
                }
            }
            if ($profile === 'crawler') {
                $vars['env:MAX_DEPTH'] = (string) max(1, min(20, (int) ($_POST['max_depth'] ?? 5)));
            }
            if ($profile === 'smoke') {
                $sg = trim($_POST['smoke_groups'] ?? '');
                if ($sg !== '' && preg_match('/^[a-z,]+$/', $sg)) $vars['env:SMOKE_GROUPS'] = $sg;
            }
            if ($profile === 'cleanup') {
                if (!empty($_POST['cleanup_customers'])) $vars['env:CLEANUP_CUSTOMERS'] = '1';
                if (!empty($_POST['cleanup_admins']))    $vars['env:CLEANUP_ADMINS']    = '1';
            }
            // Magento admin credentials (for admin-using profiles)
            if (in_array($profile, ['combined', 'graphql', 'admin', 'smoke', 'cleanup'], true)) {
                $au = trim($_POST['admin_user'] ?? '');
                $ap = trim($_POST['admin_password'] ?? '');
                if ($au !== '') $vars['env:ADMIN_USER']     = $au;
                if ($ap !== '') $vars['env:ADMIN_PASSWORD'] = $ap;
            }

            $bau = trim($_POST['basic_auth_user'] ?? '');
            $bap = trim($_POST['basic_auth_password'] ?? '');
            if ($bau !== '') $vars['env:BASIC_AUTH_USER']     = $bau;
            if ($bap !== '') $vars['env:BASIC_AUTH_PASSWORD'] = $bap;

            $r = runTaskViaCli($cliBin, 'loadtesting-k6', $vars);
            $r['profile'] = $profile;
            $r['vars']    = $vars;
        }
    }
    $_SESSION['csrf']  = bin2hex(random_bytes(24));
    $_SESSION['flash'] = ['type' => 'trigger', 'result' => $r];
    header('Location: /', true, 303);
    exit;
}

// ── POST: delete_run ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete_run'
) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $r = ['ok' => false, 'error' => 'Invalid security token — please reload.'];
    } else {
        $r = deleteRun($reportsBase, basename($_POST['run'] ?? ''));
    }
    $_SESSION['csrf']  = bin2hex(random_bytes(24));
    $_SESSION['flash'] = ['type' => 'delete', 'result' => $r];
    header('Location: /', true, 303);
    exit;
}

// ── Read session flash (set by POST-Redirect-GET above) ───────────────────────
$flash         = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$triggerResult = ($flash['type'] ?? '') === 'trigger' ? $flash['result'] : null;
$deleteResult  = ($flash['type'] ?? '') === 'delete'  ? $flash['result'] : null;

$csrf = $h($_SESSION['csrf']);

$bannerHtml = '';
if ($triggerResult !== null) {
    if ($triggerResult['ok']) {
        $profile = $h($triggerResult['profile'] ?? '');
        $varList = implode('&ensp;', array_map(
            fn($k, $v) => '<code>' . $h($k) . '=' . $h($v) . '</code>',
            array_keys($triggerResult['vars'] ?? []), $triggerResult['vars'] ?? []
        ));
        $cliOut = $triggerResult['output'] !== ''
            ? '<pre class="result-pre">' . $h($triggerResult['output']) . '</pre>' : '';
        $bannerHtml = '<div class="banner banner-ok">'
            . '<strong>Task queued</strong> — profile: <code>' . $profile . '</code><br>'
            . '<span class="banner-meta">' . $varList . '</span>'
            . $cliOut
            . '<span class="banner-hint">Follow progress in the '
            . '<a href="https://console.upsun.com/" target="_blank" rel="noopener">Upsun console</a>'
            . ' or via <code>upsun activity:log</code>. '
            . 'Results appear in the table below once finished.</span></div>';
    } else {
        $out  = $h($triggerResult['output'] ?? 'Unknown error');
        $exit = isset($triggerResult['exitCode']) && $triggerResult['exitCode'] >= 0
            ? ' <span class="exit-code">exit ' . (int) $triggerResult['exitCode'] . '</span>' : '';
        $bannerHtml = '<div class="banner banner-err"><strong>Failed to start task' . $exit
            . '</strong><pre class="result-pre">' . $out . '</pre></div>';
    }
}

if ($canTrigger) {
    $envLabel    = '';
    $adminOk     = (getenv('ADMIN_USER') !== false && getenv('ADMIN_USER') !== '');
    $adminBadge  = $adminOk
        ? '<span class="notice-admin admin-ok">&#x2713; Admin credentials set — admin and customer flows will run.</span>'
        : '<span class="notice-admin">&#x26A0; <span><code>ADMIN_USER</code> / <code>ADMIN_PASSWORD</code> not set — '
          . 'admin and customer flows are disabled. '
          . '<a href="https://docs.upsun.com/development/variables/set-variables.html" target="_blank" rel="noopener">How to set env vars</a></span></span>';

    $generalProfiles = ['http', 'crawler'];
    $magentoProfiles = ['combined', 'graphql', 'storefront', 'admin', 'smoke', 'cleanup'];
    $optGeneral = implode('', array_map(
        fn($p) => '<option value="' . $p . '"' . ($p === 'crawler' ? ' selected' : '') . '>' . $p . '</option>',
        $generalProfiles
    ));
    $optMagento = implode('', array_map(
        fn($p) => '<option value="' . $p . '">' . $p . '</option>',
        $magentoProfiles
    ));
    $optHtml = '<optgroup label="General">' . $optGeneral . '</optgroup>'
             . '<optgroup label="Magento">' . $optMagento . '</optgroup>';

    $triggerSection = <<<HTML
    <section class="card">
      <h2>Run a load test{$envLabel}</h2>
      <p class="section-intro">Triggers an on-demand k6 load test in its own isolated container — the web UI stays responsive while it runs.</p>
      <form method="post" action="/" id="run-form">
        <input type="hidden" name="action" value="run_task">
        <input type="hidden" name="csrf"   value="{$csrf}">
        <div class="form-grid">

          <div class="form-group">
            <label for="f-profile">Profile</label>
            <select id="f-profile" name="profile" class="form-control" onchange="toggleFields(this.value)">{$optHtml}</select>
          </div>

          <!-- URL (all profiles) -->
          <div class="form-group form-group-wide" id="fg-url">
            <label for="f-url">URL override <span class="label-hint">(optional — uses task default if blank)</span></label>
            <input id="f-url" name="url_override" type="url" class="form-control" placeholder="https://staging.yourshop.com">
          </div>

          <!-- HTTP method: http -->
          <div class="form-group" id="fg-http-method">
            <label for="f-http-method">HTTP method</label>
            <select id="f-http-method" name="http_method" class="form-control">
              <option value="GET" selected>GET</option>
              <option value="POST">POST</option>
              <option value="PUT">PUT</option>
              <option value="PATCH">PATCH</option>
              <option value="DELETE">DELETE</option>
              <option value="HEAD">HEAD</option>
            </select>
          </div>

          <!-- HTTP path: http -->
          <div class="form-group" id="fg-http-path">
            <label for="f-http-path">Path <span class="label-hint">(default: /)</span></label>
            <input id="f-http-path" name="http_path" type="text" class="form-control" placeholder="/api/v1/products">
          </div>

          <!-- HTTP body: http -->
          <div class="form-group form-group-wide" id="fg-http-body">
            <label for="f-http-body">Request body <span class="label-hint">(optional — for POST/PUT/PATCH)</span></label>
            <input id="f-http-body" name="http_body" type="text" class="form-control" placeholder='{"key":"value"}'>
          </div>

          <!-- VUs: combined, graphql, storefront, crawler -->
          <div class="form-group" id="fg-vus">
            <label for="f-vus">VUs</label>
            <input id="f-vus" name="vus" type="number" class="form-control" min="1" max="500" value="10">
          </div>

          <!-- Admin VUs: combined, graphql, admin -->
          <div class="form-group" id="fg-admin-vus">
            <label for="f-admin-vus">Admin VUs</label>
            <input id="f-admin-vus" name="admin_vus" type="number" class="form-control" min="0" max="50" value="2">
          </div>

          <!-- Duration: combined, graphql, storefront, admin, crawler -->
          <div class="form-group" id="fg-duration">
            <label for="f-duration">Duration</label>
            <input id="f-duration" name="duration" type="text" class="form-control" value="10m" placeholder="10m / 30m / 1h" pattern="\\d+[smh]">
          </div>

          <!-- Ramp: combined, graphql, storefront, admin, crawler -->
          <div class="form-group" id="fg-ramp">
            <label for="f-ramp">Ramp-up <span class="label-hint">(optional)</span></label>
            <input id="f-ramp" name="ramp" type="text" class="form-control" placeholder="1m" pattern="\\d+[smh]">
          </div>

          <!-- FPC: combined, graphql, storefront, crawler -->
          <div class="form-group" id="fg-fpc">
            <label for="f-fpc">Full-Page Cache</label>
            <select id="f-fpc" name="fpc" class="form-control">
              <option value="ON">ON (warm cache, realistic)</option>
              <option value="OFF">OFF (bust cache, worst-case)</option>
            </select>
          </div>

          <!-- Admin path: combined, graphql, admin, smoke, cleanup -->
          <div class="form-group" id="fg-admin-path">
            <label for="f-admin-path">Admin path <span class="label-hint">(default: admin)</span></label>
            <input id="f-admin-path" name="admin_path" type="text" class="form-control" placeholder="admin">
          </div>

          <!-- Crawler options: crawler -->
          <div class="form-group" id="fg-crawler">
            <label for="f-max-depth">Max crawl depth</label>
            <input id="f-max-depth" name="max_depth" type="number" class="form-control" min="1" max="20" value="5">
          </div>

          <!-- Smoke options: smoke -->
          <div class="form-group form-group-wide" id="fg-smoke">
            <label for="f-smoke-groups">Smoke groups <span class="label-hint">(comma-separated: web, graphql, admin, varnish)</span></label>
            <input id="f-smoke-groups" name="smoke_groups" type="text" class="form-control" placeholder="web,graphql,admin,varnish">
          </div>

          <!-- Cleanup options: cleanup -->
          <div class="form-group form-group-wide form-group-inline" id="fg-cleanup">
            <label>Cleanup options</label>
            <div class="form-check-row">
              <input type="checkbox" id="f-cleanup-customers" name="cleanup_customers" value="1">
              <label for="f-cleanup-customers">Delete seeded customers (<code>CLEANUP_CUSTOMERS</code>)</label>
            </div>
            <div class="form-check-row">
              <input type="checkbox" id="f-cleanup-admins" name="cleanup_admins" value="1">
              <label for="f-cleanup-admins">Delete seeded admin users (<code>CLEANUP_ADMINS</code>)</label>
            </div>
          </div>

          <!-- Magento admin credentials: combined, graphql, admin, smoke, cleanup -->
          <div class="form-group" id="fg-admin-creds-user">
            <label for="f-admin-user">Magento admin user <span class="label-hint">(leave blank to use env var)</span></label>
            <input id="f-admin-user" name="admin_user" type="text" class="form-control" autocomplete="off" placeholder="admin">
          </div>
          <div class="form-group" id="fg-admin-creds-pass">
            <label for="f-admin-pass">Magento admin password <span class="label-hint">(leave blank to use env var)</span></label>
            <input id="f-admin-pass" name="admin_password" type="password" class="form-control" autocomplete="new-password">
          </div>

          <!-- HTTP basic auth: crawler, combined, graphql, storefront, admin, smoke -->
          <div class="form-group" id="fg-basic-auth-user">
            <label for="f-bau">HTTP Basic auth user <span class="label-hint">(optional — staging environments)</span></label>
            <input id="f-bau" name="basic_auth_user" type="text" class="form-control" autocomplete="off" placeholder="staging">
          </div>
          <div class="form-group" id="fg-basic-auth-pass">
            <label for="f-bap">HTTP Basic auth password <span class="label-hint">(optional)</span></label>
            <input id="f-bap" name="basic_auth_password" type="password" class="form-control" autocomplete="new-password">
          </div>

        </div><!-- /.form-grid -->

        <!-- Admin credential notice: combined, graphql, admin, smoke, cleanup -->
        <div id="fg-admin-notice" style="margin:10px 0 14px">
          {$adminBadge}
        </div>

        <div class="btn-row">
          <button type="submit" class="btn-primary">Start task</button>
          <button type="button" class="btn-ghost" onclick="runProfile('cleanup')">Run cleanup</button>
        </div>
      </form>
      {$bannerHtml}
      <script>
      (function(){
        // Which field groups each profile shows
        var GROUPS = {
          http:       ['url','vus','duration','ramp','http-method','http-path','http-body','basic-auth-user','basic-auth-pass'],
          crawler:    ['url','vus','duration','ramp','fpc','crawler','basic-auth-user','basic-auth-pass'],
          combined:   ['url','vus','admin-vus','duration','ramp','fpc','admin-path','admin-creds-user','admin-creds-pass','basic-auth-user','basic-auth-pass','admin-notice'],
          graphql:    ['url','vus','admin-vus','duration','ramp','fpc','admin-path','admin-creds-user','admin-creds-pass','basic-auth-user','basic-auth-pass','admin-notice'],
          storefront: ['url','vus','duration','ramp','fpc','basic-auth-user','basic-auth-pass'],
          admin:      ['url','admin-vus','duration','ramp','admin-path','admin-creds-user','admin-creds-pass','basic-auth-user','basic-auth-pass','admin-notice'],
          smoke:      ['url','smoke','admin-path','admin-creds-user','admin-creds-pass','basic-auth-user','basic-auth-pass','admin-notice'],
          cleanup:    ['admin-path','admin-creds-user','admin-creds-pass','cleanup','admin-notice'],
        };
        var ALL = ['url','vus','admin-vus','duration','ramp','fpc','crawler','smoke','cleanup','admin-path','admin-creds-user','admin-creds-pass','basic-auth-user','basic-auth-pass','admin-notice','http-method','http-path','http-body'];
        function toggleFields(p){
          var show = GROUPS[p] || [];
          ALL.forEach(function(g){
            var el = document.getElementById('fg-'+g);
            if(el) el.style.display = show.includes(g) ? '' : 'none';
          });
        }
        window.toggleFields = toggleFields;
        window.runProfile = function(p){
          document.getElementById('f-profile').value = p;
          toggleFields(p);
          document.getElementById('run-form').submit();
        };
        toggleFields(document.getElementById('f-profile').value);
      })();
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

$deleteBannerHtml = '';
if ($deleteResult !== null) {
    if ($deleteResult['ok']) {
        $deleteBannerHtml = '<div class="banner banner-ok" style="margin-bottom:16px">'
            . '<strong>Run deleted:</strong> <code>' . $h($deleteResult['run'] ?? '') . '</code></div>';
    } else {
        $deleteBannerHtml = '<div class="banner banner-err" style="margin-bottom:16px">'
            . '<strong>Delete failed:</strong> ' . $h($deleteResult['error'] ?? 'Unknown error') . '</div>';
    }
}

$runsHtml = runsTable($reportsBase, $h, $csrf);

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
    <span class="wordmark-text">upsun</span>
    <span class="header-sep">/</span>
    <span class="header-product">k6 Load Testing</span>
  </a>
  <div class="header-spacer"></div>
  <button class="theme-btn" id="theme-btn" aria-label="Toggle colour scheme">
    <svg id="theme-icon-sun" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" style="width:14px;height:14px;display:none"><circle cx="10" cy="10" r="4"/><line x1="10" y1="2" x2="10" y2="0.5"/><line x1="10" y1="18" x2="10" y2="19.5"/><line x1="2" y1="10" x2=".5" y2="10"/><line x1="18" y1="10" x2="19.5" y2="10"/><line x1="4.2" y1="4.2" x2="3.1" y2="3.1"/><line x1="15.8" y1="15.8" x2="16.9" y2="16.9"/><line x1="4.2" y1="15.8" x2="3.1" y2="16.9"/><line x1="15.8" y1="4.2" x2="16.9" y2="3.1"/></svg>
    <svg id="theme-icon-moon" viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px"><path d="M17.3 13.4A7 7 0 0 1 6.6 2.7a8 8 0 1 0 10.7 10.7z"/></svg>
    <span id="theme-label">Dark</span>
  </button>
</header>

<main class="site-main">

  <h1 class="page-title">k6 Load Testing</h1>
  <p class="page-sub">On-demand k6 load testing — trigger, monitor, and review results for any web application.</p>

  <?= $triggerSection ?>

  <section class="card">
    <h2>CLI commands</h2>
    <p class="section-intro">Run tasks directly from your terminal — pass <code>--wait</code> to block until done.</p>
    <pre class="cli-pre"># Smoke test (one VU, every flow once)
upsun task:run loadtesting-k6 --variable env:PROFILE=smoke --wait

# Combined load test (25 VUs, 3 min)
upsun task:run loadtesting-k6 --wait

# Custom storefront run
upsun task:run loadtesting-k6 \
  --variable env:PROFILE=storefront \
  --variable env:VUS=30 \
  --variable env:DURATION=15m \
  --wait

# Cleanup k6-created data
upsun task:run loadtesting-k6 --variable env:PROFILE=cleanup --wait</pre>
  </section>

  <section class="card">
    <h2>Runs</h2>
    <p class="section-intro">Click a timestamp to view the full report — metrics, thresholds, log, and file downloads. Reload after a task finishes to see new results.</p>
    <?= $deleteBannerHtml ?>
    <?= $runsHtml ?>
  </section>

</main>

<footer class="site-footer">
  made with <span class="heart">♥</span> by
  <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
</footer>

<script>
(function(){
  var html=document.documentElement,btn=document.getElementById('theme-btn'),
      sun=document.getElementById('theme-icon-sun'),moon=document.getElementById('theme-icon-moon'),
      lbl=document.getElementById('theme-label');
  function apply(t){
    html.setAttribute('data-theme',t);
    var dark=t==='dark';
    sun.style.display=dark?'none':'block';moon.style.display=dark?'block':'none';
    lbl.textContent=dark?'Dark':'Light';
  }
  apply(localStorage.getItem('theme')||'dark');
  btn.addEventListener('click',function(){
    var next=html.getAttribute('data-theme')==='dark'?'light':'dark';
    localStorage.setItem('theme',next);apply(next);
  });
})();
</script>

</body>
</html>
