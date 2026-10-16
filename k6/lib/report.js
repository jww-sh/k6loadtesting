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

function progressBar(pct, cls) {
  const capped = Math.max(0, Math.min(100, pct));
  return `<div class="pbar-wrap"><div class="pbar-fill ${cls}" style="width:${capped}%"></div></div>`;
}

// ── Metric value formatters by type/contains ────────────────────────────────

function formatValue(raw, contains) {
  if (contains === 'time') return fmtMs(raw);
  if (contains === 'data') return fmtBytes(raw);
  return fmtNum(raw, 2);
}

// Inline SVG range-bar: shows distribution (min → avg → p90 → p95 → max)
function rangeSvg(v, contains) {
  const min  = parseFloat(v.min   || 0);
  const avg  = parseFloat(v.avg   || 0);
  const p90  = parseFloat(v['p(90)'] || 0);
  const p95  = parseFloat(v['p(95)'] || 0);
  const max  = parseFloat(v.max   || 0);
  if (!max || max <= 0) return '';
  const W = 200, H = 28, pad = 4;
  const px = val => pad + ((val / max) * (W - pad * 2));
  const avgX = px(avg), p90X = px(p90), p95X = px(p95), maxX = px(max);
  const fmt  = contains === 'time' ? fmtMs : fmtNum;
  return `<svg width="${W}" height="${H}" class="range-svg" aria-hidden="true">
    <rect x="${pad}" y="11" width="${W-pad*2}" height="6" rx="3" fill="var(--border)"/>
    <rect x="${pad}" y="11" width="${p95X-pad}" height="6" rx="3" fill="var(--purple)" opacity=".35"/>
    <rect x="${pad}" y="11" width="${avgX-pad}" height="6" rx="3" fill="var(--purple)" opacity=".75"/>
    <line x1="${p90X}" y1="8" x2="${p90X}" y2="20" stroke="var(--muted)" stroke-width="1.5"/>
    <line x1="${p95X}" y1="6" x2="${p95X}" y2="22" stroke="var(--accent)" stroke-width="1.5"/>
    <title>min:${fmt(min)} avg:${fmt(avg)} p90:${fmt(p90)} p95:${fmt(p95)} max:${fmt(max)}</title>
  </svg>`;
}

// SVG donut gauge: pct 0-100
function gaugeArc(pct, color) {
  const R = 18, cx = 22, cy = 22, sw = 5;
  const circ = 2 * Math.PI * R;
  const dash  = (pct / 100) * circ;
  return `<svg width="44" height="44" viewBox="0 0 44 44" class="gauge-svg">
    <circle cx="${cx}" cy="${cy}" r="${R}" fill="none" stroke="var(--border)" stroke-width="${sw}"/>
    <circle cx="${cx}" cy="${cy}" r="${R}" fill="none" stroke="${color}" stroke-width="${sw}"
      stroke-dasharray="${dash} ${circ}" stroke-linecap="round"
      transform="rotate(-90 ${cx} ${cy})"/>
    <text x="${cx}" y="${cy+4}" text-anchor="middle" font-size="8" font-weight="700"
      fill="currentColor" font-family="var(--mono)">${pct.toFixed(0)}%</text>
  </svg>`;
}

function trendRow(name, metric, trendStats) {
  const v = metric.values || {};
  const cells = trendStats.map(stat => {
    const raw = v[stat];
    return `<td class="num-cell">${formatValue(raw, metric.contains)}</td>`;
  }).join('');
  const threshCells = thresholdBadges(metric.thresholds);
  const chart = rangeSvg(v, metric.contains);
  return `<tr><td class="mname">${esc(name)}</td>${cells}<td class="chart-cell">${chart}</td><td>${threshCells}</td></tr>`;
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

// ── Overview KPI cards ──────────────────────────────────────────────────────

function overviewCards(metrics, state) {
  const reqs     = metrics['http_reqs'];
  const failed   = metrics['http_req_failed'];
  const duration = metrics['http_req_duration'];
  const iters    = metrics['iterations'];

  const reqCount  = reqs     ? fmtNum(reqs.values?.count)          : '—';
  const reqRate   = reqs     ? `${fmtNum(reqs.values?.rate, 1)}/s` : '';
  const errPct    = failed   ? parseFloat(failed.values?.rate || 0) * 100 : null;
  const p95       = duration ? fmtMs(duration.values?.['p(95)'])   : '—';
  const p95raw    = duration ? parseFloat(duration.values?.['p(95)'] || 0) : 0;
  const avgDur    = duration ? fmtMs(duration.values?.avg)         : '';
  const iterCount = iters    ? fmtNum(iters.values?.count)         : '—';
  const iterRate  = iters    ? `${fmtNum(iters.values?.rate, 2)}/s` : '';
  const runTime   = state?.testRunDurationMs ? fmtDuration(state.testRunDurationMs) : '—';

  const errCls   = errPct == null ? '' : errPct < 1 ? 'card-ok' : errPct < 5 ? 'card-warn' : 'card-err';
  const p95Cls   = p95raw < 1000 ? 'card-ok' : p95raw < 3000 ? 'card-warn' : 'card-err';
  const errGaugeColor = errPct == null ? 'var(--dim)' : errPct < 1 ? 'var(--ok-text)' : errPct < 5 ? 'var(--warn-text)' : 'var(--err-text)';
  const errGauge = errPct != null ? gaugeArc(Math.min(100, errPct), errGaugeColor) : '';

  // Duration distribution mini chart from http_req_duration values
  const durVals  = duration ? duration.values || {} : {};
  const durRange = duration ? rangeSvg(durVals, 'time') : '';

  return `
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-icon">⚡</div>
      <div class="kpi-body">
        <div class="kpi-label">HTTP Requests</div>
        <div class="kpi-val">${reqCount}</div>
        <div class="kpi-sub">${reqRate}</div>
      </div>
    </div>
    <div class="kpi-card ${errCls}">
      ${errGauge ? `<div class="kpi-gauge">${errGauge}</div>` : '<div class="kpi-icon">⚠</div>'}
      <div class="kpi-body">
        <div class="kpi-label">Error Rate</div>
        <div class="kpi-val">${errPct != null ? errPct.toFixed(2) + '%' : '—'}</div>
        <div class="kpi-sub">http_req_failed</div>
      </div>
    </div>
    <div class="kpi-card ${p95Cls}">
      <div class="kpi-icon">⏱</div>
      <div class="kpi-body">
        <div class="kpi-label">p(95) Response</div>
        <div class="kpi-val">${p95}</div>
        ${durRange ? `<div class="kpi-range">${durRange}</div>` : `<div class="kpi-sub">avg ${avgDur}</div>`}
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon">↺</div>
      <div class="kpi-body">
        <div class="kpi-label">Iterations</div>
        <div class="kpi-val">${iterCount}</div>
        <div class="kpi-sub">${iterRate}</div>
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-icon">🕒</div>
      <div class="kpi-body">
        <div class="kpi-label">Test Duration</div>
        <div class="kpi-val">${runTime}</div>
        <div class="kpi-sub">&nbsp;</div>
      </div>
    </div>
  </div>`;
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
    html += `<tr class="group-header"><td colspan="5" ${indent}>${esc(group.name)}</td></tr>`;
  }

  for (const check of checks) {
    const total  = (check.passes || 0) + (check.fails || 0);
    const pct    = total > 0 ? (check.passes / total) * 100 : 100;
    const ok     = check.fails === 0;
    const barCls = ok ? 'pbar-ok' : pct > 50 ? 'pbar-warn' : 'pbar-err';
    html += `<tr>
      <td ${indent}>${esc(check.name)}</td>
      <td><span class="status-pill ${ok ? 'pill-ok' : 'pill-err'}">${ok ? '✓ pass' : '✗ fail'}</span></td>
      <td class="num-cell">${fmtNum(check.passes)}</td>
      <td class="num-cell ${check.fails > 0 ? 'err-text' : ''}">${fmtNum(check.fails)}</td>
      <td class="bar-cell">${progressBar(pct, barCls)}<span class="pbar-label">${pct.toFixed(0)}%</span></td>
    </tr>`;
  }

  for (const sub of groups) {
    html += renderGroup(sub, depth + 1);
  }
  return html;
}

// ── Section builders ────────────────────────────────────────────────────────

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
  <div class="table-wrap">
    <table>
      <thead><tr><th>Metric</th>${headers}<th>Distribution</th><th>Thresholds</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
  </div>`;
}

function ratioMetricsTable(metrics) {
  const RATIO_METRICS = ['http_reqs', 'http_req_failed', 'iterations', 'iteration_duration',
    'data_sent', 'data_received', 'checks'];
  const rows = RATIO_METRICS
    .filter(name => metrics[name])
    .map(name => {
      const m = metrics[name];
      const v = m.values || {};
      let primary = '—', secondary = '', bar = '';
      if (m.type === 'counter') {
        primary   = m.contains === 'data' ? fmtBytes(v.count) : fmtNum(v.count);
        secondary = m.contains === 'data' ? fmtBytesRate(v.rate) : `${fmtNum(v.rate, 2)}/s`;
      } else if (m.type === 'rate') {
        const pct = parseFloat(v.rate || 0) * 100;
        primary   = fmtRate(v.rate);
        secondary = `${fmtNum(v.passes)} pass / ${fmtNum(v.fails)} fail`;
        const isFailMetric = name.includes('failed') || name.includes('error');
        const barCls = isFailMetric
          ? (pct < 1 ? 'pbar-ok' : pct < 5 ? 'pbar-warn' : 'pbar-err')
          : (pct > 95 ? 'pbar-ok' : pct > 50 ? 'pbar-warn' : 'pbar-err');
        bar = progressBar(pct, barCls);
      } else if (m.type === 'trend') {
        primary   = fmtMs(v.avg);
        secondary = `p95: ${fmtMs(v['p(95)'])}`;
      } else if (m.type === 'gauge') {
        primary   = fmtNum(v.value);
        secondary = `max: ${fmtNum(v.max)}`;
      }
      const threshCells = thresholdBadges(m.thresholds);
      return `<tr>
        <td class="mname">${esc(name)}</td>
        <td class="num-cell"><strong>${primary}</strong></td>
        <td class="dim">${secondary}</td>
        <td>${bar}</td>
        <td>${threshCells}</td>
      </tr>`;
    }).join('');

  if (!rows) return '';
  return `
  <div class="table-wrap">
    <table>
      <thead><tr><th>Metric</th><th>Value</th><th>Detail</th><th>Rate</th><th>Thresholds</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
  </div>`;
}

function thresholdsSection(metrics) {
  const rows = [];
  for (const [name, metric] of Object.entries(metrics)) {
    if (!metric.thresholds || Object.keys(metric.thresholds).length === 0) continue;
    for (const [expr, res] of Object.entries(metric.thresholds)) {
      const ok  = res && res.ok;
      rows.push(`<tr>
        <td class="mname">${esc(name)}</td>
        <td><code>${esc(expr)}</code></td>
        <td><span class="status-pill ${ok ? 'pill-ok' : 'pill-err'}">${ok ? '✓ passed' : '✗ failed'}</span></td>
      </tr>`);
    }
  }
  if (!rows.length) return '';
  return `
  <div class="table-wrap">
    <table>
      <thead><tr><th>Metric</th><th>Expression</th><th>Result</th></tr></thead>
      <tbody>${rows.join('')}</tbody>
    </table>
  </div>`;
}

function checksSection(rootGroup) {
  const rows = renderGroup(rootGroup);
  if (!rows) return '';
  return `
  <div class="table-wrap">
    <table>
      <thead><tr><th>Check</th><th>Result</th><th>Passes</th><th>Fails</th><th>Pass rate</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
  </div>`;
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
    let primary = '—', secondary = '', bar = '';
    if (metric.type === 'rate') {
      const pct = parseFloat(v.rate || 0) * 100;
      primary   = fmtRate(v.rate);
      secondary = `${fmtNum(v.passes)} pass / ${fmtNum(v.fails)} fail`;
      const isFailMetric = name.includes('error') || name.includes('fail');
      bar = progressBar(pct, isFailMetric
        ? (pct < 5 ? 'pbar-ok' : 'pbar-err')
        : (pct > 95 ? 'pbar-ok' : 'pbar-warn'));
    } else if (metric.type === 'counter') {
      primary   = metric.contains === 'data' ? fmtBytes(v.count) : fmtNum(v.count);
      secondary = metric.contains === 'data' ? fmtBytesRate(v.rate) : `${fmtNum(v.rate, 2)}/s`;
    } else if (metric.type === 'gauge') {
      primary   = fmtNum(v.value);
      secondary = `min: ${fmtNum(v.min)} / max: ${fmtNum(v.max)}`;
    }
    const threshCells = thresholdBadges(metric.thresholds);
    return `<tr>
      <td class="mname">${esc(name)}</td>
      <td class="num-cell"><strong>${primary}</strong></td>
      <td class="dim">${secondary}</td>
      <td>${bar}</td>
      <td>${threshCells}</td>
    </tr>`;
  }).join('');

  const hasTrend = custom.some(([, m]) => m.type === 'trend');
  const headers  = hasTrend
    ? `<th>Metric</th>${trendStats.map(s => `<th>${esc(s)}</th>`).join('')}<th>Distribution</th><th>Thresholds</th>`
    : `<th>Metric</th><th>Value</th><th>Detail</th><th>Rate</th><th>Thresholds</th>`;

  return `
  <div class="table-wrap">
    <table>
      <thead><tr>${headers}</tr></thead>
      <tbody>${rows}</tbody>
    </table>
  </div>`;
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
  --r:8px;--shadow:0 2px 8px rgba(0,0,0,.5)
}
[data-theme="light"]{
  --bg:#F4F6F7;--surface:#FFFFFF;--surface2:#EEF1F3;
  --border:#D2D8DB;--border-hi:#B4BABD;
  --text:#191C1E;--muted:#3D474C;--dim:#646F75;
  --purple-light:#6046FF;
  --ok-bg:#f0fdf4;--ok-border:#86efac;--ok-text:#166534;
  --err-bg:#fef2f2;--err-border:#fca5a5;--err-text:#991b1b;
  --warn-bg:#fffbeb;--warn-border:#fde68a;--warn-text:#92400e;
  --shadow:0 2px 8px rgba(0,0,0,.1)
}
@media(prefers-color-scheme:light){
  :root:not([data-theme="dark"]){
    --bg:#F4F6F7;--surface:#FFFFFF;--surface2:#EEF1F3;
    --border:#D2D8DB;--border-hi:#B4BABD;
    --text:#191C1E;--muted:#3D474C;--dim:#646F75;
    --purple-light:#6046FF;
    --ok-bg:#f0fdf4;--ok-border:#86efac;--ok-text:#166534;
    --err-bg:#fef2f2;--err-border:#fca5a5;--err-text:#991b1b;
    --warn-bg:#fffbeb;--warn-border:#fde68a;--warn-text:#92400e;
    --shadow:0 2px 8px rgba(0,0,0,.1)
  }
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:15px;scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--font);line-height:1.6;
  min-height:100vh;padding-bottom:80px}
a{color:var(--purple-light);text-decoration:none}
a:hover{text-decoration:underline}
code{font-family:var(--mono);font-size:.84em;background:var(--border);
  color:var(--text);padding:.1em .35em;border-radius:3px}
h1{font-size:26px;font-weight:900;letter-spacing:-.02em;color:var(--text)}

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
.theme-btn{background:none;border:1px solid var(--border);border-radius:6px;
  color:var(--muted);cursor:pointer;font-size:15px;padding:5px 9px;
  transition:all .15s;line-height:1;font-family:inherit}
.theme-btn:hover{background:var(--border);border-color:var(--border-hi);color:var(--text)}

/* ── Layout ── */
.page{max-width:1200px;margin:0 auto;padding:32px 40px 0}
.page-head{margin-bottom:24px}
.run-sub{font-size:13px;color:var(--muted);margin-top:4px;font-family:var(--mono)}

/* ── KPI cards ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));
  gap:14px;margin-bottom:32px}
.kpi-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);
  padding:20px;box-shadow:var(--shadow);display:flex;gap:16px;align-items:flex-start;
  transition:transform .15s}
.kpi-card:hover{transform:translateY(-1px)}
.kpi-card.card-ok  {border-color:var(--ok-border);background:var(--ok-bg)}
.kpi-card.card-err {border-color:var(--err-border);background:var(--err-bg)}
.kpi-card.card-warn{border-color:var(--warn-border);background:var(--warn-bg)}
.kpi-icon{font-size:24px;line-height:1;margin-top:2px;flex-shrink:0}
.kpi-body{flex:1;min-width:0}
.kpi-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--dim);margin-bottom:6px}
.kpi-val{font-size:26px;font-weight:900;font-family:var(--mono);line-height:1.1;
  color:var(--text);word-break:break-all}
.card-ok  .kpi-val{color:var(--ok-text)}
.card-err .kpi-val{color:var(--err-text)}
.card-warn .kpi-val{color:var(--warn-text)}
.kpi-sub{font-size:11px;color:var(--dim);margin-top:4px;font-family:var(--mono)}

/* ── Progress bars ── */
.pbar-wrap{height:6px;background:var(--border);border-radius:3px;overflow:hidden;
  margin:8px 0 2px;min-width:60px}
.pbar-fill{height:100%;border-radius:3px;transition:width .3s}
.pbar-ok  {background:var(--ok-text)}
.pbar-warn{background:var(--warn-text)}
.pbar-err {background:var(--err-text)}
.pbar-label{font-size:11px;color:var(--dim);font-family:var(--mono)}
.bar-cell{min-width:120px}

/* ── Tabs ── */
.tabs{margin-bottom:0}
.tab-nav{display:flex;gap:2px;border-bottom:2px solid var(--border);margin-bottom:0;
  flex-wrap:wrap}
.tab-btn{background:none;border:none;border-bottom:2px solid transparent;
  margin-bottom:-2px;padding:10px 18px;font-size:13px;font-weight:600;
  color:var(--dim);cursor:pointer;font-family:var(--font);transition:all .15s;
  white-space:nowrap}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{color:var(--purple-light);border-bottom-color:var(--purple-light)}
.tab-panel{padding:20px 0;display:none}
.tab-panel.active{display:block}
.tab-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--dim);margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.empty-tab{color:var(--dim);font-size:13px;padding:24px 0;text-align:center}

/* ── Status pills ── */
.status-pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;
  font-weight:700;font-family:var(--mono)}
.pill-ok {background:var(--ok-bg);color:var(--ok-text);border:1px solid var(--ok-border)}
.pill-err{background:var(--err-bg);color:var(--err-text);border:1px solid var(--err-border)}
.pill-warn{background:var(--warn-bg);color:var(--warn-text);border:1px solid var(--warn-border)}

/* ── Tables ── */
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--r);
  margin-bottom:20px}
table{width:100%;border-collapse:collapse;font-size:13px}
thead{background:var(--surface2)}
th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;
  letter-spacing:.07em;color:var(--dim);white-space:nowrap;
  border-bottom:2px solid var(--border)}
td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(96,70,255,.05)}
.mname{font-family:var(--mono);font-size:12px;color:var(--muted);white-space:nowrap}
.num-cell{font-family:var(--mono);text-align:right;white-space:nowrap}
.dim{color:var(--dim);font-size:12px}
.ok-text{color:var(--ok-text);font-weight:600}
.err-text{color:var(--err-text);font-weight:600}
.group-header td{background:var(--surface2)!important;color:var(--muted);
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
  padding:7px 14px}

/* ── Badges ── */
.badge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:11px;
  font-weight:700;font-family:var(--mono);margin:1px 2px}
.badge-ok {background:var(--ok-bg);color:var(--ok-text);border:1px solid var(--ok-border)}
.badge-err{background:var(--err-bg);color:var(--err-text);border:1px solid var(--err-border)}

/* ── SVG charts ── */
.range-svg{display:block;overflow:visible}
.chart-cell{min-width:210px;padding-right:8px}
.gauge-svg{display:block;color:var(--text)}
.kpi-gauge{flex-shrink:0;margin-top:2px}
.kpi-range{margin-top:6px}

/* ── Summary banner ── */
.summary-banner{display:flex;align-items:center;gap:12px;padding:14px 18px;
  border-radius:var(--r);margin-bottom:24px;border:1px solid;font-weight:600}
.summary-banner.banner-ok  {background:var(--ok-bg);border-color:var(--ok-border);color:var(--ok-text)}
.summary-banner.banner-err {background:var(--err-bg);border-color:var(--err-border);color:var(--err-text)}
.summary-banner.banner-warn{background:var(--warn-bg);border-color:var(--warn-border);color:var(--warn-text)}
.banner-icon{font-size:20px}

/* ── Footer ── */
.site-footer{text-align:center;padding:32px 40px;color:var(--dim);font-size:13px;
  border-top:1px solid var(--border);margin-top:40px}
.site-footer .heart{color:var(--accent)}
.site-footer a{color:var(--dim)}

@media(max-width:720px){
  .site-header{padding:0 16px}
  .page{padding:20px 16px 0}
  .kpi-grid{grid-template-columns:1fr 1fr}
  h1{font-size:20px}
  .kpi-val{font-size:20px}
}
`;

// ── JS for tabs + theme toggle ───────────────────────────────────────────────

const JS = `
(function(){
  // Theme
  var btn=document.getElementById('theme-toggle');
  function getTheme(){return document.documentElement.getAttribute('data-theme')||'dark';}
  function applyTheme(t){
    document.documentElement.setAttribute('data-theme',t);
    if(btn){btn.textContent=t==='dark'?'☀':'☾';btn.title=t==='dark'?'Switch to light mode':'Switch to dark mode';}
    try{localStorage.setItem('theme',t);}catch(e){}
  }
  applyTheme(getTheme());
  if(btn) btn.addEventListener('click',function(){applyTheme(getTheme()==='dark'?'light':'dark');});

  // Tabs
  document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      var group=btn.closest('.tabs');
      group.querySelectorAll('.tab-btn').forEach(function(b){b.classList.remove('active');});
      group.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active');});
      btn.classList.add('active');
      var panel=group.querySelector('#'+btn.dataset.tab);
      if(panel) panel.classList.add('active');
    });
  });
})();
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

  const runSub = state.testRunDurationMs ? `Run duration: ${fmtDuration(state.testRunDurationMs)}` : '';

  // Back-navigation links
  const runHref   = runName ? `/k6-reports/${encodeURIComponent(runName)}` : null;
  const backLinks = runHref
    ? `<a href="/" class="header-link">&#x2190; All runs</a>
       <a href="${runHref}" class="header-link">&#x2190; Run detail</a>`
    : '';

  // Overall pass/fail banner
  const allThresholds = Object.values(metrics).flatMap(m =>
    m.thresholds ? Object.values(m.thresholds) : []
  );
  const anyFailed  = allThresholds.some(t => t && !t.ok);
  const hasThresh  = allThresholds.length > 0;
  const bannerCls  = !hasThresh ? 'banner-warn' : anyFailed ? 'banner-err' : 'banner-ok';
  const bannerIcon = !hasThresh ? '⚠' : anyFailed ? '✗' : '✓';
  const bannerText = !hasThresh ? 'No thresholds defined'
    : anyFailed ? 'Some thresholds failed'
    : 'All thresholds passed';
  const banner = `<div class="summary-banner ${bannerCls}">
    <span class="banner-icon">${bannerIcon}</span>
    <span>${bannerText}</span>
  </div>`;

  // Build tab sections
  const threshHtml  = thresholdsSection(metrics);
  const checksHtml  = checksSection(rootGroup);
  const httpHtml    = httpMetricsTable(metrics, trendStats);
  const otherHtml   = ratioMetricsTable(metrics);
  const customHtml  = customMetricsSection(metrics, trendStats);

  const tabs = [
    { id: 'tab-thresh',  label: 'Thresholds',    html: threshHtml  || '<p class="empty-tab">No thresholds defined.</p>' },
    { id: 'tab-checks',  label: 'Checks',         html: checksHtml  || '<p class="empty-tab">No checks recorded.</p>' },
    { id: 'tab-http',    label: 'HTTP Timing',    html: httpHtml    || '<p class="empty-tab">No HTTP metrics.</p>' },
    { id: 'tab-other',   label: 'Other Metrics',  html: otherHtml   || '<p class="empty-tab">No metrics.</p>' },
    { id: 'tab-custom',  label: 'Custom Metrics', html: customHtml  || '<p class="empty-tab">No custom metrics.</p>' },
  ];

  const navButtons = tabs.map((t, i) =>
    `<button class="tab-btn${i === 0 ? ' active' : ''}" data-tab="${t.id}">${t.label}</button>`
  ).join('');

  const panels = tabs.map((t, i) =>
    `<div class="tab-panel${i === 0 ? ' active' : ''}" id="${t.id}">${t.html}</div>`
  ).join('');

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
  <script>
    (function(){
      var saved=localStorage.getItem('theme');
      var prefersDark=window.matchMedia('(prefers-color-scheme:dark)').matches;
      var theme=saved||(prefersDark?'dark':'light');
      document.documentElement.setAttribute('data-theme',theme);
    })();
  </script>
</head>
<body>
<header class="site-header">
  <span class="wordmark">upsun</span>
  <span class="header-sep">/</span>
  <span class="header-product">k6 Load Testing</span>
  <div class="header-spacer"></div>
  ${runSub ? `<span class="header-meta">${esc(runSub)}</span>` : ''}
  ${backLinks}
  <button class="theme-btn" id="theme-toggle" title="Toggle theme" aria-label="Toggle theme">☀</button>
</header>
<div class="page">
  <div class="page-head">
    <h1>${esc(title)}</h1>
    ${runSub ? `<div class="run-sub">${esc(runSub)}</div>` : ''}
  </div>
  ${banner}
  ${overviewCards(metrics, state)}
  <div class="tabs">
    <div class="tab-nav">${navButtons}</div>
    ${panels}
  </div>
</div>
<footer class="site-footer">
  made with <span class="heart">♥</span> by
  <a href="https://upsun.com" target="_blank" rel="noopener">Upsun</a>
</footer>
<script>${JS}</script>
</body>
</html>`;
}
