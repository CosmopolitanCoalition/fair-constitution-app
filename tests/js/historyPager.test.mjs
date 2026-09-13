import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';

const node = () => ({ children: [] });
const renderer = Vue.createRenderer({ createElement: node, createText: node, createComment: node,
    setText() {}, setElementText() {}, patchProp() {}, remove() {}, parentNode: () => null,
    nextSibling: () => null, insert(child, parent) { parent.children.push(child); } });
async function fixture(overrides = {}, inlineTemplate = false) {
    const calls = [];
    const page = Vue.reactive({ url: '/economy/treasury?jurisdiction=poland&account=new-account&ledger_cursor=ledger-two&issuance_cursor=issue-three#records' });
    const props = { cursorKey: 'ledger_cursor', only: ['ledger', 'ledger_pages'], label: 'Ledger history',
        first: '/economy/treasury?jurisdiction=earth&account=old-account',
        pages: { previous: '/economy/treasury?jurisdiction=earth&ledger_cursor=ledger-one', next: '/economy/treasury?jurisdiction=earth&ledger_cursor=ledger-three' }, ...overrides };
    const source = await readFile(new URL('../../resources/js/Components/Ui/HistoryPager.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const context = vm.createContext({ URL, window: { location: { origin: 'http://fixture.invalid' } } });
    const module = new vm.SourceTextModule(compileScript(descriptor, { id: 'history-pager', inlineTemplate }).content, { context });
    await module.link(name => {
        const values = name === 'vue' ? Vue : { usePage: () => page, router: { get: (...args) => calls.push(args) } };
        return new vm.SyntheticModule(Object.keys(values), function () {
            for (const [key, value] of Object.entries(values)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();
    const component = module.namespace.default;
    if (inlineTemplate) return { html: await renderToString(Vue.createSSRApp(component, props)) };
    component.render = () => null;
    let exposed;
    const app = renderer.createApp({ render: () => Vue.h(component, { ...props, ref: value => { exposed = value; } }) });
    app.mount(node());
    return { state: exposed.$.setupState, calls, page, props, close: () => app.unmount() };
}

test('stale pager links preserve current place, account and independently advanced histories', async () => {
    const f = await fixture();
    f.state.visit(f.props.pages.next);
    let url = new URL(f.calls[0][0], 'http://fixture.invalid');
    assert.equal(url.searchParams.get('jurisdiction'), 'poland');
    assert.equal(url.searchParams.get('account'), 'new-account');
    assert.equal(url.searchParams.get('ledger_cursor'), 'ledger-three');
    assert.equal(url.searchParams.get('issuance_cursor'), 'issue-three');
    assert.equal(url.hash, '#records');
    f.page.url = '/economy/treasury?jurisdiction=poland&account=new-account&ledger_cursor=ledger-three&issuance_cursor=issue-four';
    f.state.visit(f.props.pages.previous);
    url = new URL(f.calls[1][0], 'http://fixture.invalid');
    assert.equal(url.searchParams.get('ledger_cursor'), 'ledger-one');
    assert.equal(url.searchParams.get('issuance_cursor'), 'issue-four');
    f.state.visit(f.props.first);
    url = new URL(f.calls[2][0], 'http://fixture.invalid');
    assert.equal(url.searchParams.has('ledger_cursor'), false);
    assert.equal(url.searchParams.get('issuance_cursor'), 'issue-four');
    assert.equal(url.searchParams.get('account'), 'new-account');
    assert.deepEqual(Array.from(f.calls[0][2].only), ['ledger', 'ledger_pages']);
    assert.equal(f.calls[0][2].preserveState, true);
    assert.equal(f.calls[0][2].preserveScroll, true);
    f.close();
});

test('busy pager refuses duplicate visits and an error remains recoverable from its own first page', async () => {
    const f = await fixture();
    f.state.visit(f.props.pages.next);
    const callbacks = f.calls[0][2];
    callbacks.onStart();
    assert.equal(f.state.busy, true);
    f.state.visit(f.props.pages.previous);
    assert.equal(f.calls.length, 1);
    callbacks.onError({ ledger_cursor: 'That history link expired.' }); callbacks.onFinish();
    assert.equal(f.state.error, 'That history link expired.');
    assert.equal(f.state.busy, false);
    f.state.visit(f.props.first); f.calls[1][2].onStart();
    assert.equal(f.state.error, '');
    f.calls[1][2].onFinish(); f.close();
});

test('a different record route never inherits the current record query', async () => {
    const f = await fixture();
    const destination = '/organizations/different/economy?ledger_cursor=other';
    assert.equal(f.state.currentUrl(destination), destination);
    f.close();
});

test('actual pager renders named navigation, controls and an announced loading region', async () => {
    const { html } = await fixture({}, true);
    assert.match(html, /aria-label="Ledger history"/);
    assert.match(html, /Previous/); assert.match(html, /Next/); assert.match(html, /First page/);
    assert.match(html, /role="status"/); assert.match(html, /aria-busy="false"/);
});
