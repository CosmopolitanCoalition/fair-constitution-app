<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    lifecycle: { type: String, required: true },
    startedAt: { type: String, default: null },
    paused:    { type: Boolean, default: false },
    // P.1.2: when paused, the supervisor stamps the pause moment into
    // running.json so the badge can freeze its session timer at that
    // instant. The bars-side reset happens via started_at shifting
    // forward on resume, so the same timer keeps ticking from the
    // pre-pause value.
    pausedAt:  { type: String, default: null },
})

const now = ref(Date.now())
let tickTimer = null

onMounted(() => {
    tickTimer = setInterval(() => { now.value = Date.now() }, 1000)
})
onBeforeUnmount(() => { if (tickTimer) clearInterval(tickTimer) })

const elapsed = computed(() => {
    if (!props.startedAt) return ''
    const started = new Date(props.startedAt).getTime()
    if (!Number.isFinite(started)) return ''
    // While paused, freeze the timer at the moment the supervisor stamped.
    const endMs = props.paused && props.pausedAt
        ? new Date(props.pausedAt).getTime()
        : now.value
    const ref   = Number.isFinite(endMs) ? endMs : now.value
    const secs = Math.max(0, Math.round((ref - started) / 1000))
    const h = Math.floor(secs / 3600)
    const m = Math.floor((secs % 3600) / 60)
    const s = secs % 60
    return h > 0 ? `${h}h ${m}m ${s}s` : `${m}m ${s}s`
})

const classes = computed(() => {
    if (props.lifecycle === 'running' && props.paused) {
        return 'bg-amber-900/50 text-amber-300 border-amber-800'
    }
    switch (props.lifecycle) {
        case 'running': return 'bg-blue-900/50 text-blue-300 border-blue-800'
        case 'done':    return 'bg-emerald-900/50 text-emerald-300 border-emerald-800'
        case 'failed':  return 'bg-red-900/50 text-red-300 border-red-800'
        default:        return 'bg-gray-800 text-gray-400 border-gray-700'
    }
})

const label = computed(() => {
    if (props.lifecycle === 'running' && props.paused) return 'PAUSED'
    return props.lifecycle.toUpperCase()
})
</script>

<template>
    <span
        class="text-xs px-2 py-1 rounded font-mono border inline-flex items-center gap-2"
        :class="classes"
    >
        <span
            v-if="lifecycle === 'running'"
            class="w-1.5 h-1.5 rounded-full"
            :class="paused ? 'bg-amber-400' : 'bg-blue-400 animate-pulse'"
        />
        {{ label }}<span v-if="lifecycle === 'running' && elapsed"> · {{ elapsed }}</span>
    </span>
</template>
