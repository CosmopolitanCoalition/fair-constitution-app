<script setup>
/**
 * One hidden <audio> bound to a remote LiveKit track. The call dock renders one per remote
 * voice (and per screen-share audio), so sound keeps playing on every page of the app.
 * Never given the local microphone track, which would echo.
 */
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    track: { type: Object, default: null },
    sink: { type: String, default: '' }, // chosen speaker deviceId; empty = system default
});

const el = ref(null);
let bound = null;

function applySink() {
    if (el.value && typeof el.value.setSinkId === 'function' && props.sink) el.value.setSinkId(props.sink).catch(() => {});
}
// Detach from this element only, never the no-argument detach() that unbinds every element.
watch(() => [props.track, el.value], () => {
    if (bound && bound !== props.track && el.value) bound.detach(el.value);
    if (props.track && el.value) props.track.attach(el.value);
    bound = props.track ?? null;
    applySink();
}, { flush: 'post' });
watch(() => props.sink, applySink);
onBeforeUnmount(() => { if (bound && el.value) bound.detach(el.value); });
</script>

<template>
    <audio ref="el" autoplay aria-hidden="true"></audio>
</template>
