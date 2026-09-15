// ============================================================================
// Gap lane fix-guest-locale — behaviour pins (JS side).
//
// Three follow-ups from gap-wiring-locale:
//   (1) GUEST LOCALE READ-BACK — persistLocale's guest branch now records the
//       choice server-side through POST /locale (LocaleController stores it in
//       the session under the key SetLocale reads), in addition to localStorage,
//       so a guest's header choice survives a full reload.
//   (2) SETTINGS IN THE FLYOUT — both shells (AppShellV2, AppShell) show a
//       Settings entry ABOVE Log out, linking to /civic/record?tab=settings,
//       through the c_gap_shell_operator settings keys.
//   (3) LINK-SYNTAX PROPAGATION is asserted PHP/CLI-side (check.mjs); the JS
//       side pins only the en escape shape here.
//
// DB-free. Source-text regex pins plus a real unit test of the persistLocale
// guest branch (router + storage stubs). Run on the Windows host:
//   node --experimental-vm-modules --test tests/js/i18nWiring2_fix_guest_locale.test.mjs
// ============================================================================
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (rel) => readFileSync(new URL('../../' + rel, import.meta.url), 'utf8');

const INDEX = 'resources/js/i18n/index.js';
const SHELLS = [
    ['resources/js/Layouts/AppShellV2.vue', 'app_shell_v2'],
    ['resources/js/Layouts/AppShell.vue', 'app_shell'],
];
const EN_CAT = 'resources/js/i18n/locales/en/c_gap_shell_operator.json';

// ── (1) guest read-back ─────────────────────────────────────────────────────
test('gap 1 — persistLocale guest branch posts to /locale', () => {
    const src = read(INDEX);
    assert.match(
        src,
        /router\.post\(\s*'\/locale'\s*,\s*\{\s*locale:\s*code\s*\}\s*,\s*\{[^}]*preserveScroll:\s*true[^}]*preserveState:\s*true[^}]*\}\s*\)/,
        'index.js persistLocale posts { locale: code } to /locale with preserveScroll + preserveState',
    );
    // The authenticated branch is unchanged — still the F-IND-002 endpoint.
    assert.match(
        src,
        /router\.post\(\s*'\/civic\/record\/profile'\s*,\s*\{\s*locale:\s*code\s*\}/,
        'index.js persistLocale authenticated branch still posts to /civic/record/profile',
    );
});

// Extract the persistLocale function body from the real source and evaluate it
// in isolation (no vue-i18n, no Inertia, no browser) so the guest branch's
// behaviour is exercised for real, not just matched by regex.
function loadPersistLocale() {
    const src = read(INDEX);
    const m = src.match(/export function persistLocale[\s\S]*?\n\}/);
    assert.ok(m, 'index.js exports persistLocale');
    const body = m[0].replace('export function persistLocale', 'function persistLocale');
    return new Function('LOCALE_STORAGE_KEY', `${body}\nreturn persistLocale;`)('cga.locale');
}

test('gap 1 — persistLocale guest with a router posts to /locale AND mirrors to storage', () => {
    const persistLocale = loadPersistLocale();
    const calls = [];
    const writes = [];
    const router = { post: (url, data, opts) => calls.push({ url, data, opts }) };
    const storage = { setItem: (k, v) => writes.push([k, v]) };

    const branch = persistLocale('es', { authenticated: false, router, storage });

    assert.equal(branch, 'storage', 'the guest branch still reports the storage write');
    assert.equal(calls.length, 1, 'exactly one server post');
    assert.equal(calls[0].url, '/locale');
    assert.deepEqual(calls[0].data, { locale: 'es' });
    assert.equal(calls[0].opts.preserveScroll, true);
    assert.equal(calls[0].opts.preserveState, true);
    assert.deepEqual(writes, [['cga.locale', 'es']], 'localStorage mirrors the choice');
});

test('gap 1 — persistLocale guest without a router only writes storage (no accidental post)', () => {
    const persistLocale = loadPersistLocale();
    const writes = [];
    const storage = { setItem: (k, v) => writes.push([k, v]) };
    assert.equal(persistLocale('fr', { authenticated: false, storage }), 'storage');
    assert.deepEqual(writes, [['cga.locale', 'fr']]);
});

test('gap 1 — persistLocale authenticated branch is unchanged (endpoint, no /locale post)', () => {
    const persistLocale = loadPersistLocale();
    const calls = [];
    const router = { post: (url, data, opts) => calls.push({ url, data, opts }) };
    assert.equal(persistLocale('ar', { authenticated: true, router }), 'endpoint');
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/civic/record/profile');
});

// ── (2) Settings in the flyout ──────────────────────────────────────────────
test('gap 2 — both shells show a Settings entry above Log out, linking to the record settings tab', () => {
    for (const [rel, keyBase] of SHELLS) {
        const src = read(rel);
        assert.match(
            src,
            /href="\/civic\/record\?tab=settings"/,
            `${rel} links the Settings entry to /civic/record?tab=settings`,
        );
        const key = `c_gap_shell_operator.${keyBase}.settings`;
        const escaped = key.replace(/[.?*+^$()[\]{}|\\]/g, '\\$&');
        assert.match(
            src,
            new RegExp(`t\\(\\s*'${escaped}'\\s*,\\s*'Settings'\\s*\\)`),
            `${rel} renders the Settings label through t('${key}', 'Settings')`,
        );
        // The Settings entry sits ABOVE Log out (its t() key appears earlier in the file).
        const settingsAt = src.indexOf(`${keyBase}.settings`);
        const logoutAt = src.indexOf(`${keyBase}.log_out`);
        assert.ok(settingsAt !== -1 && logoutAt !== -1, `${rel} carries both flyout keys`);
        assert.ok(settingsAt < logoutAt, `${rel} places Settings above Log out`);
    }
});

test('gap 2 — the en catalog carries both settings keys, verbatim English', () => {
    const cat = JSON.parse(read(EN_CAT));
    assert.equal(cat['app_shell_v2.settings'], 'Settings');
    assert.equal(cat['app_shell.settings'], 'Settings');
});

// ── (3) link-syntax escape shape (en side) ─────────────────────────────────
test("gap 3 — the six en @handle keys use the literal-@ escape {'@'}", () => {
    const inst = JSON.parse(read('resources/js/i18n/locales/en/c_institutions.json'));
    const comp = JSON.parse(read('resources/js/i18n/locales/en/c_institution_components.json'));
    const keys = [
        [inst, 'org_detail.f_start_name'],
        [inst, 'org_detail.opt_name'],
        [comp, 'cgc_governors.query_name'],
        [comp, 'cgc_governors.search_prompt'],
        [comp, 'judicial_nominations.find_by_name'],
        [comp, 'judicial_nominations.query_name'],
    ];
    for (const [bag, k] of keys) {
        assert.ok(bag[k] !== undefined, `en carries ${k}`);
        assert.ok(bag[k].includes("{'@'}"), `${k} escapes @ as {'@'}`);
        assert.ok(!/(^|[^'{])@[A-Za-z]/.test(bag[k]), `${k} carries no bare @handle`);
    }
});
