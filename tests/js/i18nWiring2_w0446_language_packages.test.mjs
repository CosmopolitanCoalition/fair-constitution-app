// node --experimental-vm-modules --test tests/js/i18nWiring2_w0446_language_packages.test.mjs
//
// Gap lane w0446-language-packages pin (operator ruling 2026-09-15). The
// operator-only "Language packages" card on /system/translations: export a
// target locale's outstanding strings, import a translated package back, and
// request a language nobody has opened.
//
// Three assertions:
//   (a) System/Translations.vue compiles with @vue/compiler-sfc;
//   (b) every new card string resolves as a key in en/c_system.json;
//   (c) the 2s poll, the export/import/confirm/request flows and the operator
//       gate are present in the page by regex.

import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const VUE = ROOT + 'resources/js/Pages/System/Translations.vue';
const CATALOG = ROOT + 'resources/js/i18n/locales/en/c_system.json';

const src = fs.readFileSync(VUE, 'utf8');
const catalog = JSON.parse(fs.readFileSync(CATALOG, 'utf8'));

// ---------------------------------------------------------------------------
// (a) the page compiles
// ---------------------------------------------------------------------------
test('System/Translations.vue compiles', () => {
    const { descriptor, errors } = parse(src, { filename: 'Translations.vue' });
    assert.equal(errors.length, 0, 'no SFC parse errors');

    const id = 'w0446';
    const script = compileScript(descriptor, { id });
    assert.ok(script.content.length > 0, 'script block compiles');

    const tmpl = compileTemplate({
        source: descriptor.template.content,
        filename: 'Translations.vue',
        id,
    });
    assert.equal(tmpl.errors.length, 0, 'template compiles with no errors');
});

// ---------------------------------------------------------------------------
// (b) every new card key resolves in the catalog
// ---------------------------------------------------------------------------
const KEYS = [
    'translations.pkg_title',
    'translations.pkg_eyebrow',
    'translations.pkg_intro',
    'translations.pkg_export_title',
    'translations.pkg_export_hint',
    'translations.pkg_export_select',
    'translations.pkg_export_placeholder',
    'translations.pkg_export_submit',
    'translations.pkg_import_title',
    'translations.pkg_import_hint',
    'translations.pkg_import_locale',
    'translations.pkg_import_file',
    'translations.pkg_import_submit',
    'translations.pkg_confirm',
    'translations.pkg_download',
    'translations.pkg_runs_title',
    'translations.pkg_col_kind',
    'translations.pkg_col_locale',
    'translations.pkg_col_status',
    'translations.pkg_col_action',
    'translations.pkg_dry_report',
    'translations.pkg_request_title',
    'translations.pkg_request_hint',
    'translations.pkg_request_locale',
    'translations.pkg_request_note',
    'translations.pkg_request_submit',
    'translations.pkg_no_runs',
    'translations.pkg_no_requests',
    'translations.pkg_busy',
    'translations.pkg_error',
];

test('every card key resolves in en/c_system.json', () => {
    for (const k of KEYS) {
        assert.ok(Object.prototype.hasOwnProperty.call(catalog, k), `catalog has ${k}`);
        assert.ok(String(catalog[k]).length > 0, `${k} is non-empty`);
    }
});

test('every card key the page uses is a declared key', () => {
    // Each t('c_system.translations.pkg_*', ...) in the page must be listed above.
    const used = new Set(
        [...src.matchAll(/c_system\.(translations\.pkg_[a-z_]+)/g)].map((m) => m[1]),
    );
    for (const k of used) {
        assert.ok(KEYS.includes(k), `${k} used in the page is pinned here`);
        assert.ok(k in catalog, `${k} used in the page exists in the catalog`);
    }
    assert.ok(used.size >= 25, 'the card wires a real set of keys');
});

// ---------------------------------------------------------------------------
// (c) the flows are present
// ---------------------------------------------------------------------------
test('the card polls the package endpoint every 2s', () => {
    assert.match(src, /setInterval\(pollPackages,\s*2000\)/);
    assert.match(src, /fetch\('\/system\/translations\/packages'/);
});

test('the export, import, confirm and request flows are wired', () => {
    assert.match(src, /\/system\/translations\/packages\/export/);
    assert.match(src, /\/system\/translations\/packages\/import/);
    assert.match(src, /\/confirm`/);
    assert.match(src, /\/system\/translations\/languages\/request/);
    assert.match(src, /function confirmImport\(/);
    assert.match(src, /function startExport\(/);
    assert.match(src, /function startImport\(/);
    assert.match(src, /function requestLanguageSubmit\(/);
});

test('the card is operator-gated', () => {
    // The card block opens with an operator gate and the poll refuses non-operators.
    assert.match(src, /<Card v-if="viewer\.isOperator"/);
    assert.match(src, /if \(!props\.viewer\?\.isOperator\) return;/);
});
