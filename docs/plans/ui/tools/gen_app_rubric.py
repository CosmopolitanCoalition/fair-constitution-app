# -*- coding: utf-8 -*-
"""Rubric v3 — five views: UI Screens, Capabilities, Tech Debt, Fleet & Waves, Open Questions.
Native-feeling drill (search / filter / expand-all) with per-item punch detail, per the operator's
beloved v3_gap_dashboard, extended to carry the whole plan to a tested playable game."""
import json, io, sys, os
_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _HERE)
from wave4_data import FLEET
# Structured, fillable open-questions (options + owning lane). Resolved ones are read-only.
QUESTIONS = [
 {"id":"edu-arming","q":"Education arming sequencing — how do untrained demo members behave when the gate arms?","status":"open","lane":"15",
  "detail":"education:seed arms the act-gate for 6 civic tracks; every untrained role-holder then redirects on their next role-act. Gates your browser walk of the training gate.",
  "options":[{"k":"A","t":"Pre-train demo members (seeders file F-EDU-001) — the walk shows a trained fleet. [lane 15 rec]"},
             {"k":"B","t":"Seed and leave demo members untrained — the walk DEMOS the redirect→train→act loop live."},
             {"k":"C","t":"Don't seed this wave — the gate is proven by the e2e only, no live walk."}]},
 {"id":"mass-pass","q":"Game-box mass pass — run the Type B mapper over the real ~9,708 flagged chambers?","status":"open","lane":"1",
  "detail":"The ~9,708 flagged chambers live on the GAME box, not dev. Waits on the Type B race fix so cleared chambers get the correct race.",
  "options":[{"k":"A","t":"After the race fix, pull lane 1's commits to the game box and run the mass pass now (ETL-chunked)."},
             {"k":"B","t":"Defer to the Wave-4 cloud rehearsal."}]},
 {"id":"lane3-compact","q":"Lane 3 compaction — run the keystone exit walk with fresh context?","status":"open","lane":"3",
  "detail":"The Live Civic Room is built but not yet WALKED end-to-end (the acceptance gate). The exit walk needs lane 3 compacted.",
  "options":[{"k":"A","t":"Compact lane 3 now — it resumes straight into seating a committee + the exit walk."},
             {"k":"B","t":"Hold lane 3 for now."}]},
 {"id":"ranked-live","q":"RankedBallot live standings — spin the secrecy-critical build?","status":"open","lane":"3",
  "detail":"Live provisional standings during an OPEN ranked ballot, without an in-request decrypt. Cold-start spec ready; cadence ruled daily-batch.",
  "options":[{"k":"A","t":"Trigger the fresh-session build now."},
             {"k":"B","t":"Defer — the electoral partial stays as-is."}]},
 {"id":"secondary-trade","q":"Secondary share trading — pull into Wave 4 or leave deferred?","status":"open","lane":"13",
  "detail":"You ruled share ISSUANCE (delivered). A holder RESELLING issued shares needs its own schema.",
  "options":[{"k":"A","t":"Pull into Wave 4 — lane 13 builds share resale on the exchange."},
             {"k":"B","t":"Leave deferred — the exchange shares floor stays honest-empty."}]},
 {"id":"handshake-4xx","q":"Cross-class federation handshake — return a graceful 4xx instead of 500?","status":"open","lane":"2",
  "detail":"A genuine cross-class handshake surfaces the class-rule refusal as an uncaught 500 rather than a 409/422. Pre-existing.",
  "options":[{"k":"A","t":"Fix in Wave 4 — catch it, return 409/422 gracefully."},
             {"k":"B","t":"Leave as-is (pre-existing, low priority)."}]},
 {"id":"b2-pairing","q":"B2 remainder rule — compact-first vs strictly-lowest-population pairing?","status":"open","lane":"1",
  "detail":"On real adjacency, compactness drives which children pair (population only orients the walk head). Lane 1 shipped compact-first.",
  "options":[{"k":"A","t":"Keep compact-first (shipped, matches intent)."},
             {"k":"B","t":"Force strictly-lowest-population pairing even when less compact."}]},
 {"id":"oversight-live","q":"Oversight — does 'public to watch' extend to the LIVE console of in-progress proceedings against NAMED members?","status":"open","lane":"3",
  "detail":"§10-1 makes government proceedings public. Open: the LIVE console of an in-progress removal/discipline against a named member, or only the sealed public record after?",
  "options":[{"k":"A","t":"Keep the live console gated; the public RECORD stays public. [desk rec]"},
             {"k":"B","t":"Make the live console public too (fully open in-progress)."}]},
 {"id":"orphans","q":"Orphan-surface deletions — remove unreferenced surfaces?","status":"open","lane":"6",
  "detail":"e.g. Elections/CandidateProfile.vue (unreferenced) + a couple of orphan surface records.",
  "options":[{"k":"A","t":"Delete the orphan surfaces."},
             {"k":"B","t":"Keep them for now."}]},
 {"id":"q4a-rooms","q":"Q4a — provisioning can't materialise court tiers / extra civic rooms (the schema forbids it). How should the scaling model resolve this?","status":"open","lane":"4",
  "detail":"One live court per jurisdiction (hierarchy is expressed ACROSS THE TREE via parent_judiciary_id); one public space per type. courtTiers/extraRooms have no lawful shape as extra rows at one place. The min_judges-from-tier fix already wires the meaningful bench scaling. Your framing: the court JURISDICTION stays singular; the scalable thing is rooms/chambers within the infrastructure.",
  "options":[{"k":"A","t":"Reframe courtTiers as a jurisdiction's tree-DEPTH; extra rooms = group-type or a future room model. Doc amendment, no schema, nothing built moves. [desk rec]"},
             {"k":"B","t":"Weaken the two uniqueness constraints (allow duplicate courts/squares). Needs a migration; trades two real safety rails."},
             {"k":"C","t":"Defer past this wave — the min_judges-from-tier fix already advances the scaling capability; build the room model later."}]},
 {"id":"advocate-gate","q":"Should advocates have a qualification catalog + an approval lifecycle, or stay an instant competence register?","status":"open","lane":"6",
  "detail":"The advocate-registration mockup wanted 'I attest to X law' checkboxes + a 'pending judiciary review' banner. F-IND-015 registers instantly (rejecting only on association + duplicate) — the bar is a competence REGISTER, not a merits gate on a client's Art. I right. A catalog + pending→approved lifecycle would be a RULE change + an advocates.status CHECK migration. Held honest-empty, flagged not smuggled.",
  "options":[{"k":"A","t":"Keep it a competence register — instant, no merits gate. [held honest-empty; desk rec]"},
             {"k":"B","t":"Add a qualification catalog + an approval lifecycle (rule change + schema)."}]},
 # --- resolved (read-only, recorded) ---
 {"id":"typeb-shape","q":"Type B race shape — pooled vs per-child/per-clump?","status":"resolved","lane":"1",
  "detail":"RULED per-child/per-clump (each child, or clump, is its own at-large race). CLAUDE.md corrected @55b8846. Build = Wave 4 (lanes 1+3)."},
 {"id":"video","q":"Video library / multi-track player — build from scratch?","status":"resolved","lane":"5",
  "detail":"NO from-scratch build — the operator's player already exists; the mockups are based on it. Wave 4 = integrate it (ref fleet-11 + coalition site)."},
 {"id":"founding-stake","q":"Founding-stake-on-registration — auto-equity when an org is founded?","status":"resolved","lane":"13",
  "detail":"DEFERRED to Wave 4, structure-aware (100% stake wrong for member-owned/nonprofit; only stock has shares)."},
 {"id":"setup-order","q":"Setup order — account-first (mockup) or fork-first (ruling)?","status":"resolved","lane":"2",
  "detail":"RULED FORK-FIRST: join-or-start, THEN account. Mockup swapped; SetupController already fork-first."},
 {"id":"oversight-public","q":"Oversight console — public or gated?","status":"resolved","lane":"3",
  "detail":"RULED PUBLIC ('public if it's government'; no closed-session provision). Console read public; write controls authenticated. @4057b3c."},
]
# Operator answers (2026-07-29) — flip the 9 open to RESOLVED with the ruling folded in.
_ANS = {
 'edu-arming':('A','Pre-train demo members (seeders file F-EDU-001) — the walk shows a trained fleet.',''),
 'mass-pass':('A','After the race fix, pull lane 1\'s commits to the game box and run the ~9,708 mass pass now (ETL-chunked).',''),
 'lane3-compact':('A','Compact lane 3 now — it resumes into seating a committee + the exit walk.','Operator will NOT manually walk anything until we are all GREEN and ready.'),
 'ranked-live':('A','Build the secrecy-safe live aggregate, DAILY-BATCHED (results are invisible-until-count today, so daily provisional standings — no in-request decrypt).',''),
 'secondary-trade':('A','Pull into Wave 4 — lane 13 builds share resale on the exchange (needs schema).',''),
 'handshake-4xx':('A','Fix in Wave 4 — catch the cross-class refusal, return 409/422 gracefully.',''),
 'b2-pairing':('A','Keep compact-first (Type B clumping; matches intent).',''),
 'oversight-live':('B','GOVERNMENT IS PUBLIC BY DEFAULT — the live console of in-progress proceedings too. Organizations decide their own visibility. ⚑ SETTLED LAW, never re-ask.','"I dont know how many times I need to reanswer this question."'),
 'orphans':('A','Delete the orphan surfaces (CandidateProfile.vue etc.). ⚑ SETTLED, never re-ask.','"I already answered this many times as well."'),
}
for _q in QUESTIONS:
    a = _ANS.get(_q['id'])
    if a:
        k, txt, note = a
        _q['status'] = 'resolved'
        _q['detail'] = 'RULED = %s. %s%s · %s' % (k, txt, (' [operator: '+note+']') if note else '', _q['detail'])
        _q.pop('options', None)

# Screens / caps / debt all come from the enriched, badged corpus in this dir (repo-stable).
_enr = json.load(open(os.path.join(_HERE, 'badged.json'), encoding='utf-8'))
screens = _enr['screens']; caps = _enr['caps']; debt = _enr['debt']
DATA = {'asOf': '2026-07-29', 'head': '2ad8b1b', 'forms': 118,
        'screens': screens, 'caps': caps, 'debt': debt, 'fleet': FLEET, 'questions': QUESTIONS}

TEMPLATE = r"""<title>App Progress Rubric — CGA</title>
<style>
:root{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);--mono:"Cascadia Code",Consolas,ui-monospace,monospace;--sans:"Segoe UI Variable Text","Segoe UI",system-ui,sans-serif;}
@media (prefers-color-scheme:dark){:root{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}}
:root[data-theme="dark"]{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}
:root[data-theme="light"]{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);}
*{box-sizing:border-box}body{background:var(--bg);color:var(--ink);font-family:var(--sans);margin:0;line-height:1.5}
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
.d-good{background:var(--good)}.d-warn{background:var(--warn)}.d-bad{background:var(--bad)}.d-block{background:var(--block)}.d-low{background:var(--faint)}
.s-good{background:var(--good)}.s-warn{background:var(--warn)}.s-bad{background:var(--bad)}.s-block{background:var(--block)}
.note{background:var(--surface);border:1px solid var(--line);border-inline-start:4px solid var(--bad);border-radius:9px;padding:.75rem .9rem;font-size:.85rem;margin:.2rem 0 1.4rem}.note b{color:var(--ink)}
.views{display:flex;gap:.35rem;border-bottom:2px solid var(--line);margin:0 0 1rem;flex-wrap:wrap}
.view-btn{padding:.5rem .8rem;font:inherit;font-size:.9rem;font-weight:600;color:var(--muted);background:none;border:0;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer}
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
.area-head{display:grid;grid-template-columns:16rem 1fr 9rem 1.2rem;gap:.9rem;align-items:center;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.7rem .95rem;cursor:pointer}
.area-head:hover{background:var(--accent-soft)}.area-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.area-name{font-weight:600;font-size:.93rem}
.bar{display:flex;block-size:.8rem;border-radius:5px;overflow:hidden;background:var(--line)}.bar span{block-size:100%}
.counts{font-family:var(--mono);font-size:.75rem;color:var(--muted);text-align:end;white-space:nowrap}
.chev{color:var(--faint);transition:transform .15s}.area-head[aria-expanded=true] .chev{transform:rotate(90deg)}
.rows{border-top:1px solid var(--line)}
.scr{border-top:1px solid var(--line)}.scr:first-child{border-top:0}
.scr-head{display:grid;grid-template-columns:auto 1fr auto auto auto;gap:.6rem;align-items:baseline;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.55rem .95rem .55rem 1.2rem;cursor:pointer}
.scr-head:hover{background:var(--accent-soft)}.scr-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.scr-title{font-size:.88rem}.scr-file{font-family:var(--mono);font-size:.75rem;color:var(--faint);display:block;margin-top:.1rem}
.pill{font-size:.68rem;font-weight:600;letter-spacing:.03em;border-radius:999px;padding:.14em .6em;white-space:nowrap}
.p-built,.p-working,.p-done,.p-resolved{background:var(--good-s);color:var(--good)}
.p-partial,.p-next,.p-medium,.p-active{background:var(--warn-s);color:var(--warn)}
.p-absent,.p-high,.p-open{background:var(--bad-s);color:var(--bad)}
.p-blocked,.p-held{background:var(--block-s);color:var(--block)}
.p-low,.p-deferred{background:var(--accent-soft);color:var(--muted)}
.eff{font-family:var(--mono);font-size:.72rem;color:var(--faint);white-space:nowrap}
.lwbadge{font-family:var(--mono);font-size:.66rem;font-weight:700;background:var(--accent-soft);color:var(--accent);padding:.14em .45em;border-radius:4px;white-space:nowrap;letter-spacing:.02em}
.qbar{display:flex;gap:.8rem;align-items:center;margin:0 0 1rem;flex-wrap:wrap}
.qhint{font-size:.78rem;color:var(--faint);flex:1;min-width:14rem}
#qexport{font-weight:700;color:var(--accent);border-color:var(--accent)}
.qexport{background:var(--surface);border:1px solid var(--accent);border-radius:8px;padding:.7rem .9rem;font-family:var(--mono);font-size:.76rem;white-space:pre-wrap;color:var(--ink);margin:0 0 1rem;user-select:all}
.qcard{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:.9rem 1rem;margin:0 0 .7rem}
.qcard.resolved{opacity:.65}
.qhead{display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap}
.qtext{font-weight:600;font-size:.95rem;flex:1;min-width:12rem}
.qdetail{font-size:.82rem;color:var(--muted);margin:.4rem 0 .7rem}
.qopts{display:flex;flex-direction:column;gap:.4rem;margin:0 0 .7rem}
.qopt{display:flex;gap:.55rem;align-items:flex-start;padding:.5rem .7rem;border:1px solid var(--line);border-radius:8px;cursor:pointer;font-size:.86rem}
.qopt:hover{background:var(--accent-soft)}
.qopt.on{border-color:var(--accent);background:var(--accent-soft);box-shadow:inset 0 0 0 1px var(--accent)}
.qopt input{margin-top:.15rem;accent-color:var(--accent)}
.qk{font-family:var(--mono);font-weight:700;color:var(--accent);flex:none}
.qnotes{width:100%;min-height:2.4rem;background:var(--bg);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.85rem;padding:.45rem .6rem;resize:vertical}
.qnotes:focus{outline:2px solid var(--accent);outline-offset:1px}
.wavesline{font-size:.82rem;color:var(--muted);margin:0 0 1rem;background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:.6rem .9rem}
.lanecard{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:.9rem 1rem;margin:0 0 .7rem}
.lanehd{display:flex;align-items:center;gap:.6rem;margin:0 0 .5rem;flex-wrap:wrap}
.lanenm{font-weight:650;font-size:.98rem}
.laneorder{font-size:.87rem;line-height:1.55}
.lanehist{margin-top:.6rem;font-size:.8rem}
.lanehist summary{color:var(--faint);cursor:pointer;user-select:none}
.donerow{color:var(--muted);font-size:.8rem;margin:.4rem 0;padding-inline-start:.6rem;border-inline-start:2px solid var(--line)}
.wv{font-family:var(--mono);font-size:.72rem;font-weight:700;color:var(--accent);white-space:nowrap}
.detail{padding:.35rem 1.2rem 1rem 2.15rem;font-size:.85rem;border-top:1px dashed var(--line)}
.detail dl{margin:0}.detail dt{font-size:.7rem;letter-spacing:.09em;text-transform:uppercase;color:var(--faint);margin:.7rem 0 .2rem}
.detail dt.blk{color:var(--bad)}.detail dd{margin:0}.detail ul{margin:.1rem 0 0;padding-inline-start:1.1rem}.detail li{margin:.15rem 0}
.detail p{margin:.1rem 0 0}.detail .meta{font-family:var(--mono);font-size:.76rem;color:var(--muted)}
.ok{color:var(--good)}.hidden{display:none}mark{background:var(--warn-s);color:inherit;border-radius:3px}
.foot{font-size:.76rem;color:var(--faint);margin-top:2rem;border-top:1px solid var(--line);padding-top:.8rem}
@media (max-width:46rem){.area-head{grid-template-columns:1fr 6rem 1rem;grid-template-rows:auto auto}.area-head .bar{grid-column:1/-1;grid-row:2}.scr-head{grid-template-columns:auto 1fr auto}}
</style>
<div class="wrap">
<p class="eyebrow">Fair Constitution App · App Progress Rubric</p>
<h1>Where does the app stand — and what's the road to a playable game?</h1>
<p class="stamp">%%STAMP%% · verified against live code · click a group, then a row, for the detail · search + filter + expand-all below</p>
<div class="tiles" id="tiles"></div>
<div class="note"><b>⚠ Wave 4's headline & the one thing blocking governance at scale:</b> the <b>Type B second-chamber race</b>. The built engine runs one pooled at-large race, but the ruled model is one at-large race <b>per child</b> (or per clump). A large jurisdiction cannot elect its second chamber, cascading to block every bicameral act there. Seat math is correct; only the vote grouping must change (lanes 1+3, Wave 4). See Fleet &amp; Waves for the standing orders and Open Questions for the decision queue.</div>
<div class="views" role="tablist">
  <button class="view-btn" role="tab" data-v="screens" aria-selected="true">UI Screens</button>
  <button class="view-btn" role="tab" data-v="caps" aria-selected="false">Capabilities</button>
  <button class="view-btn" role="tab" data-v="debt" aria-selected="false">Tech Debt</button>
  <button class="view-btn" role="tab" data-v="fleet" aria-selected="false">Fleet &amp; Waves</button>
  <button class="view-btn" role="tab" data-v="questions" aria-selected="false">Open Questions</button>
</div>
<div class="controls">
  <input type="search" id="q" placeholder="Search…" aria-label="Search">
  <span id="filters"></span>
  <span class="expanders"><button class="chip" id="exAll">Expand all</button><button class="chip" id="coAll">Collapse all</button></span>
</div>
<div id="body"></div>
<p class="foot">Generated from <code>v3_gap_data.json</code> + the 8-agent verification workflow + the Wave-4 standing orders. UI screens vs the 107 <code>mockups/v3</code> screens; capabilities, debt, fleet &amp; questions from the live-code sweep and the desk plan.</p>
</div>
<script>
const D=%%DATA%%;
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const CO={built:'good',working:'good',partial:'warn',absent:'bad',blocked:'block',high:'bad',medium:'warn',low:'low',done:'good',next:'warn',held:'block',resolved:'good',open:'bad',active:'warn',deferred:'low'};
const LB={built:'built',working:'working',partial:'partial',absent:'absent',blocked:'blocked',high:'high',medium:'medium',low:'low',done:'done',next:'next',held:'held',resolved:'resolved',open:'open',active:'active',deferred:'deferred'};
let view='screens',q='',filter='all';
let ANS={};try{for(let i=0;i<localStorage.length;i++){const k=localStorage.key(i);if(k&&k.indexOf('cga4qs_')===0){const id=k.slice(7);ANS[id]=ANS[id]||{};ANS[id].sel=localStorage.getItem(k);}if(k&&k.indexOf('cga4qn_')===0){const id=k.slice(7);ANS[id]=ANS[id]||{};ANS[id].notes=localStorage.getItem(k);}}}catch(e){}
function saveAns(id,f,v){ANS[id]=ANS[id]||{};ANS[id][f]=v;try{localStorage.setItem('cga4q'+(f==='sel'?'s':'n')+'_'+id,v);}catch(e){}}
const FILTERS={screens:['all','built','partial','absent'],caps:['all','working','partial','blocked','absent'],debt:['all','high','medium','low'],fleet:['all','done','active','held','deferred'],questions:['all','open','resolved']};
const sc=t=>D.screens.filter(r=>r.bucket===t).length,cc=t=>D.caps.filter(r=>r.maturity===t).length,dc=t=>D.debt.filter(r=>r.severity===t).length;
const qOpen=D.questions.filter(x=>x.status==='open').length,qRes=D.questions.filter(x=>x.status==='resolved').length;
function meter(parts){return parts.map(p=>p[0]?`<span class="s-${p[1]}" style="flex:${p[0]}"></span>`:'').join('');}
document.getElementById('tiles').innerHTML=`
 <div class="tile"><p class="lbl">UI Screens</p><div class="num">${sc('built')} / ${D.screens.length}</div><div class="sub">${sc('partial')} partial · ${sc('absent')} absent</div><div class="meter">${meter([[sc('built'),'good'],[sc('partial'),'warn'],[sc('absent'),'bad']])}</div></div>
 <div class="tile"><p class="lbl">Capabilities</p><div class="num">${cc('working')} / ${D.caps.length}</div><div class="sub">${cc('partial')} part · ${cc('blocked')} blocked · ${cc('absent')} absent</div><div class="meter">${meter([[cc('working'),'good'],[cc('partial'),'warn'],[cc('blocked'),'block'],[cc('absent'),'bad']])}</div></div>
 <div class="tile"><p class="lbl">Technical Debt</p><div class="num">${D.debt.length} items</div><div class="sub">${dc('high')} high · ${dc('medium')} med · ${dc('low')} low</div><div class="meter">${meter([[dc('high'),'bad'],[dc('medium'),'warn'],[dc('low'),'good']])}</div></div>
 <div class="tile"><p class="lbl">Open Questions</p><div class="num">${qOpen} open</div><div class="sub">${qRes} resolved · Wave 4 green</div><div class="meter">${meter([[qRes,'good'],[qOpen,'bad']])}</div></div>`;
function hi(t){if(!q)return esc(t);const i=String(t).toLowerCase().indexOf(q);if(i<0)return esc(t);const s=String(t);return esc(s.slice(0,i))+'<mark>'+esc(s.slice(i,i+q.length))+'</mark>'+esc(s.slice(i+q.length));}
function screenDetail(r){const li=x=>x.map(i=>`<li>${hi(i)}</li>`).join('');let h='<dl>';
  h+=`<dt>Where</dt><dd class="meta">${r.page?esc(r.page):'<em>no page</em>'}${r.route?' · '+esc(r.route):''} · props: ${esc(r.props)} · backend: ${esc(r.backend)}${r.owner?' · owner '+esc(r.owner):''}</dd>`;
  if(r.propsMissing.length)h+=`<dt>Props missing</dt><dd><ul>${li(r.propsMissing)}</ul></dd>`;
  if(r.backendMissing.length)h+=`<dt>Backend missing</dt><dd><ul>${li(r.backendMissing)}</ul></dd>`;
  if(r.specHas.length)h+=`<dt>Spec has · app lacks</dt><dd><ul>${li(r.specHas)}</ul></dd>`;
  if(r.appAhead.length)h+=`<dt>App has · spec lacks (reconcile, don't strip)</dt><dd><ul>${li(r.appAhead)}</ul></dd>`;
  if(r.notes)h+=`<dt>Notes</dt><dd>${hi(r.notes)}</dd>`;
  if(!r.propsMissing.length&&!r.backendMissing.length&&!r.specHas.length&&!r.notes)h+=`<dd class="ok">Conformant — nothing outstanding.</dd>`;return h+'</dl>';}
function groupView(items,areaKey,barKeys,matchFn,rowHTML,valKey){
  const order=[...new Set(items.map(i=>i[areaKey]))];const byA={};order.forEach(a=>byA[a]=items.filter(i=>i[areaKey]===a));
  const rank=a=>{const g=byA[a];const good=g.filter(x=>['built','working','done'].includes(x[valKey])).length;return g.filter(matchFn).length?good/g.length:-1;};
  order.sort((a,b)=>rank(b)-rank(a));let html='';
  order.forEach(a=>{const g=byA[a],vis=g.filter(matchFn);if(!vis.length)return;const c={};barKeys.forEach(k=>c[k]=0);g.forEach(r=>{const v=r[valKey];if(v in c)c[v]++;});
    const bar=barKeys.map(k=>c[k]?`<span class="s-${CO[k]}" style="flex:${c[k]}"></span>`:'').join('');const cnt=barKeys.map(k=>c[k]).join(' / ');
    html+=`<section class="area"><button class="area-head" aria-expanded="false"><span class="area-name">${esc(a)}</span><span class="bar">${bar}</span><span class="counts">${cnt}</span><span class="chev">›</span></button><div class="rows hidden">${vis.map(rowHTML).join('')}</div></section>`;});
  return html;}
function render(){const b=document.getElementById('body');
  if(view==='screens'){b.innerHTML=groupView(D.screens,'area',['built','partial','absent'],r=>(filter==='all'||r.bucket===filter)&&(!q||(r.badge+' '+r.file+' '+r.title+' '+r.notes+' '+r.specHas.join(' ')+' '+r.appAhead.join(' ')+' '+r.propsMissing.join(' ')+' '+r.backendMissing.join(' ')).toLowerCase().includes(q)),r=>`<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.bucket]}"></span><span><span class="scr-title">${hi(r.title)}</span><span class="scr-file">${esc(r.file)}</span></span><span class="lwbadge">${esc(r.badge)}</span><span class="pill p-${r.bucket}">${LB[r.bucket]}</span><span class="eff">${r.effort==='none'?'—':r.effort}</span></button><div class="detail hidden">${screenDetail(r)}</div></div>`,'bucket');}
  else if(view==='caps'){b.innerHTML=groupView(D.caps,'area',['working','partial','blocked','absent'],r=>(filter==='all'||r.maturity===filter)&&(!q||(r.badge+' '+r.capability+' '+r.scaleNote+' '+r.blocker).toLowerCase().includes(q)),r=>{let d='<dl>';if(r.blocker)d+=`<dt class="blk">⛔ Blocker</dt><dd>${hi(r.blocker)}</dd>`;if(r.scaleNote)d+=`<dt>At scale</dt><dd>${hi(r.scaleNote)}</dd>`;if(!r.blocker&&!r.scaleNote)d+='<dd class="ok">Working.</dd>';d+='</dl>';return `<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.maturity]}"></span><span class="scr-title">${hi(r.capability)}</span><span class="lwbadge">${esc(r.badge)}</span><span class="pill p-${r.maturity}">${LB[r.maturity]}</span><span class="eff"></span></button><div class="detail hidden">${d}</div></div>`;},'maturity');}
  else if(view==='debt'){const vis=D.debt.filter(r=>(filter==='all'||r.severity===filter)&&(!q||(r.badge+' '+r.title+' '+r.owner+' '+r.location+' '+r.status+' '+r.note).toLowerCase().includes(q)));
    b.innerHTML=`<section class="area"><div class="rows">${vis.map(r=>`<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.severity]}"></span><span class="scr-title">${hi(r.title)}</span><span class="lwbadge">${esc(r.badge)}</span><span class="pill p-${r.severity}">${LB[r.severity]}</span><span class="eff">${esc(r.category)}</span></button><div class="detail hidden"><dl><dt>Owner</dt><dd>${hi(r.owner)}</dd><dt>Where</dt><dd class="meta">${hi(r.location)}</dd><dt>Status</dt><dd>${hi(r.status)}</dd>${r.note?`<dt>Note</dt><dd>${hi(r.note)}</dd>`:''}</dl></div></div>`).join('')||'<div class="detail">No matches.</div>'}</div></section>`;}
  else if(view==='fleet'){
    const waves=D.fleet.waves.map(w=>`<span class="wv">${w.id}</span> ${esc(w.name)} <span class="pill p-${w.status}">${LB[w.status]}</span>`).join(' &nbsp;·&nbsp; ');
    let html=`<div class="note" style="border-inline-start-color:var(--good)">✅ <b>Wave 4 is GREEN.</b> The authoritative full suite passed <b>1343 passed · 0 failed · 3 skipped</b> in a quiet window. Each lane's Wave-4 responsibilities are broken out below with per-item status — expand a lane, then an item, like the Screens and Capabilities tabs.</div><div class="wavesline"><b>Waves:</b> ${waves}</div>`;
    const bk=['done','active','held','deferred'];
    D.fleet.lanes.forEach(l=>{const items=l.items||[];
      const vis=items.filter(it=>(filter==='all'||it.status===filter)&&(!q||('l'+l.id+' '+l.name+' '+it.label+' '+(it.note||'')).toLowerCase().includes(q)));
      if(!vis.length)return;
      const c={done:0,active:0,held:0,deferred:0};items.forEach(it=>{if(it.status in c)c[it.status]++;});
      const bar=bk.map(k=>c[k]?`<span class="s-${CO[k]}" style="flex:${c[k]}"></span>`:'').join('');
      const cnt=bk.filter(k=>c[k]).map(k=>c[k]+' '+k).join(' · ')||'—';
      html+=`<section class="area"><button class="area-head" aria-expanded="false"><span class="area-name"><span class="lwbadge">L${esc(l.id)}·W4</span> Lane ${esc(l.id)} · ${esc(l.name)} <span class="pill p-${l.status}">${LB[l.status]||esc(l.status)}</span></span><span class="bar">${bar}</span><span class="counts">${esc(cnt)}</span><span class="chev">›</span></button><div class="rows hidden">`;
      vis.forEach(it=>{html+=`<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[it.status]}"></span><span class="scr-title">${hi(it.label)}</span><span class="pill p-${it.status}">${LB[it.status]||esc(it.status)}</span></button><div class="detail hidden"><dl>${it.note?`<dt>Detail</dt><dd>${hi(it.note)}</dd>`:'<dd class="ok">—</dd>'}</dl></div></div>`;});
      html+=`</div></section>`;
    });
    b.innerHTML=html;
  }
  else if(view==='questions'){
    const vis=D.questions.filter(r=>(filter==='all'||r.status===filter)&&(!q||(r.q+' '+r.detail).toLowerCase().includes(q)));
    let html='<div class="qbar"><button class="chip" id="qexport">⭳ Export answers</button><span class="qhint">Pick an option and add notes on each open question — your answers save in the page. When done, click Export (copies to clipboard) or screenshot; either lets the desk read them and update the fleet orders.</span></div><pre id="qexport-out" class="qexport hidden"></pre>';
    vis.forEach(r=>{
      if(r.status==='resolved'){html+=`<div class="qcard resolved"><div class="qhead"><span class="lwbadge">L${esc(r.lane)}</span><span class="qtext">${hi(r.q)}</span><span class="pill p-resolved">resolved</span></div><div class="qdetail">${hi(r.detail)}</div></div>`;}
      else{const a=ANS[r.id]||{};const sel=a.sel||'';const nt=a.notes||'';
        html+=`<div class="qcard open"><div class="qhead"><span class="lwbadge">L${esc(r.lane)}</span><span class="qtext">${hi(r.q)}</span><span class="pill p-open">open</span></div><div class="qdetail">${hi(r.detail)}</div><div class="qopts">`+r.options.map(o=>`<label class="qopt${sel===o.k?' on':''}"><input type="radio" name="q_${r.id}" value="${o.k}"${sel===o.k?' checked':''}><span class="qk">${o.k}</span><span>${esc(o.t)}</span></label>`).join('')+`</div><textarea class="qnotes" data-id="${r.id}" placeholder="Notes / your own answer…">${esc(nt)}</textarea></div>`;}
    });
    b.innerHTML=html;
    b.querySelectorAll('input[type=radio]').forEach(inp=>inp.addEventListener('change',e=>{const id=e.target.name.slice(2);saveAns(id,'sel',e.target.value);e.target.closest('.qopts').querySelectorAll('.qopt').forEach(l=>l.classList.toggle('on',l.querySelector('input').checked));}));
    b.querySelectorAll('.qnotes').forEach(ta=>ta.addEventListener('input',e=>saveAns(e.target.dataset.id,'notes',e.target.value)));
    const ex=document.getElementById('qexport');if(ex)ex.addEventListener('click',()=>{const L=['CGA OPEN-QUESTIONS — operator answers'];D.questions.filter(x=>x.status==='open').forEach(x=>{const a=ANS[x.id]||{};const s=a.sel||'(none)';const ot=(x.options.find(o=>o.k===s)||{}).t||'';L.push('\n[L'+x.lane+'] '+x.q+'\n  = '+s+(ot?' — '+ot:'')+(a.notes?'\n  notes: '+a.notes:''));});const txt=L.join('\n');const o=document.getElementById('qexport-out');o.textContent=txt;o.classList.remove('hidden');try{navigator.clipboard.writeText(txt);}catch(e){}});
    return;
  }
  b.querySelectorAll('.area-head').forEach(h=>{const list=h.nextElementSibling;if(!list)return;h.addEventListener('click',()=>{const hid=list.classList.toggle('hidden');h.setAttribute('aria-expanded',String(!hid));});});
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

stamp = "As of %s · main @ <code>%s</code> · Wave 4 GREEN — authoritative gate 1343 passed / 0 failed" % (DATA['asOf'], DATA['head'])
html = TEMPLATE.replace('%%DATA%%', json.dumps(DATA, separators=(',', ':'))).replace('%%STAMP%%', stamp)
out = r'E:\fair-constitution-app\docs\plans\ui\tools\app_progress_rubric.html'
with io.open(out, 'w', encoding='utf-8') as f:
    f.write(html)
print('wrote', out, len(html), 'bytes ·', len(screens), 'screens', len(caps), 'caps', len(debt), 'debt', len(FLEET['lanes']), 'lanes', len(QUESTIONS), 'questions')
