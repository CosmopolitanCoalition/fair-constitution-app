// node --experimental-vm-modules --test tests/js/i18nGaps_gap_elections_jurisdictions.test.mjs
//
// Gap lane gap-elections-jurisdictions pin. The 2026-09-14 catalogue campaign
// wired template sentences of four or more words. This lane sweeps what it
// missed on the Elections and Jurisdictions surfaces: short labels, sentences
// glued around interpolations, static user-text attributes, script label maps,
// toast and error strings, and one library error string. This test is DB-free.
// It reads the .vue and .js sources and the en catalogs directly. It asserts:
//
//   1. COMPILE GATE. Every listed .vue file compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC has a scoped
//      style). A worktree cannot reach the Vite gate, so a compiled parse
//      here is that gate.
//   2. ADOPTION. Every listed .vue file imports useI18n.
//   3. NO RAW KEY LEAK. Every c_ key any listed file references resolves in
//      that namespace's en file.
//   4. CATALOG SHAPE. Every catalog the lane touched parses and every value
//      is a non-empty string.
//   5. NO RAW TEXT NODE OF ANY LENGTH. Walking the compiled template AST, no
//      text node with a letter remains outside t(), unless its element or an
//      ancestor carries data-no-i18n, or the node is only a citation, a glyph,
//      punctuation or a number.
//   6. NO RAW USER-TEXT ATTRIBUTE. No static attribute or prop whose name is
//      a user-text carrier (title, placeholder, alt, label, hint, caption,
//      quota-title, eyebrow, submit-label, and the *label*/*text*/*title*/
//      *message* families) holds a plain string with translatable text.
//   7. NO RAW SCRIPT-SIDE ATTRIBUTION. A Leaflet attribution prefix
//      ('Population', 'Boundaries', 'Basemap') built as a quoted string literal
//      in a <script> block escapes the template AST walk. This section reads the
//      script source and flags any quoted attribution literal that pairs a prefix
//      word with a &copy; credit. The wired form opens with t() in a template
//      literal, so a t() default argument is not flagged.
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing). Every file path is built from segments, never one full literal.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const enDir = path.join(jsRoot, 'i18n/locales/en');

// Listed .vue files, built from segments.
const VUE_FILES = [
  ['Pages', 'Elections', 'Results.vue'],
  ['Pages', 'Elections', 'RankedBallot.vue'],
  ['Pages', 'Elections', 'VacancyCountback.vue'],
  ['Pages', 'Elections', 'BoardConsole.vue'],
  ['Pages', 'Elections', 'OpenBallot.vue'],
  ['Pages', 'Elections', 'ElectionDetail.vue'],
  ['Pages', 'Jurisdictions', 'Home.vue'],
  ['Pages', 'Jurisdictions', 'Federation.vue'],
  ['Pages', 'Jurisdictions', 'UnionFormation.vue'],
  ['Pages', 'Jurisdictions', 'Bootstrap.vue'],
  ['Pages', 'Jurisdictions', 'Disintermediation.vue'],
  ['Pages', 'Jurisdictions', 'Restoration.vue'],
  ['Pages', 'Jurisdictions', 'Show.vue'],
  ['Pages', 'Build', 'Progress.vue'],
  ['Components', 'Federation', 'SyncProgress.vue'],
];

// Listed plain .js files (not SFCs) — key references and adoption do not apply.
const JS_FILES = [
  ['lib', 'csrf.js'],
  ['lib', 'deviceIdentity.js'],
];

// Catalogs this lane touched.
const TOUCHED_CATALOGS = [
  'c_elections',
  'c_institutions',
  'c_jurisdictions',
  'c_civic_components',
  'c_gap_elections_jurisdictions',
];

function fileInfo(segs) {
  const rel = segs.join('/');
  return { rel, abs: path.join(jsRoot, ...segs) };
}

function catalogPath(ns) {
  return path.join(enDir, `${ns}.json`);
}

// Every literal c_ key a source references, as [ns, key].
function referencedKeys(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_[a-z0-9_]+)\.((?:\\.|(?!\1).)*)\1/g;
  let m;
  while ((m = re.exec(src)) !== null) out.push([m[2], m[3]]);
  return out;
}

// Concatenated (dynamic-tail) c_ key references, if any.
function dynamicPrefixes(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_[a-z0-9_]+\.(?:\\.|(?!\1).)*)\1\s*\+/g;
  let m;
  while ((m = re.exec(src)) !== null) out.push(m[2]);
  return out;
}

// ── citation / machine-token detector. A value needs no translation when,
//    after removing citation tokens, form ids, glyphs, digits and punctuation,
//    no letters remain. Mirrors the doctrine: constitutional citations in code
//    voice, glyphs and machine values stay raw.
const CITATION_TOKEN = new RegExp(
  [
    'Art\\.?\\s*[IVXLCDM]+',        // Art. II, Art IV
    '§\\s*\\d+',                    // §2
    'F-[A-Z]{2,}-\\d+',            // F-ELB-004
    'WF-[A-Z]{2,}-\\d+',          // WF-ELE-05
    'CLK-\\d+',                    // CLK-18
    'ESM-\\d+',                    // ESM-06
    'R-\\d{1,2}',                  // R-08
    'G-[A-Z]{2,}',                // G-ID
    '\\bF-[A-Z]{2,}\\b',          // bare form family
  ].join('|'),
  'g',
);

function residualLetters(raw) {
  const decoded = raw
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ').replace(/&#\d+;/g, ' ');
  let s = decoded.replace(CITATION_TOKEN, ' ');
  // strip glyphs, digits and punctuation this codebase uses in citation lines
  s = s.replace(/[·—–|§%×÷#()/+.,:;=<>°'"`\-–—\d\s]/g, '');
  return s.replace(/[A-Za-zÀ-ɏ]/g, '').length === s.length ? '' : s;
}

// A text node holds translatable text when letters survive the strip.
function textIsRawUnit(raw) {
  const collapsed = raw.replace(/\s+/g, ' ').trim();
  if (!collapsed) return null;
  const hasLetter = /[A-Za-zÀ-ɏ]/.test(collapsed);
  if (!hasLetter) return null;
  if (residualLetters(collapsed) === '') return null; // citation / machine only
  return collapsed.slice(0, 120);
}

const T_TEXT = 2;
const T_INTERPOLATION = 5;
const T_ELEMENT = 1;

function hasNoI18n(node) {
  return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

function rawTextNodes(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_TEXT) {
      const hit = textIsRawUnit(node.content ?? '');
      if (hit) hits.push(hit);
      return;
    }
    if (node.type === T_INTERPOLATION) return;
    if (node.type === T_ELEMENT && hasNoI18n(node)) return;
    for (const child of node.children || []) walk(child);
  };
  walk(ast);
  return hits;
}

// User-text attribute carriers. A static prop with one of these names holding
// translatable text must be bound to t() instead. ARIA id-reference attributes
// hold element ids, never text, so they are excluded even though their names
// contain "label"/"description".
const ARIA_IDREF = new Set([
  'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns',
  'aria-activedescendant', 'aria-details', 'aria-errormessage', 'aria-flowto',
]);
function isUserTextAttrName(name) {
  const n = name.toLowerCase();
  if (ARIA_IDREF.has(n)) return false;
  const exact = new Set([
    'title', 'placeholder', 'alt', 'aria-label', 'aria-description',
    'label', 'help', 'hint', 'description', 'caption', 'eyebrow',
    'submit-label', 'processing-label', 'quota-title', 'empty-text',
  ]);
  if (exact.has(n)) return true;
  // Segment match so "context" (has substring "text") is not a carrier, while
  // "submit-label", "empty-text", "quota-title" and camelCase forms are.
  const segs = name.replace(/([a-z])([A-Z])/g, '$1 $2').split(/[-_\s]+/).map((s) => s.toLowerCase());
  return segs.some((s) => s === 'label' || s === 'text' || s === 'title' || s === 'message');
}

// Static props are AST prop type 6 with a value; bound props (:label / v-bind)
// are type 7 and are never flagged (they can reference t()).
function rawAttrs(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_ELEMENT) {
      if (hasNoI18n(node)) return; // a marked element opts its own attrs out too
      for (const p of node.props || []) {
        if (p.type === 6 && p.value && isUserTextAttrName(p.name)) {
          const hit = textIsRawUnit(p.value.content ?? '');
          if (hit) hits.push(`${node.tag}[${p.name}]="${hit}"`);
        }
      }
    }
    for (const child of node.children || []) walk(child);
  };
  walk(ast);
  return hits;
}

// ── Leaflet attribution prefixes live in <script> string literals, not the
//    template, so the AST walk never sees them. The raw defect pairs a prefix
//    word with a &copy; credit inside one quoted literal, e.g.
//    attribution: 'Population &copy; <a ...>WorldPop</a>'. The wired form is a
//    template literal opening with t(): `${t(...)} &copy; ...`. A t() default
//    argument such as t('...', 'Population') carries no &copy; and is not a hit.
const ATTR_LITERAL = /(['"])\s*(Population|Boundaries|Basemap)\b(?:(?!\1).)*?&copy;/g;
function rawScriptAttribution(scriptSrc) {
  const hits = [];
  if (!scriptSrc) return hits;
  let m;
  ATTR_LITERAL.lastIndex = 0;
  while ((m = ATTR_LITERAL.exec(scriptSrc)) !== null) hits.push(m[0].slice(0, 120));
  return hits;
}

// The combined script text of an SFC (both <script> and <script setup>).
function scriptText(descriptor) {
  return [descriptor.script?.content, descriptor.scriptSetup?.content].filter(Boolean).join('\n');
}

function compileOne(info) {
  const src = readFileSync(info.abs, 'utf8');
  const { descriptor, errors } = parse(src, { filename: info.abs });
  if (errors && errors.length) {
    return { error: errors.map((e) => e.message).join('; ') };
  }
  compileScript(descriptor, { id: info.rel });
  let templateErrors = [];
  if (descriptor.template) {
    const scoped = descriptor.styles.some((st) => st.scoped);
    const r = compileTemplate({
      source: descriptor.template.content,
      filename: info.abs,
      id: info.rel,
      scoped,
      compilerOptions: scoped ? { scopeId: 'data-v-gap' } : {},
    });
    if (r.errors && r.errors.length) templateErrors = r.errors.map((e) => e.message || String(e));
  }
  return { descriptor, src, templateErrors };
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys, raw text and raw attributes', () => {
  assert.equal(/useI18n/.test("const { t } = useI18n();"), true);
  assert.deepEqual(referencedKeys("t('c_elections.a.b') and t(\"c_ui.c.d\")"), [['c_elections', 'a.b'], ['c_ui', 'c.d']]);
  const astOf = (tpl) => parse(`<template>${tpl}</template>`).descriptor.template.ast;
  assert.deepEqual(rawTextNodes(astOf('<p>This is a raw sentence</p>')), ['This is a raw sentence']);
  assert.deepEqual(rawTextNodes(astOf('<p>Add</p>')), ['Add']);
  assert.deepEqual(rawTextNodes(astOf('<p>{{ t(\'x\', \'wired\') }}</p>')), []);
  assert.deepEqual(rawTextNodes(astOf('<span data-no-i18n>syncs on federation</span>')), []);
  assert.deepEqual(rawTextNodes(astOf('<p>· Art. II §2</p>')), []);
  assert.deepEqual(rawTextNodes(astOf('<p>Art. II §7</p>')), []);
  assert.deepEqual(rawTextNodes(astOf('<p>CLK-18 · CLK-21 · Art. II §2</p>')), []);
  assert.deepEqual(rawTextNodes(astOf('<p>{{ count }} ×</p>')), []);
  assert.deepEqual(rawAttrs(astOf('<Stat label="valid ballots" />')), ['Stat[label]="valid ballots"']);
  assert.deepEqual(rawAttrs(astOf('<Stat :label="t(\'x\')" />')), []);
  assert.deepEqual(rawAttrs(astOf('<CitationLine text="CLK-18 · Art. II §2" />')), []);
  assert.deepEqual(rawAttrs(astOf('<Btn icon="map" tone="warning" />')), []);
  assert.deepEqual(rawAttrs(astOf('<section aria-labelledby="found-h" />')), []);
  assert.deepEqual(rawAttrs(astOf('<PhaseBanner context="open-ballot" />')), []);
  assert.deepEqual(rawTextNodes(astOf('<span>Civil</span>')), ['Civil']);
  assert.deepEqual(
    rawScriptAttribution("attribution: 'Population &copy; <a href=\"https://www.worldpop.org/\">WorldPop</a>',"),
    ["'Population &copy;"],
  );
  assert.deepEqual(rawScriptAttribution("`${t('c_jurisdictions.show.attr_population', 'Population')} &copy; <a>WorldPop</a>`"), []);
  assert.deepEqual(rawScriptAttribution("t('c_jurisdictions.show.attr_population', 'Population')"), []);
  assert.equal(VUE_FILES.length, 15, 'the full lane vue set');
  assert.equal(JS_FILES.length, 2, 'the two lane js files');
  console.log(`  vue files: ${VUE_FILES.length}; js files: ${JS_FILES.length}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every listed .vue file compiles', () => {
  const broken = [];
  for (const segs of VUE_FILES) {
    const info = fileInfo(segs);
    try {
      const r = compileOne(info);
      if (r.error) broken.push(`${info.rel}: ${r.error}`);
      else if (r.templateErrors.length) broken.push(`${info.rel} (template): ${r.templateErrors.join('; ')}`);
    } catch (e) {
      broken.push(`${info.rel}: ${e.message}`);
    }
  }
  console.log(`  compiled ${VUE_FILES.length} files, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `lane files must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every listed .vue file imports useI18n', () => {
  const off = [];
  for (const segs of VUE_FILES) {
    const info = fileInfo(segs);
    if (!/useI18n/.test(readFileSync(info.abs, 'utf8'))) off.push(info.rel);
  }
  console.log(`  wired ${VUE_FILES.length - off.length}/${VUE_FILES.length}`);
  for (const f of off) console.log(`    unwired: ${f}`);
  assert.deepEqual(off, [], `every lane .vue file must use i18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
test('no raw key leak — every referenced c_ key resolves', () => {
  const catalogs = new Map();
  const load = (ns) => {
    if (!catalogs.has(ns)) {
      const p = catalogPath(ns);
      catalogs.set(ns, existsSync(p) ? new Set(Object.keys(JSON.parse(readFileSync(p, 'utf8')))) : null);
    }
    return catalogs.get(ns);
  };
  const unresolved = [];
  let checked = 0;
  const allFiles = [...VUE_FILES, ...JS_FILES];
  for (const segs of allFiles) {
    const info = fileInfo(segs);
    const src = readFileSync(info.abs, 'utf8');
    for (const [ns, key] of referencedKeys(src)) {
      checked += 1;
      const have = load(ns);
      if (have === null) unresolved.push(`${info.rel}: ${ns}.json absent`);
      else if (!have.has(key)) unresolved.push(`${info.rel}: ${ns}.${key} absent from ${ns}.json`);
    }
  }
  console.log(`  checked ${checked} referenced c_ keys`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 3b — no unhandled dynamic key families.
test('no dynamic key families — every c_ key is a literal', () => {
  const dyn = [];
  for (const segs of [...VUE_FILES, ...JS_FILES]) {
    const info = fileInfo(segs);
    const src = readFileSync(info.abs, 'utf8');
    for (const pre of dynamicPrefixes(src)) dyn.push(`${info.rel}: ${pre} + <dynamic>`);
  }
  console.log(`  dynamic-tail key references: ${dyn.length}`);
  for (const d of dyn) console.log(`    ${d}`);
  assert.deepEqual(dyn, [], `no concatenated c_ keys are expected: ${dyn.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — every touched catalog parses, values non-empty strings', () => {
  const bad = [];
  let total = 0;
  for (const ns of TOUCHED_CATALOGS) {
    const p = catalogPath(ns);
    if (!existsSync(p)) { bad.push(`${ns}.json missing`); continue; }
    const cat = JSON.parse(readFileSync(p, 'utf8'));
    for (const [k, v] of Object.entries(cat)) {
      total += 1;
      if (typeof v !== 'string' || v.length === 0) bad.push(`${ns}.${k}`);
    }
  }
  console.log(`  catalog values across ${TOUCHED_CATALOGS.length} namespaces: ${total}, bad: ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw text node of any length.
test('no raw text node — no letter text node remains outside t()', () => {
  const leaks = [];
  for (const segs of VUE_FILES) {
    const info = fileInfo(segs);
    const { descriptor } = parse(readFileSync(info.abs, 'utf8'), { filename: info.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawTextNodes(descriptor.template.ast)) leaks.push(`${info.rel}: "${hit}"`);
  }
  console.log(`  raw text-node leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw text nodes may remain: ${leaks.join(' | ')}`);
});

// ── SECTION 6 — no raw user-text attribute.
test('no raw user-text attribute — every user-text prop is bound', () => {
  const leaks = [];
  for (const segs of VUE_FILES) {
    const info = fileInfo(segs);
    const { descriptor } = parse(readFileSync(info.abs, 'utf8'), { filename: info.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawAttrs(descriptor.template.ast)) leaks.push(`${info.rel}: ${hit}`);
  }
  console.log(`  raw user-text attribute leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw user-text attributes may remain: ${leaks.join(' | ')}`);
});

// ── SECTION 7 — no raw script-side Leaflet attribution prefix.
test('no raw script attribution — Leaflet prefixes route through t()', () => {
  const leaks = [];
  for (const segs of VUE_FILES) {
    const info = fileInfo(segs);
    const { descriptor } = parse(readFileSync(info.abs, 'utf8'), { filename: info.abs });
    for (const hit of rawScriptAttribution(scriptText(descriptor))) leaks.push(`${info.rel}: ${hit}`);
  }
  console.log(`  raw script attribution leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw script attribution prefixes may remain: ${leaks.join(' | ')}`);
});
