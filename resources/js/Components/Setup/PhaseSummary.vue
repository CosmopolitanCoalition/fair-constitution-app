<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
    summary: { type: Object, default: null },
})
</script>

<template>
    <div v-if="summary" class="grid grid-cols-2 gap-3 mb-4 text-sm">
        <div class="bg-gray-950 border border-gray-800 rounded p-3">
            <div class="text-gray-400 text-xs">{{ t('c_setup_components.phase_summary.phase1', 'Phase 1 — Boundaries') }}</div>
            <div class="text-gray-100 font-mono">
                {{ summary.phase1_countries_done }} {{ t('c_setup_components.phase_summary.countries_done', 'countries done') }}
            </div>
            <div
                v-if="summary.phase1_in_progress"
                class="text-blue-300 font-mono text-xs mt-1"
            >
                → {{ summary.phase1_in_progress }}
            </div>
        </div>
        <div class="bg-gray-950 border border-gray-800 rounded p-3">
            <div class="text-gray-400 text-xs">{{ t('c_setup_components.phase_summary.phase2', 'Phase 2 — Population') }}</div>
            <div class="text-gray-100 font-mono">
                {{ summary.phase2_countries_done }} {{ t('c_setup_components.phase_summary.countries_done', 'countries done') }}
            </div>
            <div
                v-if="summary.phase2_in_progress"
                class="text-blue-300 font-mono text-xs mt-1"
            >
                → {{ summary.phase2_in_progress }}
            </div>
        </div>
    </div>
</template>
