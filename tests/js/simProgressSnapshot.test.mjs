import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';

const base = new URL('../../resources/js/', import.meta.url);
const catalogue = JSON.parse(await readFile(new URL('i18n/locales/en/c_setup.json', base), 'utf8'));

async function renderStamp(snapshot) {
    const context = vm.createContext({ console });
    const source = await readFile(new URL('Components/Progress/SnapshotStamp.vue', base), 'utf8');
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, { id: 'snapshot', inlineTemplate: true });
    const mod = new vm.SourceTextModule(compiled.content, { context });
    await mod.link(async name => {
        const values = name === 'vue' ? Vue : name === 'vue-i18n'
            ? { useI18n: () => ({ t: (key, args = {}) => catalogue[key.replace(/^c_setup\./, '')].replace(/\{(\w+)\}/g, (_, k) => args[k]) }) }
            : { useLocaleFormat: () => ({ time: value => value }) };
        return new vm.SyntheticModule(Object.keys(values), function () {
            for (const [key, value] of Object.entries(values)) this.setExport(key, value);
        }, { context });
    });
    await mod.evaluate();
    return renderToString(Vue.createSSRApp(mod.namespace.default, { snapshot }));
}

test('progress displays the measurement timestamp rather than the last HTTP poll', async () => {
    const html = await renderStamp({ snapshot_at: '2026-09-19T19:00:00Z', snapshot_stale: false });
    assert.match(html, /Progress measured at 2026-09-19T19:00:00Z/);
    assert.doesNotMatch(html, /Refreshing counts/);
});

test('a stale sample stays dated and visibly indicates a refresh', async () => {
    const html = await renderStamp({ snapshot_at: '2026-09-19T19:00:00Z', snapshot_stale: true });
    assert.match(html, /2026-09-19T19:00:00Z/);
    assert.match(html, /Refreshing counts/);
});

test('a competing cold reader shows measuring instead of an invented measurement time', async () => {
    const html = await renderStamp({ snapshot_at: null, snapshot_state: 'computing' });
    assert.match(html, /Measuring progress/);
    assert.doesNotMatch(html, /Progress measured at/);
});

test('an older server without snapshot metadata does not break the component', async () => {
    assert.doesNotMatch(await renderStamp(null), /Progress measured|Measuring progress/);
});

for (const page of ['Pages/Setup/Step5_Simulate.vue', 'Pages/Demo/SimConsole.vue']) {
    test(`${page} compiles with the shared timestamp component`, async () => {
        const source = await readFile(new URL(page, base), 'utf8');
        const { descriptor, errors } = parse(source);
        assert.deepEqual(errors, []);
        assert.ok(compileScript(descriptor, { id: page, inlineTemplate: true }).content);
        assert.match(source, /<SnapshotStamp :snapshot="data\??\.progress_snapshot"/);
    });
}
