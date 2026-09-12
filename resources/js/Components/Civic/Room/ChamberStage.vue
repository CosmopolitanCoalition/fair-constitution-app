<script setup>
/**
 * The chamber stage — every participant's live media laid out as a responsive
 * grid of seats (the AV counterpart to the v3 live-room chamber scene). The
 * floor-holder / local seat sorts first; tiles flow to fill. Empty until voice
 * is joined.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ParticipantTile from './ParticipantTile.vue';
import CivicFloor from './CivicFloor.vue';
import { personLabel } from './roomPresentation.js';

const props = defineProps({
    participants: { type: Array, default: () => [] },
    connectionState: { type: String, default: 'disconnected' },
    selectedDevices: { type: Object, default: () => ({ camera: '', mic: '', speaker: '' }) },
    variant: { type: String, default: 'commons' },
    roster: { type: Array, default: () => [] },
    floorHolder: { type: String, default: null },
});
const { t } = useI18n();
const text = (key, fallback) => t('c_rooms.' + key, fallback);

// Keep the live indicator + seat count through a reconnection blip (the tiles persist);
// only a real disconnect drops them.
const live = computed(() => props.connectionState === 'connected' || props.connectionState === 'reconnecting');
const count = computed(() => props.participants.length);
const statusLabel = computed(() => {
    if (props.connectionState === 'reconnecting') return text('reconnecting', 'Reconnecting');
    if (live.value) return t('c_rooms.participants_count', { count: count.value }, '{count} in the call');
    if (props.connectionState === 'connecting') return text('connecting', 'Connecting');
    return text('not_connected', 'Not connected');
});
// Anyone sharing a screen gets a prominent presenter tile above the seat grid (and still a
// normal camera/avatar seat below). A presenter's screen track is rendered "contain", no audio.
const presenters = computed(() => props.participants.filter((p) => p.screenTrack));
</script>

<template>
    <section class="rounded-2xl border border-black/10 bg-neutral-900 p-3 dark:border-white/10">
        <header class="mb-2 flex items-center justify-between px-1 text-sm text-white/80">
            <span class="font-medium">{{ text('live_call', 'Live call') }}</span>
            <span class="inline-flex items-center gap-1.5">
                <span v-if="live" class="h-2 w-2 animate-pulse rounded-full bg-emerald-400" aria-hidden="true"></span>
                {{ statusLabel }}
            </span>
        </header>

        <!-- Presenter view: shared screens, large. -->
        <div v-if="presenters.length" class="mb-2 grid gap-2" :class="presenters.length > 1 ? 'sm:grid-cols-2' : ''">
            <ParticipantTile
                v-for="p in presenters"
                :key="'screen-' + p.identity"
                :identity="p.identity"
                :display-name="personLabel(p)"
                :is-local="p.isLocal"
                :video-track="p.screenTrack"
                :audio-track="p.screenAudioTrack"
                :audio-output="selectedDevices.speaker"
                :presenting="true"
            />
        </div>

        <CivicFloor :variant="variant" :roster="roster" :participants="participants" :floor-holder="floorHolder" :audio-output="selectedDevices.speaker" />
    </section>
</template>
