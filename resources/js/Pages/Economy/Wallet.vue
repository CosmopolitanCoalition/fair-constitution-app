<script setup>
/**
 * Economy/Wallet — your own money (design contract:
 * mockups/v3/economy/wallet.html).
 *
 * PRIVATE BY CONSTRUCTION. This is the one economy surface that is nobody
 * else's business: the aggregate is public, an individual's balance is not.
 * Nothing here names a counterparty as a person — the contract carries account
 * ids only, and resolving an account back to a human is deliberately not
 * something any page can do.
 *
 * READ-ONLY in v1. Transfers arrive with F-IND-022/023/024, so there is no
 * submit path yet and the page says so rather than showing a dead button.
 */
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { formatMoney, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    /** null when they have no wallet yet — a normal state, not an error. */
    account: { type: Object, default: null },
    transactions: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
});

const txColumns = [
    { key: 'when', label: 'When' },
    { key: 'direction', label: 'In / out' },
    { key: 'amount', label: 'Amount' },
    { key: 'kind', label: 'Kind' },
    { key: 'memo', label: 'Note' },
    { key: 'counterparty', label: 'Other account', mono: true },
];

const txRows = () =>
    (props.transactions ?? []).map((t) => ({
        id: t.id,
        when: formatWhen(t.at),
        direction: t.direction === 'in' ? 'In' : 'Out',
        amount: (t.direction === 'out' ? '−' : '+') + formatMoney(t.amount, props.currency),
        kind: t.kind ?? '—',
        memo: t.memo ?? '—',
        counterparty: shortId(t.counterparty_account_id),
    }));

const receiptColumns = [
    { key: 'when', label: 'When' },
    { key: 'base', label: 'Base' },
    { key: 'bump', label: 'Extra for serving' },
    { key: 'amount', label: 'Paid' },
];

const receiptRows = () =>
    (props.receipts ?? []).map((r) => ({
        id: r.id,
        when: formatWhen(r.at),
        base: formatMoney(r.base, props.currency),
        bump: formatMoney(r.bump, props.currency),
        amount: formatMoney(r.amount, props.currency),
    }));
</script>

<template>
    <PageScaffold title="My wallet">
        <template #intro>
            What you hold, and where it came from. This page is yours alone — balances are private
            in the same way a ballot is, and no one else can look yours up.
        </template>

        <Banner v-if="!currency" tone="info" title="No currency yet">
            This world's root legislature hasn't defined one, so there is nothing to hold.
        </Banner>

        <Banner v-else-if="!account" tone="info" title="You don't have a wallet yet">
            A wallet opens once your residency is confirmed. If you've just declared where you live,
            it arrives when the confirmation does.
        </Banner>

        <template v-else>
            <Card as="section" title="Balance">
                <div class="econ-stats">
                    <Stat :value="formatMoney(account.balance, currency)" label="Available" accent />
                    <Stat :value="account.status || '—'" label="Account" />
                </div>
                <p class="econ-note">
                    Sending money isn't built yet — it arrives with the transfer forms. Nothing on
                    this page can move money today.
                </p>
            </Card>

            <Card as="section" title="Activity">
                <DataTable
                    v-if="transactions.length"
                    :columns="txColumns"
                    :rows="txRows()"
                    row-key="id"
                    caption="Your most recent transactions, newest first"
                />
                <p v-else class="econ-note">Nothing has moved in or out yet.</p>
            </Card>

            <Card as="section" title="Stipend receipts">
                <DataTable
                    v-if="receipts.length"
                    :columns="receiptColumns"
                    :rows="receiptRows()"
                    row-key="id"
                    caption="Civic stipend payments you have received, newest first"
                />
                <p v-else class="econ-note">You haven't received a stipend payment yet.</p>
            </Card>
        </template>
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
</style>
