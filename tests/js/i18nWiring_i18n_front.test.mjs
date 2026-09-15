// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_front.test.mjs
//
// Catalogue lane pin: i18n-front (namespace c_front). This test is DB-free.
// It reads the .vue sources and the en catalog directly. It asserts five
// things about the lane's front-door, learn and support pages.
//
//   1. COMPILE GATE. Every lane page compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate). A worktree cannot reach the Vite
//      gate, so a compiled parse here is that gate.
//   2. ADOPTION. Every wired page imports useI18n. Two pages are thin
//      ArrivalHub wrappers with no user text and are exempt.
//   3. NO RAW KEY LEAK. Every t('c_front.<key>') the pages reference resolves
//      to a real en catalog entry.
//   4. CATALOG SHAPE. c_front.json parses and every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No wired page holds a raw English sentence of 4+
//      words in a template text node outside t().
//
// The census note (NavRoleGateParityTest): that test reads full
// 'Pages/<module>/<file>.vue' string literals as DOM-mount companions. This
// pin is a SOURCE pin, so it builds every page path from segments and never
// writes one such literal.
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing).
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const localesDir = path.join(jsRoot, 'i18n/locales/en');
const NS = 'c_front';

// Lane pages as [dir, file] segments, so no single literal spells a full
// 'Pages/<dir>/<file>.vue' path (the census note).
const LANE = [
    ['Auth', 'Login.vue'],
    ['Auth', 'OperatorLogin.vue'],
    ['Auth', 'Register.vue'],
    ['', 'Home.vue'],
    ['Invite', 'Landing.vue'],
    ['', 'Launchpad.vue'],
    ['Learn', 'MaterialEdit.vue'],
    ['Learn', 'MaterialManager.vue'],
    ['Learn', 'VideoLibrary.vue'],
    ['Support', 'Report.vue'],
    ['Support', 'Ticket.vue'],
    ['Support', 'Tickets.vue'],
    ['Tour', 'Index.vue'],
];

// Home and Launchpad are thin ArrivalHub wrappers with no user text: SKIPPED.
// They still compile, but they carry no useI18n and no raw sentences.
const SKIPPED = new Set(['Home.vue', 'Launchpad.vue']);

function pageInfo([dir, file]) {
    const segs = dir ? ['Pages', dir, file] : ['Pages', file];
    return {
        file,
        dir,
        rel: segs.join('/'),
        abs: path.join(jsRoot, ...segs),
        wired: !SKIPPED.has(file),
    };
}

const PAGES = LANE.map(pageInfo);
const WIRED = PAGES.filter((p) => p.wired);

function usesI18n(src) {
    return /useI18n/.test(src);
}

// Flatten a catalog to a set of dotted leaf keys (flat and nested both).
function catalogKeys(ns) {
    const p = path.join(localesDir, `${ns}.json`);
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

// Every t('c_front.<key>' ...) and t("c_front.<key>" ...) literal in a source.
// The lane builds no key from a static prefix plus a dynamic tail, so a plain
// literal scan is complete.
function referencedKeys(src) {
    const out = [];
    const re = new RegExp(`t\\(\\s*['"](${NS}\\.[A-Za-z0-9_.]+)['"]`, 'g');
    let m;
    while ((m = re.exec(src)) !== null) out.push(m[1]);
    return out;
}

// Strip HTML comments and {{ }} interpolations, then return the template's
// text nodes (the runs between > and <).
function templateTextNodes(templateContent) {
    let s = templateContent.replace(/<!--[\s\S]*?-->/g, ' ');
    s = s.replace(/\{\{[\s\S]*?\}\}/g, ' ');
    const nodes = [];
    const re = />([^<]*)</g;
    let m;
    while ((m = re.exec(s)) !== null) {
        const text = m[1].trim();
        if (text) nodes.push(text);
    }
    return nodes;
}

// A "word" is a token of two or more letters that is not a bare citation
// token (Art, §, F-XXX, CLK-nn, R-nn, WF-XXX, ESM-nn). A text node with 4+
// such words is a raw English sentence.
const CITATION = /^(Art|§\S*|F-[A-Z0-9-]+|CLK-\d+|R-\d+|WF-[A-Z0-9-]+|ESM-\d+|CGC|STV|RCV)$/;
function wordCount(text) {
    let n = 0;
    for (const raw of text.split(/\s+/)) {
        const tok = raw.replace(/^[^A-Za-z0-9§-]+|[^A-Za-z0-9§-]+$/g, '');
        if (!tok) continue;
        if (CITATION.test(tok)) continue;
        if (/[A-Za-z]{2,}/.test(tok)) n += 1;
    }
    return n;
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers detect wiring, keys and raw sentences', () => {
    assert.equal(usesI18n('const { t } = useI18n();'), true);
    assert.equal(usesI18n('<h1>Plain</h1>'), false);
    assert.deepEqual(
        referencedKeys("t('c_front.login.title', 'Log in') and t('places.x')"),
        ['c_front.login.title'],
    );
    assert.deepEqual(
        templateTextNodes('<p>Hello {{ x }} world</p>').map((s) => s.replace(/\s+/g, ' ')),
        ['Hello world'],
    );
    assert.ok(wordCount('Sign in to your record') >= 4);
    assert.equal(wordCount('Art. I · Art. V §1') < 4, true);
    assert.equal(PAGES.length, 13);
    console.log(`  lane pages: ${PAGES.length} (${WIRED.length} wired, ${SKIPPED.size} skipped)`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every lane page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const p of PAGES) {
        const src = readFileSync(p.abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: p.abs });
            if (errors && errors.length) {
                broken.push(`${p.rel}: ${errors.map((e) => e.message).join('; ')}`);
                continue;
            }
            const scoped = descriptor.styles.some((s) => s.scoped);
            if (descriptor.scriptSetup || descriptor.script) {
                compileScript(descriptor, { id: p.rel });
            }
            if (descriptor.template) {
                const r = compileTemplate({
                    source: descriptor.template.content,
                    filename: p.abs,
                    id: p.rel,
                    scoped,
                });
                if (r.errors && r.errors.length) {
                    broken.push(`${p.rel}: ${r.errors.map((e) => (e.message || e)).join('; ')}`);
                }
            }
        } catch (e) {
            broken.push(`${p.rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${PAGES.length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `pages must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption.
test('adoption — every wired lane page imports useI18n', () => {
    const off = [];
    for (const p of WIRED) {
        if (!usesI18n(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
    }
    console.log(`  useI18n: ${WIRED.length - off.length}/${WIRED.length} wired pages`);
    for (const f of off) console.log(`    unwired: ${f}`);
    assert.deepEqual(off, [], `every wired page must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 3 — no raw key leak.
test('no raw key leak — every referenced c_front key resolves in the catalog', () => {
    const keys = catalogKeys(NS);
    assert.ok(keys, `${NS}.json exists`);
    const unresolved = [];
    let checked = 0;
    for (const p of PAGES) {
        const src = readFileSync(p.abs, 'utf8');
        for (const key of referencedKeys(src)) {
            checked += 1;
            const rest = key.slice(NS.length + 1);
            if (!keys.has(rest)) unresolved.push(`${p.rel}: ${key} absent from ${NS}.json`);
        }
    }
    console.log(`  checked ${checked} referenced keys across ${PAGES.length} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4 — catalog shape.
test('catalog shape — c_front.json parses and every value is a non-empty string', () => {
    const p = path.join(localesDir, `${NS}.json`);
    assert.ok(existsSync(p), `${NS}.json exists`);
    const obj = JSON.parse(readFileSync(p, 'utf8'));
    const bad = [];
    for (const [k, v] of Object.entries(obj)) {
        if (typeof v !== 'string' || !v.trim()) bad.push(k);
    }
    console.log(`  catalog entries: ${Object.keys(obj).length}, bad ${bad.length}`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `every catalog value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 5 — no raw English sentence in a template text node.
test('no raw sentence — wired pages hold no 4+ word text node outside t()', () => {
    const leaks = [];
    for (const p of WIRED) {
        const src = readFileSync(p.abs, 'utf8');
        const { descriptor } = parse(src, { filename: p.abs });
        if (!descriptor.template) continue;
        for (const text of templateTextNodes(descriptor.template.content)) {
            if (wordCount(text) >= 4) leaks.push(`${p.rel}: "${text}"`);
        }
    }
    console.log(`  raw-sentence leaks: ${leaks.length}`);
    for (const l of leaks) console.log(`    ${l}`);
    assert.deepEqual(leaks, [], `no raw sentence may remain: ${leaks.join(' | ')}`);
});
