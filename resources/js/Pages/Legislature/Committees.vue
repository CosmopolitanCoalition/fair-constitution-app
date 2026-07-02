<script setup>
/**
 * Legislature/Committees — FE-C6 (PHASE_C_DESIGN_frontend.md §B.5).
 *
 * Creation FormCard (F-LEG-009 → supermajority VoteTally) · allocation
 * formula card (faction-independent, ledger #q1) · preference ranker
 * (F-LEG-010 — RankList removable=false, every member ranks every
 * committee) · F-SPK-005 run affordance (Speaker-gated, disabled until
 * all serving members submitted) + the assignment tie-break table showing
 * BOTH normalized vote shares on contested seats (ledger #q2 transparency)
 * · committee register with kind-ratio seat strips and chair-RCV cards.
 *
 * Every number on a meter is a server snapshot; the page never computes
 * a threshold.
 */
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import RankList from '@/Components/Electoral/RankList.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    committees: { type: Array, default: () => [] },
    pendingProposals: { type: Array, default: () => [] },
    allocation: { type: Object, required: true },
    myPreferences: { type: Object, default: null },
    preferencesState: { type: Object, required: true },
    assignment: { type: Object, default: null },
    seatMachine: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
    bicameral: { type: Boolean, default: false },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/* ------------------------------------------------ creation (F-LEG-009) */
const createForm = useForm({ name: '', purpose: '', seats: 3 });
function submitCreate() {
    createForm.post(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

/* ------------------------------------- proposal vote casting (F-LEG-004) */
const castingProposal = ref(null);
function castProposal(proposal, { value, explanation }) {
    if (!proposal.cast_url) return;
    castingProposal.value = proposal.proposal_id;
    router.post(proposal.cast_url, { value, explanation }, {
        preserveScroll: true,
        onFinish: () => {
            castingProposal.value = null;
        },
    });
}

/* --------------------------------------------- preferences (F-LEG-010) */
const prefsLocked = computed(() => props.myPreferences?.submitted_at != null);

function defaultRankItems() {
    const byId = new Map(props.committees.map((c) => [c.id, c]));
    const order = props.myPreferences?.rankings?.length
        ? props.myPreferences.rankings.filter((id) => byId.has(id))
        : props.committees.map((c) => c.id);
    // Committees missing from a stale ranking append in creation order.
    for (const c of props.committees) {
        if (!order.includes(c.id)) order.push(c.id);
    }
    return order.map((id) => ({
        id,
        name: byId.get(id)?.name ?? id,
        chips: byId.get(id)?.status === 'created' ? ['awaiting assignment'] : [],
    }));
}

const prefItems = ref(defaultRankItems());
watch(() => props.committees, () => {
    prefItems.value = defaultRankItems();
});

const prefsForm = useForm({ rankings: [] });
function submitPreferences() {
    prefsForm.rankings = prefItems.value.map((item) => item.id);
    prefsForm.post(props.urls.preferences, { preserveScroll: true });
}

/* ---------------------------------------------- assignment (F-SPK-005) */
const assignForm = useForm({});
const allSubmitted = computed(
    () => props.preferencesState.submitted >= props.preferencesState.serving,
);
function runAssignment() {
    assignForm.post(props.urls.assign, { preserveScroll: true });
}

const tieBreakColumns = [
    { key: 'committee', label: 'Committee' },
    { key: 'won', label: 'Seat taken by (share)' },
    { key: 'lost', label: 'Next preference honored for (share)' },
];
const tieBreakRows = computed(() =>
    (props.assignment?.tie_breaks ?? []).map((contest, i) => ({
        id: i,
        committee: contest.committee + (contest.kind && contest.kind !== 'all' ? ` · ${contest.kind}` : ''),
        won: contest.winners.map((w) => `${w.name} (${w.share})`).join(', '),
        lost: contest.losers.map((l) => `${l.name} (${l.share})`).join(', '),
    })),
);

/* ------------------------------------------------ chair RCV (F-LEG-011) */
const chairRankings = ref({}); // committee id → [{id,name,chips}]
function chairItems(committee) {
    if (!chairRankings.value[committee.id]) {
        chairRankings.value[committee.id] = (committee.chair_ballot?.candidates ?? []).map((c) => ({
            id: c.id,
            name: c.name,
            chips: [],
        }));
    }
    return chairRankings.value[committee.id];
}

const castingChair = ref(null);
function castChair(committee) {
    castingChair.value = committee.id;
    router.post(
        committee.chair_ballot.cast_url,
        { rankings: chairItems(committee).map((i) => i.id) },
        {
            preserveScroll: true,
            onFinish: () => {
                castingChair.value = null;
            },
        },
    );
}

const launchingChair = ref(null);
function launchChairBallot(committee) {
    launchingChair.value = committee.id;
    router.post(committee.open_ballot_url, {}, {
        preserveScroll: true,
        onFinish: () => {
            launchingChair.value = null;
        },
    });
}

const STATUS_TONES = { created: 'info', seated: 'success', dissolved: 'neutral' };
</script>

<template>
    <PageScaffold :surface="surface" :title="`Committees — ${legislature.name}`">
        <template #intro>
            Committee seats are assigned from each member's own ranked preferences — every
            member answers for themselves, with no party machinery in between. When two members
            want the same last seat, it goes to whoever won the larger share of the vote at
            the election.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ================================== creation (F-LEG-009) ===== -->
        <FormCard
            v-if="can.create && formMeta('F-LEG-009')"
            :form="formMeta('F-LEG-009')"
            :inertia-form="createForm"
            submit-label="File creation act"
            processing-label="Filing…"
            @submit="submitCreate"
        >
            <Field label="Committee name" :error="createForm.errors.name" required>
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="createForm.name"
                        class="field-input"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>
            <Field label="Purpose" :error="createForm.errors.purpose">
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="createForm.purpose"
                        class="field-input"
                        rows="2"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>
            <Field
                label="Seats"
                :hint="bicameral
                    ? 'Bicameral committees mirror the chamber-kind ratio — largest remainder over serving type A : type B, each kind ≥ 1 at 2+ seats · Art. V §3.'
                    : 'The committee exists only when the supermajority vote adopts (Art. II §4).'"
                :error="createForm.errors.seats"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model.number="createForm.seats"
                        class="field-input"
                        type="number"
                        min="1"
                        max="99"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>
        </FormCard>

        <!-- ================================== open creation votes ====== -->
        <Card
            v-for="proposal in pendingProposals"
            :key="proposal.proposal_id"
            as="section"
            :title="`Creation vote — ${proposal.name} (${proposal.seats} seats)`"
        >
            <p v-if="proposal.purpose" class="cc-small">{{ proposal.purpose }}</p>
            <VoteTally
                v-if="proposal.tally"
                v-bind="proposal.tally"
                :can-cast="can.create && !proposal.my_cast"
                :casting="castingProposal === proposal.proposal_id"
                @cast="castProposal(proposal, $event)"
            />
            <p v-if="proposal.my_cast" class="citation">Your cast is recorded — casts are immutable (the record is the record).</p>
            <details v-if="proposal.casts.length" style="margin-block-start: var(--space-2)">
                <summary class="cc-small" style="cursor: pointer">Published casts ({{ proposal.casts.length }})</summary>
                <VoteCastList :casts="proposal.casts" :group-by-kind="bicameral" />
            </details>
        </Card>

        <!-- ================================== allocation =============== -->
        <Card as="section" title="Placement allocation">
            <p class="cc-small" data-no-i18n>{{ allocation.share_formula }}</p>
            <p class="gloss">
                Placements distribute evenly across members — counts differ by at most one.
                Multi-org-endorsed and endorsement-less members are first-class: there is no
                faction layer anywhere in the procedure · ledger #q1.
            </p>
            <p class="citation">
                {{ allocation.total_seats }} committee seat(s) across {{ allocation.committee_count }}
                committee(s) · {{ allocation.total_reps }} serving member(s) · Art. II §4 · as implemented
            </p>
        </Card>

        <div class="grid-2">
            <!-- ============================== preferences (F-LEG-010) == -->
            <section class="card" aria-labelledby="prefs-h">
                <h2 id="prefs-h">
                    Your committee preferences
                    <StatusBadge v-if="prefsLocked" tone="success" icon="check">
                        Submitted {{ fmt(myPreferences.submitted_at) }}
                    </StatusBadge>
                </h2>
                <template v-if="committees.length && can.submitPreferences">
                    <p class="gloss">
                        Rank every committee — the assignment algorithm honors your order; ties
                        break by normalized vote share (ledger #q2). Default order is committee
                        creation order. Keyboard: ↑/↓ buttons or Alt+Arrow keys — no drag needed.
                    </p>
                    <RankList
                        v-model="prefItems"
                        :seats="committees.length"
                        :removable="false"
                        :disabled="prefsLocked"
                    />
                    <div class="cluster" style="margin-block-start: var(--space-3)">
                        <Btn
                            v-if="!prefsLocked"
                            variant="primary"
                            size="sm"
                            :disabled="prefsForm.processing"
                            @click="submitPreferences"
                        >Submit preferences (F-LEG-010)</Btn>
                        <span v-else class="citation">
                            Locked — an F-SPK-005 run snapshots all inputs; later edits affect only future runs.
                        </span>
                    </div>
                </template>
                <p v-else class="gloss">
                    {{ committees.length ? 'Preference ranking is a member action (R-09).' : 'No committees yet — preferences open once a creation act adopts.' }}
                </p>
            </section>

            <!-- ============================== assignment (F-SPK-005) === -->
            <section class="card" aria-labelledby="assign-h">
                <h2 id="assign-h">Assignment run</h2>
                <p class="cc-small">
                    {{ preferencesState.submitted }} of {{ preferencesState.serving }} serving
                    members have submitted preferences. Non-submitters default to committee
                    creation order.
                </p>
                <div v-if="can.runAssignment" class="cluster" style="margin-block-start: var(--space-2)">
                    <Btn
                        variant="primary"
                        size="sm"
                        :disabled="assignForm.processing || !allSubmitted"
                        :title="allSubmitted ? undefined : `Waiting on: ${preferencesState.pending.join(', ')}`"
                        @click="runAssignment"
                    >Run assignment (F-SPK-005)</Btn>
                    <span v-if="!allSubmitted" class="citation">
                        enabled when all serving members have submitted — the Speaker may also run
                        with defaults applied (engine-validated)
                    </span>
                </div>
                <p v-else class="citation">The assignment run is the Speaker's administration (F-SPK-005 · R-10).</p>

                <template v-if="assignment">
                    <p class="cc-small" style="margin-block-start: var(--space-3)">
                        Last run {{ fmt(assignment.run_at) }} — {{ assignment.placements }} placement(s),
                        sealed · audit #{{ assignment.audit_seq }}.
                    </p>
                    <template v-if="tieBreakRows.length">
                        <h3 style="font-size: var(--text-base)">Contested seats — normalized-share tie-breaks</h3>
                        <DataTable
                            :columns="tieBreakColumns"
                            :rows="tieBreakRows"
                            row-key="id"
                            caption="Contested committee seats resolved by normalized vote share"
                        />
                        <p class="gloss">
                            The winner is the largest vote share after normalizing quotas to account
                            for one-person-one-vote deviations; the loser's next preference is
                            honored in the same pass · Art. II §4 · as implemented (ledger #q2).
                        </p>
                    </template>
                    <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                        No contested seats — every placement honored a preference without a tie-break.
                    </p>
                </template>
            </section>
        </div>

        <!-- ================================== the register ============= -->
        <Card as="section" title="Committees">
            <p v-if="!committees.length" class="gloss">
                No committees yet — any member may file the creation act above; the committee
                exists only when the supermajority adopts it.
            </p>

            <div class="stack" style="gap: var(--space-3)">
                <Card v-for="committee in committees" :key="committee.id" inset>
                    <div class="cluster" style="justify-content: space-between">
                        <h3 style="font-size: var(--text-base); margin: 0">
                            <a :href="committee.href">{{ committee.name }}</a>
                            {{ ' ' }}
                            <StatusBadge :tone="STATUS_TONES[committee.status] ?? 'neutral'">{{ committee.status }}</StatusBadge>
                        </h3>
                        <span class="cc-small">
                            {{ committee.seats }} seats<template v-if="committee.by_kind">
                                — {{ committee.by_kind.type_a }} type A + {{ committee.by_kind.type_b }} type B
                                <span class="citation">mirrors the chamber-kind ratio · Art. V §3</span>
                            </template>
                            · {{ committee.bills_count }} bill(s)
                        </span>
                    </div>

                    <p v-if="committee.purpose" class="cc-small">{{ committee.purpose }}</p>

                    <p v-if="committee.created_by" class="citation" data-no-i18n>
                        Creation act: {{ committee.created_by.summary }}
                    </p>

                    <p class="cc-small" style="margin-block: var(--space-1)">
                        <template v-for="(member, mi) in committee.members" :key="mi">
                            <template v-if="mi > 0"> · </template>
                            {{ member.name }}<TagChip v-if="member.seat_kind"> {{ member.seat_kind === 'type_b' ? 'type B' : 'type A' }}</TagChip>
                        </template>
                        <span v-if="!committee.members.length" class="gloss">not yet seated — run the assignment</span>
                    </p>

                    <Banner v-for="(note, ni) in committee.notes" :key="ni" tone="warning" role="status">
                        {{ note }} <span class="citation">re-check pending the chamber countback · WF-LEG-13</span>
                    </Banner>

                    <!-- chair / alternate ------------------------------- -->
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <template v-if="committee.chair">
                            <StatusBadge tone="success" icon="check">Chair: {{ committee.chair.name }} · R-12</StatusBadge>
                            <StatusBadge v-if="committee.alternate" tone="info">Alternate: {{ committee.alternate.name }} · R-13</StatusBadge>
                            <span class="citation">whole-legislature RCV · F-LEG-011</span>
                        </template>
                        <template v-else-if="committee.status === 'seated'">
                            <template v-if="committee.chair_ballot && committee.chair_ballot.status === 'open'">
                                <StatusBadge tone="warning" icon="clock">
                                    Chair ballot open — {{ committee.chair_ballot.cast_count }} of
                                    {{ committee.chair_ballot.expected }} cast
                                </StatusBadge>
                            </template>
                            <template v-else>
                                <Btn
                                    v-if="can.runAssignment || can.voteChair"
                                    variant="secondary"
                                    size="sm"
                                    :disabled="launchingChair === committee.id"
                                    @click="launchChairBallot(committee)"
                                >Open chair ballot (F-LEG-011)</Btn>
                                <span class="citation">whole-legislature RCV · candidates = the committee's seated members</span>
                            </template>
                        </template>
                    </div>

                    <!-- chair ballot casting ---------------------------- -->
                    <div
                        v-if="committee.chair_ballot && committee.chair_ballot.status === 'open' && can.voteChair && !committee.chair_ballot.my_cast"
                        style="margin-block-start: var(--space-2)"
                    >
                        <p class="gloss">
                            Rank the committee's seated members for chair — all serving members cast,
                            Speaker included (constitutive election · Art. II §3 · as implemented).
                        </p>
                        <RankList
                            :model-value="chairItems(committee)"
                            :seats="1"
                            :removable="false"
                            @update:model-value="chairRankings[committee.id] = $event"
                        />
                        <Btn
                            variant="primary"
                            size="sm"
                            style="margin-block-start: var(--space-2)"
                            :disabled="castingChair === committee.id"
                            @click="castChair(committee)"
                        >Cast chair ballot (F-LEG-011)</Btn>
                    </div>
                    <p
                        v-else-if="committee.chair_ballot && committee.chair_ballot.status === 'open' && committee.chair_ballot.my_cast"
                        class="citation"
                        style="margin-block-start: var(--space-1)"
                    >Your chair ballot is recorded — rankings are public · Art. II §2.</p>
                </Card>
            </div>
        </Card>

        <!-- ================================== seat machine ============= -->
        <Card as="section" title="Committee seat lifecycle">
            <StateStrip :states="seatMachine" aria-label="Committee seat state machine" />
            <p class="gloss">
                tie_broken is the F-SPK-005 normalized-quota branch (ledger #q2); vacated seats
                refill by whole-house RCV among members at the chamber-minimum placement count
                (WF-LEG-13 — proportion-safe with no faction layer).
            </p>
        </Card>

        <template #about>
            <p>
                Committee assignment is faction-independent: every member rank-orders every
                committee; placements honor rank order; ties break to the seat holder with the
                largest vote share after normalizing quotas. This preserves the proportional
                representation the STV election produced while making assignment independent of
                any party layer.
            </p>
        </template>
    </PageScaffold>
</template>
