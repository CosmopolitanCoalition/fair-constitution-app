<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLocaleFormat } from '@/composables/useLocaleFormat'
import { recentTimingCards } from '@/lib/simWorkerActivity'

const props = defineProps({ timings: { type: Array, default: () => [] } })
const { t } = useI18n()
const format = useLocaleFormat()
const cards = computed(() => recentTimingCards(props.timings))
const sampledAt = computed(() => props.timings.find(row => row.sampled_at)?.sampled_at)
</script>

<template>
    <section class="rounded-lg border border-gray-700/50 bg-gray-900/30 p-4 mb-4">
        <h2 class="text-sm font-semibold text-white">{{ t('c_setup.worker_activity.recent_heading') }}</h2>
        <p class="text-xs text-gray-400 mt-1">{{ t('c_setup.worker_activity.recent_note') }}</p>
        <p v-if="cards.some(card => card.batched)" class="text-xs text-gray-400 mt-1">{{ t('c_setup.worker_activity.batch_note') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
            <div v-for="card in cards" :key="card.key">
                <div class="text-xs text-gray-400">{{ t(`c_setup.worker_activity.${card.key}`) }}</div>
                <div class="text-lg text-white tabular-nums">
                    <template v-if="card.avg_ms != null">{{ format.number(card.avg_ms, { maximumFractionDigits: 1 }) }} ms</template>
                    <span v-else>—</span>
                </div>
                <div class="text-xs text-gray-400">
                    <template v-if="card.window_seconds != null">{{ t('c_setup.worker_activity.samples', { count: format.number(card.count), seconds: format.number(card.window_seconds) }) }}</template>
                    <template v-else>{{ t('c_setup.worker_activity.measuring') }}</template>
                </div>
            </div>
        </div>
        <p v-if="sampledAt" class="text-xs text-gray-400 mt-2">{{ t('c_setup.worker_activity.sampled', { time: format.time(sampledAt) }) }}</p>
    </section>
</template>
