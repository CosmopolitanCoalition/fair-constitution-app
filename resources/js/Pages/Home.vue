<script setup>
/**
 * Home — landing page. Default AppShell chrome (applied via the app.js
 * default-layout registration, no explicit layout needed).
 *
 * Setup gating now reads the `instance.setupComplete` shared prop (WI-3)
 * instead of the old fetch('/api/setup/state') round trip — one less request
 * per visit and no flash of the wrong state.
 */
import { computed, onMounted } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'

const page = usePage()
const setupComplete = computed(() => page.props.instance?.setupComplete ?? true)

onMounted(() => {
    if (!setupComplete.value) {
        router.visit('/setup', { replace: true })
    }
})
</script>

<template>
    <Head title="Home" />

    <div class="stack" style="align-items: center; text-align: center; padding-block: var(--space-12)">
        <div v-if="!setupComplete" class="citation">Redirecting to setup…</div>
        <h1 v-else>Fair Constitution App</h1>
    </div>
</template>
