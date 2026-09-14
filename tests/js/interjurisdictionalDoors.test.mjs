// Compiled S2 lifecycle-door controls (union, disintermediation, border,
// restoration). No server mutations — router.post is captured.
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

async function fixture(t, pageFile, props) {
    const visits = [], posts = [];
    const page = Vue.reactive({ url: '/x', props: { flash: {}, errors: {} } });
    const reactiveProps = Vue.reactive(props);

    const context = vm.createContext({ URL, console, document: globalThis.document });
    const cache = new Map();
    const actual = new Set([pageFile]);
    const slots = { setup: (props, { slots }) => () => Vue.h('section', {}, [slots.default?.()]) };
    // A HistoryPager stub that surfaces its own cursorKey for assertion.
    const Pager = { props: ['pages', 'only', 'first', 'label', 'cursorKey'], setup: (p) => () => Vue.h('nav', { 'data-cursor-key': p.cursorKey }, `pager:${p.cursorKey}`) };
    const Link = { props: ['href'], setup: (props, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: props.href }, slots.default?.()) };

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
                : name === '@inertiajs/vue3' ? { Link, useForm: () => ({}), usePage: () => page,
                    router: { get: (...args) => visits.push(args), post: (url, data, options) => posts.push({ url, data: snapshot(data), options }) } }
                : name === 'vue-i18n' ? { useI18n: () => ({ t: (key, arg, fallback) => (typeof arg === 'string' ? arg : fallback ?? key) }) }
                : file === 'lib/plain.js' ? { plainState: (s) => String(s) }
                : file === 'Components/Ui/HistoryPager.vue' ? { default: Pager }
                : file === 'composables/useDemoMode' ? { useDemoMode: () => ({ isDemoMode: false }) }
                : { default: slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); }, { context });
        }
        cache.set(file, module); return module;
    }

    const module = compiled(pageFile); cache.set(pageFile, module);
    await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, get options() { return this.children; }, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {} });
    const renderer = Vue.createRenderer({
        createElement: element, createText: (text) => element('#text', text), createComment: (text) => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: (el) => el.parent, nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'), app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, reactiveProps) });
    app.mount(root); t.after(() => app.unmount()); await flush();
    const nodes = (el = root) => [el, ...el.children.flatMap((child) => nodes(child))];
    const text = (el) => (el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' '));
    return {
        props: reactiveProps, posts, page, nodes, text: () => text(root),
        button: (label) => nodes().find((el) => el.tag === 'button' && text(el).trim() === label),
        pagerKeys: () => nodes().filter((el) => el.tag === 'nav' && (el.props['data-cursor-key'])).map((el) => el.props['data-cursor-key']),
    };
}

const clickAndPost = async (f, label, urlPart) => {
    const b = f.button(label);
    assert.ok(b, `control "${label}" renders`);
    if (b.props.onClick) {
        b.props.onClick();
    } else {
        // A submit button: fire the enclosing form's submit handler.
        let el = b;
        while (el && !(el.tag === 'form' && el.props.onSubmit)) el = el.parent;
        assert.ok(el, `"${label}" is inside a submitting form`);
        el.props.onSubmit({ preventDefault() {} });
    }
    await flush();
    const post = f.posts.find((p) => p.url.includes(urlPart));
    assert.ok(post, `"${label}" posts a route containing ${urlPart}`);
    return post;
};

// ── UNION ──────────────────────────────────────────────────────────────────

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

test('union: the doors post their own routes when the viewer holds a seat; the note and pager render', async (t) => {
    const f = await fixture(t, 'Pages/Jurisdictions/UnionFormation.vue', unionProps());
    await clickAndPost(f, 'Open my chamber\'s consent vote', '/jurisdictions/union-formation/u1/consent');
    await clickAndPost(f, 'Finalize the union', '/jurisdictions/union-formation/u1/finalize');
    assert.match(f.text(), /already unioned under Earth|future worlds and sub-unions/, 'the standing union note renders');
    assert.ok(f.pagerKeys().includes('union_cursor'), 'the pager carries its own cursor name');
});

test('union: no seat → no action controls (absent, not merely disabled)', async (t) => {
    const f = await fixture(t, 'Pages/Jurisdictions/UnionFormation.vue', unionProps({ door: { seat: null, siblings: [] } }));
    assert.equal(f.button('Finalize the union'), undefined, 'the finalize control is absent without a seat');
    assert.equal(f.button('Open my chamber\'s consent vote'), undefined, 'the consent control is absent without a seat');
});

test('union: consent control is absent when the viewer is not a pending constituent', async (t) => {
    const props = unionProps();
    props.processes[0].consentable_by_viewer = false;
    const f = await fixture(t, 'Pages/Jurisdictions/UnionFormation.vue', props);
    assert.equal(f.button('Open my chamber\'s consent vote'), undefined);
    // but a seated viewer still sees finalize (the right actor).
    assert.ok(f.button('Finalize the union'));
});

// ── DISINTERMEDIATION ────────────────────────────────────────────────────────

const disinterProps = (over = {}) => ({
    surface: { title: 'Disintermediation', forms: [] },
    processes: [{
        id: 'd1', status: 'open', intermediary: 'State', encompassing: 'Country',
        encompassing_consent: null, consentable_by_viewer: true, viewer_is_encompassing: true,
        unanimity: { yes: 0, no: 0, required: 1, total: 1, status: 'open', consents: [] },
        folded_acts: [], opened_at: '2026-09-13T00:00:00Z',
    }],
    door: { seat: { jurisdiction_id: 'state', jurisdiction_name: 'State', legislature_id: 'l', has_parent: true, child_count: 1 }, proposable: true },
    pagination: { previous: null, next: '/jurisdictions/disintermediation?disinter_cursor=X', first: '/jurisdictions/disintermediation' },
    ...over,
});

test('disintermediation: encompassing-consent, consent and finalize post their routes; pager keyed disinter_cursor', async (t) => {
    const f = await fixture(t, 'Pages/Jurisdictions/Disintermediation.vue', disinterProps());
    await clickAndPost(f, 'Encompassing consents', '/jurisdictions/disintermediation/d1/encompassing-consent');
    await clickAndPost(f, 'Open my chamber\'s consent vote', '/jurisdictions/disintermediation/d1/consent');
    await clickAndPost(f, 'Finalize the dissolution', '/jurisdictions/disintermediation/d1/finalize');
    assert.ok(f.pagerKeys().includes('disinter_cursor'), 'the pager carries its own cursor name');
});

test('disintermediation: the encompassing-consent controls are absent when the viewer is not the encompassing chamber', async (t) => {
    const props = disinterProps();
    props.processes[0].viewer_is_encompassing = false;
    const f = await fixture(t, 'Pages/Jurisdictions/Disintermediation.vue', props);
    assert.equal(f.button('Encompassing consents'), undefined, 'the consent control is absent for a non-encompassing seat');
    assert.equal(f.button('Encompassing declines'), undefined, 'the decline control is absent for a non-encompassing seat');
    // a seated viewer still sees finalize (the right actor, any seat).
    assert.ok(f.button('Finalize the dissolution'));
});

// ── BORDER (BetweenGovernments) ──────────────────────────────────────────────

const borderProps = (over = {}) => ({
    surface: { title: 'Between governments', forms: [] },
    settlements: [{ id: 's1', a: 'A', b: 'B', affected_population: 3, required: 2, supermajority_met: false, status: 'open', opened_at: '2026-09-13T00:00:00Z' }],
    viewer: { jurisdiction_id: 'a', jurisdiction_name: 'A', legislature_id: 'l', has_parent: true, child_count: 0 },
    pagination: { previous: null, next: '/federation?border_cursor=X', first: '/federation' },
    ...over,
});

test('border: referendum and adopt post per-settlement routes; pager keyed border_cursor', async (t) => {
    const f = await fixture(t, 'Pages/Jurisdictions/BetweenGovernments.vue', borderProps());
    await clickAndPost(f, 'Record referendum', '/federation/border/s1/referendum');
    await clickAndPost(f, 'Adopt the boundary', '/federation/border/s1/adopt');
    await clickAndPost(f, 'Open the settlement', '/federation/border/propose');
    assert.ok(f.pagerKeys().includes('border_cursor'), 'the pager carries its own cursor name');
});

test('border: no viewer → the write doors are absent', async (t) => {
    const f = await fixture(t, 'Pages/Jurisdictions/BetweenGovernments.vue', borderProps({ viewer: null }));
    assert.equal(f.button('Adopt the boundary'), undefined);
    assert.equal(f.button('Open the settlement'), undefined);
});

// ── RESTORATION ──────────────────────────────────────────────────────────────

const restorationProps = (over = {}) => ({
    surface: { title: 'Restoration', forms: [] },
    conditions: ['countermanded', 'captured', 'destroyed'],
    events: [{ id: 'e1', jurisdiction: 'Fallen', condition: 'captured', status: 'confirmed', judicially_confirmed: true, judicial_finding: true, review_case_id: 'c1', tier: 2, declared_at: '2026-09-13T00:00:00Z' }],
    viewer: { jurisdiction_id: 'j', jurisdiction_name: 'Fallen', legislature_id: 'l', has_parent: false, child_count: 0 },
    pagination: { previous: null, next: '/jurisdictions/restoration?restoration_cursor=X', first: '/jurisdictions/restoration' },
    ...over,
});

test('restoration: declare, tier and abandon post their routes; pager keyed restoration_cursor', async (t) => {
    const f = await fixture(t, 'Pages/Jurisdictions/Restoration.vue', restorationProps());
    await clickAndPost(f, 'Enter tier 3', '/jurisdictions/restoration/e1/tier');
    await clickAndPost(f, 'Abandon', '/jurisdictions/restoration/e1/abandon');
    await clickAndPost(f, 'Declare the condition', '/jurisdictions/restoration/declare');
    assert.ok(f.pagerKeys().includes('restoration_cursor'), 'the pager carries its own cursor name');
});

test('restoration: a declared event with no finding shows confirm plus the missing-finding hint', async (t) => {
    const props = restorationProps();
    props.events[0] = { id: 'e2', jurisdiction: 'Fallen', condition: 'captured', status: 'declared', judicially_confirmed: false, judicial_finding: false, review_case_id: 'c1', tier: 0, declared_at: '2026-09-13T00:00:00Z' };
    const f = await fixture(t, 'Pages/Jurisdictions/Restoration.vue', props);
    await clickAndPost(f, 'Confirm on the judicial finding', '/jurisdictions/restoration/e2/confirm');
    assert.match(f.text(), /no constitutional finding yet/, 'the missing-finding hint renders');
});

test('restoration: no viewer → the controls are absent', async (t) => {
    const f = await fixture(t, 'Pages/Jurisdictions/Restoration.vue', restorationProps({ viewer: null }));
    assert.equal(f.button('Declare the condition'), undefined);
    assert.equal(f.button('Abandon'), undefined);
});

test('restoration: the active banner re-derives when a page swap replaces the events prop', async (t) => {
    // Page 1 carries an in-progress event → the "active" banner reads it.
    const f = await fixture(t, 'Pages/Jurisdictions/Restoration.vue', restorationProps());
    assert.match(f.text(), /Restoration active/, 'page 1 shows the active banner');
    // A HistoryPager visit swaps only the events prop (preserveState keeps the
    // component). A stale non-reactive derivation would keep the active banner.
    f.props.events = [{ id: 'e9', jurisdiction: 'Older', condition: 'destroyed', status: 'restored', judicially_confirmed: true, judicial_finding: true, review_case_id: 'c9', tier: 3, declared_at: '2026-09-12T00:00:00Z' }];
    await flush();
    assert.match(f.text(), /Restoration mode dormant/, 'the banner re-derives to dormant after the page swap');
});
