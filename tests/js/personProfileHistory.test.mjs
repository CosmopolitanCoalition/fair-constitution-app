import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };

async function fixture(t) {
    const visits = [], forms = [];
    const page = Vue.reactive({ url: '/people?who=person&tab=record&candidacy=race&profile_actions_cursor=old', props: { auth: { user: { id: 'person' } } } });
    const history = kind => ({ rows: [], pages: { next: '/people?who=person&tab=record&profile_' + kind + '_cursor=next', first: '/people?who=person' }, notice: null });
    const props = Vue.reactive({ surface: {}, person: { id: 'person', display: 'River' }, tabs: ['overview', 'record', 'candidacy', 'office'],
        tab: 'record', isSelf: true, editable: {}, record: { actions: [], associations: [], endorsementsGiven: [] },
        actionHistory: history('actions'), publications: history('publications'), officeHistory: history('offices'),
        candidacies: [{ id: 'race', race_label: 'Public place', status: 'elected' }], candidacyPanel: null, offices: [], achievements: [] });
    props.actionHistory.rows = [{ seq: '9007199254740993', label: 'Past public act', date: '2020-01-01', href: '/system/audit-chain?seq=9007199254740993' }];
    props.publications.rows = [{ id: 'document', seq: '42', title: 'Published decision', kind: 'opinion', date: '2020-01-01', body: 'Full public text' }];
    props.officeHistory.rows = [{ record_key: 'term:old', title: 'Judge', jurisdiction: 'Public place', status: 'removed', starts: '2010-01-01', scheduled_end: '2020-01-01', left_on: '2015-01-01', href: '/judiciaries/court' }];
    const context = vm.createContext({ URL, console, document: { activeElement: null } });
    const cache = new Map(), real = new Set(['Pages/Social/PersonProfile.vue', 'Components/Social/OfficeHistory.vue', 'Components/Ui/HistoryPager.vue', 'Components/Social/CandidacyEndorsements.vue']);
    const generic = { setup: (props, { slots, attrs }) => () => Vue.h('section', attrs, [slots.title?.(), slots.default?.()]) };
    const Link = { props: ['href'], setup: (props, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: props.href }, slots.default?.()) };
    const Btn = { setup: (props, { slots, attrs }) => () => Vue.h('button', attrs, slots.default?.()) };
    function compile(file) { const { descriptor } = parse(read(file)); return new vm.SourceTextModule(compileScript(descriptor, { id: file, inlineTemplate: true }).content, { context, identifier: file }); }
    function dependency(name, parent) {
        const file = name.startsWith('@/') ? name.slice(2) : name.startsWith('.') ? new URL(name, 'file:///' + parent.identifier).pathname.slice(1) : name;
        if (cache.has(file)) return cache.get(file);
        let module;
        if (real.has(file)) module = compile(file);
        else {
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? { Link, usePage: () => page, router: { get: (...args) => visits.push(args) },
                useForm: data => { let defaults = copy(data); const form = Vue.reactive({ ...data, processing: false, errors: {},
                    defaults(next) { defaults = copy(next); }, reset() { Object.assign(form, defaults); }, post() {}, patch() {} }); forms.push(form); return form; } }
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
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    return { props, page, forms, visits, nodes, text: () => text(root), button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label),
        next: label => nodes().find(el => el.tag === 'nav' && el.props['aria-label'] === label)?.children.find(el => el.tag === 'button' && text(el).trim() === 'Next') };
}

test('the one profile renders old activity, full publications and former office holders with correct date labels', async t => {
    const f = await fixture(t);
    assert.match(f.text(), /Past public act/); assert.match(f.text(), /Full public text/); assert.match(f.text(), /Office history/);
    assert.match(f.text(), /Removed/); assert.match(f.text(), /Scheduled term end 2020-01-01/); assert.match(f.text(), /Left office 2015-01-01/);
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/judiciaries/court'));
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/system/audit-chain?seq=9007199254740993'));
    assert.doesNotMatch(f.text(), /Elected by every resident.*through STV/);
});

test('independent profile pages retain the person, candidacy, active tab and other history positions', async t => {
    const f = await fixture(t);
    f.page.url = '/people?who=person&tab=record&candidacy=race&profile_publications_cursor=retained';
    f.next('Public activity pages').props.onClick();
    assert.equal(f.visits[0][0], f.page.url + '&profile_actions_cursor=next');
    assert.deepEqual(copy(f.visits[0][2].only), ['actionHistory']);
    const opts = f.visits[0][2]; opts.onStart(); await flush(); assert.match(f.text(), /Loading records/);
    opts.onError({ profile_actions_cursor: 'Try again.' }); opts.onFinish(); await flush(); assert.match(f.text(), /Try again/);
    f.button('First page').props.onClick(); assert.equal(f.visits[1][0], f.page.url);
    f.next('Office history pages').props.onClick(); assert.deepEqual(copy(f.visits[2][2].only), ['officeHistory']);
});

test('candidacy opens within the same profile and initializes its existing statement without losing edits on other history visits', async t => {
    const f = await fixture(t);
    f.button('Candidacy').props.onClick();
    const [url, , opts] = f.visits[0]; assert.match(url, /^\/people\?who=person&tab=candidacy/); assert.match(url, /candidacy=race/);
    assert.deepEqual(copy(opts.only), ['tab', 'candidacyPanel', 'endorsementOrganizations', 'endorsementIndividuals', 'endorsementWeb', 'endorsementRequests']);
    opts.onStart(); await flush(); assert.match(f.text(), /Loading profile section/);
    opts.onError({ candidacy: 'Please retry the candidacy section.' }); opts.onFinish(); await flush(); assert.match(f.text(), /Please retry/);
    f.props.candidacyPanel = { candidacy: { id: 'race', name: 'River', statement: 'Existing public statement', position_tags: [], race: null },
        isOwner: false, machine: [], currentState: 'elected', endorsements: { orgs: [], individual: { total: 0 }, publicWeb: [] }, requests: [], can: {} };
    await flush();
    const form = f.forms.find(form => 'platform_statement' in form); assert.equal(form.platform_statement, 'Existing public statement');
    form.platform_statement = 'Unsaved change'; f.props.actionHistory = { ...f.props.actionHistory, rows: [] }; await flush(); assert.equal(form.platform_statement, 'Unsaved change');
    assert.match(f.text(), /Candidacy record for River/);
});


test('endorsement pages and selected connections keep the same profile, independent cursors and unsaved statement', async t => {
    const f = await fixture(t);
    const directory = kind => ({ rows: [], pages: { next: '/people?who=person&tab=candidacy&candidacy=race&endorsement_' + kind + '_cursor=next', first: '/people?who=person&tab=candidacy&candidacy=race' } });
    f.page.url = '/people?who=person&tab=candidacy&candidacy=race&endorsement_orgs_cursor=org-page&profile_actions_cursor=saved';
    f.props.tab = 'candidacy';
    f.props.candidacyPanel = { candidacy: { id: 'race', name: 'River', statement: 'Existing statement', position_tags: [], race: null }, isOwner: false, machine: [], currentState: 'elected', can: {} };
    f.props.endorsementOrganizations = { ...directory('orgs'), rows: [{ id: 'org', name: 'Public organization', href: '/organizations/org' }] };
    f.props.endorsementIndividuals = { ...directory('people'), counts: { total: 22, public: 21, private: 1 }, rows: [{ user_id: 'endorser', name: 'Public supporter', alsoCandidate: true }] };
    await flush();
    const form = f.forms.find(form => 'platform_statement' in form); form.platform_statement = 'Unsaved statement';
    assert.match(f.text(), /Public organization/); assert.match(f.text(), /Public supporter/); assert.match(f.text(), /21 public \/ 1 private/);
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/people?who=endorser'));
    assert.equal(f.visits.length, 0, 'connections must not load eagerly');
    const expand = f.nodes().find(el => el.tag === 'button' && el.props['aria-controls'] === 'public-endorsement-connections');
    expand.props.onClick();
    assert.match(f.visits[0][0], /public_endorser=endorser/); assert.match(f.visits[0][0], /endorsement_orgs_cursor=org-page/);
    assert.deepEqual(copy(f.visits[0][2].only), ['endorsementWeb']);
    f.visits[0][2].onStart(); await flush(); assert.match(f.text(), /Loading public connections/); assert.equal(expand.props.disabled, true);
    f.visits[0][2].onError({ web: 'Connection unavailable, retry.' }); f.visits[0][2].onFinish(); await flush(); assert.match(f.text(), /Connection unavailable/);
    f.props.endorsementWeb = { ...directory('web'), endorser: { user_id: 'endorser', name: 'Public supporter' }, rows: [{ candidacy_id: 'other-race', user_id: 'other-person', name: 'Other candidate' }] };
    f.page.url = f.visits[0][0]; await flush();
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/people?who=other-person&tab=candidacy&candidacy=other-race'));
    assert.equal(expand.props['aria-expanded'], true);
    f.next('Public connection pages').props.onClick();
    assert.match(f.visits[1][0], /public_endorser=endorser/); assert.match(f.visits[1][0], /endorsement_web_cursor=next/);
    assert.deepEqual(copy(f.visits[1][2].only), ['endorsementWeb']);
    f.next('Individual endorsement pages').props.onClick();
    assert.deepEqual(copy(f.visits[2][2].only), ['endorsementIndividuals']);
    assert.match(f.visits[2][0], /endorsement_orgs_cursor=org-page/);
    assert.equal(form.platform_statement, 'Unsaved statement');
    f.button('Close connections').props.onClick(); assert.doesNotMatch(f.visits[3][0], /public_endorser=/);
});
