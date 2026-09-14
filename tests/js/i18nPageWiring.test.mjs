// node --experimental-vm-modules --test tests/js/i18nPageWiring.test.mjs
//
// W-0171 pin. The civic, legislature and election page bodies wire through
// vue-i18n. This test is DB-free. It reads the .vue sources and the en
// catalogs directly. It asserts three things.
//
//   1. COMPILE GATE. Every target page compiles with @vue/compiler-sfc. A
//      worktree cannot reach the Vite syntax gate, so a compiled parse here
//      is the gate for the edits this item makes.
//   2. ADOPTION. A majority of the target page components resolve body copy
//      through t() (the useI18n composition, or the $t template global).
//   3. NO RAW KEY LEAK. Every t('<ns>.<key>') this item wired resolves to a
//      real en catalog entry, so no page renders a raw key id. The check
//      runs per area on a representative page and across every wired page.
//
// The instrument self-checks first (a measure that cannot fail measures
// nothing).
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const localesDir = path.join(jsRoot, 'i18n/locales/en');
const AREAS = ['Civic', 'Legislature', 'Elections'];

// Pages this item wired, by area. The no-leak check runs against these.
const WIRED = {
    Civic: ['Journeys.vue', 'PrivateRooms.vue', 'PrivateRoomCreate.vue'],
    Legislature: ['SessionArchive.vue', 'Index.vue'],
    Elections: ['CandidacyRegistration.vue', 'Results.vue', 'RankedBallot.vue',
        'OpenBallot.vue', 'BoardConsole.vue', 'VacancyCountback.vue'],
};

// The major election page bodies the done_when names. Each must call useI18n()
// for body text. The finding was that the aggregate-majority and one-per-area
// checks left these five hardcoded. This list enforces them by name.
const MAJOR_ELECTION_BODIES = ['Results.vue', 'RankedBallot.vue', 'OpenBallot.vue',
    'BoardConsole.vue', 'VacancyCountback.vue', 'CandidacyRegistration.vue'];
// The namespaces this item wired into (leak check restricts to these heads,
// so a dynamic import string like t('leaflet') is never mistaken for a key).
const NS = new Set(['places', 'c_legislature_workspace', 'c_elections']);

function targetPages() {
    const out = [];
    for (const area of AREAS) {
        const dir = path.join(jsRoot, 'Pages', area);
        for (const f of readdirSync(dir).filter((x) => x.endsWith('.vue'))) {
            out.push({ area, file: f, rel: `Pages/${area}/${f}`, abs: path.join(dir, f) });
        }
    }
    return out;
}

function usesI18n(src) {
    return /useI18n/.test(src) || /\$t\(/.test(src);
}

// Flatten a catalog to a set of dotted leaf keys. flatJson:true means vue-i18n
// resolves both a nested path and a flat dotted key, so accept both forms.
function catalogKeys(ns) {
    const p = path.join(localesDir, `${ns}.json`);
    if (!existsSync(p)) return null;
    const obj = JSON.parse(readFileSync(p, 'utf8'));
    const keys = new Set();
    const walk = (node, prefix) => {
        for (const [k, v] of Object.entries(node)) {
            const key = prefix ? `${prefix}.${k}` : k;
            keys.add(key); // flat dotted key as written
            if (v && typeof v === 'object' && !Array.isArray(v)) walk(v, key);
        }
    };
    walk(obj, '');
    return keys;
}

// Every t('literal' ...) and $t('literal' ...) key whose head namespace is one
// this item wired into.
function wiredKeysIn(src) {
    const out = [];
    // Capture the literal key plus the next non-space char. A key built by
    // concatenation (t('...election_' + status, ...)) is dynamic: the char
    // after the quote is '+', and the runtime key is not this literal, so
    // skip it here. Its concrete forms are pinned by the catalog directly.
    const re = /\$?t\(\s*'([^']+)'\s*(.)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        const key = m[1];
        const next = m[2];
        if (next === '+') continue; // dynamic (concatenated) key
        const dot = key.indexOf('.');
        if (dot < 0) continue;
        const head = key.slice(0, dot);
        if (NS.has(head)) out.push(key);
    }
    return out;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect wiring and key shape', () => {
    assert.equal(usesI18n("const { t } = useI18n();"), true);
    assert.equal(usesI18n("{{ $t('x.y') }}"), true);
    assert.equal(usesI18n("<h1>Plain</h1>"), false);
    assert.deepEqual(wiredKeysIn("t('places.messages.title') and t('leaflet')"), ['places.messages.title']);
    const pages = targetPages();
    assert.ok(pages.length >= 40, `expected the full target page set, got ${pages.length}`);
    console.log(`  target pages: ${pages.length} across ${AREAS.join(', ')}`);
});

// ── SECTION 2 — compile gate (Vite substitute for the worktree).
test('compile gate — every target page compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const p of targetPages()) {
        const src = readFileSync(p.abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: p.abs });
            if (errors && errors.length) { broken.push(`${p.rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            compileScript(descriptor, { id: p.rel, inlineTemplate: true });
        } catch (e) {
            broken.push(`${p.rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${targetPages().length} pages, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `page components must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption (majority resolve body copy through t()).
test('adoption — a majority of target page components resolve body copy through t()', () => {
    const pages = targetPages();
    const perArea = {};
    let wired = 0;
    for (const area of AREAS) perArea[area] = { wired: 0, total: 0 };
    for (const p of pages) {
        const on = usesI18n(readFileSync(p.abs, 'utf8'));
        perArea[p.area].total += 1;
        if (on) { perArea[p.area].wired += 1; wired += 1; }
    }
    for (const area of AREAS) console.log(`  ${area}: ${perArea[area].wired}/${perArea[area].total}`);
    console.log(`  aggregate: ${wired}/${pages.length}`);
    assert.ok(wired * 2 > pages.length, `expected a majority (> ${pages.length / 2}) wired, got ${wired}`);
});

// ── SECTION 4 — no raw key leak on every page this item wired.
test('no raw key leak — every wired t() key resolves in the en catalog', () => {
    const unresolved = [];
    let checked = 0;
    for (const area of AREAS) {
        for (const file of WIRED[area]) {
            const abs = path.join(jsRoot, 'Pages', area, file);
            const src = readFileSync(abs, 'utf8');
            for (const key of wiredKeysIn(src)) {
                checked += 1;
                const dot = key.indexOf('.');
                const ns = key.slice(0, dot);
                const rest = key.slice(dot + 1);
                const keys = catalogKeys(ns);
                if (!keys) { unresolved.push(`${area}/${file}: catalog ${ns}.json missing`); continue; }
                if (!keys.has(rest)) unresolved.push(`${area}/${file}: ${ns}.${rest} absent from ${ns}.json`);
            }
        }
    }
    console.log(`  checked ${checked} wired keys across ${AREAS.map((a) => WIRED[a].length).reduce((x, y) => x + y, 0)} pages`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.equal(unresolved.length, 0, `wired keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 4b — the dynamic (concatenated) key families are complete.
test('dynamic key families — every runtime form of a concatenated key exists', () => {
    const keys = catalogKeys('c_legislature_workspace');
    assert.ok(keys, 'c_legislature_workspace.json exists');
    // Index.vue: t('...index.election_' + status). Every election status form.
    const statuses = ['scheduled', 'approval_open', 'finalist_cutoff', 'ranked_open',
        'voting_closed', 'tabulating', 'certified', 'audit_rerun', 'final'];
    const missing = [];
    for (const s of statuses) if (!keys.has(`index.election_${s}`)) missing.push(`index.election_${s}`);
    // Index.vue: t('...index.adm_' + i), i in 0..6.
    for (let i = 0; i <= 6; i += 1) if (!keys.has(`index.adm_${i}`)) missing.push(`index.adm_${i}`);
    console.log(`  dynamic families checked: ${statuses.length + 7} keys, missing ${missing.length}`);
    for (const k of missing) console.log(`    ${k}`);
    assert.deepEqual(missing, [], `dynamic key forms must exist: ${missing.join(', ')}`);
});

// ── SECTION 5 — a representative wired page per area is confirmed.
test('representatives — one wired page per area resolves body copy through t()', () => {
    const reps = { Civic: 'Journeys.vue', Legislature: 'SessionArchive.vue', Elections: 'CandidacyRegistration.vue' };
    for (const [area, file] of Object.entries(reps)) {
        const abs = path.join(jsRoot, 'Pages', area, file);
        const src = readFileSync(abs, 'utf8');
        assert.ok(/useI18n/.test(src), `${area}/${file} imports useI18n`);
        const keys = wiredKeysIn(src);
        assert.ok(keys.length >= 3, `${area}/${file} resolves body copy through t() (${keys.length} keys)`);
        console.log(`  ${area}/${file}: ${keys.length} wired keys`);
    }
});

// ── SECTION 6 — the major election page bodies each call useI18n() for body
// text. The done_when clause "the major ... election page bodies call
// useI18n() for body text" was not enforced by the aggregate/representative
// checks. This section pins each named major election body directly, so a
// regression that rips out the wiring turns the pin red.
test('major election bodies — every named election page wires body copy through t()', () => {
    const thin = [];
    for (const file of MAJOR_ELECTION_BODIES) {
        const abs = path.join(jsRoot, 'Pages', 'Elections', file);
        const src = readFileSync(abs, 'utf8');
        const usesComposition = /useI18n/.test(src);
        const keys = wiredKeysIn(src);
        console.log(`  Elections/${file}: useI18n=${usesComposition} wired=${keys.length}`);
        if (!usesComposition) { thin.push(`${file}: no useI18n import`); continue; }
        if (keys.length < 3) thin.push(`${file}: only ${keys.length} wired keys (< 3)`);
    }
    assert.deepEqual(thin, [], `major election bodies must wire body copy: ${thin.join(' | ')}`);
});

// ── SECTION 7 — Elections area coverage is now full, not thin.
test('elections coverage — every Elections page component uses i18n', () => {
    const dir = path.join(jsRoot, 'Pages', 'Elections');
    const files = readdirSync(dir).filter((x) => x.endsWith('.vue'));
    const off = files.filter((f) => !usesI18n(readFileSync(path.join(dir, f), 'utf8')));
    console.log(`  Elections: ${files.length - off.length}/${files.length} wired`);
    for (const f of off) console.log(`    unwired: ${f}`);
    assert.deepEqual(off, [], `every Elections page must use i18n: ${off.join(', ')}`);
});
