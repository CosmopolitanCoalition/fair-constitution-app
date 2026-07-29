<script setup>
/**
 * ShellV2/DevPlaytestPanels — lane 4's occupants of the Demo flyout.
 * (V3 synthesis §3: D1 doors + D2 clock controls + D3 chamber cast.)
 *
 * One component so the chassis (DemoFlyout.vue, lane 6's file) carries a
 * single mount line at its marked region — the seam stays one line wide in
 * a file another lane owns. Everything behind it is lane 4's.
 *
 * ONE state read feeds both panels (GET /dev/playtest/state): whether the
 * playtest time/role controls may run — with the server's refusal sentence
 * VERBATIM when not (the server is the truth, the UI never second-guesses
 * it) — plus the soonest armed timers and the open chamber votes. A 404
 * means the routes do not exist on this build; the Time and Chamber blocks
 * render nothing, matching the gate's own posture that a disabled control
 * is indistinguishable from an unbuilt one. The doors render regardless —
 * they lead to pages that carry their own gates.
 *
 * All of Slice 2 lives here now: D4 (assume a resident/role of a place),
 * D2 (clock controls), D3 (chamber cast), D5 (scenario presets — its own
 * poll, so it mounts prop-less), plus the D1 doors.
 */
import { onMounted, ref } from 'vue';
import DevAssume from './DevAssume.vue';
import DevClockControls from './DevClockControls.vue';
import DevChamberCast from './DevChamberCast.vue';
import DevScenarioPresets from './DevScenarioPresets.vue';

const state = ref(null);

async function fetchState() {
    try {
        const r = await fetch('/dev/playtest/state', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: AbortSignal.timeout(15000),
        });
        if (!r.ok) return; // 404 = not on this build; the blocks stay absent
        state.value = await r.json();
    } catch {
        /* unreachable = absent, same as the gate's 404 posture */
    }
}

onMounted(fetchState);

/* The flyout's other doors (D1): each page carries its own server gate. */
const DOORS = [
    { href: '/simworld', label: 'Simulated world' },
    { href: '/system/clocks', label: 'Constitutional clocks' },
    { href: '/dev/electoral-kit', label: 'Electoral kit' },
    { href: '/dev/legislature-kit', label: 'Legislature kit' },
    { href: '/dev/executive-kit', label: 'Executive & orgs kit' },
    { href: '/dev/judiciary-kit', label: 'Judiciary kit' },
];
</script>

<template>
    <details v-if="state" class="dev-control playtest-block">
        <summary>Assume — a resident or role of a place</summary>
        <div class="playtest-body">
            <DevAssume :state="state" />
        </div>
    </details>

    <details v-if="state" class="dev-control playtest-block">
        <summary>Time — advance the world, fire a timer</summary>
        <div class="playtest-body">
            <DevClockControls :state="state" @refresh="fetchState" />
        </div>
    </details>

    <details v-if="state" class="dev-control playtest-block">
        <summary>Chamber — bloc-cast an open vote (ballots only)</summary>
        <div class="playtest-body">
            <DevChamberCast :state="state" @refresh="fetchState" />
        </div>
    </details>

    <details v-if="state" class="dev-control playtest-block">
        <summary>Scenarios — seed a named situation (the real seeders)</summary>
        <div class="playtest-body">
            <DevScenarioPresets />
        </div>
    </details>

    <a v-for="d in DOORS" :key="d.href" class="dev-control" :href="d.href">
        {{ d.label }} → {{ d.href }}
    </a>
</template>

<style scoped>
.playtest-block {
    flex: 1 1 100%;
}
.playtest-block > summary {
    cursor: pointer;
    min-height: 44px; /* WCAG 2.2 AA target size */
    display: flex;
    align-items: center;
    font-weight: 600;
}
.playtest-body {
    padding: var(--space-2) 0;
}
</style>
