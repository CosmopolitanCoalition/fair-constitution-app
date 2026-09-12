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
import StateStrip from '@/Components/Ui/StateStrip.vue';
import OrganizationNav from '@/Components/Organizations/OrganizationNav.vue';
import ReferenceText from '@/Components/Ui/ReferenceText.vue';
import { useI18n } from 'vue-i18n';

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
    /** The open-nomination window dial (org setting) — read-only on this surface. */
    nominationWindow: { type: Object, default: () => ({ window_days: null, is_set: false, min: 1, max: 90, settings_href: '#' }) },
});

const page = usePage();
const { t } = useI18n();
const text = (key, fallback) => t('c_references.board_elections.' + key, fallback);
const isCgc = computed(() => Boolean(props.organization.is_cgc));
const ownerSeatLabel = computed(() => isCgc.value
    ? text('governor_seats', 'Appointed governor seats')
    : text('owner_seats', 'Owner-elected seats'));
const intro = computed(() => isCgc.value
    ? text('cgc_intro', 'Appointed governors and elected worker representatives serve together on this board. Follow worker elections, view the seated members, and see how the entire board elects its chair.')
    : text('intro', 'Follow elections for the owner and worker seats, view the seated members, and see how the entire board elects its chair. Open an active election to vote on its ballot.'));
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

/* ------------------------------------ open-nomination window (v3.2 0d) -- */
const WINDOW_PHASES = ['nominations', 'ranking', 'count'];
const WINDOW_LABELS = {
    nominations: 'Nominations open',
    ranking: 'Ranking open',
    count: 'Count & seating',
};

/* A one-line date summary off the election row. The dates are engine
   snapshots (the controller read them straight off the row); this only
   formats what it was handed. */
function windowDates(nom) {
    if (!nom) return null;
    const d = (iso) => (iso ? new Date(iso).toLocaleDateString() : null);
    const parts = [];
    if (d(nom.nominations_open_at) && d(nom.nominations_close_at)) {
        parts.push(`nominations ${d(nom.nominations_open_at)} → ${d(nom.nominations_close_at)}`);
    }
    if (d(nom.ranking_open_at) && d(nom.ranking_close_at)) {
        parts.push(`ranking ${d(nom.ranking_open_at)} → ${d(nom.ranking_close_at)}`);
    }
    return parts.length ? parts.join(' · ') : null;
}

/* One phase strip per track that has an election on record. */
const nominationStrips = computed(() => {
    const out = [];
    for (const [key, label, track] of [
        ['owner', 'Owner track', props.ownerTrack],
        ['worker', 'Worker track', props.workerTrack],
    ]) {
        if (track?.nomination) {
            out.push({ key, label, phase: track.nomination.phase, dates: windowDates(track.nomination) });
        }
    }
    return out;
});
</script>

<template>
    <PageScaffold :surface="surface" :title="`Board elections — ${organization.name}`">
        <template #intro>
            {{ intro }}
        </template>

        <OrganizationNav :organization="organization" current="board" />

        <Banner v-if="flashStatus" tone="info" role="status"><ReferenceText>{{ flashStatus }}</ReferenceText></Banner>
        <Banner v-if="constitutionError" tone="emergency"><ReferenceText>{{ constitutionError }}</ReferenceText></Banner>

        <!-- ===================================== no board yet =========== -->
        <Card v-if="!composition" as="section" title="No board constituted">
            <Banner tone="info" role="status" title="This organization has no board yet.">
                {{ isCgc
                    ? text('cgc_no_board', 'No governing board has been established for this organization yet. Governor appointments and worker elections will appear here when available.')
                    : text('no_board', 'No governing board has been established for this organization yet. Worker seats become available as the workforce reaches the required size.') }}
            </Banner>
        </Card>

        <template v-else>
            <!-- ====================================== stat cluster ====== -->
            <Card as="section" title="The board">
                <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                    <Stat :value="composition.ownerSeats" :label="ownerSeatLabel" />
                    <Stat :value="composition.workerSeats" :label="text('worker_seats', 'Worker-elected seats')" accent />
                    <Stat :value="composition.chair?.name ?? 'Unfilled'" :label="text('chair', 'Board chair')" />
                </div>
                <p v-if="!composition.compositionValid" style="margin-block-start: var(--space-3)">
                    <StatusBadge tone="warning" icon="alert-triangle">
                        Composition invalid — a worker-track election is required before the board acts
                    </StatusBadge>
                </p>
            </Card>

            <!-- ============================== nomination window ========= -->
            <Card as="section" :title="text('nominations', 'Nominations and schedule')">
                <p>
                    {{ text('nominations_intro', 'Candidates are nominated before voting begins. Each scheduled election shows its nomination, ranking, and counting stages below.') }}
                </p>

                <div class="lr-note" style="margin-block: var(--space-3)">
                    <div>
                        <template v-if="nominationWindow.is_set">
                            {{ t('c_references.board_elections.nomination_days', { count: nominationWindow.window_days }, 'Nominations stay open for {count} days.') }}
                        </template>
                        <template v-else>
                            {{ text('default_schedule', 'This organization uses the jurisdiction’s election schedule.') }}
                        </template>
                        {{ ' ' }}
                        <Link :href="nominationWindow.settings_href">{{ text('settings', 'Organization settings') }}</Link>
                    </div>
                </div>

                <template v-if="nominationStrips.length">
                    <div
                        v-for="strip in nominationStrips"
                        :key="strip.key"
                        style="margin-block-start: var(--space-3)"
                    >
                        <span class="eyebrow">{{ strip.label }}</span>
                        <StateStrip :states="WINDOW_PHASES" :current="strip.phase" :labels="WINDOW_LABELS" />
                        <p
                            v-if="strip.dates"
                            class="citation"
                            data-no-i18n
                            style="margin-block-start: var(--space-1)"
                        >
                            {{ strip.dates }}
                        </p>
                    </div>
                </template>
                <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                    No board election is scheduled yet — the nomination → ranking → count phases
                    appear here once a track opens.
                </p>
            </Card>

            <!-- ======================================= owner track ====== -->
            <Card as="section" :title="isCgc ? text('appointments_records', 'Governor appointments and election records') : text('owner_election', 'Owner-seat election')">
                <p class="citation">
                    {{ isCgc
                        ? text('appointments_explained', 'Appointed governors hold the common-good side of this board. The seated board shows the current appointments; any election records below are preserved for reference.')
                        : text('owner_election_explained', 'Eligible owners or members elect these seats by proportional ranked-choice voting, according to the organization’s structure.') }}
                </p>

                <div class="cluster" style="gap: var(--space-4); margin-block: var(--space-2)">
                    <Stat v-if="!isCgc || ownerTrack.election" :value="ownerTrack.electorate_count" :label="text('eligible_owners', 'Eligible owners or members')" />
                    <Stat
                        v-if="ownerTrack.result"
                        :value="ownerTrack.result.quota.toLocaleString()"
                        :label="text('quota', 'Votes needed for election')"
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
                        certified {{ new Date(ownerTrack.result.certified_at).toLocaleString() }}
                    </p>
                </template>
                <p v-else-if="!ownerTrack.election" class="gloss" style="margin-block-start: var(--space-2)">
                    {{ isCgc
                        ? text('no_owner_election_record', 'No owner-seat election is on record. Governor appointments appear in the seated board below.')
                        : text('no_owner_election', 'No owner-seat election is on record yet.') }}
                </p>

                <!-- administration (R-23) -->
                <div v-if="can.administerOwner && !ownerTrack.election?.live" class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn variant="primary" size="sm" :disabled="ownerForm.processing" @click="scheduleOwner">
                        {{ text('schedule_owner', 'Schedule owner-seat election') }}
                    </Btn>
                </div>
            </Card>

            <!-- ===================================== worker track ======= -->
            <Card as="section" :title="text('worker_election', 'Worker-seat election')">
                <template v-if="workerTrack.exists">
                    <p class="citation">
                        {{ t('c_references.board_elections.worker_seats_explained', { count: composition.workerSeats }, 'The workforce currently has {count} board seats.') }}
                        <Link :href="organization.codet_href">{{ text('worker_representation', 'Worker representation') }}</Link>
                    </p>

                    <div class="cluster" style="gap: var(--space-4); margin-block: var(--space-2)">
                        <Stat :value="workerTrack.electorate_count" :label="text('eligible_workers', 'Eligible workers')" />
                        <Stat
                            v-if="workerTrack.result"
                            :value="workerTrack.result.quota.toLocaleString()"
                            :label="text('quota', 'Votes needed for election')"
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
                            certified {{ new Date(workerTrack.result.certified_at).toLocaleString() }}
                        </p>
                    </template>
                    <p v-else-if="!workerTrack.election" class="gloss" style="margin-block-start: var(--space-2)">
                        {{ text('no_worker_election', 'No worker-seat election is on record yet. An election opens when growth in the workforce requires a new seat.') }}
                    </p>

                    <div v-if="can.administerWorker && !workerTrack.election?.live" class="cluster" style="margin-block-start: var(--space-3)">
                        <Btn variant="primary" size="sm" :disabled="workerForm.processing" @click="scheduleWorker">
                            {{ text('schedule_worker', 'Schedule worker-seat election') }}
                        </Btn>
                    </div>
                </template>

                <!-- below the threshold: no worker track at all -->
                <Banner v-else tone="info" role="status" :title="text('no_worker_seats', 'No worker seats yet')">
                    {{ text('worker_threshold', 'Worker seats become available when the workforce reaches the required size. See the current workforce and thresholds on') }}
                    <Link :href="organization.codet_href">{{ text('worker_representation', 'Worker representation') }}</Link>.
                </Banner>
            </Card>

            <!-- ===================================== joint chair ======== -->
            <Card as="section" :title="text('chair_election', 'Board chair election')">
                <p style="margin-block-end: var(--space-2)">
                    <HardenedChip>Chair elected jointly by the entire Board · Art. III §6</HardenedChip>
                </p>

                <!-- composition changed → a fresh chair election is required -->
                <Banner
                    v-if="chair?.pending_reason === 'composition_changed'"
                    tone="warning"
                    title="Composition changed — a fresh joint chair election is required before the board acts."
                >
                    {{ text('chair_re_election', 'When the board’s membership changes, the entire board elects its chair again.') }}
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
                        <h3 style="margin-block-start: var(--space-3)">{{ text('count_rounds', 'Counting rounds') }}</h3>
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
                            {{ text('chair', 'Board chair') }}: {{ chair.rounds.winner }}
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
                    {{ text('chair_re_election', 'When the board’s membership changes, the entire board elects its chair again.') }}
                </p>
            </Card>
            <Card v-else as="section" title="The seated board">
                <p class="gloss">
                    {{ text('no_seated_members', 'No seats have been filled yet. Members appear here after their election or appointment.') }}
                </p>
            </Card>
        </template>

        <template #about>
            <p>
                {{ text('elections_explained', 'Board-seat elections use proportional ranked-choice voting. All seated board members take part in choosing the chair, who must receive a majority of the full board.') }}
            </p>
        </template>
    </PageScaffold>
</template>
