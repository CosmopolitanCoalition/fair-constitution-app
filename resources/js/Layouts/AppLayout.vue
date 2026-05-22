<script setup>
import { onMounted, ref } from 'vue'
import SchemaUpdateBanner from '@/Components/SchemaUpdateBanner.vue'

const props = defineProps({
    hideNav: { type: Boolean, default: false },
})

const setupComplete = ref(true)

onMounted(async () => {
    if (props.hideNav) return
    try {
        const res = await fetch('/api/setup/state', {
            headers: { 'Accept': 'application/json' },
        })
        const data = await res.json()
        setupComplete.value = !!data.complete
    } catch (e) {
        setupComplete.value = true
    }
})
</script>

<template>
    <div class="h-screen overflow-hidden flex flex-col bg-gray-950 text-gray-100">
        <!-- Phase M: global schema-update banner. Self-hides when no
             migrations are pending; renders even on the setup wizard pages
             (hideNav is for the main nav, not for required system alerts). -->
        <SchemaUpdateBanner />
        <nav
            v-if="!hideNav && setupComplete"
            class="bg-gray-900 border-b border-gray-800 px-4 py-3 flex items-center gap-4 shrink-0"
        >
            <a href="/" class="text-lg font-semibold tracking-tight text-white hover:text-blue-400 transition-colors">
                Fair Constitution
            </a>
            <span class="text-gray-600">|</span>
            <a href="/jurisdictions" class="text-sm text-gray-400 hover:text-white transition-colors">
                Jurisdictions
            </a>
        </nav>
        <main class="flex-1 flex flex-col overflow-y-auto">
            <slot />
        </main>
    </div>
</template>
