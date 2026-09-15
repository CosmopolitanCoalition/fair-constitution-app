// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_legislature_a.test.mjs
//
// Catalogue lane pin: i18n-legislature-a (namespace c_legislature_pages).
// SOURCE pin, DB-free. It reads the wired .vue sources and the one en catalog
// directly. It asserts five things.
//
//   1. COMPILE GATE. Every lane page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped only where the SFC has a
//      scoped style). A worktree cannot reach the Vite gate, so this is it.
//   2. ADOPTION. Every lane page source contains useI18n.
//   3. NO RAW KEY LEAK. Every key referenced under 'c_legislature_pages.'
//      resolves in the en catalog. Unresolved keys are reported by page.
//   4. CATALOG SHAPE. The catalog file parses and every value is a
//      non-empty string.
//   5. NO RAW SENTENCE. No lane page holds a raw English sentence of 4+
//      words in a template text node outside t().
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing). NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals as DOM-mount companions; this is a SOURCE pin, so every page path
// is built from segments, never one literal.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PAGES_DIR = path.join('resources', 'js', 'Pages', 'Legislature'); // built from segments
const NS = 'c_legislature_pages';
const CATALOG = path.join(root, 'resources', 'js', 'i18n', 'locales', 'en', NS + '.json');

// The lane list, by base name — never a full 'Pages/...' literal.
const PAGE_FILES = ['Bills.vue', 'CommitteeDetail.vue', 'Committees.vue', 'Districts.vue', 'EmergencyPowers.vue'];
function pagePath(file) { return path.join(root, PAGES_DIR, file); }
function pageRel(file) { return PAGES_DIR.split(path.sep).join('/') + '/' + file; }

function readCatalog() {
    return JSON.parse(readFileSync(CATALOG, 'utf8'));
}

// Model the loader (resources/js/i18n/index.js): the file's contents are nested
// under the namespace taken from the FILENAME, so the runtime resolvable key is
// the namespace head + the catalog's own dotted key. A key written WITH the
// namespace baked in would double the head and never resolve. Flatten the
// catalog to the set of dotted leaf keys AS WRITTEN (flatJson:true accepts both
// a nested path and a flat dotted key), which is what a page reference resolves
// against after its own namespace head is stripped.
function catalogLeafKeys() {
    const obj = readCatalog();
    const keys = new Set();
    const walk = (node, prefix) => {
        for (const [k, v] of Object.entries(node)) {
            const key = prefix ? prefix + '.' + k : k;
            keys.add(key);
            if (v && typeof v === 'object' && !Array.isArray(v)) walk(v, key);
        }
    };
    walk(obj, '');
    return keys;
}

// Collect every t('c_legislature_pages.<key>' ...) / t("c_legislature_pages.<key>" ...)
// literal key. A key built from a static prefix plus a dynamic tail
// (t('c_legislature_pages.x.' + v)) would be counted only when the catalog
// holds every enumerated value; this lane uses no such construction, so the
// plain literal collector is exact here.
function referencedKeys(src) {
    const out = new Set();
    const re = new RegExp("t\\(\\s*['\"](" + NS + "\\.[^'\"]+)['\"]", 'g');
    let m;
    while ((m = re.exec(src)) !== null) out.add(m[1]);
    return out;
}

// Strip HTML comments and {{ }} interpolations, then scan text nodes between
// > and <. A node is a raw sentence when it holds 4+ English word tokens and
// is not a bare citation string. Runs on the template block only.
const CITATION = /^(Art\.|§|F-[A-Z]|CLK-|R-\d|WF-|ESM-|IO-)/;
function rawSentences(templateContent) {
    const region = templateContent
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/\{\{[\s\S]*?\}\}/g, ' \u0001 ');
    const re = />([^<>]+)</g;
    const out = [];
    let m;
    while ((m = re.exec(region)) !== null) {
        const txt = m[1]
            .replace(/\u0001/g, ' ')
            .replace(/&lt;|&gt;|&amp;|&mdash;|&nbsp;|&#\d+;/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (!txt) continue;
        const words = (txt.match(/[A-Za-z][A-Za-z']+/g) || []).filter((w) => w.length >= 2);
        if (words.length >= 4 && !CITATION.test(txt)) out.push(txt);
    }
    return out;
}

// SECTION 0 — instrument self-check.
test('instrument — helpers detect keys and raw sentences', () => {
    assert.deepEqual([...referencedKeys("t('c_legislature_pages.bills.title', {x:1}) and t('leaflet')")], ['c_legislature_pages.bills.title']);
    assert.deepEqual([...referencedKeys('t("c_legislature_pages.x.y", "z")')], ['c_legislature_pages.x.y']);
    assert.deepEqual(rawSentences('<p>{{ t("x") }}</p>'), []);
    assert.deepEqual(rawSentences('<p>this is four words</p>'), ['this is four words']);
    assert.deepEqual(rawSentences('<span class="citation">Art. II §2</span>'), []);
    assert.equal(PAGE_FILES.length, 5);
    console.log('  lane pages: ' + PAGE_FILES.length + ' in ' + PAGES_DIR.split(path.sep).join('/'));
});

// SECTION 1 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const file of PAGE_FILES) {
        const abs = pagePath(file);
        const src = readFileSync(abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: abs });
            if (errors && errors.length) { broken.push(pageRel(file) + ': ' + errors.map((e) => e.message).join('; ')); continue; }
            const scoped = (descriptor.styles || []).some((s) => s.scoped);
            const compiled = compileScript(descriptor, { id: pageRel(file) });
            if (descriptor.template) {
                const tpl = compileTemplate({
                    source: descriptor.template.content,
                    filename: abs,
                    id: pageRel(file),
                    scoped,
                    compilerOptions: { bindingMetadata: compiled.bindings },
                });
                if (tpl.errors && tpl.errors.length) broken.push(pageRel(file) + ' (template): ' + tpl.errors.map((e) => e.message ?? e).join('; '));
            }
        } catch (e) {
            broken.push(pageRel(file) + ': ' + e.message);
        }
    }
    console.log('  compiled ' + PAGE_FILES.length + ' pages, broken ' + broken.length);
    for (const b of broken) console.log('    ' + b);
    assert.deepEqual(broken, [], 'pages must compile: ' + broken.join(' | '));
});

// SECTION 2 — adoption.
test('adoption — every lane page source contains useI18n', () => {
    const missing = [];
    for (const file of PAGE_FILES) {
        const src = readFileSync(pagePath(file), 'utf8');
        if (!/useI18n/.test(src)) missing.push(pageRel(file));
    }
    console.log('  useI18n present in ' + (PAGE_FILES.length - missing.length) + '/' + PAGE_FILES.length);
    assert.deepEqual(missing, [], 'every lane page must import useI18n: ' + missing.join(', '));
});

// SECTION 3 — no raw key leak.
test('no raw key leak — every referenced c_legislature_pages key resolves', () => {
    const leaves = catalogLeafKeys();
    const unresolved = [];
    let checked = 0;
    for (const file of PAGE_FILES) {
        const src = readFileSync(pagePath(file), 'utf8');
        for (const key of referencedKeys(src)) {
            checked += 1;
            // Strip the namespace head the loader supplies, then resolve the
            // remainder against the catalog's own keys.
            const rest = key.slice(NS.length + 1);
            if (!leaves.has(rest)) unresolved.push(pageRel(file) + ': ' + key);
        }
    }
    console.log('  checked ' + checked + ' referenced keys, unresolved ' + unresolved.length);
    for (const u of unresolved) console.log('    ' + u);
    assert.deepEqual(unresolved, [], 'referenced keys must resolve: ' + unresolved.join(' | '));
});

// SECTION 4 — catalog shape.
test('catalog shape — parses and every value is a non-empty string', () => {
    assert.ok(existsSync(CATALOG), 'catalog exists: ' + NS + '.json');
    const catalog = readCatalog();
    const keys = Object.keys(catalog);
    assert.ok(keys.length > 0, 'catalog is non-empty');
    const bad = [];
    for (const [k, v] of Object.entries(catalog)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
        // The loader supplies the namespace from the FILENAME. A key that bakes
        // the namespace in doubles the head and never resolves at runtime.
        if (k.startsWith(NS + '.')) bad.push(k + ' (namespace baked into key)');
    }
    console.log('  catalog keys ' + keys.length + ', bad values ' + bad.length);
    assert.deepEqual(bad, [], 'catalog values must be non-empty strings with the namespace NOT baked in: ' + bad.join(', '));
});

// SECTION 5 — no raw sentence in a template text node.
test('no raw sentence — no lane page holds a raw 4+ word template text node', () => {
    const offenders = [];
    for (const file of PAGE_FILES) {
        const abs = pagePath(file);
        const { descriptor } = parse(readFileSync(abs, 'utf8'), { filename: abs });
        if (!descriptor.template) continue;
        for (const r of rawSentences(descriptor.template.content)) offenders.push(pageRel(file) + ': ' + r);
    }
    console.log('  raw template sentences ' + offenders.length);
    for (const o of offenders) console.log('    ' + o);
    assert.deepEqual(offenders, [], 'no raw 4+ word template text nodes allowed: ' + offenders.join(' | '));
});
