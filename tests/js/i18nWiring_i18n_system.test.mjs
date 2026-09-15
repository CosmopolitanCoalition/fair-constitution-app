// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_system.test.mjs
//
// Catalogue lane i18n-system (namespace c_system) pin. The eleven System
// pages in the lane list wire their user-visible body copy through vue-i18n
// into ONE catalog, resources/js/i18n/locales/en/c_system.json. This test is
// DB-free. It reads the .vue sources and the en catalog directly.
//
// It asserts five things.
//
//   1. COMPILE GATE. Every page in the lane list compiles with
//      @vue/compiler-sfc (compileScript + compileTemplate), scoped where the
//      SFC carries a scoped style. A worktree cannot reach the Vite gate, so a
//      compiled parse here is that gate.
//   2. ADOPTION. Every page's source imports useI18n.
//   3. NO RAW KEY LEAK. Every 'c_system.' key the pages reference resolves in
//      c_system.json. Keys are collected from t('c_system.…') / t("c_system.…").
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty string.
//   5. NO RAW SENTENCE. No page still holds a raw English sentence of 4+ words
//      in a template text node outside t() (a heuristic, see rawSentences).
//
// The census note (NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals in tests/js as DOM-mount companions): this is a SOURCE pin, so it
// builds every page path from segments, never one literal.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const localesDir = path.join(jsRoot, 'i18n/locales/en');
const NS = 'c_system';

// The lane list. Built from segments, never as one 'Pages/System/File.vue'
// literal (the census reads such literals as DOM-mount companions).
const PAGE_DIR = path.join(jsRoot, 'Pages', 'System');
const PAGE_FILES = [
    'Accessibility.vue',
    'Amendments.vue',
    'Atlas.vue',
    'AuditChain.vue',
    'Clocks.vue',
    'ConstitutionalQuestions.vue',
    'CoverageOps.vue',
    'PublicRecords.vue',
    'TermSync.vue',
    'Translations.vue',
    'Coverage.vue',
];

function pages() {
    return PAGE_FILES.map((f) => ({ file: f, rel: 'Pages/System/' + f, abs: path.join(PAGE_DIR, f) }));
}

// A scoped style block forces scoped compilation, so detect it.
function hasScopedStyle(descriptor) {
    return (descriptor.styles || []).some((s) => s.scoped);
}

// Collect every literal 'c_system.<key>' referenced by t('…') / t("…").
// All lane keys are literal (no static-prefix + dynamic-tail concatenation),
// so a literal scan is complete; a key followed by '+' would be dynamic and is
// skipped (there are none).
function referencedKeys(src) {
    const out = [];
    const re = /t\(\s*(['"])(c_system\.[^'"]+)\1\s*(.)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue; // dynamic (concatenated) — none expected
        out.push(m[2].slice(NS.length + 1)); // strip 'c_system.'
    }
    return out;
}

// Flatten the catalog to a set of dotted leaf keys (flat and nested forms).
function catalogKeys() {
    const p = path.join(localesDir, `${NS}.json`);
    if (!existsSync(p)) return null;
    const obj = JSON.parse(readFileSync(p, 'utf8'));
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

// Raw-sentence heuristic over the template block. Removes comments, <code>,
// <style>, <script>, and {{ }} interpolations, then scans the text between >
// and <. A node "holds a raw sentence" when it contains a run of 4+ consecutive
// plain-word tokens (a token starting with a letter, optionally with trailing
// punctuation). Citation/machine tokens (digits, §, ·, -, :, /, parens) break
// the run, so citation lines and machine values do not trip it.
function rawSentences(descriptor) {
    const tpl = descriptor.template;
    if (!tpl) return [];
    let s = tpl.content;
    s = s.replace(/<!--[\s\S]*?-->/g, ' ');
    s = s.replace(/<code[\s\S]*?<\/code>/gi, ' ');
    s = s.replace(/<script[\s\S]*?<\/script>/gi, ' ');
    s = s.replace(/<style[\s\S]*?<\/style>/gi, ' ');
    s = s.replace(/\{\{[\s\S]*?\}\}/g, ' '); // interpolations
    const hits = [];
    const isWord = (tok) => /^[A-Za-z][A-Za-z''’-]*[.,;:!?)'"]*$/.test(tok);
    // Text between a '>' and the next '<'.
    const re = />([^<]+)</g;
    let m;
    while ((m = re.exec(s)) !== null) {
        const chunk = m[1].replace(/\s+/g, ' ').trim();
        if (!chunk) continue;
        const toks = chunk.split(' ');
        let run = 0;
        let best = 0;
        for (const tok of toks) {
            if (isWord(tok)) { run += 1; best = Math.max(best, run); }
            else run = 0;
        }
        if (best >= 4) hits.push(chunk.slice(0, 90));
    }
    return hits;
}

// ── SECTION 0 — instrument self-check (a measure that cannot fail measures
// nothing).
test('instrument — helpers detect wiring, keys and raw sentences', () => {
    assert.equal(/useI18n/.test('const { t } = useI18n();'), true);
    assert.deepEqual(referencedKeys("t('c_system.atlas.page_title', 'x') and t('other.k')"), ['atlas.page_title']);
    assert.deepEqual(referencedKeys('t("c_system.a.b", "x")'), ['a.b']);
    // A raw four-word sentence is caught; a citation line is not.
    const fakeRaw = { template: { content: '<p>this is a sentence</p>' } };
    const fakeCite = { template: { content: '<span>WCAG 2.1.1 · 2.4.1 · 2.5.7</span>' } };
    const fakeInterp = { template: { content: '<p>{{ t(\'c_system.x.y\', \'this is a sentence\') }}</p>' } };
    assert.ok(rawSentences(fakeRaw).length >= 1, 'plain four-word sentence flagged');
    assert.deepEqual(rawSentences(fakeCite), [], 'citation line not flagged');
    assert.deepEqual(rawSentences(fakeInterp), [], 'interpolated t() not flagged');
    assert.equal(pages().length, 11, 'eleven lane pages');
    console.log(`  lane pages: ${pages().length}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const p of pages()) {
        const src = readFileSync(p.abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: p.abs });
            if (errors && errors.length) { broken.push(`${p.rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            const scoped = hasScopedStyle(descriptor);
            compileScript(descriptor, { id: p.rel });
            const tpl = compileTemplate({
                source: descriptor.template.content,
                filename: p.abs,
                id: p.rel,
                scoped,
                compilerOptions: { scopeId: scoped ? `data-v-${p.file}` : undefined },
            });
            if (tpl.errors && tpl.errors.length) broken.push(`${p.rel}: ${tpl.errors.join('; ')}`);
        } catch (e) {
            broken.push(`${p.rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${pages().length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every lane page imports useI18n', () => {
    const off = [];
    for (const p of pages()) {
        if (!/useI18n/.test(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
    }
    console.log(`  useI18n wired: ${pages().length - off.length}/${pages().length}`);
    for (const o of off) console.log(`    missing: ${o}`);
    assert.deepEqual(off, [], `every lane page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
test('no raw key leak — every referenced c_system key resolves in the catalog', () => {
    const keys = catalogKeys();
    assert.ok(keys, 'c_system.json exists');
    const unresolved = [];
    let checked = 0;
    for (const p of pages()) {
        const src = readFileSync(p.abs, 'utf8');
        for (const key of referencedKeys(src)) {
            checked += 1;
            if (!keys.has(key)) unresolved.push(`${p.rel}: c_system.${key}`);
        }
    }
    console.log(`  checked ${checked} referenced keys across ${pages().length} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.deepEqual(unresolved, [], `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — c_system.json parses and every value is a non-empty string', () => {
    const p = path.join(localesDir, `${NS}.json`);
    const obj = JSON.parse(readFileSync(p, 'utf8'));
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    console.log(`  catalog keys: ${Object.keys(obj).length}, bad values ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw sentence in template text nodes.
test('no raw sentence — no lane page holds a 4+ word raw sentence outside t()', () => {
    const offenders = [];
    for (const p of pages()) {
        const src = readFileSync(p.abs, 'utf8');
        const { descriptor } = parse(src, { filename: p.abs });
        const hits = rawSentences(descriptor);
        if (hits.length) offenders.push(`${p.rel}: ${hits.length} — e.g. "${hits[0]}"`);
    }
    console.log(`  scanned ${pages().length} pages, offenders ${offenders.length}`);
    for (const o of offenders) console.log(`    ${o}`);
    assert.deepEqual(offenders, [], `raw sentences must be wired: ${offenders.join(' | ')}`);
});
