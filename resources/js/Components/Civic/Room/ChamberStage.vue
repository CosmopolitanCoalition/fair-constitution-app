<script setup>
/**
 * The chamber stage — every participant's live media laid out as a responsive
 * grid of seats (the AV counterpart to the v3 live-room chamber scene). The
 * floor-holder / local seat sorts first; tiles flow to fill. Empty until voice
 * is joined.
 */
import { computed } from 'vue';
import ParticipantTile from './ParticipantTile.vue';

const props = defineProps({
    participants: { type: Array, default: () => [] },
    connectionState: { type: String, default: 'disconnected' },
});

// Keep the live indicator + seat count through a reconnection blip (the tiles persist);
// only a real disconnect drops them.
const live = computed(() => props.connectionState === 'connected' || props.connectionState === 'reconnecting');
const count = computed(() => props.participants.length);
const statusLabel = computed(() => {
    if (props.connectionState === 'reconnecting') return 'Reconnecting…';
    if (live.value) return `${count.value} in the call`;
    if (props.connectionState === 'connecting') return 'Connecting…';
    return 'Not connected';
});
</script>

<template>
    <section class="rounded-2xl border border-black/10 bg-neutral-900 p-3 dark:border-white/10">
        <header class="mb-2 flex items-center justify-between px-1 text-sm text-white/80">
            <span class="font-medium">In the chamber</span>
            <span class="inline-flex items-center gap-1.5">
                <span v-if="live" class="h-2 w-2 animate-pulse rounded-full bg-emerald-400" aria-hidden="true"></span>
                {{ statusLabel }}
            </span>
        </header>

        <div v-if="count" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            <ParticipantTile
                v-for="p in participants"
                :key="p.identity"
                :identity="p.identity"
                :is-local="p.isLocal"
                :is-speaking="p.isSpeaking"
                :audio-track="p.audioTrack"
                :video-track="p.videoTrack"
            />
        </div>

        <p v-else class="px-1 py-10 text-center text-sm text-white/50">
            No one is on the floor yet — join the voice call to take your seat.
        </p>
    </section>
</template>
