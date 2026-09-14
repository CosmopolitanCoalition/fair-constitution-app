import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

/* IO-4 — membership review + agent reassignment controls on the organization
   detail page (operator ruling 2026-09-13 · org-membership-agent-rules = A).
   Compiled-Vue test (no jsdom): the real OrgDetail.vue script runs, the
   template renders into a minimal renderer, and the Inertia router is stubbed
   to record post()/get() calls so the exact routes are asserted. */

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
// runtime-dom's v-model directive hooks read document; the Field-wrapped
// search inputs are not rendered by the stub, but keep this as a guard.
globalThis.document = globalThis.document || { activeElement: null };

async function fixture(t, { manage = true, pendingMembers = null, agentSearch = null } = {}) {
    const posts = [], visits = [];
    const page = Vue.reactive({ url: '/organizations/org1', props: { flash: {}, errors: {} } });
    const props = Vue.reactive({
        surface: { forms: [{ id: 'F-ORG-001', name: 'Organization Profile Management', alias: null }, { id: 'F-ORG-002', name: 'Candidate Endorsement Grant', alias: null }] },
        organization: { id: 'org1', name: 'Guild', status: 'active', purpose: '', agent: { name: 'Agent One', is_viewer: true } },
        machine: [],
        ownership: { structure: 'member_owned', isCgc: false, stakes: [], memberCounts: {}, structureHistory: [] },
        board: null,
        endorsements: { incoming: [], granted: [], total: 0 },
        documents: [], contracts: [], myMembership: null, myWorker: null, jobs: [],
        pendingMembers, agentSearch,
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

const applicant = { id: 'u20', name: 'Applicant', profile_href: '/people?who=u20', public_handle: null };
const pendingOne = { rows: [{ id: 'm1', user: applicant, kind: 'member', applied_at: '2026-09-13T00:00:00Z' }], pages: { previous: null, next: null, first: '/organizations/org1' } };
const searchOne = { query: 'new', by: 'name', searched: true, candidates: [{ id: 'u9', name: 'New Agent', profile_href: '/people?who=u9', public_handle: null }], previous: null, next: null };

test('the agent accepts a pending application via the exact route with per-row feedback', async t => {
    const f = await fixture(t, { pendingMembers: pendingOne });
    const accept = f.button('Accept');
    assert.ok(accept, 'the Accept control renders for the agent');
    accept.props.onClick();
    assert.equal(f.posts.length, 1);
    assert.equal(f.posts[0][0], '/organizations/org1/memberships/m1/decision');
    assert.deepEqual(copy(f.posts[0][1]), { decision: 'accept' });
    const opts = f.posts[0][2];
    opts.onStart(); await flush();
    assert.ok(f.button('Working…'), 'the row shows a busy label while filing');
    opts.onSuccess(); opts.onFinish(); await flush();
    assert.match(f.text(), /Application accepted/);
});

test('the agent declines a pending application via the exact route', async t => {
    const f = await fixture(t, { pendingMembers: pendingOne });
    const decline = f.button('Decline');
    assert.ok(decline);
    decline.props.onClick();
    assert.equal(f.posts[0][0], '/organizations/org1/memberships/m1/decision');
    assert.deepEqual(copy(f.posts[0][1]), { decision: 'decline' });
    const opts = f.posts[0][2];
    opts.onError({ constitution: 'Membership is not pending.' }); opts.onFinish(); await flush();
    assert.match(f.text(), /not pending/);
});

test('the agent transfers agency to a chosen person via the exact route', async t => {
    const f = await fixture(t, { agentSearch: searchOne });
    // No transfer control until a person is picked.
    assert.equal(f.button('Transfer agency'), undefined);
    const select = f.button('Select');
    assert.ok(select, 'a search result offers a Select control');
    select.props.onClick(); await flush();
    const transfer = f.button('Transfer agency');
    assert.ok(transfer, 'the transfer control appears once a person is selected');
    transfer.props.onClick();
    assert.equal(f.posts[0][0], '/organizations/org1/agent');
    assert.deepEqual(copy(f.posts[0][1]), { agent_user_id: 'u9' });
    const opts = f.posts[0][2];
    opts.onStart(); await flush();
    assert.ok(f.button('Transferring…'), 'the transfer control shows a busy label');
    opts.onSuccess(); opts.onFinish(); await flush();
    assert.match(f.text(), /Agency transferred/);
});

test('a non-manager sees no review or transfer controls and files nothing', async t => {
    const f = await fixture(t, { manage: false, pendingMembers: pendingOne, agentSearch: searchOne });
    assert.equal(f.button('Accept'), undefined);
    assert.equal(f.button('Decline'), undefined);
    assert.equal(f.button('Select'), undefined);
    assert.equal(f.button('Transfer agency'), undefined);
    assert.equal(f.button('Search people'), undefined);
    assert.equal(f.posts.length, 0);
});
