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
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage, useRemember } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import { formatMoney, formatCount, formatQuantity } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    tab: { type: String, default: 'offers' },
    pagination: { type: Object, default: () => ({}) },
    currency: { type: Object, default: null },
    offers: { type: Array, default: () => [] },
    work: { type: Array, default: () => [] },
    assistance: { type: Array, default: () => [] },
    /** Things you hold that aren't already listed. [] for a guest. */
    my_assets: { type: Array, default: () => [] },
    asset_directory: { type: Object, default: () => ({ query: '', previous: null, next: null, available: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

// F-IND-022 (list). A service needs no asset; a good may point at something
// you registered. Either way the engine decides whether it is lawful.
const draftKey = `market-offer:${page.props.auth?.user?.id ?? 'guest'}:${props.currency?.id ?? 'none'}`;
const offer = useForm(draftKey, { title: '', kind: 'service', price: '', asset_id: '', description: '' });
const selectedAsset = useRemember(reactive({ id: '', name: '', kind: '', quantity: '' }), `${draftKey}:asset`);
const composer = useRemember(reactive({ open: new URLSearchParams((page.url ?? '').split('?')[1] ?? '').has('asset_picker') }), `${draftKey}:composer`);
const assetSearch = ref(props.asset_directory.query ?? '');
const assetSearching = ref(false);
const assetSearchError = ref('');
watch(() => props.asset_directory.query, query => { assetSearch.value = query ?? ''; });
watch(() => props.my_assets, assets => {
    const selected = assets.find(asset => asset.id === offer.asset_id);
    if (selected) Object.assign(selectedAsset, selected);
}, { immediate: true });

function chooseAsset(asset) {
    offer.asset_id = asset.id;
    Object.assign(selectedAsset, asset);
}
function clearAsset() {
    offer.asset_id = '';
    Object.assign(selectedAsset, { id: '', name: '', kind: '', quantity: '' });
}
const assetVisitOptions = () => ({
    only: ['my_assets', 'asset_directory'],
    preserveState: true,
    preserveScroll: true,
    onStart: () => { assetSearching.value = true; assetSearchError.value = ''; },
    onFinish: () => { assetSearching.value = false; },
    onError: errors => { assetSearchError.value = errors.asset_q ?? errors.asset_cursor ?? 'The item search could not be completed. Try again.'; },
});
function searchAssets() {
    const marketCursor = new URLSearchParams((page.url ?? '').split('?')[1] ?? '').get('cursor');
    router.get('/economy/market', {
        tab: 'offers', asset_picker: 1, asset_q: assetSearch.value.trim(),
        ...(marketCursor ? { cursor: marketCursor } : {}),
    }, assetVisitOptions());
}

function submitOffer() {
    offer.transform((d) => ({ ...d, asset_id: d.kind === 'good' && d.asset_id ? d.asset_id : null }))
        .post('/economy/market', {
            preserveScroll: true,
            onSuccess: () => { offer.reset(); clearAsset(); },
        });
}

/* Deep-linkable, matching the mockup's ?tab= contract. */
const TABS = [
    { key: 'offers', label: 'For sale' },
    { key: 'work', label: 'Work' },
    { key: 'assistance', label: 'Requests for help' },
];

const tab = computed(() => props.tab);
const pageCount = computed(() => (props[tab.value] ?? []).length);
</script>

<template>
    <PageScaffold title="Market & work">
        <template #intro>
            Things and services for sale, work on offer, and neighbours asking for help — one board,
            open to everyone who lives here.
        </template>
        <WorkTradeNav active="market" />

        <Banner v-if="!currency" tone="info" title="No currency yet">
            Nothing can be priced until this world's root legislature defines a currency.
        </Banner>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <details v-if="currency && tab === 'offers'" class="mkt-compose" :open="composer.open" @toggle="composer.open = $event.target.open">
            <summary>Sell a good or offer a service</summary>
        <Card as="section">
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

                <fieldset v-if="offer.kind === 'good'" class="mkt-asset-picker">
                    <legend>Choose a registered item</legend>
                    <p v-if="offer.asset_id" class="mkt-selected-asset">
                        <span><strong>Selected: {{ selectedAsset.name || 'Previously selected item' }}</strong>
                            <span v-if="selectedAsset.quantity"> · {{ formatQuantity(selectedAsset.quantity) }} held</span>
                        </span>
                        <button type="button" @click="clearAsset">Clear selection</button>
                    </p>
                    <p class="econ-note">Goods must use an item you hold. Items with an open listing are excluded.</p>
                    <p v-if="!asset_directory.available" class="econ-note">Check <Link href="/economy/wallet">My wallet</Link> to see or register your items.</p>
                    <template v-else>
                        <label for="market-asset-search">Find an item by the beginning of its name</label>
                        <div class="mkt-asset-search">
                            <input id="market-asset-search" v-model="assetSearch" type="search" maxlength="120" @keydown.enter.prevent="searchAssets" />
                            <button type="button" :disabled="assetSearching" @click="searchAssets">{{ assetSearching ? 'Searching…' : 'Search items' }}</button>
                        </div>
                        <p v-if="assetSearchError" class="mkt-asset-error" role="alert">{{ assetSearchError }}</p>
                        <div :aria-busy="assetSearching">
                            <p v-if="!my_assets.length" class="econ-note" role="status">
                                {{ asset_directory.query ? `No available items start with “${asset_directory.query}”.` : 'No unlisted items are available.' }}
                                You can register an item in <Link href="/economy/wallet">My wallet</Link>.
                            </p>
                            <p v-else class="econ-note" role="status">{{ my_assets.length }} available items on this page. Your selection stays selected when you browse.</p>
                            <label v-for="asset in my_assets" :key="asset.id" class="mkt-asset-option">
                                <input type="radio" name="market-asset" :value="asset.id" :checked="offer.asset_id === asset.id" @change="chooseAsset(asset)" />
                                <span>{{ asset.name }} · {{ asset.kind === 'virtual' ? 'Digital' : 'Physical' }} · {{ formatQuantity(asset.quantity) }} held</span>
                            </label>
                        </div>
                        <nav v-if="asset_directory.previous || asset_directory.next" class="mkt-asset-pages" aria-label="Available item pages">
                            <Link v-if="asset_directory.previous" :href="asset_directory.previous" v-bind="assetVisitOptions()">Previous items</Link>
                            <Link v-if="asset_directory.next" :href="asset_directory.next" v-bind="assetVisitOptions()">More items</Link>
                        </nav>
                    </template>
                    <p v-if="offer.errors.asset_id" class="mkt-asset-error">{{ offer.errors.asset_id }}</p>
                </fieldset>

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

                <Btn type="submit" variant="primary" :disabled="offer.processing || (offer.kind === 'good' && !offer.asset_id)">
                    {{ offer.processing ? 'Listing…' : 'Put it on the market' }}
                </Btn>
            </form>

        </Card>
        </details>

        <nav class="mkt-tabs" aria-label="Market sections">
            <Link
                v-for="t in TABS"
                :key="t.key"
                :href="`/economy/market?tab=${t.key}`"
                :only="['offers', 'work', 'assistance', 'tab', 'pagination', 'my_assets', 'asset_directory']"
                preserve-state
                class="mkt-tab"
                :class="{ 'mkt-tab--on': tab === t.key }"
                :aria-current="tab === t.key ? 'page' : undefined"
            >
                {{ t.label }}
            </Link>
        </nav>
        <p role="status" aria-live="polite">{{ formatCount(pageCount) }} entries on this page</p>

        <!-- ------------------------------------------------------ for sale -->
        <section v-if="tab === 'offers'" aria-label="Things and services for sale">
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
                    <span v-if="o.seller_org">
                        by {{ o.seller_org.name }}<template v-if="o.seller_org.is_cgc"> · common-good</template>
                    </span>
                </p>
            </Card>
        </section>

        <!-- ---------------------------------------------------------- work -->
        <section v-if="tab === 'work'" aria-label="Work on offer">
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
        </section>

        <!-- ---------------------------------------------------- assistance -->
        <section v-if="tab === 'assistance'" aria-label="Requests for help">
            <p v-if="!assistance.length" class="econ-empty">Nobody is asking for help right now.</p>
            <Card v-for="a in assistance" :key="a.id" as="article" inset class="mkt-row">
                <h3 class="mkt-title">{{ a.title }}</h3>
                <p class="mkt-desc">{{ a.need }}</p>
                <p class="mkt-meta"><span>{{ a.status }}</span></p>
            </Card>
        </section>
        <nav v-if="pagination.previous || pagination.next" class="mkt-pages" aria-label="Market pages">
            <Link v-if="pagination.previous" :href="pagination.previous" :only="['offers', 'work', 'assistance', 'tab', 'pagination']" preserve-state rel="prev">Previous entries</Link>
            <Link v-if="pagination.next" :href="pagination.next" :only="['offers', 'work', 'assistance', 'tab', 'pagination']" preserve-state rel="next">Next entries</Link>
        </nav>
    </PageScaffold>
</template>

<style scoped>
.mkt-asset-picker { min-inline-size: 0; margin-block: 1rem; }
.mkt-selected-asset, .mkt-asset-search, .mkt-asset-pages { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; }
.mkt-selected-asset { padding: .75rem; background: var(--gov-surface-subtle); }
.mkt-asset-search input { flex: 1; min-inline-size: 12rem; }
.mkt-asset-search button, .mkt-selected-asset button, .mkt-asset-pages a { min-block-size: 44px; padding: .5rem .75rem; }
.mkt-asset-option { display: flex; align-items: center; gap: .5rem; padding-block: .5rem; min-block-size: 44px; }
.mkt-asset-pages { margin-block-start: .75rem; }
.mkt-asset-error { color: var(--gov-danger); font-size: .875rem; }
.mkt-asset-picker button:focus-visible, .mkt-asset-picker input:focus-visible, .mkt-asset-picker a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.mkt-pages { display: flex; flex-wrap: wrap; gap: 1rem; margin-block-start: 1rem; }
.mkt-pages a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .5rem 1rem; border: 1px solid var(--gov-border); border-radius: .4rem; }
.mkt-pages a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.mkt-compose summary { cursor: pointer; padding: .75rem; min-block-size: 44px; border: 1px solid var(--gov-border); border-radius: .4rem; font-weight: 600; }
.mkt-compose[open] summary { margin-block-end: .75rem; }
.mkt-compose summary:focus-visible, .mkt-tab:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.mkt-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-block-end: var(--space-4);
}
.mkt-tab {
    text-decoration: none;
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
