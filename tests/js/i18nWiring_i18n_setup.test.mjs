// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_setup.test.mjs
//
// Catalogue lane "i18n-setup" pin (namespace c_setup). The eleven Setup wizard
// pages wire their user-visible body copy through vue-i18n. This test is
// DB-free: it reads the .vue sources and the en catalog directly. It asserts:
//
//   1. INSTRUMENT self-check — the helpers detect wiring, keys and raw text.
//   2. COMPILE GATE — every page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC declares a
//      scoped style). A worktree cannot reach the Vite gate, so a compiled
//      parse here is the gate for these edits.
//   3. ADOPTION — every page's source imports useI18n.
//   4. NO RAW KEY LEAK — every key a page references under 'c_setup.' resolves
//      in resources/js/i18n/locales/en/c_setup.json (report unresolved by page).
//   5. CATALOG SHAPE — the catalog parses and every value is a non-empty string.
//   6. NO RAW ENGLISH — no page holds a raw English sentence of 4+ words in a
//      template text node outside t() (heuristic; interpolations, script, style
//      and citation patterns are ignored).
//
// The census note (NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals as DOM-mount companions): this is a SOURCE pin, so every page path
// is built from segments, never one string literal.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { parse as parseTemplateDom } from '@vue/compiler-dom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const catalogPath = path.join(jsRoot, 'i18n/locales/en/c_setup.json');
const NS = 'c_setup';

// The lane's page list, files built from segments (never a full literal).
const PAGE_DIR = ['Pages', 'Setup'];
const PAGE_FILES = [
    'Bootstrap.vue',
    'ModeFork.vue',
    'JoinHost.vue',
    'OperatorSetup.vue',
    'Step0_CosmicAddress.vue',
    'Step1_Constants.vue',
    'Step2_MapData.vue',
    'Step3_Districts.vue',
    'Step4_ScaleUp.vue',
    'Step5_Simulate.vue',
    'Step6_Confirm.vue',
];

function pages() {
    return PAGE_FILES.map((file) => ({
        file,
        rel: PAGE_DIR.join('/') + '/' + file,
        abs: path.join(jsRoot, ...PAGE_DIR, file),
    }));
}

function usesI18n(src) {
    return /useI18n/.test(src);
}

// Flatten a catalog to its set of dotted leaf keys. flatJson:true means
// vue-i18n resolves both a nested path and a flat dotted key, so accept both.
function catalogKeys() {
    if (!existsSync(catalogPath)) return null;
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const keys = new Set();
    const walk = (node, prefix) => {
        for (const [k, v] of Object.entries(node)) {
            const key = prefix ? `${prefix}.${k}` : k;
            keys.add(key);
            if (v && typeof v === 'object' && !Array.isArray(v)) walk(v, key);
        }
    };
    walk(obj, '');
    return keys;
}

// Every t('c_setup.<key>' ...) / t("c_setup.<key>" ...) literal a page uses.
// A key built by concatenation (t('c_setup.x.' + tail, ...)) is dynamic: the
// char after the closing quote is '+', so it is skipped — its concrete forms
// are pinned against the catalog directly. This lane emits no such keys.
function referencedKeys(src) {
    const out = [];
    const re = /\bt\(\s*(['"])((?:c_setup)\.[^'"]+)\1\s*(.?)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue; // dynamic (concatenated) tail
        out.push(m[2]);
    }
    return out;
}

// Strip the leading namespace segment to get the catalog-relative key.
function catalogRelative(fullKey) {
    const dot = fullKey.indexOf('.');
    return { ns: fullKey.slice(0, dot), rest: fullKey.slice(dot + 1) };
}

// Raw-English heuristic over template TEXT NODES only. The template is parsed
// to an AST (@vue/compiler-dom), so attribute values (a v-html string carries
// the wired English), {{ }} interpolations, and comments are never scanned —
// only real text children. A text node is flagged when it holds 4+ word tokens
// (2+ letters each) and is not a bare constitutional citation.
// NodeTypes: 1 ELEMENT, 2 TEXT, 3 COMMENT, 5 INTERPOLATION.
const CITATION = /^(?:Art\.|§|F-[A-Z]|CLK-|R-\d|WF-|ESM-|WCAG)/;
function textNodes(node, out) {
    if (!node) return out;
    if (node.type === 2 && typeof node.content === 'string') out.push(node.content);
    for (const child of node.children || []) textNodes(child, out);
    // <template> element bodies live under .children too; branch/slot nodes
    // carry their content in .children as well, so the walk above suffices.
    return out;
}
function rawEnglish(templateSrc) {
    const ast = parseTemplateDom(templateSrc, { comments: false });
    const flagged = [];
    for (const raw of textNodes(ast, [])) {
        const text = raw.replace(/\s+/g, ' ').trim();
        if (!text) continue;
        if (CITATION.test(text)) continue;
        const words = text.match(/[A-Za-z]{2,}/g) || [];
        if (words.length >= 4) flagged.push(text.slice(0, 80));
    }
    return flagged;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect wiring, keys and raw text', () => {
    assert.equal(usesI18n("const { t } = useI18n();"), true);
    assert.equal(usesI18n("<h1>Plain</h1>"), false);
    assert.deepEqual(
        referencedKeys("t('c_setup.bootstrap.page_title', 'Set up this node') and t('other.x')"),
        ['c_setup.bootstrap.page_title'],
    );
    assert.deepEqual(referencedKeys("t('c_setup.x.' + tail, {})"), []);
    assert.deepEqual(rawEnglish('<p>{{ t(\'c_setup.x.y\', \'Four real English words here\') }}</p>'), []);
    assert.deepEqual(rawEnglish('<p>Four real English words here</p>'), ['Four real English words here']);
    assert.deepEqual(rawEnglish('<span>Art. II §2</span>'), []);
    const ps = pages();
    assert.equal(ps.length, 11, `expected the 11 Setup pages, got ${ps.length}`);
    for (const p of ps) assert.ok(existsSync(p.abs), `missing page ${p.rel}`);
    console.log(`  pages: ${ps.length}`);
});

// ── SECTION 2 — compile gate.
test('compile gate — every page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const p of pages()) {
        const src = readFileSync(p.abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: p.abs });
            if (errors && errors.length) { broken.push(`${p.rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            compileScript(descriptor, { id: p.rel });
            if (descriptor.template) {
                const scoped = (descriptor.styles || []).some((s) => s.scoped);
                const r = compileTemplate({
                    source: descriptor.template.content,
                    filename: p.abs,
                    id: p.rel,
                    scoped,
                });
                if (r.errors && r.errors.length) {
                    broken.push(`${p.rel}: ${r.errors.map((e) => (e.message || e)).join('; ')}`);
                }
            }
        } catch (e) {
            broken.push(`${p.rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${pages().length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption.
test('adoption — every page imports useI18n', () => {
    const off = [];
    for (const p of pages()) if (!usesI18n(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
    console.log(`  wired ${pages().length - off.length}/${pages().length}`);
    for (const o of off) console.log(`    unwired: ${o}`);
    assert.deepEqual(off, [], `every page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 4 — no raw key leak.
test('no raw key leak — every referenced c_setup key resolves in the en catalog', () => {
    const keys = catalogKeys();
    assert.ok(keys, 'c_setup.json exists');
    const unresolved = [];
    let checked = 0;
    for (const p of pages()) {
        const src = readFileSync(p.abs, 'utf8');
        for (const full of referencedKeys(src)) {
            checked += 1;
            const { ns, rest } = catalogRelative(full);
            if (ns !== NS) { unresolved.push(`${p.rel}: ${full} not under ${NS}`); continue; }
            if (!keys.has(rest)) unresolved.push(`${p.rel}: ${full} absent from c_setup.json`);
        }
    }
    console.log(`  checked ${checked} referenced keys across ${pages().length} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 5 — catalog shape.
test('catalog shape — c_setup.json parses and every value is a non-empty string', () => {
    const raw = readFileSync(catalogPath, 'utf8');
    const obj = JSON.parse(raw);
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    console.log(`  catalog keys: ${Object.keys(obj).length}, bad values: ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 6 — no raw English left in a template text node.
test('no raw English — no page leaves a 4+ word sentence outside t()', () => {
    const offenders = [];
    for (const p of pages()) {
        const src = readFileSync(p.abs, 'utf8');
        const { descriptor } = parse(src, { filename: p.abs });
        if (!descriptor.template) continue;
        const hits = rawEnglish(descriptor.template.content);
        if (hits.length) offenders.push(`${p.rel}: ${hits.length} — e.g. "${hits[0]}"`);
    }
    console.log(`  scanned ${pages().length} templates, offenders ${offenders.length}`);
    for (const o of offenders) console.log(`    ${o}`);
    assert.deepEqual(offenders, [], `raw English must be wired: ${offenders.join(' | ')}`);
});
