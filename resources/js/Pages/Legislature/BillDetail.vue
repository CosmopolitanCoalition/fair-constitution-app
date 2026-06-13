<script setup>
/**
 * Legislature/BillDetail — FE-C4 (PHASE_C_DESIGN_frontend.md §B.4;
 * surface legislature/bill-detail).
 *
 * LifecycleTracker over the PHP-owned ESM-07 machine · scale & scope
 * (fixed at introduction) · law text + server-computed LawDiff on
 * amendments · committee-stage and floor-stage VoteTally cards (per-kind
 * lanes natively in bicameral chambers — q7 binds at BOTH stages) ·
 * VoteCastList once decided · constituent-consent meter for
 * dual_supermajority acts · the enactment card (act number, effective
 * date, setting_changes receipt, audit-chain seal).
 *
 * Every meter renders chamber_vote_tallies snapshots; the page never
 * computes a threshold.
 */
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import LawDiff from '@/Components/Ui/LawDiff.vue';
import LifecycleTracker from '@/Components/Ui/LifecycleTracker.vue';
import PersonaChip from '@/Components/Ui/PersonaChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ConstituentConsentPanel from '@/Components/Legislature/ConstituentConsentPanel.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    bill: { type: Object, required: true },
    machine: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    diff: { type: Object, default: null },
    lawText: { type: String, default: '' },
    committeeVote: { type: Object, default: null },
    floorVote: { type: Object, default: null },
    constituentProcess: { type: Object, default: null },
    enactment: { type: Object, default: null },
    openSession: { type: Object, default: null },
    committees: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const bicameral = computed(() => props.legislature.mode === 'bicameral');

/* The lifecycle strip shows the happy path; branch states splice in. */
const HAPPY_PATH = ['introduced', 'referred', 'in_committee', 'reported', 'on_floor', 'passed', 'enacted'];
const lifecycle = computed(() =>
    HAPPY_PATH.includes(props.bill.status)
        ? HAPPY_PATH
        : [...HAPPY_PATH.slice(0, 5), props.bill.status],
);

const STATUS_TONES = {
    enacted: 'success',
    passed: 'success',
    failed: 'danger',
    tabled: 'neutral',
    withdrawn: 'neutral',
    on_floor: 'warning',
};

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/* ----------------------------------------------------------- casting --- */
const castingStage = ref(null);
function castOn(stage, vote, payload) {
    castingStage.value = stage;
    router.post(`/votes/${vote.tally.vote_id}/cast`, payload, {
        preserveScroll: true,
        onFinish: () => {
            castingStage.value = null;
        },
    });
}

/* ----------------------------------------------------------- referral -- */
const referForm = useForm({ mode: 'floor', committee_id: '' });
function refer() {
    referForm.post(`/bills/${props.bill.id}/refer`, { preserveScroll: true });
}

const referringToFloor = ref(false);
function chairReferToFloor() {
    referringToFloor.value = true;
    router.post(`/bills/${props.bill.id}/refer`, { mode: 'chair' }, {
        preserveScroll: true,
        onFinish: () => {
            referringToFloor.value = false;
        },
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="bill.title">
        <template #intro>
            One bill, end to end: the committee gate, the floor gate, and — in a bicameral
            chamber — both seat kinds independently agreeing at each. An absent member counts
            the same as a no; the denominator never shrinks.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" title="Rejected by the Constitutional Engine.">
            {{ constitutionError }}
        </Banner>

        <!-- ============================================== header ======== -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ bill.title }}
                    <StatusBadge :tone="STATUS_TONES[bill.status] ?? 'info'" style="margin-inline-start: var(--space-2)">
                        {{ bill.status.replaceAll('_', ' ') }}
                    </StatusBadge>
                </h2>
            </template>
            <p class="cluster" style="gap: var(--space-3)">
                <PersonaChip :name="bill.sponsor.name" />
                <span class="mono">{{ bill.act_type }}</span>
                <span class="citation">introduced {{ fmt(bill.introduced_at) }} · stored as UTC</span>
                <Link :href="`/legislatures/${legislature.id}/bills`">← all bills</Link>
            </p>
            <LifecycleTracker :stages="lifecycle" :current="bill.status" style="margin-block-start: var(--space-3)" />
        </Card>

        <!-- ======================================== scale & scope ======= -->
        <Card as="section" title="Scale & scope — declared at introduction">
            <div class="grid-2">
                <div>
                    <span class="eyebrow">Scale — jurisdictions bound</span>
                    <p style="margin-block-start: var(--space-1)">
                        <template v-if="bill.scale.length">
                            <span v-for="entry in bill.scale" :key="entry.id" class="tag-chip" style="margin-inline-end: var(--space-1)">
                                {{ entry.name }}
                            </span>
                        </template>
                        <span v-else class="gloss">own jurisdiction</span>
                    </p>
                </div>
                <div>
                    <span class="eyebrow">Scope — judiciary</span>
                    <p style="margin-block-start: var(--space-1)">{{ bill.scope.label }}</p>
                </div>
            </div>
            <p class="citation" style="margin-block-start: var(--space-2)">
                fixed at introduction — no edit affordance exists anywhere · F-LEG-003 · Art. V §4
            </p>
        </Card>

        <!-- ============================================ law text ======== -->
        <Card as="section" :title="`Law text — version ${versions.length ? versions[versions.length - 1].version_no : 1}`">
            <pre class="mono" data-no-i18n style="white-space: pre-wrap; margin: 0; font-size: var(--text-sm)">{{ lawText }}</pre>
            <template v-if="versions.length > 1">
                <h3 style="margin-block-start: var(--space-4)">Versions</h3>
                <DataTable
                    :columns="[
                        { key: 'version_no', label: 'v', mono: true, align: 'right' },
                        { key: 'change_kind', label: 'Change' },
                        { key: 'changed_by', label: 'By' },
                        { key: 'created_at', label: 'At' },
                    ]"
                    :rows="versions"
                    row-key="version_no"
                    caption="Bill versions — append-only"
                >
                    <template #cell-created_at="{ row }">{{ fmt(row.created_at) }}</template>
                </DataTable>
            </template>
            <template v-if="diff">
                <h3 style="margin-block-start: var(--space-4)">
                    Amended — v{{ diff.from_version }} → v{{ diff.to_version }}
                </h3>
                <Card inset>
                    <LawDiff :segments="diff.segments" :label="`Law text changes v${diff.from_version} → v${diff.to_version}`" />
                </Card>
                <p class="gloss">
                    Server-computed segments — what you see is exactly the text the audit
                    chain hashed (law_versions.text_hash).
                </p>
            </template>
        </Card>

        <!-- ============================== committee + floor stages ====== -->
        <div class="grid-2">
            <Card as="section">
                <template #title>
                    <h2>Committee stage <FormChip form-id="F-LEG-005" /></h2>
                </template>
                <p v-if="bill.committee" class="citation" style="margin-block-end: var(--space-2)">
                    {{ bill.committee.name }} ·
                    <Link :href="bill.committee.href">committee detail →</Link>
                    · majority of ALL committee members, not those present · Art. II §4
                    <template v-if="bicameral"> · per-kind committee majorities (q7 binds at committee too)</template>
                </p>

                <template v-if="committeeVote">
                    <VoteTally
                        :mode="committeeVote.tally.mode"
                        stage="committee"
                        :threshold-class="committeeVote.tally.thresholdClass"
                        :serving="committeeVote.tally.serving"
                        :required-yes="committeeVote.tally.requiredYes"
                        :tallies="committeeVote.tally.tallies"
                        :quorum="committeeVote.tally.quorum"
                        :kinds="committeeVote.tally.kinds"
                        :outcome="committeeVote.tally.outcome"
                        :speaker-tiebreak="committeeVote.tally.speakerTiebreak"
                        :can-cast="can.castCommittee"
                        :casting="castingStage === 'committee'"
                        @cast="(payload) => castOn('committee', committeeVote, payload)"
                    />
                    <details v-if="committeeVote.casts?.length" style="margin-block-start: var(--space-2)">
                        <summary class="citation" style="cursor: pointer">Published casts ({{ committeeVote.casts.length }})</summary>
                        <VoteCastList :casts="committeeVote.casts" :group-by-kind="bicameral" />
                    </details>
                    <div v-if="can.referToFloor" class="cluster" style="margin-block-start: var(--space-3)">
                        <Btn variant="primary" size="sm" :disabled="referringToFloor" @click="chairReferToFloor">
                            Refer to the floor (F-CHR-003)
                        </Btn>
                        <span class="citation">enabled only after the committee vote passes — the engine independently rejects premature referral</span>
                    </div>
                </template>
                <p v-else class="gloss">
                    No committee vote — direct-to-floor bills skip this stage by adopted motion.
                </p>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>Floor vote <FormChip form-id="F-LEG-004" /></h2>
                </template>
                <template v-if="floorVote">
                    <VoteTally
                        :mode="floorVote.tally.mode"
                        stage="floor"
                        :threshold-class="floorVote.tally.thresholdClass"
                        :serving="floorVote.tally.serving"
                        :required-yes="floorVote.tally.requiredYes"
                        :tallies="floorVote.tally.tallies"
                        :quorum="floorVote.tally.quorum"
                        :kinds="floorVote.tally.kinds"
                        :outcome="floorVote.tally.outcome"
                        :speaker-tiebreak="floorVote.tally.speakerTiebreak"
                        :can-cast="can.castFloor"
                        :casting="castingStage === 'floor'"
                        @cast="(payload) => castOn('floor', floorVote, payload)"
                    />
                    <details v-if="floorVote.casts?.length" style="margin-block-start: var(--space-2)">
                        <summary class="citation" style="cursor: pointer">Published casts ({{ floorVote.casts.length }})</summary>
                        <VoteCastList :casts="floorVote.casts" :group-by-kind="bicameral" />
                    </details>
                </template>

                <template v-else-if="can.refer">
                    <p class="cc-small">
                        Move the bill by motion (F-LEG-007) —
                        {{ openSession ? `session ${openSession.session_no} is open` : 'requires an open session (F-SPK-001)' }}.
                    </p>
                    <form novalidate @submit.prevent="refer">
                        <div class="field">
                            <label class="field-label" for="refer-mode">Path</label>
                            <select id="refer-mode" v-model="referForm.mode" class="select">
                                <option value="floor">direct to floor (the exit-criterion path)</option>
                                <option value="committee" :disabled="!committees.length">refer to committee</option>
                            </select>
                        </div>
                        <div v-if="referForm.mode === 'committee'" class="field">
                            <label class="field-label" for="refer-committee">Committee</label>
                            <select id="refer-committee" v-model="referForm.committee_id" class="select">
                                <option value="">— pick —</option>
                                <option v-for="committee in committees" :key="committee.id" :value="committee.id">
                                    {{ committee.name }}
                                </option>
                            </select>
                        </div>
                        <p v-if="referForm.errors.constitution" class="field-error">{{ referForm.errors.constitution }}</p>
                        <div class="cluster">
                            <Btn type="submit" variant="primary" size="sm" :disabled="referForm.processing">
                                Move it (F-LEG-007)
                            </Btn>
                        </div>
                    </form>
                </template>
                <p v-else class="gloss">The floor vote opens when an adopted motion (or the committee chair) moves the bill.</p>
            </Card>
        </div>

        <!-- ================================ constituent consents ======== -->
        <!-- FE-D1: the inline card became the shared dual-supermajority
             component (PHASE_D_DESIGN_frontend.md §A.1) — the floor tally
             pairs with the multi_jurisdiction_votes process; both
             `required` numbers stay engine snapshots. -->
        <Card v-if="constituentProcess" as="section" title="Constituent jurisdictions — dual supermajority">
            <ConstituentConsentPanel
                :legislature-vote="floorVote?.tally ?? null"
                :legislature-label="legislature.name"
                :process="constituentProcess"
                basis="Art. V §6"
            />
        </Card>

        <!-- ===================================== enactment / failure ==== -->
        <Card v-if="enactment" as="section" title="Enacted">
            <p>
                <strong style="color: var(--gov-fg)" data-no-i18n>{{ enactment.law.act_number }}</strong>
                — effective {{ fmt(enactment.effective_at) }}.
                <Link :href="enactment.law.href">public record →</Link>
            </p>
            <p class="citation">
                versioned · published (WF-SYS-03) · open to Art. IV §5 challenge (Phase E) ·
                <a :href="enactment.record_href">sealed into the audit chain →</a>
            </p>
            <Card v-if="enactment.setting_change" inset style="margin-block-start: var(--space-2)">
                <p data-no-i18n>
                    <span class="mono">{{ enactment.setting_change.key }}</span>
                    {{ enactment.setting_change.old }} → <strong>{{ enactment.setting_change.new }}</strong>
                </p>
                <p class="citation">
                    dependent clocks re-derived after commit ·
                    <Link href="/system/term-sync">the re-armed timer renders on Term sync →</Link> ·
                    <Link :href="`/legislatures/${legislature.id}/settings`">settings register →</Link>
                </p>
            </Card>
        </Card>

        <Banner v-if="bill.status === 'failed'" tone="warning" title="The bill failed.">
            Archived with every member's public cast and explanation — the record above is
            permanent. <span class="citation">Art. II §2</span>
        </Banner>

        <template #about>
            <p>
                Entity state machine: Bill — {{ machine.join(' → ') }}. In a bicameral
                chamber the committee and floor cards each render the dual per-kind tally
                natively — a failure in either kind, at either stage, fails the act
                (Art. V §3 · ledger #q7).
            </p>
        </template>
    </PageScaffold>
</template>
