// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_ui_b.test.mjs
//
// Catalogue lane pin for namespace c_ui_b (Components/Ui set B). This test is
// a SOURCE pin. It reads the .vue sources and the one en catalog directly. No
// DB, no Vite. It asserts:
//
//   1. COMPILE GATE. Every lane component compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC carries a
//      scoped style). Zero errors.
//   2. COMPOSITION. Every component that references a c_ui_b key imports
//      useI18n. A thin wrapper with no user text is not forced to.
//   3. NO RAW KEY LEAK. Every t('c_ui_b.<key>') the components reference
//      resolves in c_ui_b.json.
//   4. CATALOG SHAPE. c_ui_b.json parses and every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No component holds a raw English sentence of 4+ words
//      in a template text node outside t().
//
// The instrument self-checks first. A measure that cannot fail measures
// nothing. Page paths are built from segments, never one full literal, so the
// NavRoleGateParityTest census never mounts these source strings as pages.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const NS = 'c_ui_b';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const UI_DIR = ['resources', 'js', 'Components', 'Ui'];
const catalogPath = path.join(jsRoot, 'i18n', 'locales', 'en', `${NS}.json`);

// The lane list, base names only. Absolute paths are built from segments.
const COMPONENTS = [
    'LawDiff.vue', 'LifecycleTracker.vue', 'LogRow.vue', 'OrgChip.vue',
    'PlannedBanner.vue', 'RadioGroup.vue', 'SelectionIdentity.vue', 'Stat.vue',
    'StateStrip.vue', 'StatusBadge.vue', 'Stepper.vue', 'TagChip.vue',
    'ThresholdMeter.vue',
];

function absOf(file) {
    return path.join(root, ...UI_DIR, file);
}

function usesI18n(src) {
    return /useI18n/.test(src) || /\$t\(/.test(src);
}

// Every literal t('c_ui_b.…') and t("c_ui_b.…") key referenced in a source.
// Keys built by concatenation (a static prefix plus a dynamic tail) are only
// accepted when the catalog holds every enumerated value; this lane wires no
// such family, so a concatenated head after the prefix is reported as
// unresolved rather than silently passed.
function referencedKeys(src) {
    const out = [];
    const re = /\$?t\(\s*(['"])(c_ui_b\.[^'"]+)\1/g;
    let m;
    while ((m = re.exec(src)) !== null) out.push(m[2]);
    return out;
}

// Flatten the catalog to a set of flat dotted keys (vue-i18n flatJson resolves
// both a nested path and a flat dotted key).
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

// Template text between > and <, with {{ }} interpolations, <script> and
// <style> removed. Returns raw chunks for the raw-sentence scan.
function templateTextChunks(templateContent) {
    const noInterp = templateContent.replace(/\{\{[\s\S]*?\}\}/g, ' ');
    const chunks = [];
    const re = />([^<]*)</g;
    let m;
    while ((m = re.exec(noInterp)) !== null) {
        const text = m[1];
        if (text && text.trim()) chunks.push(text.trim());
    }
    return chunks;
}

// A word token contains letters. A citation-style token (Art., §2, II,
// F-IND-011, CLK-06, R-03) is not counted as an English word.
const CITATION = /^(Art\.?|§\S*|[IVXLC]+|[A-Z]-[A-Z0-9-]+|[A-Z]{2,3}-\d+|R-\d+|CLK-\d+)$/;
function englishWordCount(chunk) {
    const tokens = chunk.split(/\s+/).filter((tk) => tk.length);
    let words = 0;
    for (const tk of tokens) {
        if (CITATION.test(tk)) continue;
        if (/[A-Za-z]/.test(tk)) words += 1;
    }
    return words;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect wiring, keys and raw sentences', () => {
    assert.equal(usesI18n("const { t } = useI18n();"), true);
    assert.equal(usesI18n("<span>Plain</span>"), false);
    assert.deepEqual(
        referencedKeys("t('c_ui_b.a.b', 'x') and t(\"c_ui_b.c.d\", { n: 1 }) and t('other.e')"),
        ['c_ui_b.a.b', 'c_ui_b.c.d'],
    );
    assert.equal(englishWordCount('This part of the world'), 5);
    assert.equal(englishWordCount('Art. II §2'), 0);
    assert.equal(englishWordCount('removed:'), 1);
    assert.equal(COMPONENTS.length, 13, 'the full lane list');
    console.log(`  lane components: ${COMPONENTS.length}`);
});

// ── SECTION 2 — compile gate (Vite substitute for the worktree).
test('compile gate — every lane component compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const file of COMPONENTS) {
        const abs = absOf(file);
        const src = readFileSync(abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: abs });
            if (errors && errors.length) { broken.push(`${file}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            compileScript(descriptor, { id: file });
            if (descriptor.template) {
                const scoped = descriptor.styles.some((s) => s.scoped);
                const r = compileTemplate({ id: file, source: descriptor.template.content, scoped, filename: abs });
                if (r.errors && r.errors.length) { broken.push(`${file}: ${r.errors.join('; ')}`); continue; }
            }
        } catch (e) {
            broken.push(`${file}: ${e.message}`);
        }
    }
    console.log(`  compiled ${COMPONENTS.length} components, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `components must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — composition present on every component that references a key.
test('composition — every component that references a c_ui_b key imports useI18n', () => {
    const missing = [];
    let wired = 0;
    for (const file of COMPONENTS) {
        const src = readFileSync(absOf(file), 'utf8');
        const keys = referencedKeys(src);
        if (keys.length === 0) continue;
        wired += 1;
        if (!usesI18n(src)) missing.push(`${file}: references ${keys.length} c_ui_b keys but no useI18n`);
    }
    console.log(`  components referencing c_ui_b keys: ${wired}`);
    assert.ok(wired >= 1, 'at least one component wires c_ui_b');
    for (const m of missing) console.log(`    ${m}`);
    assert.deepEqual(missing, [], `keyed components must import useI18n: ${missing.join(' | ')}`);
});

// ── SECTION 4 — no raw key leak. Every referenced key resolves.
test('no raw key leak — every referenced c_ui_b key resolves in the catalog', () => {
    const keys = catalogKeys();
    assert.ok(keys, `${NS}.json exists`);
    const unresolved = [];
    let checked = 0;
    for (const file of COMPONENTS) {
        const src = readFileSync(absOf(file), 'utf8');
        for (const full of referencedKeys(src)) {
            checked += 1;
            const rest = full.slice(NS.length + 1); // drop "c_ui_b."
            if (!keys.has(rest)) unresolved.push(`${file}: ${full} absent from ${NS}.json`);
        }
    }
    console.log(`  checked ${checked} referenced keys`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 5 — catalog shape.
test('catalog shape — c_ui_b.json parses and every value is a non-empty string', () => {
    assert.ok(existsSync(catalogPath), `${NS}.json exists`);
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    const sorted = Object.keys(obj);
    const expected = [...sorted].sort();
    console.log(`  catalog keys: ${sorted.length}`);
    for (const k of bad) console.log(`    non-string/empty: ${k}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
    assert.deepEqual(sorted, expected, 'catalog keys are sorted');
});

// ── SECTION 6 — no raw English sentence left in a template text node.
test('no raw sentence — no component holds a 4+ word English text node outside t()', () => {
    const offenders = [];
    for (const file of COMPONENTS) {
        const abs = absOf(file);
        const { descriptor } = parse(readFileSync(abs, 'utf8'), { filename: abs });
        if (!descriptor.template) continue;
        for (const chunk of templateTextChunks(descriptor.template.content)) {
            if (englishWordCount(chunk) >= 4) offenders.push(`${file}: "${chunk}"`);
        }
    }
    console.log(`  raw-sentence offenders: ${offenders.length}`);
    for (const o of offenders) console.log(`    ${o}`);
    assert.deepEqual(offenders, [], `raw English sentences must be wired: ${offenders.join(' | ')}`);
});
