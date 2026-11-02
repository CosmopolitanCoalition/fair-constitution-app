<script setup>
/**
 * Plays every remote voice of the one live call (lib/callSession.js). It has no visible output, so the
 * sound continues on every page, whether or not the floating window is open or minimized.
 */
import { computed } from 'vue';
import RemoteAudio from '@/Components/Shell/RemoteAudio.vue';
import { useCall } from '@/lib/callSession.js';

const call = useCall();
const v = call.voice;
const remoteAudio = computed(() => v.participants.value.filter((p) => !p.isLocal).flatMap((p) => [
    { id: p.identity + ':mic', track: p.audioTrack },
    { id: p.identity + ':screen', track: p.screenAudioTrack },
]).filter((a) => a.track));
</script>

<template>
    <RemoteAudio v-for="a in remoteAudio" :key="a.id" :track="a.track" :sink="v.selectedDevices.value.speaker" />
</template>
