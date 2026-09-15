// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_ui_a.test.mjs
//
// Catalogue lane i18n-ui-a (namespace c_ui_a) source pin. It reads the .vue
// sources and the en catalog directly. No DB, no Vite.
//
//   1. COMPILE GATE. Every lane component compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC has a scoped
//      style). A worktree cannot reach the Vite gate, so a compiled parse
//      here stands in for it.
//   2. ADOPTION. Every wired component imports useI18n. The pure slot
//      wrappers in this lane hold no component-owned user text and are not
//      listed as wired.
//   3. NO RAW KEY LEAK. Every t('c_ui_a.<key>') a component references
//      resolves in the en catalog.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No lane component holds a raw English sentence of
//      four or more words in a template text node outside t().
//
// The census note: NavRoleGateParityTest reads full page-path literals as
// DOM-mount companions. This is a SOURCE pin, so every path is built from
// segments, never one literal.
//
// The instrument self-checks first. A measure that cannot fail measures
// nothing.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { createI18n } from 'vue-i18n';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const uiDir = path.join(root, 'resources', 'js', 'Components', 'Ui');
const NS = 'c_ui_a';
const catalogPath = path.join(root, 'resources', 'js', 'i18n', 'locales', 'en', `${NS}.json`);

// Every component this lane owns. Paths are built from the base dir plus a
// file segment, never a full literal.
const LANE_FILES = [
    'AdmChip.vue', 'Avatar.vue', 'Banner.vue', 'Btn.vue', 'Card.vue',
    'CheckboxField.vue', 'ChipToggle.vue', 'CitationLine.vue', 'DataTable.vue',
    'EngineChip.vue', 'Field.vue', 'FilterBar.vue', 'HistoryPager.vue', 'Icon.vue',
];

// The components that carry component-owned user text, wired through t().
// The rest are slot or prop wrappers with no owned English body string.
const WIRED_FILES = ['AdmChip.vue', 'CitationLine.vue', 'DataTable.vue', 'HistoryPager.vue'];

const abs = (file) => path.join(uiDir, file);

function usesI18n(src) {
    return /useI18n/.test(src);
}

// Every t('c_ui_a.<key>') and t("c_ui_a.<key>") literal in a source. Keys are
// static in this lane. A concatenated (dynamic) key would need every
// enumerated form present in the catalog; the lane has none.
function collectKeys(src) {
    const out = [];
    const re = /\bt\(\s*(['"])(c_ui_a\.[^'"]+)\1/g;
    let m;
    while ((m = re.exec(src)) !== null) out.push(m[2]);
    return out;
}

// Flatten a catalog to a set of dotted leaf keys. vue-i18n flatJson resolves
// both a nested path and a flat dotted key, so accept both forms.
function catalogKeys(obj) {
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

// A citation in code voice, e.g. 'Art. II §2', 'F-IND-012', 'CLK-06', 'R-03'.
function isCitation(text) {
    return /^(Art\.|§|F-[A-Z]{3}-\d|CLK-\d|R-\d|WF-)/.test(text.trim());
}

// Template text nodes with four or more word tokens, outside {{ }} and outside
// <script>/<style>.
function rawSentences(src) {
    let t = src
        .replace(/<script[\s\S]*?<\/script>/g, '')
        .replace(/<style[\s\S]*?<\/style>/g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\{\{[\s\S]*?\}\}/g, '');
    const hits = [];
    const re = />([^<>]+)</g;
    let m;
    while ((m = re.exec(t)) !== null) {
        const text = m[1].trim();
        if (!text) continue;
        if (isCitation(text)) continue;
        const words = text.split(/\s+/).filter((w) => /[A-Za-z]/.test(w));
        if (words.length >= 4) hits.push(text);
    }
    return hits;
}

// Extract every t( ... ) call that references a c_ui_a key. Returns
// [{ key, args }] where args are the top-level argument texts, split at
// depth-0 commas and respecting (), {}, [] and quotes. A source pin cannot
// run the call, so it reads the argument SHAPE.
function tCalls(src) {
    const out = [];
    const re = /\bt\(/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        let depth = 1;
        let quote = null;
        const args = [];
        let cur = '';
        let i = m.index + m[0].length;
        for (; i < src.length && depth > 0; i++) {
            const c = src[i];
            if (quote) {
                cur += c;
                if (c === quote && src[i - 1] !== '\\') quote = null;
                continue;
            }
            if (c === '"' || c === "'" || c === '`') { quote = c; cur += c; continue; }
            if (c === '(' || c === '{' || c === '[') { depth += 1; cur += c; continue; }
            if (c === ')' || c === '}' || c === ']') {
                depth -= 1;
                if (depth === 0) break;
                cur += c;
                continue;
            }
            if (c === ',' && depth === 1) { args.push(cur.trim()); cur = ''; continue; }
            cur += c;
        }
        if (cur.trim()) args.push(cur.trim());
        if (args.length && /^(['"])c_ui_a\./.test(args[0])) {
            out.push({ key: args[0].slice(1, -1), args });
        }
    }
    return out;
}

// A default message with a placeholder needs its values under the named
// wrapper. vue-i18n reads t(key, message, options) options.named for the
// substitution; a bare object as the third argument is TranslateOptions with
// no named values, so the placeholder renders empty. This scan flags any
// three-argument c_ui_a call whose string default holds a {placeholder} and
// whose third argument does not carry named.
function placeholderArgViolations(src) {
    const bad = [];
    for (const call of tCalls(src)) {
        if (call.args.length < 3) continue;
        const def = call.args[1];
        const isStr = /^['"`]/.test(def);
        if (!isStr) continue;
        if (!/\{[A-Za-z]/.test(def)) continue;
        if (!/\bnamed\b/.test(call.args[2])) {
            bad.push(`${call.key}: placeholder default '${def}' needs a named wrapper, got ${call.args[2]}`);
        }
    }
    return bad;
}

// Compile one SFC. Returns a list of error messages (empty on success).
function compileOne(file) {
    const filename = abs(file);
    const src = readFileSync(filename, 'utf8');
    const errs = [];
    const { descriptor, errors } = parse(src, { filename });
    if (errors && errors.length) errs.push(...errors.map((e) => e.message || String(e)));
    const id = `ui-a-${file}`;
    const scoped = descriptor.styles.some((s) => s.scoped);
    let bindings;
    try {
        const s = compileScript(descriptor, { id });
        bindings = s.bindings;
    } catch (e) {
        errs.push(`script: ${e.message}`);
    }
    if (descriptor.template) {
        try {
            const r = compileTemplate({
                source: descriptor.template.content,
                filename,
                id,
                scoped,
                compilerOptions: { bindingMetadata: bindings },
            });
            if (r.errors && r.errors.length) errs.push(...r.errors.map((e) => (e.message || String(e))));
        } catch (e) {
            errs.push(`template: ${e.message}`);
        }
    }
    return errs;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect wiring, keys, citations and raw text', () => {
    assert.equal(usesI18n("const { t } = useI18n();"), true);
    assert.equal(usesI18n('<span class="avatar" />'), false);
    assert.deepEqual(collectKeys("t('c_ui_a.a.b') and t('leaflet')"), ['c_ui_a.a.b']);
    assert.deepEqual(collectKeys('t("c_ui_a.x.y", "Z")'), ['c_ui_a.x.y']);
    assert.equal(isCitation('Art. II §2'), true);
    assert.equal(isCitation('This page could not be loaded'), false);
    assert.deepEqual(rawSentences('<p>This page could not be loaded now</p>'), ['This page could not be loaded now']);
    assert.deepEqual(rawSentences('<p>{{ t("c_ui_a.x.y", "hi") }}</p>'), []);
    assert.deepEqual(rawSentences('<span>Next</span>'), []);
    assert.ok(LANE_FILES.length === 14, `expected 14 lane files, got ${LANE_FILES.length}`);
    // The arg extractor splits at depth-0 commas and keeps nested braces whole.
    const parsed = tCalls("t('c_ui_a.x.y', '{a} b', { named: { a } })");
    assert.equal(parsed.length, 1);
    assert.deepEqual(parsed[0].args, ["'c_ui_a.x.y'", "'{a} b'", '{ named: { a } }']);
    // The placeholder rule flags a bare object and clears a named wrapper.
    assert.deepEqual(
        placeholderArgViolations("t('c_ui_a.x.y', '{a} b', { a })").length, 1);
    assert.deepEqual(
        placeholderArgViolations("t('c_ui_a.x.y', '{a} b', { named: { a } })"), []);
    // A two-argument named call and a plain default are not flagged.
    assert.deepEqual(placeholderArgViolations("t('c_ui_a.x.y', { a })"), []);
    assert.deepEqual(placeholderArgViolations("t('c_ui_a.x.y', 'plain text', {})"), []);
    console.log(`  lane files: ${LANE_FILES.length}, wired: ${WIRED_FILES.length}`);
});

// ── SECTION 2 — compile gate.
test('compile gate — every lane component compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const file of LANE_FILES) {
        const errs = compileOne(file);
        if (errs.length) broken.push(`${file}: ${errs.join('; ')}`);
    }
    console.log(`  compiled ${LANE_FILES.length} components, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `components must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption on every wired component.
test('adoption — every wired component imports useI18n', () => {
    const off = [];
    for (const file of WIRED_FILES) {
        const src = readFileSync(abs(file), 'utf8');
        const on = usesI18n(src);
        console.log(`  ${file}: useI18n=${on}`);
        if (!on) off.push(file);
    }
    assert.deepEqual(off, [], `wired components must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 4 — no raw key leak.
test('no raw key leak — every referenced c_ui_a key resolves in the catalog', () => {
    assert.ok(existsSync(catalogPath), `${NS}.json exists`);
    const keys = catalogKeys(JSON.parse(readFileSync(catalogPath, 'utf8')));
    const unresolved = [];
    let checked = 0;
    for (const file of LANE_FILES) {
        const src = readFileSync(abs(file), 'utf8');
        for (const key of collectKeys(src)) {
            checked += 1;
            const rest = key.slice(NS.length + 1);
            if (!keys.has(rest)) unresolved.push(`${file}: ${key} absent from ${NS}.json`);
        }
    }
    console.log(`  checked ${checked} referenced keys across ${LANE_FILES.length} components`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.deepEqual(unresolved, [], `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 5 — catalog shape.
test('catalog shape — the catalog parses and every value is a non-empty string', () => {
    const raw = readFileSync(catalogPath, 'utf8');
    const obj = JSON.parse(raw);
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    console.log(`  catalog keys: ${Object.keys(obj).length}, bad values: ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 6 — no raw sentence leak.
test('no raw sentence — no lane component holds a raw 4+ word template sentence', () => {
    const leaks = [];
    for (const file of LANE_FILES) {
        const src = readFileSync(abs(file), 'utf8');
        for (const s of rawSentences(src)) leaks.push(`${file}: "${s}"`);
    }
    console.log(`  scanned ${LANE_FILES.length} components, raw sentences ${leaks.length}`);
    for (const l of leaks) console.log(`    ${l}`);
    assert.deepEqual(leaks, [], `no raw template sentence may remain: ${leaks.join(' | ')}`);
});

// ── SECTION 7 — placeholder argument shape.
// A three-argument t() with a {placeholder} default must pass its values under
// the named wrapper. The bare object shape drops the placeholder for the
// screen-reader viewer, which the key-resolution and raw-sentence scans miss.
test('placeholder args — a {placeholder} default passes values under named', () => {
    const bad = [];
    for (const file of LANE_FILES) {
        const src = readFileSync(abs(file), 'utf8');
        for (const v of placeholderArgViolations(src)) bad.push(`${file}: ${v}`);
    }
    console.log(`  checked ${LANE_FILES.length} components for placeholder arg shape, violations ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `placeholder defaults must use the named wrapper: ${bad.join(' | ')}`);
});

// ── SECTION 8 — runtime interpolation contract.
// Prove the shape the source pin enforces is the one vue-i18n needs. Every
// catalog value that holds a {placeholder} substitutes under the named wrapper
// and drops the value under a bare object, so a regression to the bare shape
// renders empty text.
test('interpolation contract — named wrapper substitutes, bare object drops', () => {
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const withPlaceholder = Object.entries(obj).filter(([, v]) => /\{[A-Za-z]/.test(v));
    console.log(`  catalog values with a placeholder: ${withPlaceholder.length}`);
    for (const [k, v] of withPlaceholder) {
        const names = [...v.matchAll(/\{([A-Za-z][A-Za-z0-9_]*)\}/g)].map((mm) => mm[1]);
        const named = Object.fromEntries(names.map((n) => [n, `V_${n}`]));
        const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: { probe: v } } });
        const { t } = i18n.global;
        const wrapped = t('probe', v, { named });
        const bare = t('probe', v, named);
        for (const n of names) {
            assert.ok(wrapped.includes(`V_${n}`), `${k}: named wrapper must substitute {${n}}, got "${wrapped}"`);
            assert.ok(!bare.includes(`V_${n}`), `${k}: bare object must drop {${n}}, got "${bare}"`);
        }
        console.log(`    ${k}: named="${wrapped}" bare="${bare}"`);
    }
});
