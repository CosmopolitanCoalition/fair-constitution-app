<script setup>
/**
 * The live civic room's AV shell (Phase 5 "Deferred 2"): wires the device-signed
 * voice-token request (Deferred 1) through the shared call session (lib/callSession.js) into the chamber stage +
 * in-room controls. Drop it into the commons / halls page; the live text floor +
 * the testimony bridge stay where they are (the timeline panel).
 *
 * The commons is OPEN (Art. I) — any authenticated player may join the call; the
 * game gates governance powers, not presence. No SFU reachable degrades to
 * text-only (never an error).
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Banner from '@/Components/Ui/Banner.vue';
import { useCall } from '@/lib/callSession.js';
import { focusConversation } from '@/lib/messengerStore.js';
import { useRoomParticipants } from '@/composables/useRoomParticipants.js';
import ChamberStage from './ChamberStage.vue';
import VoiceControls from './VoiceControls.vue';

const props = defineProps({
    jurisdictionId: { type: String, required: true },
    room: { type: String, required: true }, // the call room (the commons/halls room id, or a private room id)
    pseudonym: { type: String, required: true }, // the player's OWN @u-<handle>
    subjectUserId: { type: String, required: true }, // the player's OWN user id
    // How the call asks for its token: 'commons' (device-signed default), 'private' (member-gated) or 'url'
    // (a page-supplied endpoint). A descriptor, not a function, so the call can be rejoined after a reload.
    callMode: { type: String, default: null },
    tokenUrl: { type: String, default: null },
    // Kept for older callers: a requester function means a private-room call.
    tokenRequester: { type: Function, default: null },
    title: { type: String, default: '' }, // shown in the floating call panel
    // The messenger key of this room's text chat (private:<id> or commons:<id>), so the floating window can show it with the call.
    chatKey: { type: String, default: null },
    variant: { type: String, default: 'commons' },
    roster: { type: Array, default: () => [] },
    rosterUrl: { type: String, default: null },
    floorHolder: { type: String, default: null },
    activeWitness: { type: String, default: null },
    displayNames: { type: Object, default: () => ({}) },
});

// The call itself lives in the app shell (lib/callSession.js), so it survives leaving this page. This
// component shows it while it is this room's call, and offers to join otherwise.
const call = useCall();
const page = usePage();
const mine = computed(() => call.isFor(props.room));
const v = call.voice;
const rawState = computed(() => (mine.value ? v.connectionState.value : 'disconnected'));
// A lost connection that is being retried reads as "reconnecting", not as an error.
const connectionState = computed(() => (mine.value && ['error', 'degraded'].includes(rawState.value) && call.phase.value === 'retrying' ? 'reconnecting' : rawState.value));
const degraded = computed(() => mine.value && v.degraded.value && call.phase.value !== 'retrying');
const error = computed(() => (mine.value && call.phase.value !== 'retrying' ? v.error.value : null));
const micEnabled = computed(() => mine.value && v.micEnabled.value);
const cameraEnabled = computed(() => mine.value && v.cameraEnabled.value);
const screenShareEnabled = computed(() => mine.value && v.screenShareEnabled.value);
const mediaPending = computed(() => (mine.value ? v.mediaPending.value : { mic: false, camera: false, screen: false }));
const audioBlocked = computed(() => mine.value && v.audioBlocked.value);
const participants = computed(() => (mine.value ? v.participants.value : []));
const devices = computed(() => v.devices.value);
const selectedDevices = computed(() => v.selectedDevices.value);
const toggleMic = () => mine.value && v.toggleMic();
const toggleCamera = () => mine.value && v.toggleCamera();
const toggleScreenShare = () => mine.value && v.toggleScreenShare();
const selectDevice = (kind, id) => mine.value && v.selectDevice(kind, id);
const startAudio = () => mine.value && v.startAudio();
const leave = () => (mine.value ? call.endCall() : undefined);
const { roster: currentRoster } = useRoomParticipants({
    url: () => props.rosterUrl, room: () => props.room,
    participants: () => participants.value, preview: () => props.roster,
});
const { t } = useI18n();
const text = (key, fallback) => t('c_rooms.' + key, fallback);
const namedParticipants = computed(() => participants.value.map((participant) => ({
    ...participant,
    display_name: props.displayNames[participant.identity] || participant.display_name,
})));

// Map known error codes to friendly copy — never render a raw server string (it could carry
// internal topology, e.g. an unreachable peer's hostname). Unknown codes fall back to generic.
const ERROR_COPY = {
    voice_unavailable: 'Voice isn’t available here right now.',
    voice_unavailable_here: 'Voice isn’t available from this node right now.',
    peer_refused: 'The voice host declined the connection.',
    peer_unreachable: 'The voice host can’t be reached right now.',
    action_signature_invalid: 'Your device couldn’t be verified for voice.',
    sfu_connect_failed: 'Couldn’t connect to the voice server.',
    room_not_accessible: 'This call is not available through this public room.',
    room_disconnected: 'The call disconnected. You can join again.',
    mic_permission_denied: 'Microphone access was denied. Allow microphone access in your browser to speak; you can keep listening.',
    mic_unavailable: 'No microphone is available. Connect one to speak; you can keep listening.',
    mic_in_use: 'The microphone could not start. Check whether another app is using it; you can keep listening.',
    mic_failed: 'The microphone could not be changed. You are still in the call.',
    camera_permission_denied: 'Camera access was denied. Allow camera access in your browser to share video; you can keep listening.',
    camera_unavailable: 'No camera is available. Connect one to share video; you can keep listening.',
    camera_in_use: 'The camera could not start. Check whether another app is using it; you can keep listening.',
    camera_failed: 'The camera could not be changed. You are still in the call.',
    screen_permission_denied: 'Screen sharing was not started. Choose a screen and allow sharing, or keep listening.',
    screen_unavailable: 'Screen sharing is not available in this browser. You can keep listening.',
    screen_in_use: 'The screen could not be shared. Try again or keep listening.',
    screen_failed: 'Screen sharing could not be changed. You are still in the call.',
    speaker_failed: 'The speaker could not be changed. Check your browser and system audio output settings.',
    audio_playback_failed: 'Your browser could not play room audio. Check its audio permissions, then select Enable room audio again.',
};
const errorMessage = computed(() => {
    if (!error.value) return null;
    // Server codes may be prefixed (e.g. "peer_unreachable: …"); match on the leading token only.
    const code = String(error.value).split(':')[0].trim();
    return text('error.' + code, ERROR_COPY[code] ?? 'Couldn’t join the call.');
});

async function onJoin() {
    const mode = props.callMode ?? (props.tokenRequester ? 'private' : 'commons');
    await call.startCall({
        mode, tokenUrl: props.tokenUrl, room: props.room, jurisdictionId: props.jurisdictionId,
        pseudonym: props.pseudonym, subjectUserId: props.subjectUserId, variant: props.variant,
        title: props.title || (typeof document !== 'undefined' ? document.title : ''), href: page.url || '/', chatKey: props.chatKey,
    });
    if (props.chatKey) focusConversation(props.chatKey);
}
</script>

<template>
    <div class="space-y-3">
        <Banner v-if="degraded" tone="warning">
            {{ text('voice_unavailable', 'Voice is not available right now. You can keep using the room.') }}
        </Banner>
        <Banner v-else-if="error" tone="warning">
            {{ errorMessage }} {{ text('room_open', 'The room remains open.') }}
        </Banner>

        <ChamberStage :participants="namedParticipants" :connection-state="connectionState" :selected-devices="selectedDevices" :variant="variant" :roster="currentRoster" :floor-holder="floorHolder" :active-witness="activeWitness" />

        <VoiceControls
            :connection-state="connectionState"
            :mic-enabled="micEnabled"
            :camera-enabled="cameraEnabled"
            :screen-share-enabled="screenShareEnabled"
            :media-pending="mediaPending"
            :audio-blocked="audioBlocked"
            :devices="devices"
            :selected-devices="selectedDevices"
            @join="onJoin"
            @leave="leave"
            @toggle-mic="toggleMic"
            @toggle-camera="toggleCamera"
            @toggle-screen="toggleScreenShare"
            @select-device="selectDevice"
            @start-audio="startAudio"
        />
    </div>
</template>
