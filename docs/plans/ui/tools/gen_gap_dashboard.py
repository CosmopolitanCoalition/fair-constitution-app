#!/usr/bin/env python3
"""Render the drillable V3 conformance dashboard from v3_gap_data.json.

Usage:  python gen_gap_dashboard.py [out.html]
Reads   docs/plans/ui/v3_gap_data.json (the committed machine-readable matrix —
        update it as lanes land work, then regenerate + redeploy the artifact).
The operator's standing instruction (2026-07-29): progress matrixes with
drillable per-screen punch detail, redeployed as work lands.
"""
import json, sys, io, pathlib

HERE = pathlib.Path(__file__).resolve().parent
DATA = HERE.parent / "v3_gap_data.json"

TEMPLATE = r"""<title>V3 Conformance Matrix</title>
<style>
:root{
  --bg:#F6F6F3; --surface:#FFFFFF; --ink:#1B1E28; --muted:#5C6070; --faint:#8B8F9E;
  --line:rgba(27,30,40,.12); --line-strong:rgba(27,30,40,.22);
  --accent:#3B4A8C; --accent-soft:rgba(59,74,140,.08);
  --built:#1D8A47; --partial:#C98500; --absent:#C4553B;
  --built-soft:rgba(29,138,71,.12); --partial-soft:rgba(201,133,0,.14); --absent-soft:rgba(196,85,59,.13);
  --mono:"Cascadia Code",Consolas,ui-monospace,monospace;
  --sans:"Segoe UI Variable Text","Segoe UI",system-ui,sans-serif;
}
@media (prefers-color-scheme: dark){:root{
  --bg:#14161D; --surface:#1C1F29; --ink:#ECEDF2; --muted:#9BA0B0; --faint:#6E7385;
  --line:rgba(236,237,242,.12); --line-strong:rgba(236,237,242,.25);
  --accent:#8C9AD9; --accent-soft:rgba(140,154,217,.12);
  --built:#3FBF74; --partial:#E8A23D; --absent:#E07856;
  --built-soft:rgba(63,191,116,.14); --partial-soft:rgba(232,162,61,.15); --absent-soft:rgba(224,120,86,.15);
}}
:root[data-theme="dark"]{
  --bg:#14161D; --surface:#1C1F29; --ink:#ECEDF2; --muted:#9BA0B0; --faint:#6E7385;
  --line:rgba(236,237,242,.12); --line-strong:rgba(236,237,242,.25);
  --accent:#8C9AD9; --accent-soft:rgba(140,154,217,.12);
  --built:#3FBF74; --partial:#E8A23D; --absent:#E07856;
  --built-soft:rgba(63,191,116,.14); --partial-soft:rgba(232,162,61,.15); --absent-soft:rgba(224,120,86,.15);
}
:root[data-theme="light"]{
  --bg:#F6F6F3; --surface:#FFFFFF; --ink:#1B1E28; --muted:#5C6070; --faint:#8B8F9E;
  --line:rgba(27,30,40,.12); --line-strong:rgba(27,30,40,.22);
  --accent:#3B4A8C; --accent-soft:rgba(59,74,140,.08);
  --built:#1D8A47; --partial:#C98500; --absent:#C4553B;
  --built-soft:rgba(29,138,71,.12); --partial-soft:rgba(201,133,0,.14); --absent-soft:rgba(196,85,59,.13);
}
*{box-sizing:border-box}
body{background:var(--bg);color:var(--ink);font-family:var(--sans);margin:0;line-height:1.5}
.wrap{max-width:66rem;margin:0 auto;padding:2.2rem 1.2rem 4rem}
.eyebrow{font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);margin:0 0 .4rem}
h1{font-size:1.55rem;font-weight:600;margin:0 0 .3rem;text-wrap:balance}
.stamp{font-size:.82rem;color:var(--muted);margin:0 0 1.6rem}
.stamp code{font-family:var(--mono);font-size:.78rem;background:var(--accent-soft);padding:.08em .4em;border-radius:4px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr));gap:.7rem;margin:0 0 1rem}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:.8rem .95rem}
.tile .lbl{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 .25rem;display:flex;align-items:center;gap:.4rem}
.tile .num{font-size:1.7rem;font-weight:650;font-variant-numeric:tabular-nums;line-height:1.1}
.tile .sub{font-size:.78rem;color:var(--faint)}
.dot{inline-size:.55rem;block-size:.55rem;border-radius:50%;display:inline-block;flex:none}
.d-built{background:var(--built)} .d-partial{background:var(--partial)} .d-absent{background:var(--absent)}
.meter{display:flex;block-size:.85rem;border-radius:6px;overflow:hidden;margin:.2rem 0 1.7rem;border:1px solid var(--line)}
.meter span{block-size:100%}
.controls{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:0 0 1.1rem}
.controls input[type=search]{flex:1 1 14rem;background:var(--surface);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.88rem;padding:.42rem .7rem}
.controls input[type=search]:focus{outline:2px solid var(--accent);outline-offset:1px}
.chip{background:var(--surface);border:1px solid var(--line-strong);border-radius:999px;color:var(--muted);font:inherit;font-size:.8rem;padding:.28rem .75rem;cursor:pointer}
.chip[aria-pressed=true]{background:var(--accent);border-color:var(--accent);color:var(--bg)}
.chip:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.expanders{margin-inline-start:auto;display:flex;gap:.5rem}
.area{background:var(--surface);border:1px solid var(--line);border-radius:12px;margin:0 0 .65rem;overflow:hidden}
.area-head{display:grid;grid-template-columns:15rem 1fr 6.4rem 1.4rem;gap:.9rem;align-items:center;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.7rem .95rem;cursor:pointer}
.area-head:hover{background:var(--accent-soft)}
.area-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.area-name{font-weight:600;font-size:.93rem;line-height:1.25}
.area-owner{display:block;font-weight:400;font-size:.74rem;color:var(--faint)}
.bar{display:flex;block-size:.8rem;border-radius:5px;overflow:hidden;background:var(--line)}
.bar span{block-size:100%}
.s-built{background:var(--built)} .s-partial{background:var(--partial)} .s-absent{background:var(--absent)}
.counts{font-family:var(--mono);font-size:.8rem;color:var(--muted);text-align:end;font-variant-numeric:tabular-nums;white-space:nowrap}
.chev{color:var(--faint);transition:transform .15s}
.area-head[aria-expanded=true] .chev{transform:rotate(90deg)}
@media (prefers-reduced-motion: reduce){.chev{transition:none}}
.screens{border-top:1px solid var(--line)}
.scr{border-top:1px solid var(--line)}
.scr:first-child{border-top:0}
.scr-head{display:grid;grid-template-columns:auto 16rem 1fr auto auto;gap:.7rem;align-items:baseline;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.55rem .95rem .55rem 1.2rem;cursor:pointer}
.scr-head:hover{background:var(--accent-soft)}
.scr-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.scr-file{font-family:var(--mono);font-size:.78rem;color:var(--muted);overflow-wrap:anywhere}
.scr-title{font-size:.88rem}
.pill{font-size:.7rem;font-weight:600;letter-spacing:.04em;border-radius:999px;padding:.14em .6em;white-space:nowrap}
.p-built{background:var(--built-soft);color:var(--built)}
.p-partial{background:var(--partial-soft);color:var(--partial)}
.p-absent{background:var(--absent-soft);color:var(--absent)}
.eff{font-family:var(--mono);font-size:.74rem;color:var(--faint);white-space:nowrap}
.detail{padding:.35rem 1.2rem 1rem 2.15rem;font-size:.85rem}
.detail dl{margin:.35rem 0 0}
.detail dt{font-size:.7rem;letter-spacing:.09em;text-transform:uppercase;color:var(--faint);margin:.7rem 0 .2rem}
.detail dd{margin:0}
.detail ul{margin:.1rem 0 0;padding-inline-start:1.1rem}
.detail li{margin:.15rem 0}
.detail .meta{font-family:var(--mono);font-size:.76rem;color:var(--muted)}
.ok{color:var(--built)}
.legend{display:flex;gap:1.1rem;font-size:.78rem;color:var(--muted);margin:0 0 .8rem;flex-wrap:wrap}
.legend span{display:inline-flex;align-items:center;gap:.35rem}
.foot{font-size:.76rem;color:var(--faint);margin-top:2rem;border-top:1px solid var(--line);padding-top:.8rem}
.hidden{display:none}
mark{background:var(--partial-soft);color:inherit;border-radius:3px}
@media print{
  body{background:#fff;color:#000}
  .controls,.chev{display:none}
  .area{break-inside:avoid-page;border-color:#bbb}
  .screens.hidden,.detail.hidden{display:block}
}
@media (max-width: 46rem){
  .area-head{grid-template-columns:1fr 5.6rem 1rem;grid-template-rows:auto auto}
  .area-head .bar{grid-column:1/-1;grid-row:2}
  .scr-head{grid-template-columns:auto 1fr auto;grid-template-rows:auto auto}
  .scr-title{grid-column:1/-1;grid-row:2;padding-inline-start:1.15rem}
}
</style>
<div class="wrap">
<p class="eyebrow">Fair Constitution App · V3 conformance</p>
<h1>How close is the app to the design?</h1>
<p class="stamp">%%STAMP%% · one row per <code>mockups/v3</code> screen · click an area, then a screen, for the punch detail</p>
<div class="tiles" id="tiles"></div>
<div class="meter" id="meter" role="img" aria-label="Overall: built, partial, absent proportions of 107 screens"></div>
<div class="legend">
  <span><span class="dot d-built"></span>built to spec</span>
  <span><span class="dot d-partial"></span>partial</span>
  <span><span class="dot d-absent"></span>absent — no page</span>
  <span>effort: S copy/props · M one-surface rework · L new page+wiring · XL new subsystem</span>
</div>
<div class="controls">
  <input type="search" id="q" placeholder="Search screens, gaps, notes…" aria-label="Search screens">
  <button class="chip" data-f="all" aria-pressed="true">All</button>
  <button class="chip" data-f="built" aria-pressed="false">Built</button>
  <button class="chip" data-f="partial" aria-pressed="false">Partial</button>
  <button class="chip" data-f="absent" aria-pressed="false">Absent</button>
  <span class="expanders">
    <button class="chip" id="exAll">Expand all</button>
    <button class="chip" id="coAll">Collapse all</button>
  </span>
</div>
<div id="areas"></div>
<p class="foot">Generated from <code>docs/plans/ui/v3_gap_data.json</code> by <code>gen_gap_dashboard.py</code>.
Evidence per row: <code>docs/plans/ui/V3_GAP_MATRIX.md</code> · build order: <code>V3_SYNTHESIS_PLAN.md</code>.
“App has, spec lacks” items are reconciled INTO the spec, never stripped from the app (plan §1).</p>
</div>
<script>
const D=%%DATA%%;
const rows=D.rows;
const esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const B={built:'built to spec',partial:'partial',absent:'absent'};
const order=[...new Set(rows.map(r=>r.area))];
const byArea={};order.forEach(a=>byArea[a]=rows.filter(r=>r.area===a));
const ratio=a=>{const g=byArea[a];return g.filter(r=>r.bucket==='built').length/g.length};
order.sort((a,b)=>ratio(b)-ratio(a));
const tot={built:0,partial:0,absent:0};rows.forEach(r=>tot[r.bucket]++);
const effTot={};rows.forEach(r=>{if(r.effort!=='none')effTot[r.effort]=(effTot[r.effort]||0)+1});
document.getElementById('tiles').innerHTML=`
 <div class="tile"><p class="lbl"><span class="dot d-built"></span>Built to spec</p><div class="num">${tot.built}</div><div class="sub">of ${rows.length} · ${Math.round(100*tot.built/rows.length)}%</div></div>
 <div class="tile"><p class="lbl"><span class="dot d-partial"></span>Partial</p><div class="num">${tot.partial}</div><div class="sub">page exists, gaps listed</div></div>
 <div class="tile"><p class="lbl"><span class="dot d-absent"></span>Absent</p><div class="num">${tot.absent}</div><div class="sub">no page yet</div></div>
 <div class="tile"><p class="lbl">Effort left</p><div class="num" style="font-size:1.05rem;line-height:1.6">${['S','M','L','XL'].map(e=>`${effTot[e]||0}<span style="color:var(--faint)">${e}</span>`).join(' · ')}</div><div class="sub">screens by grade</div></div>`;
document.getElementById('meter').innerHTML=['built','partial','absent'].map(k=>`<span class="s-${k}" style="flex:${tot[k]}"></span>`).join('');
const areasEl=document.getElementById('areas');
let filter='all',q='';
function detailHTML(r){
  const li=x=>x.map(i=>`<li>${esc(i)}</li>`).join('');
  let h='<dl>';
  h+=`<dt>Where</dt><dd class="meta">${r.page?esc(r.page):'<em>no page</em>'}${r.route?' · '+esc(r.route):''} · props: ${esc(r.props)} · backend: ${esc(r.backend)} · owner ${esc(r.owner)}</dd>`;
  if(r.propsMissing.length)h+=`<dt>Props missing</dt><dd><ul>${li(r.propsMissing)}</ul></dd>`;
  if(r.backendMissing.length)h+=`<dt>Backend missing</dt><dd><ul>${li(r.backendMissing)}</ul></dd>`;
  if(r.specHas.length)h+=`<dt>Spec has · app lacks</dt><dd><ul>${li(r.specHas)}</ul></dd>`;
  if(r.appAhead.length)h+=`<dt>App has · spec lacks (reconcile, don't strip)</dt><dd><ul>${li(r.appAhead)}</ul></dd>`;
  if(r.notes)h+=`<dt>Notes</dt><dd>${esc(r.notes)}</dd>`;
  if(!r.propsMissing.length&&!r.backendMissing.length&&!r.specHas.length&&!r.notes)h+=`<dd class="ok">Conformant — nothing outstanding.</dd>`;
  return h+'</dl>';
}
function match(r){
  if(filter!=='all'&&r.bucket!==filter)return false;
  if(!q)return true;
  const hay=(r.file+' '+r.title+' '+r.notes+' '+r.specHas.join(' ')+' '+r.appAhead.join(' ')+' '+r.propsMissing.join(' ')).toLowerCase();
  return hay.includes(q);
}
function render(){
  areasEl.innerHTML='';
  order.forEach(a=>{
    const g=byArea[a],vis=g.filter(match);
    if(!vis.length)return;
    const c={built:0,partial:0,absent:0};g.forEach(r=>c[r.bucket]++);
    const wrap=document.createElement('section');wrap.className='area';
    wrap.innerHTML=`
      <button class="area-head" aria-expanded="false">
        <span class="area-name">${esc(g[0].areaLabel)}<span class="area-owner">${esc(g[0].owner)}</span></span>
        <span class="bar">${['built','partial','absent'].map(k=>c[k]?`<span class="s-${k}" style="flex:${c[k]}"></span>`:'').join('')}</span>
        <span class="counts">${c.built} / ${c.partial} / ${c.absent}</span>
        <span class="chev" aria-hidden="true">›</span>
      </button>
      <div class="screens hidden"></div>`;
    const list=wrap.querySelector('.screens');
    vis.forEach(r=>{
      const s=document.createElement('div');s.className='scr';
      s.innerHTML=`
        <button class="scr-head" aria-expanded="false">
          <span class="dot d-${r.bucket}" aria-hidden="true"></span>
          <span class="scr-file">${esc(r.file)}</span>
          <span class="scr-title">${esc(r.title)}</span>
          <span class="pill p-${r.bucket}">${B[r.bucket]}</span>
          <span class="eff">${r.effort==='none'?'—':r.effort}</span>
        </button>
        <div class="detail hidden">${detailHTML(r)}</div>`;
      const bh=s.querySelector('.scr-head'),dt=s.querySelector('.detail');
      bh.addEventListener('click',()=>{const o=dt.classList.toggle('hidden');bh.setAttribute('aria-expanded',String(o?false:true));});
      list.appendChild(s);
    });
    const head=wrap.querySelector('.area-head');
    head.addEventListener('click',()=>{const o=list.classList.toggle('hidden');head.setAttribute('aria-expanded',String(o?false:true));});
    if(q||filter!=='all'){list.classList.remove('hidden');head.setAttribute('aria-expanded','true');}
    areasEl.appendChild(wrap);
  });
}
document.getElementById('q').addEventListener('input',e=>{q=e.target.value.trim().toLowerCase();render();});
document.querySelectorAll('.chip[data-f]').forEach(ch=>ch.addEventListener('click',()=>{
  filter=ch.dataset.f;
  document.querySelectorAll('.chip[data-f]').forEach(x=>x.setAttribute('aria-pressed',String(x===ch)));
  render();
}));
document.getElementById('exAll').addEventListener('click',()=>{document.querySelectorAll('.screens,.detail').forEach(x=>x.classList.remove('hidden'));document.querySelectorAll('.area-head,.scr-head').forEach(x=>x.setAttribute('aria-expanded','true'));});
document.getElementById('coAll').addEventListener('click',()=>{document.querySelectorAll('.screens,.detail').forEach(x=>x.classList.add('hidden'));document.querySelectorAll('.area-head,.scr-head').forEach(x=>x.setAttribute('aria-expanded','false'));});
render();
</script>
"""

def main():
    data = json.loads(io.open(DATA, encoding="utf-8").read())
    stamp = f"As of {data['asOf']} · main @ <code>{data['commit']}</code> · {data['wave']}"
    html = TEMPLATE.replace("%%DATA%%", json.dumps(data, separators=(",", ":"))).replace("%%STAMP%%", stamp)
    out = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else HERE / "v3_gap_dashboard.html"
    io.open(out, "w", encoding="utf-8").write(html)
    print(f"wrote {out} ({len(html)} bytes, {len(data['rows'])} rows)")

if __name__ == "__main__":
    main()
