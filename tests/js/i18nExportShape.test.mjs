// node --experimental-vm-modules --test tests/js/i18nExportShape.test.mjs
//
// LG-0 - the language package shape (operator order 2026-09-15: one layout for
// every language, English included).
//
// DB-free. Builds a tiny i18n fixture in a temp directory, runs the real
// exporter (scripts/i18n/export_master.py) against it with --i18n-dir,
// --lang-dir and --out, and pins the tree it writes:
//   <code>/README.txt, <code>/ui/<namespace>.json, <code>/php/<code>.json
// every file COMPLETE (every English key; the held translation or the English).
//
// The exporter is a Python script. This test spawns python3. If python3 is
// absent the test fails loudly rather than passing vacuously.
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, readdirSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const exporter = path.join(root, 'scripts/i18n/export_master.py');

const EN = {
    'auth_login.log_in': 'Log in',
    'auth_login.welcome': 'Welcome back, {name}.',
    'auth_login.done': 'Done',
    'auth_login.locked_term': 'Residency',
    'auth_login.cite_only': 'Art. II §2',
};
const LANG_EN = { 'Log in': 'Log in', 'Art. II §2': 'Art. II §2' };

function buildFixture() {
    const tmp = mkdtempSync(path.join(os.tmpdir(), 'i18n-export-shape-'));
    const i18n = path.join(tmp, 'i18n');
    const lang = path.join(tmp, 'lang');
    const mk = (p) => mkdirSync(path.join(i18n, p), { recursive: true });
    ['locales/en', 'locales/es', 'meta/en', 'meta/es', 'glossary'].forEach(mk);
    mkdirSync(lang, { recursive: true });
    const w = (p, o) => writeFileSync(path.join(i18n, p), JSON.stringify(o), 'utf8');

    w('locales/en/auth.json', EN);
    w('locales/es/auth.json', {
        'auth_login.welcome': 'Welcome back, {name}.', // still English
        'auth_login.done': 'Hecho',                    // translated
        'auth_login.locked_term': 'Residency',
        'auth_login.cite_only': 'Art. II §2',
    });
    w('meta/es/auth.json', { 'auth_login.locked_term': { status: 'locked' } });
    w('meta/en/auth.json', {
        'auth_login.log_in': { file: 'Pages/Auth/Login.vue', line: 1, status: 'source' },
    });
    w('glossary/term-base.json', {
        _schema: { note: 'x' },
        Residency: { translations: { es: 'Residencia' } },
    });
    writeFileSync(path.join(i18n, 'locales.generated.js'),
        'export const LOCALES = [\n' +
        '    { code: "en", name: "English", endonym: "English", dir: "ltr", script: "Latn", enabled: true },\n' +
        '    { code: "es", name: "Spanish", endonym: "Espa\\u00f1ol", dir: "ltr", script: "Latn", enabled: true },\n' +
        '];\n', 'utf8');
    writeFileSync(path.join(lang, 'en.json'), JSON.stringify(LANG_EN), 'utf8');
    writeFileSync(path.join(lang, 'es.json'), JSON.stringify({ 'Log in': 'Iniciar sesión' }), 'utf8');
    return { tmp, i18n, lang };
}

function run(i18n, lang, locale, out) {
    const res = spawnSync('python3',
        [exporter, '--i18n-dir', i18n, '--lang-dir', lang, '--locale', locale, '--out', out],
        { encoding: 'utf8', env: { ...process.env, PYTHONIOENCODING: 'utf-8' } });
    assert.equal(res.status, 0, `exporter exited non-zero\nstdout:\n${res.stdout}\nstderr:\n${res.stderr}`);
}

test('a target package is the complete tree: README, ui/, php/', () => {
    assert.ok(existsSync(exporter), `exporter present at ${exporter}`);
    const { tmp, i18n, lang } = buildFixture();
    const out = path.join(tmp, 'out');
    try {
        run(i18n, lang, 'es', out);
        const dir = path.join(out, 'es');
        assert.deepEqual(readdirSync(dir).sort(), ['README.txt', 'php', 'ui'], 'exactly the three entries');

        const ui = JSON.parse(readFileSync(path.join(dir, 'ui', 'auth.json'), 'utf8'));
        assert.deepEqual(Object.keys(ui), Object.keys(EN), 'every English key, English order');
        assert.equal(ui['auth_login.log_in'], 'Log in', 'absent key carries the English');
        assert.equal(ui['auth_login.welcome'], 'Welcome back, {name}.', 'still-English key carries the English');
        assert.equal(ui['auth_login.done'], 'Hecho', 'translated key carries the translation');
        assert.equal(ui['auth_login.cite_only'], 'Art. II §2', 'citation carried verbatim');

        const php = JSON.parse(readFileSync(path.join(dir, 'php', 'es.json'), 'utf8'));
        assert.deepEqual(Object.keys(php), Object.keys(LANG_EN), 'php file is the complete lang catalogue');
        assert.equal(php['Log in'], 'Iniciar sesión', 'php file carries the held translation');

        const readme = readFileSync(path.join(dir, 'README.txt'), 'utf8');
        assert.match(readme, /Spanish \(es\)/);
        assert.match(readme, /ui\/<namespace>\.json/);
        assert.match(readme, /php\/es\.json/);
        assert.match(readme, /translated 2/);
        assert.match(readme, /to translate 3/);
        assert.match(readme, /copy verbatim 2/);
        assert.match(readme, /placeholder/i, 'the rules are in the README');
        assert.match(readme, /Residency -> Residencia/, 'the glossary is in the README');
        assert.match(readme, /used by: Pages\/Auth\/Login\.vue/, 'the surface context is in the README');
    } finally {
        rmSync(tmp, { recursive: true, force: true });
    }
});

test('the English master is the same tree and reconstitutes the catalogues line for line', () => {
    const { tmp, i18n, lang } = buildFixture();
    const out = path.join(tmp, 'out');
    try {
        run(i18n, lang, 'en', out);
        const dir = path.join(out, 'en');
        assert.deepEqual(readdirSync(dir).sort(), ['README.txt', 'php', 'ui']);
        assert.deepEqual(JSON.parse(readFileSync(path.join(dir, 'ui', 'auth.json'), 'utf8')), EN, 'ui/auth.json IS locales/en/auth.json');
        assert.deepEqual(JSON.parse(readFileSync(path.join(dir, 'php', 'en.json'), 'utf8')), LANG_EN, 'php/en.json IS lang/en.json');
        assert.match(readFileSync(path.join(dir, 'README.txt'), 'utf8'), /THE ENGLISH SOURCE MASTER/);
    } finally {
        rmSync(tmp, { recursive: true, force: true });
    }
});
