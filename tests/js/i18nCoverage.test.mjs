// node --experimental-vm-modules --test tests/js/i18nCoverage.test.mjs
//
// L1 · languages — settled conference-language scenario coverage.
//
// DB-free. Reads the locale registry (resources/js/i18n/locales.generated.js)
// and the per-namespace catalogs (resources/js/i18n/locales/<code>/<ns>.json)
// directly. Judges the register criteria for the settled conference languages:
//   - missing / fallback strings (English key set vs each conference locale),
//   - scenarios and lessons (c_learn, c_education),
//   - controls and errors (c_rooms, c_ui),
//   - audio / language fallbacks (room voice error keys + the video player),
//   - the BCP-47 conference Chinese tag (zh-Hans vs a region tag).
//
// The instrument passes its own self-check first (a measure that cannot fail
// measures nothing). The coverage blocks assert full parity per the register
// pass criterion ("every English key present per conference locale") and print
// the full per-locale, per-file gap before asserting.
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (p) => readFileSync(path.join(root, p), 'utf8');
const localesDir = path.join(root, 'resources/js/i18n/locales');

// ── The settled conference languages, read from THE registry (not hardcoded).
const registrySrc = read('resources/js/i18n/locales.generated.js');
// The registry writes one locale object literal per line, so read enabled
// codes line by line (a spanning regex mis-pairs code with a later enabled).
const ENABLED = registrySrc.split('\n')
    .filter((l) => /code:\s*"/.test(l) && /enabled:\s*true/.test(l))
    .map((l) => l.match(/code:\s*"([^"]+)"/)[1]);
const CONFERENCE = ENABLED.filter((c) => c !== 'en');

// ── Helpers.
function leafKeys(obj, prefix = '') {
    const out = [];
    for (const [k, v] of Object.entries(obj)) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) out.push(...leafKeys(v, key));
        else out.push(key);
    }
    return out;
}
function loadNs(code, file) {
    const p = path.join(localesDir, code, file);
    if (!existsSync(p)) return null; // absent file
    return JSON.parse(readFileSync(p, 'utf8'));
}
function enKeysFor(file) {
    return leafKeys(JSON.parse(readFileSync(path.join(localesDir, 'en', file), 'utf8')));
}
function keyDiff(enKeys, locKeys) {
    const have = new Set(locKeys);
    return enKeys.filter((k) => !have.has(k));
}

const enFiles = readdirSync(path.join(localesDir, 'en')).filter((f) => f.endsWith('.json')).sort();

// Namespaces a conference attendee reads directly (annotation only — the
// completeness assertions cover EVERY English file, no desk-side exclusion).
const CONFERENCE_FACING = new Set([
    'c_civic.json', 'c_learn.json', 'c_education.json', 'c_electoral.json', 'c_legislature.json',
    'c_legislature_workspace.json', 'c_executive.json', 'c_judiciary.json', 'c_organizations.json',
    'c_rooms.json', 'c_live_commons.json', 'c_navigation.json', 'c_ui.json', 'c_shell.json',
    'c_shellv2.json', 'c_surface.json', 'c_explore.json', 'c_community.json', 'c_achievements.json',
    'c_federation.json', 'c_bill.json', 'c_setup.json', 'c_references.json', 'c_geodata.json',
    'c_invite.json', 'c_host.json', 'c_term_sync.json', 'c_loading.json',
    'auth.json', 'elections.json', 'legislature.json', 'judiciary.json', 'jurisdictions.json',
    'executive.json', 'organizations.json', 'invite.json', 'pages.json', 'places.json',
    'civic.json', 'components.json', 'chrome.json', 'flows.json', 'support.json', 'system.json',
    'registry.json', 'setup.json', 'references.json',
]);

// ── SECTION 1 — instrument self-check (control, must pass).
test('instrument — keyDiff detects a seeded gap and passes an identical set', () => {
    assert.deepEqual(keyDiff(['a', 'b'], ['a', 'b']), [], 'identical sets report no missing key');
    assert.deepEqual(keyDiff(['a', 'b', 'c'], ['a']), ['b', 'c'], 'a seeded gap is reported');
    assert.deepEqual(leafKeys({ a: { b: 'x' }, c: 'y' }).sort(), ['a.b', 'c'], 'leafKeys flattens nested + flat');
    assert.equal(CONFERENCE.length, 6, `expected 6 non-English conference locales, got ${CONFERENCE.length}: ${CONFERENCE.join(',')}`);
    assert.deepEqual([...CONFERENCE].sort(), ['ar', 'es', 'fr', 'hi', 'pt', 'zh-Hans'], 'the settled conference set');
    assert.ok(enFiles.length > 0, 'English base has namespace files');
    console.log(`  conference languages: ${CONFERENCE.join(', ')}  |  English namespaces: ${enFiles.length}`);
});

// ── SECTION 2 — file-level coverage (whole namespaces absent = total fallback).
test('file coverage — every English namespace file exists in every conference locale', () => {
    const report = {};
    for (const code of CONFERENCE) {
        const have = new Set(readdirSync(path.join(localesDir, code)).filter((f) => f.endsWith('.json')));
        report[code] = enFiles.filter((f) => !have.has(f));
    }
    console.log('  absent namespace files (present in en, missing in the locale):');
    for (const code of CONFERENCE) {
        const facing = report[code].filter((f) => CONFERENCE_FACING.has(f));
        console.log(`    ${code}: ${report[code].length} absent — ${report[code].join(', ') || '(none)'}` +
            (facing.length ? `  [conference-facing: ${facing.join(', ')}]` : ''));
    }
    const absentEverywhere = enFiles.filter((f) => CONFERENCE.every((c) => report[c].includes(f)));
    console.log(`  absent from ALL 6 conference locales: ${absentEverywhere.join(', ') || '(none)'}`);
    const totalAbsent = Object.values(report).reduce((n, a) => n + a.length, 0);
    assert.equal(totalAbsent, 0, `${totalAbsent} namespace files are absent across conference locales; absent from all six: ${absentEverywhere.join(', ')}`);
});

// ── SECTION 3 — key-level coverage (missing keys fall back to English at runtime).
test('key coverage — every English key is present in every conference locale', () => {
    let grand = 0;
    const perLocale = {};
    for (const code of CONFERENCE) {
        let localeMissing = 0;
        const files = [];
        for (const file of enFiles) {
            const en = enKeysFor(file);
            const loc = loadNs(code, file);
            const missing = loc === null ? en : keyDiff(en, leafKeys(loc));
            if (missing.length) files.push({ file, missing: missing.length, of: en.length, absent: loc === null });
            localeMissing += missing.length;
        }
        perLocale[code] = { localeMissing, files };
        grand += localeMissing;
    }
    console.log('  missing keys per conference locale (fall back to English):');
    for (const code of CONFERENCE) {
        console.log(`    ${code}: ${perLocale[code].localeMissing} keys across ${perLocale[code].files.length} files`);
        for (const f of perLocale[code].files) {
            console.log(`        ${f.file}: ${f.missing}/${f.of}${f.absent ? ' (FILE ABSENT)' : ''}`);
        }
    }
    console.log(`  TOTAL missing keys across all conference locales: ${grand}`);
    assert.equal(grand, 0, `${grand} English keys are missing across conference locales and render as English fallback`);
});

// ── SECTION 4 — scenarios and lessons (the register calls these out by name).
test('lessons/scenarios — c_learn guides + c_education lesson keys cover every conference locale', () => {
    const gaps = [];
    for (const code of CONFERENCE) {
        for (const file of ['c_learn.json', 'c_education.json']) {
            const en = enKeysFor(file);
            const loc = loadNs(code, file);
            const missing = loc === null ? en : keyDiff(en, leafKeys(loc));
            if (missing.length) gaps.push(`${code}/${file}: ${missing.length}/${en.length}${loc === null ? ' (ABSENT)' : ''}`);
        }
    }
    console.log('  lesson/scenario gaps:');
    for (const g of gaps) console.log(`    ${g}`);
    assert.equal(gaps.length, 0, `lesson/scenario namespaces are incomplete: ${gaps.join('; ')}`);
});

// ── SECTION 5a — room controls + audio/voice error and fallback strings.
test('controls/errors — c_rooms voice/audio error + fallback keys cover every conference locale', () => {
    const en = enKeysFor('c_rooms.json');
    const errKeys = en.filter((k) => /^error\.|audio|voice|speaker|unavailable|refused|unreachable/i.test(k));
    console.log(`  c_rooms audio/voice/error keys in en: ${errKeys.length}`);
    const gaps = [];
    for (const code of CONFERENCE) {
        const loc = loadNs(code, 'c_rooms.json');
        const missing = loc === null ? en : keyDiff(en, leafKeys(loc));
        if (missing.length) gaps.push(`${code}: ${missing.length}/${en.length}${loc === null ? ' (FILE ABSENT)' : ''}`);
    }
    console.log('  c_rooms coverage gaps:');
    for (const g of gaps) console.log(`    ${g}`);
    assert.equal(gaps.length, 0, `room control/error strings fall back to English: ${gaps.join('; ')}`);
});

// ── SECTION 5b — the multi-track video player: audio/language fallback controls.
test('audio/language fallbacks — the video player binds its controls and error text to i18n', () => {
    const rel = 'resources/js/Components/Media/MultiTrackVideoPlayer.vue';
    const src = read(rel);
    const bound = /useI18n|from ['"]vue-i18n['"]|\$t\(|[^a-zA-Z]t\(['"]/.test(src);
    // Evidence: the user-visible control labels and error/fallback messages.
    const literals = [
        ['audio-selector label', /vtrack-lbl"[^>]*>\s*<Volume2[^>]*\/>\s*Audio/],
        ['captions-selector label', /vtrack-lbl"[^>]*>\s*<Captions[^>]*\/>\s*Captions/],
        ['video error', /The video could not play/],
        ['audio error + language fallback', /The selected audio could not play\. You can keep watching, choose another language/],
        ['caption error + language fallback', /The selected captions could not load\. You can choose another language/],
    ];
    console.log(`  ${rel}: i18n-bound = ${bound}`);
    for (const [label, re] of literals) {
        console.log(`    hardcoded ${label}: ${re.test(src)}`);
    }
    assert.ok(bound, 'the video player renders audio-selector labels and audio/video/caption error+fallback messages as hardcoded English (no useI18n / t()); conference attendees see English controls and fallbacks');
});

// ── SECTION 6 — BCP-47 conference Chinese tag (settled-language correctness).
test('BCP-47 — the conference Chinese locale uses the script subtag zh-Hans, not a region tag', () => {
    assert.ok(CONFERENCE.includes('zh-Hans'), 'zh-Hans is a settled conference locale');
    for (const bad of ['zh', 'zh-TW', 'zh-CN', 'zh-Hant']) {
        assert.ok(!ENABLED.includes(bad), `${bad} is not enabled alongside zh-Hans (script-subtag form is authoritative)`);
    }
    console.log('  conference Chinese tag = zh-Hans (Simplified); no region/bare zh tag enabled');
});

// ── SECTION 6b — the fallback path itself: missing keys resolve to English.
test('fallback wiring — the i18n instance falls back to English for missing keys', () => {
    const idx = read('resources/js/i18n/index.js');
    assert.ok(/fallbackLocale:\s*\{[^}]*default:\s*\[\s*['"]en['"]\s*\]/.test(idx),
        'index.js declares a default English fallback (missing conference keys render English, not raw key ids)');
    console.log('  fallbackLocale default = [en]: missing conference keys render as English strings');
});
