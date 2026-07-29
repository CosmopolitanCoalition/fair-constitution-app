<script setup>
/**
 * Economy/Exchange — the fungible + non-fungible INSTRUMENTS venue (design
 * contract: mockups/v3/economy/exchange.html; Design Round 2 ①).
 *
 * The mockup draws a stock-market order book. It is deliberately NOT built:
 * there is no matching engine, no simulated price. An instrument trades at a
 * FIXED price through the same rail as the open market (F-IND-022), one order
 * at a time — so each offer here links to its listing, where the shipped
 * order/settle path does the work. Honest chrome over a real rail beats a
 * live-looking floor with nothing behind it.
 *
 * Fungible = a divisible stack; unique = one of a kind. SHARES (equity) join
 * this floor once an organization can issue them (F-ORG-008, piece 4); until
 * then the shares floor is honestly empty. Goods and services are on the open
 * market — this venue is for holdings with their own identity.
 */
import { Link, useForm, router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatMoney, formatWhen } from '@/lib/money.js';

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
    <PageScaffold title="The exchange">
        <template #intro>
            Where holdings with their own identity change hands — registered assets now, and
            organization shares once they can be issued. Goods and services are on the open market;
            this floor is for instruments. Everything trades on the market's own terms — no
            privileged price, no separate rules.
        </template>

        <Banner v-if="!currency" tone="info" title="No currency yet">
            This world's root legislature hasn't defined one, so nothing prices or trades.
        </Banner>

        <Banner v-else-if="!order_book" tone="info" title="No order book — and that is honest">
            There is no live bid/ask ticker here. A trade is a fixed-price order settled through the
            same rail as the open market, one at a time. We show what is genuinely offered rather
            than simulate a market that isn't running.
        </Banner>

        <!-- ------------------------------------------------ market health -->
        <Card v-if="kpis" as="section" title="Market health">
            <p class="econ-desc">
                Read-only, counted over <strong>accounts, never people</strong> — a spread of
                balances says nothing about who holds them.
            </p>
            <div class="ex-stats">
                <Stat :value="formatMoney(kpis.in_circulation, currency)" label="In wallets" accent />
                <Stat :value="String(kpis.funded_wallets) + ' / ' + String(kpis.wallets)" label="Wallets funded / total" />
                <Stat :value="kpis.top_decile_share_pct ? kpis.top_decile_share_pct + '%' : '—'" label="Held by the top tenth" />
                <Stat :value="kpis.velocity_30d ? kpis.velocity_30d + '×' : '—'" label="Turned over (30 days)" />
            </div>
        </Card>

        <!-- ---------------------------------------------- instruments -->
        <Card as="section" title="Holdings offered">
            <p v-if="!instruments.length" class="econ-absent">
                Nothing is offered for trade right now.
            </p>
            <ul v-else class="ex-list">
                <li v-for="it in instruments" :key="it.id" class="ex-row">
                    <div class="ex-main">
                        <Link :href="`/economy/market/${it.id}`" class="ex-title">{{ it.title }}</Link>
                        <span class="ex-meta">
                            <StatusBadge>{{ it.fungibility === 'fungible' ? 'Fungible stack' : 'One of a kind' }}</StatusBadge>
                            <span class="econ-note">{{ it.asset_kind }} · {{ it.asset_name }}</span>
                            <span v-if="it.seller_org" class="econ-note">
                                · {{ it.seller_org.name }}<template v-if="it.seller_org.is_cgc"> (CGC — same terms)</template>
                            </span>
                        </span>
                    </div>
                    <div class="ex-price">
                        <strong>{{ formatMoney(it.price, currency) }}</strong>
                        <span class="econ-note">× {{ it.quantity }}</span>
                    </div>
                </li>
            </ul>
        </Card>

        <!-- --------------------------------------------- the trade tape -->
        <Card as="section" title="Recent trades">
            <p v-if="!tape.length" class="econ-absent">
                No instrument has settled a trade yet. This tape shows real settlements — what
                actually changed hands — not a simulated ticker.
            </p>
            <ul v-else class="ex-list">
                <li v-for="t in tape" :key="t.id" class="ex-row">
                    <div class="ex-main">
                        <span class="ex-title">{{ t.title }}</span>
                        <span class="ex-meta">
                            <span v-if="t.asset_name" class="econ-note">{{ t.asset_kind }} · {{ t.asset_name }}</span>
                            <span class="econ-note">· {{ formatWhen(t.at) }}</span>
                        </span>
                    </div>
                    <div class="ex-price">
                        <strong>{{ formatMoney(t.price, currency) }}</strong>
                        <span class="econ-note">× {{ t.quantity }}</span>
                    </div>
                </li>
            </ul>
        </Card>

        <!-- -------------------------------------------------- shares -->
        <Card as="section" title="Equity register">
            <p v-if="!shares.length" class="econ-absent">
                No organization has issued equity yet. Shares appear here once a stock organization
                issues them (F-ORG-008). Ownership is recorded by name — a public fact — while the
                money that changes hands on a trade stays on the private wallet ledger.
            </p>
            <template v-else>
                <ul class="ex-list">
                    <li v-for="s in shares" :key="s.org_id" class="ex-row">
                        <div class="ex-main">
                            <Link :href="`/organizations/${s.org_id}/economy`" class="ex-title">{{ s.org_name }}</Link>
                            <span class="ex-meta">
                                <StatusBadge v-if="s.is_cgc">CGC — same terms</StatusBadge>
                                <span class="econ-note">{{ s.holder_count }} holder{{ s.holder_count === 1 ? '' : 's' }}</span>
                            </span>
                        </div>
                        <div class="ex-price">
                            <strong>{{ s.total_units }} units</strong>
                            <span v-if="s.fair_market_floor" class="econ-note">floor {{ formatMoney(s.fair_market_floor, currency) }}</span>
                        </div>
                    </li>
                </ul>
                <p class="econ-note">
                    This is the register of equity that <em>exists</em>. Sell-offers below are how a
                    holder resells — units move on this named plane, the money on the private wallet
                    ledger, in one act.
                </p>
            </template>
        </Card>

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

        <p>
            <Link href="/economy/market" class="econ-back">Looking for goods or services? The open market</Link>
        </p>
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
