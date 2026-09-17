// node --experimental-vm-modules --test tests/js/i18nLocaleBundles.test.mjs
//
// THE LOCALE BUNDLES (WoS beta, 2026-09-17). The catalogs are fetched, never
// bundled: scripts/i18n/bundle_locales.mjs writes public/i18n/<code>.json per
// locale and resources/js/i18n/index.js loads one on demand. This pin proves:
//   1. the bundler writes exactly one file per shipped locale into a temp dir,
//      each carrying the root chrome dict AND every namespace catalog;
//   2. a stale file for a retired locale is removed;
//   3. index.js carries no eager glob of the catalogs, exports the loader, and
//      app.js loads English plus the page locale before it mounts;
//   4. both shells switch through setLocale().
// DB-free; runs on the host.
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(path.join(root, rel), 'utf8');
const mod = await import(pathToFileURL(path.join(root, 'scripts/i18n/bundle_locales.mjs')).href);

test('the bundler writes one bundle per locale with chrome + every namespace, and prunes a retired one', () => {
    const out = mkdtempSync(path.join(tmpdir(), 'cga-i18n-bundles-'));
    try {
        writeFileSync(path.join(out, 'xx.json'), '{}');   // a retired locale's stale bundle
        const manifest = mod.bundleLocales({ outDir: out });
        const codes = mod.localeCodes();
        assert.ok(codes.includes('en') && codes.includes('ar'), 'en and ar are shipped locales');
        assert.deepEqual(Object.keys(manifest).sort(), codes, 'one manifest row per locale');
        const files = readdirSync(out).filter((f) => f.endsWith('.json') && f !== 'manifest.json').sort();
        assert.deepEqual(files, codes.map((c) => `${c}.json`), 'one file per locale, the stale one pruned');
        assert.ok(!existsSync(path.join(out, 'xx.json')));

        const ar = JSON.parse(readFileSync(path.join(out, 'ar.json'), 'utf8'));
        assert.ok(ar.app && ar.nav, 'the root chrome dict (app.*, nav.*) is in the bundle');
        assert.ok(ar.c_media && ar.c_setup, 'namespace catalogs ride under their namespace key');
        const nsCount = readdirSync(path.join(root, 'resources/js/i18n/locales/ar')).filter((f) => f.endsWith('.json')).length;
        assert.equal(manifest.ar.namespaces, nsCount, 'every ar namespace file is merged');
        assert.ok(manifest.ar.bytes > 100000, 'the bundle carries real catalog text');
    } finally {
        rmSync(out, { recursive: true, force: true });
    }
});

test('index.js has no eager glob and exports the loader; app.js preloads before mount', () => {
    const idx = read('resources/js/i18n/index.js');
    assert.doesNotMatch(idx, /import\.meta\.glob\(/, 'no import.meta.glob of the catalogs');
    assert.match(idx, /export async function loadLocale\(/);
    assert.match(idx, /export async function setLocale\(/);
    assert.match(idx, /fallbackLocale:\s*\{[^}]*default:\s*\[\s*'en'\s*\]/, 'English fallback kept');
    const app = read('resources/js/app.js');
    assert.match(app, /await Promise\.all\(\[loadLocale\('en'\)/, 'app.js awaits the English bundle before mounting');
});

test('both shells switch locales through setLocale()', () => {
    for (const rel of ['resources/js/Layouts/AppShellV2.vue', 'resources/js/Layouts/AppShell.vue']) {
        const src = read(rel);
        assert.match(src, /import \{[^}]*setLocale[^}]*\} from '@\/i18n\/index\.js'/, `${rel} imports setLocale`);
        assert.match(src, /setLocale\(code\)/, `${rel} calls setLocale on change`);
        assert.doesNotMatch(src, /^\s*locale\.value = code;/m, `${rel} no longer assigns the locale directly`);
    }
});
