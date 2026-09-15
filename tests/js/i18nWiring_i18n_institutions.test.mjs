// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_institutions.test.mjs
//
// Catalogue lane i18n-institutions pin. The institution page bodies
// (Elections detail, Executive, Judiciary, Organizations) wire through
// vue-i18n against the c_institutions namespace. This test is DB-free. It
// reads the .vue sources and the en catalog directly. It asserts five
// things.
//
//   1. COMPILE GATE. Every lane page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC carries a
//      scoped style). A worktree cannot reach the Vite gate, so a compiled
//      parse here is that gate.
//   2. ADOPTION. Every lane page imports useI18n.
//   3. NO RAW KEY LEAK. Every c_institutions key a page references resolves
//      in resources/js/i18n/locales/en/c_institutions.json.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No lane page still holds a raw English sentence of
//      four or more words in a template text node, outside t(), {{ }},
//      data-no-i18n elements, script and style.
//
// The census note (NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals in tests/js as DOM-mount companions): this test is a SOURCE pin,
// so every page path is built from segments, never one full literal.
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
const NS = 'c_institutions';
const catalogPath = path.join(jsRoot, 'i18n/locales/en', `${NS}.json`);

// Page paths built from segments (census note) — never a single literal.
const PAGES = [
  ['Elections', 'ElectionDetail.vue'],
  ['Executive', 'Actions.vue'],
  ['Executive', 'DepartmentReporting.vue'],
  ['Executive', 'Departments.vue'],
  ['Executive', 'Home.vue'],
  ['Judiciary', 'AdvocateConsole.vue'],
  ['Judiciary', 'CaseDocket.vue'],
  ['Judiciary', 'ConstitutionalChallenge.vue'],
  ['Judiciary', 'Home.vue'],
  ['Judiciary', 'JurorView.vue'],
  ['Organizations', 'CgcDetail.vue'],
  ['Organizations', 'CoDetermination.vue'],
  ['Organizations', 'OrgDetail.vue'],
  ['Organizations', 'Registry.vue'],
  ['Organizations', 'TransfersConversions.vue'],
];

function pageInfo([dir, file]) {
  const abs = path.join(jsRoot, 'Pages', dir, file);
  return { dir, file, rel: `Pages/${dir}/${file}`, abs };
}

function readCatalog() {
  return JSON.parse(readFileSync(catalogPath, 'utf8'));
}

// Every literal c_institutions key a source references. A key built by
// concatenation (t('c_institutions.x_' + s)) would have '+' after the closing
// quote; section 3b asserts none exist, so a literal collector is complete.
function referencedKeys(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_institutions\.(?:\\.|(?!\1).)*)\1/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    out.push(m[2].slice(`${NS}.`.length));
  }
  return out;
}

// Concatenated (dynamic-tail) c_institutions key references, if any.
function dynamicPrefixes(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_institutions\.(?:\\.|(?!\1).)*)\1\s*\+/g;
  let m;
  while ((m = re.exec(src)) !== null) out.push(m[2]);
  return out;
}

// Raw-sentence scan over the compiled template AST. Walking the AST (not the
// raw string) is what keeps attribute expressions — which carry '>' and '<'
// (races > 1, (f) => f.id) — out of the scan. Skips elements marked
// data-no-i18n, interpolations, comments, script and style. Flags any TEXT
// node with 4+ alphabetic words.
const T_ELEMENT = 1;
const T_TEXT = 2;
const T_INTERPOLATION = 5;

function textIsRawSentence(raw) {
  const text = raw
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ').replace(/&#\d+;/g, ' ')
    .replace(/\s+/g, ' ').trim();
  if (!text) return null;
  const words = text.match(/[A-Za-zÀ-ɏ]{2,}/g) || [];
  return words.length >= 4 ? text.slice(0, 120) : null;
}

function hasNoI18n(node) {
  return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

function rawSentences(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_TEXT) {
      const hit = textIsRawSentence(node.content ?? '');
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

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys and raw text', () => {
  assert.equal(/useI18n/.test("const { t } = useI18n();"), true);
  assert.deepEqual(referencedKeys("t('c_institutions.a.b') and t(\"c_institutions.c.d\")"), ['a.b', 'c.d']);
  assert.deepEqual(referencedKeys("t('leaflet') t('c_ui.x.y')"), []);
  const astOf = (tpl) => parse(`<template>${tpl}</template>`).descriptor.template.ast;
  assert.deepEqual(rawSentences(astOf('<p>This is a raw sentence</p>')), ['This is a raw sentence']);
  assert.deepEqual(rawSentences(astOf('<p>{{ t(\'x\', \'This is wired\') }}</p>')), []);
  assert.deepEqual(rawSentences(astOf('<span data-no-i18n>syncs on federation now</span>')), []);
  assert.deepEqual(rawSentences(astOf('<p :label="a > 1 ? b : c">{{ x }}</p>')), []);
  assert.equal(PAGES.length, 15, 'the full lane page set');
  assert.ok(existsSync(catalogPath), 'c_institutions.json exists');
  console.log(`  lane pages: ${PAGES.length}; catalog present: ${existsSync(catalogPath)}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
  const broken = [];
  for (const p of PAGES.map(pageInfo)) {
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
          compilerOptions: scoped ? { scopeId: 'data-v-inst' } : {},
        });
        if (r.errors && r.errors.length) broken.push(`${p.rel} (template): ${r.errors.map((e) => (e.message || e)).join('; ')}`);
      }
    } catch (e) {
      broken.push(`${p.rel}: ${e.message}`);
    }
  }
  console.log(`  compiled ${PAGES.length} pages, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `lane pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every lane page imports useI18n', () => {
  const off = [];
  for (const p of PAGES.map(pageInfo)) {
    if (!/useI18n/.test(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
  }
  console.log(`  wired ${PAGES.length - off.length}/${PAGES.length}`);
  for (const f of off) console.log(`    unwired: ${f}`);
  assert.deepEqual(off, [], `every lane page must use i18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
test('no raw key leak — every referenced c_institutions key resolves', () => {
  const catalog = readCatalog();
  const have = new Set(Object.keys(catalog));
  const unresolved = [];
  let checked = 0;
  for (const p of PAGES.map(pageInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    for (const key of referencedKeys(src)) {
      checked += 1;
      if (!have.has(key)) unresolved.push(`${p.rel}: ${NS}.${key} absent from ${NS}.json`);
    }
  }
  console.log(`  checked ${checked} referenced keys across ${PAGES.length} pages`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 3b — no unhandled dynamic key families.
test('no dynamic key families — every c_institutions key is a literal', () => {
  const dyn = [];
  for (const p of PAGES.map(pageInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    for (const pre of dynamicPrefixes(src)) dyn.push(`${p.rel}: ${pre} + <dynamic>`);
  }
  console.log(`  dynamic-tail key references: ${dyn.length}`);
  for (const d of dyn) console.log(`    ${d}`);
  assert.deepEqual(dyn, [], `no concatenated c_institutions keys are expected: ${dyn.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — parses and every value is a non-empty string', () => {
  const catalog = readCatalog();
  const bad = [];
  for (const [k, v] of Object.entries(catalog)) {
    if (typeof v !== 'string' || v.length === 0) bad.push(k);
  }
  console.log(`  catalog keys: ${Object.keys(catalog).length}, bad values: ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw sentence in a template text node.
test('no raw sentence — no 4+ word raw English in a template text node', () => {
  const leaks = [];
  for (const p of PAGES.map(pageInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawSentences(descriptor.template.ast)) {
      leaks.push(`${p.rel}: "${hit}"`);
    }
  }
  console.log(`  raw sentence leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw sentences may remain: ${leaks.join(' | ')}`);
});
