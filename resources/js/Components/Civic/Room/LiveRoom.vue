<script setup>
/**
 * The live civic room's AV shell (Phase 5 "Deferred 2"): wires the device-signed
 * voice-token request (Deferred 1) through useVoiceRoom into the chamber stage +
 * in-room controls. Drop it into the commons / halls page; the live text floor +
 * the testimony bridge stay where they are (the timeline panel).
 *
 * The commons is OPEN (Art. I) — any authenticated player may join the call; the
 * game gates governance powers, not presence. No SFU reachable degrades to
 * text-only (never an error).
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Banner from '@/Components/Ui/Banner.vue';
import { useVoiceRoom } from '@/composables/useVoiceRoom.js';
import ChamberStage from './ChamberStage.vue';
import VoiceControls from './VoiceControls.vue';

const props = defineProps({
    jurisdictionId: { type: String, required: true },
    room: { type: String, required: true }, // the call room (the commons/halls room id, or a private room id)
    pseudonym: { type: String, required: true }, // the player's OWN @u-<handle>
    subjectUserId: { type: String, required: true }, // the player's OWN user id
    // Optional: a member-gated token requester for a PRIVATE room. Null → the commons device-signed default.
    tokenRequester: { type: Function, default: null },
    variant: { type: String, default: 'commons' },
    roster: { type: Array, default: () => [] },
    floorHolder: { type: String, default: null },
    displayNames: { type: Object, default: () => ({}) },
});

const {
    connectionState, degraded, error, micEnabled, cameraEnabled, screenShareEnabled, mediaPending, audioBlocked,
    participants, devices, selectedDevices,
    join, leave, toggleMic, toggleCamera, toggleScreenShare, selectDevice, startAudio,
} = useVoiceRoom();
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
    try {
        await join({
            jurisdictionId: props.jurisdictionId,
            room: props.room,
            pseudonym: props.pseudonym,
            subjectUserId: props.subjectUserId,
            tokenRequester: props.tokenRequester,
        });
    } catch {
        /* error state is set on the composable; the banner shows it */
    }
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

        <ChamberStage :participants="namedParticipants" :connection-state="connectionState" :selected-devices="selectedDevices" :variant="variant" :roster="roster" :floor-holder="floorHolder" />

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
