<script setup>
/**
 * Mounted once in the app shell for a signed-in person. Hosts the floating call panel and the
 * messenger, and on first load asks the call session to rejoin a call that was left running when the
 * window closed or the connection dropped. Because the shell stays mounted across page visits, both
 * keep their state while the person navigates.
 */
import { computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CallDock from '@/Components/Shell/CallDock.vue';
import MessengerDock from '@/Components/Shell/MessengerDock.vue';
import { useCall } from '@/lib/callSession.js';

const { t } = useI18n();
const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
const call = useCall();
onMounted(() => { if (signedIn.value) void call.recover(); });
</script>

<template>
    <aside v-if="signedIn" class="dock-host" :aria-label="t('c_dock.region', 'Messages and calls')">
        <CallDock />
        <MessengerDock />
    </aside>
</template>

<style scoped>
.dock-host { position: fixed; inset-inline-end: 0.75rem; inset-block-end: 4.75rem; z-index: 40; display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem; pointer-events: none; }
.dock-host > * { pointer-events: auto; }
@media (max-width: 40rem) { .dock-host { inset-inline: 0.5rem; align-items: stretch; } }
</style>
