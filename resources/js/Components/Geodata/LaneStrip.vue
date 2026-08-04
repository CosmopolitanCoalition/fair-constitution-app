<script setup>
/**
 * One working lane: its label, live per-unit progress, elapsed and honest
 * ETA. Extracted so the panel can nest it — a coordinator renders one of
 * these for itself and one per slice underneath, which is the organisation
 * the operator asked for ("indent with the parent/meta and slices").
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({ it: { type: Object, required: true } })

// Local ticker so elapsed advances between the panel's polls.
const nowTick = ref(Date.now())
let timer = null
onMounted(() => { timer = setInterval(() => { nowTick.value = Date.now() }, 1000) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })

const label = computed(() => {
    const it = props.it
    const iso = it.iso_code || ''
    const lvl = it.adm_level !== null && it.adm_level !== undefined ? ` L${it.adm_level}` : ''
    const suffix = it.kind.endsWith('_range') ? ' ·slice'
        : it.kind.endsWith('_decompose') ? ' ·decompose' : ''
    return `${it.kind.replace(/_(iso|pair|global)$/, '')}·${iso}${lvl}${suffix}`
})

const elapsed = computed(() => {
    if (!props.it.started_at) return ''
    const s = Math.max(0, Math.floor((nowTick.value - new Date(props.it.started_at).getTime()) / 1000))
    if (s < 60) return `${s}s`
    if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`
    return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`
})

// ETA only once a real rate exists (>=2% done) — never fabricated.
const eta = computed(() => {
    const l = props.it.live
    if (!l || !l.total || !l.current) return ''
    const frac = l.current / l.total
    if (frac < 0.02 || frac >= 1) return ''
    const ms = nowTick.value - new Date(props.it.started_at).getTime()
    if (ms <= 0) return ''
    const m = Math.round(ms * (1 - frac) / frac / 60000)
    if (m < 1) return '· <1m left'
    return m < 90 ? `· ~${m}m left` : `· ~${Math.round(m / 60)}h left`
})

const pct = computed(() => {
    const l = props.it.live
    return l && l.total ? Math.min(100, Math.round(l.current / l.total * 100)) : null
})
</script>

<template>
    <div class="text-xs bg-gray-800/50 rounded px-2.5 py-1.5">
        <div class="flex items-center justify-between mb-1">
            <span class="flex items-center gap-2 min-w-0">
                <span class="w-1.5 h-1.5 rounded-full shrink-0 bg-sky-400 animate-pulse" aria-hidden="true" />
                <span class="text-gray-200 font-medium">{{ label }}</span>
                <span v-if="it.live" class="text-gray-400 truncate">{{ it.live.label }}</span>
            </span>
            <span class="text-gray-500 tabular-nums shrink-0 ml-3">
                <template v-if="it.live && it.live.total">
                    {{ it.live.current.toLocaleString() }} / {{ it.live.total.toLocaleString() }} {{ it.live.unit }} ·
                </template>
                {{ elapsed }} {{ eta }}
            </span>
        </div>
        <div class="h-1.5 bg-gray-900 rounded overflow-hidden">
            <div v-if="pct !== null" class="h-full rounded bg-sky-500 transition-all duration-700"
                 :style="{ width: pct + '%' }" />
            <div v-else class="h-full w-1/4 rounded bg-sky-800 animate-pulse" />
        </div>
    </div>
</template>
