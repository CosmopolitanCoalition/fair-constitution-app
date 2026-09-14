// Compiled Art4Section5Tracker outcome controls (IO-3). No server mutations.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const read = (file) => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const snapshot = (value) => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = { activeElement: null };

const baseChallenge = (over = {}) => ({
    id: 'ch-1',
    name: 'Challenge to Act 2026-1',
    law: { id: 'law-1', name: 'Act 2026-1', href: '/bills/b1' },
    filed_by_label: 'a resident', filed_at: '2026-09-01',
    court: { name: 'Fixture court' }, is_major: false, full_court_size: null,
    writing_judge: { name: 'Judge' },
    state: 'under_review', resolution: 'window_open',
    finding: null,
    remedy: { form_card: null, text: 'fix', timeframe_days: 30, timeframe_due_on: '2026-10-01',
        clk: 'CLK-12', veto_window_days: 30, veto_closes_on: '2026-10-01', veto_clk: 'CLK-11', tz: 'UTC' },
    override: { form_card: null, vote: null, required: null, serving: null, yes: 0, closed: false },
    bill_href: null,
    amendment_bill_new_href: '/legislatures/leg-1/bills?intro=1&targets_challenge_id=ch-1',
    remedy_diff: null,
    judicial_remedy_form_card: null,
    enforcement: null,
    ...over,
});

const NO_ONE = { isSeatedJudge: false, isLegislatureMember: false, finding: false, recommend: false, override: false, remedy: false, proposeAmendment: false };

async function fixture(t, { challenge = baseChallenge(), can = {} } = {}) {
    const posts = [];
    const page = Vue.reactive({ url: '/constitutional-challenges/ch-1', props: { flash: {}, errors: {} } });
    const props = Vue.reactive({ challenge, machine: [], fileForm: null, can: { ...NO_ONE, ...can } });

    const context = vm.createContext({ URL, console, document: globalThis.document });
    const cache = new Map();
    const file = 'Components/Judiciary/Art4Section5Tracker.vue';
    const actual = new Set([file]);
    const slots = { setup: (props, { slots }) => () => Vue.h('section', {}, [slots.default?.()]) };
    const Link = { props: ['href'], setup: (props, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: props.href }, slots.default?.()) };

    function compiled(f) {
        const { descriptor } = parse(read(f));
        return new vm.SourceTextModule(compileScript(descriptor, { id: f, inlineTemplate: true }).content, { context, identifier: f });
    }
    function dependency(name) {
        const f = name.startsWith('@/') ? name.slice(2) : name;
        if (cache.has(f)) return cache.get(f);
        let module;
        if (actual.has(f)) module = compiled(f);
        else {
            const values = name === 'vue' ? Vue
                : name === '@inertiajs/vue3' ? { Link, useForm: () => ({}), usePage: () => page,
                    router: { get: (...a) => a, post: (url, data, options) => posts.push({ url, data: snapshot(data), options }) } }
                : { default: slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); }, { context });
        }
        cache.set(f, module); return module;
    }

    const module = compiled(file); cache.set(file, module);
    await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, get options() { return this.children; }, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {} });
    const renderer = Vue.createRenderer({
        createElement: element, createText: (text) => element('#text', text), createComment: (text) => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked', 'href'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: (el) => el.parent, nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'), app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, props) });
    app.mount(root); t.after(() => app.unmount()); await flush();
    const nodes = (el = root) => [el, ...el.children.flatMap((child) => nodes(child))];
    const text = (el) => (el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' '));
    return {
        props, posts, nodes, text: () => text(root),
        button: (label) => nodes().find((el) => el.tag === 'button' && text(el).trim() === label),
        link: (label) => nodes().find((el) => el.tag === 'a' && text(el).trim() === label),
    };
}

const submit = (f, label) => {
    let el = f.button(label);
    while (el && el.tag !== 'form') el = el.parent;
    assert.ok(el, `a form encloses the "${label}" button`);
    el.props.onSubmit({ preventDefault() {} });
};

test('a seated judge under review can record the finding; it posts /finding', async (t) => {
    const f = await fixture(t, { can: { isSeatedJudge: true, finding: true } });
    const button = f.button('Record finding');
    assert.ok(button, 'the finding control renders for the seated judge');
    assert.notEqual(button.props.disabled, true, 'enabled while under review');

    submit(f, 'Record finding');
    const post = f.posts.find((p) => p.url === '/constitutional-challenges/ch-1/finding');
    assert.ok(post, 'the finding posts its own route');
    assert.equal(post.data.finds_contradiction, true, 'the yes default casts to a bool');
});

test('at finding_issued the recommend control is enabled and finding is disabled with a reason', async (t) => {
    const f = await fixture(t, {
        challenge: baseChallenge({ state: 'finding_issued' }),
        can: { isSeatedJudge: true, finding: false, recommend: true },
    });
    assert.equal(f.button('Record finding').props.disabled, true, 'finding is disabled once issued');
    assert.match(f.text(), /A finding is recorded while the challenge is under review/);
    assert.notEqual(f.button('Recommend remedy').props.disabled, true, 'recommend is enabled');

    submit(f, 'Recommend remedy');
    assert.ok(f.posts.find((p) => p.url === '/constitutional-challenges/ch-1/remedy-recommendation'));
});

test('a legislature member inside the veto window can open the override and see the amendment link', async (t) => {
    const f = await fixture(t, {
        challenge: baseChallenge({ state: 'legislative_window_open' }),
        can: { isLegislatureMember: true, override: true, proposeAmendment: true },
    });
    const button = f.button('Open override vote');
    assert.ok(button, 'the override control renders for the member');
    assert.notEqual(button.props.disabled, true, 'enabled inside the veto window');

    submit(f, 'Open override vote');
    assert.ok(f.posts.find((p) => p.url === '/constitutional-challenges/ch-1/override'));

    const link = f.link('Propose amendment bill →');
    assert.ok(link, 'the amendment link renders');
    assert.match(link.props.href, /targets_challenge_id=ch-1/, 'the link carries the challenge id');

    // A judge sees no override control on the same challenge.
    const judge = await fixture(t, { challenge: baseChallenge({ state: 'legislative_window_open' }), can: { isSeatedJudge: true } });
    assert.equal(judge.button('Open override vote'), undefined);
});

test('a seated judge applies the remedy once both windows close; disabled with a reason otherwise', async (t) => {
    const ready = await fixture(t, {
        challenge: baseChallenge({ state: 'legislative_window_open' }),
        can: { isSeatedJudge: true, remedy: true },
    });
    assert.notEqual(ready.button('Apply the remedy now').props.disabled, true);
    submit(ready, 'Apply the remedy now');
    assert.ok(ready.posts.find((p) => p.url === '/constitutional-challenges/ch-1/remedy'));

    const early = await fixture(t, {
        challenge: baseChallenge({ state: 'legislative_window_open' }),
        can: { isSeatedJudge: true, remedy: false },
    });
    assert.equal(early.button('Apply the remedy now').props.disabled, true);
    assert.match(early.text(), /both the remedy timeframe and the veto window have closed/);
});

test('a non-actor viewer sees none of the outcome controls', async (t) => {
    const f = await fixture(t, { challenge: baseChallenge({ state: 'legislative_window_open' }), can: {} });
    assert.equal(f.button('Record finding'), undefined);
    assert.equal(f.button('Recommend remedy'), undefined);
    assert.equal(f.button('Open override vote'), undefined);
    assert.equal(f.button('Apply the remedy now'), undefined);
    assert.equal(f.link('Propose amendment bill →'), undefined);
    assert.equal(f.posts.length, 0);
});

test('a closed challenge keeps the controls visible to its actors but disabled', async (t) => {
    const f = await fixture(t, {
        challenge: baseChallenge({ state: 'closed', resolution: 'applied' }),
        can: { isSeatedJudge: true, finding: false, recommend: false, remedy: false },
    });
    assert.equal(f.button('Record finding').props.disabled, true);
    assert.equal(f.button('Recommend remedy').props.disabled, true);
    assert.equal(f.button('Apply the remedy now').props.disabled, true);
});
