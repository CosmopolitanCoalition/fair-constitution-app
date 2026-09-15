// node --experimental-vm-modules --test tests/js/i18nGaps_gap_legislature.test.mjs
//
// Gap lane gap-legislature pin. The 2026-09-14 catalogue campaign wired the
// template sentences of four or more words. This lane closes the gaps that
// sweep left: short labels of one to three words, full sentences the campaign
// never listed, text fragments glued around interpolations, static attributes
// and props that carry user text, script-side label and status maps, and toast
// and error strings built in handlers. This test is DB-free. It reads the .vue
// sources, the lib file, and the en catalogs directly. It asserts six things.
//
//   1. COMPILE GATE. Every listed .vue file compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC carries a scoped
//      style). A worktree cannot reach the Vite gate, so a compiled parse here
//      is that gate.
//   2. ADOPTION. Every listed .vue file imports useI18n.
//   3. NO RAW KEY LEAK. Every key any listed file references under any 'c_'
//      namespace resolves in that namespace's en file.
//   4. CATALOG SHAPE. Every catalog file the lane touched parses and every
//      value is a non-empty string.
//   5. NO RAW TEXT NODE OF ANY LENGTH. Walking the compiled template AST, no
//      text node with a letter remains outside t(), unless its element or an
//      ancestor carries data-no-i18n, or the node is only a citation pattern,
//      punctuation, a number or a glyph.
//   6. NO RAW USER-TEXT ATTRIBUTE. No static attribute or prop that carries
//      user text (title, placeholder, alt, aria-label, label, hint, caption,
//      and the *label*/*text*/*title*/*message* families) holds a plain string
//      with a letter on any listed file.
//
// Every page path is built from segments, never one full 'Pages/<module>/<file>'
// literal, so the NavRoleGateParityTest census note holds.
//
// The instrument self-checks first. A measure that cannot fail measures nothing.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const enDir = path.join(jsRoot, 'i18n/locales/en');

// Listed .vue files, built from segments — never a single 'Pages/.../x.vue' literal.
const VUE_PAGES = [
  ['Pages', 'Legislature', 'SessionConsole.vue'],
  ['Pages', 'Legislature', 'SpeakerTools.vue'],
  ['Pages', 'Legislature', 'BillDetail.vue'],
  ['Pages', 'Legislature', 'Chamber.vue'],
  ['Pages', 'Legislature', 'Settings.vue'],
  ['Pages', 'Legislature', 'Show.vue'],
  ['Pages', 'Legislature', 'Bills.vue'],
  ['Pages', 'Legislature', 'LiveCivicRoom.vue'],
  ['Pages', 'Legislature', 'Districts.vue'],
  ['Pages', 'Legislature', 'TypeBDistricts.vue'],
  ['Pages', 'Legislature', 'CommitteeDetail.vue'],
  ['Pages', 'Legislature', 'Committees.vue'],
  ['Pages', 'Legislature', 'Oversight.vue'],
  ['Pages', 'Legislature', 'Referendums.vue'],
  ['Pages', 'Legislature', 'Index.vue'],
  ['Components', 'Legislature', 'LegislatureWorkspaceNav.vue'],
  ['Components', 'Legislature', 'VoteTally.vue'],
];
const LIB_FILES = [['lib', 'electionKind.js']];

// The catalog files this lane writes to. Read-only namespaces the files also
// reference (c_term_sync, c_rooms shared parts) are resolved in section 3 but
// are not listed as lane-touched here.
const LANE_CATALOGS = [
  'c_legislature_workspace',
  'c_legislature_pages',
  'c_legislature_pages_b',
  'c_bill',
  'c_rooms',
  'c_institution_components',
  'c_gap_legislature',
];

function vueInfo(seg) {
  const rel = seg.join('/');
  return { rel, abs: path.join(jsRoot, ...seg) };
}
function libInfo(seg) {
  const rel = seg.join('/');
  return { rel, abs: path.join(jsRoot, ...seg) };
}

function catalogPath(ns) { return path.join(enDir, `${ns}.json`); }
function readCatalog(ns) { return JSON.parse(readFileSync(catalogPath(ns), 'utf8')); }

// A valid static dotted key tail (no concatenation prefix, no empty).
const VALID_KEY = /^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/;

// Every literal 'c_<ns>.<key>' a source references. Dynamic tails
// (t('c_x.' + k)) capture a trailing dot and fail VALID_KEY, so they are
// skipped — section 3 cannot resolve a key that is not statically known.
function referencedKeys(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_[a-z0-9_]+)\.((?:\\.|(?!\1).)*)\1(\s*\+)?/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    const ns = m[2];
    const key = m[3];
    const concatenated = !!m[4]; // a '+' after the closing quote is a dynamic tail
    if (!concatenated && VALID_KEY.test(key)) out.push({ ns, key });
  }
  return out;
}

// ── Citation / glyph exemption. A text node or attribute whose alphabetic
// content is entirely code-voice (Art. II §2, F-IND-012, CLK-06, R-03,
// WF-EXE-07, roman numerals) carries no translatable word.
function hasTranslatableWord(raw) {
  const text = raw
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ').replace(/&mdash;/g, ' ').replace(/&#\d+;/g, ' ')
    .replace(/\s+/g, ' ').trim();
  if (!text) return false;
  const stripped = text
    // form / clock / role codes: F-IND-012, WF-EXE-07, CLK-06, R-03
    .replace(/\b[A-Z]{1,4}-[A-Z0-9]+(?:-[A-Z0-9]+)*\b/g, ' ')
    // Article / section citation words and the section glyph
    .replace(/\bArts?\.?/gi, ' ')
    .replace(/§\s*\d*/g, ' ')
    // standalone roman numerals (II, IV, VII)
    .replace(/\b[IVXLCDM]{2,}\b/g, ' ');
  const words = stripped.match(/[A-Za-zÀ-ɏ]{2,}/g) || [];
  return words.length >= 1;
}

const T_ELEMENT = 1;
const T_TEXT = 2;
const T_INTERPOLATION = 5;

function hasNoI18n(node) {
  return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

// Static user-text attribute names: an exact set plus segment families.
const ATTR_EXACT = new Set([
  'title', 'placeholder', 'alt', 'aria-label', 'aria-description', 'aria-placeholder',
  'label', 'help', 'hint', 'description', 'caption', 'eyebrow',
  'submit-label', 'processing-label', 'quota-title', 'empty-text',
]);
const ATTR_SEGMENTS = new Set([
  'label', 'text', 'title', 'message', 'hint', 'caption', 'heading',
  'description', 'help', 'placeholder', 'eyebrow', 'tooltip', 'subtitle',
]);
function attrCarriesUserText(name) {
  if (ATTR_EXACT.has(name)) return true;
  return name.split('-').some((s) => ATTR_SEGMENTS.has(s));
}

// Raw text nodes with a translatable word, and raw user-text static
// attributes. Walks the AST so attribute expressions (type 7 directives) and
// data-no-i18n subtrees never enter the scan.
function templateLeaks(ast) {
  const textHits = [];
  const attrHits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_TEXT) {
      if (hasTranslatableWord(node.content ?? '')) {
        const line = node.loc?.start?.line ?? 0;
        textHits.push({ line, text: (node.content ?? '').replace(/\s+/g, ' ').trim().slice(0, 90) });
      }
      return;
    }
    if (node.type === T_INTERPOLATION) return;
    if (node.type === T_ELEMENT && hasNoI18n(node)) return;
    if (node.type === T_ELEMENT) {
      for (const p of node.props || []) {
        // type 6 == static ATTRIBUTE (no v-bind). type 7 directives are expressions.
        if (p.type === 6 && attrCarriesUserText(p.name)) {
          const val = p.value && p.value.content;
          if (val && hasTranslatableWord(val)) {
            const line = p.loc?.start?.line ?? 0;
            attrHits.push({ line, name: p.name, text: val.slice(0, 70) });
          }
        }
      }
    }
    for (const child of node.children || []) walk(child);
  };
  walk(ast);
  return { textHits, attrHits };
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys, raw text and raw attributes', () => {
  assert.equal(/useI18n/.test('const { t } = useI18n();'), true);
  assert.deepEqual(
    referencedKeys("t('c_legislature_pages.bills.title') and t(\"c_bill.record\")"),
    [{ ns: 'c_legislature_pages', key: 'bills.title' }, { ns: 'c_bill', key: 'record' }],
  );
  // dynamic tail is skipped
  assert.deepEqual(referencedKeys("t('c_legislature_workspace.' + key)"), []);
  const astOf = (tpl) => parse(`<template>${tpl}</template>`).descriptor.template.ast;
  assert.deepEqual(templateLeaks(astOf('<p>Session record</p>')).textHits.map((h) => h.text), ['Session record']);
  assert.deepEqual(templateLeaks(astOf('<p>{{ t(\'x\', \'Wired\') }}</p>')).textHits, []);
  assert.deepEqual(templateLeaks(astOf('<span data-no-i18n>Art. II §2</span>')).textHits, []);
  assert.deepEqual(templateLeaks(astOf('<span>Art. II §2</span>')).textHits, []);
  assert.deepEqual(templateLeaks(astOf('<span>§2 · R-03 F-LEG-037</span>')).textHits, []);
  assert.deepEqual(templateLeaks(astOf('<p>· → ×</p>')).textHits, []);
  assert.deepEqual(templateLeaks(astOf('<i title="Open the map">x</i>')).attrHits.map((h) => h.name), ['title']);
  assert.deepEqual(templateLeaks(astOf('<i :title="t(\'k\')">x</i>')).attrHits, []);
  assert.deepEqual(templateLeaks(astOf('<i type="submit" role="status">x</i>')).attrHits, []);
  assert.equal(VUE_PAGES.length, 17, 'the full listed .vue set');
  console.log(`  vue files: ${VUE_PAGES.length}; lib files: ${LIB_FILES.length}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every listed .vue file compiles with @vue/compiler-sfc', () => {
  const broken = [];
  for (const p of VUE_PAGES.map(vueInfo)) {
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
  console.log(`  compiled ${VUE_PAGES.length} pages, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `lane pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every listed .vue file imports useI18n', () => {
  const off = [];
  for (const p of VUE_PAGES.map(vueInfo)) {
    if (!/useI18n/.test(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
  }
  console.log(`  wired ${VUE_PAGES.length - off.length}/${VUE_PAGES.length}`);
  for (const f of off) console.log(`    unwired: ${f}`);
  assert.deepEqual(off, [], `every lane page must use i18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak (across every referenced 'c_' namespace).
test('no raw key leak — every referenced c_ key resolves in its namespace en file', () => {
  const cache = new Map();
  const have = (ns) => {
    if (!cache.has(ns)) {
      cache.set(ns, existsSync(catalogPath(ns)) ? new Set(Object.keys(readCatalog(ns))) : null);
    }
    return cache.get(ns);
  };
  const unresolved = [];
  let checked = 0;
  const files = [...VUE_PAGES.map(vueInfo), ...LIB_FILES.map(libInfo)];
  for (const p of files) {
    const src = readFileSync(p.abs, 'utf8');
    for (const { ns, key } of referencedKeys(src)) {
      checked += 1;
      const set = have(ns);
      if (!set) unresolved.push(`${p.rel}: ${ns}.json missing (key ${key})`);
      else if (!set.has(key)) unresolved.push(`${p.rel}: ${ns}.${key} absent from ${ns}.json`);
    }
  }
  console.log(`  checked ${checked} referenced keys across ${files.length} files`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — every lane catalog parses and every value is a non-empty string', () => {
  const bad = [];
  let total = 0;
  for (const ns of LANE_CATALOGS) {
    if (!existsSync(catalogPath(ns))) continue;
    const catalog = readCatalog(ns);
    for (const [k, v] of Object.entries(catalog)) {
      total += 1;
      if (typeof v !== 'string' || v.length === 0) bad.push(`${ns}.${k}`);
    }
  }
  console.log(`  lane catalog keys: ${total}, bad values: ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw text node of any length.
test('no raw text node — no text node with a letter remains outside t()', () => {
  const leaks = [];
  for (const p of VUE_PAGES.map(vueInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of templateLeaks(descriptor.template.ast).textHits) {
      leaks.push(`${p.rel}:${hit.line}: "${hit.text}"`);
    }
  }
  console.log(`  raw text-node leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw text nodes may remain: ${leaks.length}`);
});

// ── SECTION 6 — no raw user-text attribute.
test('no raw user-text attribute — no static user-text attribute holds a plain string', () => {
  const leaks = [];
  for (const p of VUE_PAGES.map(vueInfo)) {
    const src = readFileSync(p.abs, 'utf8');
    const { descriptor } = parse(src, { filename: p.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of templateLeaks(descriptor.template.ast).attrHits) {
      leaks.push(`${p.rel}:${hit.line}: ${hit.name}="${hit.text}"`);
    }
  }
  console.log(`  raw user-text attribute leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw user-text attributes may remain: ${leaks.length}`);
});
