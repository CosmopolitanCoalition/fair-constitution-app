<script setup>
/**
 * One layout for a room's call and its text chat. While the person is in this room's call the two sit
 * side by side on a wide screen (call on the left, chat on the right) and stack on a narrow one; with no
 * call they simply stack. The pages put the call in the `call` slot and the thread and composer in `chat`.
 */
import { computed } from 'vue';
import { useCall } from '@/lib/callSession.js';

const props = defineProps({ room: { type: String, default: null } });
const call = useCall();
const live = computed(() => !!props.room && call.isFor(props.room)
    && ['connected', 'reconnecting', 'connecting'].includes(call.voice.connectionState.value));
</script>

<template>
    <div class="call-chat" :class="{ 'call-chat--live': live }">
        <div class="call-chat__call"><slot name="call" /></div>
        <div class="call-chat__chat"><slot name="chat" /></div>
    </div>
</template>

<style scoped>
.call-chat { display: grid; gap: 1rem; }
.call-chat__call, .call-chat__chat { min-inline-size: 0; }
@media (min-width: 64rem) {
    .call-chat--live { grid-template-columns: minmax(0, 3fr) minmax(20rem, 2fr); align-items: start; }
    .call-chat--live .call-chat__chat { position: sticky; inset-block-start: 1rem; }
}
</style>
