import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';

const node = () => ({ children: [] });
const renderer = Vue.createRenderer({
    createElement: node, createText: node, createComment: node,
    setText() {}, setElementText() {}, patchProp() {}, remove() {},
    parentNode: () => null, nextSibling: () => null,
    insert(child, parent) { parent.children.push(child); },
});
const component = name => ({
    props: ['disabled', 'href', 'count', 'rank', 'approved', 'busy', 'candidacy', 'value', 'label'],
    setup(props, { slots }) {
        return () => Vue.h(name === 'Btn' ? 'button' : name === 'Link' ? 'a' : 'section',
            { disabled: props.disabled, href: props.href, 'data-component': name,
                'data-count': props.count, 'data-rank': props.rank, 'data-value': props.value },
            [slots.default?.(), slots.control?.({ id: 'fixture' }), ['Stat', 'Field'].includes(name) ? props.label : null]);
    },
});
function properties() {
    return Vue.reactive({ surface: {}, race: { id: 'race', election_id: 'election', phase: 'approval', finalist_count: 60 },
        stats: { myActiveApprovals: 35, validatedCandidates: 90 }, myApprovals: ['candidate-1'],
        standings: [1, 2].map(id => ({ rank: id, candidacy_id: `candidate-${id}`, status: 'validated', approvals: 100 - id, delta: 0,
            candidacy: { id: `candidate-${id}`, name: 'Same name', endorsements: { orgs: [], more_organizations: false } } })),
        filters: { q: '', organization: '', endorser: 'any', incumbents: false, approved: false },
        pagination: { pageSize: 20, next: '/next?cursor=next' }, approvable: true, inFootprint: true });
}
async function compiled(props, inlineTemplate = false, filename = 'Pages/Elections/OpenBallot.vue') {
    const calls = [], announcements = [];
    const page = Vue.reactive({ props: { errors: {} }, url: '/elections/election/open-ballot?race=race&q=water&cursor=page-two' });
    const router = Object.fromEntries(['get', 'post', 'delete'].map(method => [method, (...args) => calls.push({ method, args })]));
    const source = await readFile(new URL(`../../resources/js/${filename}`, import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const code = compileScript(descriptor, { id: 'approval-fixture', inlineTemplate }).content;
    const context = vm.createContext({ URL, window: { location: { origin: 'http://fixture.invalid' } } });
    const mod = new vm.SourceTextModule(code, { context });
    await mod.link(name => {
        let values;
        if (name === 'vue') values = Vue;
        else if (name === '@inertiajs/vue3') values = { router, usePage: () => page, Link: component('Link') };
        else if (name.endsWith('/useAnnounce')) values = { useAnnounce: () => ({ announce: text => announcements.push(text) }) };
        else if (name.endsWith('/useJourneyNudge')) values = { useJourneyNudge: () => ({ show: Vue.ref(false), dismiss() {}, href: '/journey' }) };
        else values = { default: component(name.split('/').at(-1).replace('.vue', '')) };
        return new vm.SyntheticModule(Object.keys(values), function () {
            for (const [key, value] of Object.entries(values)) this.setExport(key, value);
        }, { context });
    });
    await mod.evaluate();
    const actual = mod.namespace.default;
    if (inlineTemplate) return { html: await renderToString(Vue.createSSRApp(actual, props)), calls };
    actual.render = () => null;
    const holder = { current: null };
    const app = renderer.createApp({ render: () => Vue.h(actual, { ...props, ref: value => { holder.current = value; } }) });
    app.mount(node());
    return { state: holder.current.$.setupState, calls, announcements, page, close: () => app.unmount() };
}

test('personal total spans the race, mutations serialize, and a rejected approval reverts only private UI state', async () => {
    const props = properties(); const fixture = await compiled(props); const { state, calls } = fixture;
    assert.equal(state.myActiveApprovals, 35);
    state.toggleApprove('candidate-2', true);
    assert.equal(state.myActiveApprovals, 36); assert.equal(state.approved['candidate-2'], true);
    assert.equal(props.standings[1].approvals, 98);
    state.toggleApprove('candidate-1', false); state.applyFilters();
    assert.equal(calls.length, 1, 'An active mutation must not be canceled by another local action or search.');
    const options = calls[0].args.at(-1); options.onError(); options.onFinish();
    assert.equal(state.myActiveApprovals, 35); assert.equal(state.approved['candidate-2'], false);
    assert.equal(state.saving, false); fixture.close();
});

test('successful server refresh reconciles totals and page switches without adding to public counts', async () => {
    const props = properties(); const fixture = await compiled(props); const { state, calls } = fixture;
    state.toggleApprove('candidate-2', true);
    props.myApprovals = ['candidate-1', 'candidate-2']; props.stats = { ...props.stats, myActiveApprovals: 36 };
    await Vue.nextTick();
    calls[0].args.at(-1).onSuccess(); calls[0].args.at(-1).onFinish();
    assert.equal(state.myActiveApprovals, 36); assert.equal(state.approved['candidate-2'], true);
    props.myApprovals = ['candidate-21']; await Vue.nextTick();
    assert.equal(state.approved['candidate-2'], undefined); assert.equal(state.approved['candidate-21'], true);
    assert.equal(state.myActiveApprovals, 36); assert.equal(props.standings[1].approvals, 98); fixture.close();
});

test('interrupted mutations restore switches and explicitly ask for confirmation of saved state', async () => {
    const fixture = await compiled(properties()); const { state, calls } = fixture;
    state.toggleApprove('candidate-1', false);
    assert.equal(state.myActiveApprovals, 34);
    calls[0].args.at(-1).onCancel(); calls[0].args.at(-1).onFinish();
    assert.equal(state.myActiveApprovals, 35); assert.equal(state.approved['candidate-1'], true);
    assert.match(state.notice, /Refresh this page to confirm/); fixture.close();
});

test('search and pagination make server requests and endorsement requests preserve the candidate page', async () => {
    const fixture = await compiled(properties()); const { state, calls } = fixture;
    state.filters.q = 'Beyond first page'; state.filters.approved = true; state.applyFilters();
    assert.equal(calls[0].method, 'get'); assert.equal(calls[0].args[1].q, 'Beyond first page');
    assert.equal(calls[0].args[1].approved, 1); assert.equal(calls[0].args[1].full, undefined);
    calls[0].args.at(-1).onFinish(); state.visit('/next?cursor=next');
    assert.equal(calls[1].args[0], '/next?cursor=next'); calls[1].args.at(-1).onFinish();
    state.loadEndorsements('candidate-2');
    assert.match(calls[2].args[0], /q=water/); assert.match(calls[2].args[0], /cursor=page-two/);
    assert.match(calls[2].args[0], /endorsements_for=candidate-2/);
    assert.equal(calls[2].args.at(-1).only.join(','), 'endorsementDetails'); fixture.close();
});

test('page boundaries do not move the finalist line and empty searches retain usable search controls', async () => {
    const props = properties();
    let { html } = await compiled(props, true);
    assert.doesNotMatch(html, /data-component="FinalistLine"/);
    assert.match(html, /Next candidates/); assert.doesNotMatch(html, /Show all 90/);
    props.standings[1].rank = 60; ({ html } = await compiled(props, true));
    assert.match(html, /data-component="FinalistLine"[^>]*data-count="60"/);
    props.standings = []; props.filters.q = 'no match'; ({ html } = await compiled(props, true));
    assert.match(html, /Candidate name, statement or topic/); assert.match(html, /Clear filters/);
    assert.match(html, /No candidates on this page match your search/);
});

test('repeated display names have distinct public profile references and accessible link labels', async () => {
    const render = reference => compiled({ candidacy: { id: reference, name: 'Same name', profile_reference: reference,
        profile_href: `/candidates/${reference}`, public_handle: null, endorsements: { orgs: [] } }, rank: null, approvals: 0 }, true, 'Components/Electoral/CandidateRow.vue');
    const first = (await render('10000000-0000-4000-8000-000000001001')).html;
    const second = (await render('10000000-0000-4000-8000-000000001021')).html;
    assert.match(first, /Profile …000000001001/); assert.match(second, /Profile …000000001021/);
    assert.match(first, /aria-label="Open Same name&#39;s candidate profile, reference 10000000-0000-4000-8000-000000001001"/);
    assert.match(first, /Awaiting daily ranking/);
});
