// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_economy.test.mjs
//
// Catalogue lane "i18n-economy" (namespace c_economy). This is a SOURCE pin.
// It reads the .vue sources of the Economy page set and the en catalog
// directly. It is DB-free. It asserts five things.
//
//   1. COMPILE GATE. Every page in the lane compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate). A worktree cannot reach the Vite
//      syntax gate, so a compiled parse here is the gate for these edits.
//   2. ADOPTION. Every page's source imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_economy.<key>') the pages reference
//      resolves to a real en catalog entry (reported per page).
//   4. CATALOG SHAPE. c_economy.json parses and every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No page still holds a raw English sentence of 4+
//      words in a template text node outside t().
//
// Census note (NavRoleGateParityTest reads full 'Pages/<module>/<file>.vue'
// literals in tests/js as DOM-mount companions). This test is a SOURCE pin,
// so every page path is built from segments, never one file literal.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { baseParse } from '@vue/compiler-dom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const localesDir = path.join(jsRoot, 'i18n/locales/en');
const NS = 'c_economy';

// The lane page set. The prompt list named ListingDetail.vue, which does not
// exist in the tree; the real file is Listing.vue, wired in its place.
const PAGE_DIR = ['Pages', 'Economy'];
const PAGE_FILES = [
    'AgreementDetail.vue',
    'Agreements.vue',
    'Exchange.vue',
    'Help.vue',
    'HelpDetail.vue',
    'Home.vue',
    'JointLedgers.vue',
    'Listing.vue',
    'Market.vue',
    'OrgSettings.vue',
    'RequestDetail.vue',
    'ResidentAgreements.vue',
    'Stipend.vue',
    'Treasury.vue',
    'Units.vue',
    'Wallet.vue',
    'Work.vue',
];

function pagePath(file) {
    return path.join(jsRoot, ...PAGE_DIR, file);
}
function pageRel(file) {
    return [...PAGE_DIR, file].join('/');
}

function usesI18n(src) {
    return /useI18n/.test(src);
}

// Flat dotted leaf keys of the catalog.
function catalogKeys() {
    const p = path.join(localesDir, `${NS}.json`);
    if (!existsSync(p)) return null;
    const obj = JSON.parse(readFileSync(p, 'utf8'));
    return new Set(Object.keys(obj));
}

// Every t('c_economy.<rest>' / t("c_economy.<rest>" literal key referenced in
// a page. Concatenated keys (a static prefix + a dynamic tail) end in a '+'
// after the quote and are skipped; the lane uses none.
function referencedKeys(src) {
    const out = [];
    const re = /\$?t\(\s*(['"])c_economy\.([A-Za-z0-9_.]+)\1\s*(.)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue;
        out.push(m[2]);
    }
    return out;
}

// Words in a template text chunk: letter runs (apostrophes and hyphens kept
// inside a run). Numbers, punctuation and single symbols are not words.
function wordCount(chunk) {
    const words = chunk.match(/[A-Za-z][A-Za-z'’-]*/g);
    return words ? words.length : 0;
}

// Scan a page's template for a raw English sentence (4+ words) in a text node
// outside t(). The template is parsed to an AST, so only real TEXT nodes are
// examined: interpolations (their own node type), comments and attribute
// expressions (including arrow functions with '=>') are never text and never
// counted.
function textNodeContents(templateContent) {
    const root = baseParse(templateContent);
    const out = [];
    const visit = (n) => {
        if (!n) return;
        if (n.type === 2) out.push(n.content); // NodeTypes.TEXT
        if (Array.isArray(n.children)) n.children.forEach(visit);
        if (Array.isArray(n.branches)) n.branches.forEach(visit); // v-if branches
    };
    (root.children || []).forEach(visit);
    return out;
}

function rawSentences(templateContent) {
    const flagged = [];
    for (const chunk of textNodeContents(templateContent)) {
        if (wordCount(chunk) >= 4) flagged.push(chunk.trim().replace(/\s+/g, ' '));
    }
    return flagged;
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys and raw sentences', () => {
    assert.equal(usesI18n("const { t } = useI18n();"), true);
    assert.equal(usesI18n("<h1>Plain</h1>"), false);
    assert.deepEqual(referencedKeys("t('c_economy.wallet.title', 'My wallet')"), ['wallet.title']);
    assert.deepEqual(referencedKeys("t('c_economy.x.y' + s, {})"), []);
    assert.equal(rawSentences('<p>One two three four five</p>').length, 1);
    assert.equal(rawSentences('<p>{{ t(k) }}</p>').length, 0);
    assert.equal(rawSentences('<p>· one</p>').length, 0);
    console.log(`  lane pages: ${PAGE_FILES.length}`);
});

// ── SECTION 1 — every page exists.
test('page set — every lane page exists on disk', () => {
    const missing = PAGE_FILES.filter((f) => !existsSync(pagePath(f)));
    for (const f of missing) console.log(`    missing: ${pageRel(f)}`);
    assert.deepEqual(missing, [], `lane pages must exist: ${missing.join(', ')}`);
});

// ── SECTION 2 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const f of PAGE_FILES) {
        const abs = pagePath(f);
        const src = readFileSync(abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: abs });
            if (errors && errors.length) { broken.push(`${pageRel(f)}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            compileScript(descriptor, { id: pageRel(f), inlineTemplate: false });
            if (descriptor.template) {
                const scoped = descriptor.styles.some((s) => s.scoped);
                const r = compileTemplate({ source: descriptor.template.content, filename: abs, id: pageRel(f), scoped });
                if (r.errors && r.errors.length) broken.push(`${pageRel(f)}: ${r.errors.map((e) => (e.message ?? e)).join('; ')}`);
            }
        } catch (e) {
            broken.push(`${pageRel(f)}: ${e.message}`);
        }
    }
    console.log(`  compiled ${PAGE_FILES.length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `page components must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption.
test('adoption — every lane page imports useI18n', () => {
    const off = PAGE_FILES.filter((f) => !usesI18n(readFileSync(pagePath(f), 'utf8')));
    for (const f of off) console.log(`    unwired: ${pageRel(f)}`);
    assert.deepEqual(off, [], `every lane page must use i18n: ${off.join(', ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — c_economy.json parses and every value is a non-empty string', () => {
    const p = path.join(localesDir, `${NS}.json`);
    assert.ok(existsSync(p), `${NS}.json exists`);
    const obj = JSON.parse(readFileSync(p, 'utf8'));
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    console.log(`  catalog keys: ${Object.keys(obj).length}, bad values: ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `catalog values must be non-empty strings: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw key leak.
test('no raw key leak — every referenced c_economy key resolves in the catalog', () => {
    const keys = catalogKeys();
    assert.ok(keys, `${NS}.json exists`);
    const unresolved = [];
    let checked = 0;
    for (const f of PAGE_FILES) {
        const src = readFileSync(pagePath(f), 'utf8');
        for (const rest of referencedKeys(src)) {
            checked += 1;
            if (!keys.has(rest)) unresolved.push(`${pageRel(f)}: ${NS}.${rest} absent`);
        }
    }
    console.log(`  checked ${checked} referenced keys`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 6b — no v-for loop shadows the i18n t.
// A loop variable named t hides the injected i18n t inside its body, so any
// glue word or short label there cannot be wired and slips under the
// 4-word raw-sentence heuristic. This was the OrgSettings taxes-table defect
// (the row was v-for="t in taxes"). Guard every lane page against the shadow.
test('no i18n t shadow — no lane page loops with v-for="t in ..."', () => {
    const shadows = [];
    const re = /v-for\s*=\s*(['"])\s*(?:\(\s*)?t\b\s+(?:in|of)\b/;
    for (const f of PAGE_FILES) {
        const src = readFileSync(pagePath(f), 'utf8');
        if (re.test(src)) shadows.push(pageRel(f));
    }
    for (const s of shadows) console.log(`    shadow: ${s}`);
    assert.deepEqual(shadows, [], `no lane page may shadow i18n t with a loop var: ${shadows.join(', ')}`);
});

// ── SECTION 6c — the OrgSettings taxes-row repair keys are wired.
// The rate glue and the civic-exempt label were raw English under the loop
// shadow. Pin the two keys so the repair cannot regress silently.
test('org_settings taxes row — rate glue and civic-exempt label are wired', () => {
    const src = readFileSync(pagePath('OrgSettings.vue'), 'utf8');
    const refs = new Set(referencedKeys(src));
    const need = ['org_settings.rate_on_base', 'org_settings.civic_exempt'];
    const missing = need.filter((k) => !refs.has(k));
    for (const m of missing) console.log(`    unwired repair key: ${m}`);
    assert.deepEqual(missing, [], `OrgSettings must wire: ${missing.join(', ')}`);
});

// ── SECTION 6 — no raw English sentence in a template text node.
test('no raw sentence — no lane page holds a 4+ word text node outside t()', () => {
    const offenders = [];
    for (const f of PAGE_FILES) {
        const src = readFileSync(pagePath(f), 'utf8');
        const { descriptor } = parse(src, { filename: pagePath(f) });
        if (!descriptor.template) continue;
        const flagged = rawSentences(descriptor.template.content);
        for (const chunk of flagged) offenders.push(`${pageRel(f)}: "${chunk}"`);
    }
    console.log(`  raw sentences found: ${offenders.length}`);
    for (const o of offenders) console.log(`    ${o}`);
    assert.deepEqual(offenders, [], `template text must go through t(): ${offenders.join(' | ')}`);
});
