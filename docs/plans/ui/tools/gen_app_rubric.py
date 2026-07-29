# -*- coding: utf-8 -*-
"""Comprehensive App Progress Rubric — replicates the beloved v3_gap_dashboard functionality
(search, filter, expand/collapse-all, rich per-item punch detail) across THREE views:
UI Screens, Capabilities, Tech Debt. JS-driven like the original (proven to run in artifacts)."""
import json, io
from collections import defaultdict, Counter

base = json.load(open(r'E:\fair-constitution-app\docs\plans\ui\v3_gap_data.json', encoding='utf-8'))
res = json.load(open(r'C:\Users\JOSEPH~1\AppData\Local\Temp\claude\E--fair-constitution-app\355910cb-829c-4f85-8b3a-3a74acb84871\scratchpad\rubric_data.json', encoding='utf-8'))

trans = {}
for sc in res['screens']:
    for ch in sc.get('changes', []):
        trans[ch['file']] = ch['nowBucket']

# screens: keep ALL detail, apply verified bucket transitions
screens = []
for r in base['rows']:
    screens.append({
        'file': r['file'], 'title': r['title'], 'area': r['areaLabel'],
        'bucket': trans.get(r['file'], r['bucket']), 'effort': r.get('effort', 'none'),
        'page': r.get('page', ''), 'route': r.get('route', ''),
        'props': r.get('props', ''), 'backend': r.get('backend', ''), 'owner': r.get('owner', ''),
        'propsMissing': r.get('propsMissing', []), 'backendMissing': r.get('backendMissing', []),
        'specHas': r.get('specHas', []), 'appAhead': r.get('appAhead', []), 'notes': r.get('notes', ''),
    })

caps = [{'area': c['area'], 'capability': c['capability'], 'maturity': c['maturity'],
         'scaleNote': c.get('scaleNote', ''), 'blocker': c.get('blocker', '')}
        for c in res['capabilities']['capabilities']]

debt = [{'title': d['title'], 'severity': d['severity'], 'category': d.get('category', ''),
         'owner': d.get('owner', ''), 'location': d.get('location', ''),
         'status': d.get('status', ''), 'note': d.get('note', '')}
        for d in res['techDebt']['debt']]

DATA = {'asOf': '2026-07-29', 'head': '70a6a4c', 'forms': 117,
        'screens': screens, 'caps': caps, 'debt': debt}

TEMPLATE = r"""<title>App Progress Rubric — CGA</title>
<style>
:root{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);--mono:"Cascadia Code",Consolas,ui-monospace,monospace;--sans:"Segoe UI Variable Text","Segoe UI",system-ui,sans-serif;}
@media (prefers-color-scheme:dark){:root{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}}
:root[data-theme="dark"]{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}
:root[data-theme="light"]{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);}
*{box-sizing:border-box}
body{background:var(--bg);color:var(--ink);font-family:var(--sans);margin:0;line-height:1.5}
.wrap{max-width:70rem;margin:0 auto;padding:2rem 1.1rem 4rem}
.eyebrow{font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);margin:0 0 .4rem}
h1{font-size:1.55rem;font-weight:600;margin:0 0 .3rem}
.stamp{font-size:.82rem;color:var(--muted);margin:0 0 1.3rem}
.stamp code{font-family:var(--mono);font-size:.78rem;background:var(--accent-soft);padding:.08em .4em;border-radius:4px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.7rem;margin:0 0 1rem}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:.8rem .95rem}
.tile .lbl{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 .25rem}
.tile .num{font-size:1.6rem;font-weight:650;font-variant-numeric:tabular-nums;line-height:1.1}
.tile .sub{font-size:.77rem;color:var(--faint)}
.tile .meter{display:flex;block-size:.5rem;border-radius:4px;overflow:hidden;margin-top:.45rem;background:var(--line)}
.tile .meter span{block-size:100%}
.dot{inline-size:.55rem;block-size:.55rem;border-radius:50%;display:inline-block;flex:none;vertical-align:middle}
.s-good,.d-good{background:var(--good)}.s-warn,.d-warn{background:var(--warn)}.s-bad,.d-bad{background:var(--bad)}.s-block,.d-block{background:var(--block)}
.note{background:var(--surface);border:1px solid var(--line);border-inline-start:4px solid var(--bad);border-radius:9px;padding:.75rem .9rem;font-size:.85rem;margin:.2rem 0 1.4rem}
.note b{color:var(--ink)}
.views{display:flex;gap:.4rem;border-bottom:2px solid var(--line);margin:0 0 1rem}
.view-btn{padding:.55rem .9rem;font:inherit;font-size:.92rem;font-weight:600;color:var(--muted);background:none;border:0;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer}
.view-btn[aria-selected=true]{color:var(--ink);border-bottom-color:var(--accent)}
.view-btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.controls{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:0 0 1rem}
.controls input[type=search]{flex:1 1 13rem;background:var(--surface);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.88rem;padding:.42rem .7rem}
.controls input[type=search]:focus{outline:2px solid var(--accent);outline-offset:1px}
.chip{background:var(--surface);border:1px solid var(--line-strong);border-radius:999px;color:var(--muted);font:inherit;font-size:.8rem;padding:.28rem .75rem;cursor:pointer}
.chip[aria-pressed=true]{background:var(--accent);border-color:var(--accent);color:var(--bg)}
.chip:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.expanders{margin-inline-start:auto;display:flex;gap:.5rem}
.area{background:var(--surface);border:1px solid var(--line);border-radius:12px;margin:0 0 .6rem;overflow:hidden}
.area-head{display:grid;grid-template-columns:15rem 1fr 9rem 1.2rem;gap:.9rem;align-items:center;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.7rem .95rem;cursor:pointer}
.area-head:hover{background:var(--accent-soft)}
.area-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.area-name{font-weight:600;font-size:.93rem}
.bar{display:flex;block-size:.8rem;border-radius:5px;overflow:hidden;background:var(--line)}
.bar span{block-size:100%}
.counts{font-family:var(--mono);font-size:.76rem;color:var(--muted);text-align:end;white-space:nowrap}
.chev{color:var(--faint);transition:transform .15s}
.area-head[aria-expanded=true] .chev{transform:rotate(90deg)}
.rows{border-top:1px solid var(--line)}
.scr{border-top:1px solid var(--line)}.scr:first-child{border-top:0}
.scr-head{display:grid;grid-template-columns:auto 1fr auto auto;gap:.7rem;align-items:baseline;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.55rem .95rem .55rem 1.2rem;cursor:pointer}
.scr-head:hover{background:var(--accent-soft)}
.scr-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.scr-title{font-size:.88rem}
.scr-file{font-family:var(--mono);font-size:.75rem;color:var(--faint);display:block;margin-top:.1rem}
.pill{font-size:.68rem;font-weight:600;letter-spacing:.03em;border-radius:999px;padding:.14em .6em;white-space:nowrap}
.p-built,.p-working{background:var(--good-s);color:var(--good)}.p-partial{background:var(--warn-s);color:var(--warn)}.p-absent{background:var(--bad-s);color:var(--bad)}.p-blocked{background:var(--block-s);color:var(--block)}
.p-high{background:var(--bad-s);color:var(--bad)}.p-medium{background:var(--warn-s);color:var(--warn)}.p-low{background:var(--accent-soft);color:var(--muted)}
.eff{font-family:var(--mono);font-size:.72rem;color:var(--faint);white-space:nowrap}
.detail{padding:.35rem 1.2rem 1rem 2.15rem;font-size:.85rem;border-top:1px dashed var(--line)}
.detail dl{margin:0}
.detail dt{font-size:.7rem;letter-spacing:.09em;text-transform:uppercase;color:var(--faint);margin:.7rem 0 .2rem}
.detail dt.blk{color:var(--bad)}
.detail dd{margin:0}
.detail ul{margin:.1rem 0 0;padding-inline-start:1.1rem}.detail li{margin:.15rem 0}
.detail .meta{font-family:var(--mono);font-size:.76rem;color:var(--muted)}
.ok{color:var(--good)}
.hidden{display:none}
mark{background:var(--warn-s);color:inherit;border-radius:3px}
.foot{font-size:.76rem;color:var(--faint);margin-top:2rem;border-top:1px solid var(--line);padding-top:.8rem}
@media (max-width:46rem){.area-head{grid-template-columns:1fr 6rem 1rem;grid-template-rows:auto auto}.area-head .bar{grid-column:1/-1;grid-row:2}.scr-head{grid-template-columns:auto 1fr auto}}
</style>
<div class="wrap">
<p class="eyebrow">Fair Constitution App · App Progress Rubric</p>
<h1>Where does the app stand?</h1>
<p class="stamp">%%STAMP%% · verified against live code · click a group, then a row, for the punch detail · search + filter + expand-all below</p>
<div class="tiles" id="tiles"></div>
<div class="note"><b>⚠ The one thing blocking governance at scale:</b> the <b>Type B second-chamber race</b>. The built engine runs one pooled at-large race, but the ruled model is one at-large race <b>per child</b> (or per clump). A large jurisdiction cannot elect its second chamber, cascading to block every bicameral act there. Seat math is correct; only the vote grouping must change (Wave 4, protected counting engine). Top item under Tech Debt.</div>
<div class="views" role="tablist">
  <button class="view-btn" role="tab" data-v="screens" aria-selected="true">UI Screens</button>
  <button class="view-btn" role="tab" data-v="caps" aria-selected="false">Capabilities</button>
  <button class="view-btn" role="tab" data-v="debt" aria-selected="false">Tech Debt</button>
</div>
<div class="controls">
  <input type="search" id="q" placeholder="Search…" aria-label="Search">
  <span id="filters"></span>
  <span class="expanders"><button class="chip" id="exAll">Expand all</button><button class="chip" id="coAll">Collapse all</button></span>
</div>
<div id="body"></div>
<p class="foot">Generated from <code>v3_gap_data.json</code> + the 8-agent verification workflow. UI screens vs the 107 <code>mockups/v3</code> screens; capabilities + tech-debt from the live-code sweep.</p>
</div>
<script>
const D=%%DATA%%;
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const CO={built:'good',working:'good',partial:'warn',absent:'bad',blocked:'block',high:'bad',medium:'warn',low:'low'};
const LB={built:'built',working:'working',partial:'partial',absent:'absent',blocked:'blocked',high:'high',medium:'medium',low:'low'};
let view='screens',q='',filter='all';
const FILTERS={screens:['all','built','partial','absent'],caps:['all','working','partial','blocked','absent'],debt:['all','high','medium','low']};

// tiles
const sc=t=>D.screens.filter(r=>r.bucket===t).length;
const cc=t=>D.caps.filter(r=>r.maturity===t).length;
const dc=t=>D.debt.filter(r=>r.severity===t).length;
function meter(parts){const tot=parts.reduce((a,p)=>a+p[0],0)||1;return parts.map(p=>p[0]?`<span class="s-${p[1]}" style="flex:${p[0]}"></span>`:'').join('');}
document.getElementById('tiles').innerHTML=`
 <div class="tile"><p class="lbl">UI Screens</p><div class="num">${sc('built')} / ${D.screens.length}</div><div class="sub">${sc('partial')} partial · ${sc('absent')} absent</div><div class="meter">${meter([[sc('built'),'good'],[sc('partial'),'warn'],[sc('absent'),'bad']])}</div></div>
 <div class="tile"><p class="lbl">Capabilities</p><div class="num">${cc('working')} / ${D.caps.length}</div><div class="sub">${cc('partial')} part · ${cc('blocked')} blocked · ${cc('absent')} absent</div><div class="meter">${meter([[cc('working'),'good'],[cc('partial'),'warn'],[cc('blocked'),'block'],[cc('absent'),'bad']])}</div></div>
 <div class="tile"><p class="lbl">Technical Debt</p><div class="num">${D.debt.length} items</div><div class="sub">${dc('high')} high · ${dc('medium')} med · ${dc('low')} low</div><div class="meter">${meter([[dc('high'),'bad'],[dc('medium'),'warn'],[dc('low','good')?dc('low'):0,'good']])}</div></div>
 <div class="tile"><p class="lbl">Build phase</p><div class="num">0–5</div><div class="sub">phases done · forms ${D.forms} · Wave 4 next</div><div class="meter"><span class="s-good" style="flex:83"></span><span class="s-warn" style="flex:17"></span></div></div>`;

function hi(t){if(!q)return esc(t);const i=t.toLowerCase().indexOf(q);if(i<0)return esc(t);return esc(t.slice(0,i))+'<mark>'+esc(t.slice(i,i+q.length))+'</mark>'+esc(t.slice(i+q.length));}

// ---------- SCREENS (matches the old dashboard) ----------
function screenDetail(r){
  const li=x=>x.map(i=>`<li>${hi(i)}</li>`).join('');
  let h='<dl>';
  h+=`<dt>Where</dt><dd class="meta">${r.page?esc(r.page):'<em>no page</em>'}${r.route?' · '+esc(r.route):''} · props: ${esc(r.props)} · backend: ${esc(r.backend)}${r.owner?' · owner '+esc(r.owner):''}</dd>`;
  if(r.propsMissing.length)h+=`<dt>Props missing</dt><dd><ul>${li(r.propsMissing)}</ul></dd>`;
  if(r.backendMissing.length)h+=`<dt>Backend missing</dt><dd><ul>${li(r.backendMissing)}</ul></dd>`;
  if(r.specHas.length)h+=`<dt>Spec has · app lacks</dt><dd><ul>${li(r.specHas)}</ul></dd>`;
  if(r.appAhead.length)h+=`<dt>App has · spec lacks (reconcile, don't strip)</dt><dd><ul>${li(r.appAhead)}</ul></dd>`;
  if(r.notes)h+=`<dt>Notes</dt><dd>${hi(r.notes)}</dd>`;
  if(!r.propsMissing.length&&!r.backendMissing.length&&!r.specHas.length&&!r.notes)h+=`<dd class="ok">Conformant — nothing outstanding.</dd>`;
  return h+'</dl>';
}
function screenMatch(r){if(filter!=='all'&&r.bucket!==filter)return false;if(!q)return true;
  return (r.file+' '+r.title+' '+r.notes+' '+r.specHas.join(' ')+' '+r.appAhead.join(' ')+' '+r.propsMissing.join(' ')+' '+r.backendMissing.join(' ')).toLowerCase().includes(q);}

// ---------- generic grouped renderer ----------
function groupView(items,areaKey,barKeys,matchFn,rowHTML){
  const order=[...new Set(items.map(i=>i[areaKey]))];
  const byA={};order.forEach(a=>byA[a]=items.filter(i=>i[areaKey]===a));
  const rank=a=>{const g=byA[a];return g.filter(matchFn).length?g.filter(x=>x.bucket==='built'||x.maturity==='working').length/g.length:-1;};
  order.sort((a,b)=>rank(b)-rank(a));
  let html='';
  order.forEach(a=>{const g=byA[a],vis=g.filter(matchFn);if(!vis.length)return;
    const c={};barKeys.forEach(k=>c[k]=0);g.forEach(r=>{const v=r.bucket||r.maturity;if(v in c)c[v]++;});
    const bar=barKeys.map(k=>c[k]?`<span class="s-${CO[k]}" style="flex:${c[k]}"></span>`:'').join('');
    const cnt=barKeys.map(k=>c[k]).join(' / ');
    html+=`<section class="area"><button class="area-head" aria-expanded="false"><span class="area-name">${esc(a)}</span><span class="bar">${bar}</span><span class="counts">${cnt}</span><span class="chev">›</span></button><div class="rows hidden">${vis.map(rowHTML).join('')}</div></section>`;
  });
  return html;
}

function render(){
  const b=document.getElementById('body');
  if(view==='screens'){
    b.innerHTML=groupView(D.screens,'area',['built','partial','absent'],screenMatch,r=>
      `<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.bucket]}"></span><span><span class="scr-title">${hi(r.title)}</span><span class="scr-file">${esc(r.file)}</span></span><span class="pill p-${r.bucket}">${LB[r.bucket]}</span><span class="eff">${r.effort==='none'?'—':r.effort}</span></button><div class="detail hidden">${screenDetail(r)}</div></div>`);
  } else if(view==='caps'){
    const m=r=>{if(filter!=='all'&&r.maturity!==filter)return false;if(!q)return true;return (r.capability+' '+r.scaleNote+' '+r.blocker).toLowerCase().includes(q);};
    b.innerHTML=groupView(D.caps,'area',['working','partial','blocked','absent'],m,r=>{
      let d='<dl>';if(r.blocker)d+=`<dt class="blk">⛔ Blocker</dt><dd>${hi(r.blocker)}</dd>`;if(r.scaleNote)d+=`<dt>At scale</dt><dd>${hi(r.scaleNote)}</dd>`;if(!r.blocker&&!r.scaleNote)d+='<dd class="ok">Working.</dd>';d+='</dl>';
      return `<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.maturity]}"></span><span class="scr-title">${hi(r.capability)}</span><span class="pill p-${r.maturity}">${LB[r.maturity]}</span><span class="eff"></span></button><div class="detail hidden">${d}</div></div>`;});
  } else {
    const m=r=>{if(filter!=='all'&&r.severity!==filter)return false;if(!q)return true;return (r.title+' '+r.owner+' '+r.location+' '+r.status+' '+r.note).toLowerCase().includes(q);};
    const vis=D.debt.filter(m);
    b.innerHTML=`<section class="area"><div class="rows">${vis.map(r=>
      `<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.severity]}"></span><span class="scr-title">${hi(r.title)}</span><span class="pill p-${r.severity}">${LB[r.severity]}</span><span class="eff">${esc(r.category)}</span></button><div class="detail hidden"><dl><dt>Owner</dt><dd>${hi(r.owner)}</dd><dt>Where</dt><dd class="meta">${hi(r.location)}</dd><dt>Status</dt><dd>${hi(r.status)}</dd>${r.note?`<dt>Note</dt><dd>${hi(r.note)}</dd>`:''}</dl></div></div>`).join('')||'<div class="detail">No matches.</div>'}</div></section>`;
  }
  b.querySelectorAll('.area-head').forEach(h=>{const list=h.nextElementSibling;h.addEventListener('click',()=>{const hid=list.classList.toggle('hidden');h.setAttribute('aria-expanded',String(!hid));});});
  b.querySelectorAll('.scr-head').forEach(h=>{const dt=h.nextElementSibling;h.addEventListener('click',()=>{const hid=dt.classList.toggle('hidden');h.setAttribute('aria-expanded',String(!hid));});});
  if(q||filter!=='all')b.querySelectorAll('.rows').forEach(x=>x.classList.remove('hidden'));
}
function buildFilters(){document.getElementById('filters').innerHTML=FILTERS[view].map(f=>`<button class="chip" data-f="${f}" aria-pressed="${f===filter}">${f[0].toUpperCase()+f.slice(1)}</button>`).join('');
  document.querySelectorAll('.chip[data-f]').forEach(ch=>ch.addEventListener('click',()=>{filter=ch.dataset.f;document.querySelectorAll('.chip[data-f]').forEach(x=>x.setAttribute('aria-pressed',String(x===ch)));render();}));}
document.querySelectorAll('.view-btn').forEach(vb=>vb.addEventListener('click',()=>{view=vb.dataset.v;filter='all';document.querySelectorAll('.view-btn').forEach(x=>x.setAttribute('aria-selected',String(x===vb)));buildFilters();render();}));
document.getElementById('q').addEventListener('input',e=>{q=e.target.value.trim().toLowerCase();render();});
document.getElementById('exAll').addEventListener('click',()=>{document.querySelectorAll('#body .rows,#body .detail').forEach(x=>x.classList.remove('hidden'));document.querySelectorAll('#body .area-head,#body .scr-head').forEach(x=>x.setAttribute('aria-expanded','true'));});
document.getElementById('coAll').addEventListener('click',()=>{document.querySelectorAll('#body .rows,#body .detail').forEach(x=>x.classList.add('hidden'));document.querySelectorAll('#body .area-head,#body .scr-head').forEach(x=>x.setAttribute('aria-expanded','false'));});
buildFilters();render();
</script>
"""

stamp = "As of %s · main @ <code>%s</code> · Wave 3 build closed · Wave 4 pending" % (DATA['asOf'], DATA['head'])
html = TEMPLATE.replace('%%DATA%%', json.dumps(DATA, separators=(',', ':'))).replace('%%STAMP%%', stamp)
out = r'E:\fair-constitution-app\docs\plans\ui\tools\app_progress_rubric.html'
with io.open(out, 'w', encoding='utf-8') as f:
    f.write(html)
print('wrote', out, len(html), 'bytes ·', len(screens), 'screens', len(caps), 'caps', len(debt), 'debt')
