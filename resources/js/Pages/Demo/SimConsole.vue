<script setup>
/**
 * The simulated-world populate console.
 *
 * Built to be LEFT OPEN while a run happens — the same job Step3_Districts.vue
 * does for the district mapper. Everything here comes from a fresh aggregate
 * every 2 s; there is no progress table and nothing is cached, so a number that
 * moves means work actually happened.
 *
 * Numbers tween rather than jump, because a counter that snaps between polls
 * reads as a glitch and a counter that eases reads as a machine working.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import AppShellV2 from '@/Layouts/AppShellV2.vue'

const props = defineProps({
    instanceClass: { type: String, default: 'production' },
    isScaleDemo: { type: Boolean, default: false },
    initial: { type: Object, default: () => ({}) },
})

const data = ref(props.initial || {})
const lastPoll = ref(null)
const polling = ref(false)
let timer = null

const run = computed(() => data.value.run || null)
const stages = computed(() => data.value.stages || [])
const workers = computed(() => data.value.workers || [])
const liveItems = computed(() => data.value.live_items || [])
const reviewItems = computed(() => data.value.review_items || [])
const world = computed(() => data.value.world || {})

const totalDone = computed(() => stages.value.reduce((a, s) => a + s.done, 0))
const totalItems = computed(() => stages.value.reduce((a, s) => a + s.total, 0))
const totalReview = computed(() => stages.value.reduce((a, s) => a + s.review, 0))

/** Terminal runs stop the timer; halted does NOT — a halt is resumable and the
 *  page must keep showing the moment it resumes. */
const isTerminal = computed(() => ['done', 'failed'].includes(run.value?.status))

const pct = (done, total) => (total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0)

const fmt = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString())

async function poll() {
    if (polling.value) return
    polling.value = true
    try {
        const res = await fetch('/api/simworld/progress', { headers: { Accept: 'application/json' } })
        if (res.ok) {
            data.value = await res.json()
            lastPoll.value = new Date()
        }
    } catch (e) {
        // A failed poll is not an error state — the engine is in the DB, not in
        // this page. Keep polling; the next tick heals it.
    } finally {
        polling.value = false
    }
    if (isTerminal.value && timer) {
        clearInterval(timer)
        timer = null
    }
}

onMounted(() => {
    poll()
    timer = setInterval(poll, 2000)
})

onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
})

const statusTone = computed(() => {
    const s = run.value?.status
    if (s === 'running') return 'text-emerald-400'
    if (s === 'halted') return 'text-amber-400'
    if (s === 'done') return 'text-sky-400'
    if (s === 'failed') return 'text-rose-400'
    return 'text-gray-400'
})
</script>

<template>
    <AppShellV2 title="Simulated world">
        <div class="mx-auto max-w-5xl px-4 py-6 space-y-6">
            <header class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-100">Simulated world — populate engine</h1>
                    <p class="mt-1 text-sm text-gray-400">
                        Live. Refreshes every 2 seconds from the work queue itself — no cached counters.
                    </p>
                </div>
                <div class="text-right text-xs text-gray-500">
                    <div>
                        instance class
                        <span :class="isScaleDemo ? 'text-emerald-400' : 'text-amber-400'" class="font-mono">
                            {{ instanceClass }}
                        </span>
                    </div>
                    <div v-if="lastPoll">updated {{ lastPoll.toLocaleTimeString() }}</div>
                </div>
            </header>

            <!-- A production instance can never run this engine. Say so plainly
                 rather than showing an empty page that looks broken. -->
            <div v-if="!isScaleDemo" class="rounded-lg border border-amber-700/40 bg-amber-950/30 p-4 text-sm text-amber-200">
                This instance is <span class="font-mono">production</span>, so it carries no synthetic data and
                the populate engine will refuse to run here. The engine runs only on an instance classed
                <span class="font-mono">scale_demo</span>.
            </div>

            <div v-if="!run" class="rounded-lg border border-gray-700/60 bg-gray-900/40 p-6 text-sm text-gray-400">
                No populate run yet. Start one with
                <code class="rounded bg-gray-800 px-1.5 py-0.5 font-mono text-gray-200">php artisan sim:start</code>
                — this page comes alive within the minute, when the pump seeds its first workers.
            </div>

            <template v-else>
                <!-- RUN STATE -->
                <section class="rounded-lg border border-gray-700/60 bg-gray-900/40 p-4">
                    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                        <div>
                            <span class="text-xs uppercase tracking-wide text-gray-500">status</span>
                            <span :class="statusTone" class="ml-2 font-semibold">{{ run.status }}</span>
                        </div>
                        <div>
                            <span class="text-xs uppercase tracking-wide text-gray-500">stage</span>
                            <span class="ml-2 font-mono text-gray-200">{{ run.phase }}</span>
                        </div>
                        <div>
                            <span class="text-xs uppercase tracking-wide text-gray-500">workers</span>
                            <span class="ml-2 font-mono text-gray-200">
                                {{ workers.length }}<span class="text-gray-500">/{{ run.workers_target }}</span>
                            </span>
                        </div>
                        <div v-if="totalItems">
                            <span class="text-xs uppercase tracking-wide text-gray-500">items</span>
                            <span class="ml-2 font-mono text-gray-200">{{ fmt(totalDone) }}/{{ fmt(totalItems) }}</span>
                        </div>
                    </div>

                    <p v-if="run.halt_requested" class="mt-3 text-sm text-amber-300">
                        Halt requested — workers stop at their next claim boundary, never mid-item. Clearing the
                        flag resumes exactly where it stopped.
                    </p>
                    <p v-else-if="run.is_paused" class="mt-3 text-sm text-amber-300">
                        Paused by the database breaker until {{ run.paused_until }} — a Postgres restart was
                        detected. This pauses claims; it never abandons work.
                    </p>
                    <p v-if="run.last_error" class="mt-2 font-mono text-xs text-gray-500">{{ run.last_error }}</p>

                    <!-- The phase DAG, so the run's position is legible at a glance. -->
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <span
                            v-for="p in run.phases"
                            :key="p"
                            class="rounded px-2 py-0.5 text-xs font-mono"
                            :class="p === run.phase
                                ? 'bg-emerald-900/60 text-emerald-300 ring-1 ring-emerald-700'
                                : 'bg-gray-800/60 text-gray-500'"
                        >{{ p }}</span>
                    </div>
                </section>

                <!-- PER-STAGE BARS — one per item kind, not a spinner. -->
                <section v-if="stages.length" class="space-y-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Stages</h2>
                    <div
                        v-for="s in stages"
                        :key="s.kind"
                        class="rounded-lg border p-3"
                        :class="s.is_current ? 'border-emerald-800/60 bg-emerald-950/20' : 'border-gray-700/50 bg-gray-900/30'"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <div class="flex items-baseline gap-2">
                                <span class="text-sm text-gray-200">{{ s.label }}</span>
                                <span class="font-mono text-xs text-gray-500">{{ s.kind }}</span>
                                <span v-if="s.running" class="inline-flex h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
                            </div>
                            <div class="font-mono text-xs text-gray-400">
                                {{ fmt(s.done) }}/{{ fmt(s.total) }}
                                <span v-if="s.review" class="ml-2 text-amber-400">{{ s.review }} review</span>
                            </div>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded bg-gray-800">
                            <div
                                class="h-full rounded transition-[width] duration-700 ease-out"
                                :class="s.review && s.done + s.review >= s.total ? 'bg-amber-500' : 'bg-emerald-500'"
                                :style="{ width: pct(s.done, s.total) + '%' }"
                            />
                        </div>
                    </div>
                </section>

                <!-- WORKER STRIP — one honest line per live worker. -->
                <section v-if="workers.length" class="rounded-lg border border-gray-700/50 bg-gray-900/30 p-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Workers ({{ workers.length }})
                    </h2>
                    <ul class="mt-2 space-y-1 font-mono text-xs text-gray-400">
                        <li v-for="w in workers" :key="w.id" class="flex items-baseline gap-2">
                            <span class="text-gray-600">{{ w.id }}</span>
                            <span class="text-gray-300">{{ w.claim_type || 'idle' }}</span>
                            <span class="truncate text-gray-500">{{ w.claim_label }}</span>
                            <span v-if="w.claim_secs !== null" class="ml-auto text-gray-600">{{ w.claim_secs }}s</span>
                        </li>
                    </ul>
                </section>

                <!-- WORKING NOW, by place name. -->
                <section v-if="liveItems.length" class="rounded-lg border border-gray-700/50 bg-gray-900/30 p-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Working now</h2>
                    <ul class="mt-2 space-y-1 font-mono text-xs text-gray-400">
                        <li v-for="(i, idx) in liveItems" :key="idx">
                            <span class="text-gray-600">{{ i.kind }}</span>
                            <span class="ml-2 text-gray-200">{{ i.jurisdiction }}</span>
                        </li>
                    </ul>
                </section>

                <!-- WHAT THE RUN HAS PRODUCED — the point of the machine. -->
                <section class="rounded-lg border border-gray-700/60 bg-gray-900/40 p-4">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">The world so far</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-gray-500">jurisdictions</dt>
                            <dd class="font-mono text-lg text-gray-100">{{ fmt(world.jurisdictions) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">cohorts modelled</dt>
                            <dd class="font-mono text-lg text-gray-100">{{ fmt(world.cohorts) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">people minted</dt>
                            <dd class="font-mono text-lg text-gray-100">{{ fmt(world.people) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">active residencies</dt>
                            <dd class="font-mono text-lg text-gray-100">{{ fmt(world.residencies) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">population modelled</dt>
                            <dd class="font-mono text-lg text-gray-100">{{ fmt(world.population_modelled) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">electorate modelled</dt>
                            <dd class="font-mono text-lg text-gray-100">{{ fmt(world.electorate_modelled) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-500">
                        People minted is deliberately far below population modelled: identity is materialized only
                        where the constitution requires it — a candidate, a member — and everyone else is counted
                        exactly as a cohort rather than stored as a row.
                    </p>
                </section>

                <!-- WHAT REFUSED. A run never dies of a failed item. -->
                <section v-if="reviewItems.length" class="rounded-lg border border-amber-800/40 bg-amber-950/20 p-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-amber-300">
                        Refused ({{ fmt(totalReview) }}) — the run continued
                    </h2>
                    <ul class="mt-2 space-y-1 text-xs text-amber-100/80">
                        <li v-for="(i, idx) in reviewItems" :key="idx">
                            <span class="font-mono text-amber-400/70">{{ i.kind }}</span>
                            <span class="ml-2">{{ i.jurisdiction }}</span>
                            <span v-if="i.reason" class="ml-2 text-amber-200/60">— {{ i.reason }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AppShellV2>
</template>
