// node --experimental-vm-modules --test tests/js/a11yTableFocusTitle.test.mjs
//
// W-0338 pin. The shared DataTable scroll container becomes keyboard-focusable
// with an accessible name only while it overflows, and Setup/Bootstrap renders
// a non-empty document title. Both components are compiled and mounted through
// a headless renderer, so a template or script error fails the run.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

function makeElement() {
    return function element(tag, text = '') {
        return Vue.markRaw({
            tag, tagName: String(tag).toUpperCase(), text, children: [], parent: null, props: {}, listeners: {},
            scrollWidth: 0, clientWidth: 0,
            addEventListener(name, cb) { (this.listeners[name] ??= []).push(cb); }, removeEventListener() {},
            setAttribute(name, value) { this.props[name] = value; }, removeAttribute(name) { delete this.props[name]; },
        });
    };
}
function makeRenderer(element) {
    return Vue.createRenderer({
        createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent) { el.parent = parent; parent.children.push(el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; },
        setText: (el, t) => { el.text = t; }, setElementText: (el, t) => { el.text = t; el.children = []; },
        parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
}
async function compile(rel, id) {
    const source = await readFile(new URL('../../' + rel, import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const context = vm.createContext({ ResizeObserver: globalThis.ResizeObserver, window: globalThis.window, console });
    const mod = new vm.SourceTextModule(compileScript(descriptor, { id, inlineTemplate: true }).content, { context });
    return { mod, context };
}
function nodesOf(root) { return [root, ...root.children.flatMap(nodesOf)]; }

test('W-0338 DataTable wrapper is focusable and named only when it overflows', async (t) => {
    let roCb = null;
    const priorRO = globalThis.ResizeObserver, priorWin = globalThis.window;
    globalThis.ResizeObserver = class { constructor(cb) { roCb = cb; } observe() {} disconnect() {} };
    globalThis.window = { addEventListener() {}, removeEventListener() {} };
    t.after(() => { globalThis.ResizeObserver = priorRO; globalThis.window = priorWin; });

    const element = makeElement();
    const { mod, context: ctx } = await compile('resources/js/Components/Ui/DataTable.vue', 'dt');
    await mod.link(name => {
        const values = name === 'vue' ? Vue : { default: { setup: (_, { slots }) => () => Vue.h('section', slots.default?.()) } };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); }, { context: ctx });
    });
    await mod.evaluate();
    const root = element('root');
    const app = makeRenderer(element).createApp(mod.namespace.default, {
        columns: [{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }],
        rows: [{ a: '1', b: '2' }], caption: 'Node families',
    });
    app.mount(root); t.after(() => app.unmount()); await Vue.nextTick();

    const wrap = nodesOf(root).find(el => el.props.class === 'table-wrap');
    assert.ok(wrap, 'table-wrap rendered');
    // Not overflowing at first: no tabindex, no name.
    assert.equal(wrap.props.tabindex, undefined, 'no tabindex when it fits');
    assert.equal(wrap.props['aria-label'], undefined, 'no name when it fits');

    // Simulate overflow, drive the ResizeObserver callback.
    wrap.scrollWidth = 800; wrap.clientWidth = 300; roCb(); await Vue.nextTick();
    assert.equal(wrap.props.tabindex, 0, 'tabindex 0 when overflowing');
    assert.equal(wrap.props.role, 'region', 'role region when overflowing');
    assert.equal(wrap.props['aria-label'], 'Node families (scrollable)', 'named when overflowing');

    // Back to fitting.
    wrap.scrollWidth = 300; wrap.clientWidth = 300; roCb(); await Vue.nextTick();
    assert.equal(wrap.props.tabindex, undefined, 'tabindex dropped when it fits again');
    assert.equal(wrap.props['aria-label'], undefined, 'name dropped when it fits again');
});

test('W-0338 Bootstrap renders a non-empty document title', async (t) => {
    const element = makeElement();
    const { mod, context: ctx } = await compile('resources/js/Pages/Setup/Bootstrap.vue', 'boot');
    const plain = { setup: (_, { slots }) => () => Vue.h('slotwrap', slots.default?.()) };
    await mod.link(name => {
        const values = name === 'vue' ? Vue
            : name === '@inertiajs/vue3' ? { Head: plain, router: { visit() {} } }
            : name === '@/lib/csrf' ? { csrfFetch() {} }
            : { default: plain };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); }, { context: ctx });
    });
    await mod.evaluate();
    const root = element('root');
    const app = makeRenderer(element).createApp(mod.namespace.default, {
        status: { schema_state: 'uninitialised', pending_count: 0, etl_running: false },
    });
    app.mount(root); t.after(() => app.unmount()); await Vue.nextTick();

    const title = nodesOf(root).find(el => el.tag === 'title');
    assert.ok(title, '<title> rendered');
    const text = (title.text || title.children.map(c => c.text).join('')).trim();
    assert.ok(text.length > 0, 'title is non-empty');
    assert.equal(text, 'Set up this node');
});
