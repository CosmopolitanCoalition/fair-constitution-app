<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import { formatMoney, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    currency: { type: Object, default: null },
    jurisdictionContext: { type: Object, default: null },
    finance_scope: { type: Object, default: () => ({}) },
    accounts: { type: Array, default: () => [] }, accounts_pages: { type: Object, default: () => ({}) },
    ledger: { type: Array, default: () => [] }, ledger_pages: { type: Object, default: () => ({}) },
    issuance: { type: Array, default: () => [] }, issuance_pages: { type: Object, default: () => ({}) },
    budgets: { type: Array, default: () => [] }, budgets_pages: { type: Object, default: () => ({}) },
    budget_lines: { type: Array, default: () => [] }, budget_lines_pages: { type: Object, default: () => ({}) },
    revenue: { type: Array, default: () => [] }, revenue_pages: { type: Object, default: () => ({}) },
    levies: { type: Array, default: () => [] }, levies_pages: { type: Object, default: () => ({}) },
    borrowings: { type: Array, default: () => [] }, borrowings_pages: { type: Object, default: () => ({}) },
    places: { type: Array, default: () => [] }, places_pages: { type: Object, default: () => ({}) },
    clock: { type: Object, default: () => ({}) },
    totals: { type: Object, default: () => ({}) },
    report: { type: Object, default: () => ({}) },
});
const page = usePage();
const placeUrl = id => `/economy/treasury?jurisdiction=${encodeURIComponent(id)}`;
function selectionUrl(changes) {
    const params = new URLSearchParams((page.url ?? '').split('?')[1] ?? '');
    if (props.finance_scope.place?.id) params.set('jurisdiction', props.finance_scope.place.id);
    for (const [key, value] of Object.entries(changes)) {
        if (value === null) params.delete(key);
        else params.set(key, value);
    }
    return `/economy/treasury?${params}`;
}
const accountUrl = id => selectionUrl({ account: id, ledger_scope: 'account', ledger_cursor: null });
const budgetUrl = id => selectionUrl({ budget: id, lines_cursor: null });
const revenueUrl = id => selectionUrl({ revenue_source: id, levies_cursor: null });
const ledgerColumns = [
    { key: 'seq', label: '#' }, { key: 'when', label: 'When' }, { key: 'direction', label: 'Debit / credit' },
    { key: 'amount', label: 'Amount' }, { key: 'kind', label: 'Kind' },
    { key: 'account', label: 'Account', mono: true }, { key: 'hash', label: 'Hash', mono: true },
];
const ledgerRows = () => props.ledger.map(e => ({ id: e.seq, seq: e.seq, when: formatWhen(e.at),
    direction: e.direction === 'debit' ? 'Debit' : 'Credit', amount: formatMoney(e.amount, props.currency),
    kind: e.kind?.replaceAll('_', ' ') ?? '—', account: shortId(e.account_id), hash: shortId(e.hash) }));
const accountColumns = [{ key: 'label', label: 'Account' }, { key: 'owner', label: 'Belongs to' }, { key: 'balance', label: 'Balance' }];
const accountRows = () => props.accounts.map(a => ({ id: a.id, label: a.label || (a.owner_type === 'departments' ? 'Department treasury' : 'Jurisdiction treasury'),
    owner: a.owner_type === 'departments' ? 'Department' : props.finance_scope.place?.name ?? 'Jurisdiction', balance: formatMoney(a.balance, props.currency) }));
const issuanceColumns = [{ key: 'when', label: 'When' }, { key: 'direction', label: 'Issued / withdrawn' }, { key: 'amount', label: 'Amount' }, { key: 'reason', label: 'Reason' }];
const issuanceRows = () => props.issuance.map(i => ({ id: i.id, when: formatWhen(i.at), direction: i.direction === 'burn' ? 'Withdrawn' : 'Issued',
    amount: formatMoney(i.amount, props.currency), reason: i.reason }));
</script>

<template>
    <PageScaffold title="Public finance">
        <template #intro>Browse public accounts, budgets and revenue for a place. The currency ledger identifies accounts without identifying the people behind them.</template>
        <Banner v-if="!currency" tone="info" title="No currency yet">There is no public money to account for until a currency exists.</Banner>

        <Card as="section" :title="finance_scope.place ? `Public finance in ${finance_scope.place.name}` : 'Choose a place'">
            <nav v-if="jurisdictionContext?.chain?.length" aria-label="Public finance by place" class="finance-navigation">
                <Link v-for="place in jurisdictionContext.chain" :key="place.id" :href="placeUrl(place.id)"
                    :aria-current="place.id === finance_scope.place?.id ? 'page' : undefined">{{ place.name }}</Link>
            </nav>
            <p class="econ-note">Choose a place inside this jurisdiction to inspect its own public finances.</p>
            <ul v-if="places.length" class="finance-places">
                <li v-for="place in places" :key="place.id"><Link :href="placeUrl(place.id)">{{ place.name }}</Link></li>
            </ul>
            <p v-else class="econ-note">No smaller places on this page.</p>
            <HistoryPager cursor-key="places_cursor" :pages="places_pages" :only="['places', 'places_pages']" :first="places_pages.first ?? '/economy/treasury'" label="Places" />
            <Link href="/jurisdictions">Browse the world map and place directory</Link>
        </Card>

        <Card as="section" title="Currency totals">
            <div class="econ-stats">
                <Stat :value="formatMoney(totals.supply, currency)" label="Money issued, less withdrawals" />
                <Stat :value="formatMoney(totals.treasury_balance, currency)" label="Held by all treasuries" accent />
            </div>
            <p v-if="report.completed_at" class="econ-note">Last completed currency report: {{ formatWhen(report.completed_at) }}. These totals cover the whole currency, including other places.</p>
            <p v-else class="econ-note">No completed currency report is available. Totals appear after a report completes.</p>
            <p v-if="report.status === 'running'" class="econ-note" role="status">A newer report is being collected.</p>
            <p v-if="report.status === 'failed'" class="econ-note">The latest report did not finish; any totals shown come from the last completed report.</p>
            <Link href="/economy/units">Open currency reports</Link>
            <p v-if="clock.next_run" class="econ-note">The next civic-stipend disbursement is due {{ formatWhen(clock.next_run) }}.</p>
        </Card>

        <Card as="section" title="Public accounts">
            <DataTable v-if="accounts.length" :columns="accountColumns" :rows="accountRows()" row-key="id" caption="Public accounts in the selected place">
                <template #cell-label="{ row, value }"><Link :href="accountUrl(row.id)" :only="['finance_scope', 'ledger', 'ledger_pages']" preserve-scroll preserve-state
                    :aria-current="finance_scope.account?.id === row.id && finance_scope.ledger_scope === 'account' ? 'true' : undefined">{{ value }}</Link></template>
            </DataTable>
            <p v-else class="econ-note">No public accounts on this page for this place.</p>
            <HistoryPager cursor-key="accounts_cursor" :pages="accounts_pages" :only="['accounts', 'accounts_pages']" :first="accounts_pages.first ?? '/economy/treasury'" label="Public accounts" />
        </Card>

        <Card as="section" title="The public ledger">
            <nav aria-label="Ledger scope" class="finance-navigation">
                <Link v-if="finance_scope.account" :href="accountUrl(finance_scope.account.id)" :only="['finance_scope', 'ledger', 'ledger_pages']" preserve-scroll preserve-state
                    :aria-current="finance_scope.ledger_scope === 'account' ? 'page' : undefined">{{ finance_scope.account.label || 'Selected public account' }}</Link>
                <Link :href="selectionUrl({ ledger_scope: 'currency', ledger_cursor: null })" :only="['finance_scope', 'ledger', 'ledger_pages']" preserve-scroll preserve-state
                    :aria-current="finance_scope.ledger_scope === 'currency' ? 'page' : undefined">All currency accounts</Link>
            </nav>
            <p class="econ-note">{{ finance_scope.ledger_scope === 'currency' ? 'All account movements in this currency, including pseudonymous economic accounts.' : 'Movements for the selected public account.' }}</p>
            <DataTable v-if="ledger.length" :columns="ledgerColumns" :rows="ledgerRows()" row-key="id" caption="Ledger entries, newest first" />
            <p v-else class="econ-note">{{ finance_scope.ledger_scope === 'account' && !finance_scope.account ? 'Choose a public account, or browse all currency accounts.' : 'No entries on this page.' }}</p>
            <HistoryPager cursor-key="ledger_cursor" :pages="ledger_pages" :only="['ledger', 'ledger_pages']" :first="ledger_pages.first ?? '/economy/treasury'" label="Ledger history" />
            <p class="econ-note">Ledger entries are append-only and hash-chained. This page shows recorded movements; it does not run a chain verification.</p>
        </Card>

        <Card as="section" title="Money issued and withdrawn">
            <p class="econ-note">Issuance history covers the whole currency.</p>
            <DataTable v-if="issuance.length" :columns="issuanceColumns" :rows="issuanceRows()" row-key="id" caption="Currency issuance history, newest first" />
            <p v-else class="econ-note">No issuance events on this page.</p>
            <HistoryPager cursor-key="issuance_cursor" :pages="issuance_pages" :only="['issuance', 'issuance_pages']" :first="issuance_pages.first ?? '/economy/treasury'" label="Issuance history" />
        </Card>

        <Card as="section" title="Budgets">
            <ul v-if="budgets.length" class="econ-list">
                <li v-for="budget in budgets" :key="budget.id">
                    <Link :href="budgetUrl(budget.id)" :only="['finance_scope', 'budget_lines', 'budget_lines_pages']" preserve-scroll preserve-state>{{ budget.fiscal_label }}</Link>
                    — {{ formatMoney(budget.total, currency) }} · {{ budget.status }}
                    <span v-if="budget.is_current" class="econ-badge">Current</span>
                    <p v-if="budget.enacting_act" class="econ-note">{{ budget.enacting_act.act_number ? `Act ${budget.enacting_act.act_number}: ` : '' }}{{ budget.enacting_act.title }}</p>
                </li>
            </ul>
            <p v-else class="econ-note">No budgets on this page for this place.</p>
            <HistoryPager cursor-key="budgets_cursor" :pages="budgets_pages" :only="['budgets', 'budgets_pages']" :first="budgets_pages.first ?? '/economy/treasury'" label="Budgets" />
            <section v-if="finance_scope.budget" aria-labelledby="budget-lines-title">
                <h3 id="budget-lines-title">Spending lines: {{ finance_scope.budget.fiscal_label }}</h3>
                <ul class="econ-list"><li v-for="line in budget_lines" :key="line.id">{{ line.line }} — {{ formatMoney(line.amount, currency) }}</li></ul>
                <p v-if="!budget_lines.length" class="econ-note">No spending lines on this page.</p>
                <HistoryPager cursor-key="lines_cursor" :pages="budget_lines_pages" :only="['budget_lines', 'budget_lines_pages']" :first="budget_lines_pages.first ?? '/economy/treasury'" label="Budget spending lines" />
            </section>
            <p v-else class="econ-note">Select a budget to read its spending lines.</p>
        </Card>

        <Card as="section" title="Borrowing">
            <ul v-if="borrowings.length" class="econ-list"><li v-for="borrowing in borrowings" :key="borrowing.id">
                <strong>{{ formatMoney(borrowing.principal, currency) }}</strong> · {{ borrowing.status }}
                <span v-if="borrowing.lender_account_id" class="econ-note"> · lender account {{ shortId(borrowing.lender_account_id) }}</span>
                <span v-if="borrowing.at" class="econ-note"> · {{ formatWhen(borrowing.at) }}</span>
                <p>{{ borrowing.terms }}</p>
            </li></ul>
            <p v-else class="econ-note">No borrowing records on this page for this place.</p>
            <HistoryPager cursor-key="borrowings_cursor" :pages="borrowings_pages" :only="['borrowings', 'borrowings_pages']" :first="borrowings_pages.first ?? '/economy/treasury'" label="Borrowing history" />
        </Card>

        <Card as="section" title="Where the money comes from">
            <ul v-if="revenue.length" class="econ-list"><li v-for="source in revenue" :key="source.id">
                <Link :href="revenueUrl(source.id)" :only="['finance_scope', 'levies', 'levies_pages']" preserve-scroll preserve-state>{{ source.name }}</Link> · {{ source.kind }} · {{ source.status }}
                <p v-if="source.enacting_act" class="econ-note">{{ source.enacting_act.act_number ? `Act ${source.enacting_act.act_number}: ` : '' }}{{ source.enacting_act.title }}</p>
            </li></ul>
            <p v-else class="econ-note">No revenue sources on this page for this place.</p>
            <HistoryPager cursor-key="revenue_cursor" :pages="revenue_pages" :only="['revenue', 'revenue_pages']" :first="revenue_pages.first ?? '/economy/treasury'" label="Revenue sources" />
            <section v-if="finance_scope.revenue_source" aria-labelledby="levies-title">
                <h3 id="levies-title">Levies: {{ finance_scope.revenue_source.name }}</h3>
                <ul class="econ-list"><li v-for="levy in levies" :key="levy.id">{{ levy.rate }} on {{ levy.base.replaceAll('_', ' ') }}<template v-if="levy.civic_exempt"> · civic use exempt</template></li></ul>
                <p v-if="!levies.length" class="econ-note">No levies on this page.</p>
                <HistoryPager cursor-key="levies_cursor" :pages="levies_pages" :only="['levies', 'levies_pages']" :first="levies_pages.first ?? '/economy/treasury'" label="Levies" />
            </section>
            <p v-else class="econ-note">Select a revenue source to read its levies.</p>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.econ-stats, .finance-navigation { display: flex; flex-wrap: wrap; gap: var(--space-4); }
.finance-navigation [aria-current] { font-weight: 700; text-decoration-thickness: 2px; }
.finance-places { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(14rem, 100%), 1fr)); gap: var(--space-2); padding-inline-start: 1.25rem; }
.econ-note { font-size: 0.875rem; color: var(--gov-text-muted); }
.econ-list { padding-inline-start: 1.25rem; }
.econ-list li { margin-block-end: var(--space-2); }
.econ-badge { display: inline-block; margin-inline-start: var(--space-2); padding: 0.05rem 0.4rem; border-radius: var(--radius-sm); background: var(--gov-accent-soft); color: var(--gov-accent); font-size: 0.75rem; }
</style>
