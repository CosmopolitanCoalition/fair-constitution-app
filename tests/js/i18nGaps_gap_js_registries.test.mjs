// node --experimental-vm-modules --test tests/js/i18nGaps_gap_js_registries.test.mjs
//
// Gap lane "gap-js-registries" pin (kind jsdata, lane namespace c_navigation).
// The 2026-09-14 catalogue campaign wired template sentences. This lane wires
// the registries, shipped content and small pieces that campaign missed:
// registry values rendered raw through their consumers, config content, and
// two component-local messages blocks. DB-free. Reads .vue sources, the .js
// registries, the PHP config fixture, and the en catalogs directly.
//
// It asserts:
//   1. COMPILE GATE. Every listed .vue file compiles with @vue/compiler-sfc.
//   2. ADOPTION. Every listed .vue file imports useI18n.
//   3. NO LOCAL MESSAGES. No listed .vue file keeps a component-local
//      messages block (useScope: 'local' / messages:).
//   4. CALL SHAPES. Each consumer calls t() for the registry/content fields it
//      renders, in the exact shapes this lane introduced.
//   5. REGISTRY EQUALITY. For every registry/config the lane wired, every
//      rendered entry has a catalog key and the catalog value equals the
//      registry's English value.
//   6. CATALOG SHAPE. Every touched catalog parses and every value is a
//      non-empty string.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const enDir = path.join(jsRoot, 'i18n/locales/en');

const abs = (rel) => path.join(jsRoot, rel);
const readSrc = (rel) => readFileSync(abs(rel), 'utf8');
const readCat = (ns) => JSON.parse(readFileSync(path.join(enDir, `${ns}.json`), 'utf8'));

// The listed .vue consumers (path segments, never one Pages/... literal).
const VUE_FILES = [
  'Components/ShellV2/TourBar.vue',
  'Pages/Tour/Index.vue',
  'Components/ShellV2/LearnFlyout.vue',
  'Pages/Civic/Journeys.vue',
  'Pages/Civic/Journey.vue',
  'Components/Arrival/ArrivalHub.vue',
  'Pages/Economy/Home.vue',
  'Components/Geodata/GeodataFlagQueue.vue',
  'Components/Geodata/GeodataRepairModal.vue',
];

const TOUCHED_CATALOGS = [
  'c_navigation', 'c_tour', 'c_learn', 'c_journeys',
  'c_arrival', 'c_economy', 'c_map_health', 'flows',
];

// ── SECTION 1 — compile gate.
test('compile gate — every listed .vue file compiles', () => {
  const broken = [];
  for (const rel of VUE_FILES) {
    const src = readSrc(rel);
    try {
      const { descriptor, errors } = parse(src, { filename: rel });
      if (errors && errors.length) { broken.push(`${rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
      const scoped = descriptor.styles.some((s) => s.scoped);
      const script = compileScript(descriptor, { id: rel });
      if (descriptor.template) {
        const r = compileTemplate({
          source: descriptor.template.content,
          filename: rel, id: rel, scoped,
          compilerOptions: { bindingMetadata: script.bindings },
        });
        if (r.errors && r.errors.length) broken.push(`${rel}: ${r.errors.map((e) => (e.message || e)).join('; ')}`);
      }
    } catch (e) {
      broken.push(`${rel}: ${e.message}`);
    }
  }
  console.log(`  compiled ${VUE_FILES.length} files, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every listed .vue file imports useI18n', () => {
  const off = VUE_FILES.filter((rel) => !/useI18n/.test(readSrc(rel)));
  console.log(`  useI18n present: ${VUE_FILES.length - off.length}/${VUE_FILES.length}`);
  assert.deepEqual(off, [], `useI18n missing: ${off.join(', ')}`);
});

// ── SECTION 3 — no component-local messages block.
test('no local messages — no listed .vue keeps useScope local / messages block', () => {
  const bad = [];
  for (const rel of VUE_FILES) {
    const src = readSrc(rel);
    if (/useScope:\s*'local'/.test(src)) bad.push(`${rel}: useScope local`);
    if (/useI18n\(\s*\{[\s\S]*?messages\s*:/.test(src)) bad.push(`${rel}: messages block`);
  }
  console.log(`  local-message blocks remaining: ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `local messages must be gone: ${bad.join(' | ')}`);
});

// ── SECTION 4 — call shapes.
test('call shapes — each consumer wires its registry/content fields', () => {
  const need = {
    'Components/ShellV2/TourBar.vue': [
      /t\('c_tour\.stop\.' \+ \(stepNumber - 1\) \+ '\.title', stop\.title\)/,
      /t\('c_tour\.stop\.' \+ \(stepNumber - 1\) \+ '\.blurb', stop\.blurb\)/,
    ],
    'Pages/Tour/Index.vue': [
      /t\('c_tour\.stop\.' \+ o\.i \+ '\.title', o\.title\)/,
      /t\('c_tour\.stop\.' \+ o\.i \+ '\.blurb', o\.blurb\)/,
      /t\(actKey\(g\.act\), g\.act\)/,
      /const actKey = /,
    ],
    'Components/ShellV2/LearnFlyout.vue': [
      /function flowsKey\(/,
      /flowText\(primaryFlow\.wfName\)/,
      /flowText\(primaryFlow\.familyLabel\)/,
      /flowText\(f\.wfName\)/,
      /t\('c_learn\.module\.' \+ mod, LEARN_BY_MODULE\[mod\]\)/,
      /text\('report_issue', 'Report an issue'\)/,
    ],
    'Pages/Civic/Journeys.vue': [
      /t\('c_journeys\.class\.' \+ group\.id, group\.label\)/,
      /t\('c_journeys\.' \+ j\.id \+ '\.title', j\.title\)/,
    ],
    'Pages/Civic/Journey.vue': [
      /t\('c_journeys\.class\.' \+ clsId/,
      /t\('c_journeys\.' \+ props\.journey\.id \+ '\.your_part'/,
      /t\('c_journeys\.' \+ props\.journey\.id \+ '\.earn'/,
      /t\('c_journeys\.' \+ journey\.id \+ '\.step\.' \+ index \+ '\.label', step\.label\)/,
      /t\('c_journeys\.' \+ journey\.id \+ '\.step\.' \+ index \+ '\.what', step\.what\)/,
      /t\('c_journeys\.' \+ journey\.id \+ '\.step\.' \+ index \+ '\.you', step\.you\)/,
      /t\('c_journeys\.' \+ journey\.id \+ '\.title', journey\.title\)/,
    ],
    'Components/Arrival/ArrivalHub.vue': [
      /i18nT\('c_arrival\.arrival_hub\.' \+ key/,
    ],
    'Pages/Economy/Home.vue': [
      /i18nT\('c_economy\.home\.' \+ key/,
    ],
    'Components/Geodata/GeodataFlagQueue.vue': [
      /t\(`c_map_health\.\$\{cat\}\.\$\{field\}`/,
      /checkText\(group\.category, 'measures'\)/,
      /checkText\(group\.category, 'why'\)/,
      /checkText\(group\.category, 'reading'\)/,
      /checkText\(group\.category, 'remedy'\)/,
      /natureText\(group\.category\)/,
      /natureHint\(group\.category\)/,
    ],
  };
  const missing = [];
  for (const [rel, pats] of Object.entries(need)) {
    const src = readSrc(rel);
    for (const p of pats) if (!p.test(src)) missing.push(`${rel}: ${p}`);
  }
  console.log(`  call-shape checks failed: ${missing.length}`);
  for (const m of missing) console.log(`    ${m}`);
  assert.deepEqual(missing, [], `missing call shapes: ${missing.join(' | ')}`);
});

// ── SECTION 5 — registry equality.
test('registry equality — c_navigation covers every nav id + section', async () => {
  const s = await import(pathToFileURL(abs('registry/surfaces.js')).href);
  const cat = readCat('c_navigation');
  // Ids whose c_navigation key predates this lane, seeded by another consumer
  // with a different English (a legitimate cross-consumer key). Their on-screen
  // value is that consumer's, not the SITEMAP fallback, so only presence is
  // this lane's business. 'legislatures': DemoFlyout seeds 'Legislative maps';
  // the SITEMAP item reuses the id and already renders that value.
  const CROSS_CONSUMER = new Set(['legislatures']);
  const labels = new Map(); // id -> Set of labels
  const add = (id, label) => { if (!labels.has(id)) labels.set(id, new Set()); labels.get(id).add(label); };
  for (const it of s.PLAYER_NAV) add(it.id, it.label);
  for (const sec of s.SITEMAP) for (const it of sec.items) add(it.id, it.label);
  const bad = [];
  for (const [id, set] of labels) {
    if (!(id in cat)) { bad.push(`missing id: ${id}`); continue; }
    if (typeof cat[id] !== 'string' || cat[id].length === 0) { bad.push(`empty id: ${id}`); continue; }
    if (CROSS_CONSUMER.has(id)) continue; // presence-only, value owned elsewhere
    if (set.size === 1) {
      const only = [...set][0];
      if (cat[id] !== only) bad.push(`id ${id}: '${cat[id]}' != '${only}'`);
    } else if (!set.has(cat[id])) {
      bad.push(`collision id ${id}: '${cat[id]}' not in {${[...set].join(' | ')}}`);
    }
  }
  for (const sec of s.SITEMAP) {
    const k = 'section_' + sec.key;
    if (cat[k] !== sec.title) bad.push(`section ${sec.key}: '${cat[k]}' != '${sec.title}'`);
  }
  console.log(`  nav ids ${labels.size}, sections ${s.SITEMAP.length}, mismatches ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `c_navigation gaps: ${bad.join(' | ')}`);
});

test('registry equality — c_tour covers every TOUR stop + act', async () => {
  const s = await import(pathToFileURL(abs('registry/surfaces.js')).href);
  const cat = readCat('c_tour');
  const actSlug = (a) => a.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'act';
  const bad = [];
  s.TOUR.forEach((st, i) => {
    if (cat[`stop.${i}.title`] !== st.title) bad.push(`stop ${i} title`);
    if (cat[`stop.${i}.blurb`] !== st.blurb) bad.push(`stop ${i} blurb`);
    const ak = 'act.' + actSlug(st.act);
    if (cat[ak] !== st.act) bad.push(`act ${st.act} (${ak})`);
  });
  console.log(`  tour stops ${s.TOUR.length}, mismatches ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `c_tour gaps: ${bad.join(' | ')}`);
});

test('registry equality — c_learn covers LEARN_BY_MODULE', async () => {
  const s = await import(pathToFileURL(abs('registry/surfaces.js')).href);
  const cat = readCat('c_learn');
  const bad = [];
  for (const [mod, txt] of Object.entries(s.LEARN_BY_MODULE)) {
    if (cat[`module.${mod}`] !== txt) bad.push(`module ${mod}`);
  }
  console.log(`  learn modules ${Object.keys(s.LEARN_BY_MODULE).length}, mismatches ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `c_learn gaps: ${bad.join(' | ')}`);
});

test('registry equality — c_journeys covers classes, journeys (js) and steps (php)', async () => {
  const j = await import(pathToFileURL(abs('registry/journeys.js')).href);
  const cat = readCat('c_journeys');
  const php = JSON.parse(readFileSync(path.join(root, 'tests/js/fixtures_journeys_php.json'), 'utf8'));
  const bad = [];
  for (const c of j.CLASSES) if (cat[`class.${c.id}`] !== c.label) bad.push(`class ${c.id}`);
  for (const jr of j.JOURNEYS) {
    if (cat[`${jr.id}.title`] !== jr.title) bad.push(`${jr.id} title(js)`);
    if (jr.yourPart && cat[`${jr.id}.your_part`] !== jr.yourPart) bad.push(`${jr.id} your_part`);
    if (jr.earn && cat[`${jr.id}.earn`] !== jr.earn) bad.push(`${jr.id} earn`);
  }
  for (const [id, jr] of Object.entries(php)) {
    if (cat[`${id}.title`] !== jr.title) bad.push(`${id} title(php)`);
    (jr.steps || []).forEach((st, i) => {
      if (st.label && cat[`${id}.step.${i}.label`] !== st.label) bad.push(`${id} step ${i} label`);
      if (st.what && cat[`${id}.step.${i}.what`] !== st.what) bad.push(`${id} step ${i} what`);
      if (st.you && cat[`${id}.step.${i}.you`] !== st.you) bad.push(`${id} step ${i} you`);
    });
  }
  console.log(`  journeys js ${j.JOURNEYS.length}, php ${Object.keys(php).length}, mismatches ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `c_journeys gaps: ${bad.join(' | ')}`);
});

test('registry equality — c_map_health covers every check field + nature badge', async () => {
  const m = await import(pathToFileURL(abs('lib/mapHealth.js')).href);
  const cat = readCat('c_map_health');
  const bad = [];
  for (const [id, c] of Object.entries(m.MAP_HEALTH_CHECKS)) {
    for (const f of ['label', 'measures', 'why', 'reading', 'remedy']) {
      if (c[f] !== undefined && c[f] !== '' && cat[`${id}.${f}`] !== c[f]) bad.push(`${id}.${f}`);
    }
  }
  for (const [nat, b] of Object.entries(m.NATURE_BADGE)) {
    if (cat[`nature.${nat}.text`] !== b.text) bad.push(`nature.${nat}.text`);
    if (cat[`nature.${nat}.hint`] !== b.hint) bad.push(`nature.${nat}.hint`);
  }
  console.log(`  checks ${Object.keys(m.MAP_HEALTH_CHECKS).length}, natures ${Object.keys(m.NATURE_BADGE).length}, mismatches ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `c_map_health gaps: ${bad.join(' | ')}`);
});

// flows.json keys are a pure function of the raw text (extract_flows.mjs).
const slugify = (s) => (s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40) || 'step');
const sha8 = (s) => createHash('sha256').update(s, 'utf8').digest('hex').slice(0, 8);
const flowsKey = (raw) => { const s = String(raw).replace(/\s+/g, ' ').trim(); return `${slugify(s)}_${sha8(s)}`; };
const esc = (s) => String(s).replace(/[|{}@]/g, (c) => `{'${c}'}`);

test('registry equality — flows wfName/familyLabel resolve in the flows catalog', async () => {
  const f = await import(pathToFileURL(abs('registry/flows.js')).href);
  const cat = readCat('flows');
  const bad = [];
  let n = 0;
  for (const surface of Object.keys(f.FLOWS_BY_SURFACE)) {
    for (const wf of f.FLOWS_BY_SURFACE[surface]) {
      for (const field of ['wfName', 'familyLabel']) {
        const raw = wf[field];
        if (!raw) continue;
        n += 1;
        const key = flowsKey(raw);
        if (!(key in cat)) bad.push(`missing ${field}: ${key} (${raw})`);
        else if (cat[key] !== esc(raw)) bad.push(`${key}: '${cat[key]}' != '${esc(raw)}'`);
      }
    }
  }
  console.log(`  flows fields checked ${n}, mismatches ${bad.length}`);
  for (const b of bad.slice(0, 10)) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `flows gaps: ${bad.slice(0, 10).join(' | ')}`);
});

// ── SECTION 6 — catalog shape.
test('catalog shape — every touched catalog parses and values are non-empty strings', () => {
  const bad = [];
  for (const ns of TOUCHED_CATALOGS) {
    const cat = readCat(ns);
    for (const [k, v] of Object.entries(cat)) {
      if (typeof v !== 'string' || v.length === 0) bad.push(`${ns}: ${k}`);
    }
  }
  console.log(`  catalogs ${TOUCHED_CATALOGS.length}, bad values ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `bad catalog values: ${bad.join(', ')}`);
});
