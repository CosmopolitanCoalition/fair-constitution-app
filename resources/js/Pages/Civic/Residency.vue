<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
import { useI18n } from 'vue-i18n';
import { csrfFetch } from '@/lib/csrf.js';
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
import { addProtomapsBasemap } from '@/lib/protomapsBasemap.js';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

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
        const res = await csrfFetch('/civic/residency/locate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ lat, lng }),
        }, t);
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
                data?.message ?? t('c_civic.residency.locate_not_found', { status: res.status });
        }
    } catch (error) {
        if (seq === locateSeq) locateError.value = error.message || t('c_civic.residency.server_unreachable', 'Could not reach the server. Check your connection and try again.');
    } finally {
        if (seq === locateSeq) locatingPoint.value = false;
    }
}

const geolocating = ref(false);

function useMyLocation() {
    locateError.value = null;
    if (!('geolocation' in navigator)) {
        locateError.value = t('c_civic.residency.no_geo_map', 'Your browser cannot share your location. Click your home on the map instead.');
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
            locateError.value = t('c_civic.residency.geo_failed_map', 'Could not read your location. Click your home on the map instead.');
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
        geoError.value = t('c_civic.residency.no_geo_coords', 'Your browser cannot share your location. Enter your coordinates below.');
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
            geoError.value = t('c_civic.residency.geo_failed_coords', 'Could not read your location. Enter your coordinates below.');
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
        const res = await csrfFetch('/dev/pings/simulate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ days }),
        }, t);
        const data = await res.json().catch(() => null);
        simulateResult.value = res.ok
            ? t('c_civic.residency.sim_ok', { days: data?.simulated_days ?? days, qualifying: data?.qualifying_days ?? '?' })
            : (data?.message ?? t('c_civic.residency.sim_failed', { status: res.status }));
        if (res.ok) router.reload({ preserveScroll: true });
    } catch {
        simulateResult.value = t('c_civic.residency.sim_network', 'Simulation failed — network error.');
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
            name: chosenName.value ?? t('c_civic.residency.selected_place', 'the selected place'),
        };
    }
    if (pickerLatLng.value) {
        return {
            payload: { lat: pickerLatLng.value.lat, lng: pickerLatLng.value.lng },
            name: t('c_civic.residency.picked_point', 'the picked point'),
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
        const res = await csrfFetch('/dev/residency/grant', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(devGrantTarget.value.payload),
        }, t);
        const data = await res.json().catch(() => null);
        if (res.ok && data?.granted) {
            grantResult.value = data.already
                ? t('c_civic.residency.grant_already', { name: data.jurisdiction?.name })
                : t('c_civic.residency.grant_ok', { name: data.jurisdiction?.name, count: data.chain?.length ?? '?' });
            router.reload({ preserveScroll: true });
        } else {
            grantResult.value = data?.message ?? t('c_civic.residency.grant_failed', { status: res.status });
        }
    } catch {
        grantResult.value = t('c_civic.residency.grant_network', 'Grant failed — network error.');
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

/* Basemap: the shared Protomaps helper (the same cartography as the
   jurisdiction viewer). No bundle configured -> the picker map says so. */
async function addBasemap(target) {
    const ok = await addProtomapsBasemap(target);
    if (!ok) basemapMissing.value = true;
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
        // keyboard: false — Leaflet's keyboard handler sets tabIndex=0 on the
        // container (Map.Keyboard.addHooks), making the map itself focusable
        // while it also holds focusable descendants (zoom links, attribution).
        // axe flags that as a focusable element with focusable descendants.
        // Arrow-key panning is not a needed path here: the picker is reached by
        // "Use my current location" and search-by-name, and clicking drops the
        // pin. Disabling the handler leaves the container non-focusable.
        keyboard: false,
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
        // keyboard: false — see the picker map. This is a display-only boundary
        // view; arrow-key panning is not needed, so the container stays a
        // non-focusable region and does not hold focus with focusable children.
        keyboard: false,
        zoomControl: true,
        attributionControl: true,
        worldCopyJump: true,
    });
    map.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>',
    );
    map.attributionControl.addAttribution(
        t('c_civic.residency.attr_boundaries', 'Boundaries') + ' &copy; <a href="https://www.geoboundaries.org/" target="_blank" rel="noopener">geoBoundaries</a>',
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
            {{ t('c_civic.residency.intro', 'Tell us where you live. That is the only requirement. Once your home is confirmed you belong to every place that contains it, and you can vote and stand for office in each of them.') }}
        </template>
        <template #about>
            <p>
                {{ t('c_civic.residency.about', { machine: machine.join(' → ') }) }}
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" :title="t('c_civic.residency.filing_refused', 'This filing was refused')">
            {{ errors.constitution }}
        </Banner>
        <Banner v-if="errors.claim" tone="warning">{{ errors.claim }}</Banner>

        <!-- ═══════════════════ The one question for the current state ═══════════════════ -->

        <!-- Monitoring: show you live there -->
        <Card v-if="panel === 'locked'" as="section" class="hero" :eyebrow="t('c_civic.residency.eyebrow_step2', 'Step 2 of 2')">
            <template #title>
                <h2>{{ t('c_civic.residency.locked_h2', { home: homeName }) }}</h2>
            </template>
            <ThresholdMeter
                :value="qualifyingDays"
                :max="thresholdDays"
                :threshold="thresholdDays"
                :label="t('c_civic.residency.days_checked_label', 'Days checked in from home')"
            >
                {{ t('c_civic.residency.days_checked_meter', { done: qualifyingDays, threshold: thresholdDays }) }}
                <template #note>{{ t('c_civic.residency.checkin_note', { home: homeName }) }}</template>
            </ThresholdMeter>
            <p style="margin-block-start: var(--space-3)">
                {{ t('c_civic.residency.checkin_body', { days: thresholdDays }) }}
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn variant="primary" icon="map-pin" :disabled="locating || pingForm.processing" @click="pingHere">
                    {{ locating ? t('c_civic.residency.finding_you', 'Finding you…') : pingForm.processing ? t('c_civic.residency.recording', 'Recording…') : t('c_civic.residency.check_in_here', 'Check in from here') }}
                </Btn>
                <Btn variant="ghost" size="sm" :pressed="showManualCoords" @click="showManualCoords = !showManualCoords">
                    {{ t('c_civic.residency.enter_coords', 'Enter coordinates instead') }}
                </Btn>
            </div>
            <p v-if="geoError" class="field-error" role="alert" style="margin-block-start: var(--space-2)">{{ geoError }}</p>
            <form v-if="showManualCoords" novalidate style="margin-block-start: var(--space-3)" @submit.prevent="submitPing">
                <div class="cluster" style="align-items: flex-end">
                    <Field :label="t('c_civic.residency.latitude', 'Latitude')" :error="pingForm.errors.latitude">
                        <template #control="{ id }">
                            <input :id="id" v-model="pingForm.latitude" class="field-input" type="text" inputmode="decimal" style="inline-size: 9rem" />
                        </template>
                    </Field>
                    <Field :label="t('c_civic.residency.longitude', 'Longitude')" :error="pingForm.errors.longitude">
                        <template #control="{ id }">
                            <input :id="id" v-model="pingForm.longitude" class="field-input" type="text" inputmode="decimal" style="inline-size: 9rem" />
                        </template>
                    </Field>
                    <Btn type="submit" variant="secondary" :disabled="pingForm.processing">{{ t('c_civic.residency.record_checkin', 'Record check-in') }}</Btn>
                </div>
            </form>
            <p class="gloss" style="margin-block-start: var(--space-3)">
                {{ t('c_civic.residency.wrong_place', 'Wrong place?') }}
                <button type="button" class="link-btn" @click="openDeclare">{{ t('c_civic.residency.change_home', 'Change my home') }}</button>
            </p>
        </Card>

        <!-- Threshold met: is this your home? -->
        <Card v-else-if="panel === 'pending_confirmation'" as="section" class="hero" :eyebrow="t('c_civic.residency.eyebrow_last', 'Last step')">
            <template #title>
                <h2>{{ t('c_civic.residency.confirm_h2', { home: homeName }) }}</h2>
            </template>
            <p>
                <template v-if="isInstant">{{ t('c_civic.residency.declared_instant', { home: homeName }) }}</template>
                <template v-else>{{ t('c_civic.residency.checked_in', { home: homeName, days: qualifyingDays }) }}</template>
                {{ t('c_civic.residency.confirm_belong', 'Confirm and you belong to every place that contains it.') }}
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn variant="primary" icon="check" :disabled="confirmForm.processing" @click="submitConfirm">
                    {{ confirmForm.processing ? t('c_civic.residency.confirming', 'Confirming…') : t('c_civic.residency.yes_home', 'Yes, this is my home') }}
                </Btn>
                <Btn variant="secondary" @click="openDeclare">{{ t('c_civic.residency.no_change_home', 'No, change my home') }}</Btn>
            </div>
        </Card>

        <!-- Verified: you live in X -->
        <Card v-else-if="panel === 'verified'" as="section" class="hero" :eyebrow="t('c_civic.residency.eyebrow_confirmed', 'Confirmed')">
            <template #title>
                <h2>{{ t('c_civic.residency.verified_h2', { home: homeName }) }}</h2>
            </template>
            <div
                ref="mapEl"
                class="boundary-map"
                role="region"
                :aria-label="t('c_civic.residency.map_of', { home: homeName })"
            ></div>
            <p style="margin-block-start: var(--space-3)">
                {{ t('c_civic.residency.belong_count', { count: associations.length, plural: associations.length === 1 ? '' : 's' }) }}
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
                {{ t('c_civic.residency.moved', 'Moved?') }}
                <button type="button" class="link-btn" @click="openDeclare">{{ t('c_civic.residency.change_home', 'Change my home') }}</button>
            </p>
        </Card>

        <!-- ═══════════════════ Declare / change my home (F-IND-003) ═══════════════════ -->
        <div v-if="declareVisible" ref="declareCardEl">
            <Card as="section" class="hero" :eyebrow="isUndeclared ? (isInstant ? t('c_civic.residency.eyebrow_one_step', 'One step') : t('c_civic.residency.eyebrow_step1', 'Step 1 of 2')) : t('c_civic.residency.change_home', 'Change my home')">
                <template #title>
                    <h2>{{ isUndeclared ? t('c_civic.residency.where_live', 'Where do you live?') : t('c_civic.residency.where_live_now', 'Where do you live now?') }}</h2>
                </template>
                <form novalidate @submit.prevent="submitDeclare">
                    <p>
                        {{ t('c_civic.residency.declare_body', 'Use your current location, or click your home on the map. Only the place you live in is recorded, never the exact point.') }}
                        <template v-if="!isUndeclared && !isInstant">
                            {{ t('c_civic.residency.change_restart', 'Changing your home starts the check-in days again inside the new place.') }}
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
                            {{ geolocating ? t('c_civic.residency.finding_you', 'Finding you…') : t('c_civic.residency.use_location', 'Use my current location') }}
                        </Btn>
                        <span class="gloss">{{ t('c_civic.residency.or_click_map', 'or click your home on the map') }}</span>
                    </div>

                    <div class="map-wrap" style="margin-block-end: var(--space-3)">
                        <div ref="pickerEl" class="boundary-map" role="region" :aria-label="t('c_civic.residency.map_click', 'Map — click where you live')"></div>
                        <p v-if="basemapMissing" class="map-note">
                            {{ t('c_civic.residency.no_tiles', 'No map tiles are loaded on this box. Use your current location or search by name below.') }}
                        </p>
                    </div>

                    <p v-if="locatingPoint" class="gloss" role="status">{{ t('c_civic.residency.finding_point', 'Finding the place at that point…') }}</p>
                    <p v-if="locateError" class="field-error" role="alert">{{ locateError }}</p>

                    <div v-if="located" class="locate-preview" role="status">
                        <p class="cc-small" style="margin-block-end: var(--space-1)">
                            {{ t('c_civic.residency.home_is_in', 'Your home is in') }} <strong>{{ located.jurisdiction.name }}</strong>{{ t('c_civic.residency.sits_inside', '. It sits inside:') }}
                        </p>
                        <div class="cluster">
                            <template v-for="(level, i) in located.chain" :key="level.id">
                                <span v-if="i > 0" aria-hidden="true">→</span>
                                <AdmChip :level="level.adm_level" :label="level.name" />
                            </template>
                        </div>
                    </div>
                    <p v-else-if="selected" class="locate-preview" role="status">
                        {{ t('c_civic.residency.your_home', 'Your home:') }}
                        <AdmChip :level="selected.adm_level" :label="selected.name" />
                        <span v-if="selected.parent_name" class="citation"> {{ t('c_civic.residency.in_parent', { parent: selected.parent_name }) }}</span>
                    </p>

                    <details class="search-collapse" style="margin-block-end: var(--space-3)">
                        <summary>{{ t('c_civic.residency.search_by_name', 'Search by place name instead') }}</summary>
                        <Field
                            :label="t('c_civic.residency.place_name', 'Place name')"
                            :hint="t('c_civic.residency.place_name_hint', 'Type the name of the town, county, region or country you live in. Street addresses are not searched.')"
                            :error="declareForm.errors.jurisdiction_id"
                        >
                            <template #control="{ id, invalid, describedBy }">
                                <input
                                    :id="id"
                                    v-model="search"
                                    class="field-input"
                                    type="search"
                                    :placeholder="t('c_civic.residency.place_name_placeholder', 'e.g. Anne Arundel, New York, Serravalle')"
                                    autocomplete="off"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                />
                            </template>
                        </Field>
                        <p v-if="searching" class="gloss" role="status">{{ t('c_civic.residency.searching', 'Searching…') }}</p>
                        <ul v-if="results.length" class="search-results" role="listbox" :aria-label="t('c_civic.residency.matching_places', 'Matching places')">
                            <li v-for="result in results" :key="result.id">
                                <button type="button" class="search-result" role="option" aria-selected="false" @click="pick(result)">
                                    <AdmChip :level="result.adm_level" :label="result.name" />
                                    <span class="citation">
                                        {{ result.parent_name ? t('c_civic.residency.in_parent', { parent: result.parent_name }) : '' }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </details>

                    <p v-if="declareForm.errors.jurisdiction_id && !located && !selected" class="field-error">
                        {{ t('c_civic.residency.choose_first', 'Choose the place you live in first.') }}
                    </p>

                    <div class="field" :class="{ 'field--invalid': declareForm.errors.ping_consent }">
                        <CheckboxField v-model="declareForm.ping_consent" name="ping_consent">
                            <template v-if="isInstant">
                                {{ t('c_civic.residency.consent_instant', 'I confirm this is where I live. My location is private and is used only to place me here.') }}
                            </template>
                            <template v-else>
                                {{ t('c_civic.residency.consent_monitored', { days: thresholdDays }) }}
                            </template>
                        </CheckboxField>
                        <span v-if="declareForm.errors.ping_consent" class="field-error">
                            {{ t('c_civic.residency.tick_box', 'Please tick the box to continue.') }}
                        </span>
                    </div>

                    <div class="cluster" style="margin-block-start: var(--space-3)">
                        <Btn type="submit" variant="primary" icon="check" :disabled="declareForm.processing || !declareForm.jurisdiction_id">
                            {{ declareForm.processing ? t('c_civic.residency.saving', 'Saving…') : isInstant ? t('c_civic.residency.confirm_my_home', 'Confirm my home') : (isUndeclared ? t('c_civic.residency.declare_my_home', 'Declare my home') : t('c_civic.residency.change_home', 'Change my home')) }}
                        </Btn>
                        <Btn v-if="!isUndeclared" type="button" variant="ghost" @click="showDeclare = false">{{ t('c_civic.residency.keep_current', 'Keep my current home') }}</Btn>
                    </div>
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        <template v-if="isInstant">{{ t('c_civic.residency.instant_note', 'You are confirmed the moment you declare.') }}</template>
                        <template v-else>{{ t('c_civic.residency.monitored_note', { days: thresholdDays }) }}</template>
                    </p>
                </form>
            </Card>
        </div>

        <!-- ═══════════════════ How this works (the fine print) ═══════════════════ -->
        <details class="more">
            <summary>{{ t('c_civic.residency.how_works_summary', 'How this works, and the fine print') }}</summary>
            <Card as="section" :title="t('c_civic.residency.claim_title', 'Your residency claim')">
                <StateStrip :states="machine" :current="claim?.status ?? null" />
                <p v-if="hasClaim" class="gloss" style="margin-block-start: var(--space-2)">
                    {{ t('c_civic.residency.declared_label', 'Declared:') }}
                    <AdmChip :level="claim.jurisdiction?.adm_level ?? 0" :label="claim.jurisdiction?.name ?? '—'" />
                    · {{ claim.declared_at ? localeFmt.date(new Date(claim.declared_at)) : '—' }}
                </p>
                <p style="margin-block-start: var(--space-3)">
                    <AmendableSetting
                        :value="isInstant ? t('c_civic.residency.instant_value', 'instant') : t('c_civic.residency.days_value', { days: thresholdDays })"
                        setting-key="residency_confirmation_days"
                        citation="CLK-05 · Art. I; Art. V §1"
                    />
                </p>
                <p class="cc-small" style="margin-block-start: var(--space-3)">
                    {{ t('c_civic.residency.decl_word', 'Declaration') }} <FormChip form-id="F-IND-003" /> {{ t('c_civic.residency.checkin_word', '· Check-in') }} <FormChip form-id="F-IND-005" />
                    {{ t('c_civic.residency.confirm_word', '· Confirmation') }} <FormChip form-id="F-IND-006" /> <HardenedChip />
                    <span class="citation" style="display: block">
                        {{ t('c_civic.residency.roles_cite', 'available to R-01 Individual · the confirmation creates verified residency and every jurisdictional association (R-03) · Art. I; Art. V §1') }}
                    </span>
                </p>
                <Banner tone="info" :title="t('c_civic.residency.privacy_title', 'Check-ins are private.')" style="margin-block-start: var(--space-3)">
                    {{ t('c_civic.residency.privacy_body', 'Locations are encrypted at rest, never shown to anyone, and deleted once you are confirmed. Only the number of days is ever visible.') }}
                    <span class="citation">{{ t('c_civic.residency.pings_cite', 'location_pings · private · Art. I') }}</span>
                </Banner>
            </Card>
        </details>

        <!-- ═══════════════════ Developer tools (local builds only) ═══════════════════ -->
        <details v-if="isDev" class="more">
            <summary>{{ t('c_civic.residency.dev_summary', 'Developer tools (local only)') }}</summary>
            <Card as="section" :title="t('c_civic.residency.dev_title', 'Shortcuts through the real engine')">
                <div class="cluster">
                    <Btn variant="gold" size="sm" icon="map-pin" :disabled="!devGrantTarget || granting" @click="devGrant">
                        {{ granting ? t('c_civic.residency.granting', 'Granting…') : t('c_civic.residency.grant_instantly', 'Grant residency instantly') }}
                    </Btn>
                    <span class="citation">
                        {{ t('c_civic.residency.grant_targets', { name: devGrantTarget?.name ?? '—' }) }}
                    </span>
                </div>
                <p v-if="grantResult" class="gloss" role="status" style="margin-block-start: var(--space-2)">{{ grantResult }}</p>
                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn variant="gold" size="sm" icon="clock" :disabled="!hasClaim || isInstant || simulating" @click="simulate(30)">
                        {{ simulating ? t('c_civic.residency.simulating', 'Simulating…') : t('c_civic.residency.simulate_30', 'Simulate 30 days of check-ins') }}
                    </Btn>
                    <span class="citation">{{ t('c_civic.residency.simulate_cite', 'files 30 real F-IND-005 entries on the open claim') }}</span>
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
