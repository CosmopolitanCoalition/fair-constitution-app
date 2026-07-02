<script setup>
/**
 * Organizations/BoardElections — FE-D8 (PHASE_D_DESIGN_frontend.md §B.9;
 * surface organizations/board-elections).
 *
 * Owner + worker STV tracks reuse the Phase B election machinery wholesale
 * (elections.kind org_board_owner|org_board_worker, races.electorate_type
 * owners|workers) — this page RENDERS counts (StvBar final-round rows + the
 * Droop line) and links OUT to the Phase B ballot surfaces; it never forks a
 * ballot UI. The joint CHAIR card composes Legislature/VoteTally
 * (body_type='board', full-board majority) + the RCV round record, exactly
 * as the speaker ballot renders in the session console.
 *
 * CONSTITUTIONAL POSTURE — pure renderer: ownerSeats / workerSeats /
 * compositionValid / the Droop quota / the chair vote's required & board
 * size are ENGINE SNAPSHOTS off rows (boards / tabulations /
 * chamber_vote_tallies). Nothing is computed here.
 */
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import StvRound from '@/Components/Electoral/StvRound.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import BoardStrip from '@/Components/Organizations/BoardStrip.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    organization: { type: Object, required: true },
    /** Engine snapshot off the boards row; null = no board constituted yet. */
    composition: { type: Object, default: null },
    ownerTrack: { type: Object, required: true },
    workerTrack: { type: Object, required: true },
    chair: { type: Object, default: null },
    seated: { type: Object, default: null },
    can: { type: Object, default: () => ({ administerOwner: false, administerWorker: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const profileHref = (id) => (id ? `/candidates/${id}` : null);

/* Final-round StvBar rows: the certified count's last round (same §C
   presenter shape the public Results page renders). */
function finalRound(result) {
    if (!result?.display?.length) return null;
    const keyed = result.display.filter((r) => r.tallies);
    return keyed[keyed.length - 1] ?? null;
}

const ownerFinal = computed(() => finalRound(props.ownerTrack.result));
const workerFinal = computed(() => finalRound(props.workerTrack.result));

function electedRoundMap(result) {
    return Object.fromEntries((result?.elected ?? []).map((e) => [e.name, e.round]));
}

/* ----------------------------------------------------------- POST ------ */
const ownerForm = useForm({ track: 'owner', action: 'open_owner_election' });
const workerForm = useForm({ track: 'worker', action: 'open_worker_election' });

function scheduleOwner() {
    ownerForm.post(`/organizations/${props.organization.id}/board-elections`, { preserveScroll: true });
}
function scheduleWorker() {
    workerForm.post(`/organizations/${props.organization.id}/board-elections`, { preserveScroll: true });
}

/* Chair RCV round record — the same shape the speaker ballot renders. */
const chairRounds = computed(() => props.chair?.rounds?.rounds ?? []);
</script>

<template>
    <PageScaffold :surface="surface" :title="`Board elections — ${organization.name}`">
        <template #intro>
            Three counts seat one board: shareholders elect the owner track by STV, workers elect
            the worker track by STV, and then the entire board jointly elects its chair by ranked
            choice. Voting itself happens on the public ballot surfaces — the counts published
            here are the record.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ===================================== no board yet =========== -->
        <Card v-if="!composition" as="section" title="No board constituted">
            <Banner tone="info" role="status" title="This organization has no board yet.">
                Co-determination provisions a board on the owner track first (F-ORG-003); the worker
                track and its seats appear once the organization crosses the first-seat threshold
                (CLK-13). The owner-track administration form below provisions it.
            </Banner>
        </Card>

        <template v-else>
            <!-- ====================================== stat cluster ====== -->
            <Card as="section" title="The board">
                <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                    <Stat :value="composition.ownerSeats" label="owner-side seats · R-26" />
                    <Stat :value="composition.workerSeats" label="worker-elected seats · R-27" accent />
                    <Stat :value="composition.chair?.name ?? 'unfilled'" label="joint chair · R-28" />
                </div>
                <p v-if="!composition.compositionValid" style="margin-block-start: var(--space-3)">
                    <StatusBadge tone="warning" icon="alert-triangle">
                        Composition invalid — a worker-track election is required before the board acts
                    </StatusBadge>
                </p>
            </Card>

            <!-- ======================================= owner track ====== -->
            <Card as="section" title="Owner track — PR-STV">
                <p class="citation">
                    shareholders elect the owner side · the same Droop-quota STV as a public
                    election · Art. III §4, §6
                </p>

                <div class="cluster" style="gap: var(--space-4); margin-block: var(--space-2)">
                    <Stat :value="ownerTrack.electorate_count" label="eligible owners (active shareholdings · R-24)" />
                    <Stat
                        v-if="ownerTrack.result"
                        :value="ownerTrack.result.quota.toLocaleString()"
                        label="Droop quota = floor(votes ÷ (seats+1)) + 1"
                        accent
                    />
                </div>

                <!-- live race → link to the Phase B ballot surface -->
                <p v-if="ownerTrack.election?.live" class="cluster" style="margin-block: var(--space-2)">
                    <StatusBadge tone="info" icon="clock">election in flight · {{ ownerTrack.election.status }}</StatusBadge>
                    <Link :href="ownerTrack.election.href">vote on the ranked ballot →</Link>
                    <span class="citation">eligible owners see the race on their ballot surface</span>
                </p>

                <!-- certified result: final-round StvBar rows + the Droop line -->
                <template v-if="ownerTrack.result && ownerFinal">
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        Gold tick = the Droop quota; reaching it elects a candidate. Final round of the
                        certified count.
                    </p>
                    <span class="visually-hidden">Droop quota {{ ownerTrack.result.quota.toLocaleString() }}</span>
                    <StvRound
                        :round="ownerFinal"
                        :quota="ownerTrack.result.quota"
                        :scale="ownerTrack.result.scale"
                        :elected-round="electedRoundMap(ownerTrack.result)"
                        :profile-href="profileHref"
                        default-open
                    />
                    <p v-if="ownerTrack.result.certified_at" class="citation" data-no-i18n style="margin-block-start: var(--space-2)">
                        certified {{ new Date(ownerTrack.result.certified_at).toLocaleString() }} · F-ORG-003
                    </p>
                </template>
                <p v-else-if="!ownerTrack.election" class="gloss" style="margin-block-start: var(--space-2)">
                    No owner-track election has run — schedule one to fill the vacant owner seats.
                </p>

                <!-- administration (R-23) -->
                <div v-if="can.administerOwner && !ownerTrack.election?.live" class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn variant="primary" size="sm" :disabled="ownerForm.processing" @click="scheduleOwner">
                        Schedule owner-track election — F-ORG-003
                    </Btn>
                    <span class="citation">opens the same two-phase open ballot as a public election</span>
                </div>
            </Card>

            <!-- ===================================== worker track ======= -->
            <Card as="section" title="Worker track — PR-STV">
                <template v-if="workerTrack.exists">
                    <p class="citation">
                        this track exists because the organization crossed the first-seat threshold ·
                        CLK-13; its {{ composition.workerSeats }} seat(s) come from the uniform
                        co-determination scale · CLK-14
                        · <Link :href="organization.codet_href">co-determination scaling →</Link>
                    </p>

                    <div class="cluster" style="gap: var(--space-4); margin-block: var(--space-2)">
                        <Stat :value="workerTrack.electorate_count" label="eligible workers (active F-IND-014 registrations · R-25)" />
                        <Stat
                            v-if="workerTrack.result"
                            :value="workerTrack.result.quota.toLocaleString()"
                            label="Droop quota = floor(votes ÷ (seats+1)) + 1"
                            accent
                        />
                    </div>

                    <p v-if="workerTrack.election?.live" class="cluster" style="margin-block: var(--space-2)">
                        <StatusBadge tone="info" icon="clock">election in flight · {{ workerTrack.election.status }}</StatusBadge>
                        <Link :href="workerTrack.election.href">vote on the ranked ballot →</Link>
                        <span class="citation">eligible workers see the race on their ballot surface</span>
                    </p>

                    <template v-if="workerTrack.result && workerFinal">
                        <p class="gloss" style="margin-block-start: var(--space-2)">
                            Final round of the certified worker-track count.
                        </p>
                        <span class="visually-hidden">Droop quota {{ workerTrack.result.quota.toLocaleString() }}</span>
                        <StvRound
                            :round="workerFinal"
                            :quota="workerTrack.result.quota"
                            :scale="workerTrack.result.scale"
                            :elected-round="electedRoundMap(workerTrack.result)"
                            :profile-href="profileHref"
                            default-open
                        />
                        <p v-if="workerTrack.result.certified_at" class="citation" data-no-i18n style="margin-block-start: var(--space-2)">
                            certified {{ new Date(workerTrack.result.certified_at).toLocaleString() }} · F-ORG-004
                        </p>
                    </template>
                    <p v-else-if="!workerTrack.election" class="gloss" style="margin-block-start: var(--space-2)">
                        No worker-track election has run yet — the vacant worker seats await the count.
                        The system opens this election automatically when the scale adds a seat (CLK-13).
                    </p>

                    <div v-if="can.administerWorker && !workerTrack.election?.live" class="cluster" style="margin-block-start: var(--space-3)">
                        <Btn variant="primary" size="sm" :disabled="workerForm.processing" @click="scheduleWorker">
                            Schedule worker-track election — F-ORG-004
                        </Btn>
                        <span class="citation">also fired system-side from CLK-13 — R-23 absence never stalls a required seat</span>
                    </div>
                </template>

                <!-- below the threshold: no worker track at all -->
                <Banner v-else tone="info" role="status" title="No worker track yet.">
                    No worker track — the first worker seat appears at the CLK-13 minimum headcount.
                    Below that threshold the owner side governs per the organization's structure rules
                    (the live value and the scale are on the
                    <Link :href="organization.codet_href">co-determination page</Link>).
                </Banner>
            </Card>

            <!-- ===================================== joint chair ======== -->
            <Card as="section" title="Joint chair — elected by the entire board (RCV)">
                <p style="margin-block-end: var(--space-2)">
                    <HardenedChip>Chair elected jointly by the entire Board · Art. III §6</HardenedChip>
                </p>

                <!-- composition changed → a fresh chair election is required -->
                <Banner
                    v-if="chair?.pending_reason === 'composition_changed'"
                    tone="warning"
                    title="Composition changed — a fresh joint chair election is required before the board acts."
                >
                    Any composition change — a seat added by the scale, a vacancy, a transfer —
                    clears the chair and re-triggers the joint election by the full board ·
                    Art. III §6 · WF-ORG-05.
                </Banner>

                <template v-if="chair?.vote">
                    <p class="citation" style="margin-block: var(--space-2)">
                        majority of the full board:
                        <template v-if="chair.required != null">{{ chair.required }} of {{ chair.board_size }} seated</template>
                        <template v-else>the winner must reach a majority of all seated board seats</template>
                        — every seated seat casts, equal votes
                    </p>
                    <VoteTally
                        mode="unicameral"
                        threshold-class="rcv"
                        :serving="chair.vote.serving"
                        :required-yes="chair.vote.requiredYes"
                        :tallies="chair.vote.tallies"
                        :quorum="chair.vote.quorum"
                        :outcome="chair.vote.outcome"
                        basis="Art. III §6"
                    />

                    <!-- the round-by-round record (protected counting engine) -->
                    <template v-if="chairRounds.length">
                        <h3 style="margin-block-start: var(--space-3)">Round record (protected counting engine)</h3>
                        <div
                            v-for="round in chairRounds"
                            :key="round.round"
                            class="card card--inset"
                            style="margin-block-end: var(--space-2)"
                        >
                            <span class="eyebrow">
                                round {{ round.round }} — {{ round.action }}{{ round.subject ? ` · ${round.subject}` : '' }}
                            </span>
                            <p class="cc-small mono" data-no-i18n style="margin-block: var(--space-1) 0">
                                <template v-for="tally in round.tallies" :key="tally.member_id">
                                    {{ tally.name }} {{ tally.votes }} ·
                                </template>
                            </p>
                        </div>
                        <p v-if="chair.rounds?.winner" class="citation">
                            chair: {{ chair.rounds.winner }} — seated as joint chair · public record kind certification
                        </p>
                    </template>
                </template>

                <Banner v-else-if="chair && !chair.pending_reason" tone="info" role="status" title="No chair election on record yet.">
                    The joint chair election opens once the board has at least two seated members
                    (Art. III §6). It re-triggers on any composition change.
                </Banner>
            </Card>

            <!-- ===================================== seated board ======= -->
            <Card v-if="seated && seated.seats.length" as="section" title="The seated board">
                <BoardStrip
                    :seats="seated.seats"
                    :composition-valid="seated.compositionValid"
                    :required-worker-seats="seated.requiredWorkerSeats"
                />
                <p class="citation" style="margin-block-start: var(--space-3)">
                    Any composition change — a seat added by the scale, a vacancy, a transfer —
                    re-triggers the joint chair election · Art. III §6 · WF-ORG-04 → WF-ORG-05.
                </p>
            </Card>
            <Card v-else as="section" title="The seated board">
                <p class="gloss">
                    No seats filled yet — winners of the owner and worker tracks seat here, then the
                    board elects its chair.
                </p>
            </Card>
        </template>

        <template #about>
            <p>
                Board elections reuse the public-election engine end to end — owner and worker races
                carry an <code>electorate_type</code> (owners / workers) and run the same protected
                STV count. The chair is the one board-internal vote: a ranked-choice ballot of the
                entire seated board, won at a majority of all seated seats.
            </p>
        </template>
    </PageScaffold>
</template>
