/* ============================================================================
   CGA MOCKUPS — coverage.js
   Populates shared/coverage.html at load: axes come from fixtures.registry
   (30 roles · 80 workflows · 103 forms), fill comes from window.CGA_MANIFEST
   (the manifest.js mirror — no fetch on the critical path, so the matrix
   renders identically over http(s) and file://).
   Over http(s) it ALSO fetches manifest.json and deep-compares it with the
   mirror (drift banner), and enables the dead-link scan (QA §15 hook).
   ============================================================================ */
(function () {
  'use strict';
  /* The shell builds its chrome on DOMContentLoaded — run after it. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', main);
  } else {
    main();
  }

  function main() {
  var CGA = window.CGA;
  if (!CGA || !CGA.fixtures || !CGA.shell) return;

  var R = CGA.fixtures.registry;
  var M = window.CGA_MANIFEST || [];
  var esc = CGA.shell.esc, icon = CGA.shell.icon;

  var root = document.getElementById('coverage-root');
  if (!root) return;

  /* ----------------------------------------------------------- indexing */
  var byRole = {}, byWf = {}, byForm = {}, files = [];
  M.forEach(function (rec) {
    files.push(rec.file);
    (rec.roles || []).forEach(function (r) { (byRole[r] = byRole[r] || []).push(rec.file); });
    (rec.workflows || []).forEach(function (w) { (byWf[w] = byWf[w] || []).push(rec.file); });
    (rec.forms || []).forEach(function (f) { (byForm[f] = byForm[f] || []).push(rec.file); });
  });
  var flowBuilt = {};
  M.forEach(function (rec) { if (rec.file.indexOf('flows/') === 0) flowBuilt[rec.file.replace('flows/', '').replace('.html', '')] = true; });

  function cell(kind, label) {
    var map = { covered: ['covered', 'check'], missing: ['missing', 'minus'], stub: ['stub', 'clock'] };
    var m = map[kind];
    return '<span class="coverage-cell coverage-cell--' + m[0] + '">' + icon(m[1], { size: 'sm' }) + esc(label || kind) + '</span>';
  }

  function fileLinks(list) {
    return (list || []).map(function (f) {
      return '<a href="' + CGA.shell.href(f) + '">' + esc(f) + '</a>';
    }).join(' · ');
  }

  /* ------------------------------------------------------------ summary */
  var rolesCovered = R.roles.filter(function (r) { return (byRole[r.id] || []).length > 0; }).length;
  var wfsCovered = R.workflows.filter(function (w) { return flowBuilt[w.id]; }).length;
  var formsCovered = R.forms.filter(function (f) { return (byForm[f.id] || []).length > 0; }).length;

  var html =
    '<div class="grid-2" style="grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))">' +
    '<div class="card"><div class="stat"><span class="stat-number">' + rolesCovered + ' / ' + R.roles.length + '</span><span class="stat-label">roles with an entry-point screen</span></div></div>' +
    '<div class="card"><div class="stat"><span class="stat-number">' + wfsCovered + ' / ' + R.workflows.length + '</span><span class="stat-label">workflows with a flow page</span></div></div>' +
    '<div class="card"><div class="stat"><span class="stat-number">' + formsCovered + ' / ' + R.forms.length + '</span><span class="stat-label">forms rendered on a screen</span></div></div>' +
    '<div class="card"><div class="stat"><span class="stat-number">' + M.length + '</span><span class="stat-label">pages in the manifest</span></div></div>' +
    '</div>' +

    '<div class="card" id="drift-card"><span class="eyebrow">Manifest integrity</span>' +
    '<p id="drift-status" class="cc-small" style="margin-block-start:var(--space-2)">Checking manifest.json against the manifest.js mirror…</p>' +
    '<p><button type="button" class="btn btn--secondary btn--sm" id="link-check-btn">Scan manifest files for dead links</button> ' +
    '<span class="gloss" id="link-check-note"></span></p><div id="link-check-out" class="cc-small"></div></div>' +

    '<div class="card"><span class="eyebrow">Stage scope legend</span>' +
    '<p class="cc-small" style="margin-block-start:var(--space-2)">Each build stage must turn its scope green before it commits (build order §14): ' +
    '<strong>0</strong> foundation · <strong>1</strong> civic + WF-CIV-01…03/06/07 · <strong>2</strong> electoral + WF-CIV-04/05/08 + WF-ELE · ' +
    '<strong>3</strong> legislature + WF-LEG · <strong>4</strong> executive + organizations + WF-EXE/WF-ORG · <strong>5</strong> judiciary + WF-JUD · ' +
    '<strong>6</strong> jurisdictions/system + WF-JUR/WF-SYS · <strong>7</strong> polish, 100 % everywhere.</p></div>';

  /* -------------------------------------------------------------- roles */
  html += '<div class="card"><h2>Roles <span class="citation">' + rolesCovered + ' of ' + R.roles.length + ' covered</span></h2><div class="table-wrap"><table class="table coverage-table"><thead><tr><th>ID</th><th>Role</th><th>Tier</th><th>Status</th><th>Entry-point screens</th></tr></thead><tbody>';
  R.roles.forEach(function (r) {
    var list = byRole[r.id] || [];
    html += '<tr><td class="mono">' + r.id + '</td><td>' + esc(r.name) + '</td><td class="mono">' + esc(r.tier) + '</td>' +
      '<td>' + cell(list.length ? 'covered' : 'missing') + '</td><td class="mono">' + (list.length ? fileLinks(list) : '—') + '</td></tr>';
  });
  html += '</tbody></table></div></div>';

  /* ---------------------------------------------------------- workflows */
  var fams = ['CIV', 'ELE', 'LEG', 'EXE', 'JUD', 'ORG', 'JUR', 'SYS'];
  html += '<div class="card"><h2>Workflows <span class="citation">' + wfsCovered + ' of ' + R.workflows.length + ' covered</span></h2>';
  fams.forEach(function (fam) {
    var rows = R.workflows.filter(function (w) { return w.family === fam; });
    html += '<h3 style="margin-block-start:var(--space-4)">' + fam + ' <span class="citation">' + rows.length + ' workflows</span></h3>' +
      '<div class="table-wrap"><table class="table coverage-table"><thead><tr><th>ID</th><th>Workflow</th><th>Stage</th><th>Flow page</th><th>Referencing screens</th></tr></thead><tbody>';
    rows.forEach(function (w) {
      var refs = byWf[w.id] || [];
      html += '<tr><td class="mono">' + w.id + '</td><td>' + esc(w.name) + '</td><td class="mono">' + w.stage + '</td>' +
        '<td>' + (flowBuilt[w.id] ? cell('covered') : cell('missing', 'planned')) + '</td>' +
        '<td class="mono">' + (refs.length ? fileLinks(refs) : '—') + '</td></tr>';
    });
    html += '</tbody></table></div>';
  });
  html += '</div>';

  /* -------------------------------------------------------------- forms */
  var series = {};
  R.forms.forEach(function (f) {
    var s = f.id.split('-').slice(0, 2).join('-');
    (series[s] = series[s] || []).push(f);
  });
  html += '<div class="card"><h2>Forms <span class="citation">' + formsCovered + ' of ' + R.forms.length + ' covered</span></h2>' +
    '<p class="gloss">Form name first, ID second, everywhere (§2 item 11). Aliases record the workflows-catalog ID drift resolved per §2 — see MANIFEST.md.</p>';
  Object.keys(series).forEach(function (s) {
    html += '<h3 style="margin-block-start:var(--space-4)">' + esc(s) + ' <span class="citation">' + series[s].length + ' forms</span></h3>' +
      '<div class="table-wrap"><table class="table coverage-table"><thead><tr><th>ID</th><th>Form</th><th>Available to</th><th>Aliases</th><th>Status</th><th>Rendered on</th></tr></thead><tbody>';
    series[s].forEach(function (f) {
      var list = byForm[f.id] || [];
      html += '<tr><td class="mono">' + f.id + '</td><td>' + esc(f.name) + '</td><td class="mono">' + esc((f.availableTo || []).join(', ') || '—') + '</td>' +
        '<td class="mono">' + esc((f.aliases || []).join('; ') || '—') + '</td>' +
        '<td>' + cell(list.length ? 'covered' : 'missing') + '</td><td class="mono">' + (list.length ? fileLinks(list) : '—') + '</td></tr>';
    });
    html += '</tbody></table></div>';
  });
  html += '</div>';

  root.innerHTML = html;
  CGA.shell.refresh();

  /* ------------------------------------------- drift + dead-link checks */
  var isHttp = /^https?:$/.test(window.location.protocol);
  var driftEl = document.getElementById('drift-status');
  var linkBtn = document.getElementById('link-check-btn');
  var linkNote = document.getElementById('link-check-note');
  var linkOut = document.getElementById('link-check-out');

  if (!isHttp) {
    driftEl.textContent = 'Drift check runs over http(s) only — file:// cannot fetch manifest.json. The matrix above is rendered from the manifest.js mirror.';
    linkBtn.disabled = true;
    linkNote.textContent = 'Dead-link scan needs http(s) — serve mockups/ with e.g. "py -m http.server".';
  } else {
    fetch(CGA.shell.ROOT + 'manifest.json').then(function (r) { return r.json(); }).then(function (json) {
      var same = JSON.stringify(json) === JSON.stringify(M);
      driftEl.innerHTML = same
        ? icon('check', { size: 'sm' }) + ' manifest.json and the manifest.js mirror are identical (' + M.length + ' records).'
        : icon('alert-triangle', { size: 'sm' }) + ' <strong>manifest.js mirror out of date</strong> — regenerate it from manifest.json (snippet in MANIFEST.md).';
    }).catch(function () {
      driftEl.textContent = 'Could not fetch manifest.json (' + CGA.shell.ROOT + 'manifest.json).';
    });

    linkBtn.addEventListener('click', function () {
      linkOut.textContent = 'Scanning ' + files.length + ' files…';
      var bad = [];
      var done = 0;
      files.forEach(function (f) {
        fetch(CGA.shell.ROOT + f, { method: 'HEAD' }).then(function (r) {
          if (!r.ok) bad.push(f + ' (' + r.status + ')');
        }).catch(function () { bad.push(f + ' (unreachable)'); }).finally(function () {
          done++;
          if (done === files.length) {
            linkOut.innerHTML = bad.length
              ? icon('alert-triangle', { size: 'sm' }) + ' Dead manifest entries: ' + esc(bad.join(', '))
              : icon('check', { size: 'sm' }) + ' All ' + files.length + ' manifest files resolve.';
          }
        });
      });
    });
  }
  }
})();
