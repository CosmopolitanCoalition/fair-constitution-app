// Compiled CaseDetail appeal controls (IO-2). No server mutations.
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
    const baseCase = {
        id: 'case-1', judiciary_id: 'court-1', docket_no: 'case-2026-005', title: 'State v. Fixture',
        kind: 'Civil', kind_raw: 'civil', severity: 'Serious', court: { name: 'Civic court' },
        double_jeopardy: false, jury_entitled: false, current_stage: 9, current_state: 'decided',
        is_appeal: false, en_banc: false, appeal_of: null, appeals: [], appeal_outcomes: [],
    };
    const props = Vue.reactive({
        surface: { title: 'Case detail', forms: [
            { id: 'F-JDG-003', availableTo: ['R-19', 'R-20'], citation: 'Art. IV §4–§5 — opinion' },
            { id: 'F-IND-027', availableTo: ['R-03', 'R-21'], citation: 'Art. II §8 — appeal filing' },
        ] },
        case: { ...baseCase, ...(overrides.case ?? {}) },
        machine: [], stages: [], stageStateMap: [],
        panel: { seats: [], severity: 'serious', panelSize: 3, isFullCourt: false, rule: 'x' },
        motions: [], evidence: [], jury: null,
        can: overrides.can ?? { orderCourt: false, appeal: true, record_appeal_outcome: false },
    });

    const context = vm.createContext({ URL, console, document: globalThis.document });
    const cache = new Map();
    const actual = new Set(['Pages/Judiciary/CaseDetail.vue']);
    // Stub components render BOTH their default slot and any #control slot, so
    // Field-wrapped inputs (selects, textareas) appear in the tree.
    const slots = { setup: (props, { slots }) => () => Vue.h('section', {}, [slots.default?.(), slots.control?.({ id: 'x', describedBy: 'y' })]) };
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
        optionTexts: () => nodes().filter((el) => el.tag === 'option').map((el) => text(el).trim()),
        links: () => nodes().filter((el) => el.tag === 'a').map((el) => el.props.href),
    };
}

const submit = (form) => form.props.onSubmit({ preventDefault() {} });
const elText = (f, el) => f.nodes(el).map((n) => (n.tag === '#comment' ? '' : n.text)).join(' ');
const formWithButton = (f, label) => f.nodes().find((el) => el.tag === 'form'
    && f.nodes(el).some((c) => c.tag === 'button' && elText(f, c).trim() === label));

test('a party can appeal a decided judgement; it posts /cases/{id}/appeals with grounds', async (t) => {
    const f = await fixture(t, { can: { orderCourt: false, appeal: true, record_appeal_outcome: false } });
    const button = f.button('Appeal this judgement');
    assert.ok(button, 'the appeal control renders on a decided original');
    // A party (can.appeal) does not see the "only a party" status reason.
    assert.doesNotMatch(f.text(), /Only a party to this case may appeal/);

    submit(formWithButton(f, 'Appeal this judgement'));
    const post = f.posts.find((p) => p.url === '/cases/case-1/appeals');
    assert.ok(post, 'the appeal posts its own route');
    assert.ok('grounds' in post.data, 'the appeal payload carries grounds');
    assert.ok('statement' in post.data, 'the appeal payload carries the optional statement');
});

test('a non-party sees the appeal control disabled with a status reason', async (t) => {
    const f = await fixture(t, { can: { orderCourt: false, appeal: false, record_appeal_outcome: false } });
    const button = f.button('Appeal this judgement');
    assert.ok(button, 'the control still renders (disabled, not hidden)');
    assert.equal(button.props.disabled, true, 'a non-party cannot file the appeal');
    assert.match(f.text(), /Only a party to this case may appeal/);
});

test('an appeal case links back to the original with the en-banc note', async (t) => {
    const f = await fixture(t, {
        case: { is_appeal: true, en_banc: true, current_state: 'filed', current_stage: 1,
            appeal_of: { id: 'orig', docket_number: 'case-2026-001', href: '/cases/orig' } },
        can: { orderCourt: false, appeal: false, record_appeal_outcome: false },
    });
    assert.match(f.text(), /This case is an appeal of/);
    assert.match(f.text(), /case-2026-001/);
    assert.match(f.text(), /en banc/);
    assert.ok(f.links().includes('/cases/orig'), 'a link back to the original renders');
    // An appeal case does not show the filing control.
    assert.equal(f.button('Appeal this judgement'), undefined);
});

test('an appealed original lists its appeal case with the recorded outcome', async (t) => {
    const f = await fixture(t, {
        case: { current_state: 'appealed', current_stage: 10, is_appeal: false,
            appeals: [{ id: 'ap', docket_number: 'case-2026-009', status: 'closed', outcome: 'reverse', href: '/cases/ap' }] },
        can: { orderCourt: false, appeal: false, record_appeal_outcome: false },
    });
    assert.match(f.text(), /This judgement has been appealed/);
    assert.match(f.text(), /case-2026-009/);
    assert.match(f.text(), /Reversed/, 'the recorded appellate outcome renders');
    assert.ok(f.links().includes('/cases/ap'));
    // An appealed original past decided/sentenced no longer offers the filing control.
    assert.equal(f.button('Appeal this judgement'), undefined);
});

test('the appellate outcome select is limited by the case kind (criminal: affirm/vacate only)', async (t) => {
    const f = await fixture(t, {
        case: { is_appeal: true, kind: 'Criminal', kind_raw: 'criminal', current_state: 'decided', current_stage: 9,
            appeal_of: { id: 'orig', docket_number: 'case-2026-001', href: '/cases/orig' },
            appeal_outcomes: ['affirm', 'vacate'] },
        can: { orderCourt: true, appeal: false, record_appeal_outcome: true },
    });
    const options = f.optionTexts();
    assert.ok(options.includes('Affirm the judgement'), 'affirm is offered');
    assert.ok(options.includes('Vacate the conviction (the accused is acquitted)'), 'vacate is offered');
    assert.ok(!options.includes('Remand for further proceedings'), 'a criminal appeal never offers remand');
    assert.ok(!options.includes('Reverse the judgement'), 'a criminal appeal never offers reverse');
});
