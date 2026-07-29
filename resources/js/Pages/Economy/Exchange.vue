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
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatMoney, formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

defineProps({
    surface: { type: Object, required: true },
    currency: { type: Object, default: null },
    instruments: { type: Array, default: () => [] },
    /** Issued-equity register per stock org (named plane); resale opens with ②. */
    shares: { type: Array, default: () => [] },
    /** Market-health KPIs, account-clean; null pre-currency. */
    kpis: { type: Object, default: null },
    /** Settled instrument trades, newest first — real history, no ticker. */
    tape: { type: Array, default: () => [] },
    order_book: { type: Boolean, default: false },
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
                    This is the register of equity that <em>exists</em>. Holder-to-holder resale on
                    this floor opens with secondary trading — until then, a share changes hands only
                    through an organization's own issuance and conversion acts.
                </p>
            </template>
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
