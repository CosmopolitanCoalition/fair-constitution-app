<script setup>
/**
 * ShellV2/DevClockControls — advance the world, on screen. (P2 + P3, plan D2.)
 *
 * WHY THIS EXISTS. The clock backend has been complete for two days —
 * dry-run, bounded-chunk apply, fire-one-timer, audit-marked, refusal-gated
 * — and every door to it was a terminal. `/system/clocks` even RECEIVED the
 * dry-run preview as a prop and never rendered it: the exact
 * "server sends it, screen does not show it" failure the fleet catalogued.
 * This component is the missing screen half.
 *
 * THE CONTRACT THAT MATTERS: the dry run is RENDERED BEFORE apply, always.
 * A ten-year advance on a founded world fires a great deal at once; the
 * operator must see that list first. Apply is a second, deliberate click,
 * and it goes dark the moment the day count changes — you can only apply
 * the plan you were just shown.
 *
 * Where the gate refuses, the server's refusal sentence renders VERBATIM
 * (`DevTimeControlsEnabled::refusalReason()`, carried by
 * GET /dev/playtest/state). The server is the truth; this component never
 * second-guesses it. Where the routes do not exist at all (a production
 * world, a deployed box), the probe 404s and the component renders nothing
 * — a disabled control stays indistinguishable from one never built.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { csrfFetch } from '../../lib/csrf';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    /**
     * Pre-fetched GET /dev/playtest/state payload from a parent (the Demo
     * flyout fetches once for all its panels). Null = fetch our own.
     */
    state: { type: Object, default: null },
});

const emit = defineEmits(['refresh']);

const local = ref(null);
const hidden = ref(false);
const st = computed(() => props.state ?? local.value);

/* ── Demo-mesh time coordination (§3/§4) ───────────────────────────────
 * The server's mesh state rides the same GET /dev/playtest/state read. A
 * FOLLOWER carries a verbatim local-advance refusal — the coordinator's
 * advance replays here on sync, so origination is disabled and that sentence
 * shown, exactly as the server enforces it (a 422 on apply). */
const mesh = computed(() => st.value?.mesh ?? null);
const meshRefusal = computed(() => mesh.value?.local_advance_refusal ?? null);
const coordBusy = ref(false);

async function postCoordinator(body) {
    coordBusy.value = true;
    error.value = '';
    try {
        const r = await csrfFetch('/dev/clock/coordinator', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (r.status === 404) throw new Error('route not available in this build');
        const data = await r.json();
        if (!r.ok) throw new Error(data?.error || `could not set coordinator (${r.status})`);
        refresh();
    } catch (e) {
        error.value = e?.message || t('c_shell_components.dev_clock_controls.err_coordinator', 'Could not update the mesh coordinator.');
    } finally {
        coordBusy.value = false;
    }
}

async function probe() {
    try {
        const r = await fetch('/dev/playtest/state', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: AbortSignal.timeout(15000),
        });
        if (r.status === 404) {
            hidden.value = true;
            return;
        }
        if (!r.ok) throw new Error(`state read failed (${r.status})`);
        local.value = await r.json();
    } catch {
        hidden.value = true;
    }
}

onMounted(() => {
    if (props.state === null) probe();
});

function refresh() {
    if (props.state === null) probe();
    emit('refresh');
}

/* ── Advance N days: preview, then apply THAT preview ─────────────── */
const days = ref(1);
const plan = ref(null);
const planDays = ref(null);
const result = ref(null);
const busy = ref(false);
const error = ref('');

/* The plan on screen is only applicable while it matches the input. */
const applyArmed = computed(() => plan.value !== null && planDays.value === Number(days.value));

watch(days, () => {
    result.value = null;
});

async function postAdvance(apply) {
    busy.value = true;
    error.value = '';
    try {
        const r = await csrfFetch('/dev/clock/advance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(apply ? { days: Number(days.value), apply: true } : { days: Number(days.value) }),
        });
        if (r.status === 404) throw new Error('route not available in this build');
        const data = await r.json();
        if (!r.ok) throw new Error(data?.error || `advance failed (${r.status})`);

        plan.value = data.plan;
        planDays.value = data.plan?.days ?? Number(days.value);

        if (data.applied) {
            result.value = data.result;
            plan.value = null;
            planDays.value = null;
            refresh();
            /* Fresh page props (armed counts, due-now badges) without losing
               this panel's local result display. */
            router.reload({ preserveScroll: true });
        }
    } catch (e) {
        error.value = e?.message || t('c_shell_components.dev_clock_controls.err_clock', 'Could not reach the clock controls.');
    } finally {
        busy.value = false;
    }
}

/* ── Fire one named timer ──────────────────────────────────────────── */
const firing = ref('');
const fireNote = ref('');

async function fireTimer(timer) {
    firing.value = timer.id;
    fireNote.value = '';
    error.value = '';
    try {
        const r = await csrfFetch(`/dev/clock/fire/${timer.id}`, { method: 'POST' });
        if (r.status === 404) throw new Error('route not available in this build');
        const data = await r.json();
        if (!r.ok) throw new Error(data?.error || `fire failed (${r.status})`);
        fireNote.value = data.fired
            ? t('c_shell_components.dev_clock_controls.fired', { clock: data.clock_id })
            : t('c_shell_components.dev_clock_controls.not_armed', { clock: data.clock_id });
        refresh();
        router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e?.message || t('c_shell_components.dev_clock_controls.err_fire', 'Could not fire that timer.');
    } finally {
        firing.value = '';
    }
}

const shiftedRows = computed(() =>
    result.value ? Object.entries(result.value.shifted).filter(([, n]) => n > 0) : []);
</script>

<template>
    <div v-if="!hidden && st" class="clockctl">
        <!-- The gate said no: the server's sentence, verbatim, nothing else. -->
        <p v-if="!st.enabled" class="clockctl-refusal">{{ st.reason }}</p>

        <template v-else>
            <!-- Demo-mesh coordination state (§3/§4). A follower's advance is
                 refused with the coordinator named; the server enforces it, this
                 mirrors it. -->
            <div v-if="mesh" class="clockctl-mesh" :class="`clockctl-mesh--${mesh.role}`">
                <p v-if="mesh.role === 'coordinator'" class="clockctl-mesh-head">
                    {{ t('c_shell_components.dev_clock_controls.coord_before', 'This node') }} <strong>{{ t('c_shell_components.dev_clock_controls.coord_term', 'coordinates') }}</strong>{{ t('c_shell_components.dev_clock_controls.coord_after', { peers: mesh.demo_peers }) }}
                </p>
                <p v-else-if="mesh.role === 'follower'" class="clockctl-mesh-head clockctl-mesh-head--follower">
                    {{ t('c_shell_components.dev_clock_controls.follows_before', 'This node') }} <strong>{{ t('c_shell_components.dev_clock_controls.follows_term', 'follows') }}</strong>{{ t('c_shell_components.dev_clock_controls.follows_after', { label: mesh.coordinator.label }) }}
                    <span v-if="meshRefusal">{{ meshRefusal }}</span>
                </p>
                <p v-else class="clockctl-mesh-head clockctl-dim">
                    {{ t('c_shell_components.dev_clock_controls.solo', 'Solo — no demo peers to coordinate with.') }}
                </p>
                <div class="clockctl-mesh-controls">
                    <button
                        v-if="mesh.role === 'follower'"
                        type="button"
                        class="clockctl-btn clockctl-btn--fire"
                        :disabled="coordBusy"
                        @click="postCoordinator({ self: true })"
                    >{{ t('c_shell_components.dev_clock_controls.make_coordinator', 'Make this node the coordinator') }}</button>
                    <label class="clockctl-mesh-skew">
                        <input
                            type="checkbox"
                            :checked="mesh.skew_tolerated"
                            :disabled="coordBusy"
                            @change="postCoordinator({ skew_tolerated: $event.target.checked })"
                        />
                        {{ t('c_shell_components.dev_clock_controls.tolerate_skew', 'Tolerate skew (advance independently)') }}
                    </label>
                </div>
            </div>

            <div class="clockctl-row">
                <label class="clockctl-label" for="dev-clock-days">{{ t('c_shell_components.dev_clock_controls.advance_world', 'Advance the world') }}</label>
                <div class="clockctl-controls">
                    <input
                        id="dev-clock-days"
                        v-model.number="days"
                        type="number"
                        min="1"
                        max="73000"
                        class="clockctl-input"
                        aria-describedby="dev-clock-status"
                    />
                    <span class="clockctl-unit">{{ t('c_shell_components.dev_clock_controls.day_unit', 'day(s)') }}</span>
                    <button type="button" class="clockctl-btn" :disabled="busy || days < 1" @click="postAdvance(false)">
                        {{ t('c_shell_components.dev_clock_controls.preview', 'Preview what would fire') }}
                    </button>
                </div>
            </div>

            <p id="dev-clock-status" class="clockctl-status" aria-live="polite">
                {{ error || fireNote || '' }}
            </p>

            <!-- THE DRY RUN, rendered before anything moves (P3). -->
            <div v-if="plan" class="clockctl-plan">
                <p class="clockctl-plan-head">
                    {{ t('c_shell_components.dev_clock_controls.plan_in', { days: plan.days }) }} <strong>{{ plan.total_timers }}</strong> {{ t('c_shell_components.dev_clock_controls.plan_timers_due', 'timer(s) come due.') }}
                </p>

                <table v-if="plan.timers.length" class="clockctl-table">
                    <caption class="sr-only">{{ t('c_shell_components.dev_clock_controls.plan_caption', 'Timers that would come due, grouped by clock and place') }}</caption>
                    <thead>
                        <tr><th>{{ t('c_shell_components.dev_clock_controls.col_clock', 'Clock') }}</th><th>{{ t('c_shell_components.dev_clock_controls.col_place', 'Place') }}</th><th>{{ t('c_shell_components.dev_clock_controls.col_due', 'Due') }}</th><th>{{ t('c_shell_components.dev_clock_controls.col_window', 'Window') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="tm in plan.timers" :key="tm.clock_id + (tm.jurisdiction_id || '')">
                            <td>{{ tm.clock_id }}</td>
                            <td>{{ tm.jurisdiction_name || t('c_shell_components.dev_clock_controls.all_places', 'all places') }}</td>
                            <td>{{ tm.due }}</td>
                            <td class="clockctl-dim">{{ tm.earliest }} → {{ tm.latest }}</td>
                        </tr>
                    </tbody>
                </table>

                <details class="clockctl-cols">
                    <summary>{{ t('c_shell_components.dev_clock_controls.cols_summary', 'Deadline columns that would move') }}</summary>
                    <table class="clockctl-table">
                        <tbody>
                            <tr v-for="c in plan.columns" :key="c.table + c.column">
                                <td><code>{{ c.table }}.{{ c.column }}</code></td>
                                <td>{{ t('c_shell_components.dev_clock_controls.n_rows', { count: c.rows }) }}</td>
                                <td class="clockctl-dim">{{ c.why }}</td>
                            </tr>
                        </tbody>
                    </table>
                </details>

                <button
                    type="button"
                    class="clockctl-btn clockctl-btn--apply"
                    :disabled="busy || !applyArmed || !!meshRefusal"
                    @click="postAdvance(true)"
                >
                    {{ t('c_shell_components.dev_clock_controls.apply_btn', { days: planDays }) }}
                </button>
                <p v-if="meshRefusal" class="clockctl-dim clockctl-note">
                    {{ t('c_shell_components.dev_clock_controls.advance_coordinator', 'Advance on the coordinator — it replays here on sync.') }}
                </p>
                <p v-else-if="!applyArmed" class="clockctl-dim clockctl-note">
                    {{ t('c_shell_components.dev_clock_controls.preview_again', 'The day count changed — preview again before applying.') }}
                </p>
            </div>

            <!-- What actually happened, off the server's own response. -->
            <div v-if="result" class="clockctl-result" aria-live="polite">
                <p class="clockctl-plan-head">
                    {{ t('c_shell_components.dev_clock_controls.advanced_in', { days: result.days }) }} <strong>{{ result.fired }}</strong> {{ t('c_shell_components.dev_clock_controls.timers_fired', 'timer(s) fired') }}<span v-if="result.failed">{{ t('c_shell_components.dev_clock_controls.refused_by', { count: result.failed }) }}</span>.
                </p>
                <ul v-if="shiftedRows.length" class="clockctl-shifted">
                    <li v-for="[key, n] in shiftedRows" :key="key"><code>{{ key }}</code> — {{ t('c_shell_components.dev_clock_controls.rows_moved', { count: n }) }}</li>
                </ul>
            </div>

            <!-- Fire one timer, from the same list dev:clock-fire prints. -->
            <details class="clockctl-armed">
                <summary>{{ t('c_shell_components.dev_clock_controls.fire_one', { count: st.armed.length }) }}{{ st.armed.length === 50 ? t('c_shell_components.dev_clock_controls.soonest_50', ', soonest 50 shown') : '' }})</summary>
                <p v-if="!st.armed.length" class="clockctl-dim clockctl-note">{{ t('c_shell_components.dev_clock_controls.nothing_armed', 'Nothing is armed. Nothing is waiting to happen.') }}</p>
                <ul v-else class="clockctl-armed-list">
                    <li v-for="tm in st.armed" :key="tm.id" class="clockctl-armed-row">
                        <span class="clockctl-armed-what">
                            <strong>{{ tm.clock_id }}</strong>
                            <span class="clockctl-dim"> · {{ tm.jurisdiction || t('c_shell_components.dev_clock_controls.all_places', 'all places') }}<template v-if="tm.subject_type"> · {{ tm.subject_type }}</template></span>
                            <span class="clockctl-dim clockctl-when">{{ tm.fires_at }}</span>
                        </span>
                        <button
                            type="button"
                            class="clockctl-btn clockctl-btn--fire"
                            :disabled="firing !== ''"
                            @click="fireTimer(tm)"
                        >
                            {{ firing === tm.id ? t('c_shell_components.dev_clock_controls.firing', 'Firing…') : t('c_shell_components.dev_clock_controls.fire_now', 'Fire now') }}
                        </button>
                    </li>
                </ul>
            </details>
        </template>
    </div>
</template>

<style scoped>
.clockctl {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 16rem;
    max-width: 34rem;
    font-size: 0.8125rem;
}
.clockctl-refusal {
    margin: 0;
    padding: var(--space-2);
    border: 1px solid var(--gov-border, currentColor);
    border-radius: var(--radius-2, 0.375rem);
    opacity: 0.9;
}
.clockctl-mesh {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-2);
    border: 1px solid var(--gov-border, currentColor);
    border-radius: var(--radius-2, 0.375rem);
}
.clockctl-mesh-head {
    margin: 0;
}
.clockctl-mesh-head--follower {
    opacity: 0.95;
}
.clockctl-mesh-controls {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    flex-wrap: wrap;
}
.clockctl-mesh-skew {
    display: flex;
    align-items: center;
    gap: var(--space-1, 0.25rem);
    font-size: 0.75rem;
    cursor: pointer;
}
.clockctl-row {
    display: flex;
    flex-direction: column;
    gap: var(--space-1, 0.25rem);
}
.clockctl-label {
    font-weight: 600;
}
.clockctl-controls {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
}
.clockctl-input,
.clockctl-btn {
    min-height: 44px; /* WCAG 2.2 AA target size — this runs on a phone during the walk */
    font: inherit;
    border-radius: var(--radius-2, 0.375rem);
    border: 1px solid var(--gov-border, currentColor);
    background: transparent;
    color: inherit;
}
.clockctl-input {
    width: 6.5rem;
    padding-inline: var(--space-2);
}
.clockctl-btn {
    padding-inline: var(--space-3);
    cursor: pointer;
}
.clockctl-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.clockctl-btn--apply {
    font-weight: 600;
    border-width: 2px;
}
.clockctl-btn--fire {
    min-height: 36px;
    font-size: 0.75rem;
    flex: none;
}
.clockctl-status {
    margin: 0;
    min-height: 1em;
    font-size: 0.75rem;
    opacity: 0.85;
}
.clockctl-plan,
.clockctl-result {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-2);
    border: 1px solid var(--gov-border, currentColor);
    border-radius: var(--radius-2, 0.375rem);
}
.clockctl-plan-head {
    margin: 0;
}
.clockctl-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
}
.clockctl-table th,
.clockctl-table td {
    text-align: start;
    padding: 0.25rem 0.5rem 0.25rem 0;
    vertical-align: top;
}
.clockctl-dim {
    opacity: 0.75;
}
.clockctl-note {
    margin: 0;
    font-size: 0.75rem;
}
.clockctl-cols summary,
.clockctl-armed summary {
    cursor: pointer;
    min-height: 44px;
    display: flex;
    align-items: center;
}
.clockctl-shifted {
    margin: 0;
    padding-inline-start: 1.25rem;
    font-size: 0.75rem;
}
.clockctl-armed-list {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 16rem;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: var(--space-1, 0.25rem);
}
.clockctl-armed-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    padding: 0.25rem 0;
}
.clockctl-armed-what {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.clockctl-when {
    font-size: 0.6875rem;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
