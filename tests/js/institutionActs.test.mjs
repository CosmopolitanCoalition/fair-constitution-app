import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compileScript, parse } from '@vue/compiler-sfc';

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const snapshot = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
const base = '/legislatures/80000000-0000-4000-8000-000000000002/institution-acts';
const actions = ['delegate-executive', 'elect-executive', 'create-department', 'create-court', 'elect-court', 'create-cgc'];
globalThis.document = { activeElement: null };

async function fixture(t) {
    const posts = [], visits = [], memory = new Map();
    const page = Vue.reactive({ url: base + '?action=create-cgc&processes_cursor=current-process-page', props: { flash: {} } });
    const vote = { tally: { status: 'open', mode: 'unicameral', stage: 'floor', thresholdClass: 'majority', serving: 5, requiredYes: 3,
        tallies: { yes: 1, no: 0, abstain: 0 }, outcome: 'pending' }, cast_url: '/votes/fixture/cast', can_cast: true, my_cast: null };
    const props = Vue.reactive({ surface: {}, workspace: {}, legislature: { id: 'scope-one', name: 'Fixture legislature' },
        context: { actions: actions.map(key => ({ key, name: key, ready: true })), role: 'Legislator', canFile: true, canCast: true,
            executive: { status: 'delegated' }, court: { minimumJudges: 8 } }, filingUrl: base, initialAction: 'create-cgc',
        proposals: { records: [{ id: 'proposal', name: 'Public garden', action: 'Create a common-good corporation', status: 'open', details: [{ label: 'Charter', value: 'Public green spaces' }], vote }], pagination: { next: base + '?action=create-cgc&acts_cursor=older', first: base } },
        processes: { records: [{ id: 'process', name: 'Elected court consent', status: 'open', yes: 1, required: 2, total: 3, canOpen: true, open_url: base + '/consents/process', href: base + '?process=process' }], pagination: { first: base } },
        constituents: { name: 'Constituent decisions', records: [{ id: 'place', name: 'Child place', result: 'pending', href: '/legislatures/child/institution-acts?process=process' }], pagination: { first: base } },
    });
    const actual = new Set(['Pages/Legislature/InstitutionActs.vue', 'Components/Legislature/ConsentVoteCard.vue', 'Components/Legislature/VoteTally.vue', 'Components/Ui/HistoryPager.vue']);
    const slots = { setup: (_, { slots }) => () => Vue.h('section', slots.default?.()) };
    const Btn = { inheritAttrs: false, setup: (_, { attrs, slots }) => () => Vue.h('button', attrs, slots.default?.()) };
    const Link = { props: ['href'], setup: (p, { slots }) => () => Vue.h('a', { href: p.href }, slots.default?.()) };
    const context = vm.createContext({ console, URL }); const cache = new Map();
    function compiled(file) {
        const source = file === 'Pages/Legislature/InstitutionActs.vue' ? read(file).replace('</script>', 'defineExpose({ action, values, busy, error, submit, openConsent });\n</script>') : read(file);
        const { descriptor } = parse(source);
        return new vm.SourceTextModule(compileScript(descriptor, { id: file, inlineTemplate: true }).content, { context, identifier: file });
    }
    const remember = (state, key) => {
        if (memory.has(key)) Object.assign(state, snapshot(memory.get(key)));
        Vue.watch(state, () => memory.set(key, snapshot(state)), { deep: true, immediate: true }); return state;
    };
    async function dependency(name) {
        const file = name.startsWith('@/') ? name.slice(2) : name === './VoteTally.vue' ? 'Components/Legislature/VoteTally.vue' : name;
        if (cache.has(file)) return cache.get(file);
        let module;
        if (actual.has(file)) module = compiled(file);
        else {
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? { Link, usePage: () => page, useRemember: remember,
                router: { post: (url, data, options) => posts.push({ url, data: snapshot(data), options }), get: (...args) => visits.push(args) },
            } : name === 'vue-i18n' ? { useI18n: () => ({ t: (key, arg, fallback) => typeof arg === 'string' ? arg : fallback ?? key }) } : { default: file.endsWith('/Btn.vue') ? Btn : slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(file, module); return module;
    }
    const file = 'Pages/Legislature/InstitutionActs.vue'; const module = compiled(file); cache.set(file, module); await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {},
        get options() { return this.children.filter(child => child.tag === 'option'); } });
    const renderer = Vue.createRenderer({ createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); }, patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; }, parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'); let app, state;
    function mount() { app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, { ...props, ref: value => { if (value) state = value; } }) }); app.mount(root); }
    mount(); await flush(); t.after(() => app.unmount());
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    return { props, page, posts, visits, get state() { return state; }, text: () => text(root), nodes: () => nodes(),
        button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label),
        async remount() { app.unmount(); mount(); await flush(); },
    };
}

test('all six compiled forms submit only their action fields and retain independent drafts', async t => {
    const f = await fixture(t);
    const payloads = {
        'delegate-executive': { delegated_scope: 'Public services', member_count: 5, interested: true },
        'elect-executive': { target_type: 'individual', charter_text: 'Public election charter' },
        'create-department': { name: 'Transport', kind: 'other', function_text: 'Transit', owner_seats: 2 },
        'create-court': { court_name: 'Court', function_text: 'Cases', committee_judge_count: 8 },
        'elect-court': { judge_count: 8, charter_text: 'Public court charter' },
        'create-cgc': { name: 'Public garden', charter: 'Public green space', owner_seats: 2 },
    };
    for (const [action, payload] of Object.entries(payloads)) {
        f.state.action = action; await flush();
        for (const [key, value] of Object.entries(payload)) {
            assert.ok(f.nodes().some(el => el.props.id === 'act-' + key)); f.state.values[action + ':' + key] = value;
        }
        f.state.values[action + ':nominees'] = ['foreign']; f.state.values[action + ':system_act'] = true;
        f.state.submit(); assert.equal(f.posts.at(-1).url, base); assert.deepEqual(f.posts.at(-1).data, { action, ...payload });
    }
    await flush(); await f.remount();
    assert.equal(f.state.values['create-court:committee_judge_count'], 8); assert.equal(f.state.values['create-cgc:charter'], 'Public green space');
    f.state.action = 'create-court'; await flush(); assert.match(f.text(), /configured minimum is 8/);
    assert.ok(f.nodes().filter(el => ['input', 'select', 'textarea'].includes(el.tag) && el.props.id?.startsWith('act-')).every(el => f.nodes().some(label => label.tag === 'label' && label.props.for === el.props.id)));
});

test('filing shows busy and recoverable validation feedback and keeps the exact draft on retry', async t => {
    const f = await fixture(t); f.state.values['create-cgc:name'] = 'Public garden'; f.state.values['create-cgc:charter'] = 'Draft'; f.state.submit();
    const first = f.posts[0]; first.options.onStart(); await flush(); assert.match(f.text(), /Filing proposal/); f.state.submit(); assert.equal(f.posts.length, 1);
    first.options.onError({ owner_seats: 'Choose the governor seat count.' }); first.options.onFinish(); await flush();
    assert.match(f.text(), /Choose the governor seat count/); assert.ok(f.nodes().some(el => el.props.role === 'alert'));
    f.state.submit(); assert.deepEqual(f.posts[1].data, first.data); f.posts[1].options.onStart(); assert.equal(f.state.error, ''); f.posts[1].options.onFinish();
});

test('preview and unavailable institutional states preserve browsing and refuse client submissions', async t => {
    const f = await fixture(t); f.props.context.canFile = false; f.props.context.canCast = false; f.props.proposals.records[0].vote.can_cast = false; await flush();
    assert.match(f.text(), /Explore the forms/); assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/legislatures/child/institution-acts?process=process'));
    f.state.submit(); assert.equal(f.posts.length, 0); assert.equal(f.button('Vote yes'), undefined);
    f.props.context.canFile = true; f.props.context.actions.find(a => a.key === 'create-cgc').ready = false; await flush();
    f.state.submit(); assert.equal(f.posts.length, 0);
    f.props.context.executive = null; await flush(); assert.match(f.text(), /no overseeing executive assigned/);
    f.state.openConsent({ canOpen: false, open_url: '/foreign' }); assert.equal(f.posts.length, 0);
});

test('page hosts real shared vote controls, constituent opening, and recorded Speaker tie controls', async t => {
    const f = await fixture(t); assert.match(f.text(), /Public green spaces/);
    f.button('Vote yes').props.onClick(); assert.equal(f.posts[0].url, '/votes/fixture/cast'); assert.equal(f.posts[0].data.value, 'yes');
    f.posts[0].options.onError({ constitution: 'Member is no longer seated.' }); f.posts[0].options.onFinish(); await flush(); assert.match(f.text(), /no longer seated/);
    f.button('Open this legislature’s consent vote').props.onClick(); assert.equal(f.posts[1].url, base + '/consents/process'); assert.deepEqual(f.posts[1].data, {});
    f.props.context.canCast = false; const vote = f.props.proposals.records[0].vote;
    vote.tally = { ...vote.tally, status: 'closed', outcome: 'tied' }; vote.can_tiebreak = true; vote.tiebreak_url = '/votes/fixture/tiebreak'; await flush();
    assert.equal(f.button('Vote yes'), undefined); f.button('Break tie: no').props.onClick(); assert.deepEqual(f.posts[2].data, { value: 'no', explanation: null }); assert.equal(f.posts[2].url, '/votes/fixture/tiebreak');
});

test('history pager changes only its own cursor and gives loading, error, and first-page recovery', async t => {
    const f = await fixture(t); f.button('Next').props.onClick();
    const [url, , options] = f.visits[0]; const parsed = new URL(url, 'http://fixture.invalid');
    assert.equal(parsed.searchParams.get('acts_cursor'), 'older'); assert.equal(parsed.searchParams.get('processes_cursor'), 'current-process-page');
    assert.deepEqual(snapshot(options.only), ['proposals']); assert.equal(options.preserveState, true);
    options.onStart(); await flush(); assert.match(f.text(), /Loading records/); f.button('Next').props.onClick(); assert.equal(f.visits.length, 1);
    options.onError({ acts_cursor: 'Return to the first page.' }); options.onFinish(); await flush(); assert.match(f.text(), /Return to the first page/);
    f.button('First page').props.onClick(); assert.equal(new URL(f.visits[1][0], 'http://fixture.invalid').searchParams.has('acts_cursor'), false);
});

test('institution entry links target their exact source legislature and expose the workspace from chamber navigation', () => {
    const executive = read('Pages/Executive/Home.vue'), court = read('Pages/Judiciary/Home.vue');
    assert.match(executive, /props\.executive\.legislature\.id}\/?institution-acts\?action=delegate-executive/);
    assert.match(executive, /institution-acts\?action=elect-executive/); assert.match(executive, /institution-acts\?action=create-department/);
    assert.match(court, /institution-acts\?action=create-court/); assert.match(court, /institution-acts\?action=elect-court/);
    assert.doesNotMatch(executive, /bills\?intro=1&subject=executive/); assert.doesNotMatch(court, /bills\?intro=1&subject=judiciary/);
    assert.match(read('Components/Legislature/LegislatureWorkspaceNav.vue'), /workspace\.institutions/);
});
