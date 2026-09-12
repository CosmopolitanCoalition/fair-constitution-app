<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Icon from '@/Components/Ui/Icon.vue';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    places: { type: Object, required: true },
    parent: { type: Object, default: null },
    jurisdictionContext: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
    scope: { type: String, default: null },
});
const { t, n } = useI18n({ useScope: 'global' });
const page = usePage();
const search = ref(props.filters.search ?? '');
const loading = ref(false);
watch(() => props.filters.search, value => { search.value = value ?? ''; });
const home = computed(() => page.props.homeJurisdiction?.current);
const chain = computed(() => props.jurisdictionContext?.chain ?? []);
const title = computed(() => props.parent ? t('places.inside', { name: props.parent.name }) : t('places.worlds'));
const parentBrowse = computed(() => {
    const ancestor = chain.value.at(-2);
    return ancestor ? `/jurisdictions?parent=${encodeURIComponent(ancestor.slug)}` : '/jurisdictions?scope=roots';
});
const browseHref = place => `/jurisdictions?parent=${encodeURIComponent(place.slug)}`;
const overviewHref = place => `/jurisdictions/${encodeURIComponent(place.slug)}`;
function findPlaces() {
    router.get('/jurisdictions', {
        parent: props.parent?.slug,
        scope: props.scope || undefined,
        search: search.value.trim() || undefined,
    }, { preserveState: true, preserveScroll: true, onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; } });
}
</script>

<template>
    <Head :title="t('places.title')" />
    <div class="places-browser stack">
        <header class="places-browser__header">
            <div>
                <p class="eyebrow">{{ t('places.title') }}</p>
                <h1>{{ title }}</h1>
                <p class="gloss">{{ t('places.intro') }}</p>
            </div>
            <div class="cluster">
                <Btn :as="Link" href="/jurisdictions" variant="secondary" icon="globe">{{ t('places.browse_world') }}</Btn>
                <Btn v-if="home?.slug" :as="Link" :href="overviewHref(home)" variant="ghost" icon="map-pin">{{ t('places.my_place') }}</Btn>
            </div>
        </header>

        <nav v-if="chain.length" class="places-browser__trail" :aria-label="t('places.trail')">
            <Link href="/jurisdictions?scope=roots">{{ t('places.worlds') }}</Link>
            <template v-for="place in chain" :key="place.id">
                <span aria-hidden="true">›</span>
                <Link :href="browseHref(place)" :aria-current="place.id === parent?.id ? 'location' : undefined">{{ place.name }}</Link>
            </template>
        </nav>

        <section class="places-browser__scope">
            <div v-if="parent" class="cluster">
                <Btn :as="Link" :href="parentBrowse" variant="ghost" icon="arrow-left">{{ t('places.up') }}</Btn>
                <Link :href="overviewHref(parent)">{{ t('places.open_place', { name: parent.name }) }}</Link>
                <Link :href="`${overviewHref(parent)}/map`">{{ t('places.boundary_map') }}</Link>
            </div>
            <form class="places-browser__search" role="search" @submit.prevent="findPlaces">
                <label for="place-name">{{ parent ? t('places.search_inside', { name: parent.name }) : t('places.search_worlds') }}</label>
                <div class="cluster">
                    <input id="place-name" v-model="search" type="search" maxlength="120" :placeholder="t('places.name_placeholder')" />
                    <Btn type="submit" variant="primary" :disabled="loading">{{ t('places.search') }}</Btn>
                    <Btn v-if="filters.search" type="button" variant="ghost" @click="search = ''; findPlaces()">{{ t('places.clear') }}</Btn>
                </div>
            </form>
        </section>

        <section :aria-label="title" :aria-busy="loading" class="places-browser__results">
            <ul v-if="places.data.length" class="places-browser__grid">
                <li v-for="place in places.data" :key="place.id" class="place-card">
                    <AdmChip :level="place.adm_level" :label="t(`places.level_${Math.min(Number(place.adm_level), 6)}`)" />
                    <h2><Link :href="overviewHref(place)">{{ place.name }}</Link></h2>
                    <p class="gloss">{{ place.population > 0 ? t('places.population', { count: n(Number(place.population)) }) : t('places.population_unknown') }}</p>
                    <div class="place-card__actions">
                        <Link v-if="place.has_children" :href="browseHref(place)" class="btn btn--secondary btn--sm">
                            {{ t('places.explore_inside') }} <Icon name="chevron-right" size="sm" />
                        </Link>
                        <Link v-else :href="overviewHref(place)" class="btn btn--secondary btn--sm">{{ t('places.open') }}</Link>
                        <Link v-if="place.legislature_id" :href="`/legislatures/${place.legislature_id}/districts`" class="place-card__maps">
                            <Icon name="map" size="sm" /> {{ t('places.legislative_maps') }}
                        </Link>
                    </div>
                </li>
            </ul>
            <div v-else class="places-browser__empty">
                <h2>{{ filters.search ? t('places.no_matches') : t('places.no_children') }}</h2>
                <p class="gloss">{{ filters.search ? t('places.try_name') : t('places.leaf_hint') }}</p>
                <Link v-if="parent" :href="overviewHref(parent)">{{ t('places.open_place', { name: parent.name }) }}</Link>
            </div>
        </section>

        <nav class="cluster places-browser__paging" :aria-label="t('places.pagination')">
            <Btn v-if="places.prev_page_url" :as="Link" :href="places.prev_page_url" variant="secondary">{{ t('places.previous') }}</Btn>
            <span class="gloss">{{ t('places.page', { number: places.current_page }) }}</span>
            <Btn v-if="places.next_page_url" :as="Link" :href="places.next_page_url" variant="secondary">{{ t('places.next') }}</Btn>
        </nav>
        <p v-if="page.props.auth?.user?.is_operator" class="gloss"><Link href="/jurisdictions?view=operations">{{ t('places.host_registry') }}</Link></p>
    </div>
</template>

<style scoped>
.places-browser { padding: clamp(1rem, 3vw, 2.5rem); gap: var(--space-5); }
.places-browser__header { display: flex; justify-content: space-between; flex-wrap: wrap; align-items: end; gap: var(--space-4); }
.places-browser__header h1 { margin-block: var(--space-2); font-size: clamp(1.7rem, 3vw, 2.5rem); }
.places-browser__trail { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.places-browser__trail [aria-current] { font-weight: 700; }
.places-browser__scope { display: grid; gap: var(--space-4); padding: var(--space-4); background: var(--gov-surface); border: 1px solid var(--gov-border); border-radius: var(--radius-lg); }
.places-browser__search { display: grid; gap: var(--space-2); }
.places-browser__search input { min-inline-size: 0; flex: 1; max-inline-size: 34rem; padding: 0.7rem; background: var(--gov-bg); color: var(--gov-fg); border: 1px solid var(--gov-border); border-radius: var(--radius-md); }
.places-browser__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr)); gap: var(--space-4); list-style: none; margin: 0; padding: 0; }
.place-card { display: flex; flex-direction: column; align-items: start; gap: var(--space-2); padding: var(--space-5); border: 1px solid var(--gov-border); border-radius: var(--radius-lg); background: var(--gov-surface); }
.place-card h2 { font-size: var(--text-lg); margin: 0; overflow-wrap: anywhere; }
.place-card h2 a { color: inherit; }
.place-card__actions { margin-block-start: auto; padding-block-start: var(--space-3); display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); }
.place-card__maps { display: inline-flex; gap: var(--space-1); align-items: center; font-size: var(--text-sm); }
.places-browser__empty { padding: var(--space-6); border: 1px dashed var(--gov-border); border-radius: var(--radius-lg); }
.places-browser__paging { justify-content: center; }
</style>
