<script setup>
/**
 * Elections/RankedBallot — FE-B5 (PHASE_B_DESIGN_frontend.md §B.5 + §D).
 *
 * The §D commitment flow: rank (page-local only — nothing persists before
 * commit; closing the tab loses the draft, stated in the guidance gloss) →
 * review (distinct visual register) → commit (F-IND-007 through the
 * engine) → receipt (session flash, shown exactly once; the marked ranking
 * is REMOVED from screen — shoulder-surf window).
 *
 * Already-voted state: the envelope check renders the committed card with
 * the receipt-verify input (POST /receipt-check — anonymized lookup).
 * Double vote in a second tab → the envelope unique 422s through the
 * engine; the page reloads into the already-voted state. Window close
 * mid-session → engine 422 with citation; a 30s client timer additionally
 * disables the commit button (UX only — never the boundary).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';
import Field from '@/Components/Ui/Field.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import BallotReceipt from '@/Components/Electoral/BallotReceipt.vue';
import RankList from '@/Components/Electoral/RankList.vue';
import StvBar from '@/Components/Electoral/StvBar.vue';
import { useAnnounce } from '@/composables/useAnnounce';

const props = defineProps({
    surface: { type: Object, required: true },
    race: { type: Object, required: true },
    finalists: { type: Array, default: () => [] },
    writeInsAvailable: { type: Number, default: 0 },
    alreadyVoted: { type: Object, default: null },
    referendum: { type: Object, default: null },
    referendumVoted: { type: Boolean, default: false },
    liveAggregate: { type: Object, default: null },
    machine: { type: Array, default: () => [] },
    /** Optional prop — present only after a write-in search partial reload. */
    writeInMatches: { type: Array, default: () => [] },
});

const page = usePage();
const { announce } = useAnnounce();

const flashStatus = computed(() => page.props.flash?.status ?? null);
const receiptHash = computed(() => page.props.flash?.receipt_hash ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* ------------------------------------------------------------- state -- */

const isRanked = computed(() => props.race.phase === 'ranked');
const committedNow = computed(() => receiptHash.value !== null);
const votedEarlier = computed(() => props.alreadyVoted !== null && !committedNow.value);
const showBallotArea = computed(() => isRanked.value && !committedNow.value && !votedEarlier.value);

/* Client-side window-close disable (UX only — the engine is the boundary). */
const windowClosed = ref(false);
let closeTimer = null;
function checkWindow() {
    if (!props.race.ranked_closes_at) return;
    windowClosed.value = Date.now() >= new Date(props.race.ranked_closes_at).getTime();
}
onMounted(() => {
    checkWindow();
    closeTimer = setInterval(checkWindow, 30_000);
});
onBeforeUnmount(() => clearInterval(closeTimer));

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/* ------------------------------------------------------- rank → review -- */

const ranking = ref([]); // [{ candidacy_id, name, write_in }] — page-local ONLY (§D.1)
const reviewing = ref(false);
const committing = ref(false);
const reviewCard = ref(null);

const rankedIds = computed(() => new Set(ranking.value.map((e) => e.candidacy_id)));
const rankIndex = (id) => ranking.value.findIndex((e) => e.candidacy_id === id);

const guidance = computed(() =>
    ranking.value.length < props.race.seats
        ? `Rank for all ${props.race.seats} seats (or more) so your vote can transfer — ` +
          `${props.race.seats - ranking.value.length} more recommended. ` +
          'Nothing is saved until you commit; closing this tab loses the draft.'
        : 'All seats covered — extra ranks only help your vote transfer further. ' +
          'Nothing is saved until you commit; closing this tab loses the draft.',
);

function addFinalist(entry) {
    if (rankedIds.value.has(entry.candidacy_id)) return;
    ranking.value = [...ranking.value, { candidacy_id: entry.candidacy_id, name: entry.name, write_in: false }];
}

function review() {
    if (ranking.value.length === 0) return;
    reviewing.value = true;
    requestAnimationFrame(() => reviewCard.value?.$el?.scrollIntoView?.({ block: 'nearest' }));
}

function commit() {
    committing.value = true;
    router.post(
        `/elections/${props.race.election_id}/races/${props.race.id}/ballots`,
        { rankings: ranking.value.map((e) => e.candidacy_id) },
        {
            preserveScroll: true,
            onSuccess: () => {
                /* §D.4 — remove the marked ranking from screen entirely. */
                ranking.value = [];
                reviewing.value = false;
                announce('Ballot committed — copy your receipt now; it is shown once.');
            },
            onFinish: () => {
                committing.value = false;
            },
        },
    );
}

/* -------------------------------------------------- write-in search ---- */

const writeInQuery = ref('');
const searching = ref(false);
let searchTimer = null;

watch(writeInQuery, (q) => {
    clearTimeout(searchTimer);
    if (!q || q.trim().length < 2) return;
    searching.value = true;
    searchTimer = setTimeout(() => {
        router.reload({
            only: ['writeInMatches'],
            data: { wq: q.trim() },
            onFinish: () => {
                searching.value = false;
            },
        });
    }, 300);
});

const writeInResults = computed(() =>
    (props.writeInMatches ?? []).filter((m) => !rankedIds.value.has(m.candidacy_id)),
);

function addWriteIn(match) {
    ranking.value = [...ranking.value, { candidacy_id: match.candidacy_id, name: match.name, write_in: true }];
    announce(`${match.name} added as a write-in — rank ${ranking.value.length}`);
}

/* ------------------------------------------------- receipt self-check -- */

const checkHash = ref('');
const checkBusy = ref(false);
const checkResult = ref(null);

async function runReceiptCheck() {
    const hash = checkHash.value.trim();
    if (!hash) return;
    checkBusy.value = true;
    checkResult.value = null;
    try {
        const res = await fetch('/receipt-check', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ hash }),
        });
        checkResult.value = await res.json();
    } catch {
        checkResult.value = { found: false, message: 'Could not reach the receipt check — try again.' };
    } finally {
        checkBusy.value = false;
    }
}

const checkLine = computed(() => {
    const r = checkResult.value;
    if (!r) return null;
    if (r.found) {
        return `Found — committed ${fmt(r.cast_bucket)} (hour bucket), counted: ${r.counted ? 'yes' : 'no'}`;
    }
    return r.message ?? 'Not found — check for typos; hashes are 64 characters.';
});

/* ----------------------------------------------------- live aggregate -- */

const aggScale = computed(() =>
    props.liveAggregate?.top?.length ? props.liveAggregate.top[0][1] * 1.3 : 1,
);
</script>

<template>
    <PageScaffold :surface="surface" :title="`Ranked ballot — ${race.label}`">
        <template #intro>
            {{ race.seats }} {{ race.seats === 1 ? 'seat' : 'seats' }},
            {{ finalists.length }} finalists, one count. Rank as many candidates as you like —
            in a multi-winner district, ranking for <strong>all seats</strong> keeps your vote
            alive through surplus and elimination transfers. Your ballot is secret; your
            receipt hash lets you verify it was counted.
        </template>

        <p class="citation">
            Ballot submission (ranked choice) · F-IND-007 · available to R-04 Voter · Art. II §2
        </p>
        <p v-if="race.ranked_closes_at" class="citation">
            Window closes {{ fmt(race.ranked_closes_at) }} <span data-no-i18n>·</span>
            shown in your timezone · stored as UTC
        </p>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner tone="info" title="How the count works">
            Instant multi-winner PR-STV with the Droop quota: every candidate reaching the
            quota is elected, surpluses transfer at fractional value (Gregory method), and all
            {{ race.seats }} {{ race.seats === 1 ? 'seat fills' : 'seats fill' }} in one count.
            <span class="gloss">
                Droop quota — the smallest vote total that only {{ race.seats }} candidates can
                all reach: floor(votes ÷ (seats + 1)) + 1.
            </span>
            <CitationLine text="STV with Droop quota · hardened · Art. II §2" />
            {{ ' ' }}
            <HardenedChip>hardened</HardenedChip>
        </Banner>

        <!-- ============================== window closed (phase ≠ ranked) -->
        <Banner
            v-if="!isRanked && !committedNow"
            tone="warning"
            role="status"
            icon="clock"
            title="The ranked window is not open."
        >
            <template v-if="race.phase === 'approval'">
                The approval phase is still under way — finalists lock at the cutoff, then the
                ranked window opens.
                <Link :href="`/elections/${race.election_id}/open-ballot`">Browse the open ballot</Link>.
            </template>
            <template v-else>
                Voting has closed for this race.
                <Link :href="`/elections/${race.election_id}/results`">Watch the count</Link>.
            </template>
            <CitationLine text="CLK-18 · CLK-21 · Art. II §2" />
        </Banner>

        <!-- ======================================= committed just now ==== -->
        <Card v-if="committedNow" as="section" title="Ballot committed">
            <p>
                Your receipt hash — keep it to verify your ballot was tabulated (the lookup is
                anonymized):
            </p>
            <BallotReceipt
                :hash="receiptHash"
                :results-href="`/elections/${race.election_id}/results`"
            />
            <p class="citation" style="margin-block-start: var(--space-3)">
                Public chain of custody — endorsing organizations and candidates can observe and
                audit the count · Art. II §2
            </p>
            <p class="citation">
                Ballot machine: {{ machine.join(' → ') }} — the receipt hash is your handle on
                the counted state.
            </p>
        </Card>

        <!-- ======================================= already voted ========= -->
        <Card v-if="votedEarlier" as="section" title="Your ballot is in the count">
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <StatusBadge tone="success" icon="check">
                    Ballot committed · {{ fmt(alreadyVoted.committed_at) }}
                </StatusBadge>
            </div>
            <p>
                Your ballot is in the count. Your receipt hash was shown once at commit; it
                cannot be re-issued — by you or by anyone.
            </p>
            <StateStrip :states="machine" current="Committed" />
            <hr />
            <h3>Verify a receipt</h3>
            <Field
                label="Paste a receipt hash"
                hint="Anonymized lookup — anyone may check any hash against the public count record."
                :error="null"
            >
                <template #control="{ id, describedBy }">
                    <input
                        :id="id"
                        v-model="checkHash"
                        class="field-input"
                        data-no-i18n
                        autocomplete="off"
                        spellcheck="false"
                        :aria-describedby="describedBy"
                        @keydown.enter.prevent="runReceiptCheck"
                    />
                </template>
            </Field>
            <div class="cluster">
                <Btn variant="secondary" size="sm" :disabled="checkBusy || !checkHash.trim()" @click="runReceiptCheck">
                    {{ checkBusy ? 'Checking…' : 'Check receipt' }}
                </Btn>
                <span v-if="checkLine" class="citation" role="status" data-no-i18n>{{ checkLine }}</span>
            </div>
        </Card>

        <!-- ======================================= the ballot area ======= -->
        <div v-if="showBallotArea" id="ballot-area" class="grid-2">
            <Card as="section">
                <template #title>
                    <h2>
                        Finalists
                        <span class="citation">top {{ race.finalist_count }} from the approval phase · CLK-21</span>
                    </h2>
                </template>
                <div class="stack" style="gap: var(--space-1)">
                    <div v-for="entry in finalists" :key="entry.candidacy_id" class="roster-row">
                        <span>
                            <Link
                                style="color: var(--gov-fg-strong)"
                                :href="entry.profile_href"
                                :title="`${entry.name} — open public profile`"
                            >{{ entry.name }}</Link>
                            <span v-if="rankIndex(entry.candidacy_id) >= 0" class="citation">
                                ranked #{{ rankIndex(entry.candidacy_id) + 1 }}
                            </span>
                        </span>
                        <Btn
                            variant="secondary"
                            size="sm"
                            :disabled="rankedIds.has(entry.candidacy_id)"
                            @click="addFinalist(entry)"
                        >Add</Btn>
                    </div>
                </div>
                <hr />
                <h3>Write-in</h3>
                <p class="cc-small">
                    Any validated candidate may be written in, finalist or not —
                    {{ writeInsAvailable }} validated non-finalists remain write-in eligible in
                    this race.
                </p>
                <p class="citation">Your right to stand and to vote for anyone is preserved · Art. II §2</p>
                <Field
                    label="Write in a validated candidate"
                    hint="Search by name — write-ins are search-driven, never enumerated."
                >
                    <template #control="{ id, describedBy }">
                        <input
                            :id="id"
                            v-model="writeInQuery"
                            class="field-input"
                            autocomplete="off"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>
                <p v-if="searching" class="gloss">Searching…</p>
                <div v-else-if="writeInQuery.trim().length >= 2" class="stack" style="gap: var(--space-1)">
                    <div v-for="match in writeInResults" :key="match.candidacy_id" class="roster-row">
                        <span>{{ match.name }} <span class="citation">{{ match.status }}</span></span>
                        <Btn variant="secondary" size="sm" @click="addWriteIn(match)">Add write-in</Btn>
                    </div>
                    <p v-if="writeInResults.length === 0" class="gloss">
                        No validated candidates match “{{ writeInQuery.trim() }}”.
                    </p>
                </div>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>
                        Your ranking
                        <span class="citation">{{ ranking.length }} ranked</span>
                    </h2>
                </template>
                <p class="gloss">{{ guidance }}</p>
                <RankList v-model="ranking" :seats="race.seats" />
                <div class="cluster">
                    <Btn variant="primary" :disabled="ranking.length === 0" @click="review">Review ballot</Btn>
                    <Btn variant="ghost" size="sm" :disabled="ranking.length === 0" @click="ranking = []; reviewing = false">Clear</Btn>
                </div>
            </Card>
        </div>

        <!-- ======================================= review & commit ======= -->
        <Card v-if="showBallotArea && reviewing" ref="reviewCard" as="section" title="Review &amp; commit">
            <ol>
                <li v-for="entry in ranking" :key="entry.candidacy_id">
                    {{ entry.name }}<template v-if="entry.write_in"> (write-in)</template>
                </li>
            </ol>
            <p class="cc-small">
                Committing encrypts your ballot and separates it cryptographically from your
                identity. You will receive a receipt hash for self-audit; nobody can connect it
                back to you.
            </p>
            <p class="citation">
                Cryptographic separation of voter identity from ballot · Art. II §2 · Ensure
                Election Security and Integrity
            </p>
            <Banner v-if="windowClosed" tone="warning" role="status" icon="clock">
                The ranked window has closed — the engine will reject this ballot
                (Art. II §2).
            </Banner>
            <div class="cluster">
                <Btn variant="gold" :disabled="committing || windowClosed" @click="commit">
                    {{ committing ? 'Committing…' : 'Commit ballot' }}
                </Btn>
                <Btn variant="ghost" size="sm" @click="reviewing = false">Keep editing</Btn>
            </div>
        </Card>

        <!-- ======================================= live aggregate ======== -->
        <Card v-if="liveAggregate" as="section" title="Live aggregate — if the window closed now">
            <p class="cc-small">
                Standings stay visible through the ranked window: first preferences counted so
                far, as if the window closed this minute.
            </p>
            <p class="citation">
                {{ liveAggregate.ballotsSoFar.toLocaleString() }} ballots so far · Droop quota if
                closed now: {{ liveAggregate.quotaIfClosedNow.toLocaleString() }}
            </p>
            <StvBar
                v-for="[name, votes] in liveAggregate.top"
                :key="name"
                :name="name"
                :votes="votes"
                :quota="liveAggregate.quotaIfClosedNow"
                :scale="aggScale"
                :elected="votes >= liveAggregate.quotaIfClosedNow"
                quota-title="Quota if closed now"
            />
            <p v-if="liveAggregate.remainderNote" class="cc-small" style="margin-block-start: var(--space-2)">
                {{ liveAggregate.remainderNote }}
            </p>
            <p class="citation">
                Projection only — surpluses and eliminations transfer at the close · full count on
                the <Link :href="`/elections/${race.election_id}/results`">results page</Link> ·
                Art. II §2
            </p>
        </Card>

        <!-- ======================================= referendum slot ======= -->
        <Card v-if="referendum" as="section" title="Referendum question on this ballot">
            <p class="citation">Referendum vote · F-IND-008 · available to R-04 Voter · Art. II §6</p>
            <Card inset>
                <p><strong>{{ referendum.title }}</strong> {{ referendum.text }}</p>
                <p class="citation">
                    Delegated by supermajority act · F-LEG-023 · passes at the threshold matching
                    the act type · Art. II §6
                </p>
                <p class="gloss">Referendum content arrives with Phase C.</p>
            </Card>
        </Card>

        <template #about>
            <p>
                <strong>Interaction note:</strong> ranking is click-to-rank with ↑/↓ — keyboard
                operable, no drag required. The ballot machine is
                {{ machine.join(' → ') }}.
            </p>
        </template>
    </PageScaffold>
</template>
