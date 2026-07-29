<script setup>
/**
 * ShellV2/DevScenarioPresets — the mockups' named situations, seeded for
 * real. (Plan D5; ruling 10 BUILD; desk green-lit full async 2026-07-28.)
 *
 * Every button queues the EXACT artisan command a terminal would run —
 * nothing more. The seeder files its own records through the engine and
 * prints its own honest progress (including refusals); this panel just
 * shows that output live, on the 2-second poll idiom the sim console
 * established. One run at a time — the seeders share the world.
 *
 * HONESTY RAILS, both directions:
 *   · a preset whose precondition fails renders WHY, disabled — the
 *     dependency ladder teaching itself ("needs a seated legislature —
 *     run the election preset first");
 *   · the mockup scenario flags NO seeder can light render as plain
 *     absence with the reason — never a button that fakes it.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { csrfFetch } from '../../lib/csrf';

const state = ref(null);
const busy = ref('');
const error = ref('');
let timer = null;

const anyRunning = computed(() =>
    state.value?.running != null
    || (state.value?.presets ?? []).some((p) => ['queued', 'running'].includes(p.run?.status)));

async function poll() {
    try {
        const r = await fetch('/dev/scenario/state', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: AbortSignal.timeout(15000),
        });
        if (!r.ok) return; // 404 = not on this build; the panel stays absent
        state.value = await r.json();
    } catch {
        /* keep the last state; the next tick may recover */
    }

    // The sim-console cadence: 2s while something runs, lazy otherwise.
    clearTimeout(timer);
    timer = setTimeout(poll, anyRunning.value ? 2000 : 15000);
}

onMounted(poll);
onBeforeUnmount(() => clearTimeout(timer));

async function run(preset) {
    busy.value = preset.id;
    error.value = '';
    try {
        const r = await csrfFetch(`/dev/scenario/${preset.id}`, { method: 'POST' });
        if (r.status === 404) throw new Error('route not available in this build');
        const data = await r.json();
        if (!r.ok) throw new Error(data?.error || `queue failed (${r.status})`);
        await poll();
    } catch (e) {
        error.value = e?.message || 'Could not queue the scenario.';
    } finally {
        busy.value = '';
    }
}

function runBadge(p) {
    if (!p.run) return null;
    if (p.run.status === 'queued') return 'queued…';
    if (p.run.status === 'running') return 'running…';
    if (p.run.status === 'done') return 'last run: done';
    return 'last run: failed';
}
</script>

<template>
    <div v-if="state" class="scen">
        <!-- The gate said no: the server's sentence, verbatim, nothing else. -->
        <p v-if="!state.enabled" class="scen-refusal">{{ state.reason }}</p>

        <template v-else>
            <p class="scen-note scen-dim">
                Each button queues the real demo seeder — the same command a terminal runs,
                one at a time. The seeder's own output streams below.
            </p>

            <p class="scen-status" aria-live="polite">{{ error }}</p>

            <ul class="scen-list">
                <li v-for="p in state.presets" :key="p.id" class="scen-row">
                    <div class="scen-row-head">
                        <div class="scen-what">
                            <span class="scen-label">{{ p.label }}</span>
                            <span class="scen-dim scen-note">
                                <code>{{ p.command }}</code>
                                <template v-if="p.lights.length"> · lights {{ p.lights.join(', ') }}</template>
                                <template v-if="runBadge(p)"> · {{ runBadge(p) }}</template>
                            </span>
                        </div>
                        <button
                            type="button"
                            class="scen-btn"
                            :disabled="!p.available || busy !== '' || anyRunning"
                            @click="run(p)"
                        >
                            {{ busy === p.id ? 'Queueing…' : 'Seed it' }}
                        </button>
                    </div>

                    <!-- The teaching sentence: why this rung is not reachable yet. -->
                    <p v-if="!p.available" class="scen-dim scen-note">{{ p.blocked_reason }}</p>

                    <!-- The seeder's own live output — its progress AND its refusals. -->
                    <details v-if="p.run?.tail" class="scen-tail">
                        <summary>{{ ['queued', 'running'].includes(p.run.status) ? 'Live output' : 'Run output' }}</summary>
                        <pre class="scen-pre" data-no-i18n>{{ p.run.tail }}</pre>
                    </details>
                </li>
            </ul>

            <details class="scen-unbacked">
                <summary>Scenario flags with no seeder yet ({{ Object.keys(state.unbacked).length }})</summary>
                <ul class="scen-unbacked-list">
                    <li v-for="(why, flag) in state.unbacked" :key="flag" class="scen-dim scen-note">
                        <code>{{ flag }}</code> — {{ why }}
                    </li>
                </ul>
            </details>
        </template>
    </div>
</template>

<style scoped>
.scen {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 16rem;
    max-width: 34rem;
    font-size: 0.8125rem;
}
.scen-refusal {
    margin: 0;
    padding: var(--space-2);
    border: 1px solid var(--gov-border, currentColor);
    border-radius: var(--radius-2, 0.375rem);
    opacity: 0.9;
}
.scen-note {
    margin: 0;
    font-size: 0.75rem;
}
.scen-dim {
    opacity: 0.75;
}
.scen-status {
    margin: 0;
    min-height: 1em;
    font-size: 0.75rem;
    opacity: 0.85;
}
.scen-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}
.scen-row {
    display: flex;
    flex-direction: column;
    gap: var(--space-1, 0.25rem);
    padding-block-end: var(--space-2);
    border-block-end: 1px solid color-mix(in srgb, currentColor 15%, transparent);
}
.scen-row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
}
.scen-what {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    min-width: 0;
}
.scen-label {
    font-weight: 600;
}
.scen-btn {
    min-height: 44px; /* WCAG 2.2 AA target size */
    font: inherit;
    border-radius: var(--radius-2, 0.375rem);
    border: 1px solid var(--gov-border, currentColor);
    background: transparent;
    color: inherit;
    padding-inline: var(--space-3);
    cursor: pointer;
    flex: none;
}
.scen-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.scen-tail summary,
.scen-unbacked summary {
    cursor: pointer;
    min-height: 36px;
    display: flex;
    align-items: center;
    font-size: 0.75rem;
}
.scen-pre {
    margin: 0;
    max-height: 14rem;
    overflow: auto;
    font-size: 0.6875rem;
    white-space: pre-wrap;
    word-break: break-word;
    padding: var(--space-2);
    border: 1px solid color-mix(in srgb, currentColor 15%, transparent);
    border-radius: var(--radius-2, 0.375rem);
}
.scen-unbacked-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}
</style>
