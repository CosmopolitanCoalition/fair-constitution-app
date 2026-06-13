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
 * 49–88 (the two clock regimes visible: governors 10-yr CLK-09 terms
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

const CLASS_LABELS = {
    governor: 'Appointed governor',
    owner_elected: 'Owner-elected',
    worker_elected: 'Worker-elected',
};

const ownerSide = computed(() => props.seats.filter((s) => s.seat_class !== 'worker_elected'));
const workerSide = computed(() => props.seats.filter((s) => s.seat_class === 'worker_elected'));
const seatedWorkerSeats = computed(() => workerSide.value.filter((s) => s.status === 'seated').length);
const chair = computed(() => props.seats.find((s) => s.is_chair) ?? null);

function pipLabel(seat) {
    const cls = CLASS_LABELS[seat.seat_class] ?? seat.seat_class;
    const who = seat.status === 'vacant' ? 'vacant' : (seat.holder?.name ?? 'vacant');
    const term = seat.term?.ends_on ? `, term ends ${seat.term.ends_on}` : '';
    return `Seat — ${cls}${seat.is_chair ? ' (joint-elected chair)' : ''}, ${who}${term}`;
}

function seatTypeLabel(seat) {
    return (CLASS_LABELS[seat.seat_class] ?? seat.seat_class) + (seat.is_chair ? ' · joint-elected chair' : '');
}

function statusBadge(seat) {
    if (seat.expiring) return { tone: 'warning', icon: 'clock', text: 'Term expiring · renomination open' };
    switch (seat.status) {
        case 'seated':
            return seat.seat_class === 'worker_elected'
                ? { tone: 'success', icon: 'users', text: 'Serving · worker class' }
                : { tone: 'success', icon: 'check', text: 'Serving' };
        case 'nominated':
            return { tone: 'info', icon: 'clock', text: 'Nominated · consent pending' };
        case 'removal_requested':
            return { tone: 'warning', icon: 'alert-triangle', text: 'Removal requested' };
        case 'removed':
            return { tone: 'danger', icon: 'x', text: 'Removed' };
        case 'term_ended':
            return { tone: 'neutral', icon: 'minus', text: 'Term ended' };
        default:
            return { tone: 'neutral', icon: 'minus', text: 'Vacant' };
    }
}

/* Invalid-composition banner — the constitutional rule verbatim (§A.3). */
const invalidBanner = computed(
    () =>
        `Board composition no longer matches the co-determination scale (${seatedWorkerSeats.value} of ` +
        `${props.requiredWorkerSeats} worker seats) — the board is valid only while composition matches the ` +
        'scale; a worker-track election is required, and any composition change re-triggers the joint chair ' +
        'election · Art. III §6 · WF-ORG-04 → WF-ORG-05',
);
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
                {{ ownerSide.length }} owner-side · {{ workerSide.length }} worker-elected ·
                chair {{ chair?.holder?.name ?? 'unfilled' }}
            </p>
            <p class="citation" style="margin: 0">
                legend: solid pip = owner-side seat · blue pip = worker-elected seat · dashed pip = vacant ·
                gold ring = joint-elected chair
            </p>
        </template>

        <Banner v-if="!compositionValid" tone="warning" title="Board composition does not match the scale.">
            {{ invalidBanner }}
        </Banner>

        <!-- the roster — the two clock regimes visible side by side -->
        <DataTable
            v-if="!compact"
            :columns="[
                { key: 'member', label: 'Member' },
                { key: 'seat_type', label: 'Seat type' },
                { key: 'term', label: 'Term', mono: true },
                { key: 'status', label: 'Status' },
            ]"
            :rows="seats"
            row-key="id"
            caption="Board roster — governors on 10-yr CLK-09 civil terms; worker seats end with the legislative term (CLK-10)"
        >
            <template #cell-member="{ row }">
                <template v-if="row.holder">{{ row.holder.name }}</template>
                <span v-else class="gloss">— vacant</span>
            </template>
            <template #cell-seat_type="{ row }">{{ seatTypeLabel(row) }}</template>
            <template #cell-term="{ row }">
                <template v-if="row.term">
                    <span data-no-i18n>{{ row.term.starts_on }} → {{ row.term.ends_on }}</span>
                    <span v-if="row.term.clock" class="citation" style="display: block" data-no-i18n>{{ row.term.clock }}</span>
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
