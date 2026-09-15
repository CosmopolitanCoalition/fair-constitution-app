// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_civic_components.test.mjs
//
// Catalogue lane pin: i18n-civic-components (namespace c_civic_components).
// This is a SOURCE pin. It reads the .vue sources and the one en catalog
// directly. It does not mount the DOM. It asserts five things.
//
//   1. COMPILE GATE. Every lane page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC has a scoped
//      style). A worktree cannot reach the Vite gate, so a compiled parse
//      here is the gate for the edits this lane makes.
//   2. ADOPTION. Every lane page source imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_civic_components.<key>') the pages
//      reference resolves in the en catalog.
//   4. CATALOG SHAPE. The catalog parses and every value is a non-empty
//      string.
//   5. NO RAW COPY. No lane page holds a raw English sentence of 4+ words
//      in a template text node outside t().
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing). The census note: page paths are built from segments, never one
// full 'Components/<module>/<file>.vue' literal, so a DOM-mount census reader
// does not read this SOURCE pin as a mount companion.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const NS = 'c_civic_components';
const catalogPath = path.join(jsRoot, 'i18n/locales/en', `${NS}.json`);

// The lane list. Each row is built from segments, never one path literal.
// { segs: directory segments under resources/js, file: SFC base name }.
const LANE = [
    { segs: ['Components', 'Civic', 'Room'], file: 'VoiceControls.vue' },
    { segs: ['Components', 'Civic'], file: 'SignatureMeter.vue' },
    { segs: ['Components', 'Economy'], file: 'WorkTradeNav.vue' },
    { segs: ['Components', 'Federation'], file: 'SyncProgress.vue' },
    { segs: ['Components', 'Invite'], file: 'InviteButton.vue' },
    { segs: ['Components', 'Progress'], file: 'StageBars.vue' },
    { segs: ['Components', 'Social'], file: 'CandidacyEndorsements.vue' },
    { segs: ['Components', 'Social'], file: 'OfficeHistory.vue' },
];

function pageAbs(row) {
    return path.join(jsRoot, ...row.segs, row.file);
}
function pageRel(row) {
    return ['resources/js', ...row.segs, row.file].join('/');
}

function usesI18n(src) {
    return /useI18n/.test(src);
}

// Load the flat catalog as a set of dotted keys.
function catalogKeys() {
    if (!existsSync(catalogPath)) return null;
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    return new Set(Object.keys(obj));
}

// Every t('c_civic_components.<key>' ...) and t("c_civic_components.<key>" ...)
// literal key. A key assembled by concatenation (next char '+') is dynamic and
// skipped here; the lane wired no such keys.
function wiredKeysIn(src) {
    const out = [];
    const re = /t\(\s*(['"])(c_civic_components\.[^'"]+)\1\s*(.?)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue; // dynamic (concatenated) key
        out.push(m[2].slice(NS.length + 1)); // strip 'c_civic_components.'
    }
    return out;
}

// The template body with {{ ... }} interpolations removed, then every text
// node between > and <. A "word" is an alphabetic token. Citation and code
// tokens are ignored. A text node with 4+ words is raw English copy.
const CITATION = /^(Art\.|§|F-[A-Z]|CLK-|R-\d|WF-|CGC|STV|RCV|PR-STV)/;
function rawCopyNodes(templateSrc) {
    const stripped = templateSrc.replace(/\{\{[\s\S]*?\}\}/g, ' ');
    const found = [];
    const re = />([^<]+)</g;
    let m;
    while ((m = re.exec(stripped)) !== null) {
        const text = m[1].replace(/&[a-z]+;/g, ' ').trim();
        if (!text) continue;
        const words = text
            .split(/\s+/)
            .filter((w) => /^[A-Za-z][A-Za-z’']*$/.test(w))
            .filter((w) => !CITATION.test(w));
        if (words.length >= 4) found.push(text.slice(0, 80));
    }
    return found;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect wiring, keys and raw copy', () => {
    assert.equal(usesI18n("import { useI18n } from 'vue-i18n';"), true);
    assert.equal(usesI18n('<h1>Plain</h1>'), false);
    assert.deepEqual(
        wiredKeysIn("t('c_civic_components.office_history.title', 'Office history') t('other.x')"),
        ['office_history.title'],
    );
    assert.deepEqual(wiredKeysIn("t('c_civic_components.x.y' + z)"), []);
    assert.deepEqual(rawCopyNodes('<p>{{ t(\'x\', \'hi there my friend\') }}</p>'), []);
    assert.deepEqual(rawCopyNodes('<p>one two three four five</p>'), ['one two three four five']);
    assert.equal(LANE.length, 8, 'the lane holds eight pages');
    for (const row of LANE) assert.ok(existsSync(pageAbs(row)), `${pageRel(row)} exists`);
    console.log(`  lane pages: ${LANE.length}`);
});

// ── SECTION 2 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const row of LANE) {
        const abs = pageAbs(row);
        const src = readFileSync(abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: abs });
            if (errors && errors.length) {
                broken.push(`${pageRel(row)}: ${errors.map((e) => e.message).join('; ')}`);
                continue;
            }
            const scoped = descriptor.styles.some((s) => s.scoped);
            compileScript(descriptor, { id: pageRel(row) });
            if (descriptor.template) {
                const r = compileTemplate({
                    source: descriptor.template.content,
                    filename: abs,
                    id: pageRel(row),
                    scoped,
                });
                if (r.errors && r.errors.length) {
                    broken.push(`${pageRel(row)}: ${r.errors.map((e) => (e.message ?? e)).join('; ')}`);
                }
            }
        } catch (e) {
            broken.push(`${pageRel(row)}: ${e.message}`);
        }
    }
    console.log(`  compiled ${LANE.length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `lane pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption.
test('adoption — every lane page imports useI18n', () => {
    const off = [];
    for (const row of LANE) {
        if (!usesI18n(readFileSync(pageAbs(row), 'utf8'))) off.push(pageRel(row));
    }
    console.log(`  wired ${LANE.length - off.length}/${LANE.length}`);
    for (const f of off) console.log(`    unwired: ${f}`);
    assert.deepEqual(off, [], `every lane page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 4 — no raw key leak.
test('no raw key leak — every wired key resolves in the catalog', () => {
    const keys = catalogKeys();
    assert.ok(keys, `catalog ${NS}.json exists`);
    const unresolved = [];
    let checked = 0;
    for (const row of LANE) {
        const src = readFileSync(pageAbs(row), 'utf8');
        for (const key of wiredKeysIn(src)) {
            checked += 1;
            if (!keys.has(key)) unresolved.push(`${pageRel(row)}: ${key} absent`);
        }
    }
    console.log(`  checked ${checked} wired keys across ${LANE.length} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.deepEqual(unresolved, [], `wired keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 5 — catalog shape.
test('catalog shape — parses and every value is a non-empty string', () => {
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    console.log(`  catalog keys: ${Object.keys(obj).length}, bad values: ${bad.length}`);
    for (const k of bad) console.log(`    ${k}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 6 — no raw copy.
test('no raw copy — no lane page holds a raw 4+ word sentence in template text', () => {
    const raw = [];
    for (const row of LANE) {
        const src = readFileSync(pageAbs(row), 'utf8');
        const { descriptor } = parse(src, { filename: pageAbs(row) });
        if (!descriptor.template) continue;
        for (const node of rawCopyNodes(descriptor.template.content)) {
            raw.push(`${pageRel(row)}: "${node}"`);
        }
    }
    console.log(`  raw text nodes found: ${raw.length}`);
    for (const r of raw) console.log(`    ${r}`);
    assert.deepEqual(raw, [], `template text must go through t(): ${raw.join(' | ')}`);
});
