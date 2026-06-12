<script setup>
/**
 * Civic/Residency — the Phase A flagship (civic/residency contract,
 * EXPLORE_civic_electoral.md §2; mockups/civic/residency.html).
 *
 * Sections: Residency-Claim StateStrip (machine PHP-owned, prop-fed) ·
 * F-IND-003 declare/redeclare FormCard — POINT-FIRST: "use my current
 * location" or click the picker map → POST /civic/residency/locate
 * resolves the smallest containing jurisdiction + its full ancestor
 * chain (read-only preview), then the normal declare submit files
 * F-IND-003 with the resolved id; name search remains as a tertiary
 * collapse (it searches jurisdiction NAMES — street-address geocoding
 * needs an offline geocoder, out of scope) · F-IND-005 ping card
 * (ThresholdMeter, manual ping via browser geolocation with lat/lng
 * fallback, dev-only simulator + dev-only instant grant) · declared-
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

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/* Point-first declare: browser geolocation or a picker-map click resolves
   the SMALLEST containing jurisdiction + its root-first ancestor chain via
   POST /civic/residency/locate (read-only preview — nothing is filed). */
const located = ref(null); // { jurisdiction, chain } from /locate
const locatingPoint = ref(false);
const locateError = ref(null);
const pickerLatLng = ref(null); // last picked/geolocated point {lat,lng}
let locateSeq = 0;

async function locatePoint(lat, lng) {
    const seq = ++locateSeq;
    locatingPoint.value = true;
    locateError.value = null;
    try {
        const res = await fetch('/civic/residency/locate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ lat, lng }),
        });
        const data = await res.json().catch(() => null);
        if (seq !== locateSeq) return; // a newer click superseded this one
        if (res.ok && data?.found) {
            located.value = data;
            selected.value = null; // the point wins over any earlier search pick
            declareForm.jurisdiction_id = data.jurisdiction.id;
        } else {
            located.value = null;
            declareForm.jurisdiction_id = '';
            locateError.value =
                data?.message ?? `Could not resolve a jurisdiction for this point (${res.status}).`;
        }
    } catch {
        if (seq === locateSeq) locateError.value = 'Locate failed — network error.';
    } finally {
        if (seq === locateSeq) locatingPoint.value = false;
    }
}

const geolocating = ref(false);

function useMyLocation() {
    locateError.value = null;
    if (!('geolocation' in navigator)) {
        locateError.value = 'Browser geolocation unavailable — click your home on the map instead.';
        return;
    }
    geolocating.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            geolocating.value = false;
            const { latitude: lat, longitude: lng } = pos.coords;
            setPickerPin(lat, lng);
            if (pickerMap) pickerMap.setView([lat, lng], 11);
            locatePoint(lat, lng);
        },
        () => {
            geolocating.value = false;
            locateError.value = 'Could not read your location — click your home on the map instead.';
        },
        { enableHighAccuracy: false, timeout: 10000 },
    );
}

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
    located.value = null; // an explicit name pick overrides the point preview
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
        const res = await fetch('/dev/pings/simulate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
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

/* Dev-only instant grant (POST /dev/residency/grant — same WI-4 gate):
   declare → simulated pings → verify, all through the real engine, in one
   request. Targets the located/picked jurisdiction; falls back to the pin
   coordinates, then to the already-declared boundary. */
const granting = ref(false);
const grantResult = ref(null);

const devGrantTarget = computed(() => {
    if (declareForm.jurisdiction_id) {
        return {
            payload: { jurisdiction_id: declareForm.jurisdiction_id },
            name: located.value?.jurisdiction?.name ?? selected.value?.name ?? 'the selected boundary',
        };
    }
    if (pickerLatLng.value) {
        return {
            payload: { lat: pickerLatLng.value.lat, lng: pickerLatLng.value.lng },
            name: 'the picked point',
        };
    }
    if (props.claim?.jurisdiction?.id) {
        return {
            payload: { jurisdiction_id: props.claim.jurisdiction.id },
            name: props.claim.jurisdiction.name,
        };
    }
    return null;
});

async function devGrant() {
    if (!devGrantTarget.value) return;
    granting.value = true;
    grantResult.value = null;
    try {
        const res = await fetch('/dev/residency/grant', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(devGrantTarget.value.payload),
        });
        const data = await res.json().catch(() => null);
        if (res.ok && data?.granted) {
            grantResult.value = data.already
                ? `Already a verified resident of ${data.jurisdiction?.name} — nothing to do.`
                : `Granted — verified resident of ${data.jurisdiction?.name} (${data.chain?.length ?? '?'} associations).`;
            router.reload({ preserveScroll: true });
        } else {
            grantResult.value = data?.message ?? `Grant failed (${res.status}).`;
        }
    } catch {
        grantResult.value = 'Grant failed — network error.';
    } finally {
        granting.value = false;
    }
}

/* ─────────────────────────────────── F-IND-006 — confirm / correct */

const confirmForm = useForm({});
function submitConfirm() {
    confirmForm.post('/civic/residency/confirm', { preserveScroll: true });
}

/* ──────────────────────────── Leaflet maps (lazy) — shared basemap */

let leaflet = null; // cached module after first dynamic import

async function loadLeaflet() {
    if (!leaflet) {
        leaflet = (await import('leaflet')).default;
        await import('leaflet/dist/leaflet.css');
    }
    return leaflet;
}

/* Basemap: same dated-PMTiles lookup the jurisdiction viewer uses; maps
   degrade to polygon-on-blue when no bundle is configured. */
async function addBasemap(target) {
    try {
        const res = await fetch('/api/maps/latest-pmtiles', { credentials: 'same-origin' });
        const data = res.ok ? await res.json() : null;
        if (data?.url) {
            const protomaps = await import('protomaps-leaflet');
            const basemaps = await import('@protomaps/basemaps');
            const flavor = basemaps.namedFlavor('light');
            protomaps
                .leafletLayer({ url: data.url, flavor, attribution: 'Basemap © <a href="https://protomaps.com">Protomaps</a> · © OpenStreetMap' })
                .addTo(target);
        }
    } catch {
        /* no basemap — the map still works */
    }
}

/* ───────────────── Declare-card picker map (point-first declare) */

const pickerEl = ref(null);
let pickerMap = null;
let pickerPin = null;

function setPickerPin(lat, lng) {
    pickerLatLng.value = { lat, lng };
    if (!pickerMap || !leaflet) return;
    if (pickerPin) {
        pickerPin.setLatLng([lat, lng]);
    } else {
        // circleMarker, not the default icon marker — Leaflet's icon PNGs
        // need bundler asset wiring; a vector pin needs none. Wong vermillion.
        pickerPin = leaflet
            .circleMarker([lat, lng], {
                radius: 8,
                color: '#d55e00',
                weight: 2,
                fillColor: '#d55e00',
                fillOpacity: 0.5,
            })
            .addTo(pickerMap);
    }
}

async function mountPickerMap() {
    if (!pickerEl.value || pickerMap) return;

    const L = await loadLeaflet();

    pickerMap = L.map(pickerEl.value, {
        zoomControl: true,
        attributionControl: true,
        worldCopyJump: true,
    });
    pickerMap.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>',
    );
    pickerMap.setView([20, 0], 2);
    addBasemap(pickerMap);

    // Every click (re)drops the pin and re-resolves the chain preview.
    pickerMap.on('click', (ev) => {
        setPickerPin(ev.latlng.lat, ev.latlng.lng);
        locatePoint(ev.latlng.lat, ev.latlng.lng);
    });
}

/* ──────────────────────────── Declared-boundary map (Leaflet, lazy) */

const mapEl = ref(null);
let map = null;
let boundaryLayer = null;

async function mountMap() {
    if (!props.claim?.jurisdiction?.id || !mapEl.value || map) return;

    const L = await loadLeaflet();

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
    addBasemap(map);

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

onMounted(() =>
    nextTick(() => {
        mountMap();
        mountPickerMap();
    }),
);
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
    if (pickerMap) pickerMap.remove();
    pickerMap = null;
    pickerPin = null;
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
            :submit-label="hasClaim ? 'Redeclare — correct the boundary' : located ? 'Declare residency here' : 'Declare residency'"
            processing-label="Filing F-IND-003…"
            @submit="submitDeclare"
        >
            <p v-if="hasClaim" class="cc-small" style="margin-block-end: var(--space-3)">
                Redeclaring supersedes your open claim and restarts ping monitoring inside the new
                boundary — qualifying days do not transfer (containment must be re-proven).
            </p>

            <!-- POINT-FIRST: where you ARE resolves where you live. The point
                 itself never leaves this preview — only the resolved
                 jurisdiction_id is filed with F-IND-003. -->
            <div class="cluster" style="margin-block-end: var(--space-2)">
                <Btn
                    variant="primary"
                    icon="map-pin"
                    :disabled="geolocating || locatingPoint"
                    @click="useMyLocation"
                >
                    {{ geolocating ? 'Locating…' : 'Use my current location' }}
                </Btn>
                <span class="gloss">or click your home on the map below</span>
            </div>

            <div
                ref="pickerEl"
                class="boundary-map"
                aria-label="Map — click to drop a pin on where you live"
                style="margin-block-end: var(--space-3)"
            ></div>

            <p v-if="locatingPoint" class="gloss" role="status">Resolving the smallest containing boundary…</p>
            <p v-if="locateError" class="field-error" role="alert">{{ locateError }}</p>

            <!-- Resolved chain preview: root-first, smallest boundary last — the
                 declared boundary; every enclosing level associates on verify. -->
            <div v-if="located" class="locate-preview" role="status">
                <p class="cc-small" style="margin-block-end: var(--space-1)">
                    You appear to live in
                    <strong>{{ located.jurisdiction.name }}</strong> — the smallest boundary
                    containing your point. Its full chain:
                </p>
                <div class="cluster">
                    <template v-for="(level, i) in located.chain" :key="level.id">
                        <span v-if="i > 0" aria-hidden="true">→</span>
                        <AdmChip :level="level.adm_level" :label="level.name" />
                    </template>
                </div>
            </div>

            <!-- Tertiary: search jurisdiction NAMES (not street addresses). -->
            <details class="search-collapse" style="margin-block-end: var(--space-3)">
                <summary>Or search by place name</summary>
                <Field
                    label="Jurisdiction of residence"
                    hint="Searches jurisdiction names (e.g. Serravalle, New York) — not street addresses; address geocoding needs an offline geocoder and is out of scope for now. Declare the smallest boundary you live inside."
                    :error="declareForm.errors.jurisdiction_id"
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
            </details>

            <p v-if="selected" style="margin-block-end: var(--space-3)">
                Selected:
                <AdmChip :level="selected.adm_level" :label="selected.name" />
                <span v-if="selected.parent_name" class="citation"> in {{ selected.parent_name }}</span>
            </p>
            <p v-if="declareForm.errors.jurisdiction_id && !located && !selected" class="field-error">
                {{ declareForm.errors.jurisdiction_id }}
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

            <!-- Dev-only instant grant: declare → pings → verify in one click,
                 all real engine filings (gated exactly like the simulator). -->
            <div v-if="isDev" style="margin-block-start: var(--space-3)">
                <div class="cluster">
                    <Btn
                        variant="gold"
                        size="sm"
                        icon="map-pin"
                        :disabled="!devGrantTarget || granting"
                        @click="devGrant"
                    >
                        {{ granting ? 'Granting…' : 'Dev: grant residency here instantly' }}
                    </Btn>
                    <span class="citation">local only · real F-IND-003/005/006 filings · relocates if already verified</span>
                </div>
                <p v-if="grantResult" class="gloss" role="status" style="margin-block-start: var(--space-2)">
                    {{ grantResult }}
                </p>
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

                <!-- Dev-only relocation: pick a new point in the declare card
                     (or leave it — re-granting the declared boundary is a
                     no-op) and become a verified resident there in one click. -->
                <div v-if="isDev" style="margin-block-start: var(--space-3)">
                    <div class="cluster">
                        <Btn
                            variant="gold"
                            size="sm"
                            icon="map-pin"
                            :disabled="!devGrantTarget || granting"
                            @click="devGrant"
                        >
                            {{ granting ? 'Granting…' : 'Dev: grant residency here instantly' }}
                        </Btn>
                        <span class="citation">
                            targets {{ devGrantTarget?.name ?? '—' }} · dev-only relocation
                            (deactivates the old associations, then real F-IND filings)
                        </span>
                    </div>
                    <p v-if="grantResult" class="gloss" role="status" style="margin-block-start: var(--space-2)">
                        {{ grantResult }}
                    </p>
                </div>
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

.locate-preview {
    margin-block-end: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--gov-border, #d6d9de);
    border-radius: var(--radius-md, 8px);
    background: var(--gov-surface-2, #eef0f3);
}

.search-collapse > summary {
    cursor: pointer;
    color: var(--gov-link, inherit);
    margin-block-end: var(--space-2);
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
