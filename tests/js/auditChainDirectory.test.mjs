import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript, compileStyle } from '@vue/compiler-sfc';

async function fixture(t, overrides = {}) {
    const priorDocument = globalThis.document;
    globalThis.document = { activeElement: null };
    t.after(() => { globalThis.document = priorDocument; });
    const context = vm.createContext({}); const modules = new Map(); const requests = []; const posts = [];
    const synthetic = values => new vm.SyntheticModule(Object.keys(values), function () {
        for (const [key, value] of Object.entries(values)) this.setExport(key, value);
    }, { context });
    const plain = { props: ['title'], setup: (props, { slots }) => () => Vue.h('section', [props.title ? Vue.h('h2', props.title) : null, slots.default?.()]) };
    const page = { props: { flash: {}, errors: {} } };
    async function load(path) {
        if (modules.has(path)) return modules.get(path);
        const { descriptor } = parse(await readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8'));
        const mod = new vm.SourceTextModule(compileScript(descriptor, { id: path, inlineTemplate: true }).content, { context });
        modules.set(path, mod);
        await mod.link(name => {
            if (name === 'vue') return synthetic(Vue);
            if (name === '@inertiajs/vue3') return synthetic({
                Head: plain, usePage: () => page,
                router: { get(url, data, options) { requests.push({ url, data, options }); options.onStart(); } },
                useForm: initial => Vue.reactive({ ...initial, processing: false, post(url) { posts.push(url); }, reset() {} }),
            });
            if (name.endsWith('/PageScaffold.vue') || name.endsWith('/LogRow.vue')) return load(name.slice(2));
            if (name.endsWith('/LearnContent.vue')) return synthetic({ default: { setup: (_, { slots }) => () => Vue.h('aside', { 'data-learn': true }, slots.default?.()) } });
            if (name.endsWith('/Stat.vue')) return synthetic({ default: { props: ['label', 'value'], setup: props => () => Vue.h('div', `${props.label}: ${props.value}`) } });
            if (name.endsWith('/Btn.vue')) return synthetic({ default: { props: ['disabled'], setup: (props, { slots }) => () => Vue.h('button', { disabled: props.disabled }, slots.default?.()) } });
            return synthetic({ default: plain });
        });
        return mod;
    }
    const mod = await load('Pages/System/AuditChain.vue'); await mod.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, tagName: tag.toUpperCase(), text, children: [], parent: null, props: {}, listeners: {},
        addEventListener(name, callback) { (this.listeners[name] ??= []).push(callback); }, removeEventListener() {},
    });
    const renderer = Vue.createRenderer({
        createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor = null) { el.parent = parent; const index = parent.children.indexOf(anchor); if (index < 0) parent.children.push(el); else parent.children.splice(index, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const row = { seq: '126', occurred_at: '2026-09-13T12:00:00Z', module: 'legislature', event: 'committee.report_filed', ref: 'report', hash: 'a'.repeat(64), prev_hash: 'b'.repeat(64), rejected: true, blocked_reason: 'Synthetic refusal' };
    const entries = { data: [row], pages: { previous: '/system/audit-chain?entries_cursor=newer', next: '/system/audit-chain?entries_cursor=older' }, latest_url: '/system/audit-chain?jurisdiction=fixture-place', selection: { status: 'history', seq: null }, ...overrides.entries };
    const props = Vue.reactive({ surface: { title: 'Audit chain', module: 'System' }, chain: { head_seq: '128', genesis: '0'.repeat(64) }, canVerify: false, ...overrides, entries });
    const root = element('root'); const app = renderer.createApp({ setup: () => () => Vue.h(mod.namespace.default, props) }); app.mount(root);
    t.after(() => app.unmount()); await Vue.nextTick();
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const text = (el = root) => el.tag === '#comment' ? '' : el.text + el.children.map(child => text(child)).join(' ');
    const button = label => nodes().find(el => el.tag === 'button' && text(el).trim() === label);
    return { requests, posts, nodes, text, button, props,
        async click(label) { const el = button(label); assert.ok(el, `Button ${label} exists`); el.props.onClick?.({ preventDefault() {} }); await Vue.nextTick(); },
        async type(value) { const input = nodes().find(el => el.props.id === 'audit-sequence'); input.value = value; for (const listener of input.listeners.input ?? []) listener({ target: input }); await Vue.nextTick(); },
        async submit() { nodes().find(el => el.tag === 'form').props.onSubmit({ preventDefault() {} }); await Vue.nextTick(); },
        async finish(request, result = 'success') { if (result === 'success') request.options.onSuccess(); if (result === 'cancel') request.options.onCancel(); if (result === 'validation') request.options.onError({ fixture: 'failed' }); request.options.onFinish(); await Vue.nextTick(); },
    };
}

test('actual scaffold places teaching in Learn and keeps receipt links and metadata in the working surface', async t => {
    const f = await fixture(t);
    const learn = f.nodes().find(el => el.tag === 'aside'); assert.ok(learn);
    assert.match(f.text(learn), /How the chain works/); assert.match(f.text(learn), /Sequence numbers can have gaps/);
    const working = f.nodes().filter(el => el.tag === 'h2' && !f.text(learn).includes(f.text(el)));
    assert.ok(working.some(el => f.text(el) === 'Audit history'));
    assert.equal(f.nodes().filter(el => el.tag === 'h2' && f.text(el) === 'How the chain works').length, 1);
    assert.match(f.text(), /Latest sequence: #128/); assert.match(f.text(), /Synthetic refusal/);
    const receipt = f.nodes().find(el => el.tag === 'a');
    assert.equal(receipt.props.href, '/system/audit-chain?jurisdiction=fixture-place&seq=126');
    assert.doesNotMatch(f.text(), /Page \d|128 entries|Verify the full chain|Reconcile break/);
    assert.deepEqual(f.posts, []);
});

test('selected receipt retains full hashes and exact bigint identifier without history navigation', async t => {
    const seq = '9007199254740993';
    const f = await fixture(t, { entries: { selection: { status: 'found', seq }, pages: { previous: null, next: null }, data: [{ seq, hash: 'c'.repeat(64), prev_hash: 'd'.repeat(64), event: 'committee.report_filed' }] } });
    assert.match(f.text(), /Receipt #9007199254740993/); assert.match(f.text(), new RegExp('c'.repeat(64))); assert.match(f.text(), new RegExp('d'.repeat(64)));
    assert.equal(f.button('Older entries'), undefined); assert.equal(f.button('Newer entries'), undefined);
    await f.click('Browse latest history'); assert.equal(f.requests[0].url, '/system/audit-chain?jurisdiction=fixture-place');
    assert.deepEqual(Array.from(f.requests[0].options.only), ['entries', 'chain']); assert.deepEqual(f.posts, []);
});

test('missing, invalid, invalid-page and empty states are truthful and retain recovery', async t => {
    for (const [status, expected] of [['missing', /Entry #3 was not found on this instance/], ['invalid', /This entry number is invalid/], ['invalid_cursor', /This history page link is invalid/], ['history', /No entries on this page/]]) {
        const f = await fixture(t, { entries: { selection: { status, seq: status === 'missing' ? '3' : null }, pages: { previous: null, next: null }, data: [] } });
        assert.match(f.text(), expected); assert.ok(f.button('Browse latest history'));
        assert.doesNotMatch(f.text(), /undefined|NaN|Page 0 of|The chain is empty/);
    }
});

test('page navigation announces loading, refuses duplicate visits and recovers from a failed request', async t => {
    const f = await fixture(t); await f.click('Older entries');
    assert.equal(f.requests.length, 1); assert.match(f.text(), /Loading history…/);
    assert.equal(f.button('Older entries').props.disabled, true);
    await f.click('Newer entries'); assert.equal(f.requests.length, 1);
    await f.finish(f.requests[0], 'network');
    assert.match(f.text(), /history could not be loaded/); assert.equal(f.button('Older entries').props.disabled, false);
    await f.click('Try again'); assert.equal(f.requests[1].url, f.requests[0].url);
    await f.finish(f.requests[1]); assert.doesNotMatch(f.text(), /Loading history…|history could not be loaded/);
    await f.click('Browse latest history'); assert.equal(f.requests[2].url, '/system/audit-chain?jurisdiction=fixture-place');
    assert.deepEqual(f.posts, []);
});

test('actual receipt input preserves a bigint number through failed submission and retry', async t => {
    const f = await fixture(t); const input = f.nodes().find(el => el.props.id === 'audit-sequence');
    assert.equal(input.props.type, 'text'); assert.equal(input.props.inputmode, 'numeric');
    await f.type('9007199254740993'); await f.submit();
    assert.equal(f.requests[0].url, '/system/audit-chain?jurisdiction=fixture-place&seq=9007199254740993');
    await f.finish(f.requests[0], 'validation'); await f.click('Try again');
    assert.equal(f.requests[1].url, f.requests[0].url); assert.equal(input.value, '9007199254740993');
    assert.deepEqual(f.posts, []);
});

test('cancelled history visits clear loading without inventing a server error', async t => {
    const f = await fixture(t); await f.click('Older entries'); await f.finish(f.requests[0], 'cancel');
    assert.doesNotMatch(f.text(), /Loading history…|history could not be loaded/);
    assert.equal(f.button('Older entries').props.disabled, false);
});

test('successful receipt and latest-history visits update the lookup field with the current selection', async t => {
    const f = await fixture(t); const input = f.nodes().find(el => el.props.id === 'audit-sequence');
    f.props.entries.selection = { status: 'found', seq: '9007199254740993' }; await Vue.nextTick();
    assert.equal(input.value, '9007199254740993');
    f.props.entries.selection = { status: 'history', seq: null }; await Vue.nextTick(); assert.equal(input.value, '');
});

test('audit surface styles compile independently without a production build', async () => {
    const { descriptor, errors } = parse(await readFile(new URL('../../resources/js/Pages/System/AuditChain.vue', import.meta.url), 'utf8'));
    assert.deepEqual(errors, []);
    for (const style of descriptor.styles) assert.deepEqual(compileStyle({ source: style.content, filename: 'AuditChain.vue', id: 'audit-chain', scoped: style.scoped }).errors, []);
});
