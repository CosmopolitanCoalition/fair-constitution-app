// node --experimental-vm-modules --test tests/js/learnFlyout.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const rootPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = name => readFileSync(path.join(rootPath, name), 'utf8');
const dictionary = Object.fromEntries(['c_education', 'c_learn'].flatMap(namespace =>
    Object.entries(JSON.parse(read(`resources/js/i18n/locales/en/${namespace}.json`))).map(([key, value]) => [`${namespace}.${key}`, value])));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };

async function fixture(t, { standalone = false, learnOnly = false } = {}) {
    const page = Vue.reactive({ url: '/economy/work', props: { surface: { id: 'economy/work', title: 'Work', module: 'economy',
        workflows: ['WF-EXE-04'], forms: [{ id: 'F-IND-014', name: 'Employment agreement' }], roles: ['R-01'], clocks: ['CLK-09'] } } });
    const guidance = Vue.ref('Page-specific guidance');
    const listeners = new Map(), navigation = new Set(), warnings = [];
    const nodes = el => [el, ...el.children.flatMap(nodes)];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    const matches = (el, selector) => selector.startsWith('.') ? String(el.props.class ?? '').split(' ').includes(selector.slice(1))
        : selector.startsWith('#') ? el.props.id === selector.slice(1) : el.tag === selector;
    const element = (tag, value = '') => Vue.markRaw({
        tag, text: value, props: {}, children: [], parent: null, open: false, focused: false,
        querySelectorAll(selector) { return nodes(this).slice(1).filter(el => matches(el, selector)); },
        querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
        contains(el) { return nodes(this).includes(el); },
        removeAttribute(key) { delete this.props[key]; if (key === 'open') this.open = false; },
        focus() { this.focused = true; },
        get classList() { return { contains: value => matches(this, '.' + value) }; },
    });
    const root = element('root');
    const renderer = Vue.createRenderer({
        createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) {
            if (el.parent) { const at = el.parent.children.indexOf(el); if (at >= 0) el.parent.children.splice(at, 1); }
            el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1;
            if (at < 0) parent.children.push(el); else parent.children.splice(at, 0, el);
        },
        remove(el) { const siblings = el.parent?.children; if (siblings?.includes(el)) siblings.splice(siblings.indexOf(el), 1); el.parent = null; },
        patchProp(el, key, previous, value) { el.props[key] = value; },
        setText: (el, text) => { el.text = text; },
        setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent,
        nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
        querySelector: selector => nodes(root).find(el => matches(el, selector)) ?? null,
    });
    const context = vm.createContext({ console, document: {
        addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },
        removeEventListener(name, fn) { listeners.get(name)?.delete(fn); },
    } });
    const Link = { props: ['href'], setup: (props, { slots }) => () => Vue.h('a', { href: props.href }, slots.default?.()) };
    const imports = {
        vue: Vue,
        '@inertiajs/vue3': { Link, Head: { render: () => null }, usePage: () => page,
            router: { on(name, fn) { assert.equal(name, 'navigate'); navigation.add(fn); return () => navigation.delete(fn); } } },
        'vue-i18n': { useI18n: () => ({ t: (key, fallback) => dictionary[key] ?? fallback ?? key }) },
    };
    const cache = new Map();
    function moduleFor(specifier, importer = rootPath) {
        if (imports[specifier]) {
            if (!cache.has(specifier)) {
                const values = imports[specifier];
                cache.set(specifier, new vm.SyntheticModule(Object.keys(values), function () {
                    for (const [key, value] of Object.entries(values)) this.setExport(key, value);
                }, { context, identifier: specifier }));
            }
            return cache.get(specifier);
        }
        const filename = specifier.startsWith('@/') ? path.join(rootPath, 'resources/js', specifier.slice(2)) : path.resolve(path.dirname(importer), specifier);
        if (cache.has(filename)) return cache.get(filename);
        const stub = /\/(Icon|MenuNav|DemoFlyout)\.vue$/.test(filename.replaceAll('\\', '/'));
        let source = stub ? 'export default { render: () => null };' : readFileSync(filename, 'utf8');
        if (!stub && filename.endsWith('.vue')) {
            const { descriptor } = parse(source);
            source = compileScript(descriptor, { id: filename, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content;
        }
        const module = new vm.SourceTextModule(source, { context, identifier: filename });
        cache.set(filename, module);
        return module;
    }
    const entry = new vm.SourceTextModule(`import Scaffold from '@/Components/Surface/PageScaffold.vue'; import Bar from '@/Components/ShellV2/CmdBar.vue'; export { Scaffold, Bar };`, { context });
    await entry.link((name, from) => moduleFor(name, from.identifier));
    await entry.evaluate();
    const { Scaffold, Bar } = entry.namespace;
    const app = renderer.createApp({ setup() {
        Vue.provide('cga:surface', Vue.computed(() => page.props.surface));
        if (!standalone) Vue.provide('cga:learn-target', '#learn-test');
        return () => Vue.h('div', [
            Vue.h('main', [Vue.h(Scaffold, { key: page.url }, {
                intro: () => 'Short introduction',
                ...(guidance.value === null ? {} : { about: () => Vue.h('p', guidance.value) }),
                default: () => Vue.h('button', 'Working action'),
            })]),
            ...(!standalone ? [Vue.h(Bar, { learnOnly, demo: true })] : []),
        ]);
    } });
    app.config.warnHandler = message => warnings.push(message);
    app.mount(root); await flush();
    let mounted = true;
    const unmount = () => { if (mounted) { app.unmount(); mounted = false; } };
    t.after(unmount);
    return { page, guidance, root, warnings, listeners, navigation, unmount, nodes: () => nodes(root),
        text: (el = root) => text(el), one: selector => nodes(root).find(el => matches(el, selector)),
        emit: (event, payload) => listeners.get(event)?.forEach(fn => fn(payload)),
        navigate: () => navigation.forEach(fn => fn()),
    };
}

test('page guidance and references render inside Learn; the main page keeps its working content', async t => {
    const f = await fixture(t);
    assert.match(f.text(f.one('main')), /Short introduction.*Working action/);
    assert.doesNotMatch(f.text(f.one('main')), /Page-specific guidance|Reference codes|About this surface/);
    assert.match(f.text(f.one('#cmd-learn')), /Page-specific guidance/);
    assert.match(f.text(f.one('#cmd-learn')), /Employment agreement/);
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/learn' && f.text(el).includes('Full lessons')));
    assert.ok(f.nodes().some(el => el.tag === 'a' && el.props.href === '/videos'));
    assert.doesNotMatch(f.text(), /Planned.*Phase 7/);
    assert.deepEqual(f.warnings, []);
});

test('reactive guidance updates and navigation removes the previous page slot', async t => {
    const f = await fixture(t);
    f.guidance.value = 'Updated instructions'; await flush();
    assert.match(f.text(f.one('#learn-test')), /Updated instructions/);
    assert.doesNotMatch(f.text(), /Page-specific guidance/);
    f.page.url = '/rooms';
    f.page.props.surface = { id: 'rooms/directory', title: 'Rooms', module: 'rooms' };
    f.guidance.value = 'Room guidance'; await flush();
    assert.match(f.text(f.one('#learn-test')), /Room guidance/);
    assert.doesNotMatch(f.text(), /Updated instructions|Employment agreement/);
    f.page.url = '/economy/help'; f.guidance.value = null; await flush();
    assert.equal(f.text(f.one('#learn-test')).trim(), '');
    f.page.url = '/economy/work'; f.guidance.value = 'Returning page'; await flush();
    assert.equal(f.text().match(/Returning page/g).length, 1);
    assert.deepEqual(f.warnings, []);
});

test('all five new surface IDs resolve authored guidance on navigation', async t => {
    const f = await fixture(t);
    for (const [id, expected] of [
        ['economy/work', /applicant.s acceptance/], ['economy/help', /private drafts/],
        ['economy/help-detail', /requester chooses a helper/], ['rooms/directory', /browsing the directory does not join a call/],
        ['learn/video-library', /instance.s media host/],
    ]) {
        f.page.props.surface = { id }; await flush();
        assert.match(f.text(f.one('#cmd-learn')), expected);
        assert.doesNotMatch(f.text(f.one('#cmd-learn')), /c_education\./);
    }
});

test('Escape, outside click, single-open behavior and navigation close the actual flyouts', async t => {
    const f = await fixture(t);
    const learn = f.one('#cmd-learn'), menu = f.one('#cmd-menu');
    learn.open = true;
    f.emit('keydown', { key: 'Escape' });
    assert.equal(learn.open, false);
    assert.equal(learn.querySelector('summary').focused, true);
    learn.open = true; menu.open = true;
    f.one('.cmdbar').props.onToggleCapture({ target: learn });
    assert.equal(menu.open, false);
    f.emit('click', { target: learn.querySelector('summary') });
    assert.equal(learn.open, true);
    f.emit('click', { target: f.one('main') });
    assert.equal(learn.open, false);
    learn.open = true; f.navigate(); assert.equal(learn.open, false);
});

test('unmount removes teleported guidance and every global listener', async t => {
    const f = await fixture(t);
    const target = f.one('#learn-test');
    assert.equal(f.navigation.size, 1);
    assert.equal(f.listeners.get('keydown').size, 1);
    f.unmount(); await flush();
    assert.equal(f.text(target).trim(), '');
    assert.equal(f.navigation.size, 0);
    assert.equal(f.listeners.get('keydown').size, 0);
    assert.equal(f.listeners.get('click').size, 0);
});

test('legacy shell mode exposes Learn without adding another Menu or Demo', async t => {
    const f = await fixture(t, { learnOnly: true });
    assert.ok(f.one('#cmd-learn'));
    assert.equal(f.one('#cmd-menu'), undefined);
    assert.equal(f.one('#cmd-demo'), undefined);
    assert.match(f.text(f.one('#cmd-learn')), /Page-specific guidance/);
});

test('standalone scaffold retains accessible Learn guidance without a missing teleport target', async t => {
    const f = await fixture(t, { standalone: true });
    assert.equal(f.text(f.one('summary')).trim(), 'Learn');
    assert.match(f.text(f.one('details')), /Page-specific guidance/);
    assert.deepEqual(f.warnings, []);
});
