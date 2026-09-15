// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_jurisdictions.test.mjs
//
// Catalogue-lane pin for lane i18n-jurisdictions (namespace c_jurisdictions).
// This is a SOURCE pin, not a DOM mount. It reads the .vue sources and the
// en catalog directly. It asserts five things.
//
//   1. COMPILE GATE. Every page in the lane list compiles with
//      @vue/compiler-sfc (compileScript with inlineTemplate, so the template
//      compiles too; scoped styles are handled by the shared id). A worktree
//      cannot reach the Vite gate, so a compiled parse here is the gate.
//   2. ADOPTION. Every page source imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_jurisdictions.<key>') the pages reference
//      resolves in c_jurisdictions.json.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty string.
//   5. NO RAW SENTENCE. No page holds a raw English sentence of four or more
//      words in a template text node outside t(). The scan walks the compiled
//      template AST (text nodes only), so attribute expressions never leak in,
//      and skips code / script / style / data-no-i18n subtrees.
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing).
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const localesDir = path.join(jsRoot, 'i18n/locales/en');
const NS = 'c_jurisdictions';

// The lane list, built from segments (never one 'Pages/<module>/<file>.vue'
// literal) so the NavRoleGateParityTest census never reads a mount companion
// here.
const PAGES = [
    ['Build', 'Progress.vue'],
    ['Jurisdictions', 'BetweenGovernments.vue'],
    ['Jurisdictions', 'Bootstrap.vue'],
    ['Jurisdictions', 'Disintermediation.vue'],
    ['Jurisdictions', 'Federation.vue'],
    ['Jurisdictions', 'Index.vue'],
    ['Jurisdictions', 'Restoration.vue'],
    ['Jurisdictions', 'Show.vue'],
    ['Jurisdictions', 'UnionFormation.vue'],
];

function pageInfo(seg) {
    const rel = ['Pages', seg[0], seg[1]].join('/');
    return { rel, abs: path.join(jsRoot, 'Pages', seg[0], seg[1]) };
}

function catalogKeys() {
    const p = path.join(localesDir, `${NS}.json`);
    if (!existsSync(p)) return null;
    const obj = JSON.parse(readFileSync(p, 'utf8'));
    return { obj, keys: new Set(Object.keys(obj)) };
}

// Collect every t('c_jurisdictions.<key>') / t("c_jurisdictions.<key>") the
// source references. A key built by concatenation (next char '+') is dynamic;
// this lane wired none, so such a form is skipped.
function referencedKeys(src) {
    const out = [];
    const re = /t\(\s*(['"])c_jurisdictions\.([^'"]+)\1\s*([^\s)])/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue; // dynamic (concatenated) — not this lane
        out.push(m[2]);
    }
    return out;
}

// Walk the compiled template AST and return every literal text node, skipping
// the subtrees of code / script / style elements and any element marked
// data-no-i18n. Node types (compiler-core): 1 ELEMENT, 2 TEXT, 3 COMMENT,
// 5 INTERPOLATION.
const SKIP_TAGS = new Set(['code', 'script', 'style', 'pre']);
function collectText(node, out) {
    if (!node) return;
    if (node.type === 2) { // TEXT
        if (node.content && node.content.trim()) out.push(node.content);
        return;
    }
    if (node.type === 1) { // ELEMENT
        if (SKIP_TAGS.has(node.tag)) return;
        const noI18n = (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
        if (noI18n) return;
    }
    for (const child of node.children || []) collectText(child, out);
}

function templateTextNodes(descriptor) {
    const ast = descriptor.template && descriptor.template.ast;
    if (!ast) return [];
    const out = [];
    collectText(ast, out);
    return out;
}

// A citation-ish token that never needs translation.
const CITATION = /^(Art\.|F-[A-Z]|CLK-|R-\d|WF-|ESM-|FE-|WI-\d|ADM)/;

// Count the alphabetic word tokens in a text node, ignoring numbers,
// punctuation and citation code voice. Four or more English words is a raw
// sentence.
function rawWordCount(node) {
    let s = node.replace(/Art\.\s*[IVXLC]+(\s*§\s*\d+)?/g, ' ');
    s = s.replace(/§\s*\d+/g, ' ');
    const tokens = s.split(/[^A-Za-z']+/).filter((w) => {
        const t = w.replace(/'/g, '');
        return t.length >= 2 && /[A-Za-z]/.test(t) && !CITATION.test(w);
    });
    return tokens.length;
}

function compileOne(abs, rel) {
    const src = readFileSync(abs, 'utf8');
    const { descriptor, errors } = parse(src, { filename: abs });
    if (errors && errors.length) throw new Error(errors.map((e) => e.message).join('; '));
    compileScript(descriptor, { id: rel, inlineTemplate: true });
    return descriptor;
}

test('instrument — helpers behave', () => {
    assert.equal(/useI18n/.test('const { t } = useI18n();'), true);
    assert.deepEqual(referencedKeys("t('c_jurisdictions.a.b', 'x') and t('leaflet')"), ['a.b']);
    assert.deepEqual(referencedKeys("t('c_jurisdictions.x.' + s, {})"), []);
    assert.ok(rawWordCount('This is a raw sentence here') >= 4);
    assert.equal(rawWordCount('Art. II §2'), 0);
    assert.ok(rawWordCount('· tier') < 4);
    assert.equal(PAGES.length, 9);
    console.log(`  lane pages: ${PAGES.length}`);
});

test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const seg of PAGES) {
        const { rel, abs } = pageInfo(seg);
        try {
            compileOne(abs, rel);
        } catch (e) {
            broken.push(`${rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${PAGES.length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `pages must compile: ${broken.join(' | ')}`);
});

test('adoption — every lane page imports useI18n', () => {
    const missing = [];
    for (const seg of PAGES) {
        const { rel, abs } = pageInfo(seg);
        if (!/useI18n/.test(readFileSync(abs, 'utf8'))) missing.push(rel);
    }
    console.log(`  useI18n present: ${PAGES.length - missing.length}/${PAGES.length}`);
    assert.deepEqual(missing, [], `pages must import useI18n: ${missing.join(', ')}`);
});

test('no raw key leak — every referenced c_jurisdictions key resolves', () => {
    const cat = catalogKeys();
    assert.ok(cat, `${NS}.json exists`);
    const unresolved = [];
    let checked = 0;
    for (const seg of PAGES) {
        const { rel, abs } = pageInfo(seg);
        const src = readFileSync(abs, 'utf8');
        for (const key of referencedKeys(src)) {
            checked += 1;
            if (!cat.keys.has(key)) unresolved.push(`${rel}: ${key}`);
        }
    }
    console.log(`  checked ${checked} referenced keys, unresolved ${unresolved.length}`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `keys must resolve: ${unresolved.join(' | ')}`);
});

test('catalog shape — parses, every value a non-empty string', () => {
    const cat = catalogKeys();
    assert.ok(cat, `${NS}.json exists`);
    const bad = [];
    for (const [k, v] of Object.entries(cat.obj)) {
        if (typeof v !== 'string' || v.trim() === '') bad.push(k);
    }
    console.log(`  catalog keys: ${cat.keys.size}, bad values ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

test('no raw sentence — no lane page holds a 4+ word raw template text node', () => {
    const leaks = [];
    for (const seg of PAGES) {
        const { rel, abs } = pageInfo(seg);
        const descriptor = compileOne(abs, rel);
        for (const node of templateTextNodes(descriptor)) {
            if (rawWordCount(node) >= 4) leaks.push(`${rel}: ${node.trim().replace(/\s+/g, ' ').slice(0, 80)}`);
        }
    }
    console.log(`  raw sentences found: ${leaks.length}`);
    for (const l of leaks) console.log(`    ${l}`);
    assert.deepEqual(leaks, [], `raw template sentences remain: ${leaks.join(' | ')}`);
});
