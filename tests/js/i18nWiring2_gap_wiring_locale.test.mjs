// node --experimental-vm-modules --test tests/js/i18nWiring2_gap_wiring_locale.test.mjs
//
// Lane pins for gap-wiring-locale (five behaviour gaps, 2026-09-15).
//   1  locale persistence — both shells route the header switch through the
//      persistLocale helper; the helper posts to the F-IND-002 endpoint for a
//      signed-in viewer and localStorage for a guest.
//   2  spoken-language list sourced from THE registry, not a hardcoded five.
//   3  chrome dicts loaded by glob for EVERY locale, no static per-locale import.
//   4  the six '@handle' catalog strings escaped as {'@'} so they compile.
//
// DB-free. Source-text regex pins plus a real unit test of persistLocale and a
// real @intlify/message-compiler compile of the six escaped strings.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { baseCompile } from '@intlify/message-compiler';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(path.join(root, rel), 'utf8');

const INDEX = 'resources/js/i18n/index.js';
const SHELL_V1 = 'resources/js/Layouts/AppShell.vue';
const SHELL_V2 = 'resources/js/Layouts/AppShellV2.vue';
const REGISTER = 'resources/js/Pages/Auth/Register.vue';
const GENERATED = 'resources/js/i18n/locales.generated.js';
const CAT_INST = 'resources/js/i18n/locales/en/c_institutions.json';
const CAT_COMP = 'resources/js/i18n/locales/en/c_institution_components.json';

// ── gap 1: both shells persist through the helper ───────────────────────────
test('gap 1 — both shells route the header locale switch through persistLocale', () => {
    for (const rel of [SHELL_V1, SHELL_V2]) {
        const src = read(rel);
        assert.match(src, /import\s*\{[^}]*\bpersistLocale\b[^}]*\}\s*from\s*'@\/i18n\/index\.js'/,
            `${rel} imports persistLocale from the i18n module`);
        // The call lives inside onLocaleChange and carries the authenticated flag + router.
        assert.match(src, /function onLocaleChange\([\s\S]*?persistLocale\(\s*code\s*,\s*\{\s*authenticated:[^}]*router\s*\}\s*\)/,
            `${rel} onLocaleChange calls persistLocale(code, { authenticated, router })`);
    }
});

// Extract the persistLocale function body from the real source and evaluate it
// in isolation (index.js itself is not node-importable — it uses Vite's
// import.meta.glob and imports vue-i18n).
function loadPersistLocale() {
    const src = read(INDEX);
    // The function body lines are all indented; its terminator is a '}' alone
    // at column 0. Capture from the signature to that first line-anchored close.
    const m = src.match(/export function persistLocale[\s\S]*?\n\}/);
    assert.ok(m, 'index.js exports persistLocale');
    const body = m[0].replace('export function persistLocale', 'function persistLocale');
    // LOCALE_STORAGE_KEY is a module const; inject it as the closure value.
    // eslint-disable-next-line no-new-func
    return new Function('LOCALE_STORAGE_KEY', `${body}\nreturn persistLocale;`)('cga.locale');
}

test('gap 1 — persistLocale posts to the F-IND-002 endpoint when authenticated', () => {
    const persistLocale = loadPersistLocale();
    const calls = [];
    const router = { post: (url, data, opts) => calls.push({ url, data, opts }) };
    const branch = persistLocale('es', { authenticated: true, router });
    assert.equal(branch, 'endpoint');
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/civic/record/profile', 'same endpoint the settings panel posts to');
    assert.deepEqual(calls[0].data, { locale: 'es' });
    assert.equal(calls[0].opts.preserveScroll, true);
});

test('gap 1 — persistLocale writes localStorage for a guest, and never throws', () => {
    const persistLocale = loadPersistLocale();
    const writes = [];
    const storage = { setItem: (k, v) => writes.push([k, v]) };
    assert.equal(persistLocale('fr', { authenticated: false, storage }), 'storage');
    assert.deepEqual(writes, [['cga.locale', 'fr']]);
    // A guest with no router still persists to storage (no accidental endpoint).
    assert.equal(persistLocale('pt', { authenticated: true, router: null, storage }), 'storage');
    // A throwing storage (private window / blocked) degrades to noop, never throws.
    const bad = { setItem: () => { throw new Error('blocked'); } };
    assert.equal(persistLocale('hi', { storage: bad }), 'noop');
});

// ── gap 2: spoken-language list from the registry ───────────────────────────
function registryCodes() {
    // One locale object literal per line (same read the coverage pin uses).
    return read(GENERATED).split('\n')
        .filter((l) => /code:\s*"/.test(l))
        .map((l) => l.match(/code:\s*"([^"]+)"/)[1]);
}

test('gap 2 — Register.vue derives its language list from the registry (ALL_LOCALES)', () => {
    const src = read(REGISTER);
    assert.match(src, /import\s*\{[^}]*\bALL_LOCALES\b[^}]*\}\s*from\s*'@\/i18n\/index\.js'/,
        'Register.vue imports ALL_LOCALES');
    assert.match(src, /const LANGUAGES\s*=\s*ALL_LOCALES\.map\(\s*\(l\)\s*=>\s*\(\{\s*value:\s*l\.code/,
        'LANGUAGES maps every registry entry to { value: l.code, ... }');
    // No residual hand-copied array (the drifted five).
    assert.doesNotMatch(src, /const LANGUAGES\s*=\s*\[\s*\{\s*value:\s*'en'/,
        'the hardcoded five-entry array is gone');
    // Because value === l.code for every entry, the offered codes ARE the
    // registry codes. Prove the registry is non-empty and well-formed.
    const codes = registryCodes();
    assert.ok(codes.length >= 7, `registry has the enabled set and more (got ${codes.length})`);
    assert.ok(codes.includes('en') && codes.includes('fr') && codes.includes('pt'),
        'registry carries en plus the newly enabled fr/pt');
});

// ── gap 3: glob-loaded chrome dicts, no static per-locale import ─────────────
test('gap 3 — index.js loads chrome dicts by glob with no static per-locale import', () => {
    const src = read(INDEX);
    assert.match(src, /import\.meta\.glob\(\s*'\.\/\*\.json'\s*,\s*\{\s*eager:\s*true\s*\}\s*\)/,
        'root chrome dicts are loaded by the eager glob');
    assert.match(src, /m\[1\]\s*===\s*'coverage'/, 'coverage.json is excluded by name');
    for (const code of ['en', 'es', 'ar', 'zh-Hans', 'hi']) {
        const re = new RegExp(`import\\s+\\w+\\s+from\\s+'\\./${code.replace('-', '\\-')}\\.json'`);
        assert.doesNotMatch(src, re, `no static import of ./${code}.json remains`);
    }
    // The fallback contract the coverage pin also checks stays intact.
    assert.match(src, /fallbackLocale:\s*\{[^}]*default:\s*\[\s*'en'\s*\]/,
        'default English fallback preserved');
});

// ── gap 4: the six '@handle' strings compile after escaping ─────────────────
const ATHANDLE_KEYS = {
    [CAT_INST]: ['org_detail.f_start_name', 'org_detail.opt_name'],
    [CAT_COMP]: [
        'cgc_governors.query_name', 'cgc_governors.search_prompt',
        'judicial_nominations.find_by_name', 'judicial_nominations.query_name',
    ],
};

function compiles(msg) {
    try { baseCompile(String(msg), { onError: (e) => { throw e; } }); return true; }
    catch (e) { return e?.message ?? 'compile error'; }
}

test('gap 4 — the six @handle catalog strings are escaped and compile (C5)', () => {
    // Sanity: a bare @handle really does fail the C5 compiler.
    assert.notEqual(compiles('Public name or @handle'), true, 'bare @handle fails to compile');
    for (const [rel, keys] of Object.entries(ATHANDLE_KEYS)) {
        const bag = JSON.parse(read(rel));
        for (const k of keys) {
            const v = bag[k];
            assert.ok(typeof v === 'string', `${rel}:${k} exists`);
            assert.match(v, /\{'@'\}handle/, `${rel}:${k} escapes @ as {'@'}`);
            assert.doesNotMatch(v, /(^|[^}'])@handle/, `${rel}:${k} has no bare @handle`);
            assert.equal(compiles(v), true, `${rel}:${k} compiles under the runtime parser`);
        }
    }
});
