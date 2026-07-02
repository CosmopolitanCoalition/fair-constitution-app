<script setup>
/**
 * System/Clocks — mockups-v3-wiring Phase 2 (design contract:
 * mockups/v3/shared/clocks.html).
 *
 * READ-ONLY BY DESIGN — the full clocks registry (the scheduler spec:
 * 21 canonical records, one row, one trigger source), grouped into the
 * mockup's four families, with a LIVE column straight off clock_timers
 * (armed count + the soonest real fires_at, never a recomputed date).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    clocks: { type: Array, default: () => [] },
    /** Per clock id: { count, next_fires_at } for ARMED timers. */
    armed: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({ total: 0, amendable: 0, hardened: 0 }) },
});

/*
 * The mockup's four families as a simple type→family map. `derived`
 * splits on the registry's own unit: CLK-10 (derived_schedule) is a
 * schedule; derived formulas (CLK-21) sit with the flags.
 */
const FAMILY_INTERVALS = 'Intervals & schedules';
const FAMILY_DEADLINES = 'Deadlines & windows';
const FAMILY_THRESHOLDS = 'Thresholds & floors';
const FAMILY_FORMULAS = 'Formulas & flags';

const FAMILIES = [
    { name: FAMILY_INTERVALS, desc: 'Recurring and derived schedules — the clocks that re-arm themselves.' },
    { name: FAMILY_DEADLINES, desc: 'Countdowns, rolling deadlines, and bounded or continuous windows.' },
    { name: FAMILY_THRESHOLDS, desc: 'Quantity watchers — population, headcount, signatures, seats.' },
    { name: FAMILY_FORMULAS, desc: 'Derived values and term-scoped protections.' },
];

function familyOf(clock) {
    switch (clock.type) {
        case 'recurring':
            return FAMILY_INTERVALS;
        case 'derived':
            return clock.default_value?.unit === 'derived_schedule' ? FAMILY_INTERVALS : FAMILY_FORMULAS;
        case 'countdown':
        case 'window':
            return FAMILY_DEADLINES;
        case 'threshold':
            return FAMILY_THRESHOLDS;
        case 'flag':
        default:
            return FAMILY_FORMULAS;
    }
}

const families = computed(() =>
    FAMILIES.map((fam) => ({
        ...fam,
        rows: props.clocks.filter((clock) => familyOf(clock) === fam.name),
    })).filter((fam) => fam.rows.length > 0),
);

/** Render default_value as "{value} {unit}"; null value = derived/per-case. */
function defaultOf(clock) {
    const dv = clock.default_value ?? {};
    const unit = String(dv.unit ?? '').replaceAll('_', ' ');
    if (dv.value === null || dv.value === undefined) return null;
    if (typeof dv.value === 'object') {
        // CLK-04 carries a {min_days, max_days} window.
        if (dv.value.min_days !== undefined && dv.value.max_days !== undefined) {
            return `${dv.value.min_days}–${dv.value.max_days} ${unit}`.trim();
        }
        return JSON.stringify(dv.value);
    }
    return `${dv.value} ${unit}`.trim();
}

function liveOf(clock) {
    return props.armed?.[clock.id] ?? null;
}

function dateOf(iso) {
    return iso ? new Date(iso).toLocaleDateString() : null;
}

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Type' },
    { key: 'default', label: 'Default' },
    { key: 'amendable', label: 'Amendable' },
    { key: 'live', label: 'Live' },
    { key: 'basis', label: 'Basis', mono: true },
];
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            The scheduled sweeps that drive the world — every interval, deadline, window, and
            threshold that starts a process without anyone asking. Time and population do the
            triggering; officials never do, and nothing here needs a human to remember it.
        </template>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="stats.total" label="clocks in the registry" accent />
            <Stat :value="stats.amendable" label="amendable via settings" />
            <Stat :value="stats.hardened" label="hardened or structural" />
        </div>

        <!-- ==================================== the four families ======== -->
        <Card v-for="fam in families" :key="fam.name" as="section">
            <template #title>
                <h2>{{ fam.name }} <span class="citation">{{ fam.rows.length }} clocks</span></h2>
            </template>
            <p class="cc-small">{{ fam.desc }}</p>
            <DataTable :columns="columns" :rows="fam.rows" row-key="id" :caption="fam.name">
                <template #cell-name="{ row }">
                    <span style="color: var(--gov-fg-strong)">{{ row.name }}</span>
                    <span class="citation" style="display: block" data-no-i18n>
                        {{ row.id }} · fires {{ row.fires_workflow }}
                    </span>
                </template>
                <template #cell-default="{ row }">
                    <template v-if="defaultOf(row)">{{ defaultOf(row) }}</template>
                    <span v-else class="citation">derived — see the workflow it fires</span>
                </template>
                <template #cell-amendable="{ row }">
                    <StatusBadge v-if="row.amendable" tone="info" icon="sliders">Amendable</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="lock">No — hardened</StatusBadge>
                </template>
                <template #cell-live="{ row }">
                    <template v-if="liveOf(row)">
                        {{ liveOf(row).count }} armed
                        <span
                            v-if="liveOf(row).next_fires_at"
                            class="citation"
                            style="display: block"
                            data-no-i18n
                        >next {{ dateOf(liveOf(row).next_fires_at) }}</span>
                    </template>
                    <template v-else>—</template>
                </template>
                <template #cell-basis="{ row }">
                    <span data-no-i18n>{{ row.basis }}</span>
                </template>
            </DataTable>
        </Card>

        <!-- ==================================== reading the registry ===== -->
        <Card as="section" title="Reading the registry">
            <ul style="margin-block-end: var(--space-2)">
                <li>
                    <strong>Type</strong> is the scheduler contract: recurring intervals re-arm on
                    fire; countdowns expire once; windows open and close; thresholds watch a
                    quantity and fire on crossing.
                </li>
                <li>
                    <strong>Amendable</strong> means a valid legislative act can change the default
                    within fixed bounds — see <Link href="/system/amendments">amendments</Link>;
                    hardened and structural clocks are fixed in code.
                </li>
                <li>
                    <strong>Live</strong> is what the scheduler is actually holding right now —
                    armed timers and the soonest real deadline, straight from the timer table.
                </li>
                <li>
                    Every fire event is appended to the
                    <Link href="/system/audit-chain">audit chain</Link>.
                </li>
            </ul>
            <p class="gloss">
                Glossary: quorum here always counts all serving members, never just those present;
                the Droop quota is the smallest vote count that mathematically guarantees a seat;
                the number of ballot finalists grows with the seats in the race.
            </p>
        </Card>

        <template #about>
            <p>
                The scheduler itself is the engine behind term synchronization and 90-day meeting
                enforcement. This page doubles as the scheduler spec — the production scheduler
                implements exactly these clock records, one row, one trigger source. Amendable
                defaults change by legislative act; hardened and structural clocks are fixed in
                code. Clocks themselves hold no state — they move other things (elections,
                emergency powers, vacancies, residency claims) through their stages.
            </p>
        </template>
    </PageScaffold>
</template>
