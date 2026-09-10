<script setup>
/**
 * Civic/Residency — the resident-facing claim lifecycle, written for the
 * common user (operator order 2026-09-10: "Redesign this page so that it
 * makes sense for the common user").
 *
 * ONE QUESTION AT A TIME, driven by the PHP-owned `panel`:
 *   undeclared            → "Where do you live?"            (declare, F-IND-003)
 *   locked (monitoring)   → "Now show you live there."      (check in, F-IND-005)
 *   pending_confirmation  → "Is this your home?"            (confirm, F-IND-006)
 *   verified              → "You live in X."                (the map + every place you belong to)
 * On an INSTANT posture (threshold 0, CGA_RESIDENCY_INSTANT) the check-in
 * step does not exist: declaring confirms at once.
 *
 * Everything technical — the claim state machine, form ids, roles,
 * citations, the amendable threshold, the privacy fine print — lives in one
 * collapsed "How this works" block. Every dev-only control lives in one
 * collapsed "Developer tools" block (local builds only).
 *
 * Endpoints and filings are unchanged: POST /civic/residency/locate (point
 * preview), GET /civic/jurisdictions/search, POST /civic/residency/declare
 * and /redeclare (F-IND-003), POST /civic/pings (F-IND-005),
 * POST /civic/residency/confirm (F-IND-006), the dev simulator + grant.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
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
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

defineOptions({ layout: AppShellV2 });

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
const isInstant = computed(() => thresholdDays.value === 0);
const homeName = computed(() => props.claim?.jurisdiction?.name ?? null);
const isUndeclared = computed(() => props.panel === 'undeclared');

/* The declare form is the page itself before any claim; afterwards it hides
   behind "Change my home". */
const showDeclare = ref(false);
const declareVisible = computed(() => isUndeclared.value || showDeclare.value);
const declareCardEl = ref(null);
function openDeclare() {
    showDeclare.value = true;
    nextTick(() => declareCardEl.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }));
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/* ─────────────────────────────────── F-IND-003 — declare / redeclare */

const declareForm = useForm({
    jurisdiction_id: '',
    ping_consent: false,
});

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
                data?.message ?? `Could not find a place for this point (${res.status}).`;
        }
    } catch {
        if (seq === locateSeq) locateError.value = 'Could not reach the server. Check your connection and try again.';
    } finally {
        if (seq === locateSeq) locatingPoint.value = false;
    }
}

const geolocating = ref(false);

function useMyLocation() {
    locateError.value = null;
    if (!('geolocation' in navigator)) {
        locateError.value = 'Your browser cannot share your location. Click your home on the map instead.';
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
            locateError.value = 'Could not read your location. Click your home on the map instead.';
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

/* The place the form will file, whichever way it was chosen. */
const chosenName = computed(() => located.value?.jurisdiction?.name ?? selected.value?.name ?? null);

function submitDeclare() {
    const url = hasClaim.value ? '/civic/residency/redeclare' : '/civic/residency/declare';
    declareForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null;
            located.value = null;
            showDeclare.value = false;
            declareForm.reset();
        },
    });
}

/* ───────────────────────────────────────── F-IND-005 — check in */

const pingForm = useForm({ latitude: '', longitude: '' });
const showManualCoords = ref(false);
const locating = ref(false);
const geoError = ref(null);

function pingHere() {
    geoError.value = null;
    if (!('geolocation' in navigator)) {
        geoError.value = 'Your browser cannot share your location. Enter your coordinates below.';
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
            geoError.value = 'Could not read your location. Enter your coordinates below.';
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

/* ─────────────────────────────── F-IND-006 — "yes, this is my home" */

const confirmForm = useForm({});
function submitConfirm() {
    confirmForm.post('/civic/residency/confirm', { preserveScroll: true });
}

/* ──────────────────────────────── Developer tools (local builds only) */

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

const granting = ref(false);
const grantResult = ref(null);

const devGrantTarget = computed(() => {
    if (declareForm.jurisdiction_id) {
        return {
            payload: { jurisdiction_id: declareForm.jurisdiction_id },
            name: chosenName.value ?? 'the selected place',
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

/* ──────────────────────────── Leaflet maps (lazy) — shared basemap */

let leaflet = null; // cached module after first dynamic import
const basemapMissing = ref(false);

async function loadLeaflet() {
    if (!leaflet) {
        leaflet = (await import('leaflet')).default;
        await import('leaflet/dist/leaflet.css');
    }
    return leaflet;
}

/* Basemap: same dated-PMTiles lookup the jurisdiction viewer uses; maps
   degrade to polygon-on-blue when no bundle is configured, and the picker
   map says so instead of showing an empty box. */
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
        } else {
            basemapMissing.value = true;
        }
    } catch {
        basemapMissing.value = true;
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
    if (!pickerEl.value || pickerMap) return; // the form closed while Leaflet loaded

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

function unmountPickerMap() {
    if (pickerMap) pickerMap.remove();
    pickerMap = null;
    pickerPin = null;
}

/* ──────────────────────────── Declared-boundary map (Leaflet, lazy) */

const mapEl = ref(null);
let map = null;
let boundaryLayer = null;

async function mountMap() {
    if (!props.claim?.jurisdiction?.id || !mapEl.value || map) return;

    const L = await loadLeaflet();
    if (!mapEl.value || map) return;

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

function unmountMap() {
    if (map) map.remove();
    map = null;
    boundaryLayer = null;
}

onMounted(() =>
    nextTick(() => {
        mountMap();
        mountPickerMap();
    }),
);
/* The picker map lives only while the declare form is on screen. */
watch(declareVisible, async () => {
    await nextTick();
    if (pickerEl.value) mountPickerMap();
    else unmountPickerMap();
});
/* The boundary map re-mounts when the declared place changes or the
   verified card appears. */
watch(
    () => [props.claim?.jurisdiction?.id, props.panel],
    async () => {
        unmountMap();
        await nextTick();
        mountMap();
    },
);
onBeforeUnmount(() => {
    unmountMap();
    unmountPickerMap();
});
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Tell us where you live. That is the only requirement. Once your home is confirmed you
            belong to every place that contains it, and you can vote and stand for office in each
            of them.
        </template>
        <template #about>
            <p>
                WF-CIV-02 residency establishment — declaration, check-in monitoring, threshold,
                verification, and the association sweep all land on this surface. Entity machines:
                Residency Claim ({{ machine.join(' → ') }}) and Individual (R-02 → R-03).
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="This filing was refused">
            {{ errors.constitution }}
        </Banner>
        <Banner v-if="errors.claim" tone="warning">{{ errors.claim }}</Banner>

        <!-- ═══════════════════ The one question for the current state ═══════════════════ -->

        <!-- Monitoring: show you live there -->
        <Card v-if="panel === 'locked'" as="section" class="hero" eyebrow="Step 2 of 2">
            <template #title>
                <h2>You declared {{ homeName }}. Now show you live there.</h2>
            </template>
            <ThresholdMeter
                :value="qualifyingDays"
                :max="thresholdDays"
                :threshold="thresholdDays"
                label="Days checked in from home"
            >
                {{ qualifyingDays }} of {{ thresholdDays }} days checked in
                <template #note>one check-in per day, from inside {{ homeName }}</template>
            </ThresholdMeter>
            <p style="margin-block-start: var(--space-3)">
                Open this page while you are at home and check in. Each day counts once. Your
                location stays private; only the number of days is kept. When you reach
                {{ thresholdDays }} days you will be asked to confirm.
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn variant="primary" icon="map-pin" :disabled="locating || pingForm.processing" @click="pingHere">
                    {{ locating ? 'Finding you…' : pingForm.processing ? 'Recording…' : 'Check in from here' }}
                </Btn>
                <Btn variant="ghost" size="sm" :pressed="showManualCoords" @click="showManualCoords = !showManualCoords">
                    Enter coordinates instead
                </Btn>
            </div>
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
                    <Btn type="submit" variant="secondary" :disabled="pingForm.processing">Record check-in</Btn>
                </div>
            </form>
            <p class="gloss" style="margin-block-start: var(--space-3)">
                Wrong place?
                <button type="button" class="link-btn" @click="openDeclare">Change my home</button>
            </p>
        </Card>

        <!-- Threshold met: is this your home? -->
        <Card v-else-if="panel === 'pending_confirmation'" as="section" class="hero" eyebrow="Last step">
            <template #title>
                <h2>Is {{ homeName }} your home?</h2>
            </template>
            <p>
                <template v-if="isInstant">You declared {{ homeName }}.</template>
                <template v-else>You checked in from {{ homeName }} on {{ qualifyingDays }} days.</template>
                Confirm and you belong to every place that contains it.
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn variant="primary" icon="check" :disabled="confirmForm.processing" @click="submitConfirm">
                    {{ confirmForm.processing ? 'Confirming…' : 'Yes, this is my home' }}
                </Btn>
                <Btn variant="secondary" @click="openDeclare">No, change my home</Btn>
            </div>
        </Card>

        <!-- Verified: you live in X -->
        <Card v-else-if="panel === 'verified'" as="section" class="hero" eyebrow="Confirmed">
            <template #title>
                <h2>You live in {{ homeName }}.</h2>
            </template>
            <div
                ref="mapEl"
                class="boundary-map"
                role="img"
                :aria-label="`Map of ${homeName}`"
            ></div>
            <p style="margin-block-start: var(--space-3)">
                You belong to {{ associations.length }} place{{ associations.length === 1 ? '' : 's' }} at once,
                and you can vote and stand for office in every one of them:
            </p>
            <div class="cluster" style="margin-block-start: var(--space-2)">
                <AdmChip
                    v-for="assoc in associations"
                    :key="assoc.id"
                    :level="assoc.adm_level"
                    :label="assoc.name"
                />
            </div>
            <p class="gloss" style="margin-block-start: var(--space-3)">
                Moved?
                <button type="button" class="link-btn" @click="openDeclare">Change my home</button>
            </p>
        </Card>

        <!-- ═══════════════════ Declare / change my home (F-IND-003) ═══════════════════ -->
        <div v-if="declareVisible" ref="declareCardEl">
            <Card as="section" class="hero" :eyebrow="isUndeclared ? (isInstant ? 'One step' : 'Step 1 of 2') : 'Change my home'">
                <template #title>
                    <h2>{{ isUndeclared ? 'Where do you live?' : 'Where do you live now?' }}</h2>
                </template>
                <form novalidate @submit.prevent="submitDeclare">
                    <p>
                        Use your current location, or click your home on the map. Only the place you
                        live in is recorded, never the exact point.
                        <template v-if="!isUndeclared && !isInstant">
                            Changing your home starts the check-in days again inside the new place.
                        </template>
                    </p>

                    <div class="cluster" style="margin-block: var(--space-3) var(--space-2)">
                        <Btn
                            type="button"
                            variant="primary"
                            icon="map-pin"
                            :disabled="geolocating || locatingPoint"
                            @click="useMyLocation"
                        >
                            {{ geolocating ? 'Finding you…' : 'Use my current location' }}
                        </Btn>
                        <span class="gloss">or click your home on the map</span>
                    </div>

                    <div class="map-wrap" style="margin-block-end: var(--space-3)">
                        <div ref="pickerEl" class="boundary-map" aria-label="Map — click where you live"></div>
                        <p v-if="basemapMissing" class="map-note">
                            No map tiles are loaded on this box. Use your current location or search by name below.
                        </p>
                    </div>

                    <p v-if="locatingPoint" class="gloss" role="status">Finding the place at that point…</p>
                    <p v-if="locateError" class="field-error" role="alert">{{ locateError }}</p>

                    <div v-if="located" class="locate-preview" role="status">
                        <p class="cc-small" style="margin-block-end: var(--space-1)">
                            Your home is in <strong>{{ located.jurisdiction.name }}</strong>. It sits inside:
                        </p>
                        <div class="cluster">
                            <template v-for="(level, i) in located.chain" :key="level.id">
                                <span v-if="i > 0" aria-hidden="true">→</span>
                                <AdmChip :level="level.adm_level" :label="level.name" />
                            </template>
                        </div>
                    </div>
                    <p v-else-if="selected" class="locate-preview" role="status">
                        Your home:
                        <AdmChip :level="selected.adm_level" :label="selected.name" />
                        <span v-if="selected.parent_name" class="citation"> in {{ selected.parent_name }}</span>
                    </p>

                    <details class="search-collapse" style="margin-block-end: var(--space-3)">
                        <summary>Search by place name instead</summary>
                        <Field
                            label="Place name"
                            hint="Type the name of the town, county, region or country you live in. Street addresses are not searched."
                            :error="declareForm.errors.jurisdiction_id"
                        >
                            <template #control="{ id, invalid, describedBy }">
                                <input
                                    :id="id"
                                    v-model="search"
                                    class="field-input"
                                    type="search"
                                    placeholder="e.g. Anne Arundel, New York, Serravalle"
                                    autocomplete="off"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                />
                            </template>
                        </Field>
                        <p v-if="searching" class="gloss" role="status">Searching…</p>
                        <ul v-if="results.length" class="search-results" role="listbox" aria-label="Matching places">
                            <li v-for="result in results" :key="result.id">
                                <button type="button" class="search-result" role="option" aria-selected="false" @click="pick(result)">
                                    <AdmChip :level="result.adm_level" :label="result.name" />
                                    <span class="citation">
                                        {{ result.parent_name ? `in ${result.parent_name}` : '' }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </details>

                    <p v-if="declareForm.errors.jurisdiction_id && !located && !selected" class="field-error">
                        Choose the place you live in first.
                    </p>

                    <div class="field" :class="{ 'field--invalid': declareForm.errors.ping_consent }">
                        <CheckboxField v-model="declareForm.ping_consent" name="ping_consent">
                            <template v-if="isInstant">
                                I confirm this is where I live. My location is private and is used only to
                                place me here.
                            </template>
                            <template v-else>
                                I agree to check in from home over the next {{ thresholdDays }} days so my
                                residency can be confirmed. Check-ins are private; only the number of days
                                is kept, and the locations are deleted once I am confirmed.
                            </template>
                        </CheckboxField>
                        <span v-if="declareForm.errors.ping_consent" class="field-error">
                            Please tick the box to continue.
                        </span>
                    </div>

                    <div class="cluster" style="margin-block-start: var(--space-3)">
                        <Btn type="submit" variant="primary" icon="check" :disabled="declareForm.processing || !declareForm.jurisdiction_id">
                            {{ declareForm.processing ? 'Saving…' : isInstant ? 'Confirm my home' : (isUndeclared ? 'Declare my home' : 'Change my home') }}
                        </Btn>
                        <Btn v-if="!isUndeclared" type="button" variant="ghost" @click="showDeclare = false">Keep my current home</Btn>
                    </div>
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        <template v-if="isInstant">You are confirmed the moment you declare.</template>
                        <template v-else>Then check in from home on {{ thresholdDays }} days and you are confirmed.</template>
                    </p>
                </form>
            </Card>
        </div>

        <!-- ═══════════════════ How this works (the fine print) ═══════════════════ -->
        <details class="more">
            <summary>How this works, and the fine print</summary>
            <Card as="section" title="Your residency claim">
                <StateStrip :states="machine" :current="claim?.status ?? null" />
                <p v-if="hasClaim" class="gloss" style="margin-block-start: var(--space-2)">
                    Declared:
                    <AdmChip :level="claim.jurisdiction?.adm_level ?? 0" :label="claim.jurisdiction?.name ?? '—'" />
                    · {{ claim.declared_at ? new Date(claim.declared_at).toLocaleDateString() : '—' }}
                </p>
                <p style="margin-block-start: var(--space-3)">
                    <AmendableSetting
                        :value="isInstant ? 'instant' : `${thresholdDays} days`"
                        setting-key="residency_confirmation_days"
                        citation="CLK-05 · Art. I; Art. V §1"
                    />
                </p>
                <p class="cc-small" style="margin-block-start: var(--space-3)">
                    Declaration <FormChip form-id="F-IND-003" /> · Check-in <FormChip form-id="F-IND-005" /> ·
                    Confirmation <FormChip form-id="F-IND-006" /> <HardenedChip />
                    <span class="citation" style="display: block">
                        available to R-01 Individual · the confirmation creates verified residency and every
                        jurisdictional association (R-03) · Art. I; Art. V §1
                    </span>
                </p>
                <Banner tone="info" title="Check-ins are private." style="margin-block-start: var(--space-3)">
                    Locations are encrypted at rest, never shown to anyone, and deleted once you are
                    confirmed. Only the number of days is ever visible.
                    <span class="citation">location_pings · private · Art. I</span>
                </Banner>
            </Card>
        </details>

        <!-- ═══════════════════ Developer tools (local builds only) ═══════════════════ -->
        <details v-if="isDev" class="more">
            <summary>Developer tools (local only)</summary>
            <Card as="section" title="Shortcuts through the real engine">
                <div class="cluster">
                    <Btn variant="gold" size="sm" icon="map-pin" :disabled="!devGrantTarget || granting" @click="devGrant">
                        {{ granting ? 'Granting…' : 'Grant residency instantly' }}
                    </Btn>
                    <span class="citation">
                        targets {{ devGrantTarget?.name ?? '—' }} · real F-IND-003/005/006 filings · relocates if already verified
                    </span>
                </div>
                <p v-if="grantResult" class="gloss" role="status" style="margin-block-start: var(--space-2)">{{ grantResult }}</p>
                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn variant="gold" size="sm" icon="clock" :disabled="!hasClaim || isInstant || simulating" @click="simulate(30)">
                        {{ simulating ? 'Simulating…' : 'Simulate 30 days of check-ins' }}
                    </Btn>
                    <span class="citation">files 30 real F-IND-005 entries on the open claim</span>
                </div>
                <p v-if="simulateResult" class="gloss" role="status" style="margin-block-start: var(--space-2)">{{ simulateResult }}</p>
            </Card>
        </details>
    </PageScaffold>
</template>

<style scoped>
.hero :deep(h2) {
    font-size: 1.5rem;
    line-height: 1.25;
    text-wrap: balance;
}

.boundary-map {
    inline-size: 100%;
    block-size: 18rem;
    border-radius: var(--radius-md, 8px);
    background: var(--gov-surface-2, #eef0f3);
    overflow: hidden;
}

.map-wrap {
    position: relative;
}

.map-note {
    position: absolute;
    inset-block-end: var(--space-2);
    inset-inline: var(--space-2);
    margin: 0;
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-md, 8px);
    background: var(--gov-surface, #fff);
    border: 1px solid var(--gov-border, #d6d9de);
    font-size: 0.875rem;
    pointer-events: none;
    z-index: 500;
}

.locate-preview {
    margin-block-end: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--gov-border, #d6d9de);
    border-radius: var(--radius-md, 8px);
    background: var(--gov-surface-2, #eef0f3);
}

.link-btn {
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    color: var(--gov-link, inherit);
    text-decoration: underline;
    cursor: pointer;
}

.more {
    margin-block-start: var(--space-4);
}

.more > summary {
    cursor: pointer;
    color: var(--gov-link, inherit);
    margin-block-end: var(--space-2);
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
