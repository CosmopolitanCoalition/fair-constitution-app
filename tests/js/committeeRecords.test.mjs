import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';

const stub = name => ({
    props: ['disabled', 'href', 'label', 'only', 'title', 'form', 'first', 'pages'],
    setup(props, { slots }) {
        return () => Vue.h(name === 'Link' ? 'a' : name === 'Btn' ? 'button' : 'section',
            { disabled: props.disabled, href: props.href, 'data-component': name,
                'data-page-props': props.only?.join(','), 'aria-label': props.label },
            [props.title, slots.default?.(), slots.control?.({ id: 'fixture' })]);
    },
});
const node = () => ({ children: [] });
const renderer = Vue.createRenderer({ createElement: node, createText: node, createComment: node,
    setText() {}, setElementText() {}, patchProp() {}, remove() {}, parentNode: () => null, nextSibling: () => null,
    insert(child, parent) { parent.children.push(child); } });
function props() {
    return Vue.reactive({ surface: { forms: [{ id: 'F-CHR-004' }] },
        committee: { name: 'Public works', members: [], legislature: { name: 'Poland legislature', href: '/committees' } },
        meeting: { id: 'selected-hearing', status: 'open', agenda: [] }, meetingContext: { explicit: true, readOnly: false },
        bills: [{ id: 'bill-1', title: 'First bill', href: '/bills/bill-1?meeting=selected-hearing&jurisdiction=place', status: 'in_committee', report: null },
            { id: 'bill-2', title: 'Second bill', status: 'in_committee', report: null }],
        billPages: { first: '/committee?meeting=selected-hearing', next: '/committee?bills_cursor=bills-next' },
        reports: [{ id: 'report-1', title: 'A public report', href: '/committee?report=report-1&meeting=selected-hearing', excerpt: 'Report summary', bill: null }],
        reportPages: { first: '/committee?meeting=selected-hearing', next: '/committee?reports_cursor=reports-next' },
        testimony: [], testimonyPages: {}, selectedReport: null, can: { fileReport: true },
        urls: { current: '/committee', room: '/rooms/committee/selected-hearing', reports: '/committee/reports' } });
}
async function load(properties, inlineTemplate = false) {
    const calls = [];
    const page = { url: '/committee?meeting=selected-hearing&jurisdiction=place', props: {} };
    const source = await readFile(new URL('../../resources/js/Pages/Legislature/CommitteeDetail.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const code = compileScript(descriptor, { id: 'committee-records-fixture', inlineTemplate }).content;
    const context = vm.createContext({});
    const mod = new vm.SourceTextModule(code, { context });
    await mod.link(name => {
        let values;
        if (name === 'vue') values = Vue;
        else if (name === '@inertiajs/vue3') values = {
            Link: stub('Link'), router: { post: (...args) => calls.push(args) }, usePage: () => page,
            useForm: initial => Vue.reactive({ ...initial, errors: {}, processing: false,
                transform(callback) { this.transformer = callback; return this; },
                post(url, options) { calls.push({ url, data: this.transformer ? this.transformer(this) : this, options }); },
                reset() { Object.assign(this, initial); } }),
        };
        else values = { default: stub(name.split('/').at(-1).replace('.vue', '')) };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
    });
    await mod.evaluate();
    const component = mod.namespace.default;
    if (inlineTemplate) return { html: await renderToString(Vue.createSSRApp(component, properties)), calls };
    component.render = () => null;
    let instance;
    const app = renderer.createApp({ render: () => Vue.h(component, { ...properties, ref: value => { instance = value; } }) });
    app.mount(node());
    return { state: instance.$.setupState, calls, close: () => app.unmount() };
}

test('bill selection and draft survive pagination and submit the chosen off-page bill', async () => {
    const properties = props(); const fixture = await load(properties); const { state, calls } = fixture;
    state.reportForm.bill_id = 'bill-1'; state.reportForm.title = 'Draft'; state.reportForm.body = 'Retained draft body';
    await Vue.nextTick();
    properties.bills = [{ id: 'bill-21', title: 'Later bill', status: 'in_committee' }]; await Vue.nextTick();
    assert.equal(state.reportForm.bill_id, 'bill-1'); assert.equal(state.reportForm.title, 'Draft');
    assert.equal(state.reportBillOptions[0].id, 'bill-1'); assert.equal(state.reportBillOptions[1].id, 'bill-21');
    state.submitReport(); assert.equal(calls[0].data.bill_id, 'bill-1');
    assert.equal(calls[0].data.body, 'Retained draft body');
    calls[0].options.onSuccess(); await Vue.nextTick(); assert.equal(state.reportForm.bill_id, '');
    assert.equal(state.reportBillOptions.length, 1); fixture.close();
});

test('both pagers refresh every dependent directory link so alternating visits do not reuse stale other cursors', async () => {
    const { html } = await load(props(), true);
    const pagers = html.match(/data-component="HistoryPager"[^>]+/g);
    assert.equal(pagers.length, 3);
    for (const pager of pagers) assert.match(pager, /data-page-props="bills,billPages,reports,reportPages,selectedReport,testimonyPages"/);
    assert.match(html, /aria-label="Committee bill pages"/); assert.match(html, /aria-label="Committee report pages"/);
    assert.equal((html.match(/cursor-key="bills_cursor"/g) ?? []).length, 2);
    assert.equal((html.match(/cursor-key="reports_cursor"/g) ?? []).length, 1);
});

test('an exact historical report keeps its body and bill link while closed-hearing filing stays hidden', async () => {
    const properties = props(); properties.meetingContext.readOnly = true; properties.can.fileReport = false;
    properties.selectedReport = { id: 'report-old', title: 'Older report', body: 'Full body <script>inert</script>\nSecond paragraph',
        bill: { title: 'First bill', href: '/bills/bill-1?meeting=selected-hearing&jurisdiction=place' },
        close_href: '/committee?reports_cursor=old&meeting=selected-hearing', seq: 42, audit_seq: 99 };
    const { html } = await load(properties, true);
    assert.match(html, /id="committee-report-detail"/); assert.match(html, /Full body &lt;script&gt;inert&lt;\/script&gt;/);
    assert.match(html, /href="\/bills\/bill-1\?meeting=selected-hearing&amp;jurisdiction=place"/);
    assert.match(html, /href="\/rooms\/committee\/selected-hearing"/);
    assert.doesNotMatch(html, /data-component="FormCard"/); assert.match(html, /Audit entry 99/);
});

test('empty lists still expose recovery pagination and do not remove the report form', async () => {
    const properties = props(); properties.bills = []; properties.reports = [];
    properties.billPages.previous = '/previous-bills'; properties.reportPages.previous = '/previous-reports';
    const { html } = await load(properties, true);
    assert.match(html, /No bills on this page/); assert.match(html, /No reports on this page/);
    assert.match(html, /data-component="FormCard"/); assert.match(html, /Your chosen bill stays selected/);
});
