import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';

const component = (name) => ({
    props: ['disabled', 'href'],
    setup(props, { slots }) {
        return () => Vue.h(name === 'Btn' ? 'button' : name === 'RankList' ? 'ol' : 'section',
            name === 'RankList' ? { 'data-ranking-disabled': String(!!props.disabled) } : { disabled: props.disabled, href: props.href },
            [slots.default?.(), slots.control?.({ id: 'fixture', invalid: false, describedBy: undefined })]);
    },
});

async function render(can, processing = false) {
    const source = await readFile(new URL('../../resources/js/Pages/Legislature/Committees.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, { id: 'committee-fixture', inlineTemplate: true });
    const context = vm.createContext({});
    const mod = new vm.SourceTextModule(compiled.content, { context });
    await mod.link(name => {
        let values;
        if (name === 'vue') values = Vue;
        else if (name === '@inertiajs/vue3') values = {
            router: { post() {} }, usePage: () => ({ props: {} }),
            useForm: initial => Vue.reactive({ ...initial, processing, errors: {}, post() {}, reset() {} }),
        };
        else values = { default: component(name.split('/').at(-1).replace('.vue', '')) };
        return new vm.SyntheticModule(Object.keys(values), function () {
            for (const [key, value] of Object.entries(values)) this.setExport(key, value);
        }, { context });
    });
    await mod.evaluate();
    const props = {
        surface: { forms: [] }, legislature: {}, committees: [{ id: 'committee', name: 'Committee', status: 'created', seats: 1, members: [], chair_candidates: [] }],
        allocation: { committee_count: 1, total_seats: 1, total_reps: 3 },
        myPreferences: { submitted_at: '2026-09-13T00:00:00Z', rankings: ['committee'] },
        preferencesState: { submitted: 1, serving: 3, pending: ['Member two', 'Member three'] }, can,
        urls: { preferences: '/legislatures/fixture/committee-preferences', assign: '/legislatures/fixture/committees/assign' },
    };
    return (await renderToString(Vue.createSSRApp(mod.namespace.default, props))).replace(/<!--[\s\S]*?-->/g, '');
}

test('submitted preferences remain editable and incomplete submissions do not block a permitted assignment', async () => {
    const html = await render({ submitPreferences: true, runAssignment: true });
    assert.match(html, /data-ranking-disabled="false"/);
    assert.match(html, /<button(?![^>]*disabled)[^>]*>Update preferences<\/button>/);
    assert.match(html, /<button(?![^>]*disabled)[^>]*>Run assignment<\/button>/);
    assert.match(html, /Members who have not submitted preferences use committee creation order/);
});

test('processing disables both controls without claiming the saved preference is permanently locked', async () => {
    const html = await render({ submitPreferences: true, runAssignment: true }, true);
    assert.match(html, /data-ranking-disabled="true"/);
    assert.match(html, /<button[^>]*disabled[^>]*>Update preferences<\/button>/);
    assert.match(html, /<button[^>]*disabled[^>]*>Run assignment<\/button>/);
});

test('server authority gates still hide preference and assignment controls', async () => {
    const html = await render({ submitPreferences: false, runAssignment: false });
    assert.doesNotMatch(html, /<button[^>]*>Update preferences<\/button>/);
    assert.doesNotMatch(html, /<button[^>]*>Run assignment<\/button>/);
    assert.doesNotMatch(html, /data-ranking-disabled=/);
});
