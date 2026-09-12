import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { SITEMAP } from '../../../resources/js/registry/surfaces.js';

const out = path.resolve('docs/audits/2026-09-12');
const read = name => JSON.parse(fs.readFileSync(path.join(out,name),'utf8').replace(/^\uFEFF/,''));
const source = read('source-inventory.json');
const reports = ['arrival-inventory.json','civic-inventory.json','economy-learning-inventory.json','system-inventory.json'].map(read);
const normalize = p => p.replaceAll('\\','/').replace(/^resources\/js\/Pages\//,'').replace(/\.vue$/,'');
const reviews = reports.flatMap(r => r.surfaces ?? r.pages ?? []);
const byPage = new Map();
for (const review of reviews) {
  const key = normalize(review.page);
  assert(!byPage.has(key),`Duplicate review: ${key}`);
  byPage.set(key,review);
}
assert.equal(byPage.size,134);
const labels = {wired_action:'Actions wired in code',wired_read:'Live-data read in code',partial_action:'Incomplete action flow',developer_fixture:'Developer fixture controls',reference:'Reference material',resolver:'Context resolver'};
const supported = new Set(['keep','contextualize','merge','complete','repair','reference','operator','developer']);
function disposition(r) {
  if(supported.has(r.recommendation)) return r.recommendation;
  if(r.behavior==='partial_action') return 'complete';
  if(r.behavior==='reference') return 'reference';
  if(r.audience==='developer' || r.behavior==='developer_fixture') return 'developer';
  if(r.audience==='operator') return 'operator';
  return /merge|consolidat/i.test(r.recommendation) ? 'merge' : 'contextualize';
}
const familyDefs = [
  ['Arrival & accounts',['Arrival','Auth','Invite','Tour']],
  ['Civic life & community',['Civic','Social']],
  ['Elections & government',['Elections','Legislature','Executive','Judiciary']],
  ['Places & maps',['Jurisdictions']],
  ['Organizations & economy',['Organizations','Economy']],
  ['Learning',['Learn']],
  ['System records & support',['System','Support']],
  ['Hosting & development',['Setup','Operator','Dev','Demo','Build']],
];
const pages = source.pages.map(p=>{
  const r = byPage.get(p.page);
  assert(r,`Missing review: ${p.page}`);
  const base = p.page.split('/').at(-1).replaceAll('_',' · ').replace(/([a-z])([A-Z])/g,'$1 $2');
  const behavior = r.behavior ?? r.implementation ?? r.status ?? '';
  const routes = [...new Set([...(r.routes??[]),...(r.path?.startsWith('/')?[r.path]:[]),...p.routes])];
  return {...p,label:base+(p.group==='Arrival'?'':` · ${p.group}`),purpose:r.purpose??r.reads??base,behavior:labels[behavior]??behavior,recommendation:disposition(r),detail:r.detail??r.rationale??r.note??r.recommendation,evidence:r.evidence??[],routes,review:r};
});
const issues = [
  {title:'Multi-user demo cleanup needs a conflict check',kind:'Demo acceptance gate',problem:'UPDATE reversal restores the full earlier row using its primary key. It does not check whether another active session has since edited that row.',proposal:'Verify two-user edits, logout and expiry on an isolated fixture. Preserve later edits and the audit chain before offering shared demo actions.',evidence:['app/Services/Demo/DemoSessionService.php:224','app/Services/Demo/DemoSessionService.php:235','tests/Constitutional/DemoSessionVoidTest.php']},
  {title:'Competing arrival and learning directories',kind:'Consolidate',problem:'Home and Launchpad duplicate doors. Guides repeats the journey directory. The tour has 60 stops, and the short list does not have its own next/back sequence.',proposal:'Use one arrival flow and one Learn home. Keep guided scenarios and reference material inside it.',evidence:['resources/js/Pages/Home.vue:46','resources/js/Pages/Launchpad.vue:30','resources/js/Pages/Learn/Guides.vue:28','resources/js/Pages/Tour/Index.vue:89']},
  {title:'Selected context is lost',kind:'Repair · browser confirmed',problem:'The Earth election card on civic home opened an Anne Arundel election. Named feed and calendar links point to the generic election resolver.',proposal:'Carry the selected election, place and work item through links, forms and results.',evidence:['app/Services/TodayFeedService.php:146','app/Services/TodayFeedService.php:357','app/Http/Controllers/Elections/ElectionController.php:151']},
  {title:'Public discussion, live rooms and testimony overlap',kind:'Consolidate entry points',problem:'The menu exposes square, halls, live square, live halls and private rooms. They have distinct record/privacy purposes but competing entry points.',proposal:'Use one Community directory. Label public record, live meeting and private conversation by purpose; retain their different rules.',evidence:['resources/js/registry/surfaces.js:44','resources/js/Pages/Civic/PublicSquare.vue','resources/js/Pages/Civic/Halls.vue','resources/js/Pages/Civic/MatrixCommons.vue']},
  {title:'Lessons cannot support onboarding yet',kind:'Complete · browser confirmed',problem:'The election-board lesson shows translation keys as its title and quiz text. Its template starts with a comprehension check without the lesson body.',proposal:'Restore readable lesson content, then connect learning to a real exercise and outcome.',evidence:['resources/js/Pages/Learn/Lesson.vue:57','resources/js/Pages/Learn/Lesson.vue:67','app/Http/Controllers/Education/LearnController.php:110']},
  {title:'Meeting and jury controls stop short',kind:'Complete',problem:'The live committee room has incomplete call/vote controls. Enter deliberation room in the juror view has no destination or click handler.',proposal:'Complete the selected meeting and case workflows before promoting them as playable scenarios.',evidence:['resources/js/Pages/Legislature/LiveCivicRoom.vue:175','resources/js/Pages/Legislature/LiveCivicRoom.vue:243','resources/js/Pages/Judiciary/JurorView.vue:264']},
  {title:'Economic activities have missing counterparts',kind:'Complete',problem:'Job applications are wired, but employer posting/decision interfaces were not found. Help requests are read-only. Share issuance exists in the engine without a located UI invocation.',proposal:'Finish one buyer/seller or employer/applicant round trip. Put agreements and finance inside the relevant person or organization context.',evidence:['app/Services/Economy/LaborBoardService.php:44','resources/js/Pages/Economy/Market.vue:223','app/Domain/Forms/Handlers/OrganizationMarketParticipation.php:74']},
  {title:'Some ordinary pages perform world-scale work',kind:'Repair before broad browsing',problem:'Economy home verifies the full ledger per request. Term Sync loads all active legislatures. Co-determination loads all boards before focus. These were not load-tested.',proposal:'Use bounded context, paging and cached progress before widening demo navigation.',evidence:['app/Http/Controllers/Economy/EconomyController.php:64','app/Http/Controllers/System/TermSyncController.php:50','app/Http/Controllers/Organizations/CoDeterminationController.php:89']},
  {title:'Host and developer work crowds player navigation',kind:'Reorganize',problem:'The full menu mixes setup, node operation, developer kits, coverage reports and player activities. Some new operator pages repeat readouts while writes remain in older consoles.',proposal:'Give host/developer tools separate entry points. Keep public observation and reference access available.',evidence:['resources/js/registry/surfaces.js:155','resources/js/Components/ShellV2/MenuNav.vue:147','app/Http/Controllers/System/CoverageController.php:29']},
];
const data = {counts:source.counts,families:familyDefs.map(([name,groups])=>({name,pages:pages.filter(p=>groups.includes(p.group))})),menu:SITEMAP.map(s=>({title:s.title,items:s.items})),issues,repeatedDestinations:source.repeatedDestinations};
assert.equal(data.families.reduce((n,f)=>n+f.pages.length,0),134);
for(const p of pages) for(const ref of p.evidence){
  const file = ref.replace(/:\d+(?:-\d+)?$/,'');
  if(/^(app|resources|routes|config|tests)\//.test(file)) assert(fs.existsSync(file),`Missing evidence: ${ref}`);
}
fs.writeFileSync(path.join(out,'inventory.json'),JSON.stringify(data,null,2)+'\n');
const template=fs.readFileSync(path.join(out,'map-template.html'),'utf8');
const fragment=template.replace('__INVENTORY_JSON__',JSON.stringify(data).replaceAll('<','\\u003c'));
assert(!fragment.includes('__INVENTORY_JSON__'));
for(const m of fragment.matchAll(/<script>([\s\S]*?)<\/script>/g)) new Function(m[1]);
assert(Buffer.byteLength(fragment)<1_000_000);
const target = process.argv[2];
assert(target,'Provide an absolute fragment destination');
fs.writeFileSync(target,fragment);
console.log(JSON.stringify({pages:pages.length,families:data.families.map(f=>({name:f.name,pages:f.pages.length})),bytes:Buffer.byteLength(fragment),fragment:target},null,2));
