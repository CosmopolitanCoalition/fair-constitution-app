<script setup>
/**
 * Org/BoardStrip — board composition strip: owner / worker / chair (FE-D1;
 * PHASE_D_DESIGN_frontend.md §A.3). Flat .seat-pip strip — no SVG; the
 * circular SeatMap stays a chamber-only artifact.
 *
 * CONSTITUTIONAL POSTURE: `compositionValid` and `requiredWorkerSeats`
 * are ENGINE OUTPUTS (boards.composition_valid; what the co-determination
 * scale demands) — the component renders them, never recomputes the scale.
 * Color is never the only signal: every pip carries an aria-label and the
 * strip renders a text legend.
 *
 * Roster grammar from mockups/executive/department-detail.html lines
 * 49–88 (the two clock regimes visible: governors appointed CLK-09 terms
 * beside worker seats ending with the legislative term · CLK-10); stat
 * grammar from board-elections.html lines 26–30.
 *
 * Used by: DepartmentDetail, DepartmentCard (compact), OrgDetail,
 * CgcDetail, BoardElections "seated board".
 */
import { computed } from 'vue';
import Banner from '@/Components/Ui/Banner.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import { referenceLabel } from '@/lib/referenceLabels.js';

const props = defineProps({
    /**
     * board_seats rows: [{ id, seat_class:'governor'|'owner_elected'|
     *   'worker_elected', holder:{name}|null, is_chair,
     *   status:'vacant'|'nominated'|'seated'|'removal_requested'|'removed'|'term_ended',
     *   expiring?: bool, term:{starts_on, ends_on, clock:'CLK-09'|'CLK-10'}|null }]
     */
    seats: { type: Array, required: true },
    /** boards.composition_valid — engine output. */
    compositionValid: { type: Boolean, required: true },
    /** What the co-determination scale demands (server). */
    requiredWorkerSeats: { type: Number, required: true },
    /** Pip strip only (table rows, DepartmentCard). */
    compact: { type: Boolean, default: false },
});

const { t } = useI18n();
const CLASS_LABELS = computed(() => ({
    governor: t('c_references.board_strip.class_governor', 'Appointed governor'),
    owner_elected: t('c_references.board_strip.class_owner_elected', 'Owner-elected'),
    worker_elected: t('c_references.board_strip.class_worker_elected', 'Worker-elected'),
}));
const clockLabel = (id) => referenceLabel(id, { translate: (key, fallback) => t(key, fallback) });

const rosterColumns = computed(() => [
    { key: 'member', label: t('c_references.board_strip.col_member', 'Member') },
    { key: 'seat_type', label: t('c_references.board_strip.col_seat_type', 'Seat type') },
    { key: 'term', label: t('c_references.board_strip.col_term', 'Term'), mono: true },
    { key: 'status', label: t('c_references.board_strip.col_status', 'Status') },
]);

const ownerSide = computed(() => props.seats.filter((s) => s.seat_class !== 'worker_elected'));
const workerSide = computed(() => props.seats.filter((s) => s.seat_class === 'worker_elected'));
const seatedWorkerSeats = computed(() => workerSide.value.filter((s) => s.status === 'seated').length);
const chair = computed(() => props.seats.find((s) => s.is_chair) ?? null);

function pipLabel(seat) {
    const cls = CLASS_LABELS.value[seat.seat_class] ?? seat.seat_class;
    const who = seat.status === 'vacant'
        ? t('c_references.board_strip.vacant', 'vacant')
        : (seat.holder?.name ?? t('c_references.board_strip.vacant', 'vacant'));
    const term = seat.term?.ends_on ? t('c_references.board_strip.term_ends', { date: seat.term.ends_on }) : '';
    const chairSuffix = seat.is_chair ? t('c_references.board_strip.chair_suffix', ' (joint-elected chair)') : '';
    return t('c_references.board_strip.pip_label', { cls, chair: chairSuffix, who, term });
}

function seatTypeLabel(seat) {
    const cls = CLASS_LABELS.value[seat.seat_class] ?? seat.seat_class;
    return seat.is_chair ? t('c_references.board_strip.seat_type_chair', { cls }) : cls;
}

function statusBadge(seat) {
    if (seat.expiring) return { tone: 'warning', icon: 'clock', text: t('c_references.board_strip.status_expiring', 'Term expiring · renomination open') };
    switch (seat.status) {
        case 'seated':
            return seat.seat_class === 'worker_elected'
                ? { tone: 'success', icon: 'users', text: t('c_references.board_strip.status_serving_worker', 'Serving · worker class') }
                : { tone: 'success', icon: 'check', text: t('c_references.board_strip.status_serving', 'Serving') };
        case 'nominated':
            return { tone: 'info', icon: 'clock', text: t('c_references.board_strip.status_nominated', 'Nominated · consent pending') };
        case 'removal_requested':
            return { tone: 'warning', icon: 'alert-triangle', text: t('c_references.board_strip.status_removal_requested', 'Removal requested') };
        case 'removed':
            return { tone: 'danger', icon: 'x', text: t('c_references.board_strip.status_removed', 'Removed') };
        case 'term_ended':
            return { tone: 'neutral', icon: 'minus', text: t('c_references.board_strip.status_term_ended', 'Term ended') };
        default:
            return { tone: 'neutral', icon: 'minus', text: t('c_references.board_strip.status_vacant', 'Vacant') };
    }
}

/* Invalid-composition banner — the constitutional rule verbatim (§A.3). */
const invalidBanner = computed(() => t('c_references.board_strip.invalid_banner', {
    seated: seatedWorkerSeats.value,
    required: props.requiredWorkerSeats,
}));
</script>

<template>
    <div class="stack" style="gap: var(--space-3)">
        <!-- the pip strip -->
        <div class="board-strip">
            <span
                v-for="seat in seats"
                :key="seat.id"
                class="seat-pip"
                :class="{
                    'seat-pip--worker': seat.seat_class === 'worker_elected',
                    'seat-pip--vacant': seat.status === 'vacant',
                    'seat-pip--chair': seat.is_chair,
                }"
                role="img"
                :aria-label="pipLabel(seat)"
                :title="pipLabel(seat)"
            ></span>
        </div>

        <template v-if="!compact">
            <!-- stat cluster + text legend (color is never the only signal) -->
            <p class="cc-small" style="margin: 0">
                {{ t('c_references.board_strip.legend_counts', { owner: ownerSide.length, worker: workerSide.length, chair: chair?.holder?.name ?? t('c_references.board_strip.unfilled', 'unfilled') }) }}
            </p>
            <p class="citation" style="margin: 0">
                {{ t('c_references.board_strip.legend', 'legend: solid pip = owner-side seat · blue pip = worker-elected seat · dashed pip = vacant · gold ring = joint-elected chair') }}
            </p>
        </template>

        <Banner v-if="!compositionValid" tone="warning" :title="t('c_references.board_strip.invalid_title', 'Board composition does not match the scale.')">
            {{ invalidBanner }}
        </Banner>

        <!-- the roster — the two clock regimes visible side by side -->
        <DataTable
            v-if="!compact"
            :columns="rosterColumns"
            :rows="seats"
            row-key="id"
            :caption="t('c_references.board_roster', 'Board members and their current term dates')"
        >
            <template #cell-member="{ row }">
                <template v-if="row.holder">{{ row.holder.name }}</template>
                <span v-else class="gloss">{{ t('c_references.board_strip.cell_vacant', '— vacant') }}</span>
            </template>
            <template #cell-seat_type="{ row }">{{ seatTypeLabel(row) }}</template>
            <template #cell-term="{ row }">
                <template v-if="row.term">
                    <span data-no-i18n>{{ row.term.starts_on }} → {{ row.term.ends_on }}</span>
                    <span v-if="row.term.clock" class="citation" style="display: block">{{ clockLabel(row.term.clock) }}</span>
                </template>
                <span v-else class="gloss">—</span>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :tone="statusBadge(row).tone" :icon="statusBadge(row).icon">
                    {{ statusBadge(row).text }}
                </StatusBadge>
            </template>
        </DataTable>
    </div>
</template>
