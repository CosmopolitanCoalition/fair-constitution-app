// Compiled CaseDetail lifecycle controls (IO-1). No server mutations.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const read = (file) => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const snapshot = (value) => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = { activeElement: null };

function makeForm(posts) {
    // Minimal Inertia useForm() stub: fields + errors/processing + post/reset/transform.
    return (initial) => {
        const form = Vue.reactive({
            ...initial,
            errors: {},
            processing: false,
            transform(fn) { this._transform = fn; return this; },
            reset(...keys) {
                const target = keys.length ? keys : Object.keys(initial);
                for (const k of target) this[k] = initial[k];
            },
            post(url, options = {}) {
                const data = {};
                for (const k of Object.keys(initial)) data[k] = this[k];
                posts.push({ url, data: snapshot(data), options });
            },
        });
        return form;
    };
}

async function fixture(t, overrides = {}) {
    const visits = [], posts = [];
    const page = Vue.reactive({ url: '/cases/case-1', props: { flash: {}, errors: {} } });
    const props = Vue.reactive({
        surface: { title: 'Case detail', forms: [
            { id: 'F-JDG-011', availableTo: ['R-19', 'R-20'], citation: 'Art. IV §4 — hearing order' },
            { id: 'F-JDG-012', availableTo: ['R-19', 'R-20'], citation: 'Art. IV §4 — deliberation order' },
            { id: 'F-JDG-013', availableTo: ['R-19', 'R-20'], citation: 'Art. IV §4 — dismissal order' },
            { id: 'F-JDG-014', availableTo: ['R-19', 'R-20'], citation: 'Art. IV §4 — motion / evidence ruling' },
        ] },
        case: {
            id: 'case-1', judiciary_id: 'court-1', docket_no: 'case-2026-001', title: 'State v. Fixture',
            kind: 'Criminal', kind_raw: 'criminal', severity: 'Serious', court: { name: 'Civic court' },
            double_jeopardy: true, jury_entitled: true, current_stage: 8, current_state: 'deliberation',
        },
        machine: [], stages: [], stageStateMap: [],
        panel: { seats: [], severity: 'serious', panelSize: 3, isFullCourt: false, rule: 'x' },
        motions: [], evidence: [], jury: null,
        can: { orderCourt: true },
        ...overrides,
    });

    const context = vm.createContext({ URL, console, document: globalThis.document });
    const cache = new Map();
    const actual = new Set(['Pages/Judiciary/CaseDetail.vue']);
    const slots = { setup: (props, { slots }) => () => Vue.h('section', {}, [slots.default?.()]) };
    const Link = { props: ['href'], setup: (props, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: props.href }, slots.default?.()) };
    const useForm = makeForm(posts);

    function compiled(file) {
        const { descriptor } = parse(read(file));
        return new vm.SourceTextModule(compileScript(descriptor, { id: file, inlineTemplate: true }).content, { context, identifier: file });
    }
    function dependency(name, parent) {
        const file = name.startsWith('@/') ? name.slice(2) : name.startsWith('.') ? new URL(name, 'file:///' + parent.identifier).pathname.slice(1) : name;
        if (cache.has(file)) return cache.get(file);
        let module;
        if (actual.has(file)) module = compiled(file);
        else {
            const values = name === 'vue' ? Vue
                : name === '@inertiajs/vue3' ? { Link, useForm, usePage: () => page,
                    router: { get: (...args) => visits.push(args), post: (url, data, options) => posts.push({ url, data: snapshot(data), options }) } }
                : name === 'vue-i18n' ? { useI18n: () => ({ t: (key, arg, fallback) => (typeof arg === 'string' ? arg : fallback ?? key) }) }
                : file === 'composables/useDemoMode' ? { useDemoMode: () => ({ isDemoMode: false }) }
                : { default: slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); }, { context });
        }
        cache.set(file, module); return module;
    }

    const file = 'Pages/Judiciary/CaseDetail.vue', module = compiled(file); cache.set(file, module);
    await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, get options() { return this.children; }, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {} });
    const renderer = Vue.createRenderer({
        createElement: element, createText: (text) => element('#text', text), createComment: (text) => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: (el) => el.parent, nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'), app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, props) });
    app.mount(root); t.after(() => app.unmount()); await flush();
    const nodes = (el = root) => [el, ...el.children.flatMap((child) => nodes(child))];
    const text = (el) => (el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' '));
    return {
        props, visits, posts, page, nodes, text: () => text(root),
        button: (label) => nodes().find((el) => el.tag === 'button' && text(el).trim() === label),
    };
}

const submit = (form) => form.props.onSubmit({ preventDefault() {} });
const elText = (f, el) => f.nodes(el).map((n) => (n.tag === '#comment' ? '' : n.text)).join(' ');
const formWithButton = (f, label) => f.nodes().find((el) => el.tag === 'form'
    && f.nodes(el).some((c) => c.tag === 'button' && elText(f, c).trim() === label));

test('a court viewer in deliberation can record the verdict; it posts decided_by, outcome and counts', async (t) => {
    const f = await fixture(t);
    const button = f.button('Record verdict');
    assert.ok(button, 'the verdict control renders for the court at deliberation');
    assert.notEqual(button.props.disabled, true, 'the verdict is enabled at deliberation');
    // The "wrong state" status line is absent when the verdict is legal.
    assert.doesNotMatch(f.text(), /Available once the case is in deliberation/);

    submit(formWithButton(f, 'Record verdict'));
    const post = f.posts.find((p) => p.url === '/cases/case-1/verdict');
    assert.ok(post, 'the verdict posts its own route');
    assert.equal(post.data.decided_by, 'panel');
    assert.equal(post.data.outcome, 'guilty');
    assert.equal(post.data.panel_vote_for, 3);
    assert.equal(post.data.panel_vote_against, 0);
});

test('the controls render at the wrong state but disabled (never for a non-court viewer)', async (t) => {
    const paneled = await fixture(t, { case: { id: 'case-1', judiciary_id: 'court-1', docket_no: 'd', title: 'T',
        kind: 'Criminal', kind_raw: 'criminal', severity: 'Serious', court: { name: 'Civic court' },
        double_jeopardy: true, jury_entitled: true, current_stage: 3, current_state: 'paneled' } });
    // The verdict control still renders for the court, but is disabled and explains why.
    assert.equal(paneled.button('Record verdict').props.disabled, true);
    assert.match(paneled.text(), /Available once the case is in deliberation/);
    // The hearing control is legal at paneled, so its "wrong state" note is absent.
    assert.match(paneled.text(), /Opens arguments once the panel/);
    assert.doesNotMatch(paneled.text(), /Available once the case is paneled/);

    const guest = await fixture(t, { can: { orderCourt: false } });
    assert.equal(guest.button('Record verdict'), undefined, 'a non-court viewer sees no verdict control');
    assert.doesNotMatch(guest.text(), /Opens arguments once the panel/);
    assert.doesNotMatch(guest.text(), /Case proceedings|Record the verdict/);
});
