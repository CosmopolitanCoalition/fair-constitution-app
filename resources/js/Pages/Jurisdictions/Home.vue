<script setup>
/**
 * Jurisdictions/Home — a PLACE's own page (operator 2026-09-10: "The breadcrumbs
 * and links take me to a map view. Not the jurisdictions home page. The
 * jurisdiction viewer from setup was the MAP viewer and not the jurisdiction
 * itself."). /jurisdictions/{slug} lands here; the full-bleed map viewer lives
 * at /jurisdictions/{slug}/map and is one button away.
 *
 * Reads the same props JurisdictionController builds for the viewer, so the
 * page needs no new queries: identity, the ancestor chain, the institutions
 * that exist (legislature / chamber / districts, executive, courts, the
 * current election), how many places sit inside, and the activation state.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

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
const activationLabel = computed(() => {
    const s = props.activation?.state;
    if (!s) return null;
    // JurisdictionActivation::STATE_ORDER, in plain words.
    return {
        boundary_loaded: 'Mapped, not yet awake',
        critical_population: 'Enough people to start',
        bootstrapping: 'Setting up its government',
        self_governing: 'Self-governing',
    }[s] ?? s.replace(/_/g, ' ');
});
const plain = (v) => String(v ?? '').replace(/_/g, ' ');
</script>

<template>
    <PageScaffold :surface="surface" :title="j.name">
        <template #intro>
            <template v-if="parent">{{ j.name }} is a {{ kind }} in {{ parent.name }}.</template>
            <template v-else>{{ j.name }} is the whole world of this instance.</template>
            <template v-if="people"> About {{ people }} people live here<template v-if="j.population_year"> ({{ j.population_year }} figures)</template>.</template>
            <template v-if="hasChildren"> It contains {{ childCount.toLocaleString() }} smaller place{{ childCount === 1 ? '' : 's' }}.</template>
        </template>

        <!-- where it sits -->
        <div class="cluster" aria-label="Where this place sits">
            <template v-for="(a, i) in ancestors" :key="a.id">
                <Link :href="`/jurisdictions/${a.slug}`"><AdmChip :level="a.adm_level" :label="a.name" /></Link>
                <span aria-hidden="true">›</span>
            </template>
            <AdmChip :level="j.adm_level" :label="j.name" />
            <StatusBadge v-if="activationLabel" :tone="activation?.state === 'self_governing' ? 'success' : 'neutral'">{{ activationLabel }}</StatusBadge>
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
                                <span class="gloss">seated</span>
                                <div class="cluster"><Link :href="`/legislatures/${legislature_id}/chamber`">The chamber</Link><Link :href="`/legislatures/${j.slug}/districts`">Districts</Link></div>
                            </template>
                            <template v-else-if="legislature_id && has_district_map">
                                <span class="gloss">districts drawn, seats not yet filled</span>
                                <div class="cluster"><Link :href="`/legislatures/${j.slug}/districts`">Districts</Link></div>
                            </template>
                            <template v-else-if="legislature_id">
                                <span class="gloss">forming</span>
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

            <!-- what is happening / where to go -->
            <Card as="section" title="Take part">
                <ul class="gov">
                    <li><Icon name="message-square" size="sm" /><div><Link href="/civic/square">The public square</Link><span class="gloss">what people here are saying</span></div></li>
                    <li><Icon name="file-text" size="sm" /><div><Link href="/civic/petitions">Petitions</Link><span class="gloss">start one or sign one</span></div></li>
                    <li><Icon name="users" size="sm" /><div><Link href="/civic/commons/square">Live rooms</Link><span class="gloss">meet, talk, vote together</span></div></li>
                    <li><Icon name="globe" size="sm" /><div><Link :href="map_href">Places inside, on the map</Link><span class="gloss">{{ hasChildren ? `${childCount.toLocaleString()} places` : 'the boundary' }}</span></div></li>
                </ul>
                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn :as="Link" :href="map_href" variant="primary" icon="map-pin">Open the map</Btn>
                    <Btn :as="Link" href="/jurisdictions" variant="ghost">All places</Btn>
                </div>
            </Card>
        </div>

        <p v-if="j.official_languages && j.official_languages.length" class="gloss">
            Official languages: {{ j.official_languages.join(', ') }}.
        </p>
    </PageScaffold>
</template>

<style scoped>
.gov {
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

.gov li > div {
    display: grid;
    gap: var(--space-1);
}
</style>
