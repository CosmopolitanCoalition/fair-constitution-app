// node --experimental-vm-modules --test tests/js/frontDoorLearn.test.mjs
//
// LE-5 — the four front doors expose the Learn drawer.
//
// The three standalone sign-in pages (no shell) mount the SAME Learn-only
// command bar the legacy shell mounts, and provide the same `cga:surface` and
// `cga:learn-target` a shell provides. This test COMPILES each real page SFC,
// mounts it in a light DOM, and proves:
//   - the Learn flyout (#cmd-learn) renders, learn-only (no Menu, no Demo);
//   - the page's surface id resolves the AUTHORED K-2 guidance (learn sentence
//     + how-to steps), with no raw i18n key leaking;
//   - mounting warns about nothing (no missing injection, no bad target).
//
// The fourth front door is the operator console. It is NOT a standalone page:
// /operator/console redirects to /operator, which renders Operator/Home.vue
// under AppShellV2, and that shell already mounts the full Learn drawer
// (verified: AppShellV2.vue imports CmdBar and provides cga:surface /
// cga:learn-target). So the console needs no page change. This test still
// covers its guidance at the data layer: operator/home resolves an authored
// entry in the generated registry, alongside the three auth ids.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { EDUCATION_BY_SURFACE } from '../../resources/js/registry/education.js';

const rootPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = name => readFileSync(path.join(rootPath, name), 'utf8');
const dictionary = Object.fromEntries(['c_education', 'c_learn'].flatMap(namespace =>
    Object.entries(JSON.parse(read(`resources/js/i18n/locales/en/${namespace}.json`))).map(([key, value]) => [`${namespace}.${key}`, value])));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };

// One shared compile/link harness; each test mounts a fresh app for one page.
function harness() {
    const listeners = new Map(), navigation = new Set();
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
    });
    const context = vm.createContext({ console, Intl, document: {
        addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },
        removeEventListener(name, fn) { listeners.get(name)?.delete(fn); },
    } });
    const Link = { props: ['href'], setup: (props, { slots }) => () => Vue.h('a', { href: props.href }, slots.default?.()) };
    // Inertia's useForm is not exercised here (no submit); a reactive stand-in
    // carrying the initial fields plus errors/processing is all the templates read.
    const useForm = fields => Vue.reactive({ ...fields, errors: {}, processing: false, post() {}, reset() {} });
    const page = Vue.reactive({ url: '/login', props: { flash: {} } });
    const imports = {
        vue: Vue,
        '@inertiajs/vue3': { Link, Head: { render: () => null }, useForm, usePage: () => page,
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
        // Stub the leaf UI kit and the two sibling flyouts. CmdBar + LearnFlyout
        // and the page SFC under test stay REAL, so the Learn wiring is exercised.
        const unix = filename.replaceAll('\\', '/');
        const stub = /\/Components\/Ui\//.test(unix) || /\/(MenuNav|DemoFlyout)\.vue$/.test(unix);
        let source = stub ? 'export default { render: () => null };' : readFileSync(filename, 'utf8');
        if (!stub && filename.endsWith('.vue')) {
            const { descriptor } = parse(source);
            source = compileScript(descriptor, { id: filename, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content;
        }
        const module = new vm.SourceTextModule(source, { context, identifier: filename });
        cache.set(filename, module);
        return module;
    }
    return { element, renderer, moduleFor, context, listeners, navigation, nodes, text, matches };
}

async function mountPage(t, spec) {
    const h = harness();
    const entry = new vm.SourceTextModule(`import Page from '${spec}'; export { Page };`,
        { context: h.context, identifier: 'entry:' + spec });
    await entry.link((name, from) => h.moduleFor(name, from.identifier));
    await entry.evaluate();
    const { Page } = entry.namespace;
    const warnings = [];
    const root = h.element('root');
    const app = h.renderer.createApp(Page);
    app.config.warnHandler = message => warnings.push(message);
    app.mount(root);
    await flush();
    let mounted = true;
    const unmount = () => { if (mounted) { app.unmount(); mounted = false; } };
    t.after(unmount);
    const all = () => h.nodes(root);
    const one = selector => all().find(el => h.matches(el, selector));
    return { root, warnings, unmount, one, text: (el = root) => h.text(el),
        listeners: h.listeners, navigation: h.navigation };
}

const PAGES = {
    'auth/login': {
        spec: '@/Pages/Auth/Login.vue',
        learn: /reconnects you to the record you already made/,
        step: /five tries a minute/,
        // the beta residency-instant fact the item requires
        beta: /residency confirms the moment you declare it/,
    },
    'auth/operator-login': {
        spec: '@/Pages/Auth/OperatorLogin.vue',
        learn: /runs the instance\. It is not a way into the government/,
        step: /operator console: host services/,
        beta: /no vote, no seat, and no standing/,
    },
    'auth/register': {
        spec: '@/Pages/Auth/Register.vue',
        learn: /Creating an account gives you a place to stand/,
        step: /You are now R-01 \(Individual\)/,
        beta: /rights are inherent|it only recognises them once you live somewhere/,
    },
};

for (const [id, spec] of Object.entries(PAGES)) {
    test(`${id}: the page renders a learn-only Learn bar and resolves its authored guidance`, async t => {
        const f = await mountPage(t, spec.spec);
        const learn = f.one('#cmd-learn');
        assert.ok(learn, `${id} must mount the Learn flyout`);
        // learn-only: the shared bar carries Learn alone, no Menu, no Demo.
        assert.equal(f.one('#cmd-menu'), undefined, `${id} must not mount the Menu flyout`);
        assert.equal(f.one('#cmd-demo'), undefined, `${id} must not mount the Demo flyout`);
        assert.equal(f.text(f.one('summary')).trim(), 'Learn');
        // The injected surface id resolved the authored K-2 payload.
        const body = f.text(learn);
        assert.match(body, spec.learn, `${id} learn sentence must render`);
        assert.match(body, spec.step, `${id} how-to steps must render`);
        assert.match(body, spec.beta, `${id} required fact must render`);
        // No raw i18n key leaked (every key resolved through the dictionary).
        assert.doesNotMatch(body, /c_education\.|c_learn\./, `${id} must not leak i18n keys`);
        assert.deepEqual(f.warnings, [], `${id} must mount without Vue warnings`);
    });
}

test('every front-door surface id resolves authored guidance in the generated registry', () => {
    // The three standalone pages plus the operator console (served as
    // operator/home under AppShellV2, which already carries the Learn drawer).
    for (const id of ['auth/login', 'auth/operator-login', 'auth/register', 'operator/home']) {
        const entry = EDUCATION_BY_SURFACE[id];
        assert.ok(entry, `${id} must have an authored education entry`);
        assert.equal(typeof entry.learn, 'string', `${id} must have a learn sentence`);
        assert.ok(entry.steps.length > 0, `${id} must have how-to steps`);
        // learn/step values are i18n keys that must resolve in the en dictionary.
        assert.equal(typeof dictionary[entry.learn], 'string', `${id} learn key must resolve`);
        for (const step of entry.steps) {
            assert.equal(typeof dictionary[step.do], 'string', `${id} step do must resolve`);
            assert.equal(typeof dictionary[step.detail], 'string', `${id} step detail must resolve`);
        }
    }
});
