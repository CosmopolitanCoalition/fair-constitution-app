<script setup>
/**
 * Civic/Residency — the Phase A flagship (civic/residency contract,
 * EXPLORE_civic_electoral.md §2; mockups/civic/residency.html).
 *
 * Sections: Residency-Claim StateStrip (machine PHP-owned, prop-fed) ·
 * F-IND-003 declare/redeclare FormCard with live jurisdiction search ·
 * F-IND-005 ping card (ThresholdMeter, manual ping via browser
 * geolocation with lat/lng fallback, dev-only simulator) · declared-
 * boundary Leaflet map (boundary polygon + ping-day count — raw ping
 * coordinates are PRIVATE: encrypted at rest, purged on verification,
 * never sent to the client) · F-IND-006 confirmation panel in its three
 * contract states (locked / pending confirmation / verified).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import AmendableSetting from '@/Components/Ui/AmendableSetting.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CheckboxField from '@/Components/Ui/CheckboxField.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    claim: { type: Object, default: null },
    machine: { type: Array, default: () => [] },
    threshold: { type: Number, default: null },
    defaultThreshold: { type: Number, default: 30 },
    panel: { type: String, default: 'undeclared' },
    associations: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});

const hasClaim = computed(() => props.claim !== null);
const thresholdDays = computed(() => props.threshold ?? props.defaultThreshold);
const qualifyingDays = computed(() => props.claim?.qualifying_days ?? 0);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

/* ─────────────────────────────────── F-IND-003 — declare / redeclare */

const declareForm = useForm({
    jurisdiction_id: '',
    ping_consent: false,
});

const search = ref('');
const searching = ref(false);
const results = ref([]);
const selected = ref(null);
let searchTimer = null;
let searchSeq = 0;

watch(search, (q) => {
    clearTimeout(searchTimer);
    if (!q || q.trim().length < 2) {
        results.value = [];
        searching.value = false;
        return;
    }
    searching.value = true;
    searchTimer = setTimeout(async () => {
        const seq = ++searchSeq;
        try {
            const res = await fetch(
                `/civic/jurisdictions/search?q=${encodeURIComponent(q.trim())}`,
                { credentials: 'same-origin', headers: { Accept: 'application/json' } },
            );
            if (res.ok && seq === searchSeq) {
                const data = await res.json();
                results.value = data.results ?? [];
            }
        } catch {
            /* network hiccup — leave previous results */
        } finally {
            if (seq === searchSeq) searching.value = false;
        }
    }, 250);
});

function pick(jurisdiction) {
    selected.value = jurisdiction;
    declareForm.jurisdiction_id = jurisdiction.id;
    results.value = [];
    search.value = '';
}

function submitDeclare() {
    const url = hasClaim.value ? '/civic/residency/redeclare' : '/civic/residency/declare';
    declareForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null;
            declareForm.reset();
        },
    });
}

const declareFormEl = ref(null);
function scrollToDeclare() {
    declareFormEl.value?.$el?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
}

/* ───────────────────────────────────────── F-IND-005 — manual ping */

const pingForm = useForm({ latitude: '', longitude: '' });
const showManualCoords = ref(false);
const locating = ref(false);
const geoError = ref(null);

function pingHere() {
    geoError.value = null;
    if (!('geolocation' in navigator)) {
        geoError.value = 'Browser geolocation unavailable — enter coordinates below.';
        showManualCoords.value = true;
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            locating.value = false;
            pingForm.latitude = pos.coords.latitude;
            pingForm.longitude = pos.coords.longitude;
            submitPing();
        },
        () => {
            locating.value = false;
            geoError.value = 'Could not read your location — enter coordinates below.';
            showManualCoords.value = true;
        },
        { enableHighAccuracy: false, timeout: 10000 },
    );
}

function submitPing() {
    pingForm.post('/civic/pings', {
        preserveScroll: true,
        onSuccess: () => pingForm.reset(),
    });
}

/* Dev-only simulator (POST /dev/pings/simulate — WI-4-gated routes). */
const isDev = import.meta.env.DEV;
const simulating = ref(false);
const simulateResult = ref(null);

async function simulate(days = 30) {
    simulating.value = true;
    simulateResult.value = null;
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const res = await fetch('/dev/pings/simulate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({ days }),
        });
        const data = await res.json().catch(() => null);
        simulateResult.value = res.ok
            ? `Simulated ${data?.simulated_days ?? days} day(s) — ${data?.qualifying_days ?? '?'} qualifying.`
            : (data?.message ?? `Simulation failed (${res.status}).`);
        if (res.ok) router.reload({ preserveScroll: true });
    } catch {
        simulateResult.value = 'Simulation failed — network error.';
    } finally {
        simulating.value = false;
    }
}

/* ─────────────────────────────────── F-IND-006 — confirm / correct */

const confirmForm = useForm({});
function submitConfirm() {
    confirmForm.post('/civic/residency/confirm', { preserveScroll: true });
}

/* ──────────────────────────── Declared-boundary map (Leaflet, lazy) */

const mapEl = ref(null);
let map = null;
let boundaryLayer = null;

async function mountMap() {
    if (!props.claim?.jurisdiction?.id || !mapEl.value || map) return;

    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');

    map = L.map(mapEl.value, {
        zoomControl: true,
        attributionControl: true,
        worldCopyJump: true,
    });
    map.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>',
    );
    map.attributionControl.addAttribution(
        'Boundaries &copy; <a href="https://www.geoboundaries.org/" target="_blank" rel="noopener">geoBoundaries</a>',
    );
    map.setView([20, 0], 2);

    // Basemap: same dated-PMTiles lookup the jurisdiction viewer uses; the
    // page degrades to polygon-on-blue when no bundle is configured.
    try {
        const res = await fetch('/api/maps/latest-pmtiles', { credentials: 'same-origin' });
        const data = res.ok ? await res.json() : null;
        if (data?.url) {
            const protomaps = await import('protomaps-leaflet');
            const basemaps = await import('@protomaps/basemaps');
            const flavor = basemaps.namedFlavor('light');
            protomaps
                .leafletLayer({ url: data.url, flavor, attribution: 'Basemap © <a href="https://protomaps.com">Protomaps</a> · © OpenStreetMap' })
                .addTo(map);
        }
    } catch {
        /* no basemap — boundary still renders */
    }

    try {
        const res = await fetch(
            `/api/jurisdictions/${props.claim.jurisdiction.id}/self.geojson?zoom=8`,
            { credentials: 'same-origin' },
        );
        if (!res.ok) return;
        const geojson = await res.json();
        if (!geojson?.features?.length) return;

        // Literal hex (Wong sky-blue): Leaflet writes SVG presentation
        // attributes, where CSS var() does not resolve.
        boundaryLayer = L.geoJSON(geojson, {
            style: {
                color: '#56b4e9',
                weight: 2,
                fillColor: '#56b4e9',
                fillOpacity: 0.18,
            },
        }).addTo(map);
        map.fitBounds(boundaryLayer.getBounds(), { padding: [16, 16] });
    } catch {
        /* boundary fetch failed — map stays at world view */
    }
}

onMounted(() => nextTick(mountMap));
watch(
    () => props.claim?.jurisdiction?.id,
    async () => {
        if (map) {
            map.remove();
            map = null;
            boundaryLayer = null;
        }
        await nextTick();
        mountMap();
    },
);
onBeforeUnmount(() => {
    if (map) map.remove();
    map = null;
});
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Declare where you live, and the system verifies it from your long-term presence
            pattern — nothing else. The moment residency verifies, you are associated with
            <strong>every</strong> enclosing jurisdiction at once, and voting and candidacy unlock
            automatically with no other requirements.
        </template>
        <template #about>
            <p>
                WF-CIV-02 residency establishment — declaration, ping monitoring, threshold,
                verification, and the association sweep all land on this surface. Entity machines:
                Residency Claim ({{ machine.join(' → ') }}) and Individual (R-02 → R-03).
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>
        <Banner v-if="errors.claim" tone="warning">{{ errors.claim }}</Banner>

        <!-- ─────────────────────────────────────── Residency claim state -->
        <Card as="section" title="Your residency claim">
            <template v-if="hasClaim">
                <StateStrip :states="machine" :current="claim.status" />
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    Declared boundary:
                    <AdmChip :level="claim.jurisdiction?.adm_level ?? 0" :label="claim.jurisdiction?.name ?? '—'" />
                    · declared {{ claim.declared_at ? new Date(claim.declared_at).toLocaleDateString() : '—' }}
                </p>
            </template>
            <template v-else>
                <StateStrip :states="machine" :current="null" />
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    No claim yet — declare below to start ping monitoring.
                </p>
            </template>
            <p class="gloss">Threshold days is an amendable setting; pings are encrypted at rest.</p>
        </Card>

        <!-- ───────────────────────────────── F-IND-003 declare / redeclare -->
        <FormCard
            ref="declareFormEl"
            :form="formMeta('F-IND-003')"
            :inertia-form="declareForm"
            :submit-label="hasClaim ? 'Redeclare — correct the boundary' : 'Declare residency'"
            processing-label="Filing F-IND-003…"
            @submit="submitDeclare"
        >
            <p v-if="hasClaim" class="cc-small" style="margin-block-end: var(--space-3)">
                Redeclaring supersedes your open claim and restarts ping monitoring inside the new
                boundary — qualifying days do not transfer (containment must be re-proven).
            </p>

            <Field
                label="Jurisdiction of residence"
                hint="Declare the smallest boundary you live inside; every enclosing level associates automatically."
                :error="declareForm.errors.jurisdiction_id"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="search"
                        class="field-input"
                        type="search"
                        placeholder="Search by name — e.g. New York, Serravalle…"
                        autocomplete="off"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <p v-if="searching" class="gloss" role="status">Searching…</p>
            <ul v-if="results.length" class="search-results" role="listbox" aria-label="Matching jurisdictions">
                <li v-for="result in results" :key="result.id">
                    <button type="button" class="search-result" role="option" aria-selected="false" @click="pick(result)">
                        <AdmChip :level="result.adm_level" :label="result.name" />
                        <span class="citation">
                            {{ result.parent_name ? `in ${result.parent_name} · ` : '' }}{{ result.slug }}
                        </span>
                    </button>
                </li>
            </ul>

            <p v-if="selected" style="margin-block-end: var(--space-3)">
                Selected:
                <AdmChip :level="selected.adm_level" :label="selected.name" />
                <span v-if="selected.parent_name" class="citation"> in {{ selected.parent_name }}</span>
            </p>

            <div class="field" :class="{ 'field--invalid': declareForm.errors.ping_consent }">
                <CheckboxField v-model="declareForm.ping_consent" name="ping_consent">
                    Start collecting periodic location pings to establish my residency pattern.
                    Pings are encrypted at rest and never shown to anyone — only the derived
                    day-count is ever visible, and raw locations purge on verification.
                </CheckboxField>
                <span v-if="declareForm.errors.ping_consent" class="field-error">
                    {{ declareForm.errors.ping_consent }}
                </span>
            </div>
        </FormCard>

        <div class="grid-2">
            <!-- ────────────────────────────────── F-IND-005 ping monitoring -->
            <Card as="section">
                <template #title>
                    <h2>GPS residency ping <FormChip form-id="F-IND-005" /></h2>
                </template>
                <p class="cc-small">
                    Periodic location check-in for residency pattern establishment.
                    <span class="citation" style="display: block">
                        available to R-01 Individual · appends to the residency ping log · Art. I; Art. V §1
                    </span>
                </p>

                <ThresholdMeter
                    :value="qualifyingDays"
                    :max="thresholdDays"
                    :threshold="thresholdDays"
                    label="Qualifying days toward the residency threshold"
                >
                    {{ qualifyingDays }} of {{ thresholdDays }} qualifying days
                    <template #note>distinct days pinged inside the declared boundary</template>
                </ThresholdMeter>

                <p style="margin-block-start: var(--space-3)">
                    <AmendableSetting
                        :value="`${thresholdDays} days`"
                        setting-key="residency_confirmation_days"
                        citation="CLK-05 · Art. I; Art. V §1"
                    />
                </p>

                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn
                        variant="primary"
                        icon="map-pin"
                        :disabled="!hasClaim || locating || pingForm.processing"
                        @click="pingHere"
                    >
                        {{ locating ? 'Locating…' : pingForm.processing ? 'Recording…' : 'Ping my location now' }}
                    </Btn>
                    <Btn variant="ghost" size="sm" :pressed="showManualCoords" @click="showManualCoords = !showManualCoords">
                        Enter coordinates manually
                    </Btn>
                </div>
                <p v-if="!hasClaim" class="gloss" style="margin-block-start: var(--space-2)">
                    Declare residency first — pings need a declared boundary to count toward.
                </p>
                <p v-if="geoError" class="field-error" role="alert" style="margin-block-start: var(--space-2)">{{ geoError }}</p>

                <form v-if="showManualCoords" novalidate style="margin-block-start: var(--space-3)" @submit.prevent="submitPing">
                    <div class="cluster" style="align-items: flex-end">
                        <Field label="Latitude" :error="pingForm.errors.latitude">
                            <template #control="{ id }">
                                <input :id="id" v-model="pingForm.latitude" class="field-input" type="text" inputmode="decimal" style="inline-size: 9rem" />
                            </template>
                        </Field>
                        <Field label="Longitude" :error="pingForm.errors.longitude">
                            <template #control="{ id }">
                                <input :id="id" v-model="pingForm.longitude" class="field-input" type="text" inputmode="decimal" style="inline-size: 9rem" />
                            </template>
                        </Field>
                        <Btn type="submit" variant="secondary" :disabled="pingForm.processing">Record ping</Btn>
                    </div>
                </form>

                <div v-if="isDev" style="margin-block-start: var(--space-3)">
                    <div class="cluster">
                        <Btn variant="gold" size="sm" icon="clock" :disabled="!hasClaim || simulating" @click="simulate(30)">
                            {{ simulating ? 'Simulating…' : 'Dev: simulate 30 days of pings' }}
                        </Btn>
                        <span class="citation">local only · files 30 real F-IND-005 entries</span>
                    </div>
                    <p v-if="simulateResult" class="gloss" role="status" style="margin-block-start: var(--space-2)">
                        {{ simulateResult }}
                    </p>
                </div>

                <Banner tone="info" title="Pings are encrypted at rest." style="margin-block-start: var(--space-3)">
                    Only the derived day-count is ever visible — to you or to anyone. Raw locations
                    are never published, never shared, and purge on verification.
                    <span class="citation">location_pings · private · Art. I</span>
                </Banner>
            </Card>

            <!-- ─────────────────────────────────────── Declared boundary map -->
            <Card as="section" title="Declared boundary">
                <div
                    v-if="hasClaim"
                    ref="mapEl"
                    class="boundary-map"
                    role="img"
                    :aria-label="`Map of the declared ${claim.jurisdiction?.name ?? ''} boundary`"
                ></div>
                <p v-else class="gloss">The declared boundary renders here once you declare residency.</p>
                <p v-if="hasClaim" class="gloss" style="margin-block-start: var(--space-2)">
                    {{ qualifyingDays }} qualifying ping day{{ qualifyingDays === 1 ? '' : 's' }} recorded inside this
                    boundary. Individual ping locations are private and are never drawn.
                </p>
            </Card>
        </div>

        <!-- ─────────────────────────── F-IND-006 verification confirmation -->
        <Card as="section">
            <template #title>
                <h2>Residency verification confirmation <FormChip form-id="F-IND-006" /></h2>
            </template>
            <p class="cc-small">
                System auto-generates when the ping threshold is met; you confirm or correct.
                <span class="citation" style="display: block">
                    available to R-02 Resident (pending) · creates verified residency + all jurisdictional associations (R-03) · Art. I; Art. V §1
                </span>
            </p>

            <!-- State 1 — locked below threshold -->
            <template v-if="panel === 'undeclared' || panel === 'locked'">
                <ThresholdMeter
                    :value="qualifyingDays"
                    :max="thresholdDays"
                    :threshold="thresholdDays"
                    label="Days toward unlocking the confirmation"
                >
                    Locked — {{ qualifyingDays }} of {{ thresholdDays }} qualifying days
                    <template #note>unlocks automatically at the threshold · CLK-05</template>
                </ThresholdMeter>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    <template v-if="panel === 'undeclared'">Declare residency to begin.</template>
                    <template v-else>
                        Keep pinging inside your declared boundary — the confirmation unlocks the
                        moment the threshold is met, and the clock job can also verify it for you.
                    </template>
                </p>
            </template>

            <!-- State 2 — threshold met, awaiting the resident's word -->
            <template v-else-if="panel === 'pending_confirmation'">
                <StatusBadge tone="warning" icon="clock">Threshold met — awaiting your confirmation</StatusBadge>
                <p style="margin-block: var(--space-3)">
                    Your presence pattern reached {{ qualifyingDays }} qualifying days inside
                    <strong>{{ claim?.jurisdiction?.name }}</strong>. Confirm this is your residence, or
                    correct the boundary if you declared the wrong one.
                </p>
                <div class="cluster">
                    <Btn variant="primary" icon="check" :disabled="confirmForm.processing" @click="submitConfirm">
                        {{ confirmForm.processing ? 'Verifying…' : 'Confirm — this is my residence' }}
                    </Btn>
                    <Btn variant="secondary" @click="scrollToDeclare">Correct the boundary</Btn>
                </div>
            </template>

            <!-- State 3 — verified: the rights-unlock moment -->
            <template v-else-if="panel === 'verified'">
                <div class="cluster" style="gap: var(--space-4)">
                    <StatusBadge tone="success" icon="check">Voting unlocked</StatusBadge>
                    <StatusBadge tone="success" icon="check">Candidacy unlocked</StatusBadge>
                    <HardenedChip />
                </div>
                <p style="margin-block: var(--space-3)">
                    Residency verified. You are associated with every enclosing jurisdiction
                    simultaneously — {{ associations.length }} level{{ associations.length === 1 ? '' : 's' }}:
                </p>
                <div class="cluster">
                    <AdmChip
                        v-for="assoc in associations"
                        :key="assoc.id"
                        :level="assoc.adm_level"
                        :label="assoc.name"
                    />
                </div>
                <p class="citation" style="margin-block-start: var(--space-3)">
                    Residency verified → all associations → rights unlocked · Art. I; Art. V §1
                </p>
            </template>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.boundary-map {
    inline-size: 100%;
    block-size: 18rem;
    border-radius: var(--radius-md, 8px);
    background: var(--gov-surface-2, #eef0f3);
    overflow: hidden;
}

.search-results {
    list-style: none;
    margin: 0 0 var(--space-3);
    padding: 0;
    border: 1px solid var(--gov-border, #d6d9de);
    border-radius: var(--radius-md, 8px);
    max-block-size: 16rem;
    overflow-y: auto;
}

.search-result {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1);
    inline-size: 100%;
    padding: var(--space-2) var(--space-3);
    background: transparent;
    border: 0;
    border-block-end: 1px solid var(--gov-border, #d6d9de);
    cursor: pointer;
    text-align: start;
    font: inherit;
    color: inherit;
}

.search-results li:last-child .search-result {
    border-block-end: 0;
}

.search-result:hover,
.search-result:focus-visible {
    background: var(--gov-surface-2, #eef0f3);
}
</style>
