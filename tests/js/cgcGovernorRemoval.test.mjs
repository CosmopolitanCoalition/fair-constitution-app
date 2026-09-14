// Synthetic UI test: node --experimental-vm-modules --test tests/js/cgcGovernorRemoval.test.mjs
//
// IO-7 — CGC governor removal control on the Common Good Corporation detail
// page (operator ruling 2026-09-13 · cgc-governor-removal-shape = A). The real
// CgcDetail.vue script runs; the template renders into a minimal renderer; the
// removal form (useForm) is exposed so the exact posted route and payload are
// asserted, and the visibility/disabled guards are checked.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const id = n => `70000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = globalThis.document || { activeElement: null };

const seat = (n, seat_class, status, holder = null) => ({ id: id(n), seat_class, status, holder, is_chair: false, term: null });

async function fixture(t, { canRequestRemoval = true, status = 'active', seats = null } = {}) {
    const posts = [], visits = [];
    const page = Vue.reactive({ url: `/organizations/${id(1)}`, props: { flash: {}, errors: {} } });
    const props = Vue.reactive({
        surface: { forms: [
            { id: 'F-LEG-019', name: 'Common Good Corporation Charter', alias: null },
            { id: 'F-EXE-003', name: 'Board Member Removal Request', alias: null },
        ] },
        organization: { id: id(1), name: 'Public transport', type: 'common_good_corp', status, worker_count: 0 },
        charter: null, oversight: null, codet: null,
        board: { compositionValid: true, requiredWorkerSeats: 0, seats: seats ?? [
            seat(7, 'governor', 'seated', { name: 'Seated Governor' }),
            seat(8, 'worker_elected', 'seated', { name: 'Worker' }),
        ] },
        ipRegister: [], actionsDeepLinks: {}, conversions: [],
        can: { registerIp: false, requestRemoval: canRequestRemoval },
        urls: { ipRegister: `/organizations/${id(1)}/ip-register`, governorRemovals: `/organizations/${id(1)}/governor-removals` },
    });
    const context = vm.createContext({ URL, console, Date, document: { activeElement: null } });
    const cache = new Map(), real = new Set(['Pages/Organizations/CgcDetail.vue']);
    const expose = 'removal, submitRemoval, removableSeats, removalDisabledReason';
    const generic = { setup: (p, { slots, attrs }) => () => Vue.h('section', attrs, [slots.title?.(), slots.default?.()]) };
    const Link = { props: ['href'], setup: (p, { slots, attrs }) => () => Vue.h('a', { ...attrs, href: p.href }, slots.default?.()) };
    function compile(file) {
        const source = real.has(file) ? read(file).replace('</script>', `defineExpose({ ${expose} });\n</script>`) : read(file);
        const { descriptor } = parse(source);
        return new vm.SourceTextModule(compileScript(descriptor, { id: file, inlineTemplate: true }).content, { context, identifier: file });
    }
    function dependency(name, parent) {
        const file = name.startsWith('@/') ? name.slice(2) : name.startsWith('.') ? new URL(name, 'file:///' + parent.identifier).pathname.slice(1) : name;
        if (cache.has(file)) return cache.get(file);
        let module;
        if (real.has(file)) module = compile(file);
        else {
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? { Link, usePage: () => page,
                router: { get: (...args) => visits.push(args), post: (...args) => posts.push(args) },
                useForm: data => { let defaults = copy(data); const form = Vue.reactive({ ...data, processing: false, errors: {},
                    defaults(next) { defaults = copy(next); }, reset() { Object.assign(form, defaults); }, transform() { return form; },
                    post(url, options) { posts.push({ url, data: copy({ board_seat_id: form.board_seat_id, grounds: form.grounds }), options }); } }); return form; } }
                : name === 'vue-i18n' ? { useI18n: () => ({ t: (key, arg, fallback) => typeof arg === 'string' ? arg : fallback ?? key }) }
                : { default: generic };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(file, module); return module;
    }
    const file = 'Pages/Organizations/CgcDetail.vue', module = compile(file); cache.set(file, module); await module.link(dependency); await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, style: {}, querySelector: () => null, addEventListener() {}, removeEventListener() {} });
    const renderer = Vue.createRenderer({ createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText(el, text) { el.text = text; }, setElementText(el, text) { el.text = text; el.children = []; }, parentNode: el => el.parent,
        nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null });
    const root = element('root'); let app, state;
    app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, { ...props, ref: value => { if (value) state = value; } }) });
    app.mount(root); t.after(() => app.unmount()); await flush();
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const textOf = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(textOf)].join(' ');
    return { props, page, posts, visits, get state() { return state; }, nodes, text: () => textOf(root) };
}

test('a seated principal of the overseeing executive sees the removal form and posts the exact route and payload', async t => {
    const f = await fixture(t);
    assert.match(f.text(), /removed through this corporation's overseeing executive/);
    assert.equal(f.state.removableSeats.length, 1);
    assert.equal(f.state.removableSeats[0].id, id(7));
    assert.equal(f.state.removalDisabledReason, null);
    f.state.removal.board_seat_id = id(7);
    f.state.removal.grounds = 'Public competence grounds';
    f.state.submitRemoval();
    assert.equal(f.posts.length, 1);
    assert.equal(f.posts[0].url, `/organizations/${id(1)}/governor-removals`);
    assert.deepEqual(f.posts[0].data, { board_seat_id: id(7), grounds: 'Public competence grounds' });
});

test('the removal form is absent and files nothing when the viewer is not an overseeing principal', async t => {
    const f = await fixture(t, { canRequestRemoval: false });
    assert.doesNotMatch(f.text(), /removed through this corporation's overseeing executive/);
    assert.equal(f.posts.length, 0);
});

test('the form is disabled with a reason when no seat qualifies or the corporation is inactive', async t => {
    const noGovernor = await fixture(t, { seats: [seat(8, 'worker_elected', 'seated', { name: 'Worker' }), seat(9, 'governor', 'vacant')] });
    assert.equal(noGovernor.state.removableSeats.length, 0);
    assert.match(noGovernor.state.removalDisabledReason, /no seated governor/i);

    const inactive = await fixture(t, { status: 'dissolved' });
    assert.match(inactive.state.removalDisabledReason, /not active/i);
});
