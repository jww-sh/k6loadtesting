<?php
/**
 * k6 Load Testing — script browser + task trigger
 *
 * Routes:
 *   GET  /                  landing page with file tree and task-run form
 *   GET  /?file=<rel>       view a file inside k6/
 *   POST /  action=run_task trigger the loadtesting-k6 Upsun task via the API
 *
 * Task trigger requires one project-level env var (never committed):
 *   upsun variable:create -l project --prefix env: --name UPSUN_CLI_TOKEN \
 *     --value "<your-api-token>" --sensitive true --visible-build false
 *
 * The app reads PLATFORM_PROJECT + PLATFORM_ENVIRONMENT automatically.
 * Reports appear at /k6-reports/ once a run completes.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

session_start();

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$k6Root  = realpath(__DIR__ . '/../k6');
$viewable = ['js', 'sh', 'php', 'md', 'json', 'yaml', 'yml', 'txt'];
$skipNames = ['k6', 'node_modules', '.git'];   // skip binary and tooling dirs

$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ── Upsun CLI helper ──────────────────────────────────────────────────────────

/**
 * Run the loadtesting-k6 task by shelling out to the Upsun CLI binary.
 *
 * The CLI reads UPSUN_CLI_TOKEN from the environment for auth; project and
 * environment are auto-detected from PLATFORM_PROJECT / PLATFORM_ENVIRONMENT.
 * proc_open() with an array avoids shell injection — no escaping needed.
 *
 * Returns ['ok' => bool, 'output' => string, 'exitCode' => int].
 */
function runTaskViaCli(string $cliBin, string $taskId, array $variables): array
{
    // Build argv array — no shell involved, so values are passed as-is.
    $cmd = [$cliBin, 'task:run', $taskId, '--yes'];
    foreach ($variables as $key => $value) {
        $cmd[] = '--variable';
        $cmd[] = "$key=$value";
    }

    $desc = [
        0 => ['pipe', 'r'],  // stdin  (unused)
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
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

// ── Task trigger: detect capability ──────────────────────────────────────────

$apiToken      = (string) (getenv('UPSUN_CLI_TOKEN') ?: '');
$environmentId = (string) (getenv('PLATFORM_ENVIRONMENT') ?: '');
// CLI binary is installed to bin/upsun relative to the app root by the build hook.
$cliBin        = rtrim((string) (getenv('PLATFORM_APP_DIR') ?: ''), '/') . '/bin/upsun';
$canTrigger    = $apiToken !== '' && is_executable($cliBin);

// Ensure CSRF token exists
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

// ── Task trigger: handle POST ─────────────────────────────────────────────────

$triggerResult = null;   // null | ['ok' => bool, ...]

if ($canTrigger
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'run_task'
) {
    // CSRF check
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $triggerResult = ['ok' => false, 'message' => 'Invalid security token — please reload and try again.'];
    } else {
        // Allowed profiles
        $allowedProfiles = ['combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke', 'cleanup'];
        $profile = $_POST['profile'] ?? 'combined';

        if (!in_array($profile, $allowedProfiles, true)) {
            $triggerResult = ['ok' => false, 'message' => "Unknown profile '$profile'."];
        } else {
            // Build variable map
            $vars = ['env:PROFILE' => $profile];

            $nonCleanupProfiles = ['combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke'];
            if (in_array($profile, $nonCleanupProfiles, true)) {
                $vus = max(1, min(500, (int) ($_POST['vus'] ?? 25)));
                $vars['env:VUS'] = (string) $vus;

                if (in_array($profile, ['combined', 'graphql', 'admin', 'smoke'], true)) {
                    $adminVus = max(0, min(50, (int) ($_POST['admin_vus'] ?? 2)));
                    $vars['env:ADMIN_VUS'] = (string) $adminVus;
                }

                if (!in_array($profile, ['smoke'], true)) {
                    $duration = $_POST['duration'] ?? '3m';
                    if (!preg_match('/^\d+[smh]$/', $duration)) {
                        $duration = '3m';
                    }
                    $vars['env:DURATION'] = $duration;
                }
            }

            // Optional URL override
            $urlOverride = trim($_POST['url_override'] ?? '');
            if ($urlOverride !== '' && preg_match('#^https?://#', $urlOverride)) {
                $vars['env:URL'] = rtrim($urlOverride, '/');
            }

            $result = runTaskViaCli($cliBin, 'loadtesting-k6', $vars);
            $triggerResult = [
                'ok'      => $result['ok'],
                'output'  => $result['output'],
                'exitCode'=> $result['exitCode'],
                'profile' => $profile,
                'vars'    => $vars,
            ];
        }
    }
    // Rotate CSRF after use
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

// ── File tree helpers ─────────────────────────────────────────────────────────

function buildTree(string $dir, string $relBase, array $skipNames, array $viewable): array
{
    $dirs = $files = [];
    foreach (scandir($dir, SCANDIR_SORT_ASCENDING) as $name) {
        if ($name[0] === '.' || in_array($name, $skipNames, true)) {
            continue;
        }
        $abs = "$dir/$name";
        $rel = $relBase === '' ? $name : "$relBase/$name";
        if (is_dir($abs)) {
            $child = buildTree($abs, $rel, $skipNames, $viewable);
            if ($child['dirs'] || $child['files']) {
                $dirs[] = ['name' => $name, 'rel' => $rel, 'children' => $child];
            }
        } elseif (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $viewable, true)) {
            $files[] = ['name' => $name, 'rel' => $rel, 'size' => filesize($abs)];
        }
    }
    return ['dirs' => $dirs, 'files' => $files];
}

function renderTree(array $tree, callable $h): string
{
    $out = '<ul>';
    foreach ($tree['dirs'] as $d) {
        $out .= '<li class="dir"><span>&#x1F4C1; ' . $h($d['name']) . '/</span>'
              . renderTree($d['children'], $h) . '</li>';
    }
    foreach ($tree['files'] as $f) {
        $icon = match (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION))) {
            'js'   => '&#x1F4DC;',
            'sh'   => '&#x2699;&#xFE0F;',
            'php'  => '&#x1F418;',
            'md'   => '&#x1F4D6;',
            'json' => '&#x1F4CB;',
            default => '&#x1F4C4;',
        };
        $kb  = $f['size'] < 1024 ? $f['size'] . '\xC2\xA0B' : round($f['size'] / 1024, 1) . '\xC2\xA0KB';
        $out .= '<li>' . $icon . ' <a href="/?file=' . rawurlencode($f['rel']) . '">' . $h($f['name']) . '</a>'
              . ' <small class="muted">' . $kb . '</small></li>';
    }
    return $out . '</ul>';
}

function resolveInsideK6(string $rel, string $k6Root): ?string
{
    $abs = realpath("$k6Root/$rel");
    if ($abs === false || !is_file($abs)) {
        return null;
    }
    if (!str_starts_with($abs, $k6Root . '/')) {
        return null;   // traversal attempt
    }
    return $abs;
}

// ── CSS ───────────────────────────────────────────────────────────────────────

$css = <<<'CSS'
*, *::before, *::after { box-sizing: border-box; }
body  { font: 15px/1.6 system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #222; }
a     { color: #0b57d0; text-decoration: none; }
a:hover { text-decoration: underline; }

/* Nav */
.nav { background: #1a1a2e; color: #eee; padding: .7rem 2rem;
       display: flex; align-items: center; gap: 2rem; }
.nav-title { margin: 0; font-size: 1.05rem; color: #fff; flex: 1;
             font-weight: 600; letter-spacing: .01em; }
.nav a { color: #8ab4f8; font-size: .9rem; }

/* Layout */
.wrap    { display: flex; min-height: calc(100vh - 42px); }
.sidebar { width: 270px; min-width: 200px; background: #fff;
           border-right: 1px solid #e0e0e0; padding: 1.1rem .9rem;
           overflow-y: auto; flex-shrink: 0; }
.main    { flex: 1; padding: 2rem; overflow: auto; min-width: 0; }

/* File tree */
.sidebar ul { list-style: none; padding-left: 1rem; margin: .1rem 0; }
.sidebar > ul { padding-left: 0; }
.sidebar li  { line-height: 1.75; }
.sidebar .dir > span { font-weight: 600; color: #444; font-size: .88rem; }
.sidebar .muted { color: #bbb; font-size: .76rem; margin-left: .25rem; }
.sidebar a { font-size: .87rem; font-family: ui-monospace, monospace; }

/* File view */
.breadcrumb { font-size: .88rem; color: #666; margin-bottom: .75rem; }
.file-meta  { font-size: .81rem; color: #999; margin-bottom: .6rem; }
pre.code    { background: #1e1e1e; color: #d4d4d4; padding: 1.2rem 1.4rem;
              overflow-x: auto; font-size: 13px; border-radius: 6px;
              line-height: 1.55; white-space: pre; tab-size: 2; margin: 0; }

/* Cards */
.card { background: #fff; border: 1px solid #dde1e7; border-radius: 8px;
        padding: 1.2rem 1.4rem; margin-bottom: 1.4rem; }
.card h2 { margin: 0 0 .6rem; font-size: 1rem; }
.card p  { margin: 0 0 .8rem; font-size: .9rem; color: #444; line-height: 1.5; }

/* Form */
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
             gap: .7rem 1rem; margin: .8rem 0 1rem; }
.form-group label { display: block; font-size: .8rem; font-weight: 600;
                    color: #555; margin-bottom: .25rem; }
.form-group input, .form-group select {
    width: 100%; padding: .4rem .6rem; border: 1px solid #ccc; border-radius: 5px;
    font-size: .9rem; background: #fafafa; color: #222; }
.form-group input:focus, .form-group select:focus {
    outline: 2px solid #8ab4f8; border-color: #8ab4f8; background: #fff; }
.form-group.span2 { grid-column: span 2; }
.btn { padding: .5rem 1.1rem; border: none; border-radius: 5px; cursor: pointer;
       font-size: .9rem; font-weight: 600; transition: opacity .15s; }
.btn:hover { opacity: .85; }
.btn-primary { background: #1a73e8; color: #fff; }
.btn-danger  { background: #b3261e; color: #fff; }

/* Result banners */
.banner { padding: .7rem 1rem; border-radius: 6px; font-size: .88rem;
          margin-top: .9rem; }
.banner-ok  { background: #e6f4ea; border: 1px solid #a8d5b5; color: #1e6636; }
.banner-err { background: #fce8e6; border: 1px solid #f5c6c2; color: #8b1f1b; }
.banner pre { margin: .4rem 0 0; background: none; padding: 0;
              font-size: 12px; color: inherit; white-space: pre-wrap; word-break: break-all; }

/* Notice */
.notice { font-size: .85rem; color: #666; background: #f0f4ff;
          border: 1px solid #c8d7f9; border-radius: 6px; padding: .6rem .9rem; }
.notice code { font-size: .82rem; background: #dce7fc; padding: .1em .35em;
               border-radius: 3px; color: #333; }

code { font-size: .88em; background: #eef2ff; padding: .1em .35em;
       border-radius: 3px; color: #333; }
pre.cmds { background: #272822; color: #f8f8f2; padding: .75rem 1rem;
           border-radius: 5px; font-size: 12.5px; overflow-x: auto;
           margin: .5rem 0 0; white-space: pre; }
.muted { color: #888; }
CSS;

// ── Nav HTML ──────────────────────────────────────────────────────────────────

$navHtml = <<<NAV
<nav class="nav">
  <h1 class="nav-title">k6 Load Testing</h1>
  <a href="/">Scripts</a>
  <a href="/k6-reports/">Reports</a>
</nav>
NAV;

// ── Page renderer ─────────────────────────────────────────────────────────────

function page(string $title, string $sidebar, string $main, string $css, string $nav): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>{$title}</title>
      <style>{$css}</style>
    </head>
    <body>
    {$nav}
    <div class="wrap">
      <aside class="sidebar">{$sidebar}</aside>
      <section class="main">{$main}</section>
    </div>
    </body>
    </html>
    HTML;
}

// ── Sidebar (always rendered) ─────────────────────────────────────────────────

$tree    = buildTree($k6Root, '', $skipNames, $viewable);
$sidebar = '<strong style="font-size:.78rem;color:#888;text-transform:uppercase;'
         . 'letter-spacing:.06em">k6 scripts</strong>'
         . renderTree($tree, $h);

// ── Route: file view ──────────────────────────────────────────────────────────

$requestedRel = isset($_GET['file']) ? ltrim($_GET['file'], '/') : '';

if ($requestedRel !== '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $abs = resolveInsideK6($requestedRel, $k6Root);
    if ($abs === null) {
        http_response_code(404);
        page('Not found — k6 Load Testing', $sidebar,
            '<p class="muted">File not found.</p>', $css, $navHtml);
        exit;
    }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, $viewable, true)) {
        http_response_code(403);
        page('Not allowed — k6 Load Testing', $sidebar,
            '<p class="muted">That file type cannot be displayed.</p>', $css, $navHtml);
        exit;
    }

    $content  = file_get_contents($abs);
    $fileSize = filesize($abs);
    $parts    = explode('/', $requestedRel);
    $langHint = match ($ext) {
        'js'           => 'JavaScript',
        'sh'           => 'Shell',
        'php'          => 'PHP',
        'md'           => 'Markdown',
        'json'         => 'JSON',
        'yaml', 'yml'  => 'YAML',
        default        => strtoupper($ext),
    };

    // Breadcrumb
    $bc   = '<a href="/">k6</a>';
    $built = '';
    foreach ($parts as $i => $part) {
        $built = $built ? "$built/$part" : $part;
        $bc .= ' / ' . ($i < count($parts) - 1
            ? '<a href="/?file=' . rawurlencode($built) . '">' . $h($part) . '</a>'
            : $h($part));
    }

    $kb   = $fileSize < 1024 ? "$fileSize B" : round($fileSize / 1024, 1) . ' KB';
    $main = '<div class="breadcrumb">' . $bc . '</div>'
          . '<div class="file-meta">' . $h($langHint) . ' &middot; ' . $h($kb) . '</div>'
          . '<pre class="code">' . $h($content) . '</pre>';

    page($h(basename($abs)) . ' — k6 Load Testing', $sidebar, $main, $css, $navHtml);
    exit;
}

// ── Route: index (GET + POST result) ─────────────────────────────────────────

// --- task result banner ---
$resultHtml = '';
if ($triggerResult !== null) {
    if ($triggerResult['ok']) {
        $profile = $h($triggerResult['profile'] ?? '');
        $vars    = $triggerResult['vars'] ?? [];
        $varList = implode(', ', array_map(
            fn($k, $v) => $h($k) . '=<strong>' . $h($v) . '</strong>',
            array_keys($vars), $vars
        ));
        $cliOut  = $triggerResult['output'] !== '' ? '<pre>' . $h($triggerResult['output']) . '</pre>' : '';
        $resultHtml = <<<HTML
        <div class="banner banner-ok">
          &#x2705; Task <strong>loadtesting-k6</strong> queued (profile: <strong>{$profile}</strong>).
          <br><small>{$varList}</small>
          {$cliOut}
          <small>Follow progress in the <a href="https://console.upsun.com/" target="_blank" rel="noopener">Upsun console</a>
          or with <code>upsun activity:log</code>. Results appear in <a href="/k6-reports/">Reports</a> once done.</small>
        </div>
        HTML;
    } else {
        $output = $h($triggerResult['output'] ?? ($triggerResult['message'] ?? 'Unknown error'));
        $exit   = isset($triggerResult['exitCode']) ? ' (exit ' . (int) $triggerResult['exitCode'] . ')' : '';
        $resultHtml = '<div class="banner banner-err">&#x274C; Failed to start task' . $exit . ':<pre>' . $output . '</pre></div>';
    }
}

// --- trigger form or notice ---
$csrf   = $h($_SESSION['csrf']);
$triggerHtml = '';

if ($canTrigger) {
    $profiles = ['combined', 'graphql', 'storefront', 'admin', 'crawler', 'smoke', 'cleanup'];
    $optHtml  = implode('', array_map(
        fn($p) => '<option value="' . $p . '">' . $p . '</option>',
        $profiles
    ));

    $triggerHtml = <<<HTML
    <div class="card">
      <h2>&#x25B6;&#xFE0F; Run a load test</h2>
      <p>Triggers the <code>loadtesting-k6</code> Upsun task on environment
         <code>{$h($environmentId)}</code>.
         The task runs in its own container — the web UI stays up.</p>

      <form method="post" action="/">
        <input type="hidden" name="action" value="run_task">
        <input type="hidden" name="csrf"   value="{$csrf}">

        <div class="form-grid">
          <div class="form-group">
            <label for="f-profile">Profile</label>
            <select id="f-profile" name="profile" onchange="toggleFields(this.value)">
              {$optHtml}
            </select>
          </div>
          <div class="form-group" id="fg-vus">
            <label for="f-vus">VUs</label>
            <input id="f-vus" name="vus" type="number" min="1" max="500" value="25">
          </div>
          <div class="form-group" id="fg-admin-vus">
            <label for="f-admin-vus">Admin VUs</label>
            <input id="f-admin-vus" name="admin_vus" type="number" min="0" max="50" value="2">
          </div>
          <div class="form-group" id="fg-duration">
            <label for="f-duration">Duration</label>
            <input id="f-duration" name="duration" type="text" value="3m"
                   placeholder="e.g. 3m, 10m, 1h" pattern="\\d+[smh]">
          </div>
          <div class="form-group span2">
            <label for="f-url">URL override <span class="muted">(optional — uses config default if blank)</span></label>
            <input id="f-url" name="url_override" type="url" placeholder="https://staging.yourshop.com">
          </div>
        </div>

        <button type="submit" class="btn btn-primary">Start task</button>
        &nbsp;
        <button type="submit" class="btn btn-danger"
                onclick="document.getElementById('f-profile').value='cleanup';
                         document.getElementById('f-duration').value='';
                         return true;">
          Run cleanup
        </button>
      </form>

      {$resultHtml}
    </div>

    <script>
    function toggleFields(profile) {
      var noLoad   = ['cleanup'];
      var noAdmin  = ['storefront', 'crawler'];
      var noDur    = ['smoke'];
      document.getElementById('fg-vus').style.display       = noLoad.includes(profile)  ? 'none' : '';
      document.getElementById('fg-admin-vus').style.display = (noLoad.includes(profile) || noAdmin.includes(profile)) ? 'none' : '';
      document.getElementById('fg-duration').style.display  = (noLoad.includes(profile) || noDur.includes(profile)) ? 'none' : '';
    }
    toggleFields(document.getElementById('f-profile').value);
    </script>
    HTML;

} else {
    // Explain how to enable the trigger
    $cliMissing = !is_executable($cliBin);
    $tokenMissing = $apiToken === '';
    $why = match(true) {
        $cliMissing && $tokenMissing => 'Upsun CLI not found and <code>UPSUN_CLI_TOKEN</code> is not set',
        $cliMissing                  => 'Upsun CLI not found at <code>' . $h($cliBin) . '</code> — redeploy to install it',
        $tokenMissing                => '<code>UPSUN_CLI_TOKEN</code> is not set',
        default                      => 'Unknown',
    };
    $triggerHtml = <<<HTML
    <div class="card">
      <h2>&#x25B6;&#xFE0F; Run a load test</h2>
      <p class="muted" style="margin:0">Task triggering is disabled — {$why}.</p>
      <br>
      <div class="notice">
        Create an API token in the
        <a href="https://console.upsun.com/" target="_blank" rel="noopener">Upsun console</a>
        (Profile &rarr; API Tokens), then add it as a project variable:<br><br>
        <code>upsun variable:create -l project --prefix env: --name UPSUN_CLI_TOKEN \<br>
        &nbsp;&nbsp;--value "&lt;token&gt;" --sensitive true --visible-build false</code><br><br>
        Then redeploy so the build hook installs the CLI.
      </div>
    </div>
    HTML;
}

// --- CLI commands card ---
$cliCard = <<<'HTML'
<div class="card">
  <h2>&#x1F4BB; CLI commands</h2>
  <pre class="cmds">
# Smoke test
upsun task:run loadtesting-k6 --variable env:PROFILE=smoke --wait

# Combined load (default config: 25 VUs, 3 min)
upsun task:run loadtesting-k6 --wait

# Custom run
upsun task:run loadtesting-k6 \
  --variable env:VUS=30 \
  --variable env:DURATION=15m \
  --variable env:PROFILE=storefront \
  --wait

# Cleanup k6 test data
upsun task:run loadtesting-k6 --variable env:PROFILE=cleanup --wait</pre>
</div>
HTML;

$main = $triggerHtml . $cliCard;

page('k6 Load Testing', $sidebar, $main, $css, $navHtml);
