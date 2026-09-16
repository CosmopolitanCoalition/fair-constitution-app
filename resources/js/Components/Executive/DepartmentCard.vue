<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
import { useI18n } from 'vue-i18n';
import BoardStrip from '@/Components/Organizations/BoardStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';

const { t } = useI18n();

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
const STATUS_LABELS = () => ({
    operating: t('c_institution_components.department_card.status_operating', 'Operating'),
    chartered: t('c_institution_components.department_card.status_chartered', 'Chartered'),
    oversight_assigned: t('c_institution_components.department_card.status_oversight_assigned', 'Oversight assigned'),
    governors_nominated: t('c_institution_components.department_card.status_governors_nominated', 'Governors nominated'),
    consented: t('c_institution_components.department_card.status_consented', 'Consented'),
    reporting: t('c_institution_components.department_card.status_reporting', 'Reporting'),
    rechartered: t('c_institution_components.department_card.status_rechartered', 'Re-chartered'),
    dissolved: t('c_institution_components.department_card.status_dissolved', 'Dissolved'),
});
const statusBadge = computed(() => {
    const entry = STATUS_TONES[props.department.status];
    if (!entry) return { tone: 'neutral', icon: null, text: props.department.status };
    return { tone: entry[0], icon: entry[1], text: STATUS_LABELS()[props.department.status] ?? props.department.status };
});

/* Co-determination cell — state from engine seat counts only. */
const codet = computed(() => {
    const board = props.department.board;
    if (!board || board.worker_seats === 0) {
        return { tone: 'neutral', icon: 'minus', text: t('c_institution_components.department_card.codet_below', 'below threshold'), citation: t('c_institution_components.department_card.codet_below_cite', '{n} workers below the CLK-13 minimum', { named: { n: fmt(props.department.worker_count) } }) };
    }
    if (board.worker_seats >= board.owner_seats) {
        return { tone: 'success', icon: 'users', text: t('c_institution_components.department_card.codet_parity', 'parity'), citation: t('c_institution_components.department_card.codet_parity_cite', '{n} workers · worker seats equal owner seats · CLK-14', { named: { n: fmt(props.department.worker_count) } }) };
    }
    const n = board.worker_seats;
    return {
        tone: 'info',
        icon: 'users',
        text: t('c_institution_components.department_card.codet_scaling', '{n} worker seat{s} · scaling', { named: { n, s: n > 1 ? 's' : '' } }),
        citation: t('c_institution_components.department_card.codet_scaling_cite', '{n} workers past the CLK-13 minimum', { named: { n: fmt(props.department.worker_count) } }),
    };
});

/* Reporting-due chip. */
const reportChip = computed(() => {
    const report = props.department.next_report;
    if (!report) return null;
    if (report.status === 'overdue') return { tone: 'warning', icon: 'alert-triangle', text: t('c_institution_components.department_card.report_overdue', 'report overdue · was due {date}', { named: { date: report.due_on } }) };
    if (report.status === 'due_soon') return { tone: 'warning', icon: 'clock', text: t('c_institution_components.department_card.report_due_soon', 'report due {date}', { named: { date: report.due_on } }) };
    return { tone: 'neutral', icon: 'clock', text: t('c_institution_components.department_card.report_next', 'next report {date}', { named: { date: report.due_on } }) };
});

const fmt = (n) => localeFmt.number(Number(n ?? 0));
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
            <StatusBadge tone="info" icon="users">{{ t('c_institution_components.department_card.workers', '{n} workers', { named: { n: fmt(department.worker_count) } }) }}</StatusBadge>
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
            {{ t('c_institution_components.department_card.oversees', 'oversees:') }}
            <template v-for="(cgc, i) in department.oversees_cgcs" :key="cgc.name">
                <template v-if="i > 0"> · </template><a :href="cgc.href">{{ cgc.name }}</a>
            </template>
            {{ t('c_institution_components.department_card.cgc_public_domain', '— CGC IP perpetually public domain · Art. III §5') }}
        </p>
    </div>
</template>
