// node --experimental-vm-modules --test tests/js/demoSessionBanner.test.mjs
//
// PIN (W-0183) — the scale_demo session banner.
//
// On a scale_demo instance the shell shows a banner telling the visitor their
// changes are real for the session and are reversed at sign-out or expiry. A
// non-demo instance shows nothing, and a visitor can dismiss it.
//
// DB-free. Evaluates the component's <script setup> with stubbed vue / inertia
// / i18n (the worktree cannot reach the Vite syntax gate), then drives the
// exposed reactive state the template's v-if binds to.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { computed, reactive, ref } from 'vue';

async function mount(pageProps) {
    const file = await readFile(
        new URL('../../resources/js/Components/ShellV2/DemoSessionBanner.vue', import.meta.url),
        'utf8',
    );
    const source = file.match(/<script setup>([\s\S]*?)<\/script>/)[1];
    const context = vm.createContext({ defineExpose() {} });
    const mod = new vm.SourceTextModule(
        source + '\nexport { visible, isDemoWorld, dismiss };',
        { context },
    );
    await mod.link((name) => {
        let values = { default: {} };
        if (name === 'vue') values = { computed, ref };
        else if (name === '@inertiajs/vue3') values = { usePage: () => ({ props: pageProps }) };
        else if (name === 'vue-i18n') values = { useI18n: () => ({ t: (key, fallback) => fallback }) };
        return new vm.SyntheticModule(
            Object.keys(values),
            function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); },
            { context },
        );
    });
    await mod.evaluate();
    return mod.namespace;
}

test('the banner shows on a scale_demo world', async () => {
    const api = await mount(reactive({ shellInstance: { demo: true } }));
    assert.equal(api.isDemoWorld.value, true);
    assert.equal(api.visible.value, true);
});

test('dismissing hides the banner for this view', async () => {
    const api = await mount(reactive({ shellInstance: { demo: true } }));
    api.dismiss();
    assert.equal(api.visible.value, false);
});

test('a non-demo instance shows nothing', async () => {
    const api = await mount(reactive({ shellInstance: { demo: false } }));
    assert.equal(api.isDemoWorld.value, false);
    assert.equal(api.visible.value, false);
});

test('a missing demo flag shows nothing', async () => {
    const api = await mount(reactive({ shellInstance: {} }));
    assert.equal(api.visible.value, false);
});
