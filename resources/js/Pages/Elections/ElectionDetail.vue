<script setup>
/**
 * Elections/ElectionDetail — FE-B2 (PHASE_B_DESIGN_frontend.md §B.1;
 * mockups/electoral/election-detail.html).
 *
 * Sections: ESM-03 StateStrip + phase badge · stat row · F-ELB-001 order
 * record (read-only inset) · 5-row schedule DataTable (done/current/
 * upcoming, server-computed) · AmendableSetting ×2 · races table with
 * pre-published X · real Leaflet boundary (replaces the mockup's stylized
 * SVG) · other elections · phase CTAs (approval → open ballot/candidacy;
 * ranked → ranked ballot; certifying → results + R-08 certify/recount).
 *
 * Edge modes: `election === null` renders the CLK-01 empty state (the
 * /elections jurisdiction-scoped resolver); `blockers[]` non-empty renders
 * the Art. II §8 subdivision banner with every schedule row 'upcoming'.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AmendableSetting from '@/Components/Ui/AmendableSetting.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import { electionKindLabel } from '@/lib/electionKind.js';
import { addProtomapsBasemap } from '@/lib/protomapsBasemap.js';

const titleCase = (t) => (t ? t.charAt(0).toUpperCase() + t.slice(1) : t);
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-1 pilot (MASTER_PLAN): this page rides the v3 player chrome —
   floating header, tour-as-a-mode, bottom command bar (Menu + Learn). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    election: { type: Object, default: null },
    machine: { type: Array, default: () => [] },
    currentState: { type: String, default: null },
    stats: { type: Object, default: null },
    myRace: { type: Object, default: null },
    races: { type: Array, default: () => [] },
    blockers: { type: Array, default: () => [] },
    others: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ certify: false, recount: false }) },
    certification: { type: Object, default: null },
    /** Empty-state payload from the /elections resolver (election === null). */
    empty: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});
/* An emergency is in effect somewhere in the viewer's footprint. The shell
   already surfaces WHICH powers; this page adds the election-specific
   reassurance that an emergency cannot suspend the vote (Art. II §7). */
const emergenciesActive = computed(() => (page.props.app?.activeEmergencies?.length ?? 0) > 0);

const blocked = computed(() => props.blockers.length > 0);
const phase = computed(() => props.election?.phase ?? null);
const scheduled = computed(() => props.election?.status === 'scheduled');

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');

/* ─────────────────────────────────────────────── schedule table rows */

const scheduleColumns = [
    { key: 'stage', label: 'Stage' },
    { key: 'when', label: 'When (your timezone)' },
    { key: 'status', label: 'Status' },
];

const scheduleRows = computed(() =>
    (props.election?.schedule ?? []).map((row) => ({
        ...row,
        when: fmt(row.at),
    })),
);

const raceColumns = [
    { key: 'label', label: 'Race' },
    { key: 'seats', label: 'Seats', align: 'right' },
    { key: 'finalist_count', label: 'Finalist places', align: 'right' },
    { key: 'candidate_count', label: 'Candidates', align: 'right' },
    { key: 'links', label: '' },
];

/* ──────────────────────────────────── certify / recount (R-08 only) */

const certifyForm = useForm({});
function submitCertify() {
    certifyForm.post(`/elections/${props.election.id}/certify`, { preserveScroll: true });
}

const recountForm = useForm({ cause: '' });
const recountConfirming = ref(false);
function submitRecount() {
    recountForm.post(`/elections/${props.election.id}/recount`, {
        preserveScroll: true,
        onSuccess: () => {
            recountConfirming.value = false;
            recountForm.reset();
        },
    });
}

/* ─────────────────────────────── jurisdiction boundary map (Leaflet) */

const mapEl = ref(null);
const basemapUnavailable = ref(false);
const boundaryUnavailable = ref(false);
let map = null;
let mapDisposed = false;
const boundaryRequest = new AbortController();

async function mountMap() {
    if (!props.election?.jurisdiction?.id || !mapEl.value || map) return;

    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');
    if (mapDisposed || !mapEl.value) return;

    map = L.map(mapEl.value, { zoomControl: true, attributionControl: true, worldCopyJump: true });
    map.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>',
    );
    map.attributionControl.addAttribution(
        'Boundaries &copy; <a href="https://www.geoboundaries.org/" target="_blank" rel="noopener">geoBoundaries</a>',
    );
    map.setView([20, 0], 2);
    const target = map;

    // Use the app's cartography, source fallback and attribution. Protomaps
    // needs paintRules + labelRules; a URL and flavor alone paint no geography.
    // Start this independently so a missing tile archive cannot hide the outline.
    addProtomapsBasemap(target).then((available) => {
        if (!mapDisposed && map === target) basemapUnavailable.value = !available;
    });

    try {
        const res = await fetch(
            `/api/jurisdictions/${props.election.jurisdiction.id}/self.geojson?zoom=8`,
            { credentials: 'same-origin', signal: boundaryRequest.signal },
        );
        if (!res.ok) throw new Error('Boundary unavailable');
        const geojson = await res.json();
        if (mapDisposed || map !== target) return;
        if (!geojson?.features?.length) throw new Error('Boundary unavailable');
        const layer = L.geoJSON(geojson, {
            style: { color: '#56b4e9', weight: 2, fillColor: '#56b4e9', fillOpacity: 0.18 },
        }).addTo(target);
        if (!layer.getBounds().isValid()) throw new Error('Boundary unavailable');
        target.fitBounds(layer.getBounds(), { padding: [16, 16] });
    } catch {
        if (!mapDisposed) boundaryUnavailable.value = true;
    }
}

onMounted(() => nextTick(mountMap));
onBeforeUnmount(() => {
    mapDisposed = true;
    boundaryRequest.abort();
    if (map) map.remove();
    map = null;
});

const hasDistricts = computed(() => props.races.some((race) => !race.at_large));
</script>

<template>
    <PageScaffold
        :surface="surface"
        :title="election ? `${titleCase(election.kind_label ?? electionKindLabel(election.kind))} — ${election.jurisdiction.name}` : 'Elections'"
    >
        <template #intro>
            <template v-if="election">
                This is the {{ election.kind_label ?? electionKindLabel(election.kind) }} for
                {{ election.jurisdiction.name }}<template v-if="election.jurisdiction.adm_label">, a {{ election.jurisdiction.adm_label.toLowerCase() }}</template>.
            </template>
            Review the schedule, explore the districts, and open a ballot. Residents can stand
            for office, approve candidates, and rank the finalists when ranked voting opens.
        </template>
        <template #about>
            <p>
                Approval voting identifies the finalists for each race. Voters then rank those
                finalists, and the election board counts and certifies the result. The published
                schedule below shows the current stage.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>
        <!-- Art. II §7 — an emergency cannot suspend an election. -->
        <Banner v-if="emergenciesActive" tone="info" role="status" title="This election proceeds — an emergency cannot suspend it.">
            Emergency powers are limited and can never suspend an election, a vote, or a
            candidacy. Whatever emergency is in effect, this election runs on its clock,
            untouched. <span class="citation">Art. II §7</span>
        </Banner>

        <!-- ───────────────────────────────────── empty mode (resolver) -->
        <template v-if="!election">
            <Card as="section" :title="empty?.place ? 'No open election' : 'Explore elections'">
                <p>
                    {{ empty?.place ? `No open election was found for ${empty.place.name}.` : 'Choose a place to explore its elections.' }}
                    <template v-if="empty?.interval">The configured election interval is {{ empty.interval }} months.</template>
                </p>
                <p v-if="empty?.clk01DueAt" style="margin-block-start: var(--space-2)">
                    Next general election is due
                    <strong>{{ fmt(empty.clk01DueAt) }}</strong>
                    <span class="citation"> · shown in your timezone</span>
                </p>
                <p style="margin-block-start: var(--space-3)"><Btn :as="Link" href="/jurisdictions">Choose a place</Btn></p>
            </Card>

            <Card v-if="others.length" as="section" title="Election records for this place">
                <ul class="others-list">
                    <li v-for="other in others" :key="other.election_id">
                        <Link :href="`/elections/${other.election_id}`">{{ other.jurisdiction_name }}</Link>
                        <span class="citation">
                            {{ electionKindLabel(other.kind) }} · {{ other.seats }} seats · {{ other.finalist_count }} finalist places ·
                            {{ other.phase }}
                        </span>
                    </li>
                </ul>
            </Card>
        </template>

        <!-- ─────────────────────────────────────────────── full mode -->
        <template v-else>
            <!-- Art. II §8 subdivision blocker -->
            <Banner
                v-if="blocked"
                tone="warning"
                title="District boundaries must be completed before voting opens."
            >
                A district map meeting this jurisdiction's configured seat limits must be activated
                before this election can open its approval phase.
                <span v-for="blocker in blockers" :key="blocker.detail" style="display: block">
                    {{ blocker.detail }}
                </span>
                <Link
                    v-if="election.legislature_id"
                    :href="`/legislatures/${election.legislature_id}`"
                >Open the Legislature browser build mode →</Link>
                <span class="citation" style="display: block">District boundaries must be settled before voting opens · Art. II §8</span>
            </Banner>

            <Card as="section">
                <template #title>
                    <h2>
                        Election lifecycle
                        <StatusBadge :tone="phase === 'approval' ? 'info' : phase === 'ranked' ? 'warning' : 'neutral'">
                            phase: {{ phase }}<template v-if="election.certSubStep"> · {{ election.certSubStep }}</template>
                        </StatusBadge>
                        <StatusBadge tone="neutral">{{ election.kind_label ?? electionKindLabel(election.kind) }}</StatusBadge>
                    </h2>
                </template>
                <StateStrip :states="machine" :current="currentState" />
                <template v-if="myRace">
                    <p style="margin-block-start: var(--space-3)"><strong>Your district:</strong> {{ myRace.label }}</p>
                    <div class="cluster" style="gap: var(--space-6)">
                        <Stat :value="myRace.seats" label="seats in your district" />
                        <Stat :value="myRace.finalist_count" label="finalist places in your district" accent />
                    </div>
                </template>
                <div v-if="stats" class="cluster" style="gap: var(--space-6); margin-block-start: var(--space-3)">
                    <Stat :value="stats.seats" :label="stats.races > 1 ? `seats across all ${stats.races} districts` : 'seats'" />
                    <Stat :value="stats.finalistPlaces" :label="stats.races > 1 ? 'finalist places across all districts' : 'finalist places'" accent />
                    <Stat :value="stats.validatedCandidates" label="candidates so far" />
                    <Stat :value="stats.stage" label="current stage" />
                </div>

                <!-- F-ELB-001 scheduling-order record (read-only) -->
                <Card inset style="margin-block-start: var(--space-4)">
                    <p class="cc-small">
                        Scheduling order <FormChip form-id="F-ELB-001" />
                        <template v-if="election.schedulingOrder">
                            — issued {{ fmt(election.schedulingOrder.issued_at) }} by
                            {{ election.schedulingOrder.board_name }}.
                        </template>
                        <template v-else>
                            — not yet issued; the schedule below carries the clock-armed defaults.
                        </template>
                        <span class="citation" style="display: block">
                            Each race's number of finalist places is published before the cutoff · Art. II §2
                        </span>
                    </p>
                </Card>
            </Card>

            <!-- ──────────────────────────────────────────── schedule -->
            <Card as="section" title="Schedule">
                <DataTable
                    :columns="scheduleColumns"
                    :rows="scheduleRows"
                    row-key="stage"
                    caption="Election schedule and current stage"
                >
                    <template #cell-status="{ row }">
                        <StatusBadge
                            :tone="row.status === 'done' ? 'success' : row.status === 'current' ? 'info' : 'neutral'"
                        >{{ row.status }}</StatusBadge>
                    </template>
                </DataTable>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    All times stored as UTC, shown in your timezone.
                </p>
                <p style="margin-block-start: var(--space-3)">
                    <AmendableSetting
                        :value="`${election.interval.value} ${election.interval.unit}`"
                        :setting-key="election.interval.settingKey"
                        :citation="election.interval.citation"
                        label="Election interval"
                    />
                    {{ ' ' }}
                    <AmendableSetting
                        :value="`${election.finalistMultiplier.value}× seats`"
                        :setting-key="election.finalistMultiplier.settingKey"
                        label="Finalist places per seat"
                        citation="The multiplier sets how many candidates advance to ranked voting."
                    />
                </p>
            </Card>

            <!-- ─────────────────────────────────── races + boundary -->
            <Card as="section" title="Districts & finalist places">
                <p class="gloss">
                    Each race publishes how many candidates can advance to its ranked ballot
                    <strong>before</strong> approval voting closes.
                </p>
                <DataTable
                    v-if="races.length"
                    :columns="raceColumns"
                    :rows="races"
                    row-key="id"
                    caption="Races in this election"
                >
                    <template #cell-links="{ row }">
                        <Link :href="`/elections/${election.id}/open-ballot?race=${row.id}`">Open ballot</Link>
                    </template>
                </DataTable>
                <p v-else class="gloss">
                    No races exist yet — race generation is pending
                    {{ blocked ? 'subdivision (Art. II §8)' : 'the scheduling order' }}.
                </p>
            </Card>

            <div class="grid-2">
                <Card as="section" title="Race boundary">
                    <div
                        ref="mapEl"
                        class="boundary-map"
                        role="region"
                        :aria-label="`Map of the ${election.jurisdiction.name} election boundary`"
                    ></div>
                    <p v-if="basemapUnavailable" class="gloss" role="status">
                        {{ $t('c_elections.map.tiles_unavailable', 'Geographic map tiles are unavailable. The boundary outline can still be viewed.') }}
                    </p>
                    <p v-if="boundaryUnavailable" class="gloss" role="status">
                        {{ $t('c_elections.map.boundary_unavailable', 'The boundary outline is unavailable for this place.') }}
                    </p>
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        <template v-if="hasDistricts">
                            This chamber is subdivided —
                            <Link v-if="election.legislature_id" :href="`/legislatures/${election.legislature_id}`">
                                view the district map in the Legislature browser →
                            </Link>
                        </template>
                        <template v-else>
                            This election uses a single at-large footprint.
                        </template>
                    </p>
                </Card>

                <Card as="section" title="More elections in this place">
                    <ul v-if="others.length" class="others-list">
                        <li v-for="other in others" :key="other.election_id">
                            <Link :href="`/elections/${other.election_id}`">{{ other.jurisdiction_name }}</Link>
                            <span class="citation">
                                {{ electionKindLabel(other.kind) }} · {{ other.seats }} seats · {{ other.finalist_count }} finalist places ·
                                {{ other.phase }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="gloss">No other elections are recorded for this place.</p>
                    <Link href="/jurisdictions">Explore elections in another place →</Link>
                </Card>
            </div>

            <!-- ──────────────────────────────────────── phase actions -->
            <Card as="section" title="Participate">
                <div class="cluster">
                    <template v-if="phase === 'approval' && !scheduled">
                        <Btn :as="Link" :href="`/elections/${election.id}/open-ballot`" variant="primary" icon="vote">
                            Open ballot — approve candidates
                        </Btn>
                        <Btn :as="Link" :href="`/elections/${election.id}/candidacy`" variant="secondary" icon="user">
                            Stand for office
                        </Btn>
                    </template>
                    <template v-else-if="phase === 'ranked'">
                        <Btn :as="Link" :href="`/elections/${election.id}/ranked-ballot`" variant="gold" icon="check">
                            Rank your ballot
                        </Btn>
                    </template>
                    <template v-else-if="phase === 'certifying'">
                        <Btn :as="Link" :href="`/elections/${election.id}/results`" variant="primary" icon="bar-chart">
                            Watch the count
                        </Btn>
                    </template>
                    <span v-if="scheduled" class="gloss">
                        The approval phase has not opened yet — participation unlocks the moment it
                        does.
                    </span>
                </div>

                <!-- R-08 board actions (role-gated server-side; engine is the boundary) -->
                <div v-if="phase === 'certifying' && (can.certify || can.recount)" style="margin-block-start: var(--space-4)">
                    <div class="cluster">
                        <Btn
                            v-if="can.certify"
                            variant="primary"
                            icon="check"
                            :disabled="certifyForm.processing || certification !== null"
                            @click="submitCertify"
                        >
                            {{ certifyForm.processing ? 'Certifying…' : 'Certify results' }}
                        </Btn>
                        <Btn
                            v-if="can.recount"
                            variant="secondary"
                            icon="refresh-cw"
                            :disabled="certification === null"
                            :title="certification === null ? 'Requires certification first' : null"
                            @click="recountConfirming = !recountConfirming"
                        >
                            Order recount
                        </Btn>
                    </div>
                    <form v-if="recountConfirming" novalidate style="margin-block-start: var(--space-3)" @submit.prevent="submitRecount">
                        <Field
                            label="Cause for the recount order"
                            hint="The engine rejects an empty cause — recounts are audit re-runs with recorded grounds, never hand counts."
                            :error="recountForm.errors.cause"
                            required
                        >
                            <template #control="{ id, invalid, describedBy }">
                                <input
                                    :id="id"
                                    v-model="recountForm.cause"
                                    class="field-input"
                                    type="text"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                />
                            </template>
                        </Field>
                        <div class="cluster">
                            <Btn type="submit" variant="danger" :disabled="recountForm.processing">
                                {{ recountForm.processing ? 'Filing F-ELB-006…' : 'Confirm recount order' }}
                            </Btn>
                            <Btn variant="ghost" @click="recountConfirming = false">Cancel</Btn>
                        </div>
                    </form>
                </div>

                <p v-if="certification" class="citation" style="margin-block-start: var(--space-3)">
                    Certified {{ fmtDate(certification.certified_at) }} by {{ certification.by }} ·
                    F-ELB-004 · winners granted roles
                </p>
            </Card>
        </template>
    </PageScaffold>
</template>

<style scoped>
.boundary-map {
    inline-size: 100%;
    block-size: 16rem;
    border-radius: var(--radius-md, 8px);
    background: var(--gov-surface-2, #eef0f3);
    overflow: hidden;
}

.others-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.others-list li {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    padding-block: var(--space-2);
    border-block-end: 1px solid var(--gov-border, #d6d9de);
}

.others-list li:last-child {
    border-block-end: 0;
}
</style>
