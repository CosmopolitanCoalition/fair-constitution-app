<script setup>
/**
 * Economy/Treasury — public money (design contract:
 * mockups/v3/economy/treasury.html).
 *
 * PUBLIC BY CONSTRUCTION. Everything on this page is meant to be readable by
 * anyone — the opposite posture to Economy/Wallet, and the pairing is the
 * point: public money is examined, private money is not.
 *
 * The ledger is append-only and hash-chained. Rows are shown newest first with
 * their hash, so a reader can follow the chain rather than take the total on
 * trust.
 */
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { formatMoney, formatCount, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    accounts: { type: Array, default: () => [] },
    ledger: { type: Array, default: () => [] },
    issuance: { type: Array, default: () => [] },
    budgets: { type: Array, default: () => [] },
    revenue: { type: Array, default: () => [] },
    /** Art. V §4 — jurisdiction instruments; lenders appear as accounts. */
    borrowings: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
});

const ledgerColumns = [
    { key: 'seq', label: '#' },
    { key: 'when', label: 'When' },
    { key: 'direction', label: 'Debit / credit' },
    { key: 'amount', label: 'Amount' },
    { key: 'kind', label: 'Kind' },
    { key: 'account', label: 'Account', mono: true },
    { key: 'hash', label: 'Hash', mono: true },
];

const ledgerRows = () =>
    (props.ledger ?? []).map((e) => ({
        id: `${e.seq}-${e.hash}`,
        seq: e.seq,
        when: formatWhen(e.at),
        direction: e.direction === 'debit' ? 'Debit' : 'Credit',
        amount: formatMoney(e.amount, props.currency),
        kind: e.kind ?? '—',
        account: shortId(e.account_id),
        hash: shortId(e.hash),
    }));

const accountColumns = [
    { key: 'label', label: 'Account' },
    { key: 'owner', label: 'Belongs to' },
    { key: 'balance', label: 'Balance' },
    { key: 'visibility', label: 'Visible' },
];

const accountRows = () =>
    (props.accounts ?? []).map((a) => ({
        id: a.id,
        label: a.label ?? shortId(a.id),
        owner: a.owner_type ?? '—',
        balance: formatMoney(a.balance, props.currency),
        visibility: a.public ? 'Public' : 'Private',
    }));

const issuanceColumns = [
    { key: 'when', label: 'When' },
    { key: 'direction', label: 'Issued / withdrawn' },
    { key: 'amount', label: 'Amount' },
    { key: 'reason', label: 'Reason' },
];

const issuanceRows = () =>
    (props.issuance ?? []).map((i) => ({
        id: i.id,
        when: formatWhen(i.at),
        direction: i.direction === 'burn' ? 'Withdrawn' : 'Issued',
        amount: formatMoney(i.amount, props.currency),
        reason: i.reason ?? '—',
    }));
</script>

<template>
    <PageScaffold title="Public finance">
        <template #intro>
            Every movement of public money, in the open. This is the counterpart to your own wallet:
            what the public holds is examined by anyone, what you hold is seen by no one.
        </template>

        <Banner v-if="!currency" tone="info" title="No currency yet">
            There is no public money to account for until a currency exists.
        </Banner>

        <Card as="section" title="Totals">
            <div class="econ-stats">
                <Stat :value="formatMoney(totals.supply, currency)" label="In circulation" />
                <Stat :value="formatMoney(totals.treasury_balance, currency)" label="Held by the treasury" accent />
                <Stat :value="formatCount(ledger.length)" label="Ledger rows shown" />
            </div>
        </Card>

        <Card as="section" title="The public ledger">
            <DataTable
                v-if="ledger.length"
                :columns="ledgerColumns"
                :rows="ledgerRows()"
                row-key="id"
                caption="Public ledger entries, newest first"
            />
            <p v-else class="econ-note">No entries yet.</p>
            <p class="econ-note">
                Entries are append-only and chained to each other. Nothing here can be edited after
                the fact without breaking the chain — which is checkable on
                <Link href="/economy">the economy overview</Link>.
            </p>
        </Card>

        <Card as="section" title="Public accounts">
            <DataTable
                v-if="accounts.length"
                :columns="accountColumns"
                :rows="accountRows()"
                row-key="id"
                caption="Treasury and public accounts"
            />
            <p v-else class="econ-note">No public accounts yet.</p>
        </Card>

        <Card as="section" title="Money issued and withdrawn">
            <DataTable
                v-if="issuance.length"
                :columns="issuanceColumns"
                :rows="issuanceRows()"
                row-key="id"
                caption="Issuance history, newest first"
            />
            <p v-else class="econ-note">No money has been issued yet.</p>
            <p class="econ-note">
                Only the root legislature can issue currency, and only by an act on the record.
            </p>
        </Card>

        <Card as="section" title="Budgets">
            <ul v-if="budgets.length" class="econ-list">
                <li v-for="b in budgets" :key="b.id">
                    <strong>{{ b.fiscal_label }}</strong> — {{ formatMoney(b.total, currency) }}
                    <span class="econ-note">
                        · {{ b.status }} · {{ formatCount(b.lines) }} lines
                        <template v-if="b.enacted_at"> · enacted {{ formatWhen(b.enacted_at) }}</template>
                    </span>
                    <ul v-if="b.line_items?.length" class="econ-budget-lines">
                        <li v-for="l in b.line_items" :key="l.line">
                            {{ l.line }} — {{ formatMoney(l.amount, currency) }}
                        </li>
                    </ul>
                </li>
            </ul>
            <p v-else class="econ-note">No budget has been enacted yet.</p>
        </Card>

        <Card as="section" title="Borrowing">
            <ul v-if="borrowings.length" class="econ-list">
                <li v-for="b in borrowings" :key="b.id">
                    <strong>{{ formatMoney(b.principal, currency) }}</strong>
                    <span class="econ-note">
                        · {{ b.status }}
                        <template v-if="b.lender_account_id"> · lender account {{ b.lender_account_id.slice(0, 8) }}</template>
                        <template v-if="b.at"> · {{ formatWhen(b.at) }}</template>
                    </span>
                    <p class="econ-note">{{ b.terms }}</p>
                </li>
            </ul>
            <p v-else class="econ-note">
                No borrowing is on the books. Borrowing is a jurisdiction instrument, enacted by
                the chamber — there is no personal credit anywhere in this economy.
            </p>
        </Card>

        <Card as="section" title="Where the money comes from">
            <ul v-if="revenue.length" class="econ-list">
                <li v-for="r in revenue" :key="r.id">
                    <strong>{{ r.name }}</strong>
                    <span class="econ-note"> · {{ r.kind }} · {{ r.status }}</span>
                </li>
            </ul>
            <p v-else class="econ-note">No revenue sources have been set up yet.</p>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.econ-stats {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
}
.econ-note {
    font-size: 0.875rem;
    color: var(--gov-text-muted);
}
.econ-budget-lines {
    margin: var(--space-1, 0.25rem) 0 0;
    padding-inline-start: 1.25rem;
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.econ-list {
    margin: 0;
    padding-inline-start: var(--space-4);
}
.econ-list li {
    margin-block-end: var(--space-2);
}
</style>
