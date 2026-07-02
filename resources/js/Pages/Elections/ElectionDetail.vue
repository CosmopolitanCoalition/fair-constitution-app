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

const blocked = computed(() => props.blockers.length > 0);
const phase = computed(() => props.election?.phase ?? null);
const scheduled = computed(() => props.election?.status === 'scheduled');

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');

/* ─────────────────────────────────────────────── schedule table rows */

const scheduleColumns = [
    { key: 'stage', label: 'Stage' },
    { key: 'when', label: 'When (your timezone)' },
    { key: 'key', label: 'Clock / form', mono: true },
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
    { key: 'finalist_count', label: 'X — pre-published finalists', align: 'right' },
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
let map = null;

async function mountMap() {
    if (!props.election?.jurisdiction?.id || !mapEl.value || map) return;

    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');

    map = L.map(mapEl.value, { zoomControl: true, attributionControl: true, worldCopyJump: true });
    map.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>',
    );
    map.attributionControl.addAttribution(
        'Boundaries &copy; <a href="https://www.geoboundaries.org/" target="_blank" rel="noopener">geoBoundaries</a>',
    );
    map.setView([20, 0], 2);

    try {
        const res = await fetch('/api/maps/latest-pmtiles', { credentials: 'same-origin' });
        const data = res.ok ? await res.json() : null;
        if (data?.url) {
            const protomaps = await import('protomaps-leaflet');
            const basemaps = await import('@protomaps/basemaps');
            protomaps
                .leafletLayer({
                    url: data.url,
                    flavor: basemaps.namedFlavor('light'),
                    attribution: 'Basemap © <a href="https://protomaps.com">Protomaps</a> · © OpenStreetMap',
                })
                .addTo(map);
        }
    } catch {
        /* no basemap — boundary still renders */
    }

    try {
        const res = await fetch(
            `/api/jurisdictions/${props.election.jurisdiction.id}/self.geojson?zoom=8`,
            { credentials: 'same-origin' },
        );
        if (!res.ok) return;
        const geojson = await res.json();
        if (!geojson?.features?.length) return;
        const layer = L.geoJSON(geojson, {
            style: { color: '#56b4e9', weight: 2, fillColor: '#56b4e9', fillOpacity: 0.18 },
        }).addTo(map);
        map.fitBounds(layer.getBounds(), { padding: [16, 16] });
    } catch {
        /* boundary fetch failed — map stays at world view */
    }
}

onMounted(() => nextTick(mountMap));
onBeforeUnmount(() => {
    if (map) map.remove();
    map = null;
});

const hasDistricts = computed(() => props.races.some((race) => !race.at_large));
</script>

<template>
    <PageScaffold
        :surface="surface"
        :title="election ? `Election — ${election.jurisdiction.name}` : 'Elections'"
    >
        <template #intro>
            Elections fire from clocks, never from official discretion. Every cycle runs the
            two-phase open ballot: an approval phase that anyone associated can enter as a
            candidate, a finalist cutoff at the pre-published X, then the ranked (STV) window.
        </template>
        <template #about>
            <p>
                WF-ELE-01 general election cycle. Entity machine ESM-03:
                {{ machine.join(' → ') }}. Phase vocabulary approval | ranked | certifying is
                server-derived — this page never recomputes it.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>

        <!-- ───────────────────────────────────── empty mode (resolver) -->
        <template v-if="!election">
            <Card as="section" title="No election scheduled">
                <p>
                    No election is scheduled for your jurisdiction. Elections fire from clocks
                    (CLK-01 · every {{ empty?.interval ?? 60 }} months), never from official
                    discretion.
                </p>
                <p v-if="empty?.clk01DueAt" style="margin-block-start: var(--space-2)">
                    Next general-election clock fires
                    <strong>{{ fmt(empty.clk01DueAt) }}</strong>
                    <span class="citation"> · CLK-01 armed · stored as UTC</span>
                </p>
                <p style="margin-block-start: var(--space-3)"><HardenedChip /></p>
            </Card>

            <Card v-if="others.length" as="section" title="Elections elsewhere">
                <ul class="others-list">
                    <li v-for="other in others" :key="other.election_id">
                        <Link :href="`/elections/${other.election_id}`">{{ other.jurisdiction_name }}</Link>
                        <span class="citation">
                            {{ other.kind }} · {{ other.seats }} seats · X = {{ other.finalist_count }} ·
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
                title="This chamber is above the 9-seat ceiling — subdivision required."
            >
                A district map (5–9 seats each) must be activated before this election can open
                its approval phase.
                <span v-for="blocker in blockers" :key="blocker.detail" style="display: block">
                    {{ blocker.detail }}
                </span>
                <Link
                    v-if="election.legislature_id"
                    :href="`/legislatures/${election.legislature_id}`"
                >Open the Legislature browser build mode →</Link>
                <span class="citation" style="display: block">Art. II §8 · CLK-07 · F-ELB-003</span>
            </Banner>

            <Card as="section">
                <template #title>
                    <h2>
                        Election lifecycle
                        <StatusBadge :tone="phase === 'approval' ? 'info' : phase === 'ranked' ? 'warning' : 'neutral'">
                            phase: {{ phase }}<template v-if="election.certSubStep"> · {{ election.certSubStep }}</template>
                        </StatusBadge>
                        <StatusBadge tone="neutral">{{ election.kind }}</StatusBadge>
                    </h2>
                </template>
                <StateStrip :states="machine" :current="currentState" />
                <div v-if="stats" class="cluster" style="gap: var(--space-6); margin-block-start: var(--space-3)">
                    <Stat :value="stats.seats" label="seats in this election" />
                    <Stat :value="stats.finalistPlaces" label="finalist places (X) — pre-published" accent />
                    <Stat :value="stats.validatedCandidates" label="validated candidates" />
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
                            X per race is pre-published with this order · CLK-21 · Art. II §2
                        </span>
                    </p>
                </Card>
            </Card>

            <!-- ──────────────────────────────────────────── schedule -->
            <Card as="section" title="Schedule">
                <DataTable
                    :columns="scheduleColumns"
                    :rows="scheduleRows"
                    row-key="key"
                    caption="Election schedule stages with their clocks and status"
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
                        :default-value="60"
                    />
                    {{ ' ' }}
                    <AmendableSetting
                        :value="`${election.finalistMultiplier.value}× seats`"
                        :setting-key="election.finalistMultiplier.settingKey"
                        :citation="`finalists X = multiplier × seats · ${election.finalistMultiplier.clock}`"
                        :default-value="3"
                    />
                </p>
            </Card>

            <!-- ─────────────────────────────────── races + boundary -->
            <Card as="section" title="Races & pre-published X">
                <p class="gloss">
                    X — the number of finalists who advance to the ranked ballot — is published
                    <strong>before</strong> the cutoff, never derived after the fact.
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
                        role="img"
                        :aria-label="`Map of the ${election.jurisdiction.name} election boundary`"
                    ></div>
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        <template v-if="hasDistricts">
                            This chamber is subdivided —
                            <Link v-if="election.legislature_id" :href="`/legislatures/${election.legislature_id}`">
                                view the district map in the Legislature browser →
                            </Link>
                        </template>
                        <template v-else>
                            Single at-large footprint — the constitutional default for chambers of
                            9 seats or fewer (Art. II §8).
                        </template>
                    </p>
                </Card>

                <Card as="section" title="Other elections">
                    <ul v-if="others.length" class="others-list">
                        <li v-for="other in others" :key="other.election_id">
                            <Link :href="`/elections/${other.election_id}`">{{ other.jurisdiction_name }}</Link>
                            <span class="citation">
                                {{ other.kind }} · {{ other.seats }} seats · X = {{ other.finalist_count }} ·
                                {{ other.phase }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="gloss">No other elections on this instance right now.</p>
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
                            Stand for office — F-IND-011
                        </Btn>
                    </template>
                    <template v-else-if="phase === 'ranked'">
                        <Btn :as="Link" :href="`/elections/${election.id}/ranked-ballot`" variant="gold" icon="check">
                            Rank your ballot — F-IND-007
                        </Btn>
                    </template>
                    <template v-else-if="phase === 'certifying'">
                        <Btn :as="Link" :href="`/elections/${election.id}/results`" variant="primary" icon="bar-chart">
                            Watch the count
                        </Btn>
                    </template>
                    <span v-if="scheduled" class="gloss">
                        The approval phase has not opened yet — participation unlocks the moment it
                        does (CLK-18).
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
                            {{ certifyForm.processing ? 'Certifying…' : 'Certify results — F-ELB-004' }}
                        </Btn>
                        <Btn
                            v-if="can.recount"
                            variant="secondary"
                            icon="refresh-cw"
                            :disabled="certification === null"
                            :title="certification === null ? 'Requires certification first' : null"
                            @click="recountConfirming = !recountConfirming"
                        >
                            Order recount — F-ELB-006
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
