// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_setup_components.test.mjs
//
// Catalogue lane pin for c_setup_components. The Setup live-progress
// components wire user-visible body copy through vue-i18n. This test is
// DB-free. It reads the .vue sources and the en catalog directly.
//
// It asserts:
//   1. COMPILE GATE. Every lane component compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC has a scoped
//      style). A worktree cannot reach the Vite gate, so a compiled parse
//      here is that gate.
//   2. ADOPTION. Every lane component imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_setup_components.<key>') a component
//      references resolves in the en catalog.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No component holds a raw English sentence of 4+
//      words in a template text node outside t().
//   6. NO MIXED RESIDUE. No component holds a bare English word beside a
//      {{ }} interpolation ("{{ eta }} remaining"), a single-word leak
//      SECTION 5 cannot see.
//
// The instrument self-checks first. A measure that cannot fail measures
// nothing.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');

// The census note: build every component path from segments, never one
// 'Pages/<module>/<file>.vue' literal (NavRoleGateParityTest reads those as
// DOM-mount companions; this is a SOURCE pin).
const COMPONENT_DIR = ['resources', 'js', 'Components', 'Setup'];
const FILES = [
    'CurrentJurisdictionCard.vue',
    'EventToasts.vue',
    'ExportBackupPanel.vue',
    'ImportBackupPanel.vue',
    'JurisdictionCountsGrid.vue',
    'LiveProgress.vue',
    'LogTailPanel.vue',
    'MiniMap.vue',
    'PhaseSummary.vue',
    'ProgressStatusBadge.vue',
    'QueueBadges.vue',
    'ReviewIssuesSection.vue',
    'RowDetailPanel.vue',
    'StackedProgressBars.vue',
];
const NS = 'c_setup_components';
const CATALOG = path.join(jsRoot, 'i18n', 'locales', 'en', `${NS}.json`);

function componentPath(file) {
    return path.join(root, ...COMPONENT_DIR, file);
}

function catalogKeys() {
    const obj = JSON.parse(readFileSync(CATALOG, 'utf8'));
    return { obj, keys: new Set(Object.keys(obj)) };
}

// Every literal t('c_setup_components.<key>' / t("c_setup_components.<key>".
function wiredKeysIn(src) {
    const out = [];
    const re = /\bt\(\s*(['"])c_setup_components\.((?:(?!\1).)+)\1/g;
    let m;
    while ((m = re.exec(src)) !== null) out.push(m[2]);
    return out;
}

// Text nodes of a template, tags removed with quote awareness so a `>`
// inside an attribute value (v-if="a > b") does not split a tag. Comments
// and {{ }} interpolations are stripped first.
function textNodes(tpl) {
    const s = tpl.replace(/<!--[\s\S]*?-->/g, '');
    const texts = [];
    let cur = '';
    let i = 0;
    const n = s.length;
    while (i < n) {
        const ch = s[i];
        if (ch === '<') {
            if (cur.trim()) texts.push(cur);
            cur = '';
            i += 1;
            let quote = null;
            while (i < n) {
                const c = s[i];
                if (quote) { if (c === quote) quote = null; i += 1; continue; }
                if (c === '"' || c === "'") { quote = c; i += 1; continue; }
                if (c === '>') { i += 1; break; }
                i += 1;
            }
            continue;
        }
        cur += ch;
        i += 1;
    }
    if (cur.trim()) texts.push(cur);
    return texts;
}

// A raw English sentence = 4+ letter-words in a text node after {{ }} is
// removed. Single words, numbers, punctuation and citations pass.
function rawSentences(tpl) {
    const out = [];
    for (const raw of textNodes(tpl)) {
        const stripped = raw.replace(/\{\{[\s\S]*?\}\}/g, ' ').replace(/\s+/g, ' ').trim();
        if (!stripped) continue;
        const words = stripped.match(/[A-Za-z][A-Za-z'’]+/g) || [];
        if (words.length >= 4) out.push(stripped);
    }
    return out;
}

// A mixed interpolation residue = a template text node that carries a {{ }}
// interpolation AND leftover English text once the interpolations are
// stripped. This is the "{{ eta }} remaining" class: a single trailing word
// the 4+ word sentence heuristic in rawSentences cannot see. All-caps tokens
// (roman-numeral citations, acronyms like ADM) and citation heads pass.
function mixedResidues(tpl) {
    const out = [];
    for (const raw of textNodes(tpl)) {
        if (!/\{\{[\s\S]*?\}\}/.test(raw)) continue;
        const stripped = raw.replace(/\{\{[\s\S]*?\}\}/g, ' ').replace(/\s+/g, ' ').trim();
        if (!stripped) continue;
        const words = (stripped.match(/[A-Za-z][A-Za-z'’]+/g) || [])
            .filter((w) => !/^[A-Z]+$/.test(w) && !/^(Art|CLK)$/.test(w));
        if (words.length >= 1) out.push(stripped);
    }
    return out;
}

function descriptorOf(file) {
    const abs = componentPath(file);
    const src = readFileSync(abs, 'utf8');
    const { descriptor, errors } = parse(src, { filename: abs });
    return { abs, src, descriptor, errors };
}

// SECTION 0 — instrument self-check.
test('instrument — helpers detect keys, text nodes and sentences', () => {
    assert.deepEqual(
        wiredKeysIn("t('c_setup_components.a.b') and t(\"c_setup_components.c.d\")"),
        ['a.b', 'c.d'],
    );
    assert.deepEqual(wiredKeysIn("t('other.x.y')"), []);
    // A `>` inside an attribute must not create a phantom text node.
    assert.deepEqual(textNodes('<div v-if="a > b" class="x">hi</div>'), ['hi']);
    assert.equal(rawSentences('<p>one two three four</p>').length, 1);
    assert.equal(rawSentences('<p>{{ t(\'c_setup_components.x.y\', \'one two three four\') }}</p>').length, 0);
    assert.equal(rawSentences('<p>Next</p>').length, 0);
    // Mixed interpolation residue: a bare word beside a {{ }} is caught,
    // the same word moved inside t() is not, a pure-symbol tail passes.
    assert.equal(mixedResidues('<span>{{ eta }} remaining</span>').length, 1);
    assert.equal(mixedResidues("<span>{{ eta }} {{ t('c_setup_components.x.remaining', 'remaining') }}</span>").length, 0);
    assert.equal(mixedResidues('<span>{{ pct }}%</span>').length, 0);
    assert.equal(FILES.length, 14, 'lane holds 14 components');
    console.log(`  lane components: ${FILES.length}`);
});

// SECTION 1 — compile gate.
test('compile gate — every lane component compiles (script + template)', () => {
    const broken = [];
    for (const file of FILES) {
        const rel = `Components/Setup/${file}`;
        try {
            const { abs, descriptor, errors } = descriptorOf(file);
            if (errors && errors.length) {
                broken.push(`${rel}: ${errors.map((e) => e.message).join('; ')}`);
                continue;
            }
            const scoped = (descriptor.styles || []).some((s) => s.scoped);
            compileScript(descriptor, { id: rel });
            if (descriptor.template) {
                const r = compileTemplate({
                    source: descriptor.template.content,
                    filename: abs,
                    id: rel,
                    scoped,
                });
                if (r.errors && r.errors.length) {
                    broken.push(`${rel}: ${r.errors.map((e) => (e.message || e)).join('; ')}`);
                }
            }
        } catch (e) {
            broken.push(`${rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${FILES.length} components, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `components must compile: ${broken.join(' | ')}`);
});

// SECTION 2 — adoption.
test('adoption — every lane component imports useI18n', () => {
    const off = [];
    for (const file of FILES) {
        const { src } = descriptorOf(file);
        if (!/useI18n/.test(src)) off.push(file);
    }
    console.log(`  useI18n present: ${FILES.length - off.length}/${FILES.length}`);
    for (const f of off) console.log(`    missing: ${f}`);
    assert.deepEqual(off, [], `every component must import useI18n: ${off.join(', ')}`);
});

// SECTION 3 — no raw key leak.
test('no raw key leak — every wired key resolves in the en catalog', () => {
    const { keys } = catalogKeys();
    const unresolved = [];
    let checked = 0;
    for (const file of FILES) {
        const { src } = descriptorOf(file);
        for (const key of wiredKeysIn(src)) {
            checked += 1;
            if (!keys.has(key)) unresolved.push(`${file}: ${NS}.${key} absent from catalog`);
        }
    }
    console.log(`  checked ${checked} wired keys across ${FILES.length} components`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.deepEqual(unresolved, [], `wired keys must resolve: ${unresolved.join(' | ')}`);
});

// SECTION 4 — catalog shape.
test('catalog shape — parses and every value is a non-empty string', () => {
    const { obj, keys } = catalogKeys();
    const bad = [];
    for (const k of keys) {
        const v = obj[k];
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    const sortedOk = JSON.stringify([...keys]) === JSON.stringify([...keys].sort());
    console.log(`  catalog keys: ${keys.size}, sorted: ${sortedOk}, bad values: ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.equal(bad.length, 0, `catalog values must be non-empty strings: ${bad.join(', ')}`);
});

// SECTION 5 — no raw sentence in a template text node.
test('no raw sentence — no 4+ word English text node outside t()', () => {
    const offenders = [];
    for (const file of FILES) {
        const { descriptor } = descriptorOf(file);
        if (!descriptor.template) continue;
        for (const s of rawSentences(descriptor.template.content)) {
            offenders.push(`${file}: "${s}"`);
        }
    }
    console.log(`  raw sentences found: ${offenders.length}`);
    for (const o of offenders) console.log(`    ${o}`);
    assert.deepEqual(offenders, [], `template text must go through t(): ${offenders.join(' | ')}`);
});

// SECTION 6 — no mixed interpolation residue. A bare English word beside a
// {{ }} interpolation ("{{ eta }} remaining") is a single-word leak the 4+
// word heuristic in SECTION 5 cannot see.
test('no mixed residue — no bare word beside a {{ }} interpolation', () => {
    const offenders = [];
    for (const file of FILES) {
        const { descriptor } = descriptorOf(file);
        if (!descriptor.template) continue;
        for (const s of mixedResidues(descriptor.template.content)) {
            offenders.push(`${file}: "${s}"`);
        }
    }
    console.log(`  mixed residues found: ${offenders.length}`);
    for (const o of offenders) console.log(`    ${o}`);
    assert.deepEqual(offenders, [], `interpolation-adjacent text must go through t(): ${offenders.join(' | ')}`);
});
