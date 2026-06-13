<script setup>
/**
 * Executive/DepartmentCard — department org-chart card (FE-D1;
 * PHASE_D_DESIGN_frontend.md §A.5). The Departments page grid cell and
 * the Executive/Home departments summary.
 *
 * Co-determination cell — the registry logic verbatim
 * (org-registry/departments contract; departments.html coDetCell()):
 * parity → success / scaling → "{n} worker seat(s) · scaling" info +
 * CLK-13 citation / else "below threshold" neutral. The STATE derives
 * from ENGINE OUTPUTS ONLY (boards.worker_seats vs owner_seats — parity
 * means worker seats equal owner seats); the CLK-13/14 threshold values
 * are amendable and never hardcoded client-side, so no headcount
 * comparison happens here.
 */
import { computed } from 'vue';
import BoardStrip from '@/Components/Organizations/BoardStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';

const props = defineProps({
    /**
     * { id, name, kind:'chief_executive'|'treasury'|'defense'|'state'|
     *   'justice'|'other', status (ESM-17), worker_count,
     *   board:{ owner_seats, worker_seats, composition_valid, seats:[compact] },
     *   charter:{ act_number, href, reporting_interval_months },
     *   oversees_cgcs:[{name,href}], next_report:{ due_on, status }|null, href }
     */
    department: { type: Object, required: true },
});

const KIND_LABELS = {
    chief_executive: 'Chief Executive',
    treasury: 'Treasury',
    defense: 'Defense',
    state: 'State',
    justice: 'Justice',
    other: 'Custom',
};
const kindLabel = computed(() => KIND_LABELS[props.department.kind] ?? props.department.kind);

/* ESM-17 status badge. */
const STATUS_TONES = {
    operating: ['success', 'check', 'Operating'],
    chartered: ['info', 'file-text', 'Chartered'],
    oversight_assigned: ['info', 'shield', 'Oversight assigned'],
    governors_nominated: ['info', 'clock', 'Governors nominated'],
    consented: ['info', 'check', 'Consented'],
    reporting: ['info', 'bar-chart', 'Reporting'],
    rechartered: ['neutral', 'refresh-cw', 'Re-chartered'],
    dissolved: ['neutral', 'minus', 'Dissolved'],
};
const statusBadge = computed(() => {
    const [tone, icon, text] = STATUS_TONES[props.department.status] ?? ['neutral', null, props.department.status];
    return { tone, icon, text };
});

/* Co-determination cell — state from engine seat counts only. */
const codet = computed(() => {
    const board = props.department.board;
    if (!board || board.worker_seats === 0) {
        return { tone: 'neutral', icon: 'minus', text: 'below threshold', citation: `${fmt(props.department.worker_count)} workers below the CLK-13 minimum` };
    }
    if (board.worker_seats >= board.owner_seats) {
        return { tone: 'success', icon: 'users', text: 'parity', citation: `${fmt(props.department.worker_count)} workers · worker seats equal owner seats · CLK-14` };
    }
    const n = board.worker_seats;
    return {
        tone: 'info',
        icon: 'users',
        text: `${n} worker seat${n > 1 ? 's' : ''} · scaling`,
        citation: `${fmt(props.department.worker_count)} workers past the CLK-13 minimum`,
    };
});

/* Reporting-due chip. */
const reportChip = computed(() => {
    const report = props.department.next_report;
    if (!report) return null;
    if (report.status === 'overdue') return { tone: 'warning', icon: 'alert-triangle', text: `report overdue · was due ${report.due_on}` };
    if (report.status === 'due_soon') return { tone: 'warning', icon: 'clock', text: `report due ${report.due_on}` };
    return { tone: 'neutral', icon: 'clock', text: `next report ${report.due_on}` };
});

const fmt = (n) => Number(n ?? 0).toLocaleString();
</script>

<template>
    <div class="card">
        <div class="card-title">
            <h3 style="font-size: var(--text-base)">
                <a :href="department.href">{{ department.name }}</a>
            </h3>
        </div>

        <p class="cluster" style="gap: var(--space-2)">
            <TagChip data-no-i18n>{{ kindLabel }}</TagChip>
            <StatusBadge :tone="statusBadge.tone" :icon="statusBadge.icon">{{ statusBadge.text }}</StatusBadge>
            <StatusBadge tone="info" icon="users">{{ fmt(department.worker_count) }} workers</StatusBadge>
        </p>

        <BoardStrip
            v-if="department.board?.seats?.length"
            :seats="department.board.seats"
            :composition-valid="department.board.composition_valid"
            :required-worker-seats="department.board.worker_seats"
            compact
        />

        <!-- co-determination cell — the registry contract -->
        <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
            <StatusBadge :tone="codet.tone" :icon="codet.icon">{{ codet.text }}</StatusBadge>
            <span class="citation">{{ codet.citation }}</span>
        </p>

        <p class="cluster" style="gap: var(--space-2)">
            <a v-if="department.charter" class="tag-chip" :href="department.charter.href" data-no-i18n>
                {{ department.charter.act_number }} · F-LEG-016
            </a>
            <StatusBadge v-if="reportChip" :tone="reportChip.tone" :icon="reportChip.icon">
                {{ reportChip.text }}
            </StatusBadge>
        </p>

        <p v-if="department.oversees_cgcs?.length" class="citation" style="margin: 0">
            oversees:
            <template v-for="(cgc, i) in department.oversees_cgcs" :key="cgc.name">
                <template v-if="i > 0"> · </template><a :href="cgc.href">{{ cgc.name }}</a>
            </template>
            — CGC IP perpetually public domain · Art. III §5
        </p>
    </div>
</template>
