import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

/* F-ORG-002 withdraw / re-endorse controls on the organization detail page
   (operator ruling 2026-09-13). Compiled-Vue test (no jsdom): the real
   OrgDetail.vue script runs, the template renders into a minimal custom
   renderer, and the Inertia router is stubbed to record post() calls so the
   exact routes are asserted. */

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };

async function fixture(t, { manage = true, granted = [] } = {}) {
    const posts = [], visits = [];
    const page = Vue.reactive({ url: '/organizations/org1', props: { flash: {}, errors: {} } });
    const props = Vue.reactive({
        surface: { forms: [{ id: 'F-ORG-001', name: 'Organization Profile Management', alias: null }, { id: 'F-ORG-002', name: 'Candidate Endorsement Grant', alias: null }] },
        organization: { id: 'org1', name: 'Guild', status: 'active', purpose: '' },
        machine: [],
        ownership: { structure: 'sole', isCgc: false, stakes: [], memberCounts: {}, structureHistory: [] },
        board: null,
        endorsements: { incoming: [], granted, total: granted.filter(g => g.active !== false).length },
        documents: [], contracts: [], myMembership: null, myWorker: null, jobs: [],
        can: { manage, join: false, registerWorker: false, cosign: false, steerEconomy: false },
    });
    const context = vm.createContext({ URL, console, Date, document: { activeElement: null } });
    const cache = new Map(), real = new Set(['Pages/Organizations/OrgDetail.vue']);
    const generic = { setup: (props, { slots, attrs }) => () => Vue.h('section', attrs, [slots.title?.(), slots.default?.()]) };
    const Link = { props: ['href'], setup: (props, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: props.href }, slots.default?.()) };
    const Btn = { props: ['disabled', 'pressed', 'variant', 'size'], setup: (props, { slots, attrs }) => () => Vue.h('button', { ...attrs, disabled: props.disabled }, slots.default?.()) };
    function compile(file) { const { descriptor } = parse(read(file)); return new vm.SourceTextModule(compileScript(descriptor, { id: file, inlineTemplate: true }).content, { context, identifier: file }); }
    function dependency(name, parent) {
        const file = name.startsWith('@/') ? name.slice(2) : name.startsWith('.') ? new URL(name, 'file:///' + parent.identifier).pathname.slice(1) : name;
        if (cache.has(file)) return cache.get(file);
        let module;
        if (real.has(file)) module = compile(file);
        else {
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? { Link, usePage: () => page,
                router: { get: (...args) => visits.push(args), post: (...args) => posts.push(args) },
                useForm: data => { let defaults = copy(data); const form = Vue.reactive({ ...data, processing: false, errors: {},
                    defaults(next) { defaults = copy(next); }, reset() { Object.assign(form, defaults); }, transform() { return form; }, post() {}, patch() {} }); return form; } }
                : { default: file.endsWith('/Btn.vue') ? Btn : generic };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(file, module); return module;
    }
    const file = 'Pages/Organizations/OrgDetail.vue', module = compile(file); cache.set(file, module); await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, style: {}, querySelector: () => null, addEventListener() {}, removeEventListener() {} });
    const renderer = Vue.createRenderer({ createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText(el, text) { el.text = text; }, setElementText(el, text) { el.text = text; el.children = []; }, parentNode: el => el.parent,
        nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null });
    const root = element('root'), app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, props) }); app.mount(root); t.after(() => app.unmount()); await flush();
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const textOf = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(textOf)].join(' ');
    return { props, page, posts, visits, nodes, text: () => textOf(root),
        button: label => nodes().find(el => el.tag === 'button' && textOf(el).trim() === label) };
}

const activeGrant = { id: 'req1', candidacy_id: 'c1', candidate: { name: 'River', href: '/candidacies/c1' }, granted_at: '2026-09-13T00:00:00Z', active: true };
const withdrawnGrant = { ...activeGrant, active: false };

test('the agent can withdraw an active granted endorsement via the exact route', async t => {
    const f = await fixture(t, { granted: [activeGrant] });
    const withdraw = f.button('Withdraw');
    assert.ok(withdraw, 'the withdraw control renders on an active granted row');
    withdraw.props.onClick();
    assert.equal(f.posts.length, 1);
    assert.equal(f.posts[0][0], '/organizations/org1/endorsements/req1/withdraw');
    assert.deepEqual(copy(f.posts[0][1]), {});
    // Loading, then success feedback off the router callbacks.
    const opts = f.posts[0][2];
    opts.onStart(); await flush();
    assert.ok(f.button('Withdrawing…'), 'the button shows a loading label while filing');
    opts.onSuccess(); opts.onFinish(); await flush();
    assert.match(f.text(), /Endorsement withdrawn/);
});

test('the agent can re-endorse a withdrawn endorsement via the exact route', async t => {
    const f = await fixture(t, { granted: [withdrawnGrant] });
    assert.match(f.text(), /withdrawn/);
    assert.equal(f.button('Withdraw'), undefined, 'no withdraw control on an already-withdrawn row');
    const reEndorse = f.button('Re-endorse');
    assert.ok(reEndorse);
    reEndorse.props.onClick();
    assert.equal(f.posts[0][0], '/organizations/org1/endorsements/req1/re-endorse');
    assert.deepEqual(copy(f.posts[0][1]), {});
    const opts = f.posts[0][2];
    opts.onError({ constitution: 'Candidacy is no longer standing.' }); opts.onFinish(); await flush();
    assert.match(f.text(), /no longer standing/);
});

test('a non-manager sees no withdraw or re-endorse control', async t => {
    const f = await fixture(t, { manage: false, granted: [activeGrant, withdrawnGrant] });
    assert.equal(f.button('Withdraw'), undefined);
    assert.equal(f.button('Re-endorse'), undefined);
    assert.equal(f.posts.length, 0);
});
