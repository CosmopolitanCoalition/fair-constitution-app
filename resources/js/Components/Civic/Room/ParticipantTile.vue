<script setup>
/**
 * A single seat in the chamber: one participant's live media. Renders the camera
 * track when published, else a pseudonymous avatar; always attaches the audio
 * track (remote only — never the local mic, to avoid an echo). A speaking ring is
 * driven by LiveKit's active-speaker state. The identity is the @u-<handle>
 * pseudonym, never a legal name.
 *
 * LiveKit Track objects attach to a DOM element via track.attach(el); we
 * attach/detach on track or element change and on unmount.
 */
import { onBeforeUnmount, ref, watch } from 'vue';
import { MicOff } from 'lucide-vue-next';
import Avatar from '@/Components/Ui/Avatar.vue';

const props = defineProps({
    identity: { type: String, required: true }, // @u-<handle>:domain
    isLocal: { type: Boolean, default: false },
    isSpeaking: { type: Boolean, default: false },
    audioTrack: { type: Object, default: null },
    videoTrack: { type: Object, default: null },
});

const videoEl = ref(null);
const audioEl = ref(null);
let boundVideo = null;
let boundAudio = null;

function handle(identity) {
    return String(identity || '').replace(/^@/, '').split(':')[0].replace(/^u-/, '');
}
function initials(identity) {
    return (handle(identity).slice(0, 2) || '··').toUpperCase();
}
const muted = () => props.audioTrack === null || props.audioTrack?.isMuted;

// Always detach from the SPECIFIC element — never the no-arg track.detach(), which
// detaches the track from EVERY element it was ever bound to (a latent hazard if a
// track is ever shown in two places). attach(el) is idempotent per element.
function bind(track, el, prev) {
    if (prev && prev !== track && el?.value) prev.detach(el.value);
    if (track && el?.value) track.attach(el.value);
    return track ?? null;
}

watch(() => [props.videoTrack, videoEl.value], () => { boundVideo = bind(props.videoTrack, videoEl, boundVideo); });
watch(() => [props.audioTrack, audioEl.value], () => { boundAudio = bind(props.audioTrack, audioEl, boundAudio); });

onBeforeUnmount(() => {
    if (boundVideo && videoEl.value) boundVideo.detach(videoEl.value);
    if (boundAudio && audioEl.value) boundAudio.detach(audioEl.value);
});
</script>

<template>
    <div
        class="relative aspect-video overflow-hidden rounded-xl bg-black/80 ring-2 transition-shadow"
        :class="isSpeaking ? 'ring-emerald-400 shadow-lg shadow-emerald-500/20' : 'ring-transparent'"
    >
        <video v-show="videoTrack" ref="videoEl" autoplay playsinline :muted="isLocal" class="h-full w-full object-cover"></video>

        <div v-show="!videoTrack" class="flex h-full w-full items-center justify-center">
            <Avatar :initials="initials(identity)" :title="identity" />
        </div>

        <!-- remote audio only; the local mic is never played back -->
        <audio v-if="!isLocal" ref="audioEl" autoplay></audio>

        <div class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5 text-xs text-white">
            <MicOff v-if="muted()" :size="14" class="shrink-0 opacity-90" aria-label="muted" />
            <span class="truncate">@u-{{ handle(identity) }}</span>
            <span v-if="isLocal" class="ml-auto rounded bg-white/20 px-1 text-[10px] uppercase tracking-wide">you</span>
        </div>
    </div>
</template>
