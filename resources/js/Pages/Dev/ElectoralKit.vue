<script setup>
/**
 * Dev/ElectoralKit — FE-B1 fixture-first harness (/dev/electoral-kit,
 * dev-gated). Renders all 8 Electoral components in every state from
 * resources/js/fixtures/electoral.json (mockup-extracted; see the file's
 * `_source` provenance). NOT product UI — the per-page WIs (FE-B2…B8) wire
 * these against real props.
 *
 * Reference mockups: mockups/electoral/{open-ballot,ranked-ballot,results,
 * vacancy-countback,candidacy-registration,candidate-profile}.html.
 */
import { computed, reactive, ref } from 'vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import ChipToggle from '@/Components/Ui/ChipToggle.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ApproveSwitch from '@/Components/Electoral/ApproveSwitch.vue';
import BallotReceipt from '@/Components/Electoral/BallotReceipt.vue';
import CandidateRow from '@/Components/Electoral/CandidateRow.vue';
import FinalistLine from '@/Components/Electoral/FinalistLine.vue';
import PhaseBanner from '@/Components/Electoral/PhaseBanner.vue';
import RankList from '@/Components/Electoral/RankList.vue';
import StvBar from '@/Components/Electoral/StvBar.vue';
import StvRound from '@/Components/Electoral/StvRound.vue';
import fixtures from '@/fixtures/electoral.json';

defineProps({ surface: { type: Object, default: null } });

/* ---------------------------------------------------------- PhaseBanner */
const PHASES = ['approval', 'ranked', 'certifying'];

/* -------------------------------------------------------- ApproveSwitch */
const switchOn = ref(true);
const switchOff = ref(false);

/* --------------------------------------- CandidateRow + FinalistLine --- */
/* elec-manhattan-2031: 7 seats → finalist line at X = 21 of 24. */
const FINALISTS_X = 21;
const approvalPhase = ref(true); // flip to demo frozen/disabled switches
const orgsById = Object.fromEntries(fixtures.organizations.map((o) => [o.id, o]));
const myApprovals = reactive({});
const busyRows = reactive({});

const standings = computed(() =>
    [...fixtures.candidates]
        .sort((a, b) => b.approvals - a.approvals)
        .map((c, i) => ({
            rank: i + 1,
            approvals: c.approvals,
            delta: c.deltaDay ?? 0,
            candidacy: {
                id: c.id,
                name: c.name,
                statement: c.statement ?? null,
                position_tags: c.tags ?? [],
                incumbent: !!c.incumbent,
                profile_href: `/candidates/${c.id}`,
                endorsements: {
                    orgs: (c.endorsedBy ?? []).map((id) => ({
                        id,
                        name: orgsById[id].name,
                        type: orgsById[id].type,
                    })),
                    individual_count: c.individualEndorsements ?? 0,
                },
            },
        })),
);

/* Simulated parent flow: optimistic flip + brief busy. The AGGREGATE never
   moves on the viewer's action (secrecy — §A.2 delta note). */
function toggleApprove(candidacyId, next) {
    busyRows[candidacyId] = true;
    setTimeout(() => {
        myApprovals[candidacyId] = next;
        busyRows[candidacyId] = false;
    }, 250);
}
const myActiveApprovals = computed(
    () => Object.values(myApprovals).filter(Boolean).length,
);

/* --------------------------------------------------------- RankList ---- */
const SEATS = fixtures.rankedBallot.seats;
const ranking = ref([]);
const rankingLocked = ref(false);
const writeInPick = ref('');

const finalistRoster = computed(() =>
    fixtures.rankedBallot.finalists.map((name) => ({
        candidacy_id: name.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
        name,
        write_in: false,
        rankedAt: ranking.value.findIndex((e) => e.name === name),
    })),
);
const writeInsAvailable = computed(() =>
    fixtures.rankedBallot.writeIns.filter(
        (name) => !ranking.value.some((e) => e.name === name),
    ),
);
/* FE-C1: RankList items are { id, name, chips } now (write-in = chip). */
function addFinalist(entry) {
    ranking.value = [
        ...ranking.value,
        { id: entry.candidacy_id, name: entry.name, chips: [] },
    ];
}
function addWriteIn() {
    if (!writeInPick.value) return;
    ranking.value = [
        ...ranking.value,
        {
            id: writeInPick.value.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
            name: writeInPick.value,
            chips: ['write-in'],
        },
    ];
    writeInPick.value = '';
}
const guidance = computed(() =>
    ranking.value.length < SEATS
        ? `Rank for all ${SEATS} seats (or more) so your vote can transfer — ${SEATS - ranking.value.length} more recommended.`
        : 'All seats covered — extra ranks only help your vote transfer further.',
);

/* ------------------------------------------------------ BallotReceipt -- */
const SAMPLE_HASH =
    'a3f81c09bd2e554767f0a1b2c3d4e5f60718293a4b5c6d7e8f9012345678abcd';

/* ------------------------------------------------- StvBar / StvRound --- */
const stv = fixtures.stv;
const SCALE = stv.quota * 1.35; // mockup SCALE convention
const electedRound = Object.fromEntries(stv.elected.map((e) => [e.name, e.round]));
const keyRounds = stv.display.filter((r) => r.tallies);
const midRounds = stv.display.filter((r) => !r.tallies);
const openingRounds = keyRounds.slice(0, -1);
const finalRound = keyRounds[keyRounds.length - 1];
const midLabel = `Rounds ${midRounds[0].n}–${midRounds[midRounds.length - 1].n} — expand any round for its vote transfers`;
const profileHref = (id, name) =>
    `/candidates/${String(name).toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

/* Live aggregate (ranked-ballot mockup): scale = top × 1.3. */
const agg = fixtures.rankedBallot.liveAggregate;
const aggScale = agg.top[0][1] * 1.3;

/* Countback re-run (vacancy mockup bar()): removed → votes '—'. */
const CB_QUOTA = fixtures.countback.quota;
const CB_SCALE = CB_QUOTA * 1.35;
const countbackVariant = ref('found');
const countbackBars = computed(() =>
    fixtures.countback.rerun[countbackVariant.value].map((c) => ({
        name: c.name,
        votes: c.removed ? null : c.votes,
        elected: !!c.elected,
        eliminated: !!c.removed,
        transferFill: !!c.exhausted,
        chips: c.removed
            ? ['removed from the count']
            : c.elected
              ? ['reaches quota']
              : c.exhausted
                ? ['no remaining preference']
                : [],
    })),
);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            FE-B1 harness — the 8 Electoral components rendered from
            <code data-no-i18n>resources/js/fixtures/electoral.json</code>
            (mockup-extracted). Dev-gated; not product UI.
        </template>

        <Banner tone="demo" title="Fixture data only.">
            Nothing on this page touches the database — every state below is the
            mockups' world, frozen into a JSON fixture.
        </Banner>

        <!-- ============================================== 1. PhaseBanner -->
        <Card as="section" title="PhaseBanner — all phases × contexts">
            <p class="citation">Electoral/PhaseBanner · contexts open-ballot / registration / profile · frozen vocabulary approval | ranked | certifying</p>
            <div v-for="phase in PHASES" :key="phase" class="stack" style="gap: var(--space-2); margin-block-end: var(--space-4)">
                <h3>phase = {{ phase }}</h3>
                <p v-if="phase === 'approval'" class="gloss">approval renders nothing except the registration info banner:</p>
                <PhaseBanner :phase="phase" context="open-ballot" :links="{ rankedBallot: '#ranked', results: '#results' }" />
                <PhaseBanner :phase="phase" context="registration" />
                <PhaseBanner :phase="phase" context="profile" :is-finalist="true" />
                <PhaseBanner :phase="phase" context="profile" :is-finalist="false" />
            </div>
        </Card>

        <!-- ============================================ 2. ApproveSwitch -->
        <Card as="section" title="ApproveSwitch — states">
            <p class="citation">Electoral/ApproveSwitch · .switch · revocable, never color-only · Art. II §2</p>
            <div class="cluster">
                <ApproveSwitch v-model:pressed="switchOff" candidate-name="Diego Ramos" />
                <ApproveSwitch v-model:pressed="switchOn" candidate-name="Keisha Boyd" />
                <ApproveSwitch :pressed="false" candidate-name="Linh Pham" disabled />
                <ApproveSwitch :pressed="true" candidate-name="Robert Hale" disabled />
                <ApproveSwitch :pressed="false" candidate-name="Fatou Ndiaye" busy />
            </div>
            <p class="gloss">1–2 interactive (v-model) · 3–4 disabled with title "Approval phase is closed" · 5 busy (in-flight POST).</p>
        </Card>

        <!-- ========================== 3. CandidateRow + FinalistLine ==== -->
        <Card as="section" style="padding: 0">
            <div style="padding-block: var(--space-4) 0; padding-inline: var(--space-6)">
                <h2>
                    CandidateRow + FinalistLine — full Manhattan standings
                    <span class="citation">{{ approvalPhase ? 'aggregate · updated daily' : 'frozen at the finalist cutoff' }}</span>
                </h2>
                <div class="cluster" style="margin-block-end: var(--space-3)">
                    <ChipToggle v-model:pressed="approvalPhase">approval phase open</ChipToggle>
                    <Stat :value="myActiveApprovals" label="your active approvals (revocable)" />
                </div>
                <p class="gloss">
                    Toggling approve flips the switch and the stat — the public aggregate
                    NEVER moves on the viewer's action (daily cycle · ballot secrecy ·
                    Art. II §2). Line sits after full-race rank {{ FINALISTS_X }}.
                </p>
            </div>
            <div aria-live="polite">
                <template v-for="row in standings" :key="row.candidacy.id">
                    <FinalistLine v-if="row.rank === FINALISTS_X + 1" :count="FINALISTS_X" />
                    <CandidateRow
                        :candidacy="row.candidacy"
                        :rank="row.rank"
                        :approvals="row.approvals"
                        :delta="row.delta"
                        :approved="!!myApprovals[row.candidacy.id]"
                        :approvable="approvalPhase"
                        :busy="!!busyRows[row.candidacy.id]"
                        @toggle-approve="toggleApprove"
                    />
                </template>
            </div>
            <div style="padding: var(--space-4) var(--space-6)">
                <h3>Variants</h3>
                <CandidateRow
                    :candidacy="standings[0].candidacy"
                    :rank="1"
                    :approvals="standings[0].approvals"
                    :delta="0"
                    :show-switch="false"
                />
                <CandidateRow
                    :candidacy="standings[2].candidacy"
                    :rank="3"
                    :approvals="standings[2].approvals"
                    :delta="-4"
                    :approvable="false"
                >
                    <template #meta>
                        <StatusBadge tone="danger">withdrawn</StatusBadge>
                    </template>
                </CandidateRow>
                <p class="gloss">
                    Row 1: switch OMITTED (viewer not associated in the race jurisdiction —
                    browsing another county). Row 2: #meta slot ("withdrawn" badge) +
                    disabled switch.
                </p>
            </div>
        </Card>

        <!-- ================================================= 4. RankList -->
        <div class="grid-2">
            <Card as="section" title="Finalist roster (.roster-row)">
                <p class="citation">top {{ fixtures.rankedBallot.finalistCount }} from the approval phase · CLK-21</p>
                <div class="stack" style="gap: var(--space-1)">
                    <div v-for="entry in finalistRoster" :key="entry.candidacy_id" class="roster-row">
                        <span>
                            <a style="color: var(--gov-fg-strong)" :href="`/candidates/${entry.candidacy_id}`" :title="`${entry.name} — open public profile`">{{ entry.name }}</a>
                            <span v-if="entry.rankedAt >= 0" class="citation"> ranked #{{ entry.rankedAt + 1 }}</span>
                        </span>
                        <Btn variant="secondary" size="sm" :disabled="rankingLocked || entry.rankedAt >= 0" @click="addFinalist(entry)">Add</Btn>
                    </div>
                </div>
                <hr />
                <h3>Write-in</h3>
                <div class="cluster">
                    <label class="visually-hidden" for="writein-sel">Write in a validated candidate</label>
                    <select id="writein-sel" v-model="writeInPick" class="select" style="inline-size: auto">
                        <option value="" disabled>— validated non-finalists —</option>
                        <option v-for="name in writeInsAvailable" :key="name" :value="name">{{ name }}</option>
                    </select>
                    <Btn variant="secondary" size="sm" :disabled="rankingLocked || !writeInPick" @click="addWriteIn">Add write-in</Btn>
                </div>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>RankList — your ranking <span class="citation">{{ ranking.length }} ranked</span></h2>
                </template>
                <p class="gloss">{{ guidance }}</p>
                <RankList v-model="ranking" :seats="SEATS" :disabled="rankingLocked" />
                <div class="cluster">
                    <ChipToggle v-model:pressed="rankingLocked">post-commit lock (disabled)</ChipToggle>
                    <Btn variant="ghost" size="sm" :disabled="rankingLocked" @click="ranking = []">Clear</Btn>
                </div>
                <p class="gloss">
                    Keyboard pass: ↑/↓ buttons keep focus on the moved item's control;
                    Alt+ArrowUp/Down moves from any control in the row; remove focuses the
                    next row's remove. Moves and removals announce via the polite live
                    region.
                </p>
            </Card>
        </div>

        <!-- ============================================ 5. BallotReceipt -->
        <Card as="section" title="BallotReceipt — full / compact / non-copyable">
            <p class="citation">F-IND-007 receipt · shown once · cryptographic separation of voter identity from ballot · Art. II §2</p>
            <BallotReceipt :hash="SAMPLE_HASH" results-href="#results" />
            <hr />
            <BallotReceipt :hash="SAMPLE_HASH" compact>Referendum vote committed · receipt</BallotReceipt>
            <hr />
            <BallotReceipt :hash="SAMPLE_HASH" :copyable="false" />
        </Card>

        <!-- ================================================== 6. StvBar -->
        <Card as="section" title="StvBar — standalone states">
            <p class="citation">Electoral/StvBar · .stv-cand family · gold tick = Droop quota</p>
            <span class="visually-hidden">Droop quota {{ stv.quota.toLocaleString() }}</span>
            <StvBar name="Rita Alvarez" :votes="28454" :quota="stv.quota" :scale="SCALE" href="/candidates/rita-alvarez" :quota-title="`Droop quota ${stv.quota.toLocaleString()}`" />
            <StvBar name="Aisha Diop" :votes="41943" :quota="stv.quota" :scale="SCALE" elected badge="r27" href="/candidates/aisha-diop" :quota-title="`Droop quota ${stv.quota.toLocaleString()}`" />
            <StvBar name="Tanya Brooks" :votes="5224" :quota="stv.quota" :scale="SCALE" eliminated :quota-title="`Droop quota ${stv.quota.toLocaleString()}`" />
            <StvBar name="Quinn Avery" :votes="16999" :quota="stv.quota" :scale="SCALE" write-in href="/candidates/quinn-avery" :quota-title="`Droop quota ${stv.quota.toLocaleString()}`" />
            <StvBar name="Felipe Ortiz" :votes="1650" :scale="5224" transfer-fill arrow />
            <StvBar name="Renata Silva" :votes="null" :quota="CB_QUOTA" :scale="CB_SCALE" eliminated :chips="['removed from the count']" quota-title="Droop quota 28,755" />

            <h3 style="margin-block-start: var(--space-4)">Live aggregate (ranked window)</h3>
            <p class="citation">{{ agg.ballotsSoFar.toLocaleString() }} ballots so far · Droop quota if closed now: {{ agg.quotaIfClosedNow.toLocaleString() }}</p>
            <StvBar
                v-for="[name, votes] in agg.top"
                :key="name"
                :name="name"
                :votes="votes"
                :quota="agg.quotaIfClosedNow"
                :scale="aggScale"
                :elected="votes >= agg.quotaIfClosedNow"
                quota-title="Quota if closed now"
            />
            <p class="cc-small" style="margin-block-start: var(--space-2)">{{ agg.remainderNote }}</p>
        </Card>

        <!-- ================================== 7. StvRound — full count === -->
        <Card as="section" title="StvRound — the Queens count, round by round (27 rounds)">
            <div class="cluster" style="gap: var(--space-6); margin-block-end: var(--space-4)">
                <Stat :value="stv.total.toLocaleString()" label="valid ballots" />
                <Stat :value="stv.quota.toLocaleString()" label="Droop quota = floor(votes ÷ (seats+1)) + 1" accent />
                <Stat :value="stv.seats" label="seats — all filled in one count" />
                <Stat :value="stv.rounds" label="counting rounds" />
            </div>
            <p class="gloss">
                Gold tick = the Droop quota. Reaching it elects a candidate; their surplus
                transfers onward at fractional value so no vote is wasted.
            </p>
            <span class="visually-hidden">Droop quota {{ stv.quota.toLocaleString() }}</span>

            <StvRound
                v-for="round in openingRounds"
                :key="round.n"
                :round="round"
                :quota="stv.quota"
                :scale="SCALE"
                :elected-round="electedRound"
                :profile-href="profileHref"
            />

            <details class="about-surface" style="margin-block: var(--space-4)">
                <summary>{{ midLabel }}</summary>
                <div class="about-surface-body">
                    <StvRound
                        v-for="round in midRounds"
                        :key="round.n"
                        :round="round"
                        :quota="stv.quota"
                        :scale="SCALE"
                        :elected-round="electedRound"
                        :profile-href="profileHref"
                    />
                </div>
            </details>

            <StvRound
                :round="finalRound"
                :quota="stv.quota"
                :scale="SCALE"
                :elected-round="electedRound"
                :profile-href="profileHref"
                default-open
            />
            <p class="citation" style="margin-block-start: var(--space-3)">STV with Droop quota · fractional (Gregory) surplus transfers · hardened · Art. II §2</p>
        </Card>

        <!-- =========================================== 8. Countback bars -->
        <Card as="section" title="Countback re-run (StvBar reuse)">
            <p class="citation">Re-run of prior ballots with the vacated member removed · Art. II §5 · universal — no faction filtering</p>
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <ChipToggle :pressed="countbackVariant === 'found'" @update:pressed="countbackVariant = 'found'">winner found</ChipToggle>
                <ChipToggle :pressed="countbackVariant === 'failed'" @update:pressed="countbackVariant = 'failed'">ballots exhausted</ChipToggle>
                <StatusBadge v-if="countbackVariant === 'found'" tone="success" icon="check">Winner found — Camille Verhoeven</StatusBadge>
                <StatusBadge v-else tone="danger" icon="alert-triangle">Countback failed — ballots exhausted</StatusBadge>
            </div>
            <span class="visually-hidden">Droop quota {{ CB_QUOTA.toLocaleString() }}</span>
            <StvBar
                v-for="bar in countbackBars"
                :key="bar.name"
                :name="bar.name"
                :votes="bar.votes"
                :quota="CB_QUOTA"
                :scale="CB_SCALE"
                :elected="bar.elected"
                :eliminated="bar.eliminated"
                :transfer-fill="bar.transferFill"
                :chips="bar.chips"
                quota-title="Droop quota 28,755"
            />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Struck member: eliminated styling + "removed from the count" + votes "—".
                Winner: elected + "reaches quota". Exhausted ballots: gold fill + "no
                remaining preference".
            </p>
        </Card>
    </PageScaffold>
</template>
