/* ============================================================================
   S1 · consistency — cross-surface care-and-usability sweep.

   Register row: "Care and usability across the above surfaces … check
   navigation context, usable action doors, loading/errors, exact-record
   return paths and accessible interaction. Record specific defects; route/
   source presence alone does not establish UI acceptance."

   This sweep mounts four real page components — judiciary Home, case detail,
   org detail, and an interjurisdictional door (union formation) — and drives
   their action doors through the SAME states a live server produces
   (onStart → in-flight, onError → rejection). It asserts each surface honours
   the shared usability conventions:

     · loading    — an in-flight door shows a role="status" busy indicator or
                    an aria-busy form, and disables its own submit;
     · error      — a rejected door shows a role="alert" message that ends in a
                    "retry" affordance, OR routes a server citation to a visible
                    role="alert"/emergency banner;
     · disabled   — a guard renders its control DISABLED, not hidden, using the
                    accessible mechanism for its element (native `disabled` for a
                    <button>, aria-disabled for a non-button control);
     · return     — the surface links back to the exact records it references
                    (docket, chamber, jurisdiction, the appealed original).

   The a11y atoms (Banner, Btn, Field) are COMPILED from source, not stubbed,
   so the accessible-interaction assertions run against the real component code.

   Run:  node --experimental-vm-modules --test tests/js/consistencySweep.test.mjs
   ============================================================================ */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const read = (file) => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const snapshot = (value) => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = globalThis.document || { activeElement: null };

// The real a11y atoms every surface leans on. Compiled from source so the
// role/disabled wiring under test is the shipped wiring, not a stub's. Field is
// intentionally NOT compiled: its textarea/input `v-model` needs a real DOM the
// minimal renderer does not provide. Field's own aria wiring is pinned by the
// per-surface tests; this sweep asserts the page-level conventions.
const REAL_ATOMS = ['Components/Ui/Banner.vue', 'Components/Ui/Btn.vue'];

// A submission record readable either positionally ([url, data, options], the
// per-surface tests' shape) or by name (.url/.data/.options), so this sweep
// stays neutral to which the caller reaches for.
const rec = (url, data, options, form) => Object.assign([url, data, options], { url, data, options, form });

function makeForm(posts) {
    // Inertia useForm() stand-in: reactive fields + errors/processing +
    // post/patch/reset/transform, recording each submission's options.
    return (initial) => {
        const form = Vue.reactive({
            ...initial, errors: {}, processing: false,
            transform(fn) { this._transform = fn; return this; },
            reset(...keys) { for (const k of (keys.length ? keys : Object.keys(initial))) this[k] = initial[k]; },
            post(url, options = {}) { const data = {}; for (const k of Object.keys(initial)) data[k] = this[k]; posts.push(rec(url, snapshot(data), options, form)); },
            patch(url, options = {}) { const data = {}; for (const k of Object.keys(initial)) data[k] = this[k]; posts.push(rec(url, snapshot(data), options, form)); },
        });
        return form;
    };
}

async function mount(t, pageFile, props, realExtra = []) {
    const visits = [], posts = [];
    const page = Vue.reactive({ url: '/x', props: { flash: {}, errors: {} } });
    const reactiveProps = Vue.reactive(props);
    const context = vm.createContext({ URL, console, Date, document: globalThis.document });
    const cache = new Map();
    const actual = new Set([pageFile, ...REAL_ATOMS, ...realExtra]);
    const generic = { setup: (p, { slots, attrs }) => () => Vue.h('section', attrs, [slots.title?.(), slots.default?.()]) };
    const Icon = { props: ['name', 'size'], setup: (p) => () => Vue.h('i', { 'data-icon': p.name }) };
    const Link = { props: ['href'], setup: (p, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: p.href }, slots.default?.()) };
    const Head = { setup: (p, { slots }) => () => Vue.h('head-stub', {}, slots.default?.()) };
    const Pager = { props: ['pages', 'only', 'first', 'label', 'cursorKey'], setup: (p) => () => Vue.h('nav', { 'data-cursor-key': p.cursorKey }, `pager:${p.cursorKey}`) };
    const useForm = makeForm(posts);

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
            const values = name === 'vue' ? Vue
                : name === '@inertiajs/vue3' ? { Link, Head, useForm, usePage: () => page,
                    router: { get: (...a) => visits.push(a), post: (url, data, options) => posts.push(rec(url, snapshot(data), options)) } }
                : name === 'vue-i18n' ? { useI18n: () => ({ t: (key, arg, fallback) => (typeof arg === 'string' ? arg : fallback ?? key), n: (v) => String(v) }) }
                : file === 'lib/plain.js' ? { plainState: (s) => String(s) }
                : file === 'Components/Ui/HistoryPager.vue' ? { default: Pager }
                : file === 'Components/Ui/Icon.vue' ? { default: Icon }
                : file === 'composables/useDemoMode' ? { useDemoMode: () => ({ isDemoMode: false }) }
                : { default: generic };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); }, { context });
        }
        cache.set(file, module); return module;
    }

    const module = compiled(pageFile); cache.set(pageFile, module);
    await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, style: {}, querySelector: () => null, addEventListener() {}, removeEventListener() {} });
    const renderer = Vue.createRenderer({
        createElement: element, createText: (t2) => element('#text', t2), createComment: (t2) => element('#comment', t2),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, t2) => { el.text = t2; }, setElementText: (el, t2) => { el.text = t2; el.children = []; },
        parentNode: (el) => el.parent, nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'), app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, reactiveProps) });
    app.mount(root); t.after(() => app.unmount()); await flush();
    const nodes = (el = root) => [el, ...el.children.flatMap((c) => nodes(c))];
    const textOf = (el) => (el.tag === '#comment' ? '' : [el.text, ...el.children.map(textOf)].join(' '));
    return {
        props: reactiveProps, page, posts, visits, nodes, text: () => textOf(root),
        button: (label) => nodes().find((el) => el.tag === 'button' && textOf(el).trim() === label),
        role: (r) => nodes().filter((el) => el.props.role === r),
        roleText: (r) => nodes().filter((el) => el.props.role === r).map(textOf).join(' '),
        links: () => nodes().filter((el) => el.tag === 'a' && el.props.href).map((el) => el.props.href),
        anyBusyForm: () => nodes().some((el) => el.tag === 'form' && el.props['aria-busy'] === true),
    };
}

const clickPost = (f, label, urlPart) => {
    const b = f.button(label);
    assert.ok(b, `door "${label}" renders`);
    if (b.props.onClick) {
        b.props.onClick();
    } else {
        let el = b; while (el && !(el.tag === 'form' && el.props.onSubmit)) el = el.parent;
        assert.ok(el, `"${label}" sits in a submitting form`);
        el.props.onSubmit({ preventDefault() {} });
    }
    return f.posts.find((p) => p.url.includes(urlPart));
};

/* ── prop fixtures (mirrors of the per-surface tests) ─────────────────────── */

const homeProps = () => ({
    surface: { title: 'Judiciary', module: 'judicial', forms: [] },
    judiciary: {
        id: 'court', name: 'Civic court', type: 'appointed', status: 'appointed',
        judges_on_bench: 5, min_judges_per_race: 5,
        jurisdiction: { id: 'j', name: 'Home', href: '/jurisdictions/home' },
        legislature: { id: 'l', name: 'Chamber', chamber_href: '/legislature' },
    },
});

const caseProps = (over = {}) => ({
    surface: { title: 'Case detail', forms: [{ id: 'F-IND-027', availableTo: ['R-03', 'R-21'], citation: 'Art. II §8 — appeal filing' }] },
    case: {
        id: 'case-1', judiciary_id: 'court-1', docket_no: 'case-2026-001', title: 'State v. Fixture',
        kind: 'Criminal', kind_raw: 'criminal', severity: 'Serious', court: { name: 'Civic court' },
        double_jeopardy: false, jury_entitled: false, current_stage: 9, current_state: 'decided',
        is_appeal: false, en_banc: false, appeal_of: null, appeals: [], appeal_outcomes: [],
    },
    machine: [], stages: [], stageStateMap: [],
    panel: { seats: [], severity: 'serious', panelSize: 3, isFullCourt: false, rule: 'x' },
    motions: [], evidence: [], jury: null,
    can: { orderCourt: false, appeal: true, record_appeal_outcome: false },
    ...over,
});

const orgProps = (over = {}) => ({
    surface: { forms: [{ id: 'F-ORG-001', name: 'Organization Profile Management', alias: null }] },
    organization: { id: 'org1', name: 'Guild', status: 'active', purpose: '', agent: { name: 'Agent One', is_viewer: true } },
    machine: [],
    ownership: { structure: 'member_owned', isCgc: false, stakes: [], memberCounts: {}, structureHistory: [] },
    board: null, endorsements: { incoming: [], granted: [], total: 0 },
    documents: [], contracts: [], myMembership: null, myWorker: null, jobs: [],
    pendingMembers: { rows: [{ id: 'm1', user: { id: 'u20', name: 'Applicant', profile_href: '/people?who=u20', public_handle: null }, kind: 'member', applied_at: '2026-09-13T00:00:00Z' }], pages: { previous: null, next: null, first: '/organizations/org1' } },
    agentSearch: null,
    can: { manage: true, join: false, registerWorker: false, cosign: false, steerEconomy: false },
    ...over,
});

const unionProps = (over = {}) => ({
    surface: { title: 'Union formation', forms: [] },
    processes: [{
        id: 'u1', kind: 'formation', status: 'open', applicants: [{ id: 'a', name: 'A' }],
        union_name: null, resulting_name: null, applicant_supermajority_met: false,
        compatibility_diff: {}, consentable_by_viewer: true,
        constituent_vote: { yes: 0, no: 0, required: 2, total: 3, status: 'open', consents: [] },
        opened_at: '2026-09-13T00:00:00Z',
    }],
    door: { seat: { jurisdiction_id: 'a', jurisdiction_name: 'A', legislature_id: 'l', has_parent: true, child_count: 0 }, siblings: [] },
    pagination: { previous: null, next: '/jurisdictions/union-formation?union_cursor=X', first: '/jurisdictions/union-formation' },
    ...over,
});

/* ── JUDICIARY HOME — navigation context, exact-record return, error banner ─ */

test('judiciary Home: links back to the exact records it names (docket, challenges, jurisdiction, chamber)', async (t) => {
    const f = await mount(t, 'Pages/Judiciary/Home.vue', homeProps());
    const hrefs = f.links();
    assert.ok(hrefs.includes('/judiciary/docket'), 'the docket return link renders');
    assert.ok(hrefs.includes('/judiciary/challenges'), 'the challenges return link renders');
    assert.ok(hrefs.includes('/jurisdictions/home'), 'the seat jurisdiction return link renders');
    assert.ok(hrefs.includes('/legislature'), 'the creating-chamber return link renders');
});

test('judiciary Home: a server rejection surfaces on an accessible emergency banner (role=alert)', async (t) => {
    const f = await mount(t, 'Pages/Judiciary/Home.vue', homeProps());
    assert.equal(f.role('alert').length, 0, 'no alert banner in the clean state');
    f.page.props.errors = { constitution: 'The court is already appointed.' };
    await flush();
    assert.match(f.roleText('alert'), /already appointed/, 'the constitution error renders on a role="alert" banner');
});

/* ── CASE DETAIL — in-flight (aria-busy + Filing…), guard-disabled, return ── */

test('case detail: the appeal door shows an in-flight state and disables its own submit', async (t) => {
    const f = await mount(t, 'Pages/Judiciary/CaseDetail.vue', caseProps());
    const button = f.button('Appeal this judgement');
    assert.ok(button, 'the appeal door renders on a decided original');
    // A party with empty grounds: the door is DISABLED, not hidden (accessible guard).
    assert.equal(button.props.disabled, true, 'the appeal submit is disabled while grounds are empty');
    // Submit once to capture the appeal form model, then drive the in-flight
    // state the server produces (processing → true).
    let el = button; while (el && !(el.tag === 'form' && el.props.onSubmit)) el = el.parent;
    assert.ok(el, 'the appeal submit sits in a form');
    el.props.onSubmit({ preventDefault() {} });
    const post = f.posts.find((p) => p.url === '/cases/case-1/appeals');
    assert.ok(post && post.form, 'the appeal door posts its own exact route via a form model');
    post.form.grounds = 'A proven contradiction in law.';
    post.form.processing = true;
    await flush();
    assert.ok(f.button('Filing…'), 'the door relabels to a busy state while in flight');
    assert.equal(f.button('Filing…').props.disabled, true, 'the busy door stays disabled while in flight');
    assert.ok(f.anyBusyForm(), 'the appeal form marks itself aria-busy while in flight');
    post.form.processing = false; await flush();
});

test('case detail: a non-party sees the appeal door disabled with a status reason, never removed', async (t) => {
    const f = await mount(t, 'Pages/Judiciary/CaseDetail.vue', caseProps({ can: { orderCourt: false, appeal: false, record_appeal_outcome: false } }));
    const button = f.button('Appeal this judgement');
    assert.ok(button, 'the door is present for the non-party (disabled, not hidden)');
    assert.equal(button.props.disabled, true, 'the door is disabled for a non-party');
    assert.match(f.text(), /Only a party to this case may appeal/, 'the disabled door explains why');
});

test('case detail: a server rejection surfaces on an accessible banner (role=alert)', async (t) => {
    const f = await mount(t, 'Pages/Judiciary/CaseDetail.vue', caseProps());
    f.page.props.errors = { constitution: 'Double jeopardy bars a second filing.' };
    await flush();
    assert.match(f.roleText('alert'), /Double jeopardy/, 'the court rejection renders on a role="alert" banner');
});

/* ── ORG DETAIL — in-flight (Working…), error→retry, return links ─────────── */

test('org detail: a rejected action door shows a role="alert" retry message; its in-flight state shows a busy label', async (t) => {
    const f = await mount(t, 'Pages/Organizations/OrgDetail.vue', orgProps());
    const accept = f.button('Accept');
    assert.ok(accept, 'the membership decision door renders for the agent');
    accept.props.onClick();
    const post = f.posts[0];
    assert.equal(post[0], '/organizations/org1/memberships/m1/decision', 'the door posts its own exact route');
    const opts = post[2];
    opts.onStart(); await flush();
    assert.ok(f.button('Working…'), 'the door shows an in-flight busy label');
    // Reject with no field detail → the default retry message on a role="alert".
    opts.onError({}); opts.onFinish(); await flush();
    assert.match(f.roleText('alert'), /retry/i, 'a rejection with no detail shows a retry affordance');
});

test('org detail: a server citation on a rejected door passes through verbatim to role="alert"', async (t) => {
    const f = await mount(t, 'Pages/Organizations/OrgDetail.vue', orgProps());
    f.button('Accept').props.onClick();
    const opts = f.posts[0][2];
    opts.onError({ constitution: 'Membership is not pending.' }); opts.onFinish(); await flush();
    assert.match(f.roleText('alert'), /not pending/, 'the server citation reaches the viewer');
});

test('org detail: a non-manager sees the decision doors withheld (absent), never dangling', async (t) => {
    const f = await mount(t, 'Pages/Organizations/OrgDetail.vue', orgProps({ can: { manage: false, join: false, registerWorker: false, cosign: false, steerEconomy: false } }));
    assert.equal(f.button('Accept'), undefined, 'no decision door for a non-manager');
    assert.equal(f.button('Decline'), undefined);
});

/* ── UNION FORMATION — in-flight (Working…), error→retry, guard-disabled ──── */

test('union formation: the finalize door reports its in-flight state and a retry-bearing error', async (t) => {
    const f = await mount(t, 'Pages/Jurisdictions/UnionFormation.vue', unionProps());
    const post = clickPost(f, 'Finalize the union', '/jurisdictions/union-formation/u1/finalize');
    assert.ok(post, 'the finalize door posts its own exact route');
    post.options.onStart(); await flush();
    assert.match(f.roleText('status'), /Working/, 'the door announces the in-flight state on role="status"');
    post.options.onError({}); post.options.onFinish(); await flush();
    assert.match(f.roleText('alert'), /retry/i, 'a rejection shows a retry affordance on role="alert"');
});

test('union formation: no seat → the write doors are withheld (absent, not merely disabled)', async (t) => {
    const f = await mount(t, 'Pages/Jurisdictions/UnionFormation.vue', unionProps({ door: { seat: null, siblings: [] } }));
    assert.equal(f.button('Finalize the union'), undefined, 'the finalize door is absent without a seat');
});

/* ── ACCESSIBLE-INTERACTION ATOM — native disabled vs aria-disabled ──────── */

test('a11y atom: Btn uses native disabled for a <button> and aria-disabled for a non-button control', async (t) => {
    // A native-button door reports the constraint through the native `disabled`
    // property (union/case/org doors above all rely on this).
    const asButton = await mount(t, 'Components/Ui/Btn.vue', { disabled: true });
    const nb = asButton.nodes().find((el) => el.tag === 'button');
    assert.ok(nb, 'Btn renders a <button> by default');
    assert.equal(nb.props.disabled, true, 'a disabled <button> carries the native disabled property');
    assert.equal(nb.props['aria-disabled'], undefined, 'a native button does not double up with aria-disabled');

    // A non-button door (Btn as an anchor) cannot use native disabled, so it
    // MUST signal the constraint with aria-disabled to stay accessible.
    const asLink = await mount(t, 'Components/Ui/Btn.vue', { as: 'a', disabled: true });
    const link = asLink.nodes().find((el) => el.tag === 'a');
    assert.ok(link, 'Btn renders an <a> when as="a"');
    assert.equal(link.props['aria-disabled'], 'true', 'a disabled non-button control carries aria-disabled="true"');
});
