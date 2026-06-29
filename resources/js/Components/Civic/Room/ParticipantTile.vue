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
import { MicOff, ScreenShare } from 'lucide-vue-next';
import Avatar from '@/Components/Ui/Avatar.vue';

const props = defineProps({
    identity: { type: String, required: true }, // @u-<handle>:domain
    isLocal: { type: Boolean, default: false },
    isSpeaking: { type: Boolean, default: false },
    audioTrack: { type: Object, default: null },
    videoTrack: { type: Object, default: null },
    // A screen-share tile: render the shared screen (contain, not cover), screen audio only, no speaking ring.
    presenting: { type: Boolean, default: false },
    // The chosen speaker (audiooutput) deviceId — applied per audio element so a NEW participant's
    // audio also plays out the selected device (Room.switchActiveDevice only touches elements that existed
    // at switch time). Empty = the system default.
    audioOutput: { type: String, default: '' },
});

const videoEl = ref(null);
const audioEl = ref(null);
let boundVideo = null;
let boundAudio = null;

// Route this element's audio to the chosen speaker (Chrome/Edge; setSinkId is unsupported elsewhere).
function applySink() {
    const el = audioEl.value;
    if (el && typeof el.setSinkId === 'function' && props.audioOutput) {
        el.setSinkId(props.audioOutput).catch(() => {});
    }
}

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
watch(() => [props.audioTrack, audioEl.value], () => { boundAudio = bind(props.audioTrack, audioEl, boundAudio); applySink(); });
watch(() => props.audioOutput, applySink);

onBeforeUnmount(() => {
    if (boundVideo && videoEl.value) boundVideo.detach(videoEl.value);
    if (boundAudio && audioEl.value) boundAudio.detach(audioEl.value);
});
</script>

<template>
    <div
        class="relative aspect-video overflow-hidden rounded-xl bg-black/80 ring-2 transition-shadow"
        :class="!presenting && isSpeaking ? 'ring-emerald-400 shadow-lg shadow-emerald-500/20' : (presenting ? 'ring-sky-500/40' : 'ring-transparent')"
    >
        <video v-show="videoTrack" ref="videoEl" autoplay playsinline :muted="isLocal"
            class="h-full w-full" :class="presenting ? 'bg-black object-contain' : 'object-cover'"></video>

        <div v-show="!videoTrack" class="flex h-full w-full items-center justify-center">
            <Avatar :initials="initials(identity)" :title="identity" />
        </div>

        <!-- remote audio only; the local mic / own screen audio is never played back. On a camera tile this
             is the participant's mic; on a presenter tile it's the screen's tab/system audio (if any). -->
        <audio v-if="!isLocal" ref="audioEl" autoplay></audio>

        <div class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5 text-xs text-white">
            <ScreenShare v-if="presenting" :size="14" class="shrink-0 opacity-90" aria-label="screen share" />
            <MicOff v-else-if="muted()" :size="14" class="shrink-0 opacity-90" aria-label="muted" />
            <span class="truncate">@u-{{ handle(identity) }}{{ presenting ? ' · screen' : '' }}</span>
            <span v-if="isLocal" class="ml-auto rounded bg-white/20 px-1 text-[10px] uppercase tracking-wide">you</span>
        </div>
    </div>
</template>
