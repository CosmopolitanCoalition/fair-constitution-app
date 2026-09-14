// node --experimental-vm-modules --test tests/js/i18nExportShape.test.mjs
//
// LG-0 - the master export file shape.
//
// DB-free. Builds a tiny i18n fixture in a temp directory, runs the real
// exporter (scripts/i18n/export_master.py) against it with --i18n-dir and
// --out, and pins the shape of the JSON it writes: the _meta context header
// and the strings map. The fixture is deterministic, so the test does not
// depend on the live catalog state.
//
// The exporter is a Python script. This test spawns python3 (present in the
// fc_vite container this suite runs in). If python3 is absent the test fails
// loudly rather than passing vacuously.
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const exporter = path.join(root, 'scripts/i18n/export_master.py');

function buildFixture() {
    const tmp = mkdtempSync(path.join(os.tmpdir(), 'i18n-export-shape-'));
    const i18n = path.join(tmp, 'i18n');
    const mk = (p) => mkdirSync(path.join(i18n, p), { recursive: true });
    ['locales/en', 'locales/es', 'meta/en', 'meta/es', 'glossary'].forEach(mk);
    const w = (p, o) => writeFileSync(path.join(i18n, p), JSON.stringify(o), 'utf8');

    // English source: an absent key, a still-English key, a translated key, a
    // locked key, and a pure-citation (non-translatable) key.
    w('locales/en/auth.json', {
        'auth_login.log_in': 'Log in',
        'auth_login.welcome': 'Welcome back, {name}.',
        'auth_login.done': 'Done',
        'auth_login.locked_term': 'Residency',
        'auth_login.cite_only': 'Art. II §2',
    });
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
    return { tmp, i18n };
}

test('exporter writes the pinned master-export file shape', () => {
    assert.ok(existsSync(exporter), `exporter present at ${exporter}`);
    const { tmp, i18n } = buildFixture();
    const out = path.join(tmp, 'out');
    try {
        const res = spawnSync('python3',
            [exporter, '--i18n-dir', i18n, '--locale', 'es', '--out', out, '--chunk', '250'],
            { encoding: 'utf8', env: { ...process.env, PYTHONIOENCODING: 'utf-8' } });
        assert.equal(res.status, 0, `exporter exited non-zero\nstdout:\n${res.stdout}\nstderr:\n${res.stderr}`);

        const file = path.join(out, 'es', 'auth.json');
        assert.ok(existsSync(file), `a chunk file was written at ${file}`);
        const obj = JSON.parse(readFileSync(file, 'utf8'));

        // _meta context header.
        const m = obj._meta;
        assert.ok(m, '_meta block present');
        assert.equal(m.locale, 'es', '_meta.locale');
        assert.equal(m.language, 'Spanish', '_meta.language (English name)');
        assert.equal(m.native_name, 'Español', '_meta.native_name (endonym, decoded)');
        assert.equal(m.direction, 'ltr', '_meta.direction');
        assert.equal(m.namespace, 'auth', '_meta.namespace');
        assert.equal(typeof m.surface, 'string', '_meta.surface is a one-line description');
        assert.ok(m.surface.length > 0, '_meta.surface non-empty');
        assert.ok(Array.isArray(m.used_by), '_meta.used_by is an array');
        assert.ok(m.used_by.includes('Pages/Auth/Login.vue'), '_meta.used_by carries the manifest file');
        assert.equal(typeof m.glossary, 'object', '_meta.glossary is an object');
        assert.equal(m.glossary.Residency, 'Residencia', '_meta.glossary carries the locale rendering');
        assert.equal(typeof m.instructions, 'string', '_meta.instructions is a string');
        assert.ok(/placeholder/i.test(m.instructions), '_meta.instructions names the placeholder rule');
        assert.ok(/JSON/i.test(m.instructions), '_meta.instructions asks for JSON');
        assert.equal(typeof m.string_count, 'number', '_meta.string_count is a number');

        // strings map.
        const s = obj.strings;
        assert.equal(typeof s, 'object', 'strings is an object');
        assert.equal(m.string_count, Object.keys(s).length, '_meta.string_count matches the strings count');
        assert.ok('auth_login.log_in' in s, 'absent key exported');
        assert.ok('auth_login.welcome' in s, 'still-English key exported');
        assert.ok(!('auth_login.done' in s), 'translated key not exported');
        assert.ok(!('auth_login.locked_term' in s), 'locked key not exported');
        assert.ok(!('auth_login.cite_only' in s), 'pure-citation key not exported');
        assert.equal(s['auth_login.log_in'], 'Log in', 'exported value is the English source text');

        console.log(`  export shape pinned: ${Object.keys(s).length} strings, ` +
            `used_by=${JSON.stringify(m.used_by)}, glossary terms=${Object.keys(m.glossary).length}`);
    } finally {
        rmSync(tmp, { recursive: true, force: true });
    }
});
