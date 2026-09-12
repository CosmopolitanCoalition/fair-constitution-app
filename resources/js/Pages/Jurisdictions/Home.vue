<script setup>
/**
 * Jurisdictions/Home — a PLACE's own page (operator 2026-09-10: the front
 * door, not the map). /jurisdictions/{slug} lands here; the full-bleed map
 * viewer lives at /jurisdictions/{slug}/map, one rail click away.
 *
 * Layout (design panel 2026-09-10, "One Context Bar"): the shell's header
 * chain is the breadcrumb (jurisdictionContext); the JurisdictionRail on the
 * left carries the general jurisdiction tools; the content fills the 120rem
 * canvas with bands the props already carry — no page-local breadcrumb, no
 * void. Every query behind this page is bounded (children preview LIMIT 12).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import JurisdictionRail from '@/Components/Shell/JurisdictionRail.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    surface: { type: Object, required: true },
    jurisdiction: { type: Object, required: true },
    ancestors: { type: Array, default: () => [] },
    childCount: { type: Number, default: 0 },
    hasChildren: { type: Boolean, default: false },
    legislature_id: { type: String, default: null },
    executive_id: { type: String, default: null },
    judiciary_id: { type: String, default: null },
    has_district_map: { type: Boolean, default: false },
    chamber_seated: { type: Boolean, default: false },
    current_election: { type: Object, default: null },
    activation: { type: Object, default: null },
    reach: { type: Object, default: null },
    meta: { type: Object, default: null },
    seats: { type: Number, default: null },
    children_preview: { type: Array, default: () => [] },
    tools: { type: Array, default: () => [] },
    map_href: { type: String, required: true },
});

const j = computed(() => props.jurisdiction);
const parent = computed(() => (props.ancestors.length ? props.ancestors[props.ancestors.length - 1] : null));
const kind = computed(() => (j.value.adm_label || (j.value.adm_level === 0 ? 'planet' : 'place')).toLowerCase());
const people = computed(() => {
    const n = Number(j.value.population ?? 0);
    return n > 0 ? n.toLocaleString() : null;
});
const electionLive = computed(() => {
    const s = props.current_election?.status;
    return s && !['final', 'cancelled'].includes(s);
});
const plain = (v) => String(v ?? '').replace(/_/g, ' ');

// JurisdictionActivation::STATE_ORDER, in plain words.
const ACTIVATION = {
    boundary_loaded: 'Mapped, not yet awake',
    critical_population: 'Enough people to start',
    bootstrapping: 'Setting up its government',
    self_governing: 'Self-governing',
};
const activation = computed(() => {
    const s = props.activation?.state;
    if (!s) return null;
    return { label: ACTIVATION[s] ?? plain(s), tone: s === 'self_governing' ? 'success' : 'neutral' };
});

// Reach: verified residents over the measured population (legitimacy_snapshots).
const reachPct = computed(() => {
    const m = props.reach?.ratio_micro;
    return typeof m === 'number' ? `${(m / 10000).toFixed(m >= 100000 ? 0 : 1)}%` : null;
});

const railPlace = computed(() => ({
    name: j.value.name,
    kind: kind.value,
    population: j.value.population,
    activation: activation.value,
}));

const fmt = (n) => Number(n ?? 0).toLocaleString();
</script>

<template>
    <PageScaffold :surface="surface" :title="j.name">
        <template #intro>
            <template v-if="parent">{{ j.name }} is a {{ kind }} in {{ parent.name }}.</template>
            <template v-else>{{ j.name }} is the whole world of this instance.</template>
            <template v-if="people"> About {{ people }} people live here<template v-if="j.population_year"> ({{ j.population_year }} figures)</template>.</template>
            <template v-if="hasChildren"> It contains {{ fmt(childCount) }} smaller place{{ childCount === 1 ? '' : 's' }}.</template>
        </template>

        <div class="place-layout">
            <JurisdictionRail :place="railPlace" :tools="tools" />

            <div class="stack place-body">
                <Card as="section" :title="t('places.legislative_maps')" class="place-maps">
                    <p class="gloss">{{ legislature_id ? t('places.map_intro') : t('places.parent_map_hint') }}</p>
                    <div class="cluster">
                        <Btn v-if="legislature_id" :as="Link" :href="`/legislatures/${legislature_id}/districts`" variant="primary" icon="map">{{ t('places.district_map') }}</Btn>
                        <Btn v-if="legislature_id && hasChildren" :as="Link" :href="`/legislatures/${legislature_id}/panels`" variant="secondary">{{ t('places.panels_map') }}</Btn>
                        <Btn v-if="!legislature_id && parent" :as="Link" :href="`/jurisdictions/${parent.slug}`" variant="primary">{{ t('places.parent_map', { name: parent.name }) }}</Btn>
                        <Btn :as="Link" :href="map_href" variant="ghost" icon="map-pin">{{ t('places.boundary_map') }}</Btn>
                    </div>
                </Card>

                <!-- at a glance -->
                <div class="cluster place-stats" aria-label="At a glance">
                    <Stat :value="people ?? '—'" label="people" />
                    <Stat :value="hasChildren ? fmt(childCount) : '0'" label="places inside" />
                    <Stat :value="seats !== null ? fmt(seats) : '—'" :label="seats !== null ? 'seats in its legislature' : 'no legislature yet'" />
                    <Stat :value="reachPct ?? '—'" :label="reachPct ? 'of people confirmed here' : 'reach not measured yet'" accent />
                    <StatusBadge v-if="activation" :tone="activation.tone">{{ activation.label }}</StatusBadge>
                </div>

                <div class="grid-2">
                    <!-- its government -->
                    <Card as="section" title="Its government">
                        <ul class="gov">
                            <li>
                                <Icon name="landmark" size="sm" />
                                <div>
                                    <strong>Legislature</strong>
                                    <template v-if="legislature_id && chamber_seated">
                                        <span class="gloss">seated<template v-if="seats"> · {{ fmt(seats) }} seats</template></span>
                                        <div class="cluster"><Link :href="`/legislatures/${legislature_id}/chamber`">The chamber</Link><Link :href="`/legislatures/${legislature_id}/districts`">{{ t('places.legislative_maps') }}</Link></div>
                                    </template>
                                    <template v-else-if="legislature_id && has_district_map">
                                        <span class="gloss">districts drawn, seats not yet filled<template v-if="seats"> · {{ fmt(seats) }} seats</template></span>
                                        <div class="cluster"><Link :href="`/legislatures/${legislature_id}/districts`">Districts</Link></div>
                                    </template>
                                    <template v-else-if="legislature_id">
                                        <span class="gloss">forming</span>
                                        <Link :href="`/legislatures/${legislature_id}/chamber`">The chamber</Link>
                                    </template>
                                    <span v-else class="gloss">none (a leaf place; it is represented in {{ parent?.name ?? 'its parent' }})</span>
                                </div>
                            </li>
                            <li>
                                <Icon name="briefcase" size="sm" />
                                <div>
                                    <strong>Executive</strong>
                                    <div v-if="executive_id" class="cluster"><Link :href="`/executives/${executive_id}`">The executive</Link></div>
                                    <span v-else class="gloss">none yet</span>
                                </div>
                            </li>
                            <li>
                                <Icon name="scale" size="sm" />
                                <div>
                                    <strong>Courts</strong>
                                    <div v-if="judiciary_id" class="cluster"><Link :href="`/judiciaries/${judiciary_id}`">The courts</Link></div>
                                    <span v-else class="gloss">none yet</span>
                                </div>
                            </li>
                            <li>
                                <Icon name="vote" size="sm" />
                                <div>
                                    <strong>Elections</strong>
                                    <div v-if="current_election" class="cluster">
                                        <Link :href="`/elections/${current_election.id}`">{{ electionLive ? 'An election is under way' : 'The last election' }}</Link>
                                        <StatusBadge v-if="electionLive" tone="warning">{{ plain(current_election.status) }}</StatusBadge>
                                    </div>
                                    <span v-else class="gloss">none scheduled</span>
                                </div>
                            </li>
                        </ul>
                    </Card>

                    <!-- take part -->
                    <Card as="section" title="Take part">
                        <p style="margin-block-end: var(--space-3)"><Btn :as="Link" :href="`/explore?jurisdiction=${encodeURIComponent(j.slug)}`" variant="secondary" icon="users">{{ t('places.explore_roles') }}</Btn></p>
                        <ul class="gov">
                            <li><Icon name="message-square" size="sm" /><div><Link :href="`/civic/square?jurisdiction=${j.id}`">The public square</Link><span class="gloss">what people here are saying</span></div></li>
                            <li><Icon name="file-text" size="sm" /><div><Link :href="`/civic/petitions?jurisdiction=${j.id}`">Petitions</Link><span class="gloss">start one or sign one</span></div></li>
                            <li><Icon name="users" size="sm" /><div><Link :href="`/civic/commons/square?jurisdiction=${j.id}`">Live rooms</Link><span class="gloss">meet, talk, vote together</span></div></li>
                            <li><Icon name="globe" size="sm" /><div><Link :href="map_href">The map</Link><span class="gloss">{{ hasChildren ? `${fmt(childCount)} places inside` : 'the boundary' }}</span></div></li>
                        </ul>
                        <div class="cluster" style="margin-block-start: var(--space-3)">
                            <Btn :as="Link" :href="map_href" variant="primary" icon="map-pin">Open the map</Btn>
                            <Btn :as="Link" href="/jurisdictions" variant="ghost">All places</Btn>
                        </div>
                    </Card>

                    <!-- places inside (bounded) -->
                    <Card v-if="children_preview.length" as="section" :title="childCount > children_preview.length ? 'The largest places inside' : 'The places inside'">
                        <ul class="places">
                            <li v-for="c in children_preview" :key="c.id">
                                <Link :href="`/jurisdictions/${c.slug}`"><AdmChip :level="c.adm_level" :label="c.name" /></Link>
                                <span class="gloss">{{ c.population > 0 ? `${fmt(c.population)} people` : 'population not measured' }}</span>
                            </li>
                        </ul>
                        <div class="cluster" style="margin-block-start: var(--space-3)">
                            <Btn :as="Link" :href="`/jurisdictions?parent=${encodeURIComponent(j.slug)}`" variant="secondary">{{ t('places.browse_all_inside', { count: fmt(childCount) }) }}</Btn>
                            <Link href="/jurisdictions">{{ t('places.browse_world') }}</Link>
                        </div>
                    </Card>

                    <!-- region and dataset -->
                    <Card v-if="meta || j.source || (j.official_languages && j.official_languages.length)" as="section" title="Region and dataset">
                        <dl class="facts">
                            <template v-if="meta?.boundary_canonical && meta.boundary_canonical !== j.name"><dt>Boundary name</dt><dd>{{ meta.boundary_canonical }}</dd></template>
                            <template v-if="meta?.continent"><dt>Continent</dt><dd>{{ meta.continent }}</dd></template>
                            <template v-if="meta?.unsdg_region"><dt>UN region</dt><dd>{{ meta.unsdg_region }}<template v-if="meta.unsdg_subregion"> · {{ meta.unsdg_subregion }}</template></dd></template>
                            <template v-if="meta?.world_bank_income_group"><dt>Income group</dt><dd>{{ meta.world_bank_income_group }}</dd></template>
                            <template v-if="j.adm_level > 0 && j.official_languages && j.official_languages.length"><dt>Languages</dt><dd>{{ j.official_languages.join(', ') }}</dd></template>
                            <template v-if="j.iso_code"><dt>Code</dt><dd>{{ j.iso_code }}</dd></template>
                            <template v-if="j.source"><dt>Boundary source</dt><dd>{{ plain(j.source) }}<template v-if="meta?.year_represented"> · {{ meta.year_represented }}</template></dd></template>
                            <template v-if="j.population_year"><dt>Population figures</dt><dd>{{ j.population_year }}</dd></template>
                        </dl>
                    </Card>
                </div>
            </div>
        </div>
    </PageScaffold>
</template>

<style scoped>
.place-layout {
    display: grid;
    grid-template-columns: 15rem minmax(0, 1fr);
    gap: var(--space-6);
    align-items: start;
}
@media (max-width: 63.99rem) {
    .place-layout { grid-template-columns: minmax(0, 1fr); }
}
.place-body { gap: var(--space-6); }
.place-maps { border-inline-start: 3px solid var(--gov-primary); }
.place-maps .cluster { margin-block-start: var(--space-3); }
.place-stats { gap: var(--space-6); align-items: end; }

.gov, .places {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: var(--space-3);
}
.gov li {
    display: grid;
    grid-template-columns: 1.25rem 1fr;
    gap: var(--space-2);
    align-items: start;
}
.gov li > div { display: grid; gap: var(--space-1); }
.places { gap: var(--space-2); }
.places li { display: flex; align-items: baseline; justify-content: space-between; gap: var(--space-3); }
.facts { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: var(--space-1) var(--space-4); margin: 0; }
.facts dt { color: var(--gov-fg-muted); }
.facts dd { margin: 0; }
</style>
