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
import { useI18n } from 'vue-i18n';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
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

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

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

const { t } = useI18n();
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

/* [{ id: candidacy_id, name, write_in, chips }] — page-local ONLY (§D.1).
   FE-C1: RankList generalized to { id, name, chips } (write-in renders as
   a chip); write_in stays for the review-card suffix + commit semantics. */
const ranking = ref([]);
const reviewing = ref(false);
const committing = ref(false);
const reviewCard = ref(null);

const rankedIds = computed(() => new Set(ranking.value.map((e) => e.id)));
const rankIndex = (id) => ranking.value.findIndex((e) => e.id === id);

const guidance = computed(() =>
    ranking.value.length < props.race.seats
        ? t('c_elections.ranked.guidance_more', 'Rank for all {seats} seats (or more) so your vote can transfer — {more} more recommended. Nothing is saved until you commit; closing this tab loses the draft.', { seats: props.race.seats, more: props.race.seats - ranking.value.length })
        : t('c_elections.ranked.guidance_full', 'All seats covered — extra ranks only help your vote transfer further. Nothing is saved until you commit; closing this tab loses the draft.'),
);

function addFinalist(entry) {
    if (rankedIds.value.has(entry.candidacy_id)) return;
    ranking.value = [...ranking.value, { id: entry.candidacy_id, name: entry.name, write_in: false, chips: [] }];
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
        { rankings: ranking.value.map((e) => e.id) },
        {
            preserveScroll: true,
            onSuccess: () => {
                /* §D.4 — remove the marked ranking from screen entirely. */
                ranking.value = [];
                reviewing.value = false;
                announce(t('c_elections.ranked.announce_committed', 'Ballot committed — copy your receipt now; it is shown once.'));
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
    ranking.value = [
        ...ranking.value,
        { id: match.candidacy_id, name: match.name, write_in: true, chips: [t('c_elections.ranked.writein_chip', 'write-in')] },
    ];
    announce(t('c_elections.ranked.announce_writein', '{name} added as a write-in — rank {rank}', { name: match.name, rank: ranking.value.length }));
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
        checkResult.value = { found: false, message: t('c_elections.ranked.check_unreachable', 'Could not reach the receipt check — try again.') };
    } finally {
        checkBusy.value = false;
    }
}

const checkLine = computed(() => {
    const r = checkResult.value;
    if (!r) return null;
    if (r.found) {
        return t('c_elections.ranked.check_found', 'Found — committed {when} (hour bucket), counted: {counted}', {
            when: fmt(r.cast_bucket),
            counted: r.counted ? t('c_elections.ranked.counted_yes', 'yes') : t('c_elections.ranked.counted_no', 'no'),
        });
    }
    return r.message ?? t('c_elections.ranked.check_not_found', 'Not found — check for typos; hashes are 64 characters.');
});

/* ----------------------------------------------------- live aggregate -- */

const aggScale = computed(() =>
    props.liveAggregate?.top?.length ? props.liveAggregate.top[0][1] * 1.3 : 1,
);
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_elections.ranked.title', 'Ranked ballot — {race}', { race: race.label })">
        <template #intro>
            {{ t('c_elections.ranked.intro', 'Rank as many candidates as you like. Ranking for all seats keeps your vote alive as the count unfolds. Your ballot is secret. Your receipt code lets you check it was counted.') }}
        </template>

        <p class="citation">
            {{ t('c_elections.ranked.cite_form', 'Ballot submission (ranked choice) · F-IND-007 · available to R-04 Voter · Art. II §2') }}
        </p>
        <p v-if="race.ranked_closes_at" class="citation">
            {{ t('c_elections.ranked.window_closes', 'Window closes {when} · shown in your timezone · stored as UTC', { when: fmt(race.ranked_closes_at) }) }}
        </p>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner tone="info" :title="t('c_elections.ranked.how_title', 'How the count works')">
            {{ t('c_elections.ranked.how_body', 'Instant multi-winner PR-STV with the Droop quota: every candidate reaching the quota is elected, surpluses transfer at fractional value (Gregory method), and all seats fill in one count.') }}
            <span class="gloss">
                {{ t('c_elections.ranked.how_gloss', 'Droop quota is the smallest vote total that only the seated candidates can all reach: floor(votes / (seats + 1)) + 1.') }}
            </span>
            <CitationLine :text="t('c_elections.ranked.cite_stv', 'STV with Droop quota · hardened · Art. II §2')" />
            {{ ' ' }}
            <HardenedChip>{{ t('c_elections.ranked.hardened_chip', 'hardened') }}</HardenedChip>
        </Banner>

        <!-- ============================== window closed (phase ≠ ranked) -->
        <Banner
            v-if="!isRanked && !committedNow"
            tone="warning"
            role="status"
            icon="clock"
            :title="t('c_elections.ranked.closed_title', 'The ranked window is not open.')"
        >
            <template v-if="race.phase === 'approval'">
                {{ t('c_elections.ranked.closed_approval', 'The approval phase is still under way. Finalists lock at the cutoff, then the ranked window opens.') }}
                <Link :href="`/elections/${race.election_id}/open-ballot`">{{ t('c_elections.ranked.browse_open', 'Browse the open ballot') }}</Link>.
            </template>
            <template v-else>
                {{ t('c_elections.ranked.closed_voting', 'Voting has closed for this race.') }}
                <Link :href="`/elections/${race.election_id}/results`">{{ t('c_elections.ranked.watch_count', 'Watch the count') }}</Link>.
            </template>
            <CitationLine text="CLK-18 · CLK-21 · Art. II §2" />
        </Banner>

        <!-- ======================================= committed just now ==== -->
        <Card v-if="committedNow" as="section" :title="t('c_elections.ranked.committed_title', 'Ballot committed')">
            <p>
                {{ t('c_elections.ranked.committed_body', 'Keep your receipt hash to verify your ballot was tabulated. The lookup is anonymized.') }}
            </p>
            <BallotReceipt
                :hash="receiptHash"
                :results-href="`/elections/${race.election_id}/results`"
            />
            <p class="citation" style="margin-block-start: var(--space-3)">
                {{ t('c_elections.ranked.custody_cite', 'Public chain of custody — endorsing organizations and candidates can observe and audit the count · Art. II §2') }}
            </p>
            <p class="citation">
                {{ t('c_elections.ranked.machine_note', 'Ballot machine: {machine} — the receipt hash is your handle on the counted state.', { machine: machine.join(' → ') }) }}
            </p>
        </Card>

        <!-- ======================================= already voted ========= -->
        <Card v-if="votedEarlier" as="section" :title="t('c_elections.ranked.voted_title', 'Your ballot is in the count')">
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <StatusBadge tone="success" icon="check">
                    {{ t('c_elections.ranked.committed_at', 'Ballot committed') }} · {{ fmt(alreadyVoted.committed_at) }}
                </StatusBadge>
            </div>
            <p>
                {{ t('c_elections.ranked.voted_body', 'Your ballot is in the count. Your receipt hash was shown once at commit. It cannot be re-issued by you or by anyone.') }}
            </p>
            <StateStrip :states="machine" current="Committed" />
            <hr />
            <h3>{{ t('c_elections.ranked.verify_title', 'Verify a receipt') }}</h3>
            <Field
                :label="t('c_elections.ranked.verify_label', 'Paste a receipt hash')"
                :hint="t('c_elections.ranked.verify_hint', 'Anonymized lookup. Anyone may check any hash against the public count record.')"
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
                    {{ checkBusy ? t('c_elections.ranked.checking', 'Checking…') : t('c_elections.ranked.check_receipt', 'Check receipt') }}
                </Btn>
                <span v-if="checkLine" class="citation" role="status" data-no-i18n>{{ checkLine }}</span>
            </div>
        </Card>

        <!-- ======================================= the ballot area ======= -->
        <div v-if="showBallotArea" id="ballot-area" class="grid-2">
            <Card as="section">
                <template #title>
                    <h2>
                        {{ t('c_elections.ranked.finalists_title', 'Finalists') }}
                        <span class="citation">{{ t('c_elections.ranked.finalists_cite', 'top {n} from the approval phase · CLK-21', { n: race.finalist_count }) }}</span>
                    </h2>
                </template>
                <div class="stack" style="gap: var(--space-1)">
                    <div v-for="entry in finalists" :key="entry.candidacy_id" class="roster-row">
                        <span>
                            <Link
                                style="color: var(--gov-fg-strong)"
                                :href="entry.profile_href"
                                :title="t('c_elections.ranked.profile_title', '{name} — open public profile', { name: entry.name })"
                            >{{ entry.name }}</Link>
                            <span v-if="rankIndex(entry.candidacy_id) >= 0" class="citation">
                                {{ t('c_elections.ranked.ranked_hash', 'ranked #{n}', { n: rankIndex(entry.candidacy_id) + 1 }) }}
                            </span>
                        </span>
                        <Btn
                            variant="secondary"
                            size="sm"
                            :disabled="rankedIds.has(entry.candidacy_id)"
                            @click="addFinalist(entry)"
                        >{{ t('c_elections.ranked.add_btn', 'Add') }}</Btn>
                    </div>
                </div>
                <hr />
                <h3>{{ t('c_elections.ranked.writein_title', 'Write-in') }}</h3>
                <p class="cc-small">
                    {{ t('c_elections.ranked.writein_body', 'Any validated candidate may be written in, finalist or not. {n} validated non-finalists remain write-in eligible in this race.', { n: writeInsAvailable }) }}
                </p>
                <p class="citation">{{ t('c_elections.ranked.writein_cite', 'Your right to stand and to vote for anyone is preserved · Art. II §2') }}</p>
                <Field
                    :label="t('c_elections.ranked.writein_label', 'Write in a validated candidate')"
                    :hint="t('c_elections.ranked.writein_hint', 'Search by name. Write-ins are search-driven, never enumerated.')"
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
                <p v-if="searching" class="gloss">{{ t('c_elections.ranked.searching', 'Searching…') }}</p>
                <div v-else-if="writeInQuery.trim().length >= 2" class="stack" style="gap: var(--space-1)">
                    <div v-for="match in writeInResults" :key="match.candidacy_id" class="roster-row">
                        <span>{{ match.name }} <span class="citation">{{ match.status }}</span></span>
                        <Btn variant="secondary" size="sm" @click="addWriteIn(match)">{{ t('c_elections.ranked.add_writein', 'Add write-in') }}</Btn>
                    </div>
                    <p v-if="writeInResults.length === 0" class="gloss">
                        {{ t('c_elections.ranked.no_match', 'No validated candidates match your search.') }}
                    </p>
                </div>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>
                        {{ t('c_elections.ranked.your_ranking', 'Your ranking') }}
                        <span class="citation">{{ t('c_elections.ranked.ranked_count', '{n} ranked', { n: ranking.length }) }}</span>
                    </h2>
                </template>
                <p class="gloss">{{ guidance }}</p>
                <RankList v-model="ranking" :seats="race.seats" />
                <div class="cluster">
                    <Btn variant="primary" :disabled="ranking.length === 0" @click="review">{{ t('c_elections.ranked.review_btn', 'Review ballot') }}</Btn>
                    <Btn variant="ghost" size="sm" :disabled="ranking.length === 0" @click="ranking = []; reviewing = false">{{ t('c_elections.ranked.clear_btn', 'Clear') }}</Btn>
                </div>
            </Card>
        </div>

        <!-- ======================================= review & commit ======= -->
        <Card v-if="showBallotArea && reviewing" ref="reviewCard" as="section" :title="t('c_elections.ranked.review_title', 'Review and commit')">
            <ol>
                <li v-for="entry in ranking" :key="entry.id">
                    {{ entry.name }}<template v-if="entry.write_in"> {{ t('c_elections.ranked.writein_tag', '(write-in)') }}</template>
                </li>
            </ol>
            <p class="cc-small">
                {{ t('c_elections.ranked.commit_body', 'Committing encrypts your ballot and separates it cryptographically from your identity. You will receive a receipt hash for self-audit. Nobody can connect it back to you.') }}
            </p>
            <p class="citation">
                {{ t('c_elections.ranked.commit_cite', 'Cryptographic separation of voter identity from ballot · Art. II §2 · Ensure Election Security and Integrity') }}
            </p>
            <Banner v-if="windowClosed" tone="warning" role="status" icon="clock">
                {{ t('c_elections.ranked.window_closed', 'The ranked window has closed. The engine will reject this ballot (Art. II §2).') }}
            </Banner>
            <div class="cluster">
                <Btn variant="gold" :disabled="committing || windowClosed" @click="commit">
                    {{ committing ? t('c_elections.ranked.committing', 'Committing…') : t('c_elections.ranked.commit_btn', 'Commit ballot') }}
                </Btn>
                <Btn variant="ghost" size="sm" @click="reviewing = false">{{ t('c_elections.ranked.keep_editing', 'Keep editing') }}</Btn>
            </div>
        </Card>

        <!-- ======================================= live aggregate ======== -->
        <Card v-if="liveAggregate" as="section" :title="t('c_elections.ranked.live_title', 'Live aggregate — if the window closed now')">
            <p class="cc-small">
                {{ t('c_elections.ranked.live_body', 'Standings stay visible through the ranked window: first preferences counted so far, as if the window closed this minute.') }}
            </p>
            <p class="citation">
                {{ t('c_elections.ranked.live_agg_note', '{ballots} ballots so far · Droop quota if closed now: {quota}', { ballots: liveAggregate.ballotsSoFar.toLocaleString(), quota: liveAggregate.quotaIfClosedNow.toLocaleString() }) }}
            </p>
            <StvBar
                v-for="[name, votes] in liveAggregate.top"
                :key="name"
                :name="name"
                :votes="votes"
                :quota="liveAggregate.quotaIfClosedNow"
                :scale="aggScale"
                :elected="votes >= liveAggregate.quotaIfClosedNow"
                :quota-title="t('c_elections.ranked.quota_title', 'Quota if closed now')"
            />
            <p v-if="liveAggregate.remainderNote" class="cc-small" style="margin-block-start: var(--space-2)">
                {{ liveAggregate.remainderNote }}
            </p>
            <p class="citation">
                {{ t('c_elections.ranked.proj_before', 'Projection only — surpluses and eliminations transfer at the close · full count on the') }}
                <Link :href="`/elections/${race.election_id}/results`">{{ t('c_elections.ranked.proj_link', 'results page') }}</Link>
                <span data-no-i18n>· Art. II §2</span>
            </p>
        </Card>

        <!-- ======================================= referendum slot ======= -->
        <Card v-if="referendum" as="section" :title="t('c_elections.ranked.ref_title', 'Referendum question on this ballot')">
            <p class="citation">{{ t('c_elections.ranked.ref_cite', 'Referendum vote · F-IND-008 · available to R-04 Voter · Art. II §6') }}</p>
            <Card inset>
                <p><strong>{{ referendum.title }}</strong> {{ referendum.text }}</p>
                <p class="citation">
                    {{ t('c_elections.ranked.ref_delegated', 'Delegated by supermajority act · F-LEG-023 · passes at the threshold matching the act type · Art. II §6') }}
                </p>
                <p class="gloss">{{ t('c_elections.ranked.ref_phase_c', 'Referendum content arrives with Phase C.') }}</p>
            </Card>
        </Card>

        <template #about>
            <p>
                {{ t('c_elections.ranked.about', 'Interaction note: ranking is click-to-rank with up and down keys, keyboard operable, no drag required.') }}
            </p>
        </template>
    </PageScaffold>
</template>
