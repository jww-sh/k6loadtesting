<?php
/**
 * k6 report detail router — served from /k6-reports/* via the Upsun web location.
 *
 *   /k6-reports/              → redirect to / (runs list is on the main page)
 *   /k6-reports/<run>/failures → failures detail page
 *   /k6-reports/<run>/<file>   → serve report.html, summary.json, k6.log, etc.
 */

$appDir = (string) (getenv('PLATFORM_APP_DIR') ?: dirname(__DIR__, 3));
$base   = realpath($appDir . '/var/k6-reports');

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$path = trim(substr(
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    strlen('/k6-reports')
), '/');

// ── Allowed file types ────────────────────────────────────────────────────────

$types = [
    'html'  => 'text/html; charset=utf-8',
    'json'  => 'application/json',
    'jsonl' => 'text/plain; charset=utf-8',
    'log'   => 'text/plain; charset=utf-8',
];

// ── Path helpers ──────────────────────────────────────────────────────────────

$inside = function (string $rel) use ($base): ?string {
    if ($base === false) return null;
    $file = realpath($base . '/' . $rel);
    return $file && str_starts_with($file, $base . '/') ? $file : null;
};

$readJsonLines = function (string $file, int $limit = 1000): array {
    $rows = [];
    $fh   = @fopen($file, 'r');
    while ($fh && ($line = fgets($fh)) !== false && count($rows) < $limit) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    $fh && fclose($fh);
    return $rows;
};

// ── Shared page shell ─────────────────────────────────────────────────────────

$shell = function (string $title, string $body) use ($h): void {
    header('Content-Type: text/html; charset=utf-8');
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<HTML
    <!doctype html>
    <html lang="en" data-theme="dark">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>{$esc($title)}</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700;900&family=Space+Mono&display=swap" rel="stylesheet">
      <style>
    :root{--bg:#0E1113;--surface:#191C1E;--border:#2B3134;--border-hi:#41484D;
      --text:#E1E3E4;--text-muted:#B4BABD;--text-dim:#646F75;
      --purple:#6046FF;--purple-hover:#4D38CC;--purple-light:#806BFF;--purple-btn:#EFEDF5;
      --accent:#D5F800;
      --ok-bg:#1a2e1a;--ok-border:#2d5a2d;--ok-text:#86efac;
      --err-bg:#2e1a1a;--err-border:#5a2d2d;--err-text:#fca5a5;
      --warn-bg:#2a2510;--warn-border:#5a4f20;--warn-text:#fde68a;
      --font:'Space Grotesk','Instrument Sans',sans-serif;
      --mono:'Space Mono',ui-monospace,monospace}
    [data-theme="light"]{--bg:#F7F8F8;--surface:#fff;--border:#E1E3E4;--border-hi:#B4BABD;
      --text:#2B3134;--text-muted:#646F75;--text-dim:#8A9399;
      --purple:#4D38CC;--purple-hover:#3a2b99;--purple-light:#6046FF;
      --ok-bg:#d4edda;--ok-border:#a8d5b5;--ok-text:#155724;
      --err-bg:#f8d7da;--err-border:#f5c6c2;--err-text:#721c24;
      --warn-bg:#fff3cd;--warn-border:#ffc107;--warn-text:#856404}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html{font-size:16px;scroll-behavior:smooth}
    body{background:var(--bg);color:var(--text);font-family:var(--font);line-height:1.6;
      min-height:100vh;display:flex;flex-direction:column;transition:background .3s,color .3s}
    a{color:var(--purple-light);text-decoration:none;transition:color .2s}
    a:hover{color:var(--purple);text-decoration:underline}
    strong{color:var(--text-muted);font-weight:700}
    code{font-family:var(--mono);font-size:.84em;background:var(--border);
      color:var(--text);padding:.1em .4em;border-radius:3px}
    .site-header{background:var(--surface);border-bottom:1px solid var(--border);
      padding:16px 40px;display:flex;align-items:center;gap:16px;flex-shrink:0;
      transition:background .3s,border-color .3s}
    .header-wordmark{display:flex;align-items:center;gap:10px;text-decoration:none}
    .header-logo{height:24px;width:auto;display:block}
    .header-title{font-size:16px;font-weight:500;color:var(--text);
      margin-right:auto;transition:color .3s}
    .header-link{color:var(--text);font-size:14px;font-weight:500;padding:6px 13px;
      border-radius:8px;border:1px solid transparent;transition:all .2s;text-decoration:none}
    .header-link:hover{background:var(--border);border-color:var(--border-hi);
      color:var(--text);text-decoration:none}
    .theme-toggle{background:transparent;border:1px solid var(--border);border-radius:8px;
      padding:6px 11px;cursor:pointer;font-size:1rem;color:var(--text);
      transition:all .2s;flex-shrink:0;line-height:1}
    .theme-toggle:hover{background:var(--border)}
    .site-main{flex:1;padding:40px;max-width:1100px;width:100%;margin:0 auto}
    .site-footer{background:var(--surface);border-top:1px solid var(--border);
      padding:20px 40px;text-align:center;color:var(--text-dim);font-size:13px;
      flex-shrink:0;transition:background .3s,border-color .3s}
    .site-footer .heart{color:var(--accent)}
    .site-footer a{color:inherit}
    .site-footer a:hover{text-decoration:underline;color:var(--text)}
    .breadcrumb{font-size:13px;color:var(--text-dim);margin-bottom:20px}
    .breadcrumb a{color:var(--purple-light)}
    h1{font-size:26px;font-weight:900;margin-bottom:6px}
    h2{font-size:16px;font-weight:700;color:var(--text-muted);
      margin:28px 0 10px;padding-top:28px;border-top:1px solid var(--border)}
    h2:first-of-type{margin-top:0;padding-top:0;border-top:none}
    .meta{color:var(--text-dim);font-size:13px;margin-bottom:24px}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{padding:9px 12px;border-bottom:2px solid var(--border);color:var(--text-dim);
      font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;
      text-align:left;white-space:nowrap}
    td{padding:11px 12px;border-bottom:1px solid var(--border);vertical-align:top}
    tr:last-child td{border-bottom:none}
    tbody tr:hover td{background:rgba(255,255,255,.02)}
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;
      font-weight:700;letter-spacing:.03em}
    .badge-ok  {background:var(--ok-bg);color:var(--ok-text)}
    .badge-err {background:var(--err-bg);color:var(--err-text)}
    .badge-warn{background:var(--warn-bg);color:var(--warn-text)}
    .ok  {color:var(--ok-text)}
    .bad {color:var(--err-text);font-weight:600}
    .muted{color:var(--text-dim)}
    pre{background:var(--bg);border:1px solid var(--border-hi);border-radius:6px;
      padding:14px 18px;font-family:var(--mono);font-size:12px;color:var(--text);
      overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:6px 0}
    details{margin:4px 0}summary{cursor:pointer;color:var(--text-muted);font-size:13px}
    summary:hover{color:var(--text)}
    .card{background:var(--surface);border:1px solid var(--border);border-radius:8px;
      padding:20px 24px;margin-bottom:16px}
    .fail-row td:first-child{white-space:nowrap;font-size:12px;color:var(--text-dim)}
    .fail-what{font-size:13px}
    .fail-req{font-family:var(--mono);font-size:12px}
    .exception{color:var(--err-text);font-weight:600}
    @media(max-width:640px){
      .site-header{padding:12px 16px}.site-main{padding:24px 16px}
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
      <a href="/" class="header-link">&#x2190; All runs</a>
      <button class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">
        <span id="theme-icon">🌙</span>
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
      var html=document.documentElement,btn=document.getElementById('theme-toggle'),
          icon=document.getElementById('theme-icon');
      function apply(t){html.setAttribute('data-theme',t);icon.textContent=t==='dark'?'🌙':'☀️';}
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

// ── Route: bare index → redirect to main page ─────────────────────────────────

if ($path === '') {
    header('Location: /', true, 302);
    exit;
}

// ── Route: failures page ──────────────────────────────────────────────────────

if (preg_match('#^([^/]+)/failures$#', $path, $m)) {
    $runName = rawurldecode($m[1]);
    $dir     = $inside($runName);
    $file    = $dir ? "$dir/failures.jsonl" : null;

    if (!$dir || !is_file($file)) {
        http_response_code(404);
        $shell('Not found — k6', '<p class="muted">Run not found.</p>');
        exit;
    }

    $rows   = $readJsonLines($file);
    $pretty = fn($v) => $h(is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Group by flow+name for the summary
    $byFlow = [];
    foreach ($rows as $r) {
        $key = (($r['flow'] ?? '') ?: ($r['scenario'] ?? '')) . ' · ' . ($r['name'] ?? '');
        $byFlow[$key] = ($byFlow[$key] ?? 0) + 1;
    }
    arsort($byFlow);

    $total    = count($rows);
    $truncated = $total >= 1000 ? ' <span class="muted">(first 1000 shown)</span>' : '';
    $rawLink  = '/k6-reports/' . rawurlencode($runName) . '/failures.jsonl';

    $summaryRows = '';
    foreach ($byFlow as $k => $n) {
        $summaryRows .= '<tr><td>' . $h($k) . '</td><td style="text-align:right">' . $n . '</td></tr>';
    }

    $detailRows = '';
    foreach ($rows as $r) {
        $req = $r['request']  ?? [];
        $res = $r['response'] ?? [];
        $isEx = ($r['type'] ?? '') === 'exception';

        $what = $isEx
            ? '<span class="exception">exception:</span> ' . $h($r['message'] ?? '')
            : '<strong>' . $h($r['name'] ?? '') . '</strong>'
              . ($r['failed'] ?? [] ? '<br><span class="muted">' . $h(implode('; ', $r['failed'])) . '</span>' : '');

        $reqCell = isset($req['url'])
            ? '<code class="fail-req">' . $h(($req['method'] ?? '') . ' ' . $req['url']) . '</code>'
              . ' <span class="badge ' . (($res['status'] ?? 0) >= 400 ? 'badge-err' : 'badge-ok') . '">'
              . $h((string)($res['status'] ?? '')) . '</span>'
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

        $detailRows .= <<<ROW
        <tr class="fail-row">
          <td><div>{$ts}</div><div class="muted">{$vu}</div></td>
          <td><code>{$flow}</code></td>
          <td class="fail-what">{$what}</td>
          <td>{$reqCell}</td>
          <td>{$detailCell}</td>
        </tr>
        ROW;
    }

    $summaryTable = $summaryRows
        ? <<<HTML
          <h2>By flow and request</h2>
          <table>
            <thead><tr><th>Flow · request</th><th style="text-align:right">Count</th></tr></thead>
            <tbody>{$summaryRows}</tbody>
          </table>
          HTML
        : '';

    $body = <<<HTML
    <div class="breadcrumb"><a href="/">All runs</a> / {$h($runName)} / failures</div>
    <h1>Failures</h1>
    <p class="meta">
      {$total} records{$truncated} &ensp;·&ensp;
      <a href="{$rawLink}">raw .jsonl</a>
    </p>

    {$summaryTable}

    <h2>Records</h2>
    <table>
      <thead>
        <tr>
          <th>Time / VU</th><th>Flow</th><th>What failed</th>
          <th>Request</th><th>Details</th>
        </tr>
      </thead>
      <tbody>{$detailRows}</tbody>
    </table>
    HTML;

    $shell('Failures — ' . $runName, $body);
    exit;
}

// ── Route: serve a single report file ────────────────────────────────────────

$file = $inside(rawurldecode($path));
$ext  = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';

if (!$file || !is_file($file) || !isset($types[$ext])) {
    http_response_code(404);
    $shell('Not found — k6', '<p class="muted">File not found.</p>');
    exit;
}

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($file));
readfile($file);
