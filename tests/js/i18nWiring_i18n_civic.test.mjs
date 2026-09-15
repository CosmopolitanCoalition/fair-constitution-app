// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_civic.test.mjs
//
// SOURCE PIN for the c_civic catalogue lane (namespace c_civic). It reads the
// .vue sources and the en catalog directly. It is DB-free. It asserts five
// things.
//
//   1. COMPILE GATE. Every page in the lane list compiles with
//      @vue/compiler-sfc (compileScript + compileTemplate). A worktree cannot
//      reach the Vite syntax gate, so a compiled parse here is that gate.
//   2. ADOPTION. Every page's source imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_civic.<key>') the pages reference resolves
//      to a real en catalog entry. Unresolved keys are reported per page.
//   4. CATALOG SHAPE. c_civic.json parses and every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No page holds a raw English sentence of 4+ words in a
//      template text node outside t(). Interpolations, citations, single
//      words and punctuation are allowed.
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing). The census note (NavRoleGateParityTest reads full page-path
// literals): every page path here is built from segments, never one literal.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const catalogPath = path.join(jsRoot, 'i18n/locales/en/c_civic.json');
const NS = 'c_civic';

// Page paths built from segments, never a single literal (census note).
const PAGES = [
    ['Pages', 'Civic', 'Halls.vue'],
    ['Pages', 'Civic', 'Home.vue'],
    ['Pages', 'Civic', 'IdentityVerification.vue'],
    ['Pages', 'Civic', 'Journey.vue'],
    ['Pages', 'Civic', 'PetitionDetail.vue'],
    ['Pages', 'Civic', 'Petitions.vue'],
    ['Pages', 'Civic', 'PrivateRoom.vue'],
    ['Pages', 'Civic', 'PublicSquare.vue'],
    ['Pages', 'Civic', 'Relocation.vue'],
    ['Pages', 'Civic', 'Residency.vue'],
    ['Pages', 'Rooms', 'Directory.vue'],
    ['Pages', 'Social', 'Reach.vue'],
];

function pageAbs(segments) {
    return path.join(jsRoot, ...segments);
}
function pageRel(segments) {
    return segments.join('/');
}

// Flatten the catalog to a set of dotted leaf keys (flat dotted and nested).
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

// Every t('c_civic.<key>' ...) / t("c_civic.<key>" ...) key. A key built by
// concatenation (t('c_civic.x_' + tail, ...)) is dynamic: the char after the
// closing quote is '+', so skip it (the model pin does the same).
function referencedKeys(src) {
    const out = [];
    const re = /\$?t\(\s*(['"])(c_civic\.[^'"]+)\1\s*(.)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue; // dynamic (concatenated) key
        out.push(m[2].slice(NS.length + 1)); // strip "c_civic."
    }
    return out;
}

// A text chunk reads as a citation / code voice, not prose. These are allowed
// raw. Markers: the middot, the section sign, en/em dashes with a code id, or
// a constitutional/form/role/clock/workflow id.
function isCitationish(text) {
    if (/[·§]/.test(text)) return true;
    if (/\bArt\.?\b/.test(text)) return true;
    if (/\b(?:CLK|WF|ESM|WF-[A-Z]+)-?\d*/.test(text)) return true;
    if (/\bF-[A-Z]{2,}/.test(text)) return true;
    if (/\bR-\d/.test(text)) return true;
    return false;
}

// Raw English sentences (4+ alphabetic words) left in a template text node,
// outside t(). Interpolations, comments, citations, single words, numbers and
// punctuation are allowed.
function rawSentences(templateContent) {
    const cleaned = templateContent
        .replace(/<!--[\s\S]*?-->/g, ' ') // strip comments
        .replace(/\{\{[\s\S]*?\}\}/g, ' '); // strip interpolations
    const found = [];
    const re = />([^<]*)</g;
    let m;
    while ((m = re.exec(cleaned)) !== null) {
        const chunk = m[1].replace(/\s+/g, ' ').trim();
        if (!chunk) continue;
        if (isCitationish(chunk)) continue;
        const words = chunk.match(/[A-Za-z]{2,}/g) ?? [];
        if (words.length >= 4) found.push(chunk);
    }
    return found;
}

// Unbound aria-label values that still hold raw English. A bound attribute
// (:aria-label / v-bind:aria-label) routes through t() and is fine. A static
// aria-label="Some words" is user text left raw. Citation voice is allowed.
// This closes the gap the text-node scan cannot see (attribute values).
function rawAriaLabels(templateContent) {
    const cleaned = templateContent.replace(/<!--[\s\S]*?-->/g, ' ');
    const found = [];
    const re = /(.)aria-label\s*=\s*"([^"]*)"/g;
    let m;
    while ((m = re.exec(cleaned)) !== null) {
        if (m[1] === ':') continue; // bound (:aria-label or v-bind:aria-label)
        const val = m[2].replace(/\s+/g, ' ').trim();
        if (!val) continue;
        if (isCitationish(val)) continue;
        const words = val.match(/[A-Za-z]{2,}/g) ?? [];
        if (words.length >= 1) found.push(val);
    }
    return found;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect keys, wiring and raw sentences', () => {
    assert.deepEqual(referencedKeys("t('c_civic.home.intro', 'x') and t('places.y')"), ['home.intro']);
    assert.deepEqual(referencedKeys("t('c_civic.a_' + s, {})"), []); // dynamic skipped
    assert.equal(/useI18n/.test('const { t } = useI18n();'), true);
    assert.deepEqual(rawSentences('<p>{{ t(\'c_civic.x.y\', \'hi\') }}</p>'), []);
    assert.deepEqual(rawSentences('<p>Art. II §6 · CLK-17</p>'), []); // citation allowed
    assert.deepEqual(rawSentences('<p>one two</p>'), []); // < 4 words allowed
    assert.deepEqual(rawSentences('<p>this raw english sentence leaks</p>'), ['this raw english sentence leaks']);
    assert.deepEqual(rawAriaLabels('<nav aria-label="Room location">'), ['Room location']); // static leaks
    assert.deepEqual(rawAriaLabels('<nav :aria-label="t(\'c_civic.x.y\', \'Room location\')">'), []); // bound is fine
    assert.deepEqual(rawAriaLabels('<div aria-label="Art. II §2">'), []); // citation allowed
    assert.equal(PAGES.length, 12, 'the full lane list');
    console.log(`  lane pages: ${PAGES.length}`);
});

// ── SECTION 2 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const seg of PAGES) {
        const abs = pageAbs(seg);
        const rel = pageRel(seg);
        const src = readFileSync(abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: abs });
            if (errors && errors.length) { broken.push(`${rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            compileScript(descriptor, { id: rel });
            const scoped = (descriptor.styles || []).some((s) => s.scoped);
            const r = compileTemplate({
                source: descriptor.template.content,
                filename: abs,
                id: rel,
                scoped,
            });
            if (r.errors && r.errors.length) broken.push(`${rel}: ${r.errors.map((e) => (e.message ?? e)).join('; ')}`);
        } catch (e) {
            broken.push(`${rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${PAGES.length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `lane pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption.
test('adoption — every lane page imports useI18n', () => {
    const off = [];
    for (const seg of PAGES) {
        const src = readFileSync(pageAbs(seg), 'utf8');
        if (!/useI18n/.test(src)) off.push(pageRel(seg));
    }
    console.log(`  useI18n: ${PAGES.length - off.length}/${PAGES.length}`);
    for (const f of off) console.log(`    unwired: ${f}`);
    assert.deepEqual(off, [], `every lane page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog — c_civic.json parses and every value is a non-empty string', () => {
    assert.ok(existsSync(catalogPath), 'c_civic.json exists');
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    console.log(`  catalog keys: ${Object.keys(obj).length}, bad values: ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw key leak.
test('no raw key leak — every referenced c_civic key resolves in the catalog', () => {
    const keys = catalogKeys();
    assert.ok(keys, 'catalog present');
    const unresolved = [];
    let checked = 0;
    for (const seg of PAGES) {
        const src = readFileSync(pageAbs(seg), 'utf8');
        for (const key of referencedKeys(src)) {
            checked += 1;
            if (!keys.has(key)) unresolved.push(`${pageRel(seg)}: ${NS}.${key} absent`);
        }
    }
    console.log(`  checked ${checked} referenced keys across ${PAGES.length} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 6 — no raw sentence leak in any template.
test('no raw sentence — no lane page leaves a 4+ word sentence outside t()', () => {
    const leaks = [];
    for (const seg of PAGES) {
        const src = readFileSync(pageAbs(seg), 'utf8');
        const { descriptor } = parse(src, { filename: pageAbs(seg) });
        const content = descriptor.template ? descriptor.template.content : '';
        for (const s of rawSentences(content)) leaks.push(`${pageRel(seg)}: "${s}"`);
    }
    console.log(`  raw sentence leaks: ${leaks.length}`);
    for (const l of leaks) console.log(`    ${l}`);
    assert.deepEqual(leaks, [], `no raw sentence may remain outside t(): ${leaks.join(' | ')}`);
});

// ── SECTION 7 — no raw aria-label leak (attribute values, not text nodes).
test('no raw aria-label — no lane page leaves a static aria-label in English', () => {
    const leaks = [];
    for (const seg of PAGES) {
        const src = readFileSync(pageAbs(seg), 'utf8');
        const { descriptor } = parse(src, { filename: pageAbs(seg) });
        const content = descriptor.template ? descriptor.template.content : '';
        for (const v of rawAriaLabels(content)) leaks.push(`${pageRel(seg)}: aria-label="${v}"`);
    }
    console.log(`  raw aria-label leaks: ${leaks.length}`);
    for (const l of leaks) console.log(`    ${l}`);
    assert.deepEqual(leaks, [], `aria-label values must route through t(): ${leaks.join(' | ')}`);
});
