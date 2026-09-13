import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as money from '../../resources/js/lib/money.js';

const base = new URL('../../resources/js/', import.meta.url);
async function render(overrides = {}) {
    const context = vm.createContext({ URLSearchParams });
    const modules = new Map();
    const synthetic = values => new vm.SyntheticModule(Object.keys(values), function () {
        for (const [key, value] of Object.entries(values)) this.setExport(key, value);
    }, { context });
    const Link = { props: ['href', 'only'], setup: (props, { slots }) => () => Vue.h('a', { href: props.href, 'data-only': props.only?.join(',') }, slots.default?.()) };
    const plain = { props: ['title'], setup: (props, { slots }) => () => Vue.h('section', [Vue.h('h2', props.title), slots.intro?.(), slots.default?.()]) };
    async function load(path) {
        if (modules.has(path)) return modules.get(path);
        const source = await readFile(new URL(path, base), 'utf8');
        const { descriptor } = parse(source);
        const mod = new vm.SourceTextModule(compileScript(descriptor, { id: path, inlineTemplate: true }).content, { context });
        modules.set(path, mod);
        await mod.link(async name => {
            if (name === 'vue') return synthetic(Vue);
            if (name === '@inertiajs/vue3') return synthetic({ Link, usePage: () => ({ url: '/economy/treasury?jurisdiction=place&budgets_cursor=old-budget-page' }), router: { get() {} } });
            if (name.endsWith('/money.js')) return synthetic(money);
            if (name.endsWith('/HistoryPager.vue') || name.endsWith('/DataTable.vue')) return load(name.slice(2));
            if (name.endsWith('/Stat.vue')) return synthetic({ default: { props: ['value', 'label'], setup: props => () => Vue.h('div', `${props.label}: ${props.value}`) } });
            return synthetic({ default: plain });
        });
        return mod;
    }
    const mod = await load('Pages/Economy/Treasury.vue'); await mod.evaluate();
    const fixture = {
        currency: { symbol: 'F', precision: 6 },
        jurisdictionContext: { chain: [{ id: 'world', name: 'World' }, { id: 'place', name: 'Fixture place' }] },
        finance_scope: { place: { id: 'place', name: 'Fixture place' }, account: { id: 'account', label: 'Public treasury' }, ledger_scope: 'account' },
        places: [{ id: 'child', name: 'Child place' }],
        accounts: [{ id: 'account', label: 'Public treasury', owner_type: 'jurisdictions', balance: '999999999999999999.123456' }],
        budgets: [{ id: 'budget', fiscal_label: 'Current budget', total: '1.000001', status: 'enacted' }],
        revenue: [{ id: 'revenue', name: 'Public revenue', kind: 'levy', status: 'active' }],
        totals: { supply: null, treasury_balance: null }, report: { status: 'not_started', completed_at: null },
    };
    return (await renderToString(Vue.createSSRApp(mod.namespace.default, { ...fixture, ...overrides }))).replace(/<!--[\s\S]*?-->/g, '');
}

test('finance renders navigable places and selections retaining unrelated history context', async () => {
    const html = await render();
    assert.match(html, /href="\/economy\/treasury\?jurisdiction=world"[^>]*>World/);
    assert.match(html, /href="\/economy\/treasury\?jurisdiction=child"[^>]*>Child place/);
    assert.match(html, /href="[^"]*budgets_cursor=old-budget-page[^"]*account=account[^"]*" data-only="finance_scope,ledger,ledger_pages"/);
    assert.match(html, /data-only="finance_scope,budget_lines,budget_lines_pages"/);
    assert.match(html, /data-only="finance_scope,levies,levies_pages"/);
    assert.match(html, /F999,999,999,999,999,999\.123456/);
    assert.match(html, /All currency accounts/);
    assert.match(html, /No completed currency report is available/);
    assert.doesNotMatch(html, /what you hold is seen by no one|Private|checkable on the economy overview/);
});

test('actual pagers expose every selected history and report snapshots retain their date', async () => {
    const history = { previous: '/previous', next: '/next', first: '/first' };
    const props = Object.fromEntries(['accounts', 'ledger', 'issuance', 'budgets', 'budget_lines', 'borrowings', 'revenue', 'levies', 'places'].map(key => [`${key}_pages`, history]));
    const html = await render({ ...props,
        finance_scope: { place: { id: 'place', name: 'Fixture place' }, ledger_scope: 'currency', budget: { id: 'budget', fiscal_label: 'Selected budget' }, revenue_source: { id: 'revenue', name: 'Selected source' } },
        budget_lines: [{ id: 'line', line: 'Schools', amount: '2.123456' }], levies: [{ id: 'levy', base: 'per_capita', rate: '0.123456', civic_exempt: true }],
        totals: { supply: '3.123456', treasury_balance: '1.123456' }, report: { status: 'running', completed_at: '2026-09-12T12:00:00Z' },
    });
    for (const label of ['Places', 'Public accounts', 'Ledger history', 'Issuance history', 'Budgets', 'Budget spending lines', 'Borrowing history', 'Revenue sources', 'Levies']) {
        assert.match(html, new RegExp(`aria-label="${label}"[^>]*>.*?Previous.*?Next.*?First page`, 's'));
    }
    assert.match(html, /Schools — F2\.123456/); assert.match(html, /0\.123456 on per capita/);
    assert.match(html, /Last completed currency report:/); assert.match(html, /A newer report is being collected/);
    assert.match(html, /including pseudonymous economic accounts/);
});

test('empty installation and missing public account render without invented money or inaccessible controls', async () => {
    const html = await render({ currency: null, accounts: [], places: [], finance_scope: { place: null, account: null, ledger_scope: 'account' } });
    assert.match(html, /No currency yet/); assert.match(html, /Choose a public account, or browse all currency accounts/);
    assert.match(html, /Money issued, less withdrawals: —/); assert.doesNotMatch(html, /NaN|undefined|null/);
});
