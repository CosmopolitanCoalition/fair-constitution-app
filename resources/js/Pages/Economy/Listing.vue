<script setup>
/**
 * Economy/Listing — one offer on the market (design contract:
 * mockups/v3/economy/listing-detail.html).
 *
 * Ordering and settling are live (F-IND-022). Both file through the
 * ConstitutionalEngine, so the page cannot make a sale the constitution would
 * refuse: an empty wallet comes back as a rejection carrying its citation.
 *
 * ONLY THE SELLER SETTLES. Accepting an order is the seller's act — a buyer
 * settling their own purchase would simply take the goods. The handler
 * refuses it outright; hiding the control here is courtesy, not the boundary.
 *
 * PRIVACY: `orders` is a COUNT, and the public page cannot list buyers even
 * if a designer later wants it to. The seller sees pending orders because
 * they must choose which to accept — and even there a buyer is an ACCOUNT.
 * Knowing which order to settle is not the same as being told who bought it.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import { formatMoney, formatCount, formatQuantity, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    currency: { type: Object, default: null },
    listing: { type: Object, default: null },
    orders: { type: Number, default: 0 },
    can_order: { type: Boolean, default: false },
    is_seller: { type: Boolean, default: false },
    /** Seller-only, and buyers appear as accounts. [] for everyone else. */
    pending_orders: { type: Array, default: () => [] },
    pending_order_pages: { type: Object, default: () => ({ previous: null, next: null }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const buy = useForm({});
const accept = useForm({});

function placeOrder() {
    buy.post(`/economy/market/${props.listing.id}/order`, { preserveScroll: true });
}

function settle(orderId) {
    accept.post(`/economy/orders/${orderId}/settle`, { preserveScroll: true });
}
</script>

<template>
    <PageScaffold :title="listing?.title || t('c_economy.listing.a_listing', 'A listing')">
        <WorkTradeNav active="market" back-href="/economy/market?tab=offers" :back-label="t('c_economy.listing.back_label', 'For sale')" />
        <p v-if="!listing" class="econ-note">{{ t('c_economy.listing.no_longer_available', 'This listing is no longer available.') }}</p>

        <template v-else>
            <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
            <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

            <Card as="section" :title="t('c_economy.listing.whats_on_offer', 'What\'s on offer')">
                <div class="econ-stats">
                    <Stat :value="formatMoney(listing.price, currency)" :label="t('c_economy.listing.price_label', 'Price')" accent />
                    <Stat :value="formatQuantity(listing.quantity)" :label="t('c_economy.listing.quantity_label', 'Quantity')" />
                    <Stat :value="formatCount(orders)" :label="t('c_economy.listing.orders_placed_label', 'Orders placed')" />
                </div>

                <p v-if="listing.description" class="lst-desc">{{ listing.description }}</p>

                <dl class="econ-grid">
                    <div>
                        <dt>{{ t('c_economy.listing.kind_label', 'Kind') }}</dt>
                        <dd>{{ listing.kind === 'service' ? t('c_economy.listing.a_service', 'A service') : t('c_economy.listing.a_thing', 'A thing') }}</dd>
                    </div>
                    <div><dt>{{ t('c_economy.listing.status_label', 'Status') }}</dt><dd>{{ listing.status }}</dd></div>
                    <div v-if="listing.asset">
                        <dt>{{ t('c_economy.listing.item_label', 'Item') }}</dt>
                        <dd>{{ listing.asset.name }} ({{ listing.asset.kind === 'virtual' ? t('c_economy.listing.digital', 'digital') : t('c_economy.listing.physical', 'physical') }})</dd>
                    </div>
                    <div v-if="listing.seller_org">
                        <dt>{{ t('c_economy.listing.seller_label', 'Seller') }}</dt>
                        <dd>
                            {{ listing.seller_org.name }}
                            <span v-if="listing.seller_org.is_cgc" class="econ-cgc-badge">{{ t('c_economy.listing.common_good_corp', 'Common-good corporation') }}</span>
                        </dd>
                    </div>
                    <div v-else>
                        <dt>{{ t('c_economy.listing.seller_account_label', 'Seller\'s account') }}</dt>
                        <dd class="mono">{{ shortId(listing.seller_account_id) }}</dd>
                    </div>
                </dl>

                <p v-if="listing.seller_org" class="econ-note">
                    {{ t('c_economy.listing.org_trades', 'An organization trades under its own name — its listing is its public act.') }}
                    <template v-if="listing.seller_org.is_cgc">
                        {{ t('c_economy.listing.cgc_badge_note', 'The common-good badge is informational: a CGC trades on identical terms to private enterprise, never a different rule.') }}
                    </template>
                </p>
                <p v-else class="econ-note">
                    {{ t('c_economy.listing.person_account_note', 'A person selling is shown as an account, not a name. Linking an account to a person is deliberately not something this page can do.') }}
                </p>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>{{ t('c_economy.listing.buying_it', 'Buying it') }} <FormChip form-id="F-IND-022" name="Marketplace Order" /></h2>
                </template>

                <template v-if="can_order">
                    <p class="econ-note">
                        {{ t('c_economy.listing.ordering_note', 'Ordering doesn\'t move anything on its own. The seller accepts, and then money and thing move together — both, or neither.') }}
                    </p>
                    <Btn variant="primary" :disabled="buy.processing" @click="placeOrder">
                        {{ buy.processing ? t('c_economy.listing.ordering', 'Ordering…') : t('c_economy.listing.order_for', { price: formatMoney(listing.price, currency) }) }}
                    </Btn>
                </template>

                <p v-else-if="is_seller" class="econ-note">
                    <StatusBadge>{{ t('c_economy.listing.your_listing', 'Your listing') }}</StatusBadge>
                    {{ t('c_economy.listing.cant_order_own', 'You can\'t order your own goods.') }}
                </p>

                <p v-else class="econ-note">
                    <StatusBadge>{{ t('c_economy.listing.not_available', 'Not available to you') }}</StatusBadge>
                    {{ t('c_economy.listing.listing_closed', 'This listing is closed, or you don\'t have a wallet in this currency yet.') }}
                </p>

            </Card>

            <Card v-if="is_seller" as="section">
                <template #title>
                    <h2>{{ t('c_economy.listing.orders_waiting', 'Orders waiting on you') }} <FormChip form-id="F-IND-022" name="Marketplace Settlement" /></h2>
                </template>

                <template v-if="pending_orders.length">
                    <ul class="lst-orders">
                        <li v-for="o in pending_orders" :key="o.id">
                            <span>
                                <span class="mono">{{ shortId(o.buyer_account_id) }}</span>
                                {{ t('c_economy.listing.ordered_line', { quantity: formatQuantity(o.quantity), when: formatWhen(o.at) }) }}
                            </span>
                            <Btn variant="primary" size="sm" :disabled="accept.processing" @click="settle(o.id)">
                                {{ accept.processing ? t('c_economy.listing.settling', 'Settling…') : t('c_economy.listing.accept_settle', 'Accept and settle') }}
                            </Btn>
                        </li>
                    </ul>
                    <p class="econ-note">
                        {{ t('c_economy.listing.buyers_accounts_note', 'Buyers are shown as accounts. You are told which order to accept, not who placed it. Settling moves the money and the thing in one transaction.') }}
                    </p>
                </template>

                <p v-else class="econ-note">{{ t('c_economy.listing.no_pending_orders', 'No pending orders on this page.') }}</p>
                <nav class="lst-pages" :aria-label="t('c_economy.listing.pending_order_pages', 'Pending order pages')">
                    <Link v-if="pending_order_pages.previous" :href="pending_order_pages.previous" preserve-scroll rel="prev">{{ t('c_economy.listing.previous_orders', 'Previous orders') }}</Link>
                    <Link v-if="pending_order_pages.next" :href="pending_order_pages.next" preserve-scroll rel="next">{{ t('c_economy.listing.next_orders', 'Next orders') }}</Link>
                    <Link :href="`/economy/market/${listing.id}`" preserve-scroll>{{ t('c_economy.listing.first_page', 'First page') }}</Link>
                </nav>
            </Card>
        </template>
    </PageScaffold>
</template>

<style scoped>
.lst-pages { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-block-start: 1rem; }
.lst-pages a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .4rem .6rem; }
.lst-pages a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.econ-stats {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
}
.econ-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: var(--space-3);
    margin: var(--space-3) 0 0;
}
.econ-grid dt {
    font-size: 0.8125rem;
    color: var(--gov-text-muted);
}
.econ-grid dd {
    margin: 0;
    font-weight: 600;
}
.lst-desc {
    margin: var(--space-3) 0 0;
}
.lst-orders {
    list-style: none;
    margin: 0 0 var(--space-3);
    padding: 0;
    display: grid;
    gap: var(--space-2);
}
.lst-orders li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
}
.mono {
    font-family: var(--font-mono, ui-monospace, monospace);
}
.econ-note {
    font-size: 0.875rem;
    color: var(--gov-text-muted);
}
.econ-cgc-badge {
    display: inline-block;
    margin-inline-start: 0.5rem;
    padding: 0.1rem 0.5rem;
    border: 1px solid var(--gov-border, #dde);
    border-radius: 999px;
    font-size: 0.75rem;
    color: var(--gov-fg-muted, #667);
}
</style>
