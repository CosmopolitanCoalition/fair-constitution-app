// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_shell_components.test.mjs
//
// Catalogue lane "i18n-shell-components" pin (namespace c_shell_components).
// The shell and geodata component bodies wire through vue-i18n. This test is
// DB-free. It reads the .vue sources and the en catalog directly. It asserts:
//
//   1. COMPILE GATE. Every lane page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate), zero errors.
//   2. ADOPTION. Every lane page's source contains useI18n.
//   3. NO RAW KEY LEAK. Every literal t('c_shell_components.<key>') resolves in
//      the en catalog, and every enumerated form of each dynamic key family
//      (a static prefix plus a dynamic tail) resolves too.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty string.
//   5. NO RAW ENGLISH. No lane page holds a raw English sentence of 4+ words in
//      a template text node outside t().
//
// The census note: NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals in tests/js as DOM-mount companions. This is a SOURCE pin, so every
// page path is built from segments, never one literal.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { parse as parseDom } from '@vue/compiler-dom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const NS = 'c_shell_components';
const catalogPath = path.join(jsRoot, 'i18n/locales/en', `${NS}.json`);

// Lane pages, each built from path segments (never one 'Components/...' literal).
const COMPONENTS = 'Components';
const PAGE_SEGMENTS = [
  [COMPONENTS, 'BackgroundJobsWidget.vue'],
  [COMPONENTS, 'CosmicAddressPicker.vue'],
  [COMPONENTS, 'Geodata', 'GeodataFlagQueue.vue'],
  [COMPONENTS, 'Geodata', 'GeodataPullPanel.vue'],
  [COMPONENTS, 'Geodata', 'GeodataRepairModal.vue'],
  [COMPONENTS, 'Geodata', 'LaneStrip.vue'],
  [COMPONENTS, 'Geodata', 'ScanDetectorBars.vue'],
  [COMPONENTS, 'SchemaUpdateBanner.vue'],
  [COMPONENTS, 'SetupStepper.vue'],
  [COMPONENTS, 'Shell', 'DevBar.vue'],
  [COMPONENTS, 'Shell', 'DevPersonaSwitcher.vue'],
  [COMPONENTS, 'Shell', 'EmergencyBanner.vue'],
  [COMPONENTS, 'ShellV2', 'CmdBar.vue'],
  [COMPONENTS, 'ShellV2', 'DevAssume.vue'],
  [COMPONENTS, 'ShellV2', 'DevChamberCast.vue'],
  [COMPONENTS, 'ShellV2', 'DevClockControls.vue'],
  [COMPONENTS, 'ShellV2', 'DevPlaytestPanels.vue'],
  [COMPONENTS, 'ShellV2', 'DevScenarioPresets.vue'],
  [COMPONENTS, 'ShellV2', 'TourBar.vue'],
  [COMPONENTS, 'Surface', 'PageScaffold.vue'],
];

// Dynamic key families: a static prefix plus one dynamic tail from an
// enumerated set. Every enumerated form must resolve in the catalog.
const DYN_FAMILIES = {
  'cosmic_address_picker.level_': ['observable_universe', 'supercluster',
    'galaxy_group', 'galaxy', 'galactic_region', 'star_system', 'world'],
  'setup_stepper.step_': ['0', '1', '2', '3', '4', '5', '6'],
  'geodata_flag_queue.cat_': ['dual_coverage', 'mis_anchored_cluster',
    'same_space_chain', 'raster_coverage', 'displaced_geometry', 'orphaned_rows',
    'stray_synthetic', 'national_delta_gt5'],
  'geodata_flag_queue.action_': ['accept_flag', 'synthesize_anchor', 'merge_chain',
    'reparent', 'recompute_population', 'prune', 'reimport_boundaries'],
  'geodata_pull_panel.phase_': ['enumerating', 'boundaries', 'rasters', 'resolving',
    'attribution', 'finalizing', 'scanning'],
  'geodata_pull_panel.level_': ['1', '2', '3', '4', '5', '6'],
  'geodata_repair_modal.title_': ['accept_flag', 'reparent', 'synthesize_anchor',
    'merge_chain', 'recompute_population', 'prune'],
  'dev_assume.role_': ['R-04', 'R-06', 'R-08', 'R-09', 'R-10', 'R-19', 'R-20', 'R-21'],
};

function pageAbs(segs) {
  return path.join(jsRoot, ...segs);
}
function pageRel(segs) {
  return segs.join('/');
}
function readCatalog() {
  return JSON.parse(readFileSync(catalogPath, 'utf8'));
}

// Literal t('c_shell_components.<key>' ...) keys, quote ' or ". Backtick keys
// are dynamic (template literals) and handled by DYN_FAMILIES, not here.
function literalKeysIn(src) {
  const out = [];
  const re = /\bt\(\s*(['"])c_shell_components\.((?:(?!\1).)+?)\1/g;
  let m;
  while ((m = re.exec(src)) !== null) out.push(m[2]);
  return out;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers and lane roster are sane', () => {
  assert.equal(PAGE_SEGMENTS.length, 20, 'the lane holds 20 pages');
  assert.deepEqual(
    literalKeysIn("t('c_shell_components.a.b', 'X') and t('leaflet')"),
    ['a.b'],
  );
  assert.deepEqual(
    literalKeysIn('t("c_shell_components.c.d", { n: 1 })'),
    ['c.d'],
  );
  // A backtick dynamic key is not a literal.
  assert.deepEqual(literalKeysIn('t(`c_shell_components.e.f_${x}`, y)'), []);
});

// ── SECTION 2 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
  const broken = [];
  for (const segs of PAGE_SEGMENTS) {
    const rel = pageRel(segs);
    const src = readFileSync(pageAbs(segs), 'utf8');
    try {
      const { descriptor, errors } = parse(src, { filename: rel });
      if (errors && errors.length) {
        broken.push(`${rel}: ${errors.map((e) => e.message).join('; ')}`);
        continue;
      }
      const scoped = descriptor.styles.some((s) => s.scoped);
      const script = compileScript(descriptor, { id: rel });
      if (descriptor.template) {
        const r = compileTemplate({
          source: descriptor.template.content,
          filename: rel,
          id: rel,
          scoped,
          compilerOptions: { bindingMetadata: script.bindings },
        });
        if (r.errors && r.errors.length) {
          broken.push(`${rel}: ${r.errors.map((e) => (e.message || e)).join('; ')}`);
        }
      }
    } catch (e) {
      broken.push(`${rel}: ${e.message}`);
    }
  }
  console.log(`  compiled ${PAGE_SEGMENTS.length} pages, broken ${broken.length}`);
  for (const b of broken) console.log(`    ${b}`);
  assert.deepEqual(broken, [], `pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption.
test('adoption — every lane page source contains useI18n', () => {
  const off = [];
  for (const segs of PAGE_SEGMENTS) {
    const src = readFileSync(pageAbs(segs), 'utf8');
    if (!/useI18n/.test(src)) off.push(pageRel(segs));
  }
  console.log(`  useI18n present: ${PAGE_SEGMENTS.length - off.length}/${PAGE_SEGMENTS.length}`);
  for (const f of off) console.log(`    missing: ${f}`);
  assert.deepEqual(off, [], `every lane page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog — parses and every value is a non-empty string', () => {
  const cat = readCatalog();
  const bad = [];
  for (const [k, v] of Object.entries(cat)) {
    if (typeof v !== 'string' || v.length === 0) bad.push(k);
  }
  console.log(`  catalog keys: ${Object.keys(cat).length}, bad values: ${bad.length}`);
  for (const b of bad) console.log(`    ${b}`);
  assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw key leak (literal keys + dynamic families).
test('no raw key leak — every referenced key resolves in the catalog', () => {
  const cat = readCatalog();
  const keys = new Set(Object.keys(cat));
  const unresolved = [];
  let checked = 0;

  for (const segs of PAGE_SEGMENTS) {
    const src = readFileSync(pageAbs(segs), 'utf8');
    for (const key of literalKeysIn(src)) {
      checked += 1;
      if (!keys.has(key)) unresolved.push(`${pageRel(segs)}: ${key}`);
    }
  }
  // Dynamic families: every enumerated form present.
  for (const [prefix, tails] of Object.entries(DYN_FAMILIES)) {
    for (const tail of tails) {
      checked += 1;
      const key = `${prefix}${tail}`;
      if (!keys.has(key)) unresolved.push(`dynamic: ${key}`);
    }
  }

  console.log(`  checked ${checked} keys, unresolved ${unresolved.length}`);
  for (const u of unresolved) console.log(`    ${u}`);
  assert.deepEqual(unresolved, [], `keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 6 — no raw English sentence in a template text node.
// Real text nodes come from the compiler AST, so attribute expressions (a `>`
// inside `?? 0) > 0"`), comments and interpolations never leak in. A text node
// with 4+ real English words is a leak. Single words, numbers, punctuation and
// citation tokens (Art., §, F-*, CLK-*, R-*, WF-*, ESM-*) are allowed.
const CITATION = /^(Art\.?|§[\dIVX]*|F-[A-Z0-9-]+|CLK-[0-9]+|R-[0-9]+|WF-[A-Z0-9-]+|ESM-[0-9]+)$/;
const NODE_TEXT = 2; // @vue/compiler-core NodeTypes.TEXT

function collectTextNodes(node, acc) {
  if (!node) return;
  if (node.type === NODE_TEXT && typeof node.content === 'string') acc.push(node.content);
  const kids = node.children;
  if (Array.isArray(kids)) for (const k of kids) collectTextNodes(k, acc);
}

function rawEnglishRuns(templateSrc) {
  const ast = parseDom(templateSrc, { comments: false });
  const texts = [];
  collectTextNodes(ast, texts);
  const runs = [];
  for (const raw of texts) {
    const text = raw.replace(/&[a-zA-Z]+;/g, ' ');
    const words = text
      .split(/[^A-Za-z-]+/)
      .filter((w) => /[A-Za-z]{2,}/.test(w) && !CITATION.test(w));
    if (words.length >= 4) runs.push(text.trim().replace(/\s+/g, ' '));
  }
  return runs;
}

test('instrument — raw-English heuristic detects and ignores correctly', () => {
  assert.equal(rawEnglishRuns('<p>These are four raw words</p>').length, 1);
  assert.equal(rawEnglishRuns('<p>{{ t("x.y", "These are four raw words") }}</p>').length, 0);
  assert.equal(rawEnglishRuns('<span>· F-JDG-007</span>').length, 0);
  assert.equal(rawEnglishRuns('<span>Menu</span>').length, 0);
});

test('no raw English — no lane page holds a 4+ word raw sentence in the template', () => {
  const leaks = [];
  for (const segs of PAGE_SEGMENTS) {
    const src = readFileSync(pageAbs(segs), 'utf8');
    const { descriptor } = parse(src, { filename: pageRel(segs) });
    if (!descriptor.template) continue;
    const runs = rawEnglishRuns(descriptor.template.content);
    for (const r of runs) leaks.push(`${pageRel(segs)}: "${r}"`);
  }
  console.log(`  raw-English runs found: ${leaks.length}`);
  for (const l of leaks) console.log(`    ${l}`);
  assert.deepEqual(leaks, [], `template text must be wired: ${leaks.join(' | ')}`);
});
