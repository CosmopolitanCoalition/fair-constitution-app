// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_legislature_b.test.mjs
//
// Catalogue lane pin: namespace c_legislature_pages_b.
//
// The five Legislature pages in this lane wire every user-visible body string
// through vue-i18n. This test is DB-free. It reads the .vue sources and the en
// catalog directly. It asserts five things.
//
//   1. COMPILE GATE. Every page compiles with @vue/compiler-sfc (compileScript
//      + compileTemplate), zero errors. A worktree cannot reach the Vite gate,
//      so a compiled parse here is the gate for the edits this lane makes.
//   2. ADOPTION. Every page imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_legislature_pages_b.<page>.<key>') the pages
//      reference resolves to a real en catalog entry.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty string.
//   5. NO RAW SENTENCE. No page holds a raw English sentence of 4+ words in a
//      template TEXT node outside t().
//   6. NO T-COERCION. No page concatenates a t() call after a stray unary plus
//      ("+ + t(...)"). That parses as valid JS and compiles, but the unary plus
//      coerces the translated string to NaN. A wiring transform produced this on
//      a string-concat line. The compile gate cannot see it. This pin does.
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
const NS = 'c_legislature_pages_b';
const catalogPath = path.join(jsRoot, 'i18n/locales/en', `${NS}.json`);

// Census note (NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals in tests/js as DOM-mount companions). This is a SOURCE pin, so
// every page path is built from segments, never one literal.
const MODULE_DIR = ['resources', 'js', 'Pages', 'Legislature'];
const PAGE_FILES = [
    'InstitutionActs.vue',
    'Oversight.vue',
    'Referendums.vue',
    'SessionRecord.vue',
    'TypeBDistricts.vue',
];
function pageAbs(file) { return path.join(root, ...MODULE_DIR, file); }
function pageRel(file) { return [...MODULE_DIR, file].join('/'); }

// Citation / code-voice tokens that are not ordinary English words.
const CITE = /^(Art|CLK|WF|ELB|LEG|SPK|IND|ORG|CHR|CGC|GPS|II|III|IV|VII|RCV|STV|PR|ESM|CIV|ELE|CAN|BOG|SOC|EDU|ADM|DEV|WorldPop|Alt|Esc|Droop|Gregory)$/;

function wordsIn(text) {
    return text
        .split(/[^A-Za-z'’]+/)
        .filter((w) => /[A-Za-z]{2,}/.test(w) && !CITE.test(w));
}

// Walk the parsed template AST; return TEXT-node contents (type 2). Attributes
// and interpolations are separate node types, so they are ignored natively.
function textNodes(node, out) {
    if (!node) return;
    if (node.type === 2) out.push(node.content);
    if (Array.isArray(node.children)) for (const k of node.children) textNodes(k, out);
}

// Keys referenced under this namespace, single- or double-quoted.
function referencedKeys(src) {
    const out = [];
    const re = /t\(\s*(['"])c_legislature_pages_b\.((?:[a-z0-9_]+)\.(?:[a-z0-9_]+))\1/g;
    let m;
    while ((m = re.exec(src)) !== null) out.push(m[2]);
    return out;
}

function catalogKeys() {
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    return { obj, keys: new Set(Object.keys(obj)) };
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect words, keys and text nodes', () => {
    assert.deepEqual(wordsIn('Filing requires a current seat'), ['Filing', 'requires', 'current', 'seat']);
    assert.deepEqual(wordsIn('Art. II §2 · CLK-06'), []);
    assert.deepEqual(referencedKeys("t('c_legislature_pages_b.oversight.page_title', {"), ['oversight.page_title']);
    assert.deepEqual(referencedKeys('t("c_legislature_pages_b.a.b", "x")'), ['a.b']);
    assert.ok(existsSync(catalogPath), `catalog present at ${catalogPath}`);
    console.log(`  pages: ${PAGE_FILES.length}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const file of PAGE_FILES) {
        const abs = pageAbs(file);
        const rel = pageRel(file);
        const src = readFileSync(abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: abs });
            if (errors && errors.length) { broken.push(`${rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            compileScript(descriptor, { id: rel });
            const scoped = descriptor.styles.some((s) => s.scoped);
            const tpl = compileTemplate({
                source: descriptor.template.content,
                filename: abs,
                id: rel,
                scoped,
            });
            if (tpl.errors && tpl.errors.length) broken.push(`${rel}: ${tpl.errors.join('; ')}`);
        } catch (e) {
            broken.push(`${rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${PAGE_FILES.length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every page imports useI18n', () => {
    const off = [];
    for (const file of PAGE_FILES) {
        const src = readFileSync(pageAbs(file), 'utf8');
        if (!/useI18n/.test(src)) off.push(file);
    }
    console.log(`  useI18n wired: ${PAGE_FILES.length - off.length}/${PAGE_FILES.length}`);
    assert.deepEqual(off, [], `every page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
test('no raw key leak — every referenced key resolves in the en catalog', () => {
    const { keys } = catalogKeys();
    const unresolved = [];
    let checked = 0;
    for (const file of PAGE_FILES) {
        const src = readFileSync(pageAbs(file), 'utf8');
        for (const key of referencedKeys(src)) {
            checked += 1;
            if (!keys.has(key)) unresolved.push(`${file}: ${NS}.${key} absent`);
        }
    }
    console.log(`  checked ${checked} referenced keys across ${PAGE_FILES.length} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — parses and every value is a non-empty string', () => {
    const { obj } = catalogKeys();
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    const sortedOk = JSON.stringify(Object.keys(obj)) === JSON.stringify([...Object.keys(obj)].sort());
    console.log(`  catalog keys: ${Object.keys(obj).length}, sorted: ${sortedOk}`);
    for (const b of bad) console.log(`    non-string/empty: ${b}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw sentence in a template text node.
test('no raw sentence — no 4+ word English text node outside t()', () => {
    const flagged = [];
    for (const file of PAGE_FILES) {
        const abs = pageAbs(file);
        const { descriptor } = parse(readFileSync(abs, 'utf8'), { filename: abs });
        const ast = descriptor.template?.ast;
        assert.ok(ast, `${file}: template AST available`);
        const texts = [];
        textNodes(ast, texts);
        for (const txt of texts) {
            if (wordsIn(txt).length >= 4) flagged.push(`${file}: ${txt.trim().replace(/\s+/g, ' ').slice(0, 80)}`);
        }
    }
    console.log(`  scanned template text nodes, flagged ${flagged.length}`);
    for (const f of flagged) console.log(`    ${f}`);
    assert.deepEqual(flagged, [], `raw sentences must be wired: ${flagged.join(' | ')}`);
});

// ── SECTION 6 — no unary-plus coercion of a t() call.
test('no t-coercion — no stray "+ + t(" that NaNs the translated string', () => {
    // A "+" operator, whitespace, a second "+" that reads as unary on t(...).
    const re = /\+\s+\+\s*t\s*\(/g;
    const flagged = [];
    for (const file of PAGE_FILES) {
        const src = readFileSync(pageAbs(file), 'utf8');
        let m;
        while ((m = re.exec(src)) !== null) {
            const upto = src.slice(0, m.index).split('\n').length;
            flagged.push(`${file}:${upto}: ${src.slice(m.index, m.index + 40).replace(/\s+/g, ' ')}`);
        }
    }
    console.log(`  scanned for "+ + t(" coercion, flagged ${flagged.length}`);
    for (const f of flagged) console.log(`    ${f}`);
    assert.deepEqual(flagged, [], `unary-plus must not coerce a t() call: ${flagged.join(' | ')}`);
});
