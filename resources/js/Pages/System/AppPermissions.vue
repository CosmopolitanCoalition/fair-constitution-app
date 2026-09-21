<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import { appInstalled, installAvailable, installApp } from '@/lib/installedApp';
import { csrfFetch } from '@/lib/csrf';
import { currentLocation, locationEnabled, setLocationEnabled } from '@/lib/locationPermission';

const props = defineProps({ signedIn: Boolean, pushKey: String, places: Array, devices: Array });
const { t } = useI18n();
const busy = ref(false), error = ref(''), notice = ref('');
const checkingDevice = ref(true);
const locationState = ref('unknown'), locationAllowed = ref(locationEnabled());
const notificationState = ref('Notification' in window ? Notification.permission : 'unsupported');
const pushSupported = 'PushManager' in window && 'serviceWorker' in navigator && 'Notification' in window;
const devices = ref([...props.devices]);
const currentId = ref(null);
const device = computed(() => devices.value.find(d => d.id === currentId.value));
const prefs = ref({ invitations: true, messages: true, clocks: false, clock_jurisdiction_id: props.places?.[0]?.id || null, remind_minutes: 60 });
let geoPermission;
async function readPermissions() {
    notificationState.value = 'Notification' in window ? Notification.permission : 'unsupported';
    try {
        geoPermission = await navigator.permissions.query({ name: 'geolocation' });
        locationState.value = geoPermission.state;
        geoPermission.onchange = () => { locationState.value = geoPermission.state; };
    } catch { locationState.value = 'unknown'; }
}
async function registration() {
    return Promise.race([navigator.serviceWorker.ready, new Promise((_, reject) => setTimeout(() => reject(new Error(t('c_app.worker_wait'))), 10000))]);
}
async function findDevice() {
    if (!pushSupported || !props.signedIn) return;
    const sub = await (await registration()).pushManager.getSubscription();
    if (!sub) return;
    const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(sub.endpoint));
    const hash = [...new Uint8Array(bytes)].map(x => x.toString(16).padStart(2, '0')).join('');
    const found = devices.value.find(d => d.endpoint_hash === hash);
    if (found) { currentId.value = found.id; prefs.value = { ...prefs.value, ...found }; }
    else await sub.unsubscribe(); // A previous account's endpoint must not follow a browser account switch.
}
onMounted(() => {
    readPermissions();
    findDevice().catch(() => {}).finally(() => { checkingDevice.value = false; });
    window.addEventListener('focus', readPermissions);
});
onBeforeUnmount(() => { window.removeEventListener('focus', readPermissions); if (geoPermission) geoPermission.onchange = null; });
async function api(path, method, data) {
    const response = await csrfFetch(`/app-notifications/subscriptions${path}`, {
        method, headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: data ? JSON.stringify(data) : undefined,
    });
    if (!response.ok) {
        const detail = await response.json().catch(() => ({}));
        throw new Error(detail.message || t('c_app.save_failed'));
    }
    return response.status === 204 ? null : response.json();
}
async function enablePush() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        // The permission request occurs directly in the click handler, before any other await.
        const permission = await Notification.requestPermission();
        notificationState.value = permission;
        if (permission !== 'granted') { notice.value = t('c_app.notification_denied'); return; }
        const reg = await registration();
        const bytes = Uint8Array.from(atob(props.pushKey.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
        let sub = await reg.pushManager.getSubscription();
        if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes });
        const result = await api('', 'POST', sub.toJSON());
        currentId.value = result.id;
        if (!device.value) devices.value.push({ id: result.id, ...prefs.value });
        await savePreferences();
        notice.value = t('c_app.enabled');
    } catch (e) { error.value = e.message; }
    finally { busy.value = false; }
}
async function savePreferences() {
    if (!currentId.value) return;
    await api(`/${currentId.value}`, 'PUT', {
        invitations: prefs.value.invitations, messages: prefs.value.messages, clocks: prefs.value.clocks,
        clock_jurisdiction_id: prefs.value.clock_jurisdiction_id, remind_minutes: prefs.value.remind_minutes,
    });
}
async function perform(action) {
    busy.value = true; error.value = ''; notice.value = '';
    try { await action(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function disable(id) {
    await api(`/${id}`, 'DELETE');
    if (id === currentId.value) {
        const sub = await (await registration()).pushManager.getSubscription();
        await sub?.unsubscribe();
        currentId.value = null;
    }
    devices.value = devices.value.filter(d => d.id !== id);
    notice.value = t('c_app.disabled');
}
function requestLocation() {
    setLocationEnabled(true); locationAllowed.value = true; error.value = ''; notice.value = '';
    if (!navigator.geolocation) { error.value = t('c_app.location_unavailable'); return; }
    busy.value = true;
    currentLocation(() => {
        busy.value = false; locationState.value = 'granted'; notice.value = t('c_app.location_ready');
    }, e => {
        busy.value = false;
        if (e.code === 1) locationState.value = 'denied';
        error.value = e.code === 1 ? t('c_app.location_denied') : t('c_app.location_unavailable');
    });
}
function stopLocation() { setLocationEnabled(false); locationAllowed.value = false; notice.value = t('c_app.location_stopped'); }
</script>

<template>
    <PageScaffold :title="t('c_app.settings')">
        <template #intro>{{ t('c_app.intro') }}</template>
        <section class="app-setting-card" aria-labelledby="install-title">
            <h2 id="install-title">{{ t('c_app.install_title') }}</h2>
            <p>{{ t('c_app.install_body') }}</p>
            <p v-if="appInstalled" role="status">{{ t('c_app.installed') }}</p>
            <button v-else-if="installAvailable" class="btn btn--primary" @click="perform(installApp)" :disabled="busy">{{ t('c_app.install_button') }}</button>
            <template v-else><p>{{ t('c_app.android_install') }}</p><p>{{ t('c_app.ios_install') }}</p></template>
            <p class="muted">{{ t('c_app.online') }}</p>
        </section>
        <section class="app-setting-card" aria-labelledby="location-title">
            <h2 id="location-title">{{ t('c_app.location_title') }}</h2>
            <p>{{ t('c_app.location_body') }}</p>
            <p>{{ t('c_app.browser_permission') }} <strong>{{ t(`c_app.permission_${locationState}`) }}</strong></p>
            <p v-if="!locationAllowed">{{ t('c_app.location_stopped') }}</p>
            <p v-if="locationState === 'denied'">{{ t('c_app.location_denied') }}</p>
            <div class="app-setting-actions">
                <button class="btn btn--secondary" :disabled="busy" @click="requestLocation">{{ t('c_app.location_enable') }}</button>
                <button v-if="locationAllowed" class="btn btn--secondary" @click="stopLocation">{{ t('c_app.location_stop') }}</button>
                <a class="btn btn--secondary" href="/civic/residency">{{ t('c_app.residency') }}</a>
            </div>
        </section>
        <section class="app-setting-card" aria-labelledby="notifications-title">
            <h2 id="notifications-title">{{ t('c_app.notifications_title') }}</h2>
            <p>{{ t('c_app.notifications_body') }}</p>
            <p>{{ t('c_app.browser_permission') }} <strong>{{ t(`c_app.permission_${notificationState}`) }}</strong></p>
            <p v-if="!signedIn"><a href="/login">{{ t('c_app.sign_in') }}</a></p>
            <p v-else-if="!pushSupported">{{ t('c_app.push_unsupported') }}</p>
            <template v-else>
                <p v-if="notificationState === 'denied'">{{ t('c_app.notification_denied') }}</p>
                <fieldset :disabled="busy" class="app-preferences">
                    <legend>{{ t('c_app.on_this_device') }}</legend>
                    <label><input type="checkbox" v-model="prefs.invitations"> {{ t('c_app.invitations') }}</label>
                    <label><input type="checkbox" v-model="prefs.messages"> {{ t('c_app.messages') }}</label>
                    <label><input type="checkbox" v-model="prefs.clocks" :disabled="!places.length"> {{ t('c_app.clocks') }}</label>
                    <p v-if="!places.length"><a href="/civic/residency">{{ t('c_app.clock_residency') }}</a></p>
                    <template v-if="prefs.clocks">
                        <label>{{ t('c_app.clock_place') }} <select v-model="prefs.clock_jurisdiction_id"><option v-for="place in places" :key="place.id" :value="place.id">{{ place.name }}</option></select></label>
                        <label>{{ t('c_app.clock_lead') }} <select v-model="prefs.remind_minutes"><option :value="60">{{ t('c_app.hour') }}</option><option :value="1440">{{ t('c_app.day') }}</option></select></label>
                        <p class="muted">{{ t('c_app.clock_scope') }}</p>
                    </template>
                </fieldset>
                <div class="app-setting-actions">
                    <button v-if="!device" class="btn btn--primary" :disabled="busy || checkingDevice || notificationState === 'denied'" @click="enablePush">{{ t('c_app.enable_notifications') }}</button>
                    <template v-else>
                        <button class="btn btn--primary" :disabled="busy" @click="perform(async () => { await savePreferences(); notice = t('c_app.saved'); })">{{ t('c_app.save') }}</button>
                        <button class="btn btn--secondary" :disabled="busy" @click="perform(async () => { await api(`/${currentId}/test`, 'POST'); notice = t('c_app.test_queued'); })">{{ t('c_app.test') }}</button>
                        <button class="btn btn--secondary" :disabled="busy" @click="perform(() => disable(currentId))">{{ t('c_app.disable') }}</button>
                    </template>
                </div>
                <details v-if="devices.length"><summary>{{ t('c_app.devices') }} ({{ devices.length }})</summary>
                    <ul><li v-for="(d, index) in devices" :key="d.id">{{ d.id === currentId ? t('c_app.this_device') : t('c_app.device_number', { n: index + 1 }) }} <button class="btn btn--secondary" :disabled="busy" @click="perform(() => disable(d.id))">{{ t('c_app.remove') }}</button></li></ul>
                </details>
            </template>
        </section>
        <p v-if="notice" role="status">{{ notice }}</p>
        <p v-if="error" role="alert" class="field-error">{{ error }}</p>
        <p>{{ t('c_app.browser_controls') }}</p>
    </PageScaffold>
</template>

<style scoped>
.app-setting-card{padding:1.25rem;border:1px solid var(--gov-border);border-radius:.6rem;background:var(--gov-surface);margin-block:1rem}
.app-setting-card h2{margin-block:0 .75rem}.app-setting-card p{margin-block:.75rem}.app-setting-actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-block:1rem}
.app-preferences{border:0;padding:.5rem 0;display:grid;gap:.8rem}.app-preferences label{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}.app-preferences select{max-width:100%}details{margin-block:1rem}li{margin-block:.75rem}
</style>

