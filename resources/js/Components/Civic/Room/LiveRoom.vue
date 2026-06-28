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
import Banner from '@/Components/Ui/Banner.vue';
import { useVoiceRoom } from '@/composables/useVoiceRoom.js';
import ChamberStage from './ChamberStage.vue';
import VoiceControls from './VoiceControls.vue';

const props = defineProps({
    jurisdictionId: { type: String, required: true },
    room: { type: String, required: true }, // the call room (the commons/halls room id)
    pseudonym: { type: String, required: true }, // the player's OWN @u-<handle>
    subjectUserId: { type: String, required: true }, // the player's OWN user id
});

const { connectionState, degraded, error, micEnabled, cameraEnabled, participants, join, leave, toggleMic, toggleCamera } =
    useVoiceRoom();

// Map known error codes to friendly copy — never render a raw server string (it could carry
// internal topology, e.g. an unreachable peer's hostname). Unknown codes fall back to generic.
const ERROR_COPY = {
    voice_unavailable: 'Voice isn’t available here right now.',
    voice_unavailable_here: 'Voice isn’t available from this node right now.',
    peer_refused: 'The voice host declined the connection.',
    peer_unreachable: 'The voice host can’t be reached right now.',
    action_signature_invalid: 'Your device couldn’t be verified for voice.',
    sfu_connect_failed: 'Couldn’t connect to the voice server.',
};
const errorMessage = computed(() => {
    if (!error.value) return null;
    // Server codes may be prefixed (e.g. "peer_unreachable: …"); match on the leading token only.
    const code = String(error.value).split(':')[0].trim();
    return ERROR_COPY[code] ?? 'Couldn’t join the call.';
});

async function onJoin() {
    try {
        await join({
            jurisdictionId: props.jurisdictionId,
            room: props.room,
            pseudonym: props.pseudonym,
            subjectUserId: props.subjectUserId,
        });
    } catch {
        /* error state is set on the composable; the banner shows it */
    }
}
</script>

<template>
    <div class="space-y-3">
        <Banner v-if="degraded" tone="warning">
            No voice host is reachable from here right now — the room stays text-only. Your posts and the
            record plane are unaffected.
        </Banner>
        <Banner v-else-if="error" tone="danger">
            {{ errorMessage }} You can still take part in text below.
        </Banner>

        <ChamberStage :participants="participants" :connection-state="connectionState" />

        <VoiceControls
            :connection-state="connectionState"
            :mic-enabled="micEnabled"
            :camera-enabled="cameraEnabled"
            @join="onJoin"
            @leave="leave"
            @toggle-mic="toggleMic"
            @toggle-camera="toggleCamera"
        />
    </div>
</template>
