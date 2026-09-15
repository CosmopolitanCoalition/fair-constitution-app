// node --experimental-vm-modules --test tests/js/i18nGaps_gap_civic_social.test.mjs
//
// Gap lane gap-civic-social pin. The 2026-09-14 catalogue campaign wired
// template sentences of 4+ words; the 2026-09-15 gap sweep wires what it
// missed on these files: short labels, glued fragments, static user-text
// attributes, script label maps, toast/error strings, and the LiveRoom
// error.<code> family. This test is DB-free. It reads the .vue and .js
// sources and the en catalogs directly.
//
//   1. COMPILE GATE. Every listed .vue file compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC has a scoped
//      style). The .js composable is parsed as a module source (no template).
//   2. ADOPTION. Every listed .vue file imports useI18n.
//   3. NO RAW KEY LEAK. Every c_<ns> key a file references — via a literal
//      t('c_ns.key') OR via a per-file text() helper bound to one c_<ns> —
//      resolves in that namespace's en json.
//   4. CATALOG SHAPE. Every catalog the lane touched parses and every value
//      is a non-empty string.
//   5. NO RAW TEXT NODE OF ANY LENGTH. Walking the compiled template AST, no
//      text node with a letter remains outside t()/{{ }} unless its element
//      or an ancestor carries data-no-i18n, or the node is only a citation
//      pattern, punctuation, a number, a machine token, or a glyph.
//   6. NO RAW USER-TEXT ATTRIBUTE. No static attribute or prop that carries
//      user text (title, placeholder, alt, aria-label, aria-description,
//      label, help, hint, description, caption, eyebrow, submit-label,
//      processing-label, quota-title, empty-text, or any *label*/*text*/
//      *title*/*message* prop) holds a plain string with a letter.
//
// Page paths are built from segments (the census note): never a single
// 'Pages/<module>/<file>.vue' literal.
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
const localeDir = path.join(jsRoot, 'i18n/locales/en');

// File paths built from segments — never a single literal.
const FILES = [
  ['Pages', 'Social', 'PersonProfile.vue'],
  ['Pages', 'Social', 'Achievements.vue'],
  ['Pages', 'Civic', 'MyRecord.vue'],
  ['Pages', 'Civic', 'MatrixCommons.vue'],
  ['Pages', 'Civic', 'Petitions.vue'],
  ['Pages', 'Civic', 'PetitionDetail.vue'],
  ['Pages', 'Civic', 'Relocation.vue'],
  ['Pages', 'Civic', 'Halls.vue'],
  ['Pages', 'Civic', 'Home.vue'],
  ['Pages', 'Civic', 'IdentityVerification.vue'],
  ['Pages', 'Civic', 'Residency.vue'],
  ['Pages', 'Invite', 'Landing.vue'],
  ['Pages', 'Rooms', 'Institution.vue'],
  ['Pages', 'Learn', 'LearnHome.vue'],
  ['Pages', 'Learn', 'Lesson.vue'],
  ['Pages', 'Auth', 'Register.vue'],
  ['Components', 'Shell', 'EmergencyBanner.vue'],
  ['Components', 'Civic', 'Room', 'LiveRoom.vue'],
  ['composables', 'useRoomParticipants.js'],
];

function fileInfo(segments) {
  const rel = segments.join('/');
  return { rel, abs: path.join(jsRoot, ...segments), isVue: rel.endsWith('.vue') };
}

const catalogCache = new Map();
function catalog(ns) {
  if (!catalogCache.has(ns)) {
    const p = path.join(localeDir, `${ns}.json`);
    catalogCache.set(ns, existsSync(p) ? JSON.parse(readFileSync(p, 'utf8')) : null);
  }
  return catalogCache.get(ns);
}

// Every (ns, key) a source references: literal t('c_ns.key') plus, when the
// file declares a helper `const NAME = (...) => t('c_ns.' + ...)`, every
// NAME('literalkey') call resolved against that ns.
function referencedKeys(src) {
  const out = [];
  const litRe = /\bt\(\s*(['"])(c_[a-z0-9_]+)\.((?:\\.|(?!\1).)*?)\1/g;
  let m;
  while ((m = litRe.exec(src)) !== null) {
    if (m[3]) out.push([m[2], m[3]]);
  }
  const helperDecl = /const\s+(\w+)\s*=\s*\([^)]*\)\s*=>\s*t\(\s*'(c_[a-z0-9_]+)\.'\s*\+/g;
  let h;
  while ((h = helperDecl.exec(src)) !== null) {
    const name = h[1];
    const ns = h[2];
    const callRe = new RegExp(`\\b${name}\\(\\s*(['"])((?:\\\\.|(?!\\1).)*?)\\1\\s*[,)]`, 'g');
    let c;
    while ((c = callRe.exec(src)) !== null) {
      const key = c[2];
      if (key && !key.includes('+')) out.push([ns, key]);
    }
  }
  return out;
}

// ── citation / machine-token / glyph classification for the raw-text scan.
const T_TEXT = 2;
const T_INTERPOLATION = 5;
const T_ELEMENT = 1;

function tokenIsCitationish(tok) {
  const t = tok.replace(/^[^0-9A-Za-zÀ-ɏ§#%]+|[^0-9A-Za-zÀ-ɏ§#%]+$/g, '');
  if (!t) return true;
  if (/^[0-9]+$/.test(t)) return true; // a number
  if (/^[·—–\-•→§#%/]+$/.test(t)) return true; // glyph run
  if (/^§[\d–\-]*$/.test(t)) return true; // section marks (§6, §1–2)
  if (/^Art\.?$/.test(t)) return true; // Article
  if (/^[IVXLCDM]+$/.test(t)) return true; // Roman numeral (Art. II, Art. V)
  // form / clock / role / doctrine refs: F-JDG-007, WF-ELE-07, CLK-05, R-03,
  // CI-1, PI-6, EO-5, ESM-01
  if (/^[A-Z]{1,4}(-[A-Z0-9]+)+$/.test(t)) return true;
  // snake_case machine token (residency_confirmation_days, location_pings)
  if (/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/.test(t)) return true;
  return false;
}

function textHasRealWords(raw) {
  const text = raw
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ').replace(/&#\d+;/g, ' ')
    .replace(/\s+/g, ' ').trim();
  if (!text) return false;
  if (!/[A-Za-zÀ-ɏ]/.test(text)) return false; // no letters at all
  // Every whitespace token must be citation-ish / glyph / number, else real.
  for (const tok of text.split(/\s+/)) {
    if (!tokenIsCitationish(tok)) return true;
  }
  return false;
}

function hasNoI18n(node) {
  return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

function rawTextLeaks(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_TEXT) {
      if (textHasRealWords(node.content ?? '')) hits.push((node.content ?? '').trim().slice(0, 100));
      return;
    }
    if (node.type === T_INTERPOLATION) return;
    if (node.type === T_ELEMENT && hasNoI18n(node)) return;
    for (const child of node.children || []) walk(child);
  };
  walk(ast);
  return hits;
}

// ── static user-text attribute scan.
const TEXT_ATTRS = new Set([
  'title', 'placeholder', 'alt', 'aria-label', 'aria-description', 'label',
  'help', 'hint', 'description', 'caption', 'eyebrow', 'submit-label',
  'processing-label', 'quota-title', 'empty-text',
]);
function isUserTextAttr(name) {
  if (TEXT_ATTRS.has(name)) return true;
  return /(^|-)(label|text|title|message)(-|$)/.test(name);
}

function rawAttrLeaks(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node) return;
    if (node.type === T_ELEMENT) {
      if (hasNoI18n(node)) return;
      for (const p of node.props || []) {
        if (p.type === 6 && p.value && isUserTextAttr(p.name)) {
          const v = p.value.content ?? '';
          if (textHasRealWords(v)) hits.push(`${node.tag} ${p.name}="${v.slice(0, 60)}"`);
        }
      }
    }
    for (const child of node.children || []) walk(child);
  };
  walk(ast);
  return hits;
}

// ── glued separator-fragment scan (the 2026-09-15 review finding).
// A continuation FRAGMENT is a t() value that begins with a bare separator
// glyph (— – · • |) and no leading space. Its own template leading space is
// edge-stripped by condense, so when it renders directly after a preceding
// inline sibling the separator glues to that sibling ("recordX— detail",
// "seated D1· term ends D2"). Such a fragment must carry its OWN leading
// space. This walks the CONDENSED SFC AST (parse defaults to whitespace
// 'condense') and flags any glued, space-less separator fragment.
const SEP_START = /^[—–·•|]/; // starts with a separator glyph, no leading space
const INLINE_TAGS = new Set([
  'template', 'span', 'a', 'em', 'strong', 'b', 'i', 'small', 'code', 'abbr',
  'time', 'sup', 'sub', 'mark', 'label', 'u', 's', 'q',
]);

// t('c_ns.key', …) -> { ns, key, value } resolved against the en catalog.
function tCallValue(expr) {
  const m = /^\s*t\(\s*(['"])(c_[a-z0-9_]+)\.((?:\\.|(?!\1).)*?)\1/.exec(expr || '');
  if (!m || !m[3]) return null;
  const cat = catalog(m[2]);
  if (!cat || !(m[3] in cat)) return null;
  return { ns: m[2], key: m[3], value: cat[m[3]] };
}

// The first rendered t() fragment a node contributes, descending only through
// inline / template wrappers (a block element starts a new line, not glue).
function leadingFragment(node) {
  if (!node || node.type === 3) return null;
  if (node.type === T_INTERPOLATION) return tCallValue(node.content?.content ?? '');
  if (node.type === T_ELEMENT) {
    if (hasNoI18n(node) || !INLINE_TAGS.has(node.tag)) return null;
    for (const c of node.children || []) {
      if (c.type === 3) continue;
      return leadingFragment(c);
    }
  }
  return null;
}

// Does prev render inline right before cur with no trailing space to separate?
function gluesForward(prev) {
  if (!prev) return false;
  if (prev.type === T_INTERPOLATION) return true;
  if (prev.type === T_TEXT) return !/\s$/.test(prev.content ?? '');
  if (prev.type === T_ELEMENT) return INLINE_TAGS.has(prev.tag);
  return false;
}

function gluedSeparatorLeaks(ast) {
  const hits = [];
  const walk = (node) => {
    if (!node || node.type === 3) return;
    if (node.type === T_ELEMENT && hasNoI18n(node)) return;
    const kids = (node.children || []).filter((c) => c.type !== 3);
    for (let i = 0; i < kids.length; i += 1) {
      const cur = kids[i];
      let frag = null;
      if (cur.type === T_INTERPOLATION) frag = tCallValue(cur.content?.content ?? '');
      else if (cur.type === T_ELEMENT && INLINE_TAGS.has(cur.tag) && !hasNoI18n(cur)) frag = leadingFragment(cur);
      if (frag && typeof frag.value === 'string' && SEP_START.test(frag.value) && gluesForward(kids[i - 1])) {
        hits.push(`${frag.ns}.${frag.key} = ${JSON.stringify(frag.value)}`);
      }
    }
    for (const c of kids) walk(c);
  };
  walk(ast);
  return hits;
}

function parseVue(abs, rel) {
  const src = readFileSync(abs, 'utf8');
  const { descriptor, errors } = parse(src, { filename: abs });
  if (errors && errors.length) throw new Error(`${rel}: ${errors.map((e) => e.message).join('; ')}`);
  return descriptor;
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys, raw text and raw attrs', () => {
  assert.equal(/useI18n/.test('const { t } = useI18n();'), true);
  assert.deepEqual(referencedKeys("t('c_civic.a.b') and t(\"c_rooms.c.d\")"), [['c_civic', 'a.b'], ['c_rooms', 'c.d']]);
  assert.deepEqual(referencedKeys("$t('places.x')"), []);
  assert.deepEqual(
    referencedKeys("const text = (key, fb) => t('c_rooms.' + key, fb);\n text('floor_open', 'x') text('error.' + code, y)"),
    [['c_rooms', 'floor_open']],
  );
  const astOf = (tpl) => parse(`<template>${tpl}</template>`).descriptor.template.ast;
  assert.deepEqual(rawTextLeaks(astOf('<p>Follow</p>')), ['Follow']);
  assert.deepEqual(rawTextLeaks(astOf('<p>A raw sentence here</p>')), ['A raw sentence here']);
  assert.deepEqual(rawTextLeaks(astOf("<p>{{ t('x', 'wired') }}</p>")), []);
  assert.deepEqual(rawTextLeaks(astOf('<span data-no-i18n>Art. I hardened</span>')), []);
  assert.deepEqual(rawTextLeaks(astOf('<p>Art. II §6 · CLK-17</p>')), []); // citation-only
  assert.deepEqual(rawTextLeaks(astOf('<p>· F-JDG-007</p>')), []); // form ref only
  assert.deepEqual(rawTextLeaks(astOf('<p>residency_confirmation_days · CLK-05</p>')), []); // machine + clock
  assert.deepEqual(rawTextLeaks(astOf('<p :label="a > 1 ? b : c">{{ x }}</p>')), []);
  assert.deepEqual(rawAttrLeaks(astOf('<input placeholder="Type a name" />')), ['input placeholder="Type a name"']);
  assert.deepEqual(rawAttrLeaks(astOf('<Card title="At a glance" />')), ['Card title="At a glance"']);
  assert.deepEqual(rawAttrLeaks(astOf('<Card :title="t(\'x\',\'y\')" />')), []);
  assert.deepEqual(rawAttrLeaks(astOf('<input type="text" name="handle" />')), []);
  // glued separator-fragment self-check (a seeded probe catalog).
  catalogCache.set('c_probe', {
    'a.record': 'Candidacy record for {display}',
    'a.glued': '· term ends {date}',
    'a.spaced': ' · term ends {date}',
    'a.plain': 'seated {date}',
  });
  const gcOf = (tpl) => gluedSeparatorLeaks(astOf(tpl));
  // interp then adjacent template fragment that starts with a bare glyph -> flagged.
  assert.deepEqual(
    gcOf("<p>{{ t('c_probe.a.record', {display}) }}<template v-if=\"r\"> {{ t('c_probe.a.glued', {date}) }}</template>.</p>"),
    ['c_probe.a.glued = "· term ends {date}"'],
  );
  // two adjacent v-if templates, second starts with a bare glyph -> flagged.
  assert.deepEqual(
    gcOf("<p><template v-if=\"s\">{{ t('c_probe.a.plain', {date}) }}</template>\n<template v-if=\"u\"> {{ t('c_probe.a.glued', {date}) }}</template></p>"),
    ['c_probe.a.glued = "· term ends {date}"'],
  );
  // same fragment WITH a leading space -> clean.
  assert.deepEqual(
    gcOf("<p>{{ t('c_probe.a.record', {display}) }}<template v-if=\"r\"> {{ t('c_probe.a.spaced', {date}) }}</template></p>"),
    [],
  );
  // preceding text already ends in a space -> not glued.
  assert.deepEqual(gcOf("<p>seated <template v-if=\"u\">{{ t('c_probe.a.glued', {date}) }}</template></p>"), []);
  catalogCache.delete('c_probe');
  assert.equal(FILES.length, 19, 'the full lane file set');
  console.log(`  lane files: ${FILES.length}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every listed .vue file compiles with @vue/compiler-sfc', () => {
  const broken = [];
  for (const f of FILES.map(fileInfo)) {
    if (!f.isVue) {
      // parse the .js composable as a module (no template).
      try { readFileSync(f.abs, 'utf8'); } catch (e) { broken.push(`${f.rel}: ${e.message}`); }
      continue;
    }
    try {
      const descriptor = parseVue(f.abs, f.rel);
      compileScript(descriptor, { id: f.rel });
      if (descriptor.template) {
        const scoped = descriptor.styles.some((st) => st.scoped);
        const r = compileTemplate({
          source: descriptor.template.content,
          filename: f.abs,
          id: f.rel,
          scoped,
          compilerOptions: scoped ? { scopeId: 'data-v-gcs' } : {},
        });
        if (r.errors && r.errors.length) broken.push(`${f.rel} (template): ${r.errors.map((e) => (e.message || e)).join('; ')}`);
      }
    } catch (e) {
      broken.push(`${f.rel}: ${e.message}`);
    }
  }
  console.log(`  compiled ${FILES.length} files, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `lane files must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption (.vue only).
test('adoption — every listed .vue file imports useI18n', () => {
  const off = [];
  for (const f of FILES.map(fileInfo)) {
    if (!f.isVue) continue;
    if (!/useI18n/.test(readFileSync(f.abs, 'utf8'))) off.push(f.rel);
  }
  console.log(`  wired ${off.length === 0 ? 'all' : ''}`);
  assert.deepEqual(off, [], `every listed .vue file must use i18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak (any c_ namespace).
test('no raw key leak — every referenced c_ key resolves in its namespace', () => {
  const unresolved = [];
  let checked = 0;
  for (const f of FILES.map(fileInfo)) {
    const src = readFileSync(f.abs, 'utf8');
    for (const [ns, key] of referencedKeys(src)) {
      checked += 1;
      const cat = catalog(ns);
      if (!cat) { unresolved.push(`${f.rel}: ${ns}.json absent`); continue; }
      if (!(key in cat)) unresolved.push(`${f.rel}: ${ns}.${key} absent from ${ns}.json`);
    }
  }
  console.log(`  checked ${checked} referenced keys`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape (every catalog the lane touched).
test('catalog shape — parses and every value is a non-empty string', () => {
  const touched = ['c_gap_civic_social', 'c_civic', 'c_live_commons', 'c_learn', 'c_rooms'];
  const bad = [];
  for (const ns of touched) {
    const cat = catalog(ns);
    assert.ok(cat, `${ns}.json exists`);
    for (const [k, v] of Object.entries(cat)) {
      if (typeof v !== 'string' || v.length === 0) bad.push(`${ns}.${k}`);
    }
  }
  console.log(`  touched catalogs: ${touched.length}, bad values: ${bad.length}`);
  assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw text node of any length.
test('no raw text node — no letter-bearing text node remains outside t()', () => {
  const leaks = [];
  for (const f of FILES.map(fileInfo)) {
    if (!f.isVue) continue;
    const descriptor = parseVue(f.abs, f.rel);
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawTextLeaks(descriptor.template.ast)) leaks.push(`${f.rel}: "${hit}"`);
  }
  console.log(`  raw text leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw text nodes may remain: ${leaks.join(' | ')}`);
});

// ── SECTION 6 — no raw user-text attribute.
test('no raw user-text attribute — no static user-text attr holds a plain string', () => {
  const leaks = [];
  for (const f of FILES.map(fileInfo)) {
    if (!f.isVue) continue;
    const descriptor = parseVue(f.abs, f.rel);
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of rawAttrLeaks(descriptor.template.ast)) leaks.push(`${f.rel}: ${hit}`);
  }
  console.log(`  raw attr leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `no raw user-text attributes may remain: ${leaks.join(' | ')}`);
});

// ── SECTION 7 — no glued separator-fragment (the 2026-09-15 review finding).
test('no glued separator fragment — a continuation fragment carries its own leading space', () => {
  const leaks = [];
  for (const f of FILES.map(fileInfo)) {
    if (!f.isVue) continue;
    const descriptor = parseVue(f.abs, f.rel);
    if (!descriptor.template || !descriptor.template.ast) continue;
    for (const hit of gluedSeparatorLeaks(descriptor.template.ast)) leaks.push(`${f.rel}: ${hit}`);
  }
  console.log(`  glued separator leaks: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `separator fragments must carry a leading space: ${leaks.join(' | ')}`);
});
