import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';

// W-0222 part 3: the Record tab carries an "open the full record" link.
// The link is self only: the full record at /civic/record is the viewer's
// own private half, so a stranger's Record tab must not show it.

const stub = name => ({
    props: ['disabled', 'href', 'label', 'only', 'title', 'form', 'first', 'pages', 'as', 'variant', 'size', 'tone', 'name', 'error', 'hint'],
    setup(props, { slots }) {
        const tag = name === 'Link' ? 'a' : name === 'Btn' ? 'button' : 'section';
        return () => Vue.h(tag,
            { href: props.href, 'data-component': name, 'data-page-props': props.only?.join(','), 'aria-label': props.label },
            [props.title, slots.intro?.(), slots.default?.(), slots.control?.({ id: 'fixture', invalid: false, describedBy: '' })]);
    },
});

function moduleFor(name, context) {
    let values;
    if (name === 'vue') {
        values = Vue;
    } else if (name === '@inertiajs/vue3') {
        const form = initial => Vue.reactive({
            ...initial, errors: {}, processing: false,
            defaults() { return this; }, reset() { return this; },
            transform(callback) { this.transformer = callback; return this; },
            post() {}, patch() {}, delete() {},
        });
        values = { Link: stub('Link'), router: { get() {}, post() {}, delete() {} }, useForm: form,
            usePage: () => ({ url: '/people?tab=record', props: { flash: {}, errors: {}, auth: { user: { id: 'u1' } } } }) };
    } else if (name === 'vue-i18n') {
        values = { useI18n: () => ({ t: key => key }) };
    } else if (name.endsWith('achievementTitle')) {
        values = { achievementTitle: () => '' };
    } else {
        values = { default: stub(name.split('/').at(-1).replace('.vue', '')) };
    }
    return new vm.SyntheticModule(Object.keys(values),
        function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); },
        { context });
}

async function render(overrides = {}) {
    const source = await readFile(new URL('../../resources/js/Pages/Social/PersonProfile.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const code = compileScript(descriptor, { id: 'person-profile-record-fixture', inlineTemplate: true }).content;
    const context = vm.createContext({});
    const mod = new vm.SourceTextModule(code, { context });
    await mod.link(name => moduleFor(name, context));
    await mod.evaluate();

    const properties = {
        surface: { forms: [] },
        tab: 'record',
        tabs: ['overview', 'record'],
        isSelf: true,
        canMessage: false,
        editable: { display_name: '', handle: '', bio: '', visibility: 'public' },
        person: { id: 'u1', display: 'Someone', initials: 'S', handle: null, bio: null, office: null, home: null, followCounts: null, visibility: 'public' },
        follow: { canFollow: false, isFollowing: false },
        candidacies: [], candidacyPanel: null,
        endorsementOrganizations: null, endorsementIndividuals: null, endorsementWeb: null, endorsementRequests: null, endorsementsGiven: null,
        offices: [],
        record: { actions: [], associations: [], endorsementsGiven: [] },
        achievements: null, actionHistory: null, publications: null, officeHistory: null,
        ...overrides,
    };
    return renderToString(Vue.createSSRApp(mod.namespace.default, properties));
}

test('the Record tab shows an open-full-record link on your own profile', async () => {
    const html = await render({ isSelf: true });
    assert.match(html, /data-testid="open-full-record"/);
    assert.match(html, /Open the full record/);
    assert.match(html, /href="\/civic\/record"/);
});

test('a stranger profile hides the open-full-record link', async () => {
    const html = await render({ isSelf: false, editable: null });
    assert.doesNotMatch(html, /data-testid="open-full-record"/);
    assert.doesNotMatch(html, /Open the full record/);
});
