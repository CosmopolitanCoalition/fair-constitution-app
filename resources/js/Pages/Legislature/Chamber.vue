<script setup>
/**
 * Legislature/Chamber — FE-C2 (PHASE_C_DESIGN_frontend.md §B.1; surface
 * legislature/legislature-home).
 *
 * Stat row (peg-quorum glosses) · the circular SeatMap (real members,
 * vacancy dots, Speaker gold; bicameral kind legend) · roster DataTable ·
 * term-lockstep + vacancy cards · the WF-LEG-01 first-sessions checklist
 * driven by REAL rows. Mode is data-driven: bicameral (San Marino) /
 * unicameral (Montegiardino) / forming empty state (Earth).
 *
 * Every number on this page is server-resolved — quorum/supermajority
 * stats come from the controller's PROTECTED-function resolution; vote
 * meters live on other surfaces and render engine snapshots there.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import OrgChip from '@/Components/Ui/OrgChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import SeatMap from '@/Components/Legislature/SeatMap.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.1 legislature block; null = resolver empty state. */
    legislature: { type: Object, default: null },
    members: { type: Array, default: () => [] },
    vacancies: { type: Array, default: () => [] },
    firstSessions: { type: Array, default: () => [] },
    mapperHref: { type: String, default: '/legislatures' },
    can: { type: Object, default: () => ({ takeOath: false, oathMemberId: null, isMember: false }) },
    empty: { type: Object, default: null },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const forming = computed(() => props.legislature !== null && props.legislature.status !== 'active');
const bicameral = computed(() => props.legislature?.mode === 'bicameral');
const highlightId = ref(null);

const rosterColumns = [
    { key: 'seat_no', label: 'Seat', align: 'right' },
    { key: 'name', label: 'Member' },
    { key: 'endorsements', label: 'Endorsements' },
    { key: 'vote_share_norm', label: 'Share (norm)', mono: true, align: 'right' },
    { key: 'status', label: 'Status' },
];

const serving = computed(() => props.members.filter((m) => !m.vacant));

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return iso;
    }
}

/* ----------------------------------------------------------- oath ------ */
const swearing = ref(false);
function takeOath() {
    if (!props.can.oathMemberId) return;
    swearing.value = true;
    router.post(`/members/${props.can.oathMemberId}/oath`, {}, {
        preserveScroll: true,
        onFinish: () => {
            swearing.value = false;
        },
    });
}

/* ------------------------------------------- first-sessions actions ---- */
function stepBadge(step) {
    if (step.done_at) return { tone: 'success', icon: 'check', text: 'Done' };
    return { tone: 'neutral', icon: 'clock', text: 'Pending' };
}
</script>

<template>
    <PageScaffold
        :surface="surface"
        :title="legislature ? `Chamber — ${legislature.name}` : 'Chamber'"
    >
        <template #intro>
            The chamber itself: who serves, who presides, what the peg thresholds are, and
            what a freshly constituted legislature must do first. Every threshold here is the
            engine's arithmetic over ALL serving seats — never over those present.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ================================== resolver empty state ====== -->
        <Card v-if="!legislature" as="section" title="No active legislature">
            <Banner tone="info" role="status" title="No active legislature in your association chain.">
                {{ empty?.note ?? 'Jurisdictions activate at critical population · CLK-06.' }}
            </Banner>
            <p class="cc-small" style="margin-block-start: var(--space-3)">
                Browse every legislature on the instance from the
                <Link href="/legislatures">legislature index</Link>.
            </p>
        </Card>

        <template v-else>
            <!-- ======================================= header links ====== -->
            <div class="cluster">
                <Link :href="mapperHref">Districts &amp; maps →</Link>
                <Link :href="`/legislatures/${legislature.id}/bills`">Bills →</Link>
                <Link v-if="can.isMember" :href="`/legislatures/${legislature.id}/session`">Session console →</Link>
                <Link :href="`/legislatures/${legislature.id}/settings`">Settings register →</Link>
            </div>

            <!-- ========================================== stat row ====== -->
            <Card as="section" title="The peg numbers">
                <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                    <Stat :value="legislature.seats" label="seats" />
                    <Stat :value="legislature.serving" label="serving" />
                    <Stat
                        :value="legislature.quorum ?? '—'"
                        label="quorum — of all serving, never of those present"
                    />
                    <Stat
                        :value="legislature.supermajority ?? '—'"
                        :label="`supermajority = ceil(${legislature.serving} × 2/3)`"
                        accent
                    />
                    <template v-if="bicameral && legislature.by_kind">
                        <Stat
                            :value="`${legislature.by_kind.type_a.serving}/${legislature.by_kind.type_a.seats}`"
                            label="type A serving / seats"
                        />
                        <Stat
                            :value="`${legislature.by_kind.type_b.serving}/${legislature.by_kind.type_b.seats}`"
                            label="type B serving / seats"
                        />
                    </template>
                </div>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    A vacant seat is simply not serving; an absent member counts the same as a
                    no. Thresholds resolve through the protected functions — hardened.
                </p>
            </Card>

            <!-- ===================================== forming state ====== -->
            <Banner v-if="forming" tone="info" role="status" title="Forming — seats fill at certification (WF-ELE-01).">
                This legislature has no seated members yet. The seat map appears when the
                first general election certifies; the first-sessions checklist below is the
                constituting to-do list.
            </Banner>

            <!-- ========================================== seat map ====== -->
            <Card v-if="!forming && members.length" as="section" title="The chamber — circular, no head of the room">
                <p class="citation">
                    seniority-alternating seating · vacancies join at the junior-most position ·
                    seniority = days served, ties by normalized vote share (ledger #q2)
                </p>
                <SeatMap :members="members" :highlight-id="highlightId" :max-width="members.length > 12 ? '30rem' : '22rem'" />
                <p v-if="bicameral" class="gloss">
                    {{ legislature.by_kind.type_a.seats }} type A across the districts +
                    {{ legislature.by_kind.type_b.seats }} type B, one per constituent —
                    both kinds must independently agree · Art. V §3.
                </p>
            </Card>

            <!-- ============================================ roster ====== -->
            <Card v-if="!forming && serving.length" as="section" title="Roster">
                <DataTable :columns="rosterColumns" :rows="serving" row-key="id" caption="Serving members">
                    <template #cell-seat_no="{ row }">
                        <span
                            class="mono"
                            style="cursor: default"
                            @mouseenter="highlightId = row.id"
                            @mouseleave="highlightId = null"
                        >{{ row.seat_no }}</span>
                    </template>
                    <template #cell-name="{ row }">
                        <strong style="color: var(--gov-fg)">{{ row.name }}</strong>
                        <StatusBadge v-if="row.speaker" tone="warning" icon="landmark" style="margin-inline-start: var(--space-2)">
                            Speaker · neutral
                        </StatusBadge>
                        <span v-if="bicameral" class="cc-small" style="margin-inline-start: var(--space-2)">
                            {{ row.seat_kind === 'type_b' ? 'type B' : 'type A' }}
                        </span>
                        <span v-if="row.district_label" class="cc-small" style="margin-inline-start: var(--space-2)">
                            {{ row.district_label }}
                        </span>
                    </template>
                    <template #cell-endorsements="{ row }">
                        <template v-if="row.endorsements.length">
                            <OrgChip
                                v-for="org in row.endorsements"
                                :key="org.name"
                                :name="org.name"
                                :org-type="org.org_type"
                                style="margin-inline-end: var(--space-1)"
                            />
                        </template>
                        <span v-else class="gloss">no endorsements</span>
                    </template>
                    <template #cell-vote_share_norm="{ row }">
                        <span class="mono">{{ row.vote_share_norm != null ? row.vote_share_norm.toFixed(4) : '—' }}</span>
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="row.status === 'seated' ? 'success' : 'info'">{{ row.status }}</StatusBadge>
                        <span v-if="row.note" class="citation" style="display: block">{{ row.note }}</span>
                    </template>
                </DataTable>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    normalized vote share = the certification's quota-normalized support ·
                    committee tie-break currency · ledger #q2
                </p>
                <div v-if="can.takeOath" class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn variant="primary" :disabled="swearing" @click="takeOath">
                        Take the oath of office
                    </Btn>
                    <FormChip form-id="F-LEG-001" name="Oath of office / seating acceptance" />
                    <span class="citation">flips your seat elected → seated · Art. II §1</span>
                </div>
            </Card>

            <!-- =========================== term lockstep + vacancies ===== -->
            <div class="grid-2">
                <Card as="section" title="Term — one clock for every elected office">
                    <Stat
                        :value="legislature.term.days_remaining ?? '—'"
                        :label="`days remaining — term ends ${legislature.term.ends_on ?? '(not yet certified)'}`"
                    />
                    <p style="margin-block-start: var(--space-2)">
                        <HardenedChip>term lockstep · CLK-01 / CLK-10 — elections cannot be skipped or delayed</HardenedChip>
                    </p>
                    <p class="cc-small" style="margin-block-start: var(--space-2)">
                        The next election exists from the moment the prior one certifies.
                        <template v-if="legislature.term.election_id">
                            <Link :href="`/elections/${legislature.term.election_id}`">Open the successor election →</Link>
                        </template>
                    </p>
                    <p v-if="legislature.next_session_due" class="citation">
                        next session due by {{ legislature.next_session_due }} · CLK-02 ·
                        the scheduler compels it (WF-SYS-02)
                    </p>
                </Card>

                <Card as="section" title="Vacancies">
                    <template v-if="vacancies.length">
                        <div v-for="vacancy in vacancies" :key="vacancy.id" class="card card--inset" style="margin-block-end: var(--space-2)">
                            <p style="margin-block-end: var(--space-1)">
                                <strong>Seat {{ vacancy.seat_no ?? '—' }}</strong> — {{ vacancy.member_name }}
                                <StatusBadge
                                    :tone="vacancy.status === 'special_election_scheduled' ? 'warning' : 'info'"
                                    style="margin-inline-start: var(--space-2)"
                                >{{ vacancy.status.replaceAll('_', ' ') }}</StatusBadge>
                            </p>
                            <p class="citation">
                                declared via {{ vacancy.declared_via ?? 'system' }} ·
                                <FormChip form-id="F-LEG-036" /> ·
                                <Link :href="vacancy.href">countback record →</Link>
                            </p>
                        </div>
                        <p class="gloss">
                            Countback first (the voters' prior ballots decide), special election only
                            when ballots exhaust · Art. II §5.
                        </p>
                    </template>
                    <p v-else class="gloss">No open vacancies — every seat is either serving or filled.</p>
                </Card>
            </div>

            <!-- ========================== first-sessions checklist ======= -->
            <Card as="section" title="First sessions — constituting the chamber (WF-LEG-01)">
                <ol class="agenda-list">
                    <li v-for="(step, i) in firstSessions" :key="step.form_id" class="agenda-slot">
                        <span class="flow-step-n">{{ i + 1 }}</span>
                        <div style="flex: 1 1 auto; min-inline-size: 0">
                            <strong style="color: var(--gov-fg)">{{ step.name }}</strong>
                            {{ ' ' }}
                            <FormChip :form-id="step.form_id" />
                            <p class="cc-small" style="margin-block: var(--space-1) 0">{{ step.desc }}</p>
                            <p class="citation">
                                {{ step.basis }}
                                <template v-if="step.note"> · {{ step.note }}</template>
                                <template v-if="step.act_href">
                                    · <Link :href="step.act_href">record →</Link>
                                </template>
                            </p>
                            <!-- The next undone step renders its live action. -->
                            <div
                                v-if="!step.done_at && step.form_id === 'F-LEG-001' && can.takeOath"
                                class="cluster"
                                style="margin-block-start: var(--space-2)"
                            >
                                <Btn variant="primary" size="sm" :disabled="swearing" @click="takeOath">Take the oath</Btn>
                            </div>
                            <div
                                v-else-if="!step.done_at && step.form_id === 'F-LEG-008' && can.isMember"
                                class="cluster"
                                style="margin-block-start: var(--space-2)"
                            >
                                <Link :href="`/legislatures/${legislature.id}/session`">Open the session console — speaker balloting →</Link>
                            </div>
                        </div>
                        <StatusBadge :tone="stepBadge(step).tone" :icon="stepBadge(step).icon">
                            {{ stepBadge(step).text }}
                        </StatusBadge>
                    </li>
                </ol>
            </Card>
        </template>

        <template #about>
            <p>
                The chamber is circular — there is no head of the room; the Speaker presides
                from among equals and votes only to break ties (Art. II §3). Seat-map seniority
                and the roster's normalized vote share are display of certified records.
            </p>
        </template>
    </PageScaffold>
</template>
