<script setup>
/**
 * Economy/Market — the open market (design contract:
 * mockups/v3/economy/marketplace.html, which absorbed requests.html as its
 * second tab).
 *
 * BOTH SIDES OF THE BOARD, deliberately: a market with only sellers is a
 * catalogue. Offers, work being sought, and neighbours asking for help all
 * live here.
 *
 * PRIVACY: the assistance list is filtered server-side — requests marked
 * private never cross the boundary, so this page cannot leak one by accident.
 *
 * OFFERING IS LIVE (F-IND-022). Listing files through the
 * ConstitutionalEngine — there is no market API — and a CGC trades here on
 * identical terms to private enterprise (Art. III §5). The badge is
 * informational; it is never a different rule.
 */
import { ref, computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatMoney, formatCount, formatQuantity } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    offers: { type: Array, default: () => [] },
    work: { type: Array, default: () => [] },
    assistance: { type: Array, default: () => [] },
    /** Things you hold that aren't already listed. [] for a guest. */
    my_assets: { type: Array, default: () => [] },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

// F-IND-022 (list). A service needs no asset; a good may point at something
// you registered. Either way the engine decides whether it is lawful.
const offer = useForm({ title: '', kind: 'service', price: '', asset_id: '', description: '' });

function submitOffer() {
    offer.transform((d) => ({ ...d, asset_id: d.kind === 'good' && d.asset_id ? d.asset_id : null }))
        .post('/economy/market', {
            preserveScroll: true,
            onSuccess: () => offer.reset(),
        });
}

/* Deep-linkable, matching the mockup's ?tab= contract. */
const TABS = [
    { key: 'offers', label: 'For sale' },
    { key: 'work', label: 'Work' },
    { key: 'assistance', label: 'Requests for help' },
];

const initialTab = () => {
    const t = new URLSearchParams(window.location.search).get('tab');
    return TABS.some((x) => x.key === t) ? t : 'offers';
};
const tab = ref(initialTab());

const counts = computed(() => ({
    offers: props.offers?.length ?? 0,
    work: props.work?.length ?? 0,
    assistance: props.assistance?.length ?? 0,
}));
</script>

<template>
    <PageScaffold title="The open market">
        <template #intro>
            Things and services for sale, work on offer, and neighbours asking for help — one board,
            open to everyone who lives here.
        </template>

        <Banner v-if="!currency" tone="info" title="No currency yet">
            Nothing can be priced until this world's root legislature defines a currency.
        </Banner>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Card v-if="currency" as="section">
            <template #title>
                <h2>Offer something <FormChip form-id="F-IND-022" name="Marketplace Listing" /></h2>
            </template>

            <form @submit.prevent="submitOffer">
                <Field label="What are you offering" :error="offer.errors.title" required>
                    <template #control="{ id, invalid, describedBy }">
                        <input :id="id" v-model="offer.title" class="field-input" type="text"
                            placeholder="Two hours of carpentry"
                            :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                    </template>
                </Field>

                <Field label="A thing or a service" :error="offer.errors.kind" required>
                    <template #control="{ id }">
                        <select :id="id" v-model="offer.kind" class="select">
                            <option value="service">A service — my time or work</option>
                            <option value="good">A thing I hold</option>
                        </select>
                    </template>
                </Field>

                <Field v-if="offer.kind === 'good'" label="Which thing" :error="offer.errors.asset_id">
                    <template #control="{ id }">
                        <select :id="id" v-model="offer.asset_id" class="select">
                            <option value="">— none, just describe it —</option>
                            <option v-for="a in my_assets" :key="a.id" :value="a.id">
                                {{ a.name }} ({{ a.kind === 'virtual' ? 'digital' : 'physical' }})
                            </option>
                        </select>
                    </template>
                </Field>

                <p v-if="offer.kind === 'good' && !my_assets.length" class="econ-note">
                    You haven't registered anything yet. Register it on
                    <Link href="/economy/wallet">your wallet</Link> first and it can travel with the sale.
                </p>

                <Field label="Price" :error="offer.errors.price" required>
                    <template #control="{ id, invalid, describedBy }">
                        <input :id="id" v-model="offer.price" class="field-input" type="text"
                            inputmode="decimal" placeholder="0.00"
                            :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                    </template>
                </Field>

                <Field label="Describe it (optional)" :error="offer.errors.description">
                    <template #control="{ id, invalid, describedBy }">
                        <textarea :id="id" v-model="offer.description" class="field-input" rows="2"
                            :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy"></textarea>
                    </template>
                </Field>

                <Btn type="submit" variant="primary" :disabled="offer.processing">
                    {{ offer.processing ? 'Listing…' : 'Put it on the market' }}
                </Btn>
            </form>

            <p class="econ-note">
                Anyone who lives here can offer anything — there is no seller's licence and no fee for
                listing. A common-good corporation trades on identical terms to anyone else.
            </p>
        </Card>

        <div class="mkt-tabs" role="tablist" aria-label="Market sections">
            <button
                v-for="t in TABS"
                :key="t.key"
                type="button"
                role="tab"
                class="mkt-tab"
                :class="{ 'mkt-tab--on': tab === t.key }"
                :aria-selected="tab === t.key"
                @click="tab = t.key"
            >
                {{ t.label }}
                <span class="mkt-count">{{ formatCount(counts[t.key]) }}</span>
            </button>
        </div>

        <!-- ------------------------------------------------------ for sale -->
        <section v-show="tab === 'offers'" aria-label="Things and services for sale">
            <p v-if="!offers.length" class="econ-empty">
                Nothing is for sale right now.
            </p>
            <Card v-for="o in offers" :key="o.id" as="article" inset class="mkt-row">
                <div class="mkt-head">
                    <h3 class="mkt-title">
                        <Link :href="`/economy/market/${o.id}`">{{ o.title }}</Link>
                    </h3>
                    <span class="mkt-price">{{ formatMoney(o.price, currency) }}</span>
                </div>
                <p v-if="o.description" class="mkt-desc">{{ o.description }}</p>
                <p class="mkt-meta">
                    <StatusBadge>{{ o.kind === 'service' ? 'A service' : 'A thing' }}</StatusBadge>
                    <span>Quantity {{ formatQuantity(o.quantity) }}</span>
                    <span v-if="o.asset">{{ o.asset.kind === 'virtual' ? 'Digital item' : 'Physical item' }}</span>
                    <span>{{ o.status }}</span>
                </p>
            </Card>
        </section>

        <!-- ---------------------------------------------------------- work -->
        <section v-show="tab === 'work'" aria-label="Work on offer">
            <p v-if="!work.length" class="econ-empty">No work is being offered right now.</p>
            <Card v-for="w in work" :key="w.id" as="article" inset class="mkt-row">
                <div class="mkt-head">
                    <h3 class="mkt-title">
                        <Link :href="`/economy/requests/${w.id}`">{{ w.title }}</Link>
                    </h3>
                    <span v-if="w.rate" class="mkt-price">{{ formatMoney(w.rate, currency) }}</span>
                </div>
                <p class="mkt-desc">{{ w.terms }}</p>
                <p class="mkt-meta">
                    <span>{{ formatCount(w.applications) }} applied</span>
                    <span>{{ w.status }}</span>
                    <Link :href="`/economy/requests/${w.id}`">View &amp; apply</Link>
                </p>
            </Card>
            <p class="econ-note">
                Taking a job is what eventually gives workers a seat on the board of the
                organisation they work for — at a hundred workers a seat appears on its own.
            </p>
        </section>

        <!-- ---------------------------------------------------- assistance -->
        <section v-show="tab === 'assistance'" aria-label="Requests for help">
            <p v-if="!assistance.length" class="econ-empty">Nobody is asking for help right now.</p>
            <Card v-for="a in assistance" :key="a.id" as="article" inset class="mkt-row">
                <h3 class="mkt-title">{{ a.title }}</h3>
                <p class="mkt-desc">{{ a.need }}</p>
                <p class="mkt-meta"><span>{{ a.status }}</span></p>
            </Card>
            <p class="econ-note">
                Requests marked private are never shown here, to anyone — they don't leave the
                server.
            </p>
        </section>
    </PageScaffold>
</template>

<style scoped>
.mkt-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-block-end: var(--space-4);
}
.mkt-tab {
    /* 44px min target — WCAG 2.2 AA pointer target size at 375px. */
    min-height: 44px;
    padding: 0 var(--space-3);
    border: 1px solid var(--gov-border);
    border-radius: var(--radius-2, 0.5rem);
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
}
.mkt-tab--on {
    background: var(--gov-surface-subtle, rgba(127, 127, 127, 0.12));
    font-weight: 600;
}
.mkt-count {
    font-size: 0.75rem;
    color: var(--gov-text-muted);
}
.mkt-row {
    margin-block-end: var(--space-3);
}
.mkt-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-3);
    flex-wrap: wrap;
}
.mkt-title {
    margin: 0;
    font-size: 1rem;
}
.mkt-price {
    font-weight: 700;
    white-space: nowrap;
}
.mkt-desc {
    margin: var(--space-2) 0 0;
}
.mkt-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin: var(--space-2) 0 0;
    font-size: 0.8125rem;
    color: var(--gov-text-muted);
}
.econ-empty,
.econ-note {
    font-size: 0.875rem;
    color: var(--gov-text-muted);
}
</style>
