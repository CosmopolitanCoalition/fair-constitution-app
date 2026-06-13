<script setup>
/**
 * Judiciary/PanelTable — the conflict-screened bench seated to one case
 * (FE-E1; PHASE_E_DESIGN_frontend.md §A.1). Renders the panel roster with
 * screening results from `panels` + `panel_judges` rows.
 *
 * CONSTITUTIONAL POSTURE — pure renderer: `panelSize` and `isFullCourt`
 * are ENGINE snapshots from the `panels` row (size, is_en_banc), computed
 * by the hardened App\Services\Judiciary\PanelSizing (CLK-16: panels ≥3,
 * odd, severity-scaled; full court for major constitutional questions ·
 * Art. IV §4). The component NEVER derives a panel size from severity —
 * the same posture VoteTally's required_yes and CoDetScale's worker_seats
 * enforce. Feed `panelSize=3` against a "major" severity and it honestly
 * displays 3.
 *
 * Markup grammar from mockups/judiciary/case-detail.html stage 3 +
 * judiciary-home.html severity gloss (line 44). Classes: .hardened,
 * .table, .badge--*, .citation — all already ported, no new CSS.
 */
import { computed } from 'vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    /**
     * panel_judges rows (server-shaped):
     * [{ judge:{name, href?}, is_presiding:bool,
     *    screening:'no_conflicts'|'recused'|'excluded',
     *    screening_reason:string|null,
     *    result:'seated'|'recused'|'excluded' }]
     */
    seats: { type: Array, required: true },
    /** 'minor'|'moderate'|'serious'|'constitutional_major' — court classification. */
    severity: { type: String, required: true },
    /** ENGINE output (panels.size, CLK-16 rule) — never client-computed. */
    panelSize: { type: Number, required: true },
    /** ENGINE output (panels.is_en_banc) — major constitutional question → all judges. */
    isFullCourt: { type: Boolean, default: false },
    /** Server citation, e.g. "≥3, odd, severity-scaled · CLK-16 · Art. IV §4". */
    rule: { type: String, default: null },
});

const GLOSS =
    'Severity scaling: the heavier the possible consequence, the more judges must hear it — ' +
    'the panel is always odd so no case can deadlock.';

const headline = computed(() =>
    props.isFullCourt
        ? `Full court — all ${props.panelSize} judges`
        : `Panel of ${props.panelSize} — odd, severity-scaled`,
);

const RESULT_TONES = { seated: 'success', recused: 'neutral', excluded: 'neutral' };
const RESULT_ICONS = { seated: 'check', recused: 'x', excluded: 'x' };
const RESULT_LABELS = { seated: 'Seated', recused: 'Recused', excluded: 'Excluded' };

const columns = [
    { key: 'judge', label: 'Judge' },
    { key: 'screening', label: 'Screening' },
    { key: 'result', label: 'Result' },
];
</script>

<template>
    <div class="stack" style="gap: var(--space-3)">
        <div class="cluster">
            <HardenedChip />
            <StatusBadge tone="info" icon="users">{{ headline }}</StatusBadge>
        </div>

        <DataTable :columns="columns" :rows="seats" caption="Panel assignment and conflict screening results">
            <template #cell-judge="{ row }">
                <a v-if="row.judge?.href" :href="row.judge.href">{{ row.judge?.name }}</a>
                <template v-else>{{ row.judge?.name }}</template>
                <span v-if="row.is_presiding" class="citation" data-no-i18n> · presiding</span>
            </template>
            <template #cell-screening="{ row }">
                {{ row.screening === 'no_conflicts' ? 'No conflicts declared or found' : row.screening_reason || '—' }}
            </template>
            <template #cell-result="{ row }">
                <StatusBadge :tone="RESULT_TONES[row.result] ?? 'neutral'" :icon="RESULT_ICONS[row.result] ?? 'x'">
                    {{ RESULT_LABELS[row.result] ?? row.result }}
                </StatusBadge>
                <span v-if="row.result !== 'seated' && row.screening_reason" class="citation" style="display: block">
                    {{ row.screening_reason }}
                </span>
            </template>
        </DataTable>

        <p class="gloss">{{ GLOSS }}</p>
        <p v-if="rule" class="citation" data-no-i18n>{{ rule }}</p>
    </div>
</template>
