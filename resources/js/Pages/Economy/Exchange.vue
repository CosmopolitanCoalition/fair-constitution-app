<script setup>
/** Share resale uses the existing constitutional engine; ordinary assets trade in Market. */
import { Link, useForm, router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import Banner from '@/Components/Ui/Banner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatMoney } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    currency: { type: Object, default: null },
    instruments: { type: Array, default: () => [] },
    /** Issued-equity register per stock org (named plane). */
    shares: { type: Array, default: () => [] },
    /** Market-health KPIs, account-clean; null pre-currency. */
    kpis: { type: Object, default: null },
    /** Settled instrument trades, newest first — real history, no ticker. */
    tape: { type: Array, default: () => [] },
    /** Wave 4 ②: open share sell-offers a buyer can take (F-IND-021). */
    offers: { type: Array, default: () => [] },
    /** The viewer's own holdings per stock org — what they can offer. */
    my_holdings: { type: Array, default: () => [] },
    my_id: { type: String, default: null },
    order_book: { type: Boolean, default: false },
    pagination: { type: Object, default: () => ({}) },
});

// F-IND-021 secondary trading, all through the engine door.
const buy = (offerId) => router.post(`/economy/shares/${offerId}/buy`, {}, { preserveScroll: true });
const cancel = (offerId) => router.post(`/economy/shares/${offerId}/cancel`, {}, { preserveScroll: true });

const sell = useForm({ organization_id: '', units: '', price_per_unit: '' });
const submitOffer = () => sell.post('/economy/shares/offer', {
    preserveScroll: true,
    onSuccess: () => { sell.units = ''; sell.price_per_unit = ''; },
});
</script>

<template>
    <PageScaffold title="Shares">
        <template #intro>Buy shares offered by other holders, or offer some of your own at a fixed price.</template>
        <WorkTradeNav active="shares" />
        <p class="econ-note"><Link href="/organizations">Find an organization</Link> to review its ownership and finances. Goods and registered items are in <Link href="/economy/market">the market</Link>.</p>
        <Banner v-if="!currency" tone="info" title="No currency yet">
            This world's root legislature hasn't defined one, so nothing prices or trades.
        </Banner>

        <!-- ------------------------------------------- shares for sale -->
        <Card as="section" title="Shares for sale">
            <p v-if="!offers.length" class="econ-absent">
                No shares are offered for sale right now. A holder lists some below; a buyer takes the
                whole offer at its fixed price — money and units move together or not at all.
            </p>
            <ul v-else class="ex-list">
                <li v-for="o in offers" :key="o.id" class="ex-row">
                    <div class="ex-main">
                        <Link :href="`/organizations/${o.org_id}/economy`" class="ex-title">{{ o.org_name }}</Link>
                        <span class="ex-meta">
                            <StatusBadge v-if="o.is_cgc">CGC — same terms</StatusBadge>
                            <span class="econ-note">{{ o.units }} units · sold by {{ o.seller }}</span>
                        </span>
                    </div>
                    <div class="ex-price">
                        <strong>{{ formatMoney(o.price_per_unit, currency) }}</strong>
                        <span class="econ-note">per unit</span>
                        <div class="ex-acts">
                            <button v-if="o.is_mine" type="button" @click="cancel(o.id)">Withdraw</button>
                            <button v-else-if="my_id" type="button" @click="buy(o.id)">Buy all {{ o.units }}</button>
                        </div>
                    </div>
                </li>
            </ul>
        </Card>

        <nav v-if="pagination.previous || pagination.next" class="ex-acts" aria-label="Share offer pages">
            <Link v-if="pagination.previous" :href="pagination.previous">Newer offers</Link>
            <Link v-if="pagination.next" :href="pagination.next">Older offers</Link>
        </nav>
        <!-- ------------------------------------------- offer your shares -->
        <Card v-if="my_holdings.length" as="section" title="Offer your shares">
            <p class="econ-desc">
                You hold equity you can resell. List some at a fixed per-unit price; a buyer takes the
                whole offer. You cannot offer more than you hold.
            </p>
            <form class="ex-offer" @submit.prevent="submitOffer">
                <label>Organization
                    <select v-model="sell.organization_id" required>
                        <option value="" disabled>Choose a holding</option>
                        <option v-for="h in my_holdings" :key="h.org_id" :value="h.org_id">
                            {{ h.org_name }} — you hold {{ h.units }}
                        </option>
                    </select>
                </label>
                <label>Units<input v-model="sell.units" type="number" min="0.000001" step="0.000001" required /></label>
                <label>Price per unit ({{ currency?.symbol ?? 'units' }})<input v-model="sell.price_per_unit" type="number" min="0" step="0.000001" required /></label>
                <button type="submit" :disabled="sell.processing || !sell.organization_id">Offer for sale</button>
                <p v-if="sell.errors.constitution" class="ex-err">{{ sell.errors.constitution }}</p>
            </form>
        </Card>


    </PageScaffold>
</template>

<style scoped>
.econ-absent {
    color: var(--gov-fg-muted, #667);
    font-style: italic;
    padding: var(--space-3, 1rem);
    background: var(--gov-surface-subtle, #eef);
    border-radius: 0.5rem;
}
.econ-desc { color: var(--gov-fg-muted, #667); }
.econ-note { font-size: var(--text-sm, 0.875rem); color: var(--gov-fg-muted, #778); }
.ex-stats { display: flex; flex-wrap: wrap; gap: var(--space-4, 1.5rem); }
.ex-acts { display: flex; gap: var(--space-2, 0.5rem); margin-block-start: var(--space-2, 0.5rem); justify-content: flex-end; }
.ex-offer { display: flex; flex-direction: column; gap: var(--space-3, 0.75rem); max-inline-size: 32rem; }
.ex-offer label { display: flex; flex-direction: column; gap: var(--space-1, 0.25rem); }
.ex-err { color: var(--gov-danger, #b00); font-size: var(--text-sm, 0.875rem); }
.ex-list { list-style: none; margin: 0; padding: 0; }
.ex-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-3, 1rem);
    padding-block: var(--space-3, 0.75rem);
    border-block-start: 1px solid var(--gov-border, #dde);
    flex-wrap: wrap;
}
.ex-title { font-weight: 600; color: var(--gov-fg, #223); }
.ex-meta { display: flex; flex-wrap: wrap; gap: var(--space-2, 0.5rem); align-items: center; margin-inline-start: var(--space-2, 0.5rem); }
.ex-price { text-align: end; }
.econ-back { display: inline-block; margin-block-start: var(--space-3, 1rem); }
</style>
