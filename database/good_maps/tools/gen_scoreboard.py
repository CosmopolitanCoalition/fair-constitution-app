#!/usr/bin/env python3
"""Good Maps scoreboard generator.

Reads the standard maps' stats CSVs + iteration CSVs and writes a single-file
HTML scoreboard (stdout path arg). Each iteration republishes the same artifact.

Usage:
  python3 gen_scoreboard.py <out.html> earth=<std.csv>:<iterN.csv>[,iterM.csv...] usa=...
Iteration files are labeled by their filename suffix (iter1, iter2, ...).
"""
import csv

import json
import re
import sys
from collections import defaultdict

PRIORITY = ['legality', 'contiguity', 'compactness', 'deviation']


def load(path):
    scopes = defaultdict(list)
    with open(path, encoding='utf-8') as f:
        for r in csv.DictReader(f):
            r['seats'] = int(r['seats'])
            r['fractional_seats'] = float(r['fractional_seats'])
            r['floor_override'] = r.get('floor_override') == 't'
            r['is_contiguous'] = r['is_contiguous'] == 't'
            r['convex_hull_ratio'] = float(r['convex_hull_ratio']) if r['convex_hull_ratio'] else None
            scopes[r['scope']].append(r)
    return scopes


def rollup(scopes):
    out = {}
    for s, rows in scopes.items():
        chrs = [r['convex_hull_ratio'] for r in rows if r['convex_hull_ratio'] is not None]
        out[s] = {
            'districts': len(rows),
            'seats': sum(r['seats'] for r in rows),
            'fit': round(sum(abs(r['fractional_seats'] - r['seats']) for r in rows), 3),
            'noncontig': sum(1 for r in rows if not r['is_contiguous']),
            'chr': round(sum(chrs) / len(chrs), 4) if chrs else None,
            'band': sum(1 for r in rows if (r['seats'] < 5 and not r['floor_override']) or r['seats'] > 9),
        }
    return out


def clusters(scopes):
    out = set()
    for s, rows in scopes.items():
        for r in rows:
            if not r['is_contiguous']:
                out.add((s, frozenset(m.strip() for m in (r['members'] or '').split(';') if m.strip())))
    return out


def overall(scopes):
    rows = [r for rr in scopes.values() for r in rr]
    chrs = [r['convex_hull_ratio'] for r in rows if r['convex_hull_ratio'] is not None]
    return {
        'districts': len(rows),
        'seats': sum(r['seats'] for r in rows),
        'noncontig': sum(1 for r in rows if not r['is_contiguous']),
        'chr': round(sum(chrs) / len(chrs), 4) if chrs else None,
        'fit': round(sum(abs(r['fractional_seats'] - r['seats']) for r in rows), 2),
        'band': sum(1 for r in rows if (r['seats'] < 5 and not r['floor_override']) or r['seats'] > 9),
    }


def analyze(std_scopes, cand_scopes, budget):
    rs, rc = rollup(std_scopes), rollup(cand_scopes)
    os_, oc = overall(std_scopes), overall(cand_scopes)
    cs, cc = clusters(std_scopes), clusters(cand_scopes)
    scope_deltas = []
    chr_wins = chr_ties = chr_loss = fit_wins = fit_ties = fit_loss = 0
    for s in sorted(set(rs) | set(rc)):
        a, b = rs.get(s), rc.get(s)
        if not a or not b:
            continue
        dchr = round((b['chr'] or 0) - (a['chr'] or 0), 4)
        dfit = round(b['fit'] - a['fit'], 3)
        if dchr > 0.005: chr_wins += 1
        elif dchr < -0.005: chr_loss += 1
        else: chr_ties += 1
        if dfit < -0.005: fit_wins += 1
        elif dfit > 0.005: fit_loss += 1
        else: fit_ties += 1
        if abs(dchr) > 0.005 or abs(dfit) > 0.005 or b['noncontig'] != a['noncontig'] or b['districts'] != a['districts']:
            scope_deltas.append({
                'scope': s, 'dchr': dchr, 'dfit': dfit,
                'std': a, 'cand': b,
            })
    scope_deltas.sort(key=lambda d: d['dchr'])
    return {
        'overall_std': os_, 'overall': oc,
        'chamber_exact': oc['seats'] == budget,
        'band': oc['band'],
        'clusters_std': len(cs), 'clusters': len(cc),
        'clusters_shared': len(cs & cc), 'clusters_new': len(cc - cs), 'clusters_gone': len(cs - cc),
        'new_cluster_list': [{'scope': s, 'members': sorted(m)[:14]} for s, m in sorted(cc - cs)],
        'chr_wins': chr_wins, 'chr_ties': chr_ties, 'chr_loss': chr_loss,
        'fit_wins': fit_wins, 'fit_ties': fit_ties, 'fit_loss': fit_loss,
        'scope_deltas': scope_deltas,
    }


def main():
    out_path = sys.argv[1]
    legs = {}
    for arg in sys.argv[2:]:
        name, files = arg.split('=', 1)
        parts = files.split(':')
        std = parts[0]
        iters = parts[1].split(',') if len(parts) > 1 else []
        legs[name] = (std, iters)

    budgets = {'earth': 2003, 'usa': 702}
    titles = {'earth': 'Earth — 2,003 seats', 'usa': 'United States — 702 seats'}
    std_names = {'earth': 'Manual Map Draft 1', 'usa': 'Manual Map Draft'}

    data = {}
    for name, (std_path, iter_paths) in legs.items():
        std = load(std_path)
        iterations = []
        for p in iter_paths:
            label = re.search(r'(iter\d+)', p)
            iterations.append({
                'label': label.group(1) if label else p,
                **analyze(std, load(p), budgets[name]),
            })
        data[name] = {
            'title': titles[name], 'std_name': std_names[name],
            'std_overall': overall(std), 'iterations': iterations,
        }

    # JSON rides inside a <script type="application/json"> element: script text
    # is never entity-decoded, so HTML-escaping would corrupt it — the only
    # hazard is a literal </script>, guarded by the <\/ substitution (valid JSON).
    payload = json.dumps(data, ensure_ascii=False)
    page = TEMPLATE.replace('__DATA__', payload.replace('</', '<\\/'))
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write(page)
    print(f'wrote {out_path}')


TEMPLATE = r'''<title>Good Maps Scoreboard</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bitter:wght@600;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style>
:root {
  --paper: #f6f4ee; --panel: #fffdf7; --ink: #232a31; --muted: #6b7480;
  --line: #d8d4c8; --accent: #2563a8; --accent-soft: #e3ecf6;
  --pass: #2e7d4f; --pass-soft: #e2efe7; --trail: #b0762a; --trail-soft: #f4ead9;
  --fail: #b03a2e; --fail-soft: #f6e3e0; --bar-neg: #b0762a; --bar-pos: #2e7d4f;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --paper: #171d24; --panel: #1f2731; --ink: #e8e4da; --muted: #97a1ad;
    --line: #35404c; --accent: #6ba3d6; --accent-soft: #24384d;
    --pass: #63b98a; --pass-soft: #23392d; --trail: #d9a35c; --trail-soft: #3d3222;
    --fail: #d97b6f; --fail-soft: #422823; --bar-neg: #d9a35c; --bar-pos: #63b98a;
  }
}
:root[data-theme="dark"] {
  --paper: #171d24; --panel: #1f2731; --ink: #e8e4da; --muted: #97a1ad;
  --line: #35404c; --accent: #6ba3d6; --accent-soft: #24384d;
  --pass: #63b98a; --pass-soft: #23392d; --trail: #d9a35c; --trail-soft: #3d3222;
  --fail: #d97b6f; --fail-soft: #422823; --bar-neg: #d9a35c; --bar-pos: #63b98a;
}
* { box-sizing: border-box; }
body {
  background: var(--paper); color: var(--ink);
  font-family: "IBM Plex Sans", "Segoe UI", system-ui, sans-serif;
  font-size: 15px; line-height: 1.5; margin: 0; padding: 24px 20px 60px;
}
.wrap { max-width: 1060px; margin: 0 auto; }
h1 { font-family: Bitter, Georgia, serif; font-weight: 800; font-size: 30px; margin: 0 0 2px; text-wrap: balance; }
.sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; }
.sub b { color: var(--ink); font-weight: 600; }
h2 { font-family: Bitter, Georgia, serif; font-weight: 600; font-size: 21px; margin: 34px 0 10px; border-bottom: 2px solid var(--line); padding-bottom: 6px; }
.lbl { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); font-weight: 600; }
table.score { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; }
.tblwrap { overflow-x: auto; }
table.score th, table.score td { text-align: right; padding: 7px 12px; border-bottom: 1px solid var(--line); white-space: nowrap; }
table.score th:first-child, table.score td:first-child { text-align: left; }
table.score thead th { font-size: 11px; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); }
.chip { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.chip.pass  { background: var(--pass-soft);  color: var(--pass); }
.chip.trail { background: var(--trail-soft); color: var(--trail); }
.chip.fail  { background: var(--fail-soft);  color: var(--fail); }
.chip.info  { background: var(--accent-soft); color: var(--accent); }
.scopes { margin-top: 14px; }
.scope-row { border: 1px solid var(--line); border-radius: 6px; margin: 6px 0; background: var(--panel); }
.scope-head { display: flex; align-items: center; gap: 10px; padding: 7px 12px; cursor: pointer; }
.scope-head:hover { background: var(--accent-soft); }
.scope-name { flex: 0 0 200px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bar-track { flex: 1; height: 14px; position: relative; background: none; min-width: 120px; }
.bar-mid { position: absolute; left: 50%; top: -2px; bottom: -2px; width: 1px; background: var(--line); }
.bar { position: absolute; top: 2px; height: 10px; border-radius: 2px; }
.dnum { flex: 0 0 130px; text-align: right; font-variant-numeric: tabular-nums; font-size: 13px; color: var(--muted); }
.dnum b { color: var(--ink); }
.scope-detail { display: none; border-top: 1px solid var(--line); padding: 8px 14px 10px; font-size: 13px; }
.scope-detail table { border-collapse: collapse; font-variant-numeric: tabular-nums; }
.scope-detail td, .scope-detail th { padding: 2px 12px 2px 0; text-align: right; }
.scope-detail td:first-child, .scope-detail th:first-child { text-align: left; }
.scope-row.open .scope-detail { display: block; }
.newclusters { font-size: 13px; color: var(--muted); margin: 8px 0 0; }
.newclusters li { margin: 2px 0; }
.legendline { display: flex; gap: 18px; margin: 8px 0 0; font-size: 12px; color: var(--muted); flex-wrap: wrap; }
.sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; vertical-align: -1px; margin-right: 5px; }
.note { font-size: 13px; color: var(--muted); margin-top: 6px; }
</style>
<div class="wrap">
  <h1>Good Maps Scoreboard</h1>
  <p class="sub">Auto Districting vs the standard — <b>reach the manual maps or beat them, on every
  count</b>, scored in the operator's priority order: legality &gt; contiguity &gt; compactness &gt; deviation.</p>
  <div id="root"></div>
</div>
<script type="application/json" id="payload">__DATA__</script>
<script>
const DATA = JSON.parse(document.getElementById('payload').textContent);
const root = document.getElementById('root');
const f3 = x => x == null ? '—' : x.toFixed(3);
const sgn = x => (x > 0 ? '+' : '') + x;

function chip(cls, txt) { return `<span class="chip ${cls}">${txt}</span>`; }

for (const key of Object.keys(DATA)) {
  const leg = DATA[key];
  const so = leg.std_overall;
  let h = `<h2>${leg.title}</h2>
  <p class="note">Standard: <b>${leg.std_name}</b> — ${so.districts} districts · ${so.seats} seats ·
  ${so.noncontig} non-contiguous (all deliberate) · avg CHR ${f3(so.chr)} · fit gap ${so.fit}</p>
  <div class="tblwrap"><table class="score"><thead>
  <tr><th>Iteration</th><th>1 · Legality</th><th>2 · Contiguity</th><th>3 · Compactness</th><th>4 · Deviation</th><th>Districts</th></tr>
  </thead><tbody>`;
  leg.iterations.forEach(it => {
    const legal = it.chamber_exact && it.band === 0;
    const contig = it.clusters <= it.clusters_std && it.clusters_new === 0
      ? chip('pass', `${it.clusters} vs ${it.clusters_std}`)
      : chip(it.clusters <= it.clusters_std ? 'info' : 'trail', `${it.clusters} vs ${it.clusters_std} · ${it.clusters_new} differ`);
    const comp = it.chr_loss === 0
      ? chip('pass', `CHR ${f3(it.overall.chr)} vs ${f3(it.overall_std.chr)}`)
      : chip(it.overall.chr >= it.overall_std.chr ? 'info' : 'trail',
             `CHR ${f3(it.overall.chr)} vs ${f3(it.overall_std.chr)} · trails in ${it.chr_loss}`);
    const dev = it.fit_loss === 0
      ? chip('pass', `fit ${it.overall.fit} vs ${it.overall_std.fit}`)
      : chip(it.overall.fit <= it.overall_std.fit ? 'info' : 'trail',
             `fit ${it.overall.fit} vs ${it.overall_std.fit} · worse in ${it.fit_loss}`);
    h += `<tr><td><b>${it.label}</b></td>
      <td>${legal ? chip('pass', `${it.overall.seats} exact · band 0`) : chip('fail', 'VIOLATION')}</td>
      <td>${contig}</td><td>${comp}</td><td>${dev}</td>
      <td>${it.overall.districts} vs ${so.districts}</td></tr>`;
  });
  h += `</tbody></table></div>`;

  const last = leg.iterations[leg.iterations.length - 1];
  if (last && last.scope_deltas.length) {
    const maxAbs = Math.max(...last.scope_deltas.map(d => Math.abs(d.dchr)), 0.02);
    h += `<div class="scopes"><div class="lbl">${last.label} — scopes that differ from the standard (ΔCHR, click for detail)</div>`;
    last.scope_deltas.forEach((d, i) => {
      const w = Math.abs(d.dchr) / maxAbs * 50;
      const left = d.dchr < 0 ? 50 - w : 50;
      const color = d.dchr < -0.005 ? 'var(--bar-neg)' : (d.dchr > 0.005 ? 'var(--bar-pos)' : 'var(--muted)');
      h += `<div class="scope-row" id="${key}-sr${i}">
        <div class="scope-head" onclick="this.parentElement.classList.toggle('open')">
          <span class="scope-name">${d.scope}</span>
          <span class="bar-track"><span class="bar-mid"></span>
            <span class="bar" style="left:${left}%;width:${Math.max(w,0.6)}%;background:${color}"></span></span>
          <span class="dnum"><b>${sgn(d.dchr.toFixed(3))}</b> CHR</span>
          <span class="dnum">${sgn(d.dfit.toFixed(2))} fit</span>
        </div>
        <div class="scope-detail"><table>
          <tr><th></th><th>districts</th><th>seats</th><th>non-contig</th><th>avg CHR</th><th>fit</th></tr>
          <tr><td>standard</td><td>${d.std.districts}</td><td>${d.std.seats}</td><td>${d.std.noncontig}</td><td>${f3(d.std.chr)}</td><td>${d.std.fit}</td></tr>
          <tr><td>${last.label}</td><td>${d.cand.districts}</td><td>${d.cand.seats}</td><td>${d.cand.noncontig}</td><td>${f3(d.cand.chr)}</td><td>${d.cand.fit}</td></tr>
        </table></div></div>`;
    });
    h += `<div class="legendline">
      <span><span class="sw" style="background:var(--bar-pos)"></span>auto more compact than the standard</span>
      <span><span class="sw" style="background:var(--bar-neg)"></span>auto trails the standard</span>
      <span>bars scale to ±${maxAbs.toFixed(3)} CHR</span></div>`;
    if (last.new_cluster_list.length) {
      h += `<div class="lbl" style="margin-top:14px">${last.label} — non-contiguous clusters not on the standard</div><ul class="newclusters">`;
      last.new_cluster_list.forEach(c => {
        h += `<li><b>${c.scope}</b>: ${c.members.join(', ')}${c.members.length >= 14 ? '…' : ''}</li>`;
      });
      h += `</ul>`;
    }
    h += `</div>`;
  }
  root.insertAdjacentHTML('beforeend', h);
}
</script>
'''

if __name__ == '__main__':
    main()
