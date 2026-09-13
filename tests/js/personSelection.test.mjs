// node --experimental-vm-modules --test tests/js/personSelection.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
const id = n => `50000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const person = n => ({ id: id(n), name: 'Same Name', type: 'users', public_handle: n === 1 ? '@first-person' : null, profile_href: '/people?who=' + id(n) });
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
const snapshot = value => JSON.parse(JSON.stringify(value));
// Vue's native v-model directives inspect focus during updates. This test has
// no focused input; other DOM behavior is supplied by the synthetic renderer.
globalThis.document = { activeElement: null };

async function fixture(t, kind) {
    const memory = new Map(), submissions = [];
    const names = kind === 'shares' ? 'shareForm, chooseRecipient, clearRecipient, chosenRecipient, issueShares' : 'draft, chooseParty, removeParty, selectedParties, submit';
    const file = kind === 'shares' ? 'Pages/Economy/OrgSettings.vue' : 'Pages/Economy/ResidentAgreements.vue';
    const { descriptor } = parse(read(file).replace('</script>', `defineExpose({ ${names} });\n</script>`));
    const content = compileScript(descriptor, { id: file, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content;
    const props = Vue.reactive(kind === 'shares' ? {
        surface: {}, org: { id: id(900), name: 'Test organization' }, dues: {}, shares: { holders: [], total: '0' },
        compose: true, can_issue_shares: true, recipient_directory: { query: 'Same', type: 'users', searched: true, candidates: [person(1)] },
    } : { surface: {}, my_id: id(999), compose: true, candidates: [person(1)], party_directory: { query: 'Same', searched: true } });
    const remember = (state, key) => {
        if (memory.has(key)) {
            if (Vue.isRef(state)) state.value = snapshot(memory.get(key));
            else Object.assign(state, snapshot(memory.get(key)));
        }
        Vue.watch(state, () => memory.set(key, snapshot(Vue.unref(state))), { deep: true, immediate: true });
        return state;
    };
    const useForm = (key, initial) => {
        if (typeof key !== 'string') { initial = key; key = null; }
        const fields = Object.keys(initial);
        const state = Vue.reactive({ ...snapshot(initial), errors: {}, processing: false,
            clearErrors() { this.errors = {}; }, reset() { Object.assign(this, snapshot(initial)); },
            post(url, options) { submissions.push({ url, data: Object.fromEntries(fields.map(field => [field, snapshot(this[field])])), options }); },
        });
        if (key) {
            if (memory.has(key)) Object.assign(state, snapshot(memory.get(key)));
            Vue.watch(state, () => memory.set(key, Object.fromEntries(fields.map(field => [field, snapshot(state[field])]))), { deep: true, immediate: true });
        }
        return state;
    };
    const slots = { setup: (_props, { slots }) => () => Vue.h('section', slots.default?.()) };
    const Link = { props: ['href'], setup: (p, { slots }) => () => Vue.h('a', { href: p.href }, slots.default?.()) };
    const context = vm.createContext({ console });
    const cache = new Map();
    function dependency(name) {
        if (cache.has(name)) return cache.get(name);
        let module;
        if (name.endsWith('/SelectionIdentity.vue')) {
            const { descriptor } = parse(read('Components/Ui/SelectionIdentity.vue'));
            module = new vm.SourceTextModule(compileScript(descriptor, { id: name, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content, { context });
        } else {
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? {
                Link, useForm, useRemember: remember, usePage: () => ({ props: { auth: { user: { id: id(999) } } } }),
                router: { get() {}, post() {}, visit() {} },
            } : name.endsWith('/money.js') ? Object.fromEntries(['formatMoney', 'formatQuantity', 'formatCount', 'formatWhen', 'shortId'].map(name => [name, value => String(value ?? '')]))
                : { default: slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(name, module);
        return module;
    }
    const module = new vm.SourceTextModule(content, { context });
    await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {},
        get options() { return this.children.filter(child => child.tag === 'option'); },
    });
    const renderer = Vue.createRenderer({
        createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) {
            if (el.parent) { const at = el.parent.children.indexOf(el); if (at >= 0) el.parent.children.splice(at, 1); }
            el.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1;
            at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el);
        },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (key === 'value' || key === 'type' || key === 'checked') el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root');
    let app, state;
    function mount() {
        app = renderer.createApp({ setup() { return () => Vue.h(module.namespace.default, { ...props, ref: value => { if (value) state = value; } }); } });
        app.mount(root);
    }
    mount(); await flush(); t.after(() => app.unmount());
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    return { props, submissions, get state() { return state; },
        nodes: () => nodes(), text: () => text(root),
        selectedText: () => nodes().filter(el => ['selected-recipient', 'ra-selected'].includes(el.props.class)).map(text).join(' '),
        links: () => nodes().filter(el => el.tag === 'a').map(el => el.props.href),
        async remount() { app.unmount(); mount(); await flush(); },
    };
}

test('ownership recipient reference stays attached to the selected person across search pages and remount', async t => {
    const f = await fixture(t, 'shares');
    f.state.chooseRecipient(person(1)); f.state.shareForm.units = '12.500000'; await flush();
    f.props.recipient_directory = { query: 'Same', type: 'users', searched: true, candidates: [person(2)], previous: '/previous' }; await flush();
    assert.match(f.selectedText(), new RegExp(id(1)));
    assert.doesNotMatch(f.selectedText(), new RegExp(id(2)));
    assert.match(f.selectedText(), /@first-person/);
    assert.ok(f.links().includes('/people?who=' + id(1)));
    assert.ok(f.links().includes('/people?who=' + id(2)));
    await f.remount();
    assert.equal(f.state.shareForm.holder_id, id(1));
    assert.equal(f.state.shareForm.units, '12.500000');
    assert.match(f.selectedText(), new RegExp(id(1)));
    f.state.issueShares();
    assert.deepEqual(f.submissions[0].data, { holder_type: 'users', holder_id: id(1), units: '12.500000' });
    f.submissions[0].options.onSuccess(); await flush();
    assert.equal(f.state.shareForm.holder_id, '');
    assert.equal(f.selectedText(), '');
});

test('explicitly selecting another repeated name changes the exact identity and clears old context', async t => {
    const f = await fixture(t, 'shares');
    f.state.chooseRecipient(person(1)); await flush();
    f.state.chooseRecipient(person(2)); await flush();
    assert.equal(f.state.shareForm.holder_id, id(2));
    assert.match(f.selectedText(), new RegExp(id(2)));
    assert.doesNotMatch(f.selectedText(), /@first-person/);
    f.state.chooseRecipient({ ...person(2), type: 'organizations' }); await flush();
    assert.ok(f.links().includes('/organizations/' + id(2)));
    assert.match(f.selectedText(), /Organization reference/);
    f.state.clearRecipient(); await flush();
    assert.equal(f.selectedText(), '');
});

test('multiple agreement parties with the same name retain separate profile context across pages and remount', async t => {
    const f = await fixture(t, 'agreement');
    f.state.chooseParty(person(1), true); f.state.draft.title = 'Garden plan'; f.state.draft.terms = 'Shared work'; await flush();
    f.props.candidates = [person(2)]; await flush();
    f.state.chooseParty(person(2), true); await flush();
    assert.match(f.selectedText(), new RegExp(id(1)));
    assert.match(f.selectedText(), new RegExp(id(2)));
    assert.match(f.selectedText(), /@first-person/);
    assert.ok(f.links().includes('/people?who=' + id(1)));
    await f.remount();
    assert.deepEqual([...f.state.draft.signers], [id(1), id(2)]);
    assert.equal(f.state.draft.title, 'Garden plan');
    assert.match(f.selectedText(), /@first-person/);
    f.state.removeParty(id(1)); await flush();
    assert.doesNotMatch(f.selectedText(), new RegExp(id(1)));
    assert.match(f.selectedText(), new RegExp(id(2)));
    f.state.submit();
    assert.deepEqual(f.submissions[0].data, { title: 'Garden plan', terms: 'Shared work', signers: [id(2)] });
});

test('checkbox and remove labels distinguish duplicate names without wrapping profile links in checkbox labels', async t => {
    const f = await fixture(t, 'agreement');
    f.props.candidates = [person(1), person(2)]; await flush();
    const checks = f.nodes().filter(el => el.tag === 'input' && el.props.type === 'checkbox');
    assert.equal(checks.length, 2);
    assert.notEqual(checks[0].props['aria-label'], checks[1].props['aria-label']);
    checks[0].props.onChange({ target: { checked: true } });
    checks[1].props.onChange({ target: { checked: true } }); await flush();
    const remove = f.nodes().filter(el => el.tag === 'button' && el.props['aria-label']?.startsWith('Remove'));
    assert.equal(remove.length, 2);
    assert.notEqual(remove[0].props['aria-label'], remove[1].props['aria-label']);
    assert.ok(checks.every(el => el.parent.tag !== 'label'));
    remove[0].props.onClick(); await flush();
    assert.deepEqual([...f.state.draft.signers], [id(2)]);
});

test('fresh result metadata removes an old public handle from an already-selected person', async t => {
    for (const kind of ['shares', 'agreement']) {
        const f = await fixture(t, kind);
        if (kind === 'shares') f.state.chooseRecipient(person(1)); else f.state.chooseParty(person(1), true);
        await flush(); assert.match(f.selectedText(), /@first-person/);
        const refreshed = { ...person(1), public_handle: null };
        if (kind === 'shares') f.props.recipient_directory.candidates = [refreshed]; else f.props.candidates = [refreshed];
        await flush();
        assert.doesNotMatch(f.selectedText(), /@first-person/);
        assert.match(f.selectedText(), new RegExp(id(1)));
    }
});
