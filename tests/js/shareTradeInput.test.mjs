import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as money from '../../resources/js/lib/money.js';

async function fixture(t) {
    const priorDocument = globalThis.document;
    globalThis.document = { activeElement: null };
    t.after(() => { globalThis.document = priorDocument; });
    const source = await readFile(new URL('../../resources/js/Pages/Economy/Exchange.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source); const context = vm.createContext({});
    const module = new vm.SourceTextModule(compileScript(descriptor, { id: 'share-input', inlineTemplate: true }).content, { context });
    let form; const requests = [];
    const plain = { setup: (_, { slots }) => () => Vue.h('section', slots.default?.()) };
    await module.link(name => {
        const values = name === 'vue' ? Vue : name.endsWith('/money.js') ? money : name === '@inertiajs/vue3' ? {
            Link: plain, router: { post() {} }, useForm: initial => {
                form = Vue.reactive({ ...initial, processing: false, errors: {}, post(url, options) {
                    requests.push({ url, data: { organization_id: form.organization_id, units: form.units, price_per_unit: form.price_per_unit }, options });
                } }); return form;
            },
        } : { default: plain };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
    });
    await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, tagName: tag.toUpperCase(), text, children: [], parent: null, props: {}, listeners: {},
        addEventListener(name, callback) { (this.listeners[name] ??= []).push(callback); }, removeEventListener() {},
        get options() { return this.children.filter(child => child.tag === 'option'); },
    });
    const renderer = Vue.createRenderer({
        createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent) { el.parent = parent; parent.children.push(el); },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (['value', 'type', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'); const app = renderer.createApp(module.namespace.default, {
        surface: {}, currency: { symbol: 'F', precision: 6 }, my_holdings: [{ org_id: 'fixture-org', org_name: 'Fixture organization', units: '99999999999999.999999' }],
    });
    app.mount(root); t.after(() => app.unmount()); await Vue.nextTick();
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    return { form, requests, inputs: nodes().filter(el => el.tag === 'input'),
        async type(input, value) { input.value = value; for (const listener of input.listeners.input ?? []) listener({ target: input }); await Vue.nextTick(); },
        async submit() { nodes().find(el => el.tag === 'form').props.onSubmit({ preventDefault() {} }); await Vue.nextTick(); },
    };
}

test('compiled share inputs retain all decimal digits in the submitted payload and retry', async t => {
    const f = await fixture(t); f.form.organization_id = 'fixture-org';
    assert.equal(f.inputs.length, 2);
    for (const input of f.inputs) { assert.equal(input.props.type, 'text'); assert.equal(input.props.inputmode, 'decimal'); }
    await f.type(f.inputs[0], '99999999999999.123456');
    await f.type(f.inputs[1], '999999999999999999.123456'); await f.submit();
    assert.deepEqual(f.requests[0].data, { organization_id: 'fixture-org', units: '99999999999999.123456', price_per_unit: '999999999999999999.123456' });
    f.form.errors.constitution = 'Another offer already reserves these units.'; await Vue.nextTick(); await f.submit();
    assert.deepEqual(f.requests[1].data, f.requests[0].data);
    f.requests[1].options.onSuccess(); await Vue.nextTick();
    assert.equal(f.form.units, ''); assert.equal(f.form.price_per_unit, '');
});

test('one-millionth quantities and zero-price gifts remain strings through actual input events', async t => {
    const f = await fixture(t); f.form.organization_id = 'fixture-org';
    await f.type(f.inputs[0], '0.000001'); await f.type(f.inputs[1], '0'); await f.submit();
    assert.equal(f.requests[0].data.units, '0.000001'); assert.equal(f.requests[0].data.price_per_unit, '0');
    assert.equal(typeof f.form.units, 'string'); assert.equal(typeof f.form.price_per_unit, 'string');
});
