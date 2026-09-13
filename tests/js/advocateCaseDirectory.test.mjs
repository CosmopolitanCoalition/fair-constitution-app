// node --experimental-vm-modules --test tests/js/advocateCaseDirectory.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const id = n => `70000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const row = n => ({ id: id(n), title: 'Same case title', docket_no: `Docket ${n}`, status: 'paneled', state: 'Paneled', href: '/cases/' + id(n) });
const copy = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await Vue.nextTick(); await Vue.nextTick(); };
globalThis.document = { activeElement: null };
const source = readFileSync(new URL('../../resources/js/Pages/Judiciary/AdvocateConsole.vue', import.meta.url), 'utf8');
const exposed = 'defineExpose({ composerType, filingForm, selectedCaseLabel, directories, browseCases, chooseCase, clearCase, submitFiling });';
const { descriptor } = parse(source.replace('</script>', `${exposed}\n</script>`));
const compiled = compileScript(descriptor, { id: 'advocate-cases', inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } });

async function fixture(t, { registered = true } = {}) {
    const memory = new Map(), reads = [], filings = [], warnings = [];
    const page = Vue.reactive({ url: '/judiciary/advocate', props: { auth: { user: { id: id(900) } }, errors: {} } });
    const props = Vue.reactive({
        surface: { forms: [] },
        advocate: registered ? { id: id(800), is_registered: true, persona: { name: 'Test advocate' }, judiciary: { id: id(700), name: 'Test court' } } : null,
        can: { file: registered, isRegistered: registered },
        myCases: [], case_pages: { query: '', by: 'title', previous: null, next: '/roster-next', first: '/roster-first' },
        composer: { types: [
            { id: 'F-ADV-001', label: 'New case', hint: 'Open a case for a client.' },
            { id: 'F-ADV-002', label: 'Motion', hint: 'A motion on the docket.' },
            { id: 'F-ADV-003', label: 'Evidence', hint: 'Evidence for this case.' },
        ] },
        composer_cases: [], composer_case_pages: { query: '', by: 'title', loaded: false, previous: null, next: null, first: '/composer-first' },
    });
    const remember = (state, key) => {
        if (memory.has(key)) state.value = copy(memory.get(key));
        Vue.watch(state, () => memory.set(key, copy(Vue.unref(state))), { deep: true, immediate: true });
        return state;
    };
    const useForm = (key, initial) => {
        if (typeof key !== 'string') { initial = key; key = null; }
        const fields = Object.keys(initial);
        const form = Vue.reactive({ ...copy(initial), errors: {}, processing: false,
            clearErrors(...names) { if (!names.length) this.errors = {}; else for (const name of names) delete this.errors[name]; },
            setError(name, message) { if (typeof name === 'object') Object.assign(this.errors, name); else this.errors[name] = message; },
            reset(...names) { for (const field of names.length ? names : fields) this[field] = copy(initial[field]); },
            post() { throw new Error('Registration is outside this read/selection fixture.'); },
        });
        if (key) {
            if (memory.has(key)) Object.assign(form, copy(memory.get(key)));
            Vue.watch(form, () => memory.set(key, Object.fromEntries(fields.map(field => [field, copy(form[field])]))), { deep: true, immediate: true });
        }
        return form;
    };
    const Link = { props: ['href'], setup: (p, { slots }) => () => Vue.h('a', { href: p.href }, slots.default?.()) };
    const Card = { props: ['title'], setup: (p, { slots }) => () => Vue.h('section', [p.title ? Vue.h('h2', p.title) : null, slots.default?.()]) };
    const Field = { props: ['label'], setup: (p, { slots }) => () => Vue.h('label', [p.label, slots.control?.({ id: p.label, describedBy: null })]) };
    const context = vm.createContext({ console, URL });
    const module = new vm.SourceTextModule(compiled.content, { context });
    await module.link(name => {
        const values = name === 'vue' ? Vue : name === '@inertiajs/vue3' ? {
            Link, useForm, useRemember: remember, usePage: () => page,
            router: {
                get(url, data, options) { reads.push({ url, data, options }); options.onStart?.(); },
                post(url, data, options) { filings.push({ url, data: copy(data), options }); options.onStart?.(); },
            },
        } : { default: name.endsWith('/Field.vue') ? Field : Card };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
    });
    await module.evaluate();
    const element = (tag, text = '') => Vue.markRaw({ tag, text, children: [], parent: null, props: {}, addEventListener() {}, removeEventListener() {},
        get options() { return this.children.filter(child => child.tag === 'option'); },
    });
    const renderer = Vue.createRenderer({
        createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        insert(el, parent, anchor) {
            if (el.parent) { const at = el.parent.children.indexOf(el); if (at >= 0) el.parent.children.splice(at, 1); }
            el.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1;
            at < 0 ? parent.children.push(el) : parent.children.splice(at, 0, el);
        },
        remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
        patchProp(el, key, old, value) { el.props[key] = value; if (key === 'value' || key === 'type' || key === 'checked') el[key] = value; },
        setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const root = element('root'); let app, state;
    function mount() {
        app = renderer.createApp({ setup: () => () => Vue.h(module.namespace.default, { ...props, ref: value => { if (value) state = value; } }) });
        app.config.warnHandler = warning => warnings.push(warning);
        app.mount(root);
    }
    mount(); await flush(); t.after(() => app.unmount());
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    return { props, page, reads, filings, warnings, get state() { return state; },
        text: () => text(root),
        selected: () => nodes().filter(el => el.props.class === 'selected-case').map(text).join(' '),
        links: () => nodes().filter(el => el.tag === 'a').map(el => el.props.href),
        button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label),
        async respond(rows = [row(1)], metadata = {}) {
            props.composer_cases = rows;
            props.composer_case_pages = { ...props.composer_case_pages, loaded: true, ...metadata };
            reads.at(-1)?.options.onFinish(); await flush();
        },
        async remount() { app.unmount(); mount(); await flush(); },
    };
}

test('entry does not fetch composer cases; opening an existing-case filing fetches only its choices', async t => {
    const f = await fixture(t);
    assert.equal(f.reads.length, 0);
    assert.equal(f.state.filingForm.case_id, '');
    f.state.composerType = 'F-ADV-003'; await flush();
    assert.equal(f.reads.length, 1);
    assert.deepEqual([...f.reads[0].options.only], ['composer_cases', 'composer_case_pages']);
    assert.match(f.text(), /Loading cases for this filing/);
    assert.equal(f.button('Find a case').props.disabled, true);
    f.state.browseCases('composer'); assert.equal(f.reads.length, 1);
    await f.respond([row(1), row(2)]);
    assert.equal(f.state.filingForm.case_id, '', 'Loading a page must never select its first case implicitly.');
    f.button('Select case').props.onClick(); await flush();
    assert.equal(f.state.filingForm.case_id, id(1));
    assert.ok(f.links().includes('/cases/' + id(1)));
    assert.deepEqual(f.warnings, []);
});

test('same-title selection and complete draft survive case paging, roster searches and navigation back', async t => {
    const f = await fixture(t);
    f.state.composerType = 'F-ADV-003'; await flush(); await f.respond();
    f.state.chooseCase(row(1)); f.state.filingForm.title = 'Witness statement'; f.state.filingForm.body = 'Keep these words'; await flush();
    f.state.browseCases('composer', '/composer-next'); await f.respond([row(2)], { previous: '/composer-first' });
    assert.match(f.selected(), new RegExp(id(1))); assert.doesNotMatch(f.selected(), new RegExp(id(2)));
    f.state.directories.roster.query = 'Another'; f.state.browseCases('roster'); await flush();
    assert.deepEqual([...f.reads.at(-1).options.only], ['myCases', 'case_pages']);
    assert.equal(new URL(f.reads.at(-1).url, 'http://localhost').searchParams.get('case_q'), 'Another');
    f.reads.at(-1).options.onFinish(); await flush();
    await f.remount();
    assert.equal(f.state.composerType, 'F-ADV-003'); assert.equal(f.state.filingForm.case_id, id(1));
    assert.equal(f.state.filingForm.title, 'Witness statement'); assert.equal(f.state.filingForm.body, 'Keep these words');
    assert.match(f.selected(), /Docket 1/);
    f.state.submitFiling();
    assert.equal(f.filings[0].url, '/cases/' + id(1) + '/filings');
    assert.deepEqual(f.filings[0].data, { form_id: 'F-ADV-003', title: 'Witness statement', body: 'Keep these words' });
});

test('failed case reads show retry feedback without changing selection or draft', async t => {
    const f = await fixture(t);
    f.state.composerType = 'F-ADV-002'; await flush(); await f.respond();
    f.state.chooseCase(row(1)); f.state.filingForm.body = 'Draft survives'; await flush();
    f.state.browseCases('composer', '/invalid-cursor');
    f.reads.at(-1).options.onError({ compose_case_cursor: 'This case page link is invalid. Search again.' });
    f.reads.at(-1).options.onFinish(); await flush();
    assert.match(f.text(), /This case page link is invalid/);
    assert.equal(f.state.filingForm.case_id, id(1)); assert.equal(f.state.filingForm.body, 'Draft survives');
    assert.equal(f.button('Find a case').props.disabled, false);
    f.state.browseCases('composer'); await f.respond([]);
    assert.doesNotMatch(f.text(), /This case page link is invalid/);
    assert.match(f.text(), /No matching cases on this page/);
    assert.match(f.selected(), new RegExp(id(1)));
});

test('missing selection refuses locally; engine-style rejection keeps the exact case and filing draft', async t => {
    const f = await fixture(t);
    f.state.composerType = 'F-ADV-003'; await flush(); await f.respond([{ ...row(1), status: 'closed', state: 'Closed' }]);
    f.state.submitFiling(); await flush();
    assert.equal(f.filings.length, 0); assert.match(f.text(), /Choose the case this filing belongs to/);
    f.state.chooseCase({ ...row(1), status: 'closed', state: 'Closed' });
    f.state.filingForm.title = 'Evidence'; f.state.filingForm.body = 'Submission';
    f.state.submitFiling(); await flush();
    assert.equal(f.filings[0].url, '/cases/' + id(1) + '/filings');
    assert.equal(f.button('Submitting…').props.disabled, true);
    f.filings[0].options.onError({ constitution: 'The docket is closed.' });
    f.page.props.errors = { constitution: 'The docket is closed.' };
    f.filings[0].options.onFinish(); await flush();
    assert.match(f.text(), /The docket is closed/);
    assert.equal(f.state.filingForm.case_id, id(1)); assert.equal(f.state.filingForm.title, 'Evidence');
    assert.equal(f.state.filingForm.body, 'Submission');
});

test('new case endpoint is unchanged and successful append clears text but retains the chosen case', async t => {
    const f = await fixture(t);
    f.state.filingForm.client = 'Client'; f.state.filingForm.title = 'New case'; f.state.filingForm.body = 'Claim';
    f.state.submitFiling();
    assert.equal(f.filings[0].url, '/judiciaries/' + id(700) + '/cases');
    assert.deepEqual(f.filings[0].data, { form_id: 'F-ADV-001', judiciary_id: id(700), title: 'New case', statement_of_claim: 'Claim', client: 'Client' });
    f.filings[0].options.onSuccess(); f.filings[0].options.onFinish();
    f.state.composerType = 'F-ADV-002'; await flush(); await f.respond();
    f.state.chooseCase(row(1)); f.state.filingForm.title = 'Motion'; f.state.filingForm.body = 'Request'; f.state.submitFiling();
    f.filings[1].options.onSuccess(); f.filings[1].options.onFinish(); await flush();
    assert.equal(f.state.filingForm.title, ''); assert.equal(f.state.filingForm.body, '');
    assert.equal(f.state.filingForm.case_id, id(1));
    f.state.clearCase(); await flush(); assert.equal(f.selected(), '');
});

test('another advocate registration cannot inherit the saved case or draft', async t => {
    const f = await fixture(t);
    f.state.composerType = 'F-ADV-003'; await flush(); await f.respond();
    f.state.chooseCase(row(1)); f.state.filingForm.body = 'Private draft'; await flush();
    f.props.advocate = { ...f.props.advocate, id: id(801) }; await f.remount();
    assert.equal(f.state.filingForm.case_id, ''); assert.equal(f.state.filingForm.body, '');
    assert.equal(f.state.composerType, 'F-ADV-001');
});

test('unregistered viewer cannot trigger case-directory reads or filings', async t => {
    const f = await fixture(t, { registered: false });
    f.state.composerType = 'F-ADV-003'; await flush();
    f.state.browseCases('composer'); f.state.browseCases('roster'); f.state.submitFiling();
    assert.equal(f.reads.length, 0); assert.equal(f.filings.length, 0);
});

test('search and stale paging links update only their own directory URL parameters', async t => {
    const f = await fixture(t);
    f.page.url = '/judiciary/advocate?filings_cursor=filings-page&case_q=Old&case_by=title&case_cursor=roster-page&compose_case_q=Docket&compose_case_by=docket&compose_case_cursor=choice-page';
    f.state.directories.roster.query = 'New'; f.state.browseCases('roster');
    let params = new URL(f.reads.at(-1).url, 'http://localhost').searchParams;
    assert.equal(params.get('case_q'), 'New'); assert.equal(params.has('case_cursor'), false);
    assert.equal(params.get('compose_case_cursor'), 'choice-page'); assert.equal(params.get('compose_case_q'), 'Docket');
    assert.equal(params.get('filings_cursor'), 'filings-page');
    f.reads.at(-1).options.onFinish();
    f.state.browseCases('composer', '/judiciary/advocate?compose_case_q=Fresh&compose_case_by=title&compose_case_cursor=next-choice&case_cursor=stale-roster');
    params = new URL(f.reads.at(-1).url, 'http://localhost').searchParams;
    assert.equal(params.get('compose_case_cursor'), 'next-choice'); assert.equal(params.get('compose_case_q'), 'Fresh');
    assert.equal(params.get('case_cursor'), 'roster-page'); assert.equal(params.get('case_q'), 'Old');
    assert.equal(params.get('filings_cursor'), 'filings-page');
    f.reads.at(-1).options.onFinish();
    f.state.browseCases('composer', '/judiciary/advocate?compose_case_q=&compose_case_by=title');
    params = new URL(f.reads.at(-1).url, 'http://localhost').searchParams;
    assert.equal(params.has('compose_case_cursor'), false); assert.equal(params.get('case_cursor'), 'roster-page');
    assert.equal(params.get('filings_cursor'), 'filings-page');
});
