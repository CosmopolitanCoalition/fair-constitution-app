<script setup>
/**
 * Dev/LegislatureKit — FE-C1 fixture-first harness (/dev/legislature-kit,
 * dev-gated). Renders the Phase C component kit in every state from
 * resources/js/fixtures/legislature.json (mockup-extracted; see the
 * file's `_source` provenance). NOT product UI — the per-page WIs
 * (FE-C2…C11) wire these against real engine-snapshotted props.
 *
 * Reference mockups: mockups/legislature/{legislature-home,session-console,
 * bill-detail}.html, mockups/judiciary/constitutional-challenge.html
 * (law-diff), mockups/civic/petition-detail.html (signature meter).
 */
import { computed, ref } from 'vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import ChipToggle from '@/Components/Ui/ChipToggle.vue';
import LawDiff from '@/Components/Ui/LawDiff.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import SignatureMeter from '@/Components/Civic/SignatureMeter.vue';
import AgendaStrip from '@/Components/Legislature/AgendaStrip.vue';
import SeatMap from '@/Components/Legislature/SeatMap.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import EmergencyBanner from '@/Components/Shell/EmergencyBanner.vue';
import RankList from '@/Components/Electoral/RankList.vue';
import fixtures from '@/fixtures/legislature.json';

defineProps({ surface: { type: Object, default: null } });

/* ------------------------------------------------------------ SeatMap -- */
const highlightNy = ref(null);
const nyMembers = fixtures.chamber.members;
const smMembers = fixtures.sanMarino.members;

/* -------------------------------------------------- VoteTally variants -- */
const MG = fixtures.votes.montegiardino; // unicameral, 8 serving
const TIE = fixtures.votes.nyCountyTie; // 9 serving — the F-SPK-004 record
const SM = fixtures.votes.sanMarino; // bicameral kinds, server-snapshotted

const uniVariants = [
    { id: 'majority-pending', thresholdClass: 'majority', requiredYes: MG.majority.requiredYes, tallies: null, outcome: 'pending', note: 'majority · pending (tallies null)' },
    { id: 'majority-adopted', thresholdClass: 'majority', requiredYes: MG.majority.requiredYes, tallies: MG.outcomes.adopted, outcome: 'adopted', note: 'majority · adopted 5–2 of 8 serving' },
    { id: 'majority-failed', thresholdClass: 'majority', requiredYes: MG.majority.requiredYes, tallies: MG.outcomes.failed, outcome: 'failed', note: 'majority · failed 3–4 (abstention is not a yes)' },
    { id: 'supermajority-adopted', thresholdClass: 'supermajority', requiredYes: MG.supermajority.requiredYes, tallies: { yes: 6, no: 1, abstain: 1 }, outcome: 'adopted', note: 'supermajority · ceil(8 × 2/3) = 6 — formula gloss is DISPLAY of the snapshot' },
    { id: 'supermajority-failed', thresholdClass: 'supermajority', requiredYes: MG.supermajority.requiredYes, tallies: { yes: 5, no: 3, abstain: 0 }, outcome: 'failed', note: 'supermajority · 5 yes meets majority but NOT the supermajority gate' },
    { id: 'rcv', thresholdClass: 'rcv', requiredYes: MG.supermajority.requiredYes, tallies: { yes: 7, no: 1, abstain: 0 }, outcome: 'adopted', note: 'rcv · Speaker election outcome (rounds render via Electoral/StvBar on the page)' },
];

const committeeVariants = [
    { id: 'committee-pending', tallies: { yes: 1, no: 0, abstain: 0 }, outcome: 'pending', note: 'committee_majority · 1 of 3 recorded so far (chair: yes)' },
    { id: 'committee-adopted', tallies: { yes: 2, no: 1, abstain: 0 }, outcome: 'adopted', note: 'committee_majority · 2 of 3 — all members, not those present' },
];

/* ----------------------------------------------------- AgendaStrip ----- */
const agendaItems = ref(fixtures.agenda.emergencyActive.map((item) => ({ ...item })));
const agendaEditable = ref(true);
function onReorder(from, to) {
    const next = [...agendaItems.value];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    next.forEach((item, i) => {
        item.position = i + 1;
    });
    agendaItems.value = next;
}

/* ------------------------------------------------ RankList (committee) -- */
const committeePrefs = ref(fixtures.committees.map((c) => ({ id: c.id, name: c.name, chips: [] })));
const prefsLocked = ref(false);
const removableDemo = ref([
    { id: 'aisha-diop', name: 'Aisha Diop', chips: [] },
    { id: 'quinn-avery', name: 'Quinn Avery', chips: ['write-in'] },
    { id: 'rita-alvarez', name: 'Rita Alvarez', chips: [] },
]);

/* ------------------------------------------------------ casting demo --- */
const lastCast = ref(null);
function onCast(payload) {
    lastCast.value = payload;
}

/* ------------------------------------------------------ state machines -- */
/* Display-only peek at config/cga/state_machines.php (FE-C0) — the real
   pages receive these as PHP-owned props; the harness shows the shapes. */
const MACHINES = {
    bill: ['introduced', 'referred', 'in_committee', 'reported', 'on_floor', 'passed', 'enacted'],
    motion: ['submitted', 'recognized', 'debated', 'voted', 'adopted'],
    petition: ['created', 'gathering', 'threshold_reached', 'signature_audit', 'constitutional_review', 'validated', 'on_ballot', 'adopted'],
};

const petitionBelow = computed(() => ({
    signatures: 412938,
    threshold: fixtures.petition.threshold,
}));
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            FE-C1 harness — the Phase C legislature components rendered from
            <code data-no-i18n>resources/js/fixtures/legislature.json</code>
            (mockup-extracted). Dev-gated; not product UI. Every threshold number
            below is a frozen "server snapshot" — no component computes one.
        </template>

        <Banner tone="demo" title="Fixture data only.">
            Nothing on this page touches the database — the chambers below are the
            mockups' New York County (9 seats) and a synthetic San Marino-shaped
            bicameral chamber (41 seats), frozen into a JSON fixture.
        </Banner>

        <!-- ================================================= 1. SeatMap -->
        <Card as="section" title="SeatMap — 9-seat unicameral (mockup chamber)">
            <p class="citation">Legislature/SeatMap · port of chamberSvg() · seniority-alternating placement · 1 ring · seat 4 vacant · Speaker gold</p>
            <SeatMap :members="nyMembers" :highlight-id="highlightNy" />
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <label class="field-label" for="highlight-sel" style="margin-block-end: 0">Highlight (roster hover sync)</label>
                <select id="highlight-sel" v-model="highlightNy" class="select" style="inline-size: auto">
                    <option :value="null">— none —</option>
                    <option v-for="m in nyMembers.filter((x) => !x.vacant)" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Gold ring = Speaker (politically neutral, votes only to break ties). Dashed =
                vacant seat in countback — vacancies join at the junior-most position.
                Seniority is total days served; ties break by normalized vote share (ledger #q2).
            </p>
        </Card>

        <Card as="section" title="SeatMap — 41-seat bicameral (San Marino-shaped)">
            <p class="citation">3 rings (12/20/9) · dynamic viewBox · 32 type A + 9 type B (blue inner ring, one per castello) · seat 17 vacant · Art. V §3</p>
            <SeatMap :members="smMembers" max-width="30rem" />
        </Card>

        <!-- ============================================== 2. VoteTally -->
        <Card as="section" title="VoteTally — unicameral threshold classes (Montegiardino, 8 serving)">
            <p class="citation">Legislature/VoteTally · pure renderer of chamber_votes snapshots — requiredYes is NEVER computed client-side</p>
            <div class="stack" style="gap: var(--space-5)">
                <div v-for="variant in uniVariants" :key="variant.id" class="card card--inset">
                    <span class="eyebrow" data-no-i18n>{{ variant.note }}</span>
                    <VoteTally
                        mode="unicameral"
                        :threshold-class="variant.thresholdClass"
                        :serving="8"
                        :required-yes="variant.requiredYes"
                        :tallies="variant.tallies"
                        :quorum="MG.quorum"
                        :outcome="variant.outcome"
                    />
                </div>
            </div>
        </Card>

        <Card as="section" title="VoteTally — tie + Speaker tie-break (9 serving, F-SPK-004)">
            <p class="citation">mockup record: “4–4 → Speaker broke the tie (F-SPK-004)” · Art. II §3</p>
            <div class="grid-2">
                <div class="card card--inset">
                    <span class="eyebrow">tied — awaiting the Speaker</span>
                    <VoteTally
                        mode="unicameral"
                        threshold-class="majority"
                        :serving="TIE.serving"
                        :required-yes="TIE.requiredYes"
                        :tallies="TIE.tied"
                        outcome="tied"
                    />
                </div>
                <div class="card card--inset">
                    <span class="eyebrow">tied_broken — adopted 5–4</span>
                    <VoteTally
                        mode="unicameral"
                        threshold-class="majority"
                        :serving="TIE.serving"
                        :required-yes="TIE.requiredYes"
                        :tallies="TIE.tied_broken"
                        outcome="tied_broken"
                        speaker-tiebreak
                    />
                </div>
            </div>
        </Card>

        <Card as="section" title="VoteTally — committee_majority (2 of 3, all members)">
            <div class="grid-2">
                <div v-for="variant in committeeVariants" :key="variant.id" class="card card--inset">
                    <span class="eyebrow" data-no-i18n>{{ variant.note }}</span>
                    <VoteTally
                        mode="unicameral"
                        stage="committee"
                        threshold-class="committee_majority"
                        :serving="MG.committee.serving"
                        :required-yes="MG.committee.requiredYes"
                        :tallies="variant.tallies"
                        :outcome="variant.outcome"
                    />
                </div>
            </div>
        </Card>

        <Card as="section" title="VoteTally — bicameral dual agreement (San Marino: type A 32 → 17/22 · type B 9 → 5/6)">
            <p class="citation">two per-kind blocks: peg-quorum meter + threshold meter + agreement badge · combined-outcome banner · Art. V §3 · ledger #q7 · WF-LEG-07</p>
            <div class="stack" style="gap: var(--space-5)">
                <div class="card card--inset">
                    <span class="eyebrow">bicameral_majority · pending (floor)</span>
                    <VoteTally mode="bicameral" threshold-class="bicameral_majority" :kinds="SM.majority.pending" outcome="pending" />
                </div>
                <div class="card card--inset">
                    <span class="eyebrow">bicameral_majority · adopted — both kinds agree</span>
                    <VoteTally mode="bicameral" threshold-class="bicameral_majority" :kinds="SM.majority.adopted" outcome="adopted" />
                </div>
                <div class="card card--inset">
                    <span class="eyebrow">bicameral_majority · failed — type B does not agree (failing kind named)</span>
                    <VoteTally mode="bicameral" threshold-class="bicameral_majority" :kinds="SM.majority.failed_type_b" outcome="failed" />
                </div>
                <div class="card card--inset">
                    <span class="eyebrow">bicameral_supermajority · adopted (22 of 32 · 6 of 9)</span>
                    <VoteTally mode="bicameral" threshold-class="bicameral_supermajority" :kinds="SM.supermajority.adopted" outcome="adopted" />
                </div>
                <div class="card card--inset">
                    <span class="eyebrow">bicameral_supermajority · failed — type A short of ceil(32 × 2/3)</span>
                    <VoteTally mode="bicameral" threshold-class="bicameral_supermajority" :kinds="SM.supermajority.failed_type_a" outcome="failed" />
                </div>
                <div class="card card--inset">
                    <span class="eyebrow">bicameral committee stage — per-kind committee majorities (q7 binds at committee AND floor)</span>
                    <VoteTally mode="bicameral" stage="committee" threshold-class="bicameral_majority" :kinds="SM.committee" outcome="adopted" />
                </div>
            </div>
        </Card>

        <Card as="section" title="VoteTally — casting cluster (emit-only)">
            <p class="citation">yes/no/abstain + optional explanation — published with the vote · Art. II §2 · the PAGE owns POST /votes/{vote}/cast</p>
            <VoteTally
                mode="unicameral"
                threshold-class="majority"
                :serving="8"
                :required-yes="MG.majority.requiredYes"
                :tallies="{ yes: 2, no: 1, abstain: 0 }"
                outcome="pending"
                can-cast
                @cast="onCast"
            />
            <p v-if="lastCast" class="gloss" role="status" data-no-i18n>
                emitted: cast({{ JSON.stringify(lastCast) }})
            </p>
        </Card>

        <!-- =========================================== 3. VoteCastList -->
        <div class="grid-2">
            <Card as="section" title="VoteCastList — published positions">
                <p class="citation">member votes are PUBLIC — the opposite of ballots · absent counts the same as a no · Art. II §2</p>
                <VoteCastList :casts="fixtures.casts.unicameral" />
            </Card>
            <Card as="section" title="VoteCastList — tie-break record + grouped kinds">
                <VoteCastList :casts="fixtures.casts.tieBroken" />
                <hr />
                <p class="citation">groupByKind — bicameral surfaces</p>
                <VoteCastList :casts="fixtures.casts.bicameral" group-by-kind />
            </Card>
        </div>

        <!-- ============================================ 4. AgendaStrip -->
        <Card as="section" title="AgendaStrip — constitutional order, locked slots 1–2">
            <p class="citation">F-SPK-002 · 1. outstanding emergency powers → 2. constitutional matters → 3. general agenda · Art. II §2; §7 · hardened</p>
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <ChipToggle v-model:pressed="agendaEditable">editable (R-10 + session open)</ChipToggle>
            </div>
            <AgendaStrip :items="agendaItems" :editable="agendaEditable" @reorder="onReorder" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                ↑/↓ keep focus on the moved item's control and announce via the polite live
                region; locked slots render no controls and other items cannot move past them.
            </p>
            <hr />
            <p class="citation">all-clear variant — the locked slots render their honest empty states</p>
            <AgendaStrip :items="fixtures.agenda.allClear" :editable="false" />
        </Card>

        <!-- ================================================ 5. LawDiff -->
        <Card as="section" title="LawDiff — server-computed segments (.law-diff del/ins)">
            <p class="citation">segments rendered verbatim — what citizens see is exactly what the audit chain hashed · Art. IV §5 PATH C grammar</p>
            <Card inset>
                <p style="margin-block-end: var(--space-1)"><strong data-no-i18n>{{ fixtures.lawDiff.label }}</strong></p>
                <LawDiff :segments="fixtures.lawDiff.segments" :label="fixtures.lawDiff.label" />
            </Card>
            <p class="gloss">
                del/ins carry visually-hidden "removed:"/"added:" prefixes — the ops are
                explicit for screen readers, never color-only.
            </p>
        </Card>

        <!-- ========================================= 6. SignatureMeter -->
        <Card as="section" title="SignatureMeter — petition thresholds (CLK-17)">
            <p class="citation">denominator is the SNAPSHOT petitions.threshold_count — never recomputed client-side · Art. II §6</p>
            <h3>Gathering — below threshold</h3>
            <SignatureMeter :signatures="petitionBelow.signatures" :threshold="petitionBelow.threshold" pct="5.00" />
            <h3 style="margin-block-start: var(--space-4)">Threshold reached</h3>
            <SignatureMeter :signatures="fixtures.petition.signatures" :threshold="fixtures.petition.threshold" :pct="fixtures.petition.pct" />
            <h3 style="margin-block-start: var(--space-4)">Compact (list-row variant)</h3>
            <SignatureMeter :signatures="fixtures.petition.signatures" :threshold="fixtures.petition.threshold" :pct="fixtures.petition.pct" compact />
        </Card>

        <!-- ======================================== 7. EmergencyBanner -->
        <Card as="section" title="EmergencyBanner — cross-surface alert (shell-wired)">
            <p class="citation">renders nothing when empty · shared prop app.activeEmergencies · Art. II §7 · CLK-03</p>
            <EmergencyBanner :emergencies="[fixtures.emergencies[0]]" />
            <h3 style="margin-block-start: var(--space-4)">Two active powers, one under judicial review</h3>
            <EmergencyBanner :emergencies="fixtures.emergencies" />
            <h3 style="margin-block-start: var(--space-4)">Empty (renders nothing between the rules)</h3>
            <hr />
            <EmergencyBanner :emergencies="[]" />
            <hr />
        </Card>

        <!-- ============================== 8. RankList generalization ==== -->
        <div class="grid-2">
            <Card as="section" title="RankList — removable: false (committee preferences, F-LEG-010)">
                <p class="citation">every member ranks the FULL committee list — no remove button, no empty-list path · default order = creation order</p>
                <RankList v-model="committeePrefs" :seats="committeePrefs.length" :removable="false" :disabled="prefsLocked" />
                <div class="cluster">
                    <ChipToggle v-model:pressed="prefsLocked">submitted (locked read-only)</ChipToggle>
                </div>
                <p class="gloss">
                    Rank every committee — the assignment algorithm honors your order; ties
                    break by normalized vote share (ledger #q2).
                </p>
            </Card>
            <Card as="section" title="RankList — removable: true + chips (electoral call-site shape)">
                <p class="citation">items are { id, name, chips } — RankedBallot maps candidacy_id → id, write_in → chips: ['write-in']</p>
                <RankList v-model="removableDemo" :seats="3" />
            </Card>
        </div>

        <!-- ================================== 9. Phase C state machines -->
        <Card as="section" title="State machines — FE-C0 config entries (display contract)">
            <p class="citation">config/cga/state_machines.php · PHP-owned, prop-fed on the real pages — shapes shown here for the kit only</p>
            <div class="stack" style="gap: var(--space-3)">
                <div>
                    <span class="eyebrow">Bill (ESM-07) — current: in_committee</span>
                    <StateStrip :states="MACHINES.bill" current="in_committee" />
                </div>
                <div>
                    <span class="eyebrow">Motion (ESM-08) — current: voted</span>
                    <StateStrip :states="MACHINES.motion" current="voted" />
                </div>
                <div>
                    <span class="eyebrow">Petition (ESM-10) — current: signature_audit</span>
                    <StateStrip :states="MACHINES.petition" current="signature_audit" />
                </div>
            </div>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <StatusBadge tone="info" icon="info">committee_seat · referendum_question · emergency_powers registered too</StatusBadge>
            </div>
        </Card>
    </PageScaffold>
</template>
