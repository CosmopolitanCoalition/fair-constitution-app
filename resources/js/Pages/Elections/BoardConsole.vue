<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Elections/BoardConsole — FE-B7 (PHASE_B_DESIGN_frontend.md §B.7).
 *
 * R-08 surface (route gate `access-board`: seated board member, or the
 * operator driving an active bootstrap board). Panels map 1:1 to the
 * mockup contract table: Scheduling (F-ELB-001) · Validation queue
 * (F-ELB-002) · District-map oversight (F-ELB-003 prereq) · Certification
 * (F-ELB-004) + Recount (F-ELB-006) · Signature audit (F-ELB-005 — live
 * since FE-C10: petitions at threshold render with the run-audit action;
 * the Phase B empty state is retired) · Vacancies. The bootstrap banner
 * renders from the REAL board.is_bootstrap flag (the mockup's toggle was
 * a scenario control, not product UI).
 */
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    board: { type: Object, default: null },
    can_act: { type: Boolean, default: false },
    boards: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    schedulable: { type: Array, default: () => [] },
    validationQueue: { type: Array, default: () => [] },
    districtOversight: { type: Array, default: () => [] },
    certifiable: { type: Array, default: () => [] },
    petitionAudits: { type: Array, default: () => [] },
    vacancies: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
/* Art. II §7 — an emergency cannot disrupt an election; the shell shows which
   powers are live, this console adds the board-specific reassurance. */
const emergenciesActive = computed(() => (page.props.app?.activeEmergencies?.length ?? 0) > 0);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

function fmt(iso) {
    return iso ? localeFmt.dateTime(new Date(iso)) : '—';
}

function switchBoard(event) {
    router.get('/board', { board: event.target.value });
}

/* ----------------------------------------------- scheduling (F-ELB-001) */

/** ISO → datetime-local value, kept in UTC ("stored as UTC" hint). */
function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toISOString().slice(0, 16);
}

const schedForm = useForm({
    election_id: props.schedulable[0]?.election_id ?? '',
    finalist_cutoff_at: toLocalInput(props.schedulable[0]?.finalist_cutoff_at),
    ranked_opens_at: toLocalInput(props.schedulable[0]?.ranked_opens_at),
    ranked_closes_at: toLocalInput(props.schedulable[0]?.ranked_closes_at),
});

watch(
    () => schedForm.election_id,
    (id) => {
        const election = props.schedulable.find((e) => e.election_id === id);
        if (!election) return;
        schedForm.finalist_cutoff_at = toLocalInput(election.finalist_cutoff_at);
        schedForm.ranked_opens_at = toLocalInput(election.ranked_opens_at);
        schedForm.ranked_closes_at = toLocalInput(election.ranked_closes_at);
    },
);

const selectedElection = computed(() =>
    props.schedulable.find((e) => e.election_id === schedForm.election_id) ?? null,
);

function submitSchedule() {
    schedForm.post('/board/scheduling-orders', { preserveScroll: true });
}

/* -------------------------------------------- validation (F-ELB-002) --- */

const decidedRows = ref([]); // rows decided this session — kept visible with badges
const decideBusy = reactive({});

function decide(row, decision) {
    decideBusy[row.candidacy_id] = true;
    router.post(
        `/board/validations/${row.candidacy_id}`,
        { decision },
        {
            preserveScroll: true,
            onSuccess: () => {
                decidedRows.value = [
                    ...decidedRows.value.filter((r) => r.candidacy_id !== row.candidacy_id),
                    { ...row, decision },
                ];
            },
            onFinish: () => {
                decideBusy[row.candidacy_id] = false;
            },
        },
    );
}

const queueRows = computed(() => {
    const pendingIds = new Set(props.validationQueue.map((r) => r.candidacy_id));
    return [
        ...props.validationQueue,
        ...decidedRows.value.filter((r) => !pendingIds.has(r.candidacy_id)),
    ];
});

/* ----------------------------------- certification + recount (F-ELB-00x) */

const certBusy = reactive({});
const recountFor = ref(null); // election_id with the cause form open
const recountCause = ref('');

function certify(electionId) {
    certBusy[electionId] = true;
    router.post(`/elections/${electionId}/certify`, {}, {
        preserveScroll: true,
        onFinish: () => {
            certBusy[electionId] = false;
        },
    });
}

function orderRecount(electionId) {
    certBusy[electionId] = true;
    router.post(`/elections/${electionId}/recount`, { cause: recountCause.value }, {
        preserveScroll: true,
        onSuccess: () => {
            recountFor.value = null;
            recountCause.value = '';
        },
        onFinish: () => {
            certBusy[electionId] = false;
        },
    });
}

/* ------------------------------------- petition audit (F-ELB-005) ------ */
const auditingPetition = ref(null);

function runPetitionAudit(row) {
    auditingPetition.value = row.petition_id;
    router.post(row.audit_url, { form_id: 'F-ELB-005' }, {
        preserveScroll: true,
        onFinish: () => {
            auditingPetition.value = null;
        },
    });
}
</script>

<template>
    <!-- Bootstrap posture — pinned above the page header (real flag). -->
    <Banner
        v-if="board && board.is_bootstrap"
        tone="warning"
        role="status"
        :title="t('c_elections.board.bootstrap_title', 'Bootstrap election board — temporary · replacement queued.')"
    >
        {{ t('c_elections.board.bootstrap_body', 'This board exists only to run the first election. The seated legislature must appoint a proper, politically neutral board as part of its first sessions.') }}
        <CitationLine text="WF-ELE-02 · WF-ELE-10 · Art. II §2" />
    </Banner>

    <PageScaffold :surface="surface" :title="board ? t('c_elections.board.console_title', 'Election board console — {name}', { name: board.jurisdiction_name }) : t('c_elections.board.console_title_none', 'Election board console')">
        <template #intro>
            {{ t('c_elections.board.intro', 'The board is an independent, politically neutral office. It schedules, validates, oversees boundaries, certifies, audits, and orders recounts. It never counts by hand. Tabulation runs in protected code.') }}
        </template>

        <p class="citation">{{ t('c_elections.board.cite_establish', 'Establish independent election boards · Art. II §2') }}</p>
        <p v-if="!board" class="citation">{{ t('c_elections.board.no_board', 'No election board is standing for you. This console is readable by everyone. Its actions belong to seated board members (R-08).') }}</p>
        <p v-else-if="!can_act" class="citation">{{ t('c_elections.board.viewing', 'You are viewing this board. Its actions belong to seated board members (R-08).') }}</p>
        <p v-if="board" class="citation">
            {{ t('c_elections.board.members_label', 'Board members:') }}
            <template v-for="(member, i) in board.members" :key="i">
                <template v-if="i > 0"> · </template>{{ member.name }}
            </template>
        </p>

        <div v-if="boards.length > 1" class="field" style="max-inline-size: 28rem">
            <label class="field-label" for="board-picker">{{ t('c_elections.board.board_label', 'Board') }}</label>
            <select id="board-picker" class="select" :value="board.id" @change="switchBoard">
                <option v-for="b in boards" :key="b.id" :value="b.id">
                    {{ b.jurisdiction_name }}{{ b.is_bootstrap ? t('c_elections.board.bootstrap_suffix', ' (bootstrap)') : '' }}
                </option>
            </select>
        </div>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>
        <!-- Art. II §7 — the board's work cannot be disrupted by an emergency. -->
        <Banner v-if="emergenciesActive" tone="info" role="status" :title="t('c_elections.board.emergency_title', 'Elections cannot be disrupted — the board proceeds.')">
            {{ t('c_elections.board.emergency_body', 'An emergency cannot suspend an election or the board work. Certification, seating and the countback run on their clocks regardless.') }} <span class="citation" data-no-i18n>Art. II §7</span>
        </Banner>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="stats.electionsAdministered" :label="t('c_elections.board.stat_elections', 'elections under administration')" />
            <Stat :value="stats.validationsPending" :label="t('c_elections.board.stat_validations', 'validations pending')" accent />
            <Stat :value="stats.countbacksRunning" :label="t('c_elections.board.stat_countbacks', 'countbacks running')" />
            <Stat :value="stats.petitionAuditsDue" :label="t('c_elections.board.stat_audits_due', 'petition audits due')" />
        </div>

        <!-- ======================================= scheduling ============ -->
        <FormCard
            v-if="schedulable.length && formMeta('F-ELB-001')"
            :form="formMeta('F-ELB-001')"
            :inertia-form="schedForm"
            :submit-label="t('c_elections.board.issue_order', 'Issue scheduling order')"
            :processing-label="t('c_elections.board.issuing', 'Issuing…')"
            @submit="submitSchedule"
        >
            <div class="grid-2">
                <div>
                    <Field :label="t('c_elections.board.election_label', 'Election')" :error="schedForm.errors.election_id">
                        <template #control="{ id }">
                            <select :id="id" v-model="schedForm.election_id" class="select">
                                <option v-for="e in schedulable" :key="e.election_id" :value="e.election_id">
                                    {{ e.label }}
                                </option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        :label="t('c_elections.board.finalist_cutoff', 'Finalist cutoff')"
                        :hint="selectedElection
                            ? t('c_elections.board.cutoff_hint_sel', 'X per race is pre-published with this order — {races} · CLK-21', { races: selectedElection.races.map((r) => `${r.label}: X = ${r.finalist_count}`).join(' · ') })
                            : t('c_elections.board.cutoff_hint', 'X per race is pre-published with this order · CLK-21')"
                        :error="schedForm.errors.finalist_cutoff_at"
                    >
                        <template #control="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="schedForm.finalist_cutoff_at"
                                class="field-input"
                                type="datetime-local"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                </div>
                <div>
                    <Field :label="t('c_elections.board.ranked_opens', 'Ranked window opens')" :error="schedForm.errors.ranked_opens_at">
                        <template #control="{ id }">
                            <input :id="id" v-model="schedForm.ranked_opens_at" class="field-input" type="datetime-local" />
                        </template>
                    </Field>
                    <Field
                        :label="t('c_elections.board.ranked_closes', 'Ranked window closes')"
                        :hint="t('c_elections.board.ranked_closes_hint', 'Entered and stored as UTC. The engine validates window ordering and phase lengths.')"
                        :error="schedForm.errors.ranked_closes_at || schedForm.errors.constitution"
                    >
                        <template #control="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="schedForm.ranked_closes_at"
                                class="field-input"
                                type="datetime-local"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                </div>
            </div>
        </FormCard>

        <!-- ======================================= validation queue ====== -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ t('c_elections.board.queue_title', 'Validation queue') }}
                    <span class="citation">{{ t('c_elections.board.queue_form_cite', 'Candidate validation · F-ELB-002') }}</span>
                </h2>
            </template>
            <p class="citation">{{ t('c_elections.board.queue_cite', 'available to R-08 · prereq: F-IND-011 submitted · Art. II §2 (election integrity)') }}</p>
            <p class="cc-small">
                {{ t('c_elections.board.queue_body', 'Residency association is the only permissible check. A rejection is appealable in court.') }}
            </p>

            <DataTable
                v-if="queueRows.length"
                :columns="[
                    { key: 'name', label: t('c_elections.board.col_registrant', 'Registrant') },
                    { key: 'office', label: t('c_elections.board.col_office', 'Office') },
                    { key: 'residency', label: t('c_elections.board.col_residency', 'Residency record') },
                    { key: 'decision', label: t('c_elections.board.col_decision', 'Decision') },
                ]"
                :rows="queueRows"
                row-key="candidacy_id"
                :caption="t('c_elections.board.queue_caption', 'Pending candidacy registrations')"
            >
                <template #cell-residency="{ row }">
                    <StatusBadge v-if="row.residency.found" tone="success" icon="check">
                        {{ t('c_elections.board.res_found', 'found') }}{{ row.residency.slug ? ` · ${row.residency.slug}` : '' }}{{ row.residency.duplicate ? t('c_elections.board.res_dup', ' · duplicate registration flag') : '' }}
                    </StatusBadge>
                    <StatusBadge v-else tone="danger" icon="alert-triangle">{{ t('c_elections.board.res_not_found', 'not found in jurisdiction') }}</StatusBadge>
                </template>
                <template #cell-decision="{ row }">
                    <StatusBadge v-if="row.decision === 'validate'" tone="success" icon="check">
                        {{ t('c_elections.board.dec_validated', 'validated · in approval pool') }}
                    </StatusBadge>
                    <template v-else-if="row.decision === 'reject'">
                        <StatusBadge tone="danger" icon="x">{{ t('c_elections.board.dec_rejected', 'rejected · appeal path open') }}</StatusBadge>
                        {{ ' ' }}
                        <span class="planned-flag">{{ t('c_elections.board.court_appeal', 'court appeal · Planned · Phase E') }}</span>
                    </template>
                    <span v-else class="cluster" style="gap: var(--space-1)">
                        <Btn
                            variant="secondary"
                            size="sm"
                            :disabled="!can_act || !!decideBusy[row.candidacy_id]"
                            @click="decide(row, 'validate')"
                        >{{ t('c_elections.board.validate_btn', 'Validate') }}</Btn>
                        <Btn
                            variant="ghost"
                            size="sm"
                            :disabled="!can_act || !!decideBusy[row.candidacy_id]"
                            @click="decide(row, 'reject')"
                        >{{ t('c_elections.board.reject_btn', 'Reject') }}</Btn>
                    </span>
                </template>
            </DataTable>
            <p v-else class="gloss">{{ t('c_elections.board.queue_empty', 'No registrations awaiting validation.') }}</p>
        </Card>

        <div class="grid-2">
            <!-- ===================================== district oversight == -->
            <Card as="section">
                <template #title>
                    <h2>
                        {{ t('c_elections.board.district_title', 'District-map oversight') }}
                        <span class="citation">{{ t('c_elections.board.district_form_cite', 'Subdivision boundary drawing · F-ELB-003') }}</span>
                    </h2>
                </template>
                <p class="citation">{{ t('c_elections.board.district_cite', 'available to R-08 · prereq: legislature seat count above 9 · Art. II §2; Art. II §8 (Subdivision)') }}</p>
                <DataTable
                    v-if="districtOversight.length"
                    :columns="[
                        { key: 'name', label: t('c_elections.board.col_plan', 'Plan') },
                        { key: 'districts', label: t('c_elections.board.col_districts', 'Districts') },
                        { key: 'status', label: t('c_elections.board.col_status', 'Status') },
                    ]"
                    :rows="districtOversight"
                    row-key="map_id"
                    :caption="t('c_elections.board.district_caption', 'District map plans under oversight')"
                >
                    <template #cell-districts="{ row }">
                        {{ t('c_elections.board.district_seats', '{n} · seats {s}', { n: row.district_count, s: row.seat_string }) }}
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge v-if="row.status === 'active'" tone="success" icon="check">{{ t('c_elections.board.status_active', 'active') }}</StatusBadge>
                        <StatusBadge v-else-if="row.status === 'draft'" tone="info" icon="map">{{ t('c_elections.board.status_draft', 'draft · published for observation') }}</StatusBadge>
                        <StatusBadge v-else tone="neutral">{{ row.status }}</StatusBadge>
                    </template>
                </DataTable>
                <p v-else class="gloss">
                    {{ t('c_elections.board.district_empty', 'No district maps under oversight. Chambers at or below the 9-seat ceiling run at-large by constitutional default (Art. II §8).') }}
                </p>
                <p style="margin-block-start: var(--space-2)">
                    <Link href="/legislatures">{{ t('c_elections.board.open_leg_browser', 'Open the legislature browser') }}</Link>
                </p>
                <p class="citation">{{ t('c_elections.board.contiguous_cite', 'Contiguous, equal subdivisions · Art. II §8') }}</p>
            </Card>

            <!-- ===================================== certification ======= -->
            <Card as="section">
                <template #title>
                    <h2>
                        {{ t('c_elections.board.cert_title', 'Certification') }}
                        <span class="citation">{{ t('c_elections.board.cert_form_cite', 'Election results certification · F-ELB-004') }}</span>
                    </h2>
                </template>
                <p class="citation">{{ t('c_elections.board.cert_cite', 'available to R-08 · prereq: voting closed + tabulation complete · Art. II §2 (transparent election process)') }}</p>

                <div v-for="row in certifiable" :key="row.election_id" class="card card--inset" style="margin-block-end: var(--space-3)">
                    <p style="margin-block-end: var(--space-1)"><strong>{{ row.label }}</strong></p>
                    <p class="citation">
                        {{ t('c_elections.board.cert_summary', '{r} rounds · {s} seats', { r: row.rounds, s: row.seats }) }} ·
                        {{ row.tabulation_complete ? t('c_elections.board.tab_complete', 'tabulation complete') : t('c_elections.board.tab_progress', 'tabulation in progress') }}
                    </p>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn as="a" :href="`/elections/${row.election_id}/results`" variant="secondary" size="sm">
                            {{ t('c_elections.board.review_record', 'Review the count record') }}
                        </Btn>
                        <template v-if="row.certified">
                            <StatusBadge tone="success" icon="check">{{ t('c_elections.board.certified_badge', 'Certified — winners granted roles') }}</StatusBadge>
                        </template>
                        <Btn
                            v-else
                            variant="primary"
                            size="sm"
                            :disabled="!can_act || !row.tabulation_complete || !!certBusy[row.election_id]"
                            @click="certify(row.election_id)"
                        >{{ t('c_elections.board.certify_results', 'Certify results') }}</Btn>
                    </div>
                    <hr />
                    <h3>{{ t('c_elections.board.recount_title', 'Recount') }} <span class="citation">{{ t('c_elections.board.recount_form_cite', 'Recount/audit order · F-ELB-006') }}</span></h3>
                    <div class="cluster">
                        <StatusBadge v-if="row.recount.ordered" tone="danger" icon="refresh-cw">
                            {{ t('c_elections.board.recount_open', 'Recount proceedings open · WF-ELE-05') }}
                        </StatusBadge>
                        <template v-else-if="recountFor !== row.election_id">
                            <Btn
                                variant="danger"
                                size="sm"
                                :disabled="!can_act || !row.certified"
                                :title="row.certified ? undefined : t('c_elections.board.needs_cert', 'Requires certification first')"
                                @click="recountFor = row.election_id"
                            >{{ t('c_elections.board.order_recount', 'Order recount') }}</Btn>
                            <span class="citation">
                                {{ row.certified ? t('c_elections.board.cause_required', 'cause must be stated on the order') : t('c_elections.board.after_cert', 'enabled after certification') }}
                                {{ t('c_elections.board.recount_opens_tail', '· opens WF-ELE-05') }}
                            </span>
                        </template>
                    </div>
                    <div v-if="recountFor === row.election_id" class="field" style="margin-block-start: var(--space-2)">
                        <label class="field-label" :for="`cause-${row.election_id}`">{{ t('c_elections.board.recount_cause_label', 'Cause for the audit re-run (required)') }}</label>
                        <textarea :id="`cause-${row.election_id}`" v-model="recountCause" class="field-input" rows="2"></textarea>
                        <span class="field-hint">{{ t('c_elections.board.recount_cause_hint', 'The engine rejects an order without a stated cause.') }}</span>
                        <div class="cluster" style="margin-block-start: var(--space-2)">
                            <Btn
                                variant="danger"
                                size="sm"
                                :disabled="!can_act || !recountCause.trim() || !!certBusy[row.election_id]"
                                @click="orderRecount(row.election_id)"
                            >{{ t('c_elections.board.recount_confirm', 'Confirm recount order') }}</Btn>
                            <Btn variant="ghost" size="sm" @click="recountFor = null; recountCause = ''">{{ t('c_elections.board.cancel', 'Cancel') }}</Btn>
                        </div>
                    </div>
                </div>
                <p v-if="!certifiable.length" class="gloss">
                    {{ t('c_elections.board.cert_empty', 'Nothing awaiting certification. Counts appear here the moment a ranked window closes.') }}
                </p>
            </Card>
        </div>

        <div class="grid-2">
            <!-- ===================================== signature audit ===== -->
            <Card as="section">
                <template #title>
                    <h2>
                        {{ t('c_elections.board.sig_title', 'Signature audit') }}
                        <span class="citation">{{ t('c_elections.board.sig_form_cite', 'Petition signature audit · F-ELB-005') }}</span>
                    </h2>
                </template>
                <p class="citation">{{ t('c_elections.board.sig_cite', 'available to R-08 · prereq: petition at threshold · Art. II §6 (independent audit)') }}</p>
                <div v-if="petitionAudits.length" class="stack" style="gap: var(--space-3)">
                    <div v-for="row in petitionAudits" :key="row.petition_id" class="card card--inset">
                        <p style="margin-block-end: var(--space-1)">
                            <a :href="row.href"><strong>{{ row.title }}</strong></a>
                            {{ ' ' }}
                            <StatusBadge :tone="row.due ? 'warning' : row.result?.still_above ? 'success' : row.result ? 'danger' : 'info'">
                                {{ row.state }}
                            </StatusBadge>
                        </p>
                        <p class="cc-small">
                            {{ t('c_elections.board.sig_counts', '{live} live signatures · threshold {threshold}', { live: localeFmt.number(row.signatures), threshold: localeFmt.number(row.threshold_count) }) }}
                        </p>
                        <p v-if="row.result" class="cc-small">
                            {{ t('c_elections.board.sig_result', '{valid} of {checked} valid ({pct}%)', { valid: localeFmt.number(row.result.valid), checked: localeFmt.number(row.result.checked), pct: row.result.pct_valid }) }} —
                            {{ row.result.still_above ? t('c_elections.board.still_above', 'still above threshold') : t('c_elections.board.below_threshold', 'below threshold — invalidated (kill-path)') }}
                        </p>
                        <Btn
                            v-if="row.due"
                            variant="primary"
                            size="sm"
                            :disabled="!can_act || auditingPetition === row.petition_id"
                            @click="runPetitionAudit(row)"
                        >{{ t('c_elections.board.run_sig_audit', 'Run signature audit (F-ELB-005)') }}</Btn>
                    </div>
                </div>
                <p v-else class="gloss">{{ t('c_elections.board.sig_empty', 'No petitions at threshold.') }}</p>
            </Card>

            <!-- ===================================== vacancies =========== -->
            <Card as="section" :title="t('c_elections.board.vac_title', 'Vacancies')">
                <div v-if="vacancies.length" class="stack" style="gap: var(--space-3)">
                    <div v-for="vacancy in vacancies" :key="vacancy.vacancy_id" class="card card--inset">
                        <p style="margin-block-end: var(--space-1)"><strong>{{ vacancy.label }}</strong></p>
                        <p class="citation">{{ t('c_elections.board.vac_cite', 'Vacancy declaration received · F-LEG-036 · countback per Art. II §5') }}</p>
                        <div class="cluster" style="margin-block-start: var(--space-2)">
                            <StatusBadge
                                :tone="vacancy.status === 'filled' ? 'success' : vacancy.status === 'countback_failed' ? 'danger' : 'warning'"
                            >{{ vacancy.status }}</StatusBadge>
                            <Btn as="a" :href="`/vacancies/${vacancy.vacancy_id}`" variant="secondary" size="sm">
                                {{ t('c_elections.board.open_countback', 'Open the countback view') }}
                            </Btn>
                        </div>
                    </div>
                </div>
                <p v-else class="gloss">{{ t('c_elections.board.vac_empty', 'No vacancies. Every seat in the jurisdiction is held.') }}</p>
            </Card>
        </div>

        <template #about>
            <p>
                {{ t('c_elections.board.about', 'Bootstrap variant: a bootstrap board administers only the first election and carries the persistent replacement warning until the seated legislature appoints a proper board (WF-ELE-10).') }}
            </p>
        </template>
    </PageScaffold>
</template>
