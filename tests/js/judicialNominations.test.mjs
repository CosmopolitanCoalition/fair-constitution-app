// Compiled Vue lifecycle, navigation and synthetic confirmation requests. No server mutations.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const snapshot = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = { activeElement: null };

async function fixture(t) {
    const visits = [], posts = [], page = Vue.reactive({ url: '/judiciaries/court?confirmations_cursor=kept' });
    const props = Vue.reactive({ judiciary: { id: 'court' },
        context: { mode: 'constituent', can_designate: true, nominate_url: '/judiciaries/court/nomination-proposals', designate_url: '/judiciaries/court/judicial-committee', create_committee_url: '/legislatures/source/committees' },
        seats: { rows: [{ id: 'seat1', number: 1, nominator: 'Local legislature', legislature_id: 'local', can_propose: true }], pages: { next: '/judiciaries/court?seats_cursor=next' } },
        committees: { rows: [{ id: 'committee1', name: 'Existing review', href: '/committees/committee1' }], pages: {} },
        nominees: { query: '', by: 'name', candidates: [{ id: 'person1', name: 'Public Name' }], next: '/judiciaries/court?nominee_cursor=next' },
        proposals: { rows: [{ id: 'proposal1', title: 'Authorize nomination', status: 'open', nominee: { id: 'person1', name: 'Public Name' }, statement: 'Public reasons',
            vote: { can_cast: true, cast_url: '/votes/proposal1/cast', tally: { status: 'open', mode: 'unicameral', stage: 'committee', thresholdClass: 'supermajority', serving: 7, requiredYes: 6, tallies: { yes: 0, no: 0, abstain: 0 }, outcome: 'pending' } } }], pages: {} },
    });
    const context = vm.createContext({ URL, console, document: globalThis.document });
    const cache = new Map();
    const actual = new Set(['Components/Judiciary/JudicialNominations.vue', 'Components/Legislature/ConsentVoteCard.vue',
        'Components/Legislature/VoteTally.vue', 'Components/Ui/SelectionIdentity.vue', 'Components/Ui/HistoryPager.vue', 'Components/Ui/ThresholdMeter.vue']);
    const slots = { setup: (props, { slots }) => () => Vue.h('section', {}, [slots.default?.()]) };
    const Link = { props: ['href'], setup: (props, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: props.href }, slots.default?.()) };
    const Btn = { setup: (props, { slots, attrs }) => () => Vue.h('button', attrs, slots.default?.()) };
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
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? { Link, usePage: () => page,
                router: { get: (...args) => visits.push(args), post: (url, data, options) => posts.push({ url, data: snapshot(data), options }) } }
                : name === 'vue-i18n' ? { useI18n: () => ({ t: (key, arg, fallback) => typeof arg === 'string' ? arg : fallback ?? key }) }
                : { default: file.endsWith('/Btn.vue') ? Btn : slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(file, module); return module;
    }
    const file = 'Components/Judiciary/JudicialNominations.vue', module = compiled(file); cache.set(file, module);
    await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, get options() { return this.children; }, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {} });
    const renderer = Vue.createRenderer({ createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null });
    const root = element('root'), app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, props) });
    app.mount(root); t.after(() => app.unmount()); await flush();
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    return { props, visits, posts, page, nodes, text: () => text(root), button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label) };
}

const set = (f, id, value) => f.nodes().find(n => n.props.id === id).props['onUpdate:modelValue'](value);
const formFor = (f, field) => f.nodes().find(n => n.tag === 'form' && f.nodes(n).some(c => c.props.id === field));
const submit = form => form.props.onSubmit({ preventDefault() {} });

test('nomination selections file exact seat, body, person and statement with retry and busy feedback', async t => {
    const f = await fixture(t);
    assert.match(f.text(), /ordinary majority of all serving/);
    assert.ok(f.nodes().some(n => n.tag === 'a' && n.props.href === '/people?who=person1'));
    f.button('Select seat').props.onClick(); f.button('Select person').props.onClick();
    set(f, 'judicial-nomination-statement', 'Reasons to nominate'); await flush();
    submit(formFor(f, 'judicial-nomination-statement'));
    assert.deepEqual(f.posts[0].data, { seat_id: 'seat1', legislature_id: 'local', nominee_user_id: 'person1', statement: 'Reasons to nominate' });
    assert.equal(f.posts[0].url, '/judiciaries/court/nomination-proposals');
    f.posts[0].options.onStart(); await flush(); assert.match(f.text(), /Filing proposal/);
    submit(formFor(f, 'judicial-nomination-statement')); assert.equal(f.posts.length, 1);
    f.posts[0].options.onError({ constitution: 'This seat was filled.' }); f.posts[0].options.onFinish(); await flush();
    assert.match(f.text(), /seat was filled/); submit(formFor(f, 'judicial-nomination-statement')); assert.equal(f.posts.length, 2);
    f.posts[1].options.onSuccess(); f.posts[1].options.onFinish(); await flush(); assert.match(f.text(), /Proposal filed/);
    assert.equal(f.button('Propose this person').props.disabled, true);
});

test('public preview remains explorable and cannot submit, committee designation has its own action', async t => {
    const f = await fixture(t); f.props.context.mode = 'committee'; f.props.context.can_designate = false; f.props.seats.rows[0].can_propose = false; await flush();
    assert.match(f.text(), /including its chair/); assert.ok(f.nodes().some(n => n.props.href === '/legislatures/source/committees'));
    f.button('Select seat').props.onClick(); f.button('Select person').props.onClick(); set(f, 'judicial-nomination-statement', 'Preview');
    submit(formFor(f, 'judicial-nomination-statement')); assert.equal(f.posts.length, 0);
    f.button('Select committee').props.onClick(); set(f, 'judicial-designation-statement', 'Assign this responsibility');
    submit(formFor(f, 'judicial-designation-statement')); assert.equal(f.posts.length, 0);
    f.props.context.can_designate = true; await flush(); submit(formFor(f, 'judicial-designation-statement'));
    assert.equal(f.posts[0].url, '/judiciaries/court/judicial-committee');
    assert.deepEqual(f.posts[0].data, { committee_id: 'committee1', statement: 'Assign this responsibility' });
});

test('nominee search and seat pagination preserve independent context and expose errors', async t => {
    const f = await fixture(t); set(f, 'judicial-nominee-query', 'Public'); submit(formFor(f, 'judicial-nominee-query'));
    assert.equal(f.visits[0][0], '/judiciaries/court?confirmations_cursor=kept&nominee_q=Public&nominee_by=name');
    assert.deepEqual(snapshot(f.visits[0][2].only), ['judicialNominees']);
    f.visits[0][2].onStart(); await flush(); assert.match(f.text(), /Searching people/);
    f.visits[0][2].onError({ nominee_q: 'Retry search.' }); f.visits[0][2].onFinish(); await flush(); assert.match(f.text(), /Retry search/);
    f.button('Next').props.onClick(); assert.equal(f.visits[1][0], '/judiciaries/court?confirmations_cursor=kept&seats_cursor=next');
    assert.deepEqual(snapshot(f.visits[1][2].only), ['vacantSeats']);
});

test('authorization vote uses the shared vote control and respects configured tally', async t => {
    const f = await fixture(t); assert.match(f.text(), /6/); f.button('Vote yes').props.onClick();
    assert.equal(f.posts[0].url, '/votes/proposal1/cast'); assert.equal(f.posts[0].data.value, 'yes');
    f.props.proposals.rows[0].vote.can_cast = false; f.props.proposals.rows[0].reason = 'The committee designation changed.'; await flush();
    assert.equal(f.button('Vote yes'), undefined); assert.match(f.text(), /designation changed/);
});
