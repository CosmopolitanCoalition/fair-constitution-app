// node --experimental-vm-modules --test tests/js/i18nGaps_gap_system.test.mjs
//
// Gap lane gap-system pin. The 2026-09-14 catalogue campaign wired template
// sentences of four or more words. This lane closes what it missed on the
// System pages and the Geodata operator tooling: short labels of one to three
// words, full sentences the campaign never listed, text glued around {{ }}
// values, static attributes and props carrying user text, and error strings
// built in handlers. The pages wire through vue-i18n against the c_system and
// c_shell_components namespaces.
//
// This test is DB-free. It reads the .vue sources and the en catalogs directly
// and asserts seven things.
//
//   0. INSTRUMENT SELF-CHECK. A measure that cannot fail measures nothing.
//   1. COMPILE GATE. Every listed page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC carries a
//      scoped style). A worktree cannot reach the Vite gate, so a compiled
//      parse here is that gate.
//   2. ADOPTION. Every listed page imports useI18n.
//   3. NO RAW KEY LEAK. Every c_ key a page references (quoted literal)
//      resolves in that namespace's en file.
//   4. CATALOG SHAPE. Every catalog the lane touched parses and every value
//      is a non-empty string.
//   5. NO RAW TEXT NODE OF ANY LENGTH. Walking the compiled template AST, no
//      text node with a letter remains outside t() unless its element or an
//      ancestor carries data-no-i18n, or the node is only a citation pattern,
//      punctuation, a number or a glyph.
//   6. NO RAW USER-TEXT ATTRIBUTE. No static attribute or user-text prop
//      holds a plain letterful string on any listed file.
//
// Page paths are built from segments, never one full 'Pages/<module>/<file>'
// literal (the NavRoleGateParityTest census note).
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const localeDir = path.join(jsRoot, 'i18n/locales/en');

// The lane's touched catalogs (rule 4).
const TOUCHED_NS = ['c_system', 'c_shell_components'];

// Listed files, each as path segments under resources/js.
const FILES = [
  ['Pages', 'System', 'TranslationReview.vue'],
  ['Pages', 'System', 'Translations.vue'],
  ['Pages', 'System', 'Accessibility.vue'],
  ['Pages', 'System', 'Amendments.vue'],
  ['Pages', 'System', 'AuditChain.vue'],
  ['Pages', 'System', 'ConstitutionalQuestions.vue'],
  ['Pages', 'System', 'Coverage.vue'],
  ['Pages', 'System', 'PublicRecords.vue'],
  ['Components', 'Geodata', 'GeodataPullPanel.vue'],
  ['Components', 'Geodata', 'ScanDetectorBars.vue'],
];

function fileInfo(segments) {
  return { rel: segments.join('/'), abs: path.join(jsRoot, ...segments) };
}

function catalogPath(ns) {
  return path.join(localeDir, `${ns}.json`);
}

// Every c_ key a source references through a quoted-literal t() call. Backtick
// (template-literal) keys are dynamic families and are checked by presence of
// their concrete keys elsewhere, not here — the same choice the institutions
// pin makes.
function referencedKeys(src) {
  const out = [];
  const re = /\bt\(\s*(['"])(c_[a-z_]+\.(?:\\.|(?!\1).)*)\1/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    const full = m[2];
    const dot = full.indexOf('.');
    out.push({ ns: full.slice(0, dot), key: full.slice(dot + 1) });
  }
  return out;
}

// Node types from the compiler AST.
const T_ROOT = 0;
const T_ELEMENT = 1;
const T_TEXT = 2;
const T_INTERPOLATION = 5;

// Citation / code-voice tokens that may stand alone in a text node without
// wiring (Art. II §2, WCAG 2.1.1, F-LEG-037, CLK-08, R-03, WF-SYS-03, a
// formula). A node is citation-only when nothing but such tokens, digits,
// punctuation and glyphs remain.
const CITE_TOKEN = /\b(?:WCAG|UAX|EN|ADM|ISO|GPS|HTTP|STV|RCV|PR|CGC|Art|nav|hash|payload)\b|\bF-[A-Z]{2,5}-?\d*\b|\b(?:CLK|WF|ESM|BOG|CHR|LEG|IND|ORG|ELB|SOC|EDU|CAN|GOV|COM)-[A-Z0-9-]+\b|\bR-\d+\b|§/gi;

function decode(raw) {
  return raw
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ').replace(/&#\d+;/g, ' ')
    .replace(/\s+/g, ' ').trim();
}

// Returns the flagged snippet when raw text carries an ordinary word, else null.
// An ordinary word is a run of two or more letters that survives removing the
// citation tokens. Single letters (unit tokens like L, m, s), numbers and
// glyphs never flag.
function rawText(raw) {
  const text = decode(raw);
  if (!text) return null;
  const residue = text.replace(CITE_TOKEN, ' ');
  const words = residue.match(/[A-Za-zÀ-ɏ]{2,}/g) || [];
  return words.length ? text.slice(0, 100) : null;
}

function hasNoI18n(node) {
  return node.type === T_ELEMENT
    && (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

function rawTextNodes(ast) {
  const hits = [];
  const walk = (node, underNo) => {
    if (!node) return;
    if (node.type === T_TEXT) {
      if (!underNo) {
        const hit = rawText(node.content ?? '');
        if (hit) hits.push(hit);
      }
      return;
    }
    if (node.type === T_INTERPOLATION) return;
    const no = underNo || hasNoI18n(node);
    for (const child of node.children || []) walk(child, no);
  };
  walk(ast, false);
  return hits;
}

// Attributes and props that carry user text (rule 6b). The explicit set plus a
// wildcard on names containing label/text/title/message. ARIA carries only
// aria-label and aria-description — aria-labelledby and friends hold id
// references, not text.
const EXPLICIT_ATTR = new Set([
  'title', 'placeholder', 'alt', 'label', 'help', 'hint', 'description',
  'caption', 'eyebrow', 'submit-label', 'processing-label', 'quota-title',
  'empty-text', 'aria-label', 'aria-description',
]);

function isUserTextAttr(name) {
  if (EXPLICIT_ATTR.has(name)) return true;
  if (name.startsWith('aria-')) return name === 'aria-label' || name === 'aria-description';
  return /(label|text|title|message)/.test(name);
}

// A static attribute value, or a bound directive whose expression is a plain
// quoted string literal (no t()), carrying an ordinary word, is raw.
function rawAttrs(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_ELEMENT) {
      for (const p of node.props || []) {
        if (p.type === 6 && isUserTextAttr(p.name)) {
          const v = p.value && p.value.content;
          if (v && rawText(v)) hits.push(`${p.name}="${v.slice(0, 60)}"`);
        } else if (p.type === 7 && p.name === 'bind' && p.arg && p.exp) {
          const an = p.arg.content || '';
          const ex = p.exp.content.trim();
          if (isUserTextAttr(an) && /^(['"]).*\1$/.test(ex) && !/\bt\(/.test(ex) && rawText(ex)) {
            hits.push(`:${an}="${ex.slice(0, 60)}"`);
          }
        }
      }
    }
    for (const child of node.children || []) walk(child);
  };
  walk(ast);
  return hits;
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect keys, raw text and raw attrs', () => {
  assert.deepEqual(
    referencedKeys("t('c_system.translation_review.a') and t(\"c_shell_components.b.c\")"),
    [{ ns: 'c_system', key: 'translation_review.a' }, { ns: 'c_shell_components', key: 'b.c' }],
  );
  assert.deepEqual(referencedKeys("t(`c_shell_components.geodata_pull_panel.phase_${p.key}`)"), []);
  const astOf = (tpl) => parse(`<template>${tpl}</template>`).descriptor.template.ast;
  assert.deepEqual(rawTextNodes(astOf('<p>This is raw label text</p>')), ['This is raw label text']);
  assert.deepEqual(rawTextNodes(astOf('<p>Right</p>')), ['Right']);
  assert.deepEqual(rawTextNodes(astOf("<p>{{ t('x', 'Wired') }}</p>")), []);
  assert.deepEqual(rawTextNodes(astOf('<span data-no-i18n>syncs on federation now</span>')), []);
  assert.deepEqual(rawTextNodes(astOf('<span class="citation">WCAG 2.1.1 · 2.4.7</span>')), []);
  assert.deepEqual(rawTextNodes(astOf('<code>hash(n) = H(hash(n−1) ∥ payload(n))</code>')), []);
  assert.deepEqual(rawTextNodes(astOf('<td>L{{ n }} · {{ x }}%</td>')), []);
  assert.deepEqual(rawAttrs(astOf('<C title="By content type" />')), ['title="By content type"']);
  assert.deepEqual(rawAttrs(astOf('<C :title="t(\'x\', \'y\')" />')), []);
  assert.deepEqual(rawAttrs(astOf('<C :title="row.name" />')), []);
  assert.equal(FILES.length, 10, 'the full lane file set');
  for (const ns of TOUCHED_NS) assert.ok(existsSync(catalogPath(ns)), `${ns}.json exists`);
  console.log(`  files: ${FILES.length}; touched catalogs: ${TOUCHED_NS.join(', ')}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every listed file compiles with @vue/compiler-sfc', () => {
  const broken = [];
  for (const f of FILES.map(fileInfo)) {
    const src = readFileSync(f.abs, 'utf8');
    try {
      const { descriptor, errors } = parse(src, { filename: f.abs });
      if (errors && errors.length) { broken.push(`${f.rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
      compileScript(descriptor, { id: f.rel });
      if (descriptor.template) {
        const scoped = descriptor.styles.some((st) => st.scoped);
        const r = compileTemplate({
          source: descriptor.template.content,
          filename: f.abs,
          id: f.rel,
          scoped,
          compilerOptions: scoped ? { scopeId: 'data-v-gapsys' } : {},
        });
        if (r.errors && r.errors.length) broken.push(`${f.rel} (template): ${r.errors.map((e) => (e.message || e)).join('; ')}`);
      }
    } catch (e) {
      broken.push(`${f.rel}: ${e.message}`);
    }
  }
  console.log(`  compiled ${FILES.length} files, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `listed files must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every listed file imports useI18n', () => {
  const off = [];
  for (const f of FILES.map(fileInfo)) {
    if (!/useI18n/.test(readFileSync(f.abs, 'utf8'))) off.push(f.rel);
  }
  console.log(`  wired ${FILES.length - off.length}/${FILES.length}`);
  for (const x of off) console.log(`    unwired: ${x}`);
  assert.deepEqual(off, [], `every listed file must use i18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
test('no raw key leak — every referenced c_ key resolves in its namespace', () => {
  const catalogs = {};
  const load = (ns) => {
    if (!(ns in catalogs)) {
      catalogs[ns] = existsSync(catalogPath(ns)) ? JSON.parse(readFileSync(catalogPath(ns), 'utf8')) : null;
    }
    return catalogs[ns];
  };
  const unresolved = [];
  let checked = 0;
  for (const f of FILES.map(fileInfo)) {
    const src = readFileSync(f.abs, 'utf8');
    for (const { ns, key } of referencedKeys(src)) {
      checked += 1;
      const c = load(ns);
      if (!c) { unresolved.push(`${f.rel}: no catalog ${ns}.json`); continue; }
      if (!(key in c)) unresolved.push(`${f.rel}: ${ns}.${key} absent from ${ns}.json`);
    }
  }
  console.log(`  checked ${checked} referenced keys across ${FILES.length} files`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — touched catalogs parse and every value is a non-empty string', () => {
  const bad = [];
  for (const ns of TOUCHED_NS) {
    const catalog = JSON.parse(readFileSync(catalogPath(ns), 'utf8'));
    let n = 0;
    for (const [k, v] of Object.entries(catalog)) {
      n += 1;
      if (typeof v !== 'string' || v.length === 0) bad.push(`${ns}.${k}`);
    }
    console.log(`  ${ns}: ${n} keys`);
  }
  for (const b of bad) console.log(`    bad: ${b}`);
  assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw text node of any length.
test('no raw text — no letterful text node remains outside t()', () => {
  const leaks = [];
  for (const f of FILES.map(fileInfo)) {
    const src = readFileSync(f.abs, 'utf8');
    const { descriptor } = parse(src, { filename: f.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawTextNodes(descriptor.template.ast)) leaks.push(`${f.rel}: "${hit}"`);
  }
  console.log(`  raw text leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw text nodes may remain: ${leaks.join(' | ')}`);
});

// ── SECTION 6 — no raw user-text attribute.
test('no raw user-text attribute — no static attr or prop holds a plain string', () => {
  const leaks = [];
  for (const f of FILES.map(fileInfo)) {
    const src = readFileSync(f.abs, 'utf8');
    const { descriptor } = parse(src, { filename: f.abs });
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawAttrs(descriptor.template.ast)) leaks.push(`${f.rel}: ${hit}`);
  }
  console.log(`  raw attribute leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw user-text attributes may remain: ${leaks.join(' | ')}`);
});
