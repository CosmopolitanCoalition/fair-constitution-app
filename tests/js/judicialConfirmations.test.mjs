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
    const visits = [], posts = [], page = Vue.reactive({ url: '/judiciaries/court?nominee_q=Name&confirmations_cursor=old#judicial-confirmations' });
    const row = n => ({ id: 'nomination-' + n, seat_number: n, status: 'nominated', nominated_by: 'Home constituent', dossier: 'Public qualifications',
        nominee: { id: 'person-' + n, name: 'Same Name', public_handle: n === 1 ? '@public' : null },
        consent: { can_cast: true, cast_url: '/votes/vote-' + n + '/cast', can_tiebreak: false, tiebreak_url: '/votes/vote-' + n + '/tiebreak', my_cast: null,
            tally: { status: 'open', mode: 'unicameral', stage: 'floor', thresholdClass: 'majority', serving: 3, requiredYes: 2, tallies: { yes: 0, no: 0, abstain: 0 }, outcome: 'pending' } } });
    const props = Vue.reactive({ judiciary: { id: 'court' }, nominations: [row(1), row(2)], pages: { next: '/judiciaries/court?confirmations_cursor=next', first: '/judiciaries/court' },
        context: { preview: false, actor_name: 'Civic Member', legislature_href: '/legislatures/source/chamber' } });
    const context = vm.createContext({ URL, console, document: globalThis.document });
    const cache = new Map();
    const actual = new Set(['Components/Judiciary/JudicialConfirmations.vue', 'Components/Legislature/ConsentVoteCard.vue',
        'Components/Legislature/VoteTally.vue', 'Components/Ui/SelectionIdentity.vue', 'Components/Ui/HistoryPager.vue']);
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
    const file = 'Components/Judiciary/JudicialConfirmations.vue', module = compiled(file); cache.set(file, module);
    await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {} });
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
    return { props, row, visits, posts, page, nodes, text: () => text(root), button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label) };
}

test('court confirmation workspace renders public identity, nomination context and exact existing vote actions', async t => {
    const f = await fixture(t);
    assert.match(f.text(), /Participating as Civic Member/); assert.match(f.text(), /Home constituent/); assert.match(f.text(), /Public qualifications/);
    for (const n of [1, 2]) assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/people?who=person-' + n));
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/legislatures/source/chamber'));
    f.button('Vote yes').props.onClick(); assert.equal(f.posts[0].url, '/votes/vote-1/cast'); assert.equal(f.posts[0].data.value, 'yes');
    f.posts[0].options.onStart(); await flush(); assert.match(f.text(), /Submitting your vote/);
    f.posts[0].options.onError({ constitution: 'The nomination was replaced.' }); f.posts[0].options.onFinish(); await flush();
    assert.match(f.text(), /nomination was replaced/); f.button('Vote no').props.onClick(); assert.equal(f.posts[1].url, '/votes/vote-1/cast');
    f.posts[1].options.onSuccess(); f.posts[1].options.onFinish(); await flush(); assert.match(f.text(), /Your vote has been recorded/);
});

test('independent history paging preserves current selection, reports failures and exposes retry', async t => {
    const f = await fixture(t); f.page.url = '/judiciaries/court?nominee_q=Retained&nominee_cursor=newer#judicial-confirmations';
    f.button('Next').props.onClick();
    assert.equal(f.visits[0][0], '/judiciaries/court?nominee_q=Retained&nominee_cursor=newer&confirmations_cursor=next#judicial-confirmations');
    assert.deepEqual(snapshot(f.visits[0][2].only), ['nominations', 'confirmationPages', 'confirmationContext']);
    const options = f.visits[0][2]; options.onStart(); await flush(); assert.match(f.text(), /Loading records/);
    f.button('Next').props.onClick(); assert.equal(f.visits.length, 1);
    options.onError({ confirmations_cursor: 'Return to the first page.' }); options.onFinish(); await flush();
    assert.match(f.text(), /Return to the first page/); f.button('First page').props.onClick();
    assert.equal(f.visits[1][0], f.page.url);
});

test('public role preview, closed slate and unavailable records have no ordinary vote buttons', async t => {
    const f = await fixture(t); f.props.context = { preview: true };
    f.props.nominations = [{ ...f.row(1), consent: null, consent_notice: 'The linked record does not match this nomination.' },
        { ...f.row(2), status: 'consented', consent: { ...f.row(2).consent, can_cast: false,
            tally: { ...f.row(2).consent.tally, status: 'closed', outcome: 'adopted' }, read_only_reason: 'The recorded bench slate was confirmed together.' } }];
    await flush(); assert.match(f.text(), /Role preview/); assert.match(f.text(), /does not match/); assert.match(f.text(), /bench slate/);
    assert.equal(f.button('Vote yes'), undefined); assert.equal(f.posts.length, 0);
    f.props.nominations = []; await flush(); assert.match(f.text(), /No judicial nominations/);
});

test('both Speaker choices use exact existing tie endpoint and preserve retry explanation', async t => {
    const f = await fixture(t); f.props.context.is_speaker = true;
    f.props.nominations = [f.row(1)]; f.props.nominations[0].consent = { ...f.row(1).consent, can_cast: false, can_tiebreak: true,
        tally: { ...f.row(1).consent.tally, status: 'closed', outcome: 'tied' } }; await flush();
    f.nodes().find(el => el.tag === 'textarea').props['onUpdate:modelValue']('Tie decision reasons');
    f.button('Break tie: yes').props.onClick(); assert.equal(f.posts[0].url, '/votes/vote-1/tiebreak'); assert.equal(f.posts[0].data.value, 'yes');
    f.posts[0].options.onError({ constitution: 'Please retry.' }); f.posts[0].options.onFinish(); await flush();
    f.button('Break tie: no').props.onClick(); assert.deepEqual(f.posts[1].data, { value: 'no', explanation: 'Tie decision reasons' });
    f.posts[1].options.onSuccess(); f.posts[1].options.onFinish(); await flush(); assert.equal(f.button('Break tie: yes'), undefined);
});

test('Judiciary Home compiles and connects confirmation component and exact-source institutional action links', () => {
    const source = read('Pages/Judiciary/Home.vue'); const { descriptor } = parse(source);
    assert.doesNotThrow(() => compileScript(descriptor, { id: 'court-home', inlineTemplate: true }));
    assert.match(source, /<JudicialConfirmations :judiciary="judiciary" :nominations="nominations"/);
    assert.match(source, /institution-acts\?action=create-court/); assert.match(source, /institution-acts\?action=elect-court/);
    assert.doesNotMatch(source, /subject=judiciary_creation|Confirmation needs the same threshold the creation act met/);
});
