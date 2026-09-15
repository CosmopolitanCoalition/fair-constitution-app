// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_operator.test.mjs
//
// SOURCE PIN for the i18n-operator catalogue lane (namespace c_operator_pages).
// The operator/dev/demo console pages wire their body copy through vue-i18n.
// This test is DB-free. It reads the .vue sources and the en catalog directly.
//
// It asserts five things:
//   1. COMPILE GATE. Every page in the lane list compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate). A worktree cannot reach the Vite gate,
//      so a compiled parse here is the gate for the edits this lane makes.
//   2. ADOPTION. Every wired page (all but the thin compatibility wrapper)
//      imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_operator_pages.<key>') the pages reference
//      resolves to a real en catalog entry.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty string.
//   5. NO RAW SENTENCE. No page holds a raw English sentence of 4+ words in a
//      template text node outside t() (a heuristic, tuned below).
//
// The census note (NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals as DOM-mount companions): this is a SOURCE pin, so every page path is
// built from segments, never one string literal.
//
// The instrument self-checks first (a measure that cannot fail measures nothing).
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { baseParse } from '@vue/compiler-dom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const NS = 'c_operator_pages';
const catalogPath = path.join(jsRoot, 'i18n/locales/en', `${NS}.json`);

// The lane's pages, each as [dirSegments, file] so no full page literal appears.
const PAGES = [
    [['Pages', 'Demo'], 'SimConsole.vue'],
    [['Pages', 'Dev'], 'ElectoralKit.vue'],
    [['Pages', 'Dev'], 'ExecutiveOrgKit.vue'],
    [['Pages', 'Dev'], 'JudiciaryKit.vue'],
    [['Pages', 'Dev'], 'LegislatureKit.vue'],
    [['Pages', 'Operator'], 'Console.vue'],
    [['Pages', 'Operator'], 'Dns.vue'],
    [['Pages', 'Operator'], 'Identity.vue'],
    [['Pages', 'Operator'], 'Mesh.vue'],
    [['Pages', 'Operator'], 'Moderation.vue'],
    [['Pages', 'Operator'], 'Operations.vue'],
    [['Pages', 'Operator'], 'Roles.vue'],
    [['Pages', 'Operator'], 'Versioning.vue'],
];

// Console.vue is a compatibility component (it re-exports Home.vue) with no user
// text of its own — a legitimate SKIP for the useI18n and raw-sentence checks.
const WRAPPERS = new Set(['Console.vue']);

function pageAbs([dir, file]) {
    return path.join(jsRoot, ...dir, file);
}
function pageRel([dir, file]) {
    return [...dir, file].join('/');
}

// Flatten the catalog to a set of dotted leaf keys. flatJson resolves both a
// nested path and a flat dotted key, so accept both forms.
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

// Every t('c_operator_pages.<...>') / t("c_operator_pages.<...>") key. A key
// built by concatenation (t('c_operator_pages.x.' + tail, ...)) is dynamic: the
// char after the closing quote is '+', so skip it here (its concrete forms are
// pinned by the catalog directly).
function wiredKeysIn(src) {
    const out = [];
    const re = /\bt\(\s*(['"])(c_operator_pages\.[^'"]+)\1\s*(.)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue;
        out.push(m[2]);
    }
    return out;
}

// ── heuristic: raw English sentence detection in a template. ──
// Walk the real template AST (attribute values carry '>' and '<' — a regex
// text-node scan cannot tell them from element boundaries, so use the parser).
// A TEXT node with 4+ alphabetic words reads as an untranslated sentence.
// Interpolations ({{ t(...) }}) are INTERPOLATION nodes, never TEXT, so they
// are skipped for free. Element subtrees marked data-no-i18n, and the code
// voice tags (code/pre/script/style), are not scanned.
const SKIP_TAGS = new Set(['code', 'pre', 'script', 'style']);
function hasNoI18n(node) {
    return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}
function rawSentences(templateContent) {
    const ast = baseParse(templateContent);
    const offenders = [];
    const walk = (node) => {
        if (node.type === 2) {
            // TEXT
            const text = node.content.replace(/\s+/g, ' ').trim();
            const words = text.match(/[A-Za-z][A-Za-z'’]+/g) || [];
            if (words.length >= 4) offenders.push(text);
            return;
        }
        if (node.type === 1) {
            // ELEMENT
            if (SKIP_TAGS.has(node.tag) || hasNoI18n(node)) return;
        }
        for (const c of node.children || []) walk(c);
    };
    walk(ast);
    return offenders;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers behave', () => {
    assert.deepEqual(
        wiredKeysIn("t('c_operator_pages.dns.set', 'Set') and t('leaflet')"),
        ['c_operator_pages.dns.set'],
    );
    assert.deepEqual(wiredKeysIn("t('c_operator_pages.x.' + tail, {})"), []);
    assert.equal(rawSentences('<p>This is a raw sentence here</p>').length, 1);
    assert.equal(rawSentences('<p>{{ t(\'c_operator_pages.x.y\', \'This is fine\') }}</p>').length, 0);
    assert.equal(rawSentences('<code>python3 scripts/ops/infra_supervisor.py</code>').length, 0);
    assert.equal(rawSentences('<p data-no-i18n>fixture example text here now</p>').length, 0);
    assert.equal(PAGES.length, 13, 'the full lane list');
    console.log(`  lane pages: ${PAGES.length}`);
});

// ── SECTION 2 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const p of PAGES) {
        const abs = pageAbs(p);
        const rel = pageRel(p);
        const src = readFileSync(abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: abs });
            if (errors && errors.length) {
                broken.push(`${rel}: ${errors.map((e) => e.message).join('; ')}`);
                continue;
            }
            compileScript(descriptor, { id: rel });
            if (descriptor.template) {
                const scoped = (descriptor.styles || []).some((s) => s.scoped);
                const r = compileTemplate({
                    source: descriptor.template.content,
                    filename: abs,
                    id: rel,
                    scoped,
                });
                if (r.errors && r.errors.length) {
                    broken.push(`${rel}: template ${r.errors.map((e) => String(e.message ?? e)).join('; ')}`);
                }
            }
        } catch (e) {
            broken.push(`${rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${PAGES.length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption (every wired page imports useI18n).
test('adoption — every wired page imports useI18n', () => {
    const off = [];
    for (const p of PAGES) {
        if (WRAPPERS.has(p[1])) continue;
        const src = readFileSync(pageAbs(p), 'utf8');
        if (!/useI18n/.test(src)) off.push(pageRel(p));
    }
    console.log(`  wired pages missing useI18n: ${off.length}`);
    for (const o of off) console.log(`    ${o}`);
    assert.deepEqual(off, [], `every wired page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 4 — no raw key leak + catalog shape.
test('catalog — parses, every value a non-empty string', () => {
    assert.ok(existsSync(catalogPath), `${NS}.json exists`);
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const bad = Object.entries(obj).filter(([, v]) => typeof v !== 'string' || v.length === 0);
    for (const [k] of bad) console.log(`    bad value: ${k}`);
    assert.equal(bad.length, 0, 'every catalog value is a non-empty string');
    console.log(`  catalog keys: ${Object.keys(obj).length}`);
});

test('no raw key leak — every wired c_operator_pages key resolves', () => {
    const keys = catalogKeys();
    assert.ok(keys, `${NS}.json exists`);
    const unresolved = [];
    let checked = 0;
    for (const p of PAGES) {
        const src = readFileSync(pageAbs(p), 'utf8');
        for (const full of wiredKeysIn(src)) {
            checked += 1;
            const rest = full.slice(`${NS}.`.length);
            if (!keys.has(rest)) unresolved.push(`${pageRel(p)}: ${full}`);
        }
    }
    console.log(`  checked ${checked} wired keys across ${PAGES.length} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `wired keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 5 — no raw English sentence left in a template text node.
test('no raw sentence — templates route body copy through t()', () => {
    const offenders = [];
    for (const p of PAGES) {
        if (WRAPPERS.has(p[1])) continue;
        const abs = pageAbs(p);
        const { descriptor } = parse(readFileSync(abs, 'utf8'), { filename: abs });
        if (!descriptor.template) continue;
        const found = rawSentences(descriptor.template.content);
        for (const f of found) offenders.push(`${pageRel(p)}: ${f}`);
    }
    console.log(`  raw-sentence offenders: ${offenders.length}`);
    for (const o of offenders) console.log(`    ${o}`);
    assert.deepEqual(offenders, [], `no raw 4+ word sentence may remain: ${offenders.join(' | ')}`);
});
