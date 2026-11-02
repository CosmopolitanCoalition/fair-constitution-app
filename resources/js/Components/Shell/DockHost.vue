<script setup>
/**
 * Mounted once in the app shell for a signed-in person. Hosts the one floating window (the live call and
 * its chat) and the call's audio, and on first load asks the call session to rejoin a call that was left
 * running when the window closed or the connection dropped. It renders into <body> so no page's stacking
 * context (a map, a sticky header) can cover it. The shell stays mounted across page visits, so call and
 * chat keep their state while the person navigates.
 */
import { computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CallAudio from '@/Components/Shell/CallAudio.vue';
import DockWindow from '@/Components/Shell/DockWindow.vue';
import { useCall } from '@/lib/callSession.js';

const { t } = useI18n();
const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
const call = useCall();
onMounted(() => { if (signedIn.value) void call.recover(); });
</script>

<template>
    <Teleport to="body">
        <aside v-if="signedIn" class="dock-host" :aria-label="t('c_dock.region', 'Messages and calls')">
            <CallAudio />
            <DockWindow />
        </aside>
    </Teleport>
</template>

<style scoped>
.dock-host { position: fixed; inset-inline-end: 0.75rem; inset-block-end: 4.75rem; z-index: 5000; display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem; pointer-events: none; max-inline-size: calc(100vw - 1rem); }
.dock-host > * { pointer-events: auto; }
@media (max-width: 40rem) { .dock-host { inset-inline: 0.5rem; align-items: stretch; } }
</style>
