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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DevClockControls from '@/Components/ShellV2/DevClockControls.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    clocks: { type: Array, default: () => [] },
    /** Per clock id: { count, next_fires_at } for ARMED timers. */
    armed: { type: Object, default: () => ({}) },
    /**
     * Per clock id: how many ARMED timers are already past their deadline.
     * Normally absent or zero — the minute sweep fires them. A number that
     * persists here means the sweep is not running or a handler keeps
     * refusing, which is worth seeing on the page rather than discovering
     * later.
     */
    dueNow: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({ total: 0, amendable: 0, hardened: 0 }) },
    /**
     * P3 (DEV_TIME_AND_ROLE_CONTROLS.md): non-null ONLY when the playtest
     * gate allows time controls on this world (DevTimeControlsEnabled said
     * yes server-side). The controller sent this prop for two days while
     * nothing rendered it — the exact "server sends it, the screen does not
     * show it" defect the fleet catalogued. Now it gates the advance panel
     * below; the page stays read-only for everyone else.
     */
    playtest: { type: Object, default: null },
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

const FAMILIES = computed(() => [
    { name: FAMILY_INTERVALS, label: t('c_system.clocks.family_intervals', 'Intervals & schedules'), desc: t('c_system.clocks.family_intervals_desc', 'Recurring and derived schedules — the clocks that re-arm themselves.') },
    { name: FAMILY_DEADLINES, label: t('c_system.clocks.family_deadlines', 'Deadlines & windows'), desc: t('c_system.clocks.family_deadlines_desc', 'Countdowns, rolling deadlines, and bounded or continuous windows.') },
    { name: FAMILY_THRESHOLDS, label: t('c_system.clocks.family_thresholds', 'Thresholds & floors'), desc: t('c_system.clocks.family_thresholds_desc', 'Quantity watchers — population, headcount, signatures, seats.') },
    { name: FAMILY_FORMULAS, label: t('c_system.clocks.family_formulas', 'Formulas & flags'), desc: t('c_system.clocks.family_formulas_desc', 'Derived values and term-scoped protections.') },
]);

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
    FAMILIES.value.map((fam) => ({
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

/**
 * Overdue armed timers for a clock, or 0.
 *
 * Rendered ONLY when non-zero, deliberately: a column of zeros is noise, and
 * the one number that matters is the one that should not be there. It is
 * called "overdue" rather than "due" on screen because "3 due" reads as
 * scheduled work, while "3 overdue" reads as something that should already
 * have happened — which is exactly what it is.
 */
function overdueOf(clock) {
    return Number(props.dueNow?.[clock.id] ?? 0);
}

function dateOf(iso) {
    return iso ? new Date(iso).toLocaleDateString() : null;
}

const columns = computed(() => [
    { key: 'name', label: t('c_system.clocks.col_name', 'Name') },
    { key: 'type', label: t('c_system.clocks.col_type', 'Type') },
    { key: 'default', label: t('c_system.clocks.col_default', 'Default') },
    { key: 'amendable', label: t('c_system.clocks.col_amendable', 'Amendable') },
    { key: 'live', label: t('c_system.clocks.col_live', 'Live') },
    { key: 'basis', label: t('c_system.clocks.col_basis', 'Basis'), mono: true },
]);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_system.clocks.intro', 'The scheduled sweeps that drive the world — every interval, deadline, window, and threshold that starts a process without anyone asking. Time and population do the triggering; officials never do, and nothing here needs a human to remember it.') }}
        </template>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="stats.total" :label="t('c_system.clocks.stat_total', 'clocks in the registry')" accent />
            <Stat :value="stats.amendable" :label="t('c_system.clocks.stat_amendable', 'amendable via settings')" />
            <Stat :value="stats.hardened" :label="t('c_system.clocks.stat_hardened', 'hardened or structural')" />
        </div>

        <!-- ============================= playtest time controls (P2+P3) ==
             Rendered ONLY when the server said the gate allows (sandbox
             world, dev toolbox on, no non-demo peer). Everyone else sees
             the read-only registry and nothing more. The component shows
             the dry run BEFORE apply, always, and renders the gate's
             refusal sentence verbatim if the controls shut mid-session. -->
        <Card v-if="playtest" as="section" :title="t('c_system.clocks.playtest_title', 'Advance the world (playtest control)')">
            <p class="cc-small">
                {{ t('c_system.clocks.playtest_body', 'Pulls every registered deadline closer instead of touching the wall clock — audit-marked as a dev action, dry run rendered before anything moves.') }}
            </p>
            <DevClockControls />
        </Card>

        <!-- ==================================== the four families ======== -->
        <Card v-for="fam in families" :key="fam.name" as="section">
            <template #title>
                <h2>{{ fam.label }} <span class="citation">{{ t('c_system.clocks.family_count', '{n} clocks', { n: fam.rows.length }) }}</span></h2>
            </template>
            <p class="cc-small">{{ fam.desc }}</p>
            <DataTable :columns="columns" :rows="fam.rows" row-key="id" :caption="fam.label">
                <template #cell-name="{ row }">
                    <span style="color: var(--gov-fg-strong)">{{ t('c_system.clocks.' + row.id, row.name) }}</span>
                    <span class="citation" style="display: block" data-no-i18n>
                        {{ row.id }} · fires {{ row.fires_workflow }}
                    </span>
                </template>
                <template #cell-default="{ row }">
                    <template v-if="defaultOf(row)">{{ defaultOf(row) }}</template>
                    <span v-else class="citation">{{ t('c_system.clocks.default_derived', 'derived — see the workflow it fires') }}</span>
                </template>
                <template #cell-amendable="{ row }">
                    <StatusBadge v-if="row.amendable" tone="info" icon="sliders">{{ t('c_system.clocks.amendable_yes', 'Amendable') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="lock">{{ t('c_system.clocks.amendable_no', 'No — hardened') }}</StatusBadge>
                </template>
                <template #cell-live="{ row }">
                    <template v-if="liveOf(row)">
                        {{ t('c_system.clocks.armed_count', '{n} armed', { n: liveOf(row).count }) }}
                        <span
                            v-if="liveOf(row).next_fires_at"
                            class="citation"
                            style="display: block"
                            data-no-i18n
                        >next {{ dateOf(liveOf(row).next_fires_at) }}</span>
                        <!-- Only ever shown when non-zero. A timer past its
                             deadline that is still armed means the sweep did
                             not fire it — a fault, not a status. -->
                        <StatusBadge v-if="overdueOf(row)" tone="warning" icon="alert-triangle">
                            {{ t('c_system.clocks.overdue_count', '{n} overdue', { n: overdueOf(row) }) }}
                        </StatusBadge>
                    </template>
                    <template v-else>—</template>
                </template>
                <template #cell-basis="{ row }">
                    <span data-no-i18n>{{ row.basis }}</span>
                </template>
            </DataTable>
        </Card>

        <!-- ==================================== reading the registry ===== -->
        <Card as="section" :title="t('c_system.clocks.reading_title', 'Reading the registry')">
            <ul style="margin-block-end: var(--space-2)">
                <li>
                    <strong>{{ t('c_system.clocks.reading_type_label', 'Type') }}</strong> {{ t('c_system.clocks.reading_type_body', 'is the scheduler contract: recurring intervals re-arm on fire; countdowns expire once; windows open and close; thresholds watch a quantity and fire on crossing.') }}
                </li>
                <li>
                    <strong>{{ t('c_system.clocks.reading_amendable_label', 'Amendable') }}</strong> {{ t('c_system.clocks.reading_amendable_body', 'means a valid legislative act can change the default within fixed bounds — see') }} <Link href="/system/amendments">{{ t('c_system.clocks.amendments_link', 'amendments') }}</Link>{{ t('c_system.clocks.reading_amendable_body2', '; hardened and structural clocks are fixed in code.') }}
                </li>
                <li>
                    <strong>{{ t('c_system.clocks.reading_live_label', 'Live') }}</strong> {{ t('c_system.clocks.reading_live_body', 'is what the scheduler is actually holding right now — armed timers and the soonest real deadline, straight from the timer table.') }}
                </li>
                <li>
                    {{ t('c_system.clocks.reading_fire_body', 'Every fire event is appended to the') }}
                    <Link href="/system/audit-chain">{{ t('c_system.clocks.audit_chain_link', 'audit chain') }}</Link>.
                </li>
            </ul>
            <p class="gloss">
                {{ t('c_system.clocks.glossary', 'Glossary: quorum here always counts all serving members, never just those present; the Droop quota is the smallest vote count that mathematically guarantees a seat; the number of ballot finalists grows with the seats in the race.') }}
            </p>
        </Card>

        <template #about>
            <p>
                {{ t('c_system.clocks.about', 'The scheduler itself is the engine behind term synchronization and 90-day meeting enforcement. This page doubles as the scheduler spec — the production scheduler implements exactly these clock records, one row, one trigger source. Amendable defaults change by legislative act; hardened and structural clocks are fixed in code. Clocks themselves hold no state — they move other things (elections, emergency powers, vacancies, residency claims) through their stages.') }}
            </p>
        </template>
    </PageScaffold>
</template>
