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
/**
 * The bars are lane 3's shared component, not ours (authorised 2026-07-26).
 * Both lanes converged on the same contract independently — they built theirs
 * from this controller's `stages()` payload — so the swap needed no change on
 * the server side at all. Two near-identical progress surfaces would have been
 * the duplicated-rule defect in UI form, and the operator's end state is a
 * fresh box building a world with a bar on every stage: one idiom, growing.
 *
 * If this page ever needs a field the component lacks, EXTEND THE COMPONENT and
 * tell lane 3 — do not fork it back apart.
 */
import StageBars from '@/Components/Progress/StageBars.vue'
import { csrfFetch } from '@/lib/csrf'

/**
 * The shell is declared as this page's LAYOUT, not wrapped around its template.
 *
 * `app.js` applies `AppShell` as the default persistent layout to any page that
 * does not declare one. Wrapping the template in `<AppShellV2>` therefore
 * rendered BOTH — the default shell outside and this one inside — which put a
 * second header, a second language picker and a second user chip inside the
 * content area. Declaring the layout replaces the default instead of nesting
 * beneath it, which is the idiom every other page here uses.
 *
 * Found by lane 9's capture harness: the first time anyone looked at this page.
 * It served 200, compiled clean and had zero console errors the whole time it
 * was doing this — which is the argument for eyes over status codes.
 */
defineOptions({ layout: AppShellV2 })

const props = defineProps({
    instanceClass: { type: String, default: 'production' },
    isScaleDemo: { type: Boolean, default: false },
    // DRIVE controls: only a signed-in operator sees them; controlRefusal carries
    // the server's synthetic-data refusal SENTENCE verbatim (null when the world
    // may run), rendered where the buttons would be — the D2 refusal contract.
    canControl: { type: Boolean, default: false },
    controlRefusal: { type: String, default: null },
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

// The last DRIVE action's echo (start "enumerating…", a halt request), so the
// operator sees an effect the instant the queued command has yet to mint a run.
const controlMarker = computed(() => data.value.control || null)

// ── DRIVE controls — one POST per verb; the server holds the guard, the
// single-run law and the audit mark (SimRunControl), so the button is thin. ──
const busy = ref('')
const actionError = ref('')
const smokeLimit = ref('')

async function drive(verb, body = {}) {
    busy.value = verb
    actionError.value = ''
    try {
        const res = await csrfFetch(`/api/simworld/${verb}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
        const payload = await res.json().catch(() => ({}))
        if (!res.ok || payload.ok === false) {
            // The server's refusal SENTENCE, verbatim — never second-guessed.
            actionError.value = payload.reason || `Could not ${verb} the run (${res.status}).`
        } else {
            await poll() // reflect the new state without waiting for the 2 s tick
        }
    } catch (e) {
        actionError.value = e?.message || `Could not ${verb} the run.`
    } finally {
        busy.value = ''
    }
}

const startRun = () => drive('start', { limit: smokeLimit.value === '' ? null : Number(smokeLimit.value) })
const haltRun = () => drive('halt')
const resumeRun = () => drive('resume')

// Which verb the run's live status allows — the console never offers an act the
// server would refuse for state reasons (it still refuses for guard reasons).
const canStart = computed(() => !run.value || ['done', 'failed'].includes(run.value.status))
const canHalt = computed(() => !!run.value && ['queued', 'running'].includes(run.value.status) && !run.value.halt_requested)
const canResume = computed(() => !!run.value && (run.value.status === 'halted' || run.value.halt_requested))

const totalDone = computed(() => stages.value.reduce((a, s) => a + s.done, 0))
const totalItems = computed(() => stages.value.reduce((a, s) => a + s.total, 0))
const totalReview = computed(() => stages.value.reduce((a, s) => a + s.review, 0))

/** Terminal runs stop the timer; halted does NOT — a halt is resumable and the
 *  page must keep showing the moment it resumes. */
const isTerminal = computed(() => ['done', 'failed'].includes(run.value?.status))

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

            <!-- DRIVE CONTROLS — operator only. The console is public-read (Art. II
                 §2); driving a run is not. On a real world the server's refusal
                 SENTENCE renders verbatim where the buttons would be, so the rail
                 is legible rather than a disabled mystery (the D2 contract). -->
            <section v-if="canControl" class="rounded-lg border border-gray-700/60 bg-gray-900/40 p-4">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Drive the run</h2>

                <p v-if="controlRefusal" class="mt-2 text-sm text-amber-200">{{ controlRefusal }}</p>

                <template v-else>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <template v-if="canStart">
                            <label class="flex items-center gap-2 text-xs text-gray-400">
                                smoke limit
                                <input
                                    v-model="smokeLimit"
                                    type="number"
                                    min="1"
                                    placeholder="all"
                                    class="w-24 rounded border border-gray-700 bg-gray-950/60 px-2 py-1 font-mono text-sm text-gray-200"
                                />
                            </label>
                            <button
                                type="button"
                                :disabled="busy !== ''"
                                class="min-h-[44px] rounded bg-emerald-800/70 px-4 py-2 text-sm font-semibold text-emerald-100 ring-1 ring-emerald-700 hover:bg-emerald-700/70 disabled:opacity-50"
                                @click="startRun"
                            >
                                {{ busy === 'start' ? 'Starting…' : 'Start populate run' }}
                            </button>
                        </template>

                        <button
                            v-if="canHalt"
                            type="button"
                            :disabled="busy !== ''"
                            class="min-h-[44px] rounded bg-amber-800/70 px-4 py-2 text-sm font-semibold text-amber-100 ring-1 ring-amber-700 hover:bg-amber-700/70 disabled:opacity-50"
                            @click="haltRun"
                        >
                            {{ busy === 'halt' ? 'Halting…' : 'Halt run' }}
                        </button>

                        <button
                            v-if="canResume"
                            type="button"
                            :disabled="busy !== ''"
                            class="min-h-[44px] rounded bg-emerald-800/70 px-4 py-2 text-sm font-semibold text-emerald-100 ring-1 ring-emerald-700 hover:bg-emerald-700/70 disabled:opacity-50"
                            @click="resumeRun"
                        >
                            {{ busy === 'resume' ? 'Resuming…' : 'Resume run' }}
                        </button>
                    </div>

                    <p v-if="canStart" class="mt-2 text-xs text-gray-500">
                        A smoke limit enumerates only the N largest jurisdictions — leave it blank to populate the
                        whole set. Enumeration runs on the queue; the bars come alive within the minute.
                    </p>

                    <!-- The server's refusal or error, verbatim. -->
                    <p v-if="actionError" class="mt-2 text-sm text-rose-300">{{ actionError }}</p>

                    <!-- The last action's echo, so a start is visible before the run row exists. -->
                    <p v-if="controlMarker && controlMarker.note" class="mt-2 text-xs text-gray-500">
                        <span class="font-mono text-gray-400">{{ controlMarker.action }}</span>
                        <span v-if="controlMarker.status"> · {{ controlMarker.status }}</span>
                        — {{ controlMarker.note }}
                    </p>
                    <pre
                        v-else-if="controlMarker && controlMarker.tail"
                        class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap font-mono text-[11px] text-gray-500"
                    >{{ controlMarker.tail }}</pre>
                </template>
            </section>

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

                <!-- PER-STAGE BARS — the shared component (lane 3's), not a
                     second set of our own. One idiom for every build stage. -->
                <section v-if="stages.length" class="space-y-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Stages</h2>
                    <StageBars :stages="stages" :poll-ms="2000" />
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

                    <!-- BUILT IS NOT GOVERNED. Said first, and plainly, because
                         a visitor reading places and people would otherwise
                         assume governments. An empty chamber is the CORRECT
                         state until an election fills it. -->
                    <dl class="mt-3 grid grid-cols-3 gap-x-6 gap-y-3 rounded border border-gray-700/50 bg-gray-950/40 p-3">
                        <div>
                            <dt class="text-xs text-gray-500">places with a chamber</dt>
                            <dd class="font-mono text-lg text-gray-100">{{ fmt(world.chambers) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">chambers with members</dt>
                            <dd class="font-mono text-lg text-emerald-300">{{ fmt(world.chambers_governed) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">awaiting a first election</dt>
                            <dd class="font-mono text-lg text-amber-300">{{ fmt(world.chambers_awaiting_election) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs text-gray-500">
                        A chamber exists as soon as a place is activated, but only an election puts people in it —
                        seating anyone without one would manufacture members nobody voted for. An empty chamber is
                        the correct state, not a failure.
                        <span v-if="world.active_district_maps !== undefined">
                            Active district maps: <span class="font-mono text-gray-400">{{ fmt(world.active_district_maps) }}</span>
                            — a drawn map is a <em>draft</em> until adopted, and a chamber above nine seats cannot
                            elect without an adopted one.
                        </span>
                    </p>

                    <!--
                        THE HONESTY RAIL. Art. V §3: Type B may not exceed the Type A
                        total. The seat ladder floors at 2-per-constituent, so a chamber
                        still over the bound there is flagged and stops — stage two,
                        grouping constituents into shared panels, is unbuilt.

                        Niue's national chamber reads 11 / 14 and this engine seated all
                        25 seats before the flag reached it. "25 / 25 filled" looks like a
                        complete government; it is a lower house of 11 and an upper house
                        of 14 that no settled rule authorises. Whether such a chamber may
                        lawfully elect is the operator's question — but it does not get to
                        look ordinary while he decides.
                    -->
                    <section
                        v-if="world.over_bound && world.over_bound.count > 0"
                        class="mt-4 rounded border border-amber-500/40 bg-amber-500/5 p-3"
                    >
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-300">
                            Over the Type B bound — {{ fmt(world.over_bound.count) }}
                            {{ world.over_bound.count === 1 ? 'chamber' : 'chambers' }}
                        </h3>
                        <p class="mt-1 text-xs text-gray-400">
                            Art. V §3 binds the Type B chamber to the Type A total. These exceed it: the seat
                            ladder floors at two seats per constituent and cannot go lower, and the next step —
                            grouping constituent jurisdictions into shared panels — is not built. A chamber marked
                            <span class="text-amber-200">seated</span> has already returned members under a seat
                            count no settled rule authorises.
                        </p>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="p in world.over_bound.places"
                                :key="p.name"
                                class="flex flex-wrap items-baseline gap-x-2 text-xs"
                            >
                                <span class="text-gray-200">{{ p.name }}</span>
                                <span class="font-mono text-gray-500">
                                    type A {{ p.type_a }} · type B {{ p.type_b }}
                                </span>
                                <span
                                    :class="p.seated ? 'text-amber-300' : 'text-gray-500'"
                                    class="font-mono"
                                >{{ p.seated ? 'seated' : 'not seated' }}</span>
                            </li>
                        </ul>
                    </section>

                    <!--
                        DRIFT IS ALWAYS WRONG. The chamber size is fixed by the
                        cube-root law, so a district plan that does not sum to it
                        leaves seats with no race that can ever fill them. San Marino
                        draws 8+7+7+9 = 31 against a Type A of 32, so its elections
                        land 58/59 and always will. Only a redraw fixes it — which is
                        why this names the gap instead of quietly absorbing it.
                    -->
                    <section
                        v-if="world.seat_gap && world.seat_gap.count > 0"
                        class="mt-4 rounded border border-rose-500/40 bg-rose-500/5 p-3"
                    >
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-rose-300">
                            Seats that cannot be filled — {{ fmt(world.seat_gap.count) }}
                            {{ world.seat_gap.count === 1 ? 'chamber' : 'chambers' }}
                        </h3>
                        <p class="mt-1 text-xs text-gray-400">
                            The cube-root law fixes how many seats a chamber has; its districts must sum to
                            exactly that. Where they do not, the difference is seats the constitution declares
                            and no election can fill — every count lands short, permanently. This is a defect in
                            the district plan, and only redrawing it repairs it.
                        </p>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="p in world.seat_gap.places"
                                :key="p.name"
                                class="flex flex-wrap items-baseline gap-x-2 text-xs"
                            >
                                <span class="text-gray-200">{{ p.name }}</span>
                                <span class="font-mono text-gray-500">
                                    type A {{ p.type_a }} · districts draw {{ p.drawn }}
                                </span>
                                <span class="font-mono text-rose-300">
                                    {{ p.gap > 0 ? `${p.gap} unfillable` : `${-p.gap} unallotted` }}
                                </span>
                            </li>
                        </ul>
                    </section>

                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
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
</template>
