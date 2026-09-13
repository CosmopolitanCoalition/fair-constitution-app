import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

/* EO-5 — the individual endorsement + withdrawal controls on the person
   profile candidacy tab. Compiled-Vue test (no jsdom): the real
   PersonProfile.vue script runs, the template renders into a minimal custom
   renderer, and the Inertia router is stubbed to record post() calls so the
   exact routes and the public flag are asserted. */

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };

async function fixture(t, { isOwner = false, signedIn = true, can = {}, viewerEndorsement = null } = {}) {
    const visits = [], posts = [];
    const page = Vue.reactive({ url: '/people?who=person&tab=candidacy&candidacy=race', props: { auth: { user: signedIn ? { id: 'viewer' } : null } } });
    const directory = kind => ({ rows: [], pages: { next: null, first: '/people?who=person&tab=candidacy&candidacy=race' }, notice: null });
    const props = Vue.reactive({ surface: {}, person: { id: 'person', display: 'River' }, tabs: ['overview', 'record', 'candidacy'],
        tab: 'candidacy', isSelf: false, editable: null, record: { actions: [], associations: [], endorsementsGiven: [] },
        candidacies: [{ id: 'race', race_label: 'Public place', status: 'validated' }],
        candidacyPanel: { candidacy: { id: 'race', name: 'River', statement: '', position_tags: [], race: null },
            isOwner, machine: [], currentState: 'validated', standing: null, can, viewerEndorsement, organizations: [] },
        endorsementOrganizations: { ...directory('orgs'), rows: [] },
        endorsementIndividuals: { ...directory('people'), counts: { total: 0, public: 0, private: 0 }, rows: [] },
        offices: [], achievements: [] });
    const context = vm.createContext({ URL, console, document: { activeElement: null } });
    const cache = new Map(), real = new Set(['Pages/Social/PersonProfile.vue', 'Components/Social/OfficeHistory.vue', 'Components/Ui/HistoryPager.vue', 'Components/Social/CandidacyEndorsements.vue']);
    const generic = { setup: (props, { slots, attrs }) => () => Vue.h('section', attrs, [slots.title?.(), slots.default?.()]) };
    const Link = { props: ['href'], setup: (props, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: props.href }, slots.default?.()) };
    const Btn = { props: ['disabled', 'pressed'], setup: (props, { slots, attrs }) => () => Vue.h('button', { ...attrs, disabled: props.disabled, 'aria-pressed': props.pressed === undefined ? undefined : String(props.pressed) }, slots.default?.()) };
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
                    defaults(next) { defaults = copy(next); }, reset() { Object.assign(form, defaults); }, post() {}, patch() {} }); return form; } }
                : name === 'vue-i18n' ? { useI18n: () => ({ t: key => key }) }
                : file === 'lib/achievementTitle' ? { achievementTitle: title => title }
                : { default: file.endsWith('/Btn.vue') ? Btn : generic };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(file, module); return module;
    }
    const file = 'Pages/Social/PersonProfile.vue', module = compile(file); cache.set(file, module); await module.link(dependency); await module.evaluate();
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

test('a resident non-owner can endorse privately (default) via the exact route', async t => {
    const f = await fixture(t, { can: { endorse: true } });
    const endorse = f.button('Endorse this candidate');
    assert.ok(endorse, 'the endorse control renders');
    endorse.props.onClick();
    assert.equal(f.posts.length, 1);
    assert.equal(f.posts[0][0], '/candidates/race/endorsement');
    assert.deepEqual(copy(f.posts[0][1]), { is_public: false });
});

test('the public/private toggle sets the public flag on the endorsement post', async t => {
    const f = await fixture(t, { can: { endorse: true } });
    f.button('Private endorsement').props.onClick(); await flush();
    assert.ok(f.button('Public endorsement'), 'the toggle flips its label to public');
    f.button('Endorse this candidate').props.onClick();
    assert.deepEqual(copy(f.posts[0][1]), { is_public: true });
    // Loading, error and success states render off the router callbacks.
    const opts = f.posts[0][2];
    opts.onStart(); await flush(); assert.equal(f.button('Filing F-IND-025…')?.props.disabled, true);
    opts.onError({ constitution: 'Endorsing requires association in this race.' }); opts.onFinish(); await flush();
    assert.match(f.text(), /requires association/);
    opts.onSuccess(); await flush(); assert.match(f.text(), /Public endorsement recorded/);
});

test('a current endorser sees a withdraw control that posts the withdraw route', async t => {
    const f = await fixture(t, { can: { withdraw_endorsement: true }, viewerEndorsement: { is_public: true, withdrawn: false, endorsed_at: '2026-09-13T00:00:00Z' } });
    assert.match(f.text(), /You endorse this candidate/);
    assert.match(f.text(), /public on the record/);
    const withdraw = f.button('Withdraw my endorsement');
    assert.ok(withdraw);
    withdraw.props.onClick();
    assert.equal(f.posts[0][0], '/candidates/race/endorsement/withdraw');
    assert.deepEqual(copy(f.posts[0][1]), {});
    f.posts[0][2].onSuccess(); await flush(); assert.match(f.text(), /Endorsement withdrawn/);
});

test('the candidate (owner) sees no endorsement control', async t => {
    const f = await fixture(t, { isOwner: true, can: {} });
    assert.equal(f.button('Endorse this candidate'), undefined);
    assert.equal(f.button('Withdraw my endorsement'), undefined);
    assert.doesNotMatch(f.text(), /Your endorsement/);
});

test('a signed-out viewer gets a read-only preview, no control', async t => {
    const f = await fixture(t, { signedIn: false, can: {} });
    assert.match(f.text(), /Sign in to add yours/);
    assert.equal(f.button('Endorse this candidate'), undefined);
    assert.equal(f.posts.length, 0);
});
