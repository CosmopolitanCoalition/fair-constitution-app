// Compiled Vue lifecycle, the publish form's post shape, and the read-only preview. No server mutations.
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

async function fixture(t, overrides = {}) {
    const visits = [], posts = [];
    const props = Vue.reactive({
        surface: { id: 'education/material-manager', title: 'Manage training material' },
        can: { publish: true },
        tracks: [{ key: 'legislator', title: 'Legislator basics', status: 'live' }],
        surfaces: [{ id: 'learn/lesson', title: 'Lesson' }, { id: 'education/material-manager', title: 'Manage training material' }],
        modules: { rows: [{ module_key: 'floor', title: 'Taking the floor', track_key: 'legislator', track_title: 'Legislator basics',
            surface_id: 'learn/lesson', minutes: 5, status: 'live', revision_number: 2, published_by: 'Coalition Agent', published_at: '2026-09-13', edit_href: '/learn/manage/floor' }],
            pages: { next: '/learn/manage?cursor=floor%7Cid' } },
        ...overrides,
    });
    const context = vm.createContext({ URL, console, document: globalThis.document });
    const cache = new Map();
    const actual = new Set(['Pages/Learn/MaterialManager.vue']);
    const slots = { setup: (props, { slots }) => () => Vue.h('section', {}, [slots.default?.()]) };
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
            const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? { Link, usePage: () => ({ url: '/learn/manage' }),
                router: { get: (...args) => visits.push(args), post: (url, data, options) => posts.push({ url, data: snapshot(data), options }) } }
                : { default: slots };
            module = new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
        }
        cache.set(file, module); return module;
    }
    const file = 'Pages/Learn/MaterialManager.vue', module = compiled(file); cache.set(file, module);
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
    return { props, visits, posts, nodes, text: () => text(root),
        button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label) };
}

const set = (f, id, value) => f.nodes().find(n => n.props.id === id).props['onUpdate:modelValue'](value);
const formFor = (f, field) => f.nodes().find(n => n.tag === 'form' && f.nodes(n).some(c => c.props.id === field));
const submit = form => form.props.onSubmit({ preventDefault() {} });

test('an R-23 editor fills the fields and posts F-EDU-002 through /learn/manage', async t => {
    const f = await fixture(t);
    assert.match(f.text(), /Lesson prose lives in the K-2 source/);
    set(f, 'material-module-key', 'committees'); set(f, 'material-title', 'How committees form');
    set(f, 'material-track', 'legislator'); set(f, 'material-surface', 'learn/lesson');
    set(f, 'material-minutes', 6); set(f, 'material-status', 'live'); await flush();
    submit(formFor(f, 'material-module-key'));
    assert.equal(f.posts[0].url, '/learn/manage');
    assert.deepEqual(f.posts[0].data, { module_key: 'committees', title: 'How committees form', action: 'publish',
        track_key: 'legislator', surface_id: 'learn/lesson', minutes: 6, status: 'live', ip_register_entry_id: null });
    f.posts[0].options.onStart(); await flush(); assert.match(f.text(), /Filing publication/);
    f.posts[0].options.onError({ constitution: 'This module was never published.' }); f.posts[0].options.onFinish(); await flush();
    assert.match(f.text(), /never published/);
    submit(formFor(f, 'material-module-key')); assert.equal(f.posts.length, 2);
    f.posts[1].options.onSuccess(); f.posts[1].options.onFinish(); await flush();
    assert.match(f.text(), /Publication filed/);
});

test('a revise selection posts the revise action and lists existing modules with revision and publisher', async t => {
    const f = await fixture(t);
    assert.match(f.text(), /Taking the floor/);
    assert.match(f.text(), /revision 2/);
    assert.match(f.text(), /Coalition Agent/);
    assert.ok(f.nodes().some(n => n.tag === 'a' && n.props.href === '/learn/manage/floor'));
    assert.ok(f.nodes().some(n => n.tag === 'a' && n.props.href === '/learn/manage?cursor=floor%7Cid'));
    set(f, 'material-action', 'revise'); set(f, 'material-module-key', 'floor'); set(f, 'material-title', 'Taking the floor v2');
    set(f, 'material-track', 'legislator'); set(f, 'material-surface', 'learn/lesson'); await flush();
    submit(formFor(f, 'material-module-key'));
    assert.equal(f.posts[0].data.action, 'revise');
    assert.equal(f.posts[0].data.module_key, 'floor');
});

test('a non-editor sees a read-only preview and cannot submit', async t => {
    const f = await fixture(t, { can: { publish: false } });
    assert.match(f.text(), /Read-only preview/);
    assert.match(f.text(), /requires the authoring body's agent role/);
    assert.equal(f.nodes().find(n => n.tag === 'form'), undefined);
    assert.equal(f.button('Publish module'), undefined);
    assert.match(f.text(), /Taking the floor/); // list still renders for everyone
});
