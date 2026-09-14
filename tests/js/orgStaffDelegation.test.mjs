import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

/* IO-5 — scoped staff delegation controls on the organization detail page
   (operator ruling 2026-09-13 · org-staff-delegation-model = A). Compiled-Vue
   test (no jsdom): the real OrgDetail.vue script runs, the template renders into
   a minimal renderer, and the Inertia router is stubbed to record
   post()/get()/delete() calls so the exact routes are asserted. */

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = globalThis.document || { activeElement: null };

async function fixture(t, { manage = true, can = {}, agentSearch = null, delegations = null, pendingMembers = null } = {}) {
    const posts = [], visits = [], deletes = [];
    const page = Vue.reactive({ url: '/organizations/org1', props: { flash: {}, errors: {} } });
    const props = Vue.reactive({
        surface: { forms: [
            { id: 'F-ORG-001', name: 'Organization Profile Management', alias: null },
            { id: 'F-ORG-002', name: 'Candidate Endorsement Grant', alias: null },
            { id: 'F-ORG-011', name: 'Staff Delegation', alias: null },
        ] },
        organization: { id: 'org1', name: 'Guild', status: 'active', purpose: '', agent: { name: 'Agent One', is_viewer: true } },
        machine: [],
        ownership: { structure: 'member_owned', isCgc: false, stakes: [], memberCounts: {}, structureHistory: [] },
        board: null,
        endorsements: { incoming: [], granted: [], total: 0 },
        documents: [], contracts: [], myMembership: null, myWorker: null, jobs: [],
        pendingMembers, agentSearch, delegations,
        can: { manage, join: false, registerWorker: false, cosign: false, steerEconomy: false, ...can },
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
                router: { get: (...args) => visits.push(args), post: (...args) => posts.push(args), delete: (...args) => deletes.push(args) },
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
    return { props, page, posts, visits, deletes, nodes, text: () => textOf(root),
        buttons: label => nodes().filter(el => el.tag === 'button' && textOf(el).trim() === label),
        button: label => nodes().find(el => el.tag === 'button' && textOf(el).trim() === label) };
}

const person = { id: 'u9', name: 'New Agent', profile_href: '/people?who=u9', public_handle: null };
const searchOne = { query: 'new', by: 'name', searched: true, candidates: [person], previous: null, next: null };
const oneGrant = { rows: [{ id: 'g1', grantee: { id: 'u20', name: 'Delegate M', profile_href: '/people?who=u20', public_handle: null }, bucket: 'membership', status: 'active', granted_at: '2026-09-13T00:00:00Z' }], pages: { previous: null, next: null, first: '/organizations/org1' } };
const onePending = { rows: [{ id: 'm1', user: { id: 'u30', name: 'Applicant A', profile_href: '/people?who=u30', public_handle: null }, kind: 'member', applied_at: '2026-09-13T00:00:00Z' }], pages: { previous: null, next: null, first: '/organizations/org1' } };

test('the agent grants a task to a chosen person via the exact route with grantee + bucket', async t => {
    const f = await fixture(t, { agentSearch: searchOne });
    // The delegation block renders a Select for the search result. It is the
    // last "Select" (the agent-transfer block renders one first).
    const selects = f.buttons('Select');
    assert.ok(selects.length >= 1, 'the delegation block offers a Select control');
    selects[selects.length - 1].props.onClick(); await flush();
    const grant = f.button('Grant task');
    assert.ok(grant, 'the grant control appears once a person is selected');
    grant.props.onClick();
    assert.equal(f.posts.length, 1);
    assert.equal(f.posts[0][0], '/organizations/org1/delegations');
    assert.deepEqual(copy(f.posts[0][1]), { grantee_user_id: 'u9', bucket: 'membership' });
    const opts = f.posts[0][2];
    opts.onStart(); await flush();
    assert.ok(f.button('Granting…'), 'the grant control shows a busy label');
    opts.onSuccess(); opts.onFinish(); await flush();
    assert.match(f.text(), /Task delegated/);
});

test('the agent revokes an active grant via the exact DELETE route', async t => {
    const f = await fixture(t, { delegations: oneGrant });
    const revoke = f.button('Revoke');
    assert.ok(revoke, 'each active grant offers a Revoke control');
    revoke.props.onClick();
    assert.equal(f.deletes.length, 1);
    assert.equal(f.deletes[0][0], '/organizations/org1/delegations/g1');
    const opts = f.deletes[0][1];
    opts.onStart(); await flush();
    assert.ok(f.button('Revoking…'), 'the row shows a busy label while revoking');
    opts.onSuccess(); opts.onFinish(); await flush();
    assert.match(f.text(), /Delegation revoked/);
});

test('a membership delegate sees the applicant queue and its controls but no agent-only block', async t => {
    // A delegate: not the agent (manage false), holds only the membership bucket,
    // and the server emits pendingMembers for them (the IO-5 repair — the queue
    // is no longer agent-only when a membership delegate holds accept/decline).
    const f = await fixture(t, { manage: false, can: { membership: true }, pendingMembers: onePending });
    // The membership review card body renders for a membership delegate.
    assert.match(f.text(), /Review people who applied to join/);
    // The delegate's own data path renders: the waiting application row with its
    // Accept and Decline controls (both live only inside the per-row v-for), and
    // NOT the empty-queue branch.
    assert.doesNotMatch(f.text(), /No applications are waiting/);
    assert.ok(f.button('Accept'), 'the delegate sees the Accept control for a waiting application');
    assert.ok(f.button('Decline'), 'the delegate sees the Decline control for a waiting application');
    // Agent-only sections stay hidden: no delegation grant, no agency transfer.
    assert.doesNotMatch(f.text(), /Let a person handle one kind of task/);
    assert.equal(f.button('Grant task'), undefined);
    assert.equal(f.button('Transfer agency'), undefined);
    assert.equal(f.button('Revoke'), undefined);
    assert.equal(f.posts.length, 0);
    assert.equal(f.deletes.length, 0);
});

test('a non-agent, non-delegate sees no delegation block at all', async t => {
    const f = await fixture(t, { manage: false, agentSearch: searchOne, delegations: oneGrant });
    assert.doesNotMatch(f.text(), /Let a person handle one kind of task/);
    assert.equal(f.button('Grant task'), undefined);
    assert.equal(f.button('Revoke'), undefined);
    assert.equal(f.posts.length, 0);
    assert.equal(f.deletes.length, 0);
});
