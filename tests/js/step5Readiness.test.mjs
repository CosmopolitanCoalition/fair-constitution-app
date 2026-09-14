import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';

// G1 — the Step 5 page surfaces the world-readiness rollup and gates the Lock
// control on the guard. This also serves as the SFC syntax gate (no Vite dev
// server for the worktree): the page must compile and render.

const base = new URL('../../resources/js/', import.meta.url);

async function render(progress) {
    const context = vm.createContext({ URLSearchParams, console, setInterval: () => 0, clearInterval: () => {}, Date });
    const stub = { setup: (props, { slots }) => () => Vue.h('div', slots.default?.()) };
    const source = await readFile(new URL('Pages/Setup/Step5_Simulate.vue', base), 'utf8');
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, { id: 'step5', inlineTemplate: true });
    const mod = new vm.SourceTextModule(compiled.content, { context });
    await mod.link(async name => {
        const syn = values => new vm.SyntheticModule(Object.keys(values), function () {
            for (const [k, v] of Object.entries(values)) this.setExport(k, v);
        }, { context });
        if (name === 'vue') return syn(Vue);
        if (name === '@inertiajs/vue3') return syn({ router: { visit() {} } });
        if (name.endsWith('/csrf')) return syn({ csrfFetch: async () => ({ ok: true, json: async () => ({}) }) });
        return syn({ default: stub });
    });
    await mod.evaluate();
    const props = {
        step: 5,
        settings: { setup_step_completed: 5, ladder: [] },
        progress,
        control_refusal: null,
    };
    return (await renderToString(Vue.createSSRApp(mod.namespace.default, props))).replace(/<!--[\s\S]*?-->/g, '');
}

const runningRun = { id: 'r', status: 'done', phase: 'done', phases: [] };

test('the readiness rollup renders verify counts and the unresolved gaps', async () => {
    const html = await render({
        run: runningRun,
        ledger: { total: 3, done: 2, running: 0, pending: 0, review: 1 },
        stages: [], phase_plan: { total: 0, phases: [] }, layers: [], lanes: [], review: [], timings: [], world: {},
        readiness: {
            run_done: true, verify_total: 3, verify_done: 2, verify_review: 1,
            pending: false, complete: false,
            unresolved: [{ jurisdiction_id: 'j', name: 'Testville', slug: 'testville', adm_level: 4, gaps: 'legislature has zero seats' }],
        },
    });
    assert.match(html, /World readiness/);
    assert.match(html, /Testville/);
    assert.match(html, /legislature has zero seats/);
    // review items > 0 and run done → the documented-exclusions control appears.
    assert.match(html, /Finish with documented exclusions/);
});

test('a complete readiness enables Lock and hides the exclusions control', async () => {
    const html = await render({
        run: runningRun,
        ledger: { total: 2, done: 2, running: 0, pending: 0, review: 0 },
        stages: [], phase_plan: { total: 0, phases: [] }, layers: [], lanes: [], review: [], timings: [], world: {},
        readiness: {
            run_done: true, verify_total: 2, verify_done: 2, verify_review: 0,
            pending: false, complete: true, unresolved: [],
        },
    });
    assert.match(html, /Lock and Continue/);
    assert.doesNotMatch(html, /Finish with documented exclusions/);
    // Enabled: the emerald button carries no standalone `disabled` attribute
    // (Vue omits it when false; the Tailwind `disabled:` class is not one).
    assert.doesNotMatch(html, /<button[^>]*\sdisabled(=|\s|>)[^>]*>Lock and Continue/);
});

test('verification pending shows the pending notice', async () => {
    const html = await render({
        run: runningRun,
        ledger: { total: 0, done: 0, running: 0, pending: 0, review: 0 },
        stages: [], phase_plan: { total: 0, phases: [] }, layers: [], lanes: [], review: [], timings: [], world: {},
        readiness: {
            run_done: true, verify_total: 0, verify_done: 0, verify_review: 0,
            pending: true, complete: false, unresolved: [],
        },
    });
    assert.match(html, /verification pending/i);
});
