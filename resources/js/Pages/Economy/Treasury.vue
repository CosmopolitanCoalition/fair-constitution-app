<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import { formatMoney, formatWhen as formatWhenRaw, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t, locale } = useI18n();
const formatWhen = (iso) => formatWhenRaw(iso, locale.value);
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
    { key: 'seq', label: t('c_economy.treasury.col_seq', '#') }, { key: 'when', label: t('c_economy.treasury.col_when', 'When') }, { key: 'direction', label: t('c_economy.treasury.col_debit_credit', 'Debit / credit') },
    { key: 'amount', label: t('c_economy.treasury.col_amount', 'Amount') }, { key: 'kind', label: t('c_economy.treasury.col_kind', 'Kind') },
    { key: 'account', label: t('c_economy.treasury.col_account', 'Account'), mono: true }, { key: 'hash', label: t('c_economy.treasury.col_hash', 'Hash'), mono: true },
];
const ledgerRows = () => props.ledger.map(e => ({ id: e.seq, seq: e.seq, when: formatWhen(e.at),
    direction: e.direction === 'debit' ? t('c_economy.treasury.row_debit', 'Debit') : t('c_economy.treasury.row_credit', 'Credit'), amount: formatMoney(e.amount, props.currency),
    kind: e.kind?.replaceAll('_', ' ') ?? '—', account: shortId(e.account_id), hash: shortId(e.hash) }));
const accountColumns = [{ key: 'label', label: t('c_economy.treasury.col_account', 'Account') }, { key: 'owner', label: t('c_economy.treasury.col_belongs_to', 'Belongs to') }, { key: 'balance', label: t('c_economy.treasury.col_balance', 'Balance') }];
const accountRows = () => props.accounts.map(a => ({ id: a.id, label: a.label || (a.owner_type === 'departments' ? t('c_economy.treasury.department_treasury', 'Department treasury') : t('c_economy.treasury.jurisdiction_treasury', 'Jurisdiction treasury')),
    owner: a.owner_type === 'departments' ? t('c_economy.treasury.department', 'Department') : props.finance_scope.place?.name ?? t('c_economy.treasury.jurisdiction', 'Jurisdiction'), balance: formatMoney(a.balance, props.currency) }));
const issuanceColumns = [{ key: 'when', label: t('c_economy.treasury.col_when', 'When') }, { key: 'direction', label: t('c_economy.treasury.col_issued_withdrawn', 'Issued / withdrawn') }, { key: 'amount', label: t('c_economy.treasury.col_amount', 'Amount') }, { key: 'reason', label: t('c_economy.treasury.col_reason', 'Reason') }];
const issuanceRows = () => props.issuance.map(i => ({ id: i.id, when: formatWhen(i.at), direction: i.direction === 'burn' ? t('c_economy.treasury.row_withdrawn', 'Withdrawn') : t('c_economy.treasury.row_issued', 'Issued'),
    amount: formatMoney(i.amount, props.currency), reason: i.reason }));
</script>

<template>
    <PageScaffold :title="t('c_economy.treasury.title', 'Public finance')">
        <template #intro>{{ t('c_economy.treasury.intro', 'Browse public accounts, budgets and revenue for a place. The currency ledger identifies accounts without identifying the people behind them.') }}</template>
        <Banner v-if="!currency" tone="info" :title="t('c_economy.treasury.no_currency_title', 'No currency yet')">{{ t('c_economy.treasury.no_currency_body', 'There is no public money to account for until a currency exists.') }}</Banner>

        <Card as="section" :title="finance_scope.place ? t('c_economy.treasury.finance_in', { name: finance_scope.place.name }) : t('c_economy.treasury.choose_place', 'Choose a place')">
            <nav v-if="jurisdictionContext?.chain?.length" :aria-label="t('c_economy.treasury.by_place_nav', 'Public finance by place')" class="finance-navigation">
                <Link v-for="place in jurisdictionContext.chain" :key="place.id" :href="placeUrl(place.id)"
                    :aria-current="place.id === finance_scope.place?.id ? 'page' : undefined">{{ place.name }}</Link>
            </nav>
            <p class="econ-note">{{ t('c_economy.treasury.choose_inside', 'Choose a place inside this jurisdiction to inspect its own public finances.') }}</p>
            <ul v-if="places.length" class="finance-places">
                <li v-for="place in places" :key="place.id"><Link :href="placeUrl(place.id)">{{ place.name }}</Link></li>
            </ul>
            <p v-else class="econ-note">{{ t('c_economy.treasury.no_smaller_places', 'No smaller places on this page.') }}</p>
            <HistoryPager cursor-key="places_cursor" :pages="places_pages" :only="['places', 'places_pages']" :first="places_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.places_label', 'Places')" />
            <Link href="/jurisdictions">{{ t('c_economy.treasury.browse_world_map', 'Browse the world map and place directory') }}</Link>
        </Card>

        <Card as="section" :title="t('c_economy.treasury.currency_totals', 'Currency totals')">
            <div class="econ-stats">
                <Stat :value="formatMoney(totals.supply, currency)" :label="t('c_economy.treasury.stat_supply', 'Money issued, less withdrawals')" />
                <Stat :value="formatMoney(totals.treasury_balance, currency)" :label="t('c_economy.treasury.stat_treasury', 'Held by all treasuries')" accent />
            </div>
            <p v-if="report.completed_at" class="econ-note">{{ t('c_economy.treasury.last_report', { when: formatWhen(report.completed_at) }) }}</p>
            <p v-else class="econ-note">{{ t('c_economy.treasury.no_report', 'No completed currency report is available. Totals appear after a report completes.') }}</p>
            <p v-if="report.status === 'running'" class="econ-note" role="status">{{ t('c_economy.treasury.report_collecting', 'A newer report is being collected.') }}</p>
            <p v-if="report.status === 'failed'" class="econ-note">{{ t('c_economy.treasury.report_failed', 'The latest report did not finish; any totals shown come from the last completed report.') }}</p>
            <Link href="/economy/units">{{ t('c_economy.treasury.open_reports', 'Open currency reports') }}</Link>
            <p v-if="clock.next_run" class="econ-note">{{ t('c_economy.treasury.next_disbursement', { when: formatWhen(clock.next_run) }) }}</p>
        </Card>

        <Card as="section" :title="t('c_economy.treasury.public_accounts', 'Public accounts')">
            <DataTable v-if="accounts.length" :columns="accountColumns" :rows="accountRows()" row-key="id" :caption="t('c_economy.treasury.caption_accounts', 'Public accounts in the selected place')">
                <template #cell-label="{ row, value }"><Link :href="accountUrl(row.id)" :only="['finance_scope', 'ledger', 'ledger_pages']" preserve-scroll preserve-state
                    :aria-current="finance_scope.account?.id === row.id && finance_scope.ledger_scope === 'account' ? 'true' : undefined">{{ value }}</Link></template>
            </DataTable>
            <p v-else class="econ-note">{{ t('c_economy.treasury.no_accounts', 'No public accounts on this page for this place.') }}</p>
            <HistoryPager cursor-key="accounts_cursor" :pages="accounts_pages" :only="['accounts', 'accounts_pages']" :first="accounts_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.public_accounts_label', 'Public accounts')" />
        </Card>

        <Card as="section" :title="t('c_economy.treasury.public_ledger', 'The public ledger')">
            <nav :aria-label="t('c_economy.treasury.ledger_scope_nav', 'Ledger scope')" class="finance-navigation">
                <Link v-if="finance_scope.account" :href="accountUrl(finance_scope.account.id)" :only="['finance_scope', 'ledger', 'ledger_pages']" preserve-scroll preserve-state
                    :aria-current="finance_scope.ledger_scope === 'account' ? 'page' : undefined">{{ finance_scope.account.label || t('c_economy.treasury.selected_account', 'Selected public account') }}</Link>
                <Link :href="selectionUrl({ ledger_scope: 'currency', ledger_cursor: null })" :only="['finance_scope', 'ledger', 'ledger_pages']" preserve-scroll preserve-state
                    :aria-current="finance_scope.ledger_scope === 'currency' ? 'page' : undefined">{{ t('c_economy.treasury.all_currency_accounts', 'All currency accounts') }}</Link>
            </nav>
            <p class="econ-note">{{ finance_scope.ledger_scope === 'currency' ? t('c_economy.treasury.ledger_scope_currency', 'All account movements in this currency, including pseudonymous economic accounts.') : t('c_economy.treasury.ledger_scope_account', 'Movements for the selected public account.') }}</p>
            <DataTable v-if="ledger.length" :columns="ledgerColumns" :rows="ledgerRows()" row-key="id" :caption="t('c_economy.treasury.caption_ledger', 'Ledger entries, newest first')" />
            <p v-else class="econ-note">{{ finance_scope.ledger_scope === 'account' && !finance_scope.account ? t('c_economy.treasury.ledger_empty_choose', 'Choose a public account, or browse all currency accounts.') : t('c_economy.treasury.ledger_empty_none', 'No entries on this page.') }}</p>
            <HistoryPager cursor-key="ledger_cursor" :pages="ledger_pages" :only="['ledger', 'ledger_pages']" :first="ledger_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.ledger_history_label', 'Ledger history')" />
            <p class="econ-note">{{ t('c_economy.treasury.ledger_appendonly', 'Ledger entries are append-only and hash-chained. This page shows recorded movements; it does not run a chain verification.') }}</p>
        </Card>

        <Card as="section" :title="t('c_economy.treasury.issued_withdrawn', 'Money issued and withdrawn')">
            <p class="econ-note">{{ t('c_economy.treasury.issuance_covers', 'Issuance history covers the whole currency.') }}</p>
            <DataTable v-if="issuance.length" :columns="issuanceColumns" :rows="issuanceRows()" row-key="id" :caption="t('c_economy.treasury.caption_issuance', 'Currency issuance history, newest first')" />
            <p v-else class="econ-note">{{ t('c_economy.treasury.no_issuance', 'No issuance events on this page.') }}</p>
            <HistoryPager cursor-key="issuance_cursor" :pages="issuance_pages" :only="['issuance', 'issuance_pages']" :first="issuance_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.issuance_history_label', 'Issuance history')" />
        </Card>

        <Card as="section" :title="t('c_economy.treasury.budgets', 'Budgets')">
            <ul v-if="budgets.length" class="econ-list">
                <li v-for="budget in budgets" :key="budget.id">
                    <Link :href="budgetUrl(budget.id)" :only="['finance_scope', 'budget_lines', 'budget_lines_pages']" preserve-scroll preserve-state>{{ budget.fiscal_label }}</Link>
                    — {{ formatMoney(budget.total, currency) }} · {{ budget.status }}
                    <span v-if="budget.is_current" class="econ-badge">{{ t('c_economy.treasury.current_badge', 'Current') }}</span>
                    <p v-if="budget.enacting_act" class="econ-note">{{ budget.enacting_act.act_number ? t('c_economy.treasury.act_number', { number: budget.enacting_act.act_number }) : '' }}{{ budget.enacting_act.title }}</p>
                </li>
            </ul>
            <p v-else class="econ-note">{{ t('c_economy.treasury.no_budgets', 'No budgets on this page for this place.') }}</p>
            <HistoryPager cursor-key="budgets_cursor" :pages="budgets_pages" :only="['budgets', 'budgets_pages']" :first="budgets_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.budgets_label', 'Budgets')" />
            <section v-if="finance_scope.budget" aria-labelledby="budget-lines-title">
                <h3 id="budget-lines-title">{{ t('c_economy.treasury.spending_lines', { label: finance_scope.budget.fiscal_label }) }}</h3>
                <ul class="econ-list"><li v-for="line in budget_lines" :key="line.id">{{ line.line }} — {{ formatMoney(line.amount, currency) }}</li></ul>
                <p v-if="!budget_lines.length" class="econ-note">{{ t('c_economy.treasury.no_spending_lines', 'No spending lines on this page.') }}</p>
                <HistoryPager cursor-key="lines_cursor" :pages="budget_lines_pages" :only="['budget_lines', 'budget_lines_pages']" :first="budget_lines_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.spending_lines_label', 'Budget spending lines')" />
            </section>
            <p v-else class="econ-note">{{ t('c_economy.treasury.select_budget', 'Select a budget to read its spending lines.') }}</p>
        </Card>

        <Card as="section" :title="t('c_economy.treasury.borrowing', 'Borrowing')">
            <ul v-if="borrowings.length" class="econ-list"><li v-for="borrowing in borrowings" :key="borrowing.id">
                <strong>{{ formatMoney(borrowing.principal, currency) }}</strong> · {{ borrowing.status }}
                <span v-if="borrowing.lender_account_id" class="econ-note">{{ t('c_economy.treasury.lender_account', { id: shortId(borrowing.lender_account_id) }) }}</span>
                <span v-if="borrowing.at" class="econ-note"> · {{ formatWhen(borrowing.at) }}</span>
                <p>{{ borrowing.terms }}</p>
            </li></ul>
            <p v-else class="econ-note">{{ t('c_economy.treasury.no_borrowing', 'No borrowing records on this page for this place.') }}</p>
            <HistoryPager cursor-key="borrowings_cursor" :pages="borrowings_pages" :only="['borrowings', 'borrowings_pages']" :first="borrowings_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.borrowing_history_label', 'Borrowing history')" />
        </Card>

        <Card as="section" :title="t('c_economy.treasury.money_comes_from', 'Where the money comes from')">
            <ul v-if="revenue.length" class="econ-list"><li v-for="source in revenue" :key="source.id">
                <Link :href="revenueUrl(source.id)" :only="['finance_scope', 'levies', 'levies_pages']" preserve-scroll preserve-state>{{ source.name }}</Link> · {{ source.kind }} · {{ source.status }}
                <p v-if="source.enacting_act" class="econ-note">{{ source.enacting_act.act_number ? t('c_economy.treasury.act_number', { number: source.enacting_act.act_number }) : '' }}{{ source.enacting_act.title }}</p>
            </li></ul>
            <p v-else class="econ-note">{{ t('c_economy.treasury.no_revenue', 'No revenue sources on this page for this place.') }}</p>
            <HistoryPager cursor-key="revenue_cursor" :pages="revenue_pages" :only="['revenue', 'revenue_pages']" :first="revenue_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.revenue_sources_label', 'Revenue sources')" />
            <section v-if="finance_scope.revenue_source" aria-labelledby="levies-title">
                <h3 id="levies-title">{{ t('c_economy.treasury.levies_for', { name: finance_scope.revenue_source.name }) }}</h3>
                <ul class="econ-list"><li v-for="levy in levies" :key="levy.id">{{ t('c_economy.treasury.levy_line', { rate: levy.rate, base: levy.base.replaceAll('_', ' ') }) }}<template v-if="levy.civic_exempt">{{ t('c_economy.treasury.civic_use_exempt', ' · civic use exempt') }}</template></li></ul>
                <p v-if="!levies.length" class="econ-note">{{ t('c_economy.treasury.no_levies', 'No levies on this page.') }}</p>
                <HistoryPager cursor-key="levies_cursor" :pages="levies_pages" :only="['levies', 'levies_pages']" :first="levies_pages.first ?? '/economy/treasury'" :label="t('c_economy.treasury.levies_label', 'Levies')" />
            </section>
            <p v-else class="econ-note">{{ t('c_economy.treasury.select_revenue', 'Select a revenue source to read its levies.') }}</p>
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
