// Upsun-themed k6 HTML report generator.
// Drop-in replacement for benc-uk/k6-reporter — runs inside k6's handleSummary.
// No external dependencies; the CSS and HTML are fully local.

// ── Formatting helpers ──────────────────────────────────────────────────────

function esc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function fmtMs(v) {
  if (v == null || isNaN(v)) return '—';
  const ms = parseFloat(v);
  return ms < 1000 ? `${ms.toFixed(0)} ms` : `${(ms / 1000).toFixed(2)} s`;
}

function fmtRate(v) {
  if (v == null || isNaN(v)) return '—';
  return `${(parseFloat(v) * 100).toFixed(2)}%`;
}

function fmtBytes(v) {
  if (v == null || isNaN(v)) return '—';
  const b = parseFloat(v);
  if (b < 1024) return `${b.toFixed(0)} B`;
  if (b < 1048576) return `${(b / 1024).toFixed(1)} KB`;
  return `${(b / 1048576).toFixed(2)} MB`;
}

function fmtBytesRate(v) {
  if (v == null || isNaN(v)) return '—';
  return `${fmtBytes(v)}/s`;
}

function fmtNum(v, decimals = 0) {
  if (v == null || isNaN(v)) return '—';
  const n = parseFloat(v);
  const fixed = n.toFixed(decimals);
  const parts = fixed.split('.');
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return decimals > 0 ? parts.join('.') : parts[0];
}

function fmtDuration(ms) {
  if (!ms) return '—';
  const s = Math.floor(ms / 1000);
  const m = Math.floor(s / 60);
  const h = Math.floor(m / 60);
  if (h > 0) return `${h}h ${m % 60}m ${s % 60}s`;
  if (m > 0) return `${m}m ${s % 60}s`;
  return `${s}s`;
}

// ── Metric value formatters by type/contains ────────────────────────────────

function formatValue(raw, contains, unit) {
  if (contains === 'time') return fmtMs(raw);
  if (contains === 'data') return fmtBytes(raw);
  if (unit === 'rate') return fmtRate(raw);
  if (unit === 'ratePerSec') return `${fmtNum(raw, 2)}/s`;
  return fmtNum(raw, 2);
}

function trendRow(name, metric, trendStats) {
  const v = metric.values || {};
  const cells = trendStats.map(stat => {
    const raw = v[stat];
    return `<td>${formatValue(raw, metric.contains, '')}</td>`;
  }).join('');
  const threshCells = thresholdBadges(metric.thresholds);
  return `<tr><td class="mname">${esc(name)}</td>${cells}<td>${threshCells}</td></tr>`;
}

function thresholdBadges(thresholds) {
  if (!thresholds || Object.keys(thresholds).length === 0) return '<span class="dim">—</span>';
  return Object.entries(thresholds).map(([expr, res]) => {
    const ok = res && res.ok;
    const cls = ok ? 'badge-ok' : 'badge-err';
    const icon = ok ? '✓' : '✗';
    return `<span class="badge ${cls}" title="${esc(expr)}">${icon} ${esc(expr)}</span>`;
  }).join(' ');
}

// ── Check tree ──────────────────────────────────────────────────────────────

function renderGroup(group, depth = 0) {
  if (!group) return '';
  const checks = group.checks || [];
  const groups = group.groups || [];
  if (checks.length === 0 && groups.length === 0) return '';

  const indent = depth > 0 ? `style="padding-left:${depth * 16}px"` : '';
  let html = '';

  if (depth > 0 && group.name) {
    html += `<tr class="group-header"><td colspan="4" ${indent}>${esc(group.name)}</td></tr>`;
  }

  for (const check of checks) {
    const total = (check.passes || 0) + (check.fails || 0);
    const rate  = total > 0 ? (check.passes / total) * 100 : 100;
    const ok    = check.fails === 0;
    html += `<tr>
      <td ${indent}>${esc(check.name)}</td>
      <td class="${ok ? 'ok-text' : 'err-text'}">${ok ? '✓' : '✗'}</td>
      <td>${fmtNum(check.passes)}</td>
      <td class="${check.fails > 0 ? 'err-text' : ''}">${fmtNum(check.fails)}</td>
    </tr>`;
  }

  for (const sub of groups) {
    html += renderGroup(sub, depth + 1);
  }
  return html;
}

// ── Section builders ────────────────────────────────────────────────────────

function overviewCards(metrics) {
  const reqs     = metrics['http_reqs'];
  const failed   = metrics['http_req_failed'];
  const duration = metrics['http_req_duration'];
  const iters    = metrics['iterations'];

  const reqCount  = reqs      ? fmtNum(reqs.values?.count)                : '—';
  const reqRate   = reqs      ? `${fmtNum(reqs.values?.rate, 1)}/s`       : '';
  const errRate   = failed    ? parseFloat(failed.values?.rate || 0) * 100 : null;
  const p95       = duration  ? fmtMs(duration.values?.['p(95)'])         : '—';
  const iterCount = iters     ? fmtNum(iters.values?.count)               : '—';

  const errCls  = errRate == null ? '' : errRate < 1 ? 'card-ok' : errRate < 5 ? 'card-warn' : 'card-err';
  const p95Cls  = duration?.values?.['p(95)'] < 1000 ? 'card-ok' : duration?.values?.['p(95)'] < 3000 ? 'card-warn' : 'card-err';

  return `
  <div class="overview-grid">
    <div class="stat-card">
      <div class="stat-label">HTTP Requests</div>
      <div class="stat-val">${reqCount}</div>
      <div class="stat-sub">${reqRate}</div>
    </div>
    <div class="stat-card ${errCls}">
      <div class="stat-label">Error Rate</div>
      <div class="stat-val">${errRate != null ? errRate.toFixed(2) + '%' : '—'}</div>
      <div class="stat-sub">http_req_failed</div>
    </div>
    <div class="stat-card ${p95Cls}">
      <div class="stat-label">p(95) Duration</div>
      <div class="stat-val">${p95}</div>
      <div class="stat-sub">http_req_duration</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Iterations</div>
      <div class="stat-val">${iterCount}</div>
      <div class="stat-sub">${iters ? `${fmtNum(iters.values?.rate, 2)}/s` : ''}</div>
    </div>
  </div>`;
}

function httpMetricsTable(metrics, trendStats) {
  const HTTP_METRICS = [
    'http_req_duration', 'http_req_waiting', 'http_req_sending', 'http_req_receiving',
    'http_req_connecting', 'http_req_tls_handshaking', 'http_req_blocked',
  ];
  const headers = trendStats.map(s => `<th>${esc(s)}</th>`).join('');
  const rows = HTTP_METRICS
    .filter(name => metrics[name])
    .map(name => trendRow(name, metrics[name], trendStats))
    .join('');
  if (!rows) return '';

  return `
  <section class="section">
    <h2>HTTP Timing</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Metric</th>${headers}<th>Thresholds</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  </section>`;
}

function ratioMetricsTable(metrics) {
  const RATIO_METRICS = ['http_reqs', 'http_req_failed', 'iterations', 'iteration_duration',
    'data_sent', 'data_received', 'checks'];
  const rows = RATIO_METRICS
    .filter(name => metrics[name])
    .map(name => {
      const m = metrics[name];
      const v = m.values || {};
      let primary = '—', secondary = '';
      if (m.type === 'counter') {
        primary   = m.contains === 'data' ? fmtBytes(v.count) : fmtNum(v.count);
        secondary = m.contains === 'data' ? fmtBytesRate(v.rate) : `${fmtNum(v.rate, 2)}/s`;
      } else if (m.type === 'rate') {
        primary   = fmtRate(v.rate);
        secondary = `${fmtNum(v.passes)} pass / ${fmtNum(v.fails)} fail`;
      } else if (m.type === 'trend') {
        primary   = fmtMs(v.avg);
        secondary = `p95: ${fmtMs(v['p(95)'])}`;
      } else if (m.type === 'gauge') {
        primary   = fmtNum(v.value);
        secondary = `max: ${fmtNum(v.max)}`;
      }
      const threshCells = thresholdBadges(m.thresholds);
      return `<tr><td class="mname">${esc(name)}</td><td>${primary}</td><td class="dim">${secondary}</td><td>${threshCells}</td></tr>`;
    }).join('');

  if (!rows) return '';
  return `
  <section class="section">
    <h2>Other Metrics</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Metric</th><th>Value</th><th>Detail</th><th>Thresholds</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  </section>`;
}

function thresholdsSection(metrics) {
  const rows = [];
  for (const [name, metric] of Object.entries(metrics)) {
    if (!metric.thresholds || Object.keys(metric.thresholds).length === 0) continue;
    for (const [expr, res] of Object.entries(metric.thresholds)) {
      const ok  = res && res.ok;
      const cls = ok ? 'ok-text' : 'err-text';
      rows.push(`<tr>
        <td class="mname">${esc(name)}</td>
        <td><code>${esc(expr)}</code></td>
        <td class="${cls}">${ok ? '✓ passed' : '✗ failed'}</td>
      </tr>`);
    }
  }
  if (!rows.length) return '';
  return `
  <section class="section">
    <h2>Thresholds</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Metric</th><th>Expression</th><th>Result</th></tr></thead>
        <tbody>${rows.join('')}</tbody>
      </table>
    </div>
  </section>`;
}

function checksSection(rootGroup) {
  const rows = renderGroup(rootGroup);
  if (!rows) return '';
  return `
  <section class="section">
    <h2>Checks</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Check</th><th>Result</th><th>Passes</th><th>Fails</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  </section>`;
}

function customMetricsSection(metrics, trendStats) {
  const STANDARD = new Set([
    'http_req_duration','http_req_waiting','http_req_sending','http_req_receiving',
    'http_req_connecting','http_req_tls_handshaking','http_req_blocked',
    'http_reqs','http_req_failed','iterations','iteration_duration',
    'data_sent','data_received','checks','vus','vus_max',
  ]);
  const custom = Object.entries(metrics).filter(([name]) => !STANDARD.has(name));
  if (!custom.length) return '';

  const rows = custom.map(([name, metric]) => {
    const v = metric.values || {};
    if (metric.type === 'trend') return trendRow(name, metric, trendStats);
    let primary = '—', secondary = '';
    if (metric.type === 'rate') {
      primary   = fmtRate(v.rate);
      secondary = `${fmtNum(v.passes)} pass / ${fmtNum(v.fails)} fail`;
    } else if (metric.type === 'counter') {
      primary   = metric.contains === 'data' ? fmtBytes(v.count) : fmtNum(v.count);
      secondary = `${metric.contains === 'data' ? fmtBytesRate(v.rate) : fmtNum(v.rate, 2) + '/s'}`;
    } else if (metric.type === 'gauge') {
      primary = fmtNum(v.value);
      secondary = `min: ${fmtNum(v.min)} max: ${fmtNum(v.max)}`;
    }
    const threshCells = thresholdBadges(metric.thresholds);
    return `<tr><td class="mname">${esc(name)}</td><td>${primary}</td><td class="dim">${secondary}</td><td>${threshCells}</td></tr>`;
  }).join('');

  const hasTrend = custom.some(([, m]) => m.type === 'trend');
  const headers  = hasTrend
    ? `<th>Metric</th>${trendStats.map(s => `<th>${esc(s)}</th>`).join('')}<th>Thresholds</th>`
    : `<th>Metric</th><th>Value</th><th>Detail</th><th>Thresholds</th>`;

  return `
  <section class="section">
    <h2>Custom Metrics</h2>
    <div class="table-wrap">
      <table>
        <thead><tr>${headers}</tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  </section>`;
}

// ── CSS ─────────────────────────────────────────────────────────────────────

const CSS = `
:root{
  --bg:#0E1113;--surface:#191C1E;--surface2:#1e2226;
  --border:#2B3134;--border-hi:#41484D;
  --text:#E1E3E4;--muted:#B4BABD;--dim:#646F75;
  --purple:#6046FF;--purple-hover:#4D38CC;--purple-btn:#EFEDF5;--purple-light:#806BFF;
  --accent:#D5F800;
  --ok-bg:#1a2e1a;--ok-border:#2d5a2d;--ok-text:#86efac;
  --err-bg:#2e1a1a;--err-border:#5a2d2d;--err-text:#fca5a5;
  --warn-bg:#2a2510;--warn-border:#5a4f20;--warn-text:#fde68a;
  --font:'Space Grotesk',system-ui,sans-serif;
  --mono:'Space Mono',ui-monospace,monospace;
  --r:8px;--shadow:0 1px 3px rgba(0,0,0,.5)
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:15px;scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--font);line-height:1.6;
  min-height:100vh;padding-bottom:60px}
a{color:var(--purple-light);text-decoration:none}
a:hover{text-decoration:underline}
code{font-family:var(--mono);font-size:.84em;background:var(--border);
  color:var(--text);padding:.1em .35em;border-radius:3px}
h1{font-size:28px;font-weight:900;letter-spacing:-.02em;color:var(--text)}
h2{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--dim);margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}

/* ── Header ── */
.site-header{background:var(--surface);border-bottom:1px solid var(--border);
  padding:0 40px;height:56px;display:flex;align-items:center;gap:12px;
  box-shadow:var(--shadow);position:sticky;top:0;z-index:100}
.wordmark{font-size:17px;font-weight:900;color:var(--text);letter-spacing:-.02em}
.header-sep{color:var(--border-hi);font-size:18px}
.header-product{font-size:13px;font-weight:500;color:var(--muted)}
.header-spacer{flex:1}
.header-meta{font-size:12px;color:var(--dim);font-family:var(--mono)}
.header-link{color:var(--muted);font-size:13px;padding:6px 12px;border-radius:6px;
  border:1px solid transparent;transition:all .15s;text-decoration:none!important;white-space:nowrap}
.header-link:hover{background:var(--border);border-color:var(--border-hi);color:var(--text)}

/* ── Layout ── */
.page{max-width:1100px;margin:0 auto;padding:40px 40px 0}
.page-head{margin-bottom:32px}
.run-sub{font-size:13px;color:var(--muted);margin-top:4px}
.section{margin-bottom:28px}

/* ── Overview stat cards ── */
.overview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
  gap:12px;margin-bottom:28px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);
  padding:18px 20px;box-shadow:var(--shadow)}
.stat-card.card-ok  {border-color:var(--ok-border);background:var(--ok-bg)}
.stat-card.card-err {border-color:var(--err-border);background:var(--err-bg)}
.stat-card.card-warn{border-color:var(--warn-border);background:var(--warn-bg)}
.stat-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--dim);margin-bottom:8px}
.stat-val{font-size:24px;font-weight:700;font-family:var(--mono);line-height:1.1;
  color:var(--text)}
.card-ok  .stat-val{color:var(--ok-text)}
.card-err .stat-val{color:var(--err-text)}
.card-warn .stat-val{color:var(--warn-text)}
.stat-sub{font-size:11px;color:var(--dim);margin-top:4px;font-family:var(--mono)}

/* ── Tables ── */
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--r)}
table{width:100%;border-collapse:collapse;font-size:13px}
thead{background:var(--surface2)}
th{padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;
  letter-spacing:.07em;color:var(--dim);white-space:nowrap;
  border-bottom:2px solid var(--border)}
td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:top}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(96,70,255,.05)}
.mname{font-family:var(--mono);font-size:12px;color:var(--muted);white-space:nowrap}
.dim{color:var(--dim)}
.ok-text{color:var(--ok-text);font-weight:600}
.err-text{color:var(--err-text);font-weight:600}
.group-header td{background:var(--surface2)!important;color:var(--muted);
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
  padding:7px 12px}

/* ── Badges ── */
.badge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:11px;
  font-weight:700;font-family:var(--mono);margin:1px 2px}
.badge-ok {background:var(--ok-bg);color:var(--ok-text);border:1px solid var(--ok-border)}
.badge-err{background:var(--err-bg);color:var(--err-text);border:1px solid var(--err-border)}

/* ── Footer ── */
.site-footer{text-align:center;padding:32px 40px;color:var(--dim);font-size:13px;
  border-top:1px solid var(--border);margin-top:40px}
.site-footer .heart{color:var(--accent)}
.site-footer a{color:var(--dim)}

@media(max-width:680px){
  .site-header{padding:0 16px}
  .page{padding:24px 16px 0}
  .overview-grid{grid-template-columns:1fr 1fr}
  h1{font-size:22px}
}
`;

// ── Main export ─────────────────────────────────────────────────────────────

export function generateReport(data, opts = {}) {
  const metrics    = data.metrics    || {};
  const rootGroup  = data.root_group || {};
  const state      = data.state      || {};
  const title      = opts.title      || 'k6 Load Test Report';
  const runName    = opts.runName    || '';
  const trendStats = (data.options && data.options.summaryTrendStats) ||
                     ['avg', 'p(90)', 'p(95)', 'max'];

  const durMs  = state.testRunDurationMs;
  const runSub = durMs ? `Run duration: ${fmtDuration(durMs)}` : '';

  // Back-navigation links (only meaningful when served via the PHP app)
  const runHref    = runName ? `/k6-reports/${encodeURIComponent(runName)}` : null;
  const backLinks  = runHref
    ? `<a href="/" class="header-link">&#x2190; All runs</a>
       <a href="${runHref}" class="header-link">&#x2190; Run detail</a>`
    : '';

  const body = [
    overviewCards(metrics),
    thresholdsSection(metrics),
    checksSection(rootGroup),
    httpMetricsTable(metrics, trendStats),
    ratioMetricsTable(metrics),
    customMetricsSection(metrics, trendStats),
  ].join('\n');

  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>${esc(title)}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700;900&family=Space+Mono&display=swap" rel="stylesheet">
  <style>${CSS}</style>
</head>
<body>
<header class="site-header">
  <span class="wordmark">upsun</span>
  <span class="header-sep">/</span>
  <span class="header-product">k6 Load Testing</span>
  <div class="header-spacer"></div>
  ${runSub ? `<span class="header-meta">${esc(runSub)}</span>` : ''}
  ${backLinks}
</header>
<div class="page">
  <div class="page-head">
    <h1>${esc(title)}</h1>
  </div>
  ${body}
</div>
<footer class="site-footer">
  made with <span class="heart">♥</span> by
  <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
</footer>
</body>
</html>`;
}
