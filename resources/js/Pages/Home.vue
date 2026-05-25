<script setup>
import { onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const loading = ref(true)

onMounted(async () => {
    try {
        const res = await fetch('/api/setup/state', {
            headers: { 'Accept': 'application/json' },
        })
        const data = await res.json()
        if (!data.complete) {
            router.visit('/setup', { replace: true })
            return
        }
    } catch (e) {
        // On error, fall through to the home dashboard — don't trap the user.
    }
    loading.value = false
})
</script>

<template>
    <AppLayout>
        <div class="min-h-full flex items-center justify-center">
            <div v-if="loading" class="text-gray-400 text-sm">Checking setup state…</div>
            <h1 v-else class="text-3xl font-bold">Fair Constitution App</h1>
        </div>
    </AppLayout>
</template>
