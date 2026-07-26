<script setup>
/**
 * Economy/Listing — one offer on the market (design contract:
 * mockups/v3/economy/listing-detail.html).
 *
 * The mockup is dual-mode — read a listing, or compose one. Only the reading
 * half is built: v1 is read-only and the composer arrives with F-IND-022.
 * Showing a form that cannot submit would be worse than not showing one.
 *
 * PRIVACY: `orders` is a COUNT. Who bought a thing is not published, so this
 * page cannot list buyers even if a designer later wants it to.
 */
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatMoney, formatCount, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    listing: { type: Object, default: null },
    orders: { type: Number, default: 0 },
    can_order: { type: Boolean, default: false },
});
</script>

<template>
    <PageScaffold :title="listing?.title || 'A listing'">
        <p v-if="!listing" class="econ-note">This listing is no longer available.</p>

        <template v-else>
            <Card as="section" title="What's on offer">
                <div class="econ-stats">
                    <Stat :value="formatMoney(listing.price, currency)" label="Price" accent />
                    <Stat :value="listing.quantity" label="Quantity" />
                    <Stat :value="formatCount(orders)" label="Orders placed" />
                </div>

                <p v-if="listing.description" class="lst-desc">{{ listing.description }}</p>

                <dl class="econ-grid">
                    <div>
                        <dt>Kind</dt>
                        <dd>{{ listing.kind === 'service' ? 'A service' : 'A thing' }}</dd>
                    </div>
                    <div><dt>Status</dt><dd>{{ listing.status }}</dd></div>
                    <div v-if="listing.asset">
                        <dt>Item</dt>
                        <dd>{{ listing.asset.name }} ({{ listing.asset.kind === 'virtual' ? 'digital' : 'physical' }})</dd>
                    </div>
                    <div>
                        <dt>Seller's account</dt>
                        <dd class="mono">{{ shortId(listing.seller_account_id) }}</dd>
                    </div>
                </dl>

                <p class="econ-note">
                    Sellers are shown as accounts, not names. Linking an account to a person is
                    deliberately not something this page can do.
                </p>
            </Card>

            <Card as="section" title="Buying it">
                <p v-if="!can_order" class="econ-note">
                    <StatusBadge>Not available to you</StatusBadge>
                    This is either your own listing or no longer open.
                </p>
                <p class="econ-note">
                    Ordering isn't built yet — it arrives with the market forms. Nothing on this page
                    can spend money today.
                </p>
                <p><Link href="/economy/market">← Back to the market</Link></p>
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
.mono {
    font-family: var(--font-mono, ui-monospace, monospace);
}
.econ-note {
    font-size: 0.875rem;
    color: var(--gov-text-muted);
}
</style>
