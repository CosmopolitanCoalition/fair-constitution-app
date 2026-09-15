// node --experimental-vm-modules --test tests/js/i18nGaps_gap_shell_operator.test.mjs
//
// Gap lane gap-shell-operator pin. The 2026-09-14 catalogue campaign wired
// template sentences of four or more words. The 2026-09-15 gap sweep wires the
// rest on the shell, operator, demo and setup surfaces: short labels, static
// user-text attributes, script-side label and status maps, toast and error
// strings, and fragments glued around interpolations.
//
// This test is DB-free. It reads the .vue sources and the en catalogs directly.
// It asserts six things.
//
//   1. COMPILE GATE. Every listed .vue file compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC has a scoped
//      style). A worktree cannot reach the Vite gate, so a compiled parse here
//      is that gate.
//   2. ADOPTION. Every listed file imports useI18n.
//   3. NO RAW KEY LEAK. Every literal key referenced under any c_ namespace
//      resolves in that namespace's en catalog. Dynamic-tail keys (t('c_x.' +
//      v)) are families the desk check validates and are skipped here.
//   4. CATALOG SHAPE. Every catalog the section-3 scan reads parses and every
//      value is a non-empty string.
//   5. NO RAW TEXT NODE. Walking the compiled template AST, no text node with a
//      letter remains outside t() unless its element or an ancestor carries
//      data-no-i18n, or the node holds only a citation, a unit token, a number,
//      punctuation or a glyph.
//   6. NO RAW USER-TEXT ATTRIBUTE. No static attribute or prop that carries
//      user text (title, placeholder, alt, aria-label, aria-description, label,
//      help, hint, description, caption, eyebrow, submit-label,
//      processing-label, quota-title, empty-text, or any *label*/*text*/*title*/
//      *message* prop) holds a plain string with a letter, unless the element or
//      an ancestor carries data-no-i18n. ARIA idref attributes (aria-labelledby,
//      aria-describedby, ...) are references, not text, and are excluded.
//
// Every file path is built from segments, never one full 'Pages/<module>/<file>'
// literal (the NavRoleGateParityTest census note). The instrument self-checks
// first: a measure that cannot fail measures nothing.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const enDir = path.join(jsRoot, 'i18n/locales/en');

// File paths built from segments (census note) — never a single literal.
const FILES = [
  ['Layouts', 'AppShell.vue'],
  ['Layouts', 'AppShellV2.vue'],
  ['Components/Shell', 'AppFooter.vue'],
  ['Components/Shell', 'AppHeader.vue'],
  ['Components/Shell', 'JurisdictionRail.vue'],
  ['Components/Shell', 'JurisdictionSwitcher.vue'],
  ['Components/ShellV2', 'MenuNav.vue'],
  ['Components/ShellV2', 'DemoFlyout.vue'],
  ['Components', 'CosmicAddressPicker.vue'],
  ['Components/Ui', 'LawDiff.vue'],
  ['Pages/Operator', 'Home.vue'],
  ['Pages/Operator', 'Dns.vue'],
  ['Pages/Operator', 'Mesh.vue'],
  ['Pages/Operator', 'Moderation.vue'],
  ['Pages/Operator', 'Operations.vue'],
  ['Pages/Demo', 'SimConsole.vue'],
  ['Pages/Setup', 'JoinHost.vue'],
  ['Pages/Setup', 'OperatorSetup.vue'],
  ['Pages/Setup', 'Step1_Constants.vue'],
  ['Pages/Setup', 'Step2_MapData.vue'],
  ['Pages/Setup', 'Step3_Districts.vue'],
  ['Pages/Setup', 'Step4_ScaleUp.vue'],
  ['Pages/Setup', 'Step5_Simulate.vue'],
  ['Components/Setup', 'EventToasts.vue'],
  ['Components/Setup', 'ExportBackupPanel.vue'],
  ['Components/Setup', 'ImportBackupPanel.vue'],
];

function fileInfo([dir, file]) {
  return { dir, file, rel: `${dir}/${file}`, abs: path.join(jsRoot, dir, file) };
}

const catalogCache = new Map();
function readCatalog(ns) {
  if (catalogCache.has(ns)) return catalogCache.get(ns);
  const p = path.join(enDir, `${ns}.json`);
  const data = existsSync(p) ? JSON.parse(readFileSync(p, 'utf8')) : null;
  catalogCache.set(ns, data);
  return data;
}

// Literal c_<ns>.<key> references, excluding dynamic-tail (a '+' after the
// closing quote — a key family the desk check validates).
function referencedKeys(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_[A-Za-z0-9_]+\.[^'"]*?)\1(\s*\+)?/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    if (m[3]) continue; // dynamic tail
    const full = m[2];
    const dot = full.indexOf('.');
    out.push({ ns: full.slice(0, dot), key: full.slice(dot + 1), full });
  }
  return out;
}

// ── text-node exemption ─────────────────────────────────────────────────────
const LETTER = /\p{L}/u;
// Citation tokens in code voice (article + roman numeral + section marks).
const CIT = /\b(?:Art\.?|§\d*|CLK-\d+|R-\d+|F-[A-Z]{2,5}-\d+|WF-[A-Z]+-\d+|ESM-\d+|Phase\b|[IVXLCDM]{1,7}\b)/g;
// Unit / short measure tokens.
const UNIT = /\b(?:ms|s|min|mins|h|hr|hrs|d|px|kg|km|cm|mm|m|mb|gb|kb|tb|b|fps|dpi)\b/gi;

function decode(raw) {
  return raw
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ').replace(/&#\d+;/g, ' ')
    .replace(/\s+/g, ' ').trim();
}

function textIsRaw(raw) {
  const text = decode(raw);
  if (!text) return null;
  if (!LETTER.test(text)) return null; // pure number / punctuation / glyph
  const stripped = text.replace(CIT, ' ').replace(UNIT, ' ');
  if (!LETTER.test(stripped)) return null; // only citations / units remained
  return text.slice(0, 90);
}

const T_ELEMENT = 1;
const T_TEXT = 2;
const T_INTERPOLATION = 5;

function hasNoI18n(node) {
  return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

// ── attribute exemption ─────────────────────────────────────────────────────
const TEXT_ATTRS = new Set([
  'title', 'placeholder', 'alt', 'aria-label', 'aria-description', 'label',
  'help', 'hint', 'description', 'caption', 'eyebrow', 'submit-label',
  'processing-label', 'quota-title', 'empty-text',
]);
function attrCarriesText(name) {
  if (TEXT_ATTRS.has(name)) return true;
  // Generic prop names, but never an ARIA idref (aria-labelledby, ...).
  if (name.startsWith('aria-')) return false;
  return /(label|text|title|message)/i.test(name);
}
function rawAttrs(node) {
  const hits = [];
  for (const p of node.props || []) {
    if (p.type === 6 && p.value && typeof p.value.content === 'string') {
      if (attrCarriesText(p.name) && LETTER.test(p.value.content)) {
        hits.push(`${p.name}="${p.value.content}"`);
      }
    }
  }
  return hits;
}

function scan(ast) {
  const textHits = [];
  const attrHits = [];
  const walk = (node, ndAncestor) => {
    if (!node) return;
    if (node.type === T_TEXT) {
      if (!ndAncestor) {
        const hit = textIsRaw(node.content ?? '');
        if (hit) textHits.push(hit);
      }
      return;
    }
    if (node.type === T_INTERPOLATION) return;
    if (node.type === T_ELEMENT) {
      const nd = ndAncestor || hasNoI18n(node);
      if (!nd) for (const a of rawAttrs(node)) attrHits.push(a);
      for (const c of node.children || []) walk(c, nd);
      return;
    }
    for (const c of node.children || []) walk(c, ndAncestor);
  };
  walk(ast, false);
  return { textHits, attrHits };
}

function astOf(tpl) {
  return parse(`<template>${tpl}</template>`).descriptor.template.ast;
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys, raw text and raw attributes', () => {
  assert.equal(/useI18n/.test('const { t } = useI18n();'), true);
  assert.deepEqual(
    referencedKeys("t('c_host.a.b') and t(\"c_setup.c\")").map((r) => r.full),
    ['c_host.a.b', 'c_setup.c'],
  );
  assert.deepEqual(referencedKeys("t('c_host.' + key)"), []); // dynamic tail skipped
  assert.equal(textIsRaw('This is a raw label'), 'This is a raw label');
  assert.equal(textIsRaw('· '), null); // glyph only
  assert.equal(textIsRaw('42 ms'), null); // number + unit
  assert.equal(textIsRaw('Art. II §2'), null); // citation only
  assert.equal(textIsRaw('×'), null); // math glyph, not a letter
  assert.equal(textIsRaw('.tar.gz'), '.tar.gz'); // machine value is NOT auto-exempt
  assert.deepEqual(scan(astOf('<p>A raw sentence</p>')).textHits, ['A raw sentence']);
  assert.deepEqual(scan(astOf("<p>{{ t('x', 'wired') }}</p>")).textHits, []);
  assert.deepEqual(scan(astOf('<span data-no-i18n>scale_demo</span>')).textHits, []);
  assert.deepEqual(scan(astOf('<code data-no-i18n>.env</code>')).textHits, []);
  assert.deepEqual(scan(astOf('<i title="Hi there">{{ x }}</i>')).attrHits, ['title="Hi there"']);
  assert.deepEqual(scan(astOf('<i aria-labelledby="an-id">{{ x }}</i>')).attrHits, []);
  assert.deepEqual(scan(astOf('<i :title="t(\'k\')">{{ x }}</i>')).attrHits, []);
  assert.deepEqual(scan(astOf('<i data-no-i18n title="Raw">{{ x }}</i>')).attrHits, []);
  assert.equal(FILES.length, 26, 'the full lane file set');
  console.log(`  files: ${FILES.length}`);
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
  assert.deepEqual(broken, [], `files must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every listed file imports useI18n', () => {
  const off = [];
  for (const p of FILES.map(fileInfo)) {
    if (!/useI18n/.test(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
  }
  console.log(`  wired ${FILES.length - off.length}/${FILES.length}`);
  for (const f of off) console.log(`    unwired: ${f}`);
  assert.deepEqual(off, [], `every file must use i18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
const referencedNamespaces = new Set();
test('no raw key leak — every referenced c_ key resolves in its namespace', () => {
  const unresolved = [];
  let checked = 0;
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    for (const { ns, key, full } of referencedKeys(src)) {
      referencedNamespaces.add(ns);
      checked += 1;
      const cat = readCatalog(ns);
      if (!cat) { unresolved.push(`${p.rel}: ${ns}.json missing (key ${full})`); continue; }
      if (!(key in cat)) unresolved.push(`${p.rel}: ${full} absent from ${ns}.json`);
    }
  }
  console.log(`  checked ${checked} keys across ${referencedNamespaces.size} namespaces`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — every referenced catalog parses with non-empty string values', () => {
  const bad = [];
  const seen = new Set([...referencedNamespaces]);
  // Guarantee the lane-touched namespaces are validated even if a scan misses one.
  for (const ns of ['c_gap_shell_operator', 'c_operator_pages', 'c_setup', 'c_host', 'c_term_sync', 'c_explore', 'c_ui_b']) seen.add(ns);
  let total = 0;
  for (const ns of seen) {
    const cat = readCatalog(ns);
    if (!cat) { bad.push(`${ns}.json missing`); continue; }
    for (const [k, v] of Object.entries(cat)) {
      total += 1;
      if (typeof v !== 'string' || v.length === 0) bad.push(`${ns}.${k}`);
    }
  }
  console.log(`  validated ${seen.size} catalogs, ${total} values, bad ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw text node.
test('no raw text node — no letter-bearing text node remains outside t()', () => {
  const leaks = [];
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of scan(descriptor.template.ast).textHits) leaks.push(`${p.rel}: "${hit}"`);
  }
  console.log(`  raw text leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw text nodes may remain: ${leaks.join(' | ')}`);
});

// ── SECTION 6 — no raw user-text attribute.
test('no raw user-text attribute — no static user-text attribute holds a plain string', () => {
  const leaks = [];
  for (const p of FILES.map(fileInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of scan(descriptor.template.ast).attrHits) leaks.push(`${p.rel}: ${hit}`);
  }
  console.log(`  raw user-text attributes: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw user-text attributes may remain: ${leaks.join(' | ')}`);
});
