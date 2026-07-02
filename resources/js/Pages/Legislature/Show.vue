<script setup>
/**
 * Legislature/Show — the legislature overview (mockups-v3-wiring Phase 3e).
 *
 * The former 5.2k-line monolith hosting BOTH this overview AND the full
 * district mapper was split: the ENTIRE lm-split Leaflet machine moved
 * VERBATIM to Legislature/Districts.vue (route
 * /legislatures/{legislature}/districts); this page is what remains — who
 * this legislature is (seats, term, status), who serves in it, which
 * district maps it has, and the doors to everything else. Every pre-split
 * mapper deep link (?scope= / ?map= / ?setup= / ?compare=) is forwarded by
 * the controller to the districts surface, so nothing bookmarked breaks.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase 3e monolith retirement: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, default: null },
    /** { id, slug, name, status, term_number, term_starts_on, term_ends_on,
     *    type_a_seats, type_b_seats, serving, seated, speaker_name,
     *    chamber_seated } */
    legislature: { type: Object, required: true },
    /** Current members: [{ id, seat_no, name, district_label, status,
     *    seated_on, term_ends_on }] */
    members: { type: Array, default: () => [] },
    /** { total, active: { id, name, status, district_count } | null } */
    maps: { type: Object, default: () => ({ total: 0, active: null }) },
    /** The district-mapper URL (/legislatures/{slug}/districts). */
    districtsHref: { type: String, required: true },
});

const statusTone = computed(() => ({
    active: 'success',
    forming: 'info',
    dissolved: 'neutral',
}[props.legislature.status] ?? 'neutral'));

const totalSeats = computed(
    () => (props.legislature.type_a_seats ?? 0) + (props.legislature.type_b_seats ?? 0),
);

const memberColumns = [
    { key: 'seat_no', label: 'Seat', align: 'right' },
    { key: 'name', label: 'Member' },
    { key: 'district_label', label: 'District' },
    { key: 'status', label: 'Status' },
    { key: 'seated_on', label: 'Seated' },
    { key: 'term_ends_on', label: 'Term ends' },
];

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return iso;
    }
}

function fmtNum(n) {
    return typeof n === 'number' ? n.toLocaleString() : (n ?? '—');
}
</script>

<template>
    <PageScaffold :surface="surface" :title="`${legislature.name} — legislature`">
        <template #intro>
            One legislature, sized by its people. Every jurisdiction can seat its own
            chamber (CLK-06) — this page is that chamber's front door: its seats, its
            term, who serves, and the district maps that shape it.
        </template>

        <!-- ===================================== districts & maps ====== -->
        <Card as="section" title="Districts &amp; maps">
            <p>
                The district mapper — where this legislature's seats meet the map:
                versioned district plans, the autoseeder, quality metrics, and the
                manual drawing tools.
            </p>
            <div class="cluster" style="gap: var(--space-5); align-items: flex-start; margin-block: var(--space-3)">
                <Stat :value="fmtNum(maps.total)" :label="maps.total === 1 ? 'district map' : 'district maps'" />
                <Stat
                    v-if="maps.active"
                    :value="fmtNum(maps.active.district_count)"
                    :label="`districts on “${maps.active.name}” (${maps.active.status})`"
                    accent
                />
                <Stat v-else value="—" label="no active map yet" />
            </div>
            <Btn as="a" :href="districtsHref" variant="primary" icon="map">
                Open the district mapper →
            </Btn>
        </Card>

        <!-- =========================================== the chamber ====== -->
        <Card as="section" title="Seats &amp; term">
            <div class="cluster" style="gap: var(--space-3); margin-block-end: var(--space-3)">
                <StatusBadge :tone="statusTone">{{ legislature.status }}</StatusBadge>
                <span v-if="legislature.term_number" class="cc-small">
                    Term {{ legislature.term_number }}
                    <template v-if="legislature.term_starts_on">
                        · {{ fmtDate(legislature.term_starts_on) }} →
                        {{ fmtDate(legislature.term_ends_on) }}
                    </template>
                </span>
                <span v-if="legislature.speaker_name" class="cc-small">
                    Speaker: {{ legislature.speaker_name }}
                </span>
            </div>
            <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                <Stat :value="fmtNum(totalSeats)" label="seats" />
                <Stat :value="fmtNum(legislature.type_a_seats)" label="Type A — constituent reps" />
                <Stat
                    v-if="legislature.type_b_seats > 0"
                    :value="fmtNum(legislature.type_b_seats)"
                    label="Type B — at-large"
                />
                <Stat :value="fmtNum(legislature.serving)" label="serving now" accent />
            </div>
        </Card>

        <!-- =============================================== members ====== -->
        <Card as="section" title="Members">
            <template v-if="members.length > 0">
                <DataTable
                    :columns="memberColumns"
                    :rows="members"
                    row-key="id"
                    caption="Current members of this legislature"
                >
                    <template #cell-status="{ value }">
                        <StatusBadge :tone="value === 'seated' ? 'success' : 'info'">{{ value }}</StatusBadge>
                    </template>
                    <template #cell-seated_on="{ value }">{{ fmtDate(value) }}</template>
                    <template #cell-term_ends_on="{ value }">{{ fmtDate(value) }}</template>
                </DataTable>
            </template>
            <p v-else class="cc-small">
                No members yet — seats fill when a general election is certified
                (Art. II §1). The chamber constitutes itself at its first sessions.
            </p>
        </Card>

        <!-- ================================================= doors ====== -->
        <Card as="section" title="Inside this legislature">
            <div class="cluster">
                <Link v-if="legislature.chamber_seated" :href="`/legislatures/${legislature.id}/chamber`">Chamber →</Link>
                <Link :href="`/legislatures/${legislature.id}/bills`">Bills →</Link>
                <Link :href="`/legislatures/${legislature.id}/committees`">Committees →</Link>
                <Link :href="`/legislatures/${legislature.id}/referendums`">Referendums →</Link>
                <Link :href="`/legislatures/${legislature.id}/settings`">Settings register →</Link>
                <Link href="/legislatures">All legislatures →</Link>
            </div>
        </Card>
    </PageScaffold>
</template>
