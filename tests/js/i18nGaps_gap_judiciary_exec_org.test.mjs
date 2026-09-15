// node --experimental-vm-modules --test tests/js/i18nGaps_gap_judiciary_exec_org.test.mjs
//
// Gap lane gap-judiciary-exec-org pin. The 2026-09-14 catalogue campaign
// wired 4+ word template sentences. This lane sweeps what it missed on the
// Judiciary, Executive, Organizations and Economy surfaces: short labels
// (1 to 3 words), full sentences in unlisted files, fragments glued around
// {{ }}, static user-text attributes and props, script label and status
// maps rendered raw, and toast/error strings built in handlers.
//
// This test is DB-free. It reads the .vue sources and the en catalogs
// directly. It asserts six things.
//
//   1. COMPILE GATE. Every listed page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC carries a
//      scoped style). A worktree cannot reach the Vite gate, so a compiled
//      parse here is that gate.
//   2. ADOPTION. Every listed file imports useI18n.
//   3. NO RAW KEY LEAK. Every c_ namespace key a file references resolves
//      in that namespace's en catalog.
//   4. CATALOG SHAPE. Every touched catalog parses and every value is a
//      non-empty string.
//   5. NO RAW TEXT NODE. Walking the compiled template AST, no text node
//      with a letter remains outside t(), unless its element or an ancestor
//      carries data-no-i18n, or the node is only a citation, punctuation, a
//      number or a glyph.
//   6. NO RAW USER-TEXT ATTRIBUTE. No static attribute or prop from the
//      user-text set holds a plain string with a letter.
//   7. NO RAW PROSE FALLBACK. No ?? or || fallback in a script block holds a
//      multi-word English string outside t(). A single-word operand is a
//      machine token and passes.
//
// Page paths are built from segments, never one full 'Pages/<dir>/<file>'
// literal (the NavRoleGateParityTest census note).
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing).
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const enRoot = path.join(jsRoot, 'i18n/locales/en');

// Every listed file, path built from segments.
const FILES = [
  ['Pages', 'Judiciary', 'CaseDetail.vue'],
  ['Pages', 'Judiciary', 'JurorView.vue'],
  ['Pages', 'Judiciary', 'CaseDocket.vue'],
  ['Pages', 'Judiciary', 'ConstitutionalChallenge.vue'],
  ['Pages', 'Judiciary', 'Home.vue'],
  ['Pages', 'Executive', 'DepartmentDetail.vue'],
  ['Pages', 'Executive', 'DepartmentReporting.vue'],
  ['Pages', 'Executive', 'Departments.vue'],
  ['Pages', 'Executive', 'Actions.vue'],
  ['Pages', 'Organizations', 'BoardElections.vue'],
  ['Pages', 'Organizations', 'CgcDetail.vue'],
  ['Pages', 'Organizations', 'OrgDetail.vue'],
  ['Pages', 'Organizations', 'TransfersConversions.vue'],
  ['Pages', 'Organizations', 'Registry.vue'],
  ['Pages', 'Economy', 'OrgSettings.vue'],
  ['Pages', 'Economy', 'ResidentAgreements.vue'],
  ['Pages', 'Economy', 'JointLedgers.vue'],
  ['Pages', 'Economy', 'Treasury.vue'],
  ['Components', 'Judiciary', 'Art4Section5Tracker.vue'],
  ['Components', 'Judiciary', 'PanelTable.vue'],
  ['Components', 'Executive', 'DepartmentCard.vue'],
  ['Components', 'Executive', 'OrderScopeCard.vue'],
  ['Components', 'Organizations', 'BoardStrip.vue'],
  ['Components', 'Organizations', 'CoDetScale.vue'],
  ['Components', 'Organizations', 'OwnershipPanel.vue'],
];

// Catalogs the lane touches.
const TOUCHED = ['c_institutions', 'c_economy', 'c_institution_components', 'c_references', 'c_rooms'];

function fileInfo(seg) {
  const rel = seg.join('/');
  return { rel, abs: path.join(jsRoot, ...seg) };
}

function catPath(ns) {
  return path.join(enRoot, `${ns}.json`);
}

// Every literal c_<ns> key a source references. A helper builds a dynamic
// key by concatenation (t('c_references.department.' + key)); such a match
// ends with '.' or is immediately followed by '+'. These families are not
// literal keys, so they are skipped. The fallback second argument still
// renders their English.
function referencedKeys(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_[a-z_]+\.(?:\\.|(?!\1).)*)\1(\s*\+)?/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    const full = m[2];
    if (m[3] || full.endsWith('.')) continue; // concatenated / dynamic family
    const dot = full.indexOf('.');
    const key = full.slice(dot + 1);
    if (!key) continue;
    out.push({ ns: full.slice(0, dot), key });
  }
  return out;
}

// AST node types.
const T_ELEMENT = 1;
const T_TEXT = 2;
const T_INTERPOLATION = 5;

// Citation and glyph tolerance. A text node that reduces to nothing after
// removing citation tokens, numbers, punctuation and glyphs is not user
// prose and passes.
const CITATION = [
  /Art\.\s*[IVXLCDM]+/g,           // Art. IV
  /§\s*\d+/g,                       // § 2
  /\bF-[A-Z]{2,4}-\d{2,3}[a-z]?\b/g,// F-IND-012
  /\bCLK-\d{2}\b/g,                 // CLK-06
  /\bR-\d{2}\b/g,                   // R-03
  /\bESM-\d{2}\b/g,                 // ESM-06
  /\bWF-[A-Z]{2,4}-\d{2}\b/g,       // WF-EXE-07
  /\b[IVXLCDM]{1,6}\b/g,            // bare roman numerals
];

function reducesToNonProse(raw) {
  let text = raw
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ').replace(/&#\d+;/g, ' ')
    .replace(/\s+/g, ' ').trim();
  if (!text) return true;
  for (const re of CITATION) text = text.replace(re, ' ');
  // Glyph and unit tokens.
  text = text.replace(/\b(ms|\/s|px|rem)\b/g, ' ');
  // Any remaining Latin letter means real prose survives.
  return !/[A-Za-zÀ-ɏ]/.test(text);
}

function hasNoI18n(node) {
  return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

function rawTextNodes(ast) {
  const hits = [];
  const walk = (node, muted) => {
    if (!node) return;
    if (node.type === T_TEXT) {
      if (muted) return;
      if (!reducesToNonProse(node.content ?? '')) {
        hits.push((node.content ?? '').replace(/\s+/g, ' ').trim().slice(0, 100));
      }
      return;
    }
    if (node.type === T_INTERPOLATION) return;
    const nowMuted = muted || (node.type === T_ELEMENT && hasNoI18n(node));
    for (const child of node.children || []) walk(child, nowMuted);
  };
  walk(ast, false);
  return hits;
}

// User-text attribute names. Static (type 6) props with a plain string value
// on any of these are leaks.
const USER_TEXT_ATTRS = new Set([
  'title', 'placeholder', 'alt', 'aria-label', 'aria-description', 'label',
  'help', 'hint', 'description', 'caption', 'eyebrow', 'submit-label',
  'processing-label', 'quota-title', 'empty-text', 'confirm-label',
  'cancel-label',
]);

function isUserTextAttr(name) {
  // ARIA id-reference attributes (aria-labelledby, aria-describedby, …) hold
  // element ids, not prose. Only aria-label / aria-description carry text.
  if (name.startsWith('aria-')) return name === 'aria-label' || name === 'aria-description';
  if (USER_TEXT_ATTRS.has(name)) return true;
  return /label|message/.test(name)
    || /text$/.test(name) || name === 'text'
    || /title$/.test(name);
}

function rawAttrs(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_ELEMENT) {
      for (const p of node.props || []) {
        // type 6 is a static attribute: name + value.
        if (p.type === 6 && isUserTextAttr(p.name) && p.value && typeof p.value.content === 'string') {
          if (!reducesToNonProse(p.value.content)) {
            hits.push(`${node.tag}[${p.name}="${p.value.content.slice(0, 60)}"]`);
          }
        }
      }
    }
    for (const child of node.children || []) walk(child);
  };
  walk(ast);
  return hits;
}

// Script-side prose fallback. A rendered data value with a raw English
// fallback (name: x ?? 'Previously selected party') never reaches t() and
// shows to the viewer when the cache misses. The right operand of ?? or ||
// is scanned. A single-word operand (no space) is a machine token ('guest',
// 'draft') and passes. A t() call is not a bare literal, so it never matches.
function scriptProseFallbacks(script) {
  const hits = [];
  const re = /(\?\?|\|\|)\s*(["'])((?:\\.|(?!\2).)*?[A-Za-z](?:\\.|(?!\2).)*?)\2/g;
  let m;
  while ((m = re.exec(script)) !== null) {
    const s = m[3];
    if (!/\s/.test(s)) continue;      // single-word machine token
    if (reducesToNonProse(s)) continue; // citation or glyph only
    hits.push(`${m[1]} "${s.slice(0, 60)}"`);
  }
  return hits;
}

function scriptOf(descriptor) {
  return `${descriptor.scriptSetup?.content ?? ''}\n${descriptor.script?.content ?? ''}`;
}

function astOf(src, abs) {
  const { descriptor, errors } = parse(src, { filename: abs });
  if (errors && errors.length) throw new Error(errors.map((e) => e.message).join('; '));
  return descriptor;
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys, raw text and raw attrs', () => {
  assert.equal(/useI18n/.test('const { t } = useI18n();'), true);
  assert.deepEqual(referencedKeys("t('c_institutions.a.b') and t(\"c_economy.c.d\")"),
    [{ ns: 'c_institutions', key: 'a.b' }, { ns: 'c_economy', key: 'c.d' }]);
  assert.deepEqual(referencedKeys("t('c_references.department.' + key, fb)"), []);
  const tplAst = (tpl) => parse(`<template>${tpl}</template>`).descriptor.template.ast;
  assert.deepEqual(rawTextNodes(tplAst('<p>Open case</p>')), ['Open case']);
  assert.deepEqual(rawTextNodes(tplAst('<p>{{ t(\'x\', \'wired\') }}</p>')), []);
  assert.deepEqual(rawTextNodes(tplAst('<span data-no-i18n>Art. IV §5</span>')), []);
  assert.deepEqual(rawTextNodes(tplAst('<span>Art. IV §5</span>')), []);
  assert.deepEqual(rawTextNodes(tplAst('<p :x="a > 1 ? b : c">{{ y }}</p>')), []);
  assert.deepEqual(rawTextNodes(tplAst('<em data-no-i18n><b>F-IND-012</b></em>')), []);
  assert.deepEqual(rawAttrs(tplAst('<X title="Hello there" />')), ['X[title="Hello there"]']);
  assert.deepEqual(rawAttrs(tplAst('<X :title="t(\'k\')" type="text" />')), []);
  assert.deepEqual(rawAttrs(tplAst('<input placeholder="Search" />')), ['input[placeholder="Search"]']);
  assert.deepEqual(rawAttrs(tplAst('<section aria-labelledby="budget-title" />')), []);
  assert.deepEqual(scriptProseFallbacks("name: n ?? 'Previously selected party'"), ['?? "Previously selected party"']);
  assert.deepEqual(scriptProseFallbacks("id ?? 'guest'"), []);
  assert.deepEqual(scriptProseFallbacks("n ?? t('k', 'Previously selected party')"), []);
  assert.deepEqual(scriptProseFallbacks("tz || 'Art. IV'"), []);
  assert.equal(FILES.length, 25, 'the full lane file set');
  console.log(`  files: ${FILES.length}; touched catalogs: ${TOUCHED.join(', ')}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every listed file compiles with @vue/compiler-sfc', () => {
  const broken = [];
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    try {
      const { descriptor, errors } = parse(src, { filename: p.abs });
      if (errors && errors.length) { broken.push(`${p.rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
      compileScript(descriptor, { id: p.rel });
      if (descriptor.template) {
        const scoped = descriptor.styles.some((st) => st.scoped);
        const r = compileTemplate({
          source: descriptor.template.content,
          filename: p.abs,
          id: p.rel,
          scoped,
          compilerOptions: scoped ? { scopeId: 'data-v-gap' } : {},
        });
        if (r.errors && r.errors.length) broken.push(`${p.rel} (template): ${r.errors.map((e) => (e.message || e)).join('; ')}`);
      }
    } catch (e) {
      broken.push(`${p.rel}: ${e.message}`);
    }
  }
  console.log(`  compiled ${FILES.length} files, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `listed files must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every listed file imports useI18n', () => {
  const off = [];
  for (const p of FILES.map(fileInfo)) {
    if (!/useI18n/.test(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
  }
  console.log(`  wired ${FILES.length - off.length}/${FILES.length}`);
  for (const f of off) console.log(`    unwired: ${f}`);
  assert.deepEqual(off, [], `every listed file must use i18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
test('no raw key leak — every referenced c_ key resolves in its namespace', () => {
  const cache = {};
  const catFor = (ns) => {
    if (!(ns in cache)) {
      const cp = catPath(ns);
      cache[ns] = existsSync(cp) ? new Set(Object.keys(JSON.parse(readFileSync(cp, 'utf8')))) : null;
    }
    return cache[ns];
  };
  const unresolved = [];
  let checked = 0;
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    for (const { ns, key } of referencedKeys(src)) {
      checked += 1;
      const have = catFor(ns);
      if (!have) { unresolved.push(`${p.rel}: namespace ${ns} has no en catalog`); continue; }
      if (!have.has(key)) unresolved.push(`${p.rel}: ${ns}.${key} absent from ${ns}.json`);
    }
  }
  console.log(`  checked ${checked} referenced keys across ${FILES.length} files`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — every touched catalog parses, values non-empty strings', () => {
  const bad = [];
  for (const ns of TOUCHED) {
    const cp = catPath(ns);
    assert.ok(existsSync(cp), `${ns}.json exists`);
    const cat = JSON.parse(readFileSync(cp, 'utf8'));
    for (const [k, v] of Object.entries(cat)) {
      if (typeof v !== 'string' || v.length === 0) bad.push(`${ns}.${k}`);
    }
  }
  console.log(`  touched catalogs: ${TOUCHED.length}, bad values: ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw text node.
test('no raw text node — no letter-bearing text node outside t()', () => {
  const leaks = [];
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawTextNodes(descriptor.template.ast)) leaks.push(`${p.rel}: "${hit}"`);
  }
  console.log(`  raw text-node leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw text nodes may remain: ${leaks.length}`);
});

// ── SECTION 6 — no raw user-text attribute.
test('no raw user-text attribute — no static user-text prop holds a plain string', () => {
  const leaks = [];
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawAttrs(descriptor.template.ast)) leaks.push(`${p.rel}: ${hit}`);
  }
  console.log(`  raw user-text attribute leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw user-text attributes may remain: ${leaks.length}`);
});

// ── SECTION 7 — no raw prose fallback in script.
test('no raw prose fallback — no ?? / || English fallback outside t() in script', () => {
  const leaks = [];
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    for (const hit of scriptProseFallbacks(scriptOf(descriptor))) leaks.push(`${p.rel}: ${hit}`);
  }
  console.log(`  raw script prose-fallback leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw prose fallbacks may remain in script: ${leaks.length}`);
});
