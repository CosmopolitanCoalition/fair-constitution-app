<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import LawDiff from '@/Components/Ui/LawDiff.vue';
import LifecycleTracker from '@/Components/Ui/LifecycleTracker.vue';
import BillWorkspaceNav from '@/Components/Legislature/BillWorkspaceNav.vue';
import ConstituentConsentPanel from '@/Components/Legislature/ConstituentConsentPanel.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    workspace: { type: Object, required: true },
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
const { t } = useI18n();
const text = (key) => t('c_bill.' + key);
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

function fmt(iso) {
    return iso ? localeFmt.dateTime(new Date(iso)) : '—';
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
        <BillWorkspaceNav :workspace="workspace" active="record" />

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" :title="t('c_bill.bill_detail.rejected_title', 'Rejected by the Constitutional Engine.')">
            {{ constitutionError }}
        </Banner>

        <!-- ======================================== scale & scope ======= -->
        <Card as="section" :title="t('c_bill.bill_detail.scale_scope_title', 'Scale & scope — declared at introduction')">
            <div class="grid-2">
                <div>
                    <span class="eyebrow">{{ t('c_bill.bill_detail.scale_eyebrow', 'Scale — jurisdictions bound') }}</span>
                    <p style="margin-block-start: var(--space-1)">
                        <template v-if="bill.scale.length">
                            <span v-for="entry in bill.scale" :key="entry.id" class="tag-chip" style="margin-inline-end: var(--space-1)">
                                {{ entry.name }}
                            </span>
                        </template>
                        <span v-else class="gloss">{{ t('c_bill.bill_detail.own_jurisdiction', 'own jurisdiction') }}</span>
                    </p>
                </div>
                <div>
                    <span class="eyebrow">{{ t('c_bill.bill_detail.scope_eyebrow', 'Scope — judiciary') }}</span>
                    <p style="margin-block-start: var(--space-1)">{{ bill.scope.label }}</p>
                </div>
            </div>
            <p class="citation" style="margin-block-start: var(--space-2)">
                {{ t('c_bill.bill_detail.scale_scope_note', 'fixed at introduction — no edit affordance exists anywhere · F-LEG-003 · Art. V §4') }}
            </p>
        </Card>

        <!-- ============================================ law text ======== -->
        <Card id="bill-text" class="bill-record-section" as="section" :title="t('c_bill.law_text', { version: bill.current_version_no })">
            <pre v-if="lawText" class="mono bill-law-text" data-no-i18n>{{ lawText }}</pre>
            <p v-else class="gloss">{{ text('no_text') }}</p>
        </Card>

        <!-- ============================== committee + floor stages ====== -->
        <div id="bill-votes" class="grid-2 bill-record-section">
            <Card as="section">
                <template #title>
                    <h2>{{ t('c_bill.bill_detail.committee_stage', 'Committee stage') }} <FormChip form-id="F-LEG-005" /></h2>
                </template>
                <p v-if="bill.committee" class="citation" style="margin-block-end: var(--space-2)">
                    {{ bill.committee.name }} ·
                    <Link :href="bill.committee.href">{{ t('c_bill.bill_detail.committee_detail_link', 'committee detail →') }}</Link>
                    {{ t('c_bill.bill_detail.committee_majority', '· majority of ALL committee members, not those present · Art. II §4') }}
                    <template v-if="bicameral"> {{ t('c_bill.bill_detail.committee_per_kind', '· per-kind committee majorities (q7 binds at committee too)') }}</template>
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
                        <summary class="citation" style="cursor: pointer">{{ t('c_bill.bill_detail.published_casts', { n: committeeVote.casts.length }, 'Published casts ({n})') }}</summary>
                        <VoteCastList :casts="committeeVote.casts" :group-by-kind="bicameral" />
                    </details>
                    <div v-if="can.referToFloor" class="cluster" style="margin-block-start: var(--space-3)">
                        <Btn variant="primary" size="sm" :disabled="referringToFloor" @click="chairReferToFloor">
                            {{ t('c_bill.bill_detail.refer_floor', 'Refer to the floor (F-CHR-003)') }}
                        </Btn>
                        <span class="citation">{{ t('c_bill.bill_detail.refer_enabled_note', 'enabled only after the committee vote passes — the engine independently rejects premature referral') }}</span>
                    </div>
                </template>
                <p v-else class="gloss">
                    {{ t('c_bill.bill_detail.no_committee_vote', 'No committee vote — direct-to-floor bills skip this stage by adopted motion.') }}
                </p>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>{{ t('c_bill.bill_detail.floor_vote', 'Floor vote') }} <FormChip form-id="F-LEG-004" /></h2>
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
                        <summary class="citation" style="cursor: pointer">{{ t('c_bill.bill_detail.published_casts', { n: floorVote.casts.length }, 'Published casts ({n})') }}</summary>
                        <VoteCastList :casts="floorVote.casts" :group-by-kind="bicameral" />
                    </details>
                </template>

                <template v-else-if="can.refer">
                    <p class="cc-small">
                        {{ t('c_bill.bill_detail.move_by_motion', 'Move the bill by motion (F-LEG-007) —') }}
                        {{ openSession ? t('c_bill.bill_detail.session_open', { n: openSession.session_no }, 'session {n} is open') : t('c_bill.bill_detail.requires_session', 'requires an open session (F-SPK-001)') }}.
                    </p>
                    <form novalidate @submit.prevent="refer">
                        <div class="field">
                            <label class="field-label" for="refer-mode">{{ t('c_bill.bill_detail.path_label', 'Path') }}</label>
                            <select id="refer-mode" v-model="referForm.mode" class="select">
                                <option value="floor">{{ t('c_bill.bill_detail.opt_direct_floor', 'direct to floor (the exit-criterion path)') }}</option>
                                <option value="committee" :disabled="!committees.length">{{ t('c_bill.bill_detail.opt_refer_committee', 'refer to committee') }}</option>
                            </select>
                        </div>
                        <div v-if="referForm.mode === 'committee'" class="field">
                            <label class="field-label" for="refer-committee">{{ t('c_bill.bill_detail.committee_label', 'Committee') }}</label>
                            <select id="refer-committee" v-model="referForm.committee_id" class="select">
                                <option value="">{{ t('c_bill.bill_detail.opt_pick', '— pick —') }}</option>
                                <option v-for="committee in committees" :key="committee.id" :value="committee.id">
                                    {{ committee.name }}
                                </option>
                            </select>
                        </div>
                        <p v-if="referForm.errors.constitution" class="field-error">{{ referForm.errors.constitution }}</p>
                        <div class="cluster">
                            <Btn type="submit" variant="primary" size="sm" :disabled="referForm.processing">
                                {{ t('c_bill.bill_detail.move_it', 'Move it (F-LEG-007)') }}
                            </Btn>
                        </div>
                    </form>
                </template>
                <p v-else class="gloss">{{ t('c_bill.bill_detail.floor_opens', 'The floor vote opens when an adopted motion (or the committee chair) moves the bill.') }}</p>
            </Card>
        </div>

        <!-- ================================ constituent consents ======== -->
        <!-- FE-D1: the inline card became the shared dual-supermajority
             component (PHASE_D_DESIGN_frontend.md §A.1) — the floor tally
             pairs with the multi_jurisdiction_votes process; both
             `required` numbers stay engine snapshots. -->
        <Card v-if="constituentProcess" as="section" :title="t('c_bill.bill_detail.constituent_title', 'Constituent jurisdictions — dual supermajority')">
            <ConstituentConsentPanel
                :legislature-vote="floorVote?.tally ?? null"
                :legislature-label="legislature.name"
                :process="constituentProcess"
                basis="Art. V §6"
            />
        </Card>

        <Card id="bill-history" class="bill-record-section" as="section" :title="text('history_title')">
            <LifecycleTracker :stages="lifecycle" :current="bill.status" />
            <DataTable
                v-if="versions.length"
                style="margin-block-start: var(--space-3)"
                :columns="[
                    { key: 'version_no', label: text('version'), mono: true, align: 'right' },
                    { key: 'change_kind', label: text('change') },
                    { key: 'changed_by', label: text('changed_by') },
                    { key: 'created_at', label: text('date') },
                ]"
                :rows="versions"
                row-key="version_no"
                :caption="text('version_caption')"
            >
                <template #cell-created_at="{ row }">{{ fmt(row.created_at) }}</template>
            </DataTable>
            <p v-else class="gloss">{{ text('no_versions') }}</p>
            <template v-if="diff">
                <h3 style="margin-block-start: var(--space-4)">{{ t('c_bill.changes', { from: diff.from_version, to: diff.to_version }) }}</h3>
                <LawDiff :segments="diff.segments" :label="t('c_bill.changes', { from: diff.from_version, to: diff.to_version })" />
            </template>
        </Card>

        <!-- ===================================== enactment / failure ==== -->
        <Card v-if="enactment" as="section" :title="t('c_bill.bill_detail.enacted_title', 'Enacted')">
            <p>
                <strong style="color: var(--gov-fg)" data-no-i18n>{{ enactment.law.act_number }}</strong>
                {{ t('c_bill.bill_detail.effective', { date: fmt(enactment.effective_at) }, '— effective {date}.') }}
                <Link :href="enactment.law.href">{{ t('c_bill.bill_detail.public_record_link', 'public record →') }}</Link>
            </p>
            <p class="citation">
                {{ t('c_bill.bill_detail.versioned_note', 'versioned · published (WF-SYS-03) · open to Art. IV §5 challenge (Phase E) ·') }}
                <a :href="enactment.record_href">{{ t('c_bill.bill_detail.sealed_link', 'sealed into the audit chain →') }}</a>
            </p>
            <Card v-if="enactment.setting_change" inset style="margin-block-start: var(--space-2)">
                <p data-no-i18n>
                    <span class="mono">{{ enactment.setting_change.key }}</span>
                    {{ enactment.setting_change.old }} → <strong>{{ enactment.setting_change.new }}</strong>
                </p>
                <p class="citation">
                    {{ t('c_bill.bill_detail.clocks_rederived', 'dependent clocks re-derived after commit ·') }}
                    <Link :href="`/system/term-sync?legislature=${legislature.id}`">{{ t('c_term_sync.title') }}</Link> ·
                    <Link :href="`/legislatures/${legislature.id}/settings`">{{ t('c_bill.bill_detail.settings_register_link', 'settings register →') }}</Link>
                </p>
            </Card>
        </Card>

        <Banner v-if="bill.status === 'failed'" tone="warning" :title="t('c_bill.bill_detail.failed_title', 'The bill failed.')">
            {{ t('c_bill.bill_detail.failed_body', 'Archived with every member\'s public cast and explanation — the record above is permanent.') }} <span class="citation" data-no-i18n>Art. II §2</span>
        </Banner>

        <template #about>
            <p>
                {{ t('c_bill.bill_detail.about_lead', 'Entity state machine: Bill —') }} {{ machine.join(' → ') }}{{ t('c_bill.bill_detail.about_rest', '. In a bicameral chamber the committee and floor cards each render the dual per-kind tally natively — a failure in either kind, at either stage, fails the act (Art. V §3 · ledger #q7).') }}
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.bill-record-section { scroll-margin-block-start: 8rem; }
.bill-law-text { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; font-size: var(--text-sm); }
</style>
