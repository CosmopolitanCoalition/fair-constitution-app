// Synthetic UI/navigation only: node --experimental-vm-modules --test tests/js/cgcGovernors.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const id = n => `70000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const person = n => ({ id: id(n), name: 'Same Name', profile_href: `/people?who=${id(n)}`, public_handle: n === 1 ? '@first' : null });
const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const snapshot = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = { activeElement: null };

async function fixture(t, kind = 'governors') {
    const memory = new Map(), visits = [], posts = [];
    const page = Vue.reactive({ url: `/organizations/${id(4)}/board-elections?governor_cursor=appointments-2#governor-appointments`, props: { auth: { user: { id: id(10) } } } });
    const consent = { tally: { status: 'open', mode: 'unicameral', stage: 'floor', thresholdClass: 'majority', serving: 7, requiredYes: 4,
        tallies: { yes: 1, no: 0, abstain: 0 }, outcome: 'pending' }, cast_url: `/votes/${id(2043)}/cast`, can_cast: true, my_cast: null };
    const contextProps = { canNominate: true, preview: false, actor_name: 'Civic Nomination Member', executive_name: 'Actual executive',
        executive_href: '/executives/' + id(2), legislature_name: 'Creating legislature', legislature_href: `/legislatures/${id(3)}/chamber`,
        nominate_href: `/organizations/${id(4)}/governor-nominations` };
    const appointment = { id: id(1043), seat_no: 1, status: 'nominated', nominee: person(3), consent, dossier: { body: 'Public nomination statement.' } };
    const props = Vue.reactive(kind === 'consent' ? { consent, canCast: true } : kind === 'board' ? {
        surface: {}, organization: { id: id(4), name: 'Public garden', is_cgc: true }, appointmentContext: contextProps,
        governorAppointments: [appointment], governorPages: {}, nomineeDirectory: { query: '', by: 'name', candidates: [], searched: false },
        ownerTrack: { exists: false }, workerTrack: { exists: false }, can: {},
    } : { organization: { id: id(4), name: 'Public garden' }, context: contextProps,
        directory: { query: '', by: 'name', candidates: [], searched: false }, appointments: [appointment], pages: {} });
    const remember = (state, key) => {
        if (memory.has(key)) state.value = snapshot(memory.get(key));
        Vue.watch(state, () => memory.set(key, snapshot(Vue.unref(state))), { deep: true, immediate: true });
        return state;
    };
    const useForm = (key, initial) => {
        if (typeof key !== 'string') { initial = key; key = null; }
        const fields = Object.keys(initial);
        const state = Vue.reactive({ ...snapshot(initial), processing: false, errors: {}, clearErrors() { this.errors = {}; },
            reset() { Object.assign(this, snapshot(initial)); },
            post(url, options) { posts.push({ url, data: Object.fromEntries(fields.map(field => [field, snapshot(this[field])])), options }); },
        });
        if (key) {
            if (memory.has(key)) Object.assign(state, snapshot(memory.get(key)));
            Vue.watch(state, () => memory.set(key, Object.fromEntries(fields.map(field => [field, snapshot(state[field])]))), { deep: true, immediate: true });
        }
        return state;
    };
    const slots = { setup: (_, { slots }) => () => Vue.h('section', slots.default?.()) };
    const Link = { props: ['href'], setup: (p, { slots }) => () => Vue.h('a', { href: p.href }, slots.default?.()) };
    const Btn = { inheritAttrs: false, setup: (_, { attrs, slots }) => () => Vue.h('button', attrs, slots.default?.()) };
    const actual = new Set(['Components/Organizations/CgcGovernors.vue', 'Components/Legislature/ConsentVoteCard.vue',
        'Components/Legislature/VoteTally.vue', 'Components/Ui/SelectionIdentity.vue', 'Components/Ui/HistoryPager.vue', 'Pages/Organizations/BoardElections.vue']);
    const exposure = { 'Components/Organizations/CgcGovernors.vue': 'nomination, selected, query, by, searching, error, selectionError, search, choose, clear, submit',
        'Components/Legislature/ConsentVoteCard.vue': 'cast, breakTie, tieExplanation, busy, allowed, canBreakTie, error, submitted' };
    const context = vm.createContext({ console, URL });
    const cache = new Map();
    function compiled(file) {
        const source = exposure[file] ? read(file).replace('</script>', `defineExpose({ ${exposure[file]} });\n</script>`) : read(file);
        const { descriptor } = parse(source);
        return new vm.SourceTextModule(compileScript(descriptor, { id: file, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content, { context, identifier: file });
    }
    async function dependency(name, referencing) {
        const file = name.startsWith('@/') ? name.slice(2) : name === './VoteTally.vue' ? 'Components/Legislature/VoteTally.vue' : name;
        if (cache.has(file)) return cache.get(file);
        let module;
        if (actual.has(file)) module = compiled(file);
        else {
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? {
                Link, usePage: () => page, useForm, useRemember: remember,
                router: { get: (...args) => visits.push(args), post: (url, data, options) => posts.push({ url, data: snapshot(data), options }) },
            } : name === 'vue-i18n' ? { useI18n: () => ({ t: (key, arg, fallback) => typeof arg === 'string' ? arg : fallback ?? key }) }
                : { default: file.endsWith('/Btn.vue') ? Btn : slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(file, module); return module;
    }
    const rootFile = kind === 'consent' ? 'Components/Legislature/ConsentVoteCard.vue' : kind === 'board' ? 'Pages/Organizations/BoardElections.vue' : 'Components/Organizations/CgcGovernors.vue';
    const module = compiled(rootFile); cache.set(rootFile, module); await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {},
        get options() { return this.children.filter(child => child.tag === 'option'); } });
    const renderer = Vue.createRenderer({ createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'); let app, state;
    function mount() { app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, { ...props, ref: value => { if (value) state = value; } }) }); app.mount(root); }
    mount(); await flush(); t.after(() => app.unmount());
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    return { page, props, visits, posts, get state() { return state; }, text: () => text(root), nodes: () => nodes(),
        button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label),
        async remount() { app.unmount(); mount(); await flush(); },
    };
}

test('board page renders the working governor surface, real oversight links, dossier and shared vote controls', async t => {
    const f = await fixture(t, 'board');
    assert.match(f.text(), /Governor appointments/); assert.match(f.text(), /Actual executive/); assert.match(f.text(), /Public nomination statement/);
    assert.ok(f.button('Vote yes')); assert.ok(f.button('Submit nomination'));
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/executives/' + id(2)));
    assert.equal(f.visits.length, 0); assert.equal(f.posts.length, 0);
});

test('nominee search merges only its fields, pages duplicate names and retains selected nominee and dossier after return', async t => {
    const f = await fixture(t);
    assert.equal(f.visits.length, 0);
    f.state.query = '  Same  '; f.state.search();
    let [url, data, options] = f.visits[0];
    assert.equal(new URL(url, 'https://conference.example').searchParams.get('governor_cursor'), 'appointments-2');
    assert.equal(new URL(url, 'https://conference.example').searchParams.get('nominee_q'), 'Same');
    assert.deepEqual(snapshot(options.only), ['nomineeDirectory']); assert.equal(options.preserveState, true);
    options.onStart(); f.state.search(); assert.equal(f.visits.length, 1);
    f.page.url = url; options.onFinish();
    f.props.directory = { query: 'Same', by: 'name', searched: true, candidates: [person(1)], next: '/next' }; await flush();
    f.state.choose(person(1)); f.state.nomination.dossier = 'Skills and public experience'; await flush();
    f.page.url = f.page.url.replace('appointments-2', 'appointments-3');
    f.state.search(`/organizations/${id(4)}/board-elections?nominee_q=Same&nominee_by=name&nominee_cursor=next&governor_cursor=stale`);
    assert.equal(new URL(f.visits[1][0], 'https://conference.example').searchParams.get('governor_cursor'), 'appointments-3');
    f.props.directory = { query: 'Same', by: 'name', searched: true, candidates: [person(2)] }; await flush();
    assert.equal(f.state.nomination.nominee_user_id, id(1)); assert.match(f.text(), /@first/);
    await f.remount(); assert.equal(f.state.nomination.dossier, 'Skills and public experience'); assert.equal(f.state.selected.id, id(1));
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/people?who=' + id(1)));
    f.state.choose(person(2)); await flush(); assert.equal(f.state.nomination.nominee_user_id, id(2));
    f.state.clear(); await flush(); assert.equal(f.state.selected, null);
});

test('nomination submits only exact chosen person and dossier, retaining failure and clearing success', async t => {
    const f = await fixture(t);
    f.state.submit(); assert.equal(f.posts.length, 0); assert.match(f.state.selectionError, /Select the person/);
    f.state.choose(person(2)); f.state.nomination.dossier = 'Public statement'; f.state.submit();
    assert.equal(f.posts[0].url, `/organizations/${id(4)}/governor-nominations`);
    assert.deepEqual(f.posts[0].data, { nominee_user_id: id(2), dossier: 'Public statement' });
    f.state.nomination.processing = true; f.state.submit(); assert.equal(f.posts.length, 1);
    f.state.nomination.processing = false; f.state.nomination.errors = { constitution: 'No governor seat is vacant.' }; await flush();
    assert.match(f.text(), /No governor seat is vacant/); assert.equal(f.state.nomination.dossier, 'Public statement');
    f.posts[0].options.onSuccess(); await flush(); assert.equal(f.state.nomination.nominee_user_id, ''); assert.equal(f.state.selected, null);
});

test('role preview is explicit, cannot search or nominate, and reports loading and search failures on an active form', async t => {
    const f = await fixture(t);
    f.props.context = { ...f.props.context, canNominate: false, preview: true, reason: 'Only the assigned executive may nominate.' }; await flush();
    assert.match(f.text(), /Role preview/); assert.ok(f.nodes().some(el => el.tag === 'fieldset' && el.props.disabled));
    f.state.query = 'Same'; f.state.search(); f.state.choose(person(1)); f.state.submit(); assert.equal(f.visits.length, 0); assert.equal(f.posts.length, 0);
    f.props.context = { ...f.props.context, canNominate: true, preview: false }; await flush();
    f.state.search(); const options = f.visits[0][2]; options.onStart(); await flush(); assert.match(f.text(), /Searching for nominees/);
    options.onError({ nominee_cursor: 'Search from the first page.' }); options.onFinish(); await flush();
    assert.match(f.text(), /Search from the first page/); assert.equal(f.state.searching, false);
    f.state.search(); f.visits[1][2].onStart(); assert.equal(f.state.error, ''); f.visits[1][2].onFinish();
});

test('actual shared consent buttons send existing vote endpoint and handle busy, refusal, retry and success', async t => {
    const f = await fixture(t, 'consent');
    const explanation = f.nodes().find(el => el.tag === 'textarea'); explanation.props['onUpdate:modelValue'](' Suitable nominee ');
    f.button('Vote yes').props.onClick();
    assert.equal(f.posts[0].url, `/votes/${id(2043)}/cast`); assert.deepEqual(f.posts[0].data, { value: 'yes', explanation: 'Suitable nominee' });
    const options = f.posts[0].options; options.onStart(); await flush(); assert.match(f.text(), /Submitting your vote/);
    f.state.cast({ value: 'no', explanation: null }); assert.equal(f.posts.length, 1);
    options.onError({ constitution: 'The appointment is no longer current.' }); options.onFinish(); await flush();
    assert.match(f.text(), /no longer current/); assert.ok(f.button('Vote yes'));
    f.button('Vote no').props.onClick(); f.posts[1].options.onStart(); assert.equal(f.state.error, '');
    f.posts[1].options.onSuccess(); f.posts[1].options.onFinish(); await flush();
    assert.match(f.text(), /Your vote has been recorded/); assert.equal(f.button('Vote yes'), undefined);
    f.state.cast({ value: 'abstain' }); assert.equal(f.posts.length, 2);
});

test('shared consent surface refuses preview, existing cast, missing URL and closed vote states', async t => {
    const f = await fixture(t, 'consent');
    for (const changed of [{ canCast: false }, { consent: { ...f.props.consent, my_cast: 'yes' } },
        { consent: { ...f.props.consent, cast_url: null } }, { consent: { ...f.props.consent, tally: { ...f.props.consent.tally, status: 'closed' } } }]) {
        f.props.canCast = true; f.props.consent = { ...f.props.consent, my_cast: null, cast_url: `/votes/${id(2043)}/cast`, tally: { ...f.props.consent.tally, status: 'open' } };
        Object.assign(f.props, changed); await flush(); f.state.cast({ value: 'yes' }); assert.equal(f.posts.length, 0); assert.equal(f.button('Vote yes'), undefined);
    }
});

test('Oversight continues using the shared component for both existing appointment and creation consents', () => {
    const source = read('Pages/Legislature/Oversight.vue');
    const { descriptor } = parse(source); assert.doesNotThrow(() => compileScript(descriptor, { id: 'oversight', inlineTemplate: true }));
    assert.match(source, /ConsentVoteCard :consent="adminOffice.pending"/); assert.match(source, /ConsentVoteCard :consent="consent"/);
    assert.doesNotMatch(source, /function castConsent\(/);
});

test('Speaker can submit either tied-consent outcome through the existing tiebreak route, retaining refusal and retry feedback', async t => {
    for (const value of ['yes', 'no']) {
        const f = await fixture(t, 'consent');
        f.props.canCast = false;
        f.props.consent = { ...f.props.consent, can_tiebreak: true, tiebreak_url: `/votes/${id(2043)}/tiebreak`,
            tally: { ...f.props.consent.tally, status: 'closed', outcome: 'tied' } };
        await flush(); assert.equal(f.button('Vote yes'), undefined); assert.ok(f.button('Break tie: ' + value));
        f.state.tieExplanation = '  Resolving consent  ';
        f.button('Break tie: ' + value).props.onClick();
        assert.equal(f.posts[0].url, `/votes/${id(2043)}/tiebreak`);
        assert.deepEqual(f.posts[0].data, { value, explanation: 'Resolving consent' });
        f.posts[0].options.onStart(); f.state.breakTie(value); assert.equal(f.posts.length, 1);
        f.posts[0].options.onError({ constitution: 'The tied appointment changed.' }); f.posts[0].options.onFinish(); await flush();
        assert.match(f.text(), /The tied appointment changed/); assert.equal(f.state.tieExplanation, '  Resolving consent  ');
        f.button('Break tie: ' + value).props.onClick(); f.posts[1].options.onStart(); assert.equal(f.state.error, '');
        f.posts[1].options.onSuccess(); f.posts[1].options.onFinish(); await flush();
        assert.equal(f.button('Break tie: ' + value), undefined); assert.match(f.text(), /Your vote has been recorded/);
    }
});

test('tiebreak action stays unavailable without exact Speaker consent permission and a closed tied record', async t => {
    const f = await fixture(t, 'consent');
    const target = { ...f.props.consent, can_tiebreak: true, tiebreak_url: `/votes/${id(2043)}/tiebreak`, tally: { ...f.props.consent.tally, status: 'closed', outcome: 'tied' } };
    for (const change of [{ can_tiebreak: false }, { tiebreak_url: null }, { my_cast: 'yes' },
        { tally: { ...target.tally, status: 'open' } }, { tally: { ...target.tally, outcome: 'failed' } }]) {
        f.props.consent = { ...target, ...change }; await flush(); f.state.breakTie('yes');
        assert.equal(f.posts.length, 0); assert.equal(f.button('Break tie: yes'), undefined);
    }
    f.props.consent = target; await flush(); f.state.breakTie('abstain'); assert.equal(f.posts.length, 0);
});
