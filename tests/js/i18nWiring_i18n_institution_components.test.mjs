// node --experimental-vm-modules --test tests/js/i18nWiring_i18n_institution_components.test.mjs
//
// Catalogue-lane pin for the namespace c_institution_components. The 26
// institution component bodies (Electoral, Executive, Judiciary,
// Legislature, Organizations) wire user-visible copy through vue-i18n. This
// test is DB-free. It reads the .vue sources and the en catalog directly.
//
//   1. COMPILE GATE. Every lane component compiles with @vue/compiler-sfc
//      (compileScript + compileTemplate, scoped where the SFC has a scoped
//      style). A worktree cannot reach the Vite gate, so a compiled parse
//      here is the gate for the edits this lane makes.
//   2. ADOPTION. Every lane component imports useI18n.
//   3. NO RAW KEY LEAK. Every t('c_institution_components.<key>') the lane
//      references resolves to a real en catalog entry.
//   4. CATALOG SHAPE. The catalog file parses; every value is a non-empty
//      string.
//   5. NO RAW SENTENCE. No lane component holds a raw English sentence of
//      4+ words in a template text node outside t() (data-no-i18n subtrees,
//      interpolations, script and style excluded).
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
const NS = 'c_institution_components';
const catalogPath = path.join(jsRoot, 'i18n/locales/en', `${NS}.json`);

// The lane list, as [subdir, filename] segments. The census note: never
// build a page path from one full 'resources/js/…/File.vue' literal — the
// NavRoleGateParity census reads such literals as mount companions, and this
// is a SOURCE pin. Every path is assembled from parts.
const COMPONENTS_DIR = 'Components';
const LANE = [
    ['Electoral', 'ApproveSwitch.vue'],
    ['Electoral', 'BallotReceipt.vue'],
    ['Electoral', 'CandidateRow.vue'],
    ['Electoral', 'FinalistLine.vue'],
    ['Electoral', 'PhaseBanner.vue'],
    ['Electoral', 'RankList.vue'],
    ['Electoral', 'StvBar.vue'],
    ['Electoral', 'StvRound.vue'],
    ['Executive', 'DepartmentCard.vue'],
    ['Executive', 'OrderScopeCard.vue'],
    ['Judiciary', 'Art4Section5Tracker.vue'],
    ['Judiciary', 'CaseLifecycle.vue'],
    ['Judiciary', 'JudicialConfirmations.vue'],
    ['Judiciary', 'JudicialNominations.vue'],
    ['Judiciary', 'JurorScreening.vue'],
    ['Judiciary', 'PanelTable.vue'],
    ['Legislature', 'AgendaStrip.vue'],
    ['Legislature', 'ArchivePager.vue'],
    ['Legislature', 'ConsentVoteCard.vue'],
    ['Legislature', 'ConstituentConsentPanel.vue'],
    ['Legislature', 'SeatMap.vue'],
    ['Legislature', 'VoteCastList.vue'],
    ['Legislature', 'VoteTally.vue'],
    ['Organizations', 'CgcGovernors.vue'],
    ['Organizations', 'OrganizationNav.vue'],
    ['Organizations', 'OwnershipPanel.vue'],
];

function laneFiles() {
    return LANE.map(([dir, file]) => ({
        rel: [COMPONENTS_DIR, dir, file].join('/'),
        abs: path.join(jsRoot, COMPONENTS_DIR, dir, file),
    }));
}

function usesI18n(src) {
    return /useI18n/.test(src);
}

// Flatten the catalog to a set of dotted leaf keys (flatJson tolerated).
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

// Every literal t('c_institution_components.<key>' …) / t("…") in the source.
function referencedKeys(src) {
    const out = [];
    const re = new RegExp(`t\\(\\s*['"]${NS}\\.([A-Za-z0-9_.]+)['"]`, 'g');
    let m;
    while ((m = re.exec(src)) !== null) out.push(m[1]);
    return out;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect wiring, keys and raw sentences', () => {
    assert.equal(usesI18n("const { t } = useI18n();"), true);
    assert.equal(usesI18n('<h1>Plain</h1>'), false);
    assert.deepEqual(
        referencedKeys(`t('${NS}.a.b', 'x') and t("${NS}.c", 'y') and t('other.z','q')`),
        ['a.b', 'c'],
    );
    // The raw-sentence heuristic flags an unwired sentence, allows a wired one.
    assert.ok(rawSentences('<template><p>This is four words here</p></template>').length >= 1);
    assert.equal(rawSentences('<template><p>{{ t(\'x.y\', \'This is four words here\') }}</p></template>').length, 0);
    assert.equal(rawSentences('<template><p><span data-no-i18n>filing arrives with the judiciary</span></p></template>').length, 0);
    assert.equal(rawSentences('<template><p class="citation">Art. II §2 · CLK-06</p></template>').length, 0);
    // The static-attribute heuristic flags an unwired label, allows a bound one.
    assert.ok(staticUserAttrs('<template><div aria-label="Path A"></div></template>').length >= 1);
    assert.equal(staticUserAttrs('<template><div :aria-label="t(\'x.y\', \'Path A\')"></div></template>').length, 0);
    assert.equal(staticUserAttrs('<template><div :aria-label="rowName"></div></template>').length, 0);
    assert.equal(laneFiles().length, 26);
    console.log(`  lane components: ${laneFiles().length}`);
});

// The raw-sentence heuristic. Returns the offending text fragments.
// Strategy: strip <script>/<style>, strip comments, then walk tags keeping a
// stack so any text inside a data-no-i18n subtree is ignored. For each live
// text node, drop {{ }} interpolations and count English word tokens that are
// not citation tokens; 4+ such tokens in one node is a raw sentence.
const CITATION_TOKEN = /^(Art|CLK|WF|ESM|IO|CGC|STV|RCV|PR|BoG|UTC|F|R|R\d+|F-[A-Z]+-\d+|R-\d+|CLK-\d+|WF-[A-Z]+-\d+|§+\d*|[IVXLC]+|\d+)$/;
function tokenizeWords(text) {
    // Word token = a run of letters (with internal apostrophes/hyphens).
    const raw = text.match(/[A-Za-z][A-Za-z'’-]*/g) || [];
    return raw.filter((w) => {
        // Strip a trailing/leading citation-ish shape.
        if (CITATION_TOKEN.test(w)) return false;
        return true;
    });
}
function rawSentences(source) {
    // Isolate the template block.
    const tmplMatch = source.match(/<template>[\s\S]*<\/template>/);
    let t = tmplMatch ? tmplMatch[0] : source;
    // Drop nested <script>/<style> just in case, and comments.
    t = t.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '');
    t = t.replace(/<!--[\s\S]*?-->/g, '');

    const offenders = [];
    const tagRe = /<\/?([A-Za-z][A-Za-z0-9]*)((?:[^>"']|"[^"]*"|'[^']*')*?)(\/?)>/g;
    const stack = []; // { noI18n }
    let last = 0;
    let m;
    const noI18nActive = () => stack.length > 0 && stack[stack.length - 1].noI18n;
    while ((m = tagRe.exec(t)) !== null) {
        // Text node between the previous tag and this one.
        const text = t.slice(last, m.index);
        if (text && !noI18nActive()) {
            const cleaned = text.replace(/\{\{[\s\S]*?\}\}/g, ' ').trim();
            if (cleaned && tokenizeWords(cleaned).length >= 4) offenders.push(cleaned);
        }
        last = tagRe.lastIndex;

        const whole = m[0];
        const name = m[1];
        const attrs = m[2] || '';
        const selfClose = m[3] === '/';
        const isClose = whole.startsWith('</');
        if (isClose) {
            // Pop the nearest matching tag.
            for (let i = stack.length - 1; i >= 0; i -= 1) {
                if (stack[i].name === name) {
                    stack.length = i;
                    break;
                }
            }
        } else if (!selfClose) {
            const parentNo = noI18nActive();
            stack.push({ name, noI18n: parentNo || /\bdata-no-i18n\b/.test(attrs) });
        }
    }
    // Trailing text after the last tag.
    const tail = t.slice(last);
    if (tail && !noI18nActive()) {
        const cleaned = tail.replace(/\{\{[\s\S]*?\}\}/g, ' ').trim();
        if (cleaned && tokenizeWords(cleaned).length >= 4) offenders.push(cleaned);
    }
    return offenders;
}

// Static user-text attributes. aria-label / title / placeholder / alt values
// are user copy and get wired through :aria-label="t(…)". A STATIC value (the
// unbound attribute form, no leading ':' or 'v-bind:') that holds two or more
// non-citation word tokens is a raw leak the text-node heuristic cannot see.
// This closes the attribute gap: 'Path A' and 'The three … paths' are label
// values, not text nodes.
const USER_ATTR = /(?<![:.\w-])(aria-label|title|placeholder|alt)\s*=\s*("([^"]*)"|'([^']*)')/g;
function staticUserAttrs(source) {
    const tmplMatch = source.match(/<template>[\s\S]*<\/template>/);
    let t = tmplMatch ? tmplMatch[0] : source;
    t = t.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '');
    t = t.replace(/<!--[\s\S]*?-->/g, '');
    const offenders = [];
    let m;
    USER_ATTR.lastIndex = 0;
    while ((m = USER_ATTR.exec(t)) !== null) {
        const value = m[3] !== undefined ? m[3] : m[4];
        if (value && tokenizeWords(value).length >= 2) offenders.push(`${m[1]}="${value}"`);
    }
    return offenders;
}

// ── SECTION 2 — compile gate.
test('compile gate — every lane component compiles with @vue/compiler-sfc', () => {
    const broken = [];
    for (const p of laneFiles()) {
        const src = readFileSync(p.abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: p.abs });
            if (errors && errors.length) {
                broken.push(`${p.rel}: ${errors.map((e) => e.message).join('; ')}`);
                continue;
            }
            const scoped = (descriptor.styles || []).some((s) => s.scoped);
            const s = compileScript(descriptor, { id: p.rel });
            compileTemplate({
                source: descriptor.template.content,
                filename: p.abs,
                id: p.rel,
                scoped,
                compilerOptions: { bindingMetadata: s.bindings },
            });
        } catch (e) {
            broken.push(`${p.rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${laneFiles().length} components, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `lane components must compile: ${broken.join(' | ')}`);
});

// ── SECTION 3 — adoption.
test('adoption — every lane component imports useI18n', () => {
    const off = [];
    for (const p of laneFiles()) {
        if (!usesI18n(readFileSync(p.abs, 'utf8'))) off.push(p.rel);
    }
    console.log(`  useI18n present: ${laneFiles().length - off.length}/${laneFiles().length}`);
    for (const f of off) console.log(`    missing useI18n: ${f}`);
    assert.deepEqual(off, [], `every lane component must import useI18n: ${off.join(', ')}`);
});

// ── SECTION 4 — no raw key leak.
test('no raw key leak — every referenced key resolves in the en catalog', () => {
    const keys = catalogKeys();
    assert.ok(keys, `catalog ${NS}.json must exist`);
    const unresolved = [];
    let checked = 0;
    for (const p of laneFiles()) {
        const src = readFileSync(p.abs, 'utf8');
        for (const key of referencedKeys(src)) {
            checked += 1;
            if (!keys.has(key)) unresolved.push(`${p.rel}: ${NS}.${key} absent from ${NS}.json`);
        }
    }
    console.log(`  checked ${checked} referenced keys across ${laneFiles().length} components`);
    for (const u of unresolved) console.log(`    ${u}`);
    assert.deepEqual(unresolved, [], `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 5 — catalog shape.
test('catalog shape — the catalog parses and every value is a non-empty string', () => {
    assert.ok(existsSync(catalogPath), `${NS}.json must exist`);
    const obj = JSON.parse(readFileSync(catalogPath, 'utf8'));
    const bad = Object.entries(obj).filter(([, v]) => typeof v !== 'string' || v.length === 0);
    console.log(`  catalog keys: ${Object.keys(obj).length}, bad values ${bad.length}`);
    for (const [k] of bad) console.log(`    non-string/empty: ${k}`);
    assert.equal(bad.length, 0, `catalog values must be non-empty strings: ${bad.map(([k]) => k).join(', ')}`);
});

// ── SECTION 6 — no raw sentence.
test('no raw sentence — no lane component leaks a raw English sentence in the template', () => {
    const leaks = [];
    for (const p of laneFiles()) {
        const src = readFileSync(p.abs, 'utf8');
        const found = rawSentences(src);
        if (found.length) leaks.push(`${p.rel}: ${found.slice(0, 3).map((x) => JSON.stringify(x)).join(' | ')}`);
    }
    console.log(`  scanned ${laneFiles().length} components, leaks ${leaks.length}`);
    for (const l of leaks) console.log(`    ${l}`);
    assert.deepEqual(leaks, [], `templates must not leak raw English: ${leaks.join(' || ')}`);
});

// ── SECTION 7 — no static user-text attribute.
test('no static attr leak — no lane component leaves a raw aria-label/title/placeholder/alt', () => {
    const leaks = [];
    for (const p of laneFiles()) {
        const src = readFileSync(p.abs, 'utf8');
        const found = staticUserAttrs(src);
        if (found.length) leaks.push(`${p.rel}: ${found.slice(0, 4).join(' | ')}`);
    }
    console.log(`  scanned ${laneFiles().length} components, static-attr leaks ${leaks.length}`);
    for (const l of leaks) console.log(`    ${l}`);
    assert.deepEqual(leaks, [], `user-text attributes must be wired: ${leaks.join(' || ')}`);
});
