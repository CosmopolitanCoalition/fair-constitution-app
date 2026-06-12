<script setup>
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
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
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

const props = defineProps({
    surface: { type: Object, required: true },
    board: { type: Object, required: true },
    boards: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    schedulable: { type: Array, default: () => [] },
    validationQueue: { type: Array, default: () => [] },
    districtOversight: { type: Array, default: () => [] },
    certifiable: { type: Array, default: () => [] },
    petitionAudits: { type: Array, default: () => [] },
    vacancies: { type: Array, default: () => [] },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
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
        v-if="board.is_bootstrap"
        tone="warning"
        role="status"
        title="Bootstrap election board — temporary · replacement queued."
    >
        This board exists only to run the first election. The seated legislature must appoint
        a proper, politically neutral board as part of its first sessions.
        <CitationLine text="WF-ELE-02 · WF-ELE-10 · Art. II §2" />
    </Banner>

    <PageScaffold :surface="surface" :title="`Election board console — ${board.jurisdiction_name}`">
        <template #intro>
            The board is an independent, politically neutral office: it schedules, validates,
            oversees boundaries, certifies, audits, and orders recounts. It never counts by
            hand — tabulation is hardened code.
        </template>

        <p class="citation">Establish independent election boards · Art. II §2</p>
        <p class="citation">
            Board members:
            <template v-for="(member, i) in board.members" :key="i">
                <template v-if="i > 0"> · </template>{{ member.name }}
            </template>
        </p>

        <div v-if="boards.length > 1" class="field" style="max-inline-size: 28rem">
            <label class="field-label" for="board-picker">Board</label>
            <select id="board-picker" class="select" :value="board.id" @change="switchBoard">
                <option v-for="b in boards" :key="b.id" :value="b.id">
                    {{ b.jurisdiction_name }}{{ b.is_bootstrap ? ' (bootstrap)' : '' }}
                </option>
            </select>
        </div>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="stats.electionsAdministered" label="elections under administration" />
            <Stat :value="stats.validationsPending" label="validations pending" accent />
            <Stat :value="stats.countbacksRunning" label="countbacks running" />
            <Stat :value="stats.petitionAuditsDue" label="petition audits due" />
        </div>

        <!-- ======================================= scheduling ============ -->
        <FormCard
            v-if="schedulable.length && formMeta('F-ELB-001')"
            :form="formMeta('F-ELB-001')"
            :inertia-form="schedForm"
            submit-label="Issue scheduling order"
            processing-label="Issuing…"
            @submit="submitSchedule"
        >
            <div class="grid-2">
                <div>
                    <Field label="Election" :error="schedForm.errors.election_id">
                        <template #control="{ id }">
                            <select :id="id" v-model="schedForm.election_id" class="select">
                                <option v-for="e in schedulable" :key="e.election_id" :value="e.election_id">
                                    {{ e.label }}
                                </option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        label="Finalist cutoff"
                        :hint="selectedElection
                            ? `X per race is pre-published with this order — ${selectedElection.races.map((r) => `${r.label}: X = ${r.finalist_count}`).join(' · ')} · CLK-21`
                            : 'X per race is pre-published with this order · CLK-21'"
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
                    <Field label="Ranked window opens" :error="schedForm.errors.ranked_opens_at">
                        <template #control="{ id }">
                            <input :id="id" v-model="schedForm.ranked_opens_at" class="field-input" type="datetime-local" />
                        </template>
                    </Field>
                    <Field
                        label="Ranked window closes"
                        hint="Entered and stored as UTC — the engine validates window ordering and phase lengths."
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
                    Validation queue
                    <span class="citation">Candidate validation · F-ELB-002</span>
                </h2>
            </template>
            <p class="citation">available to R-08 · prereq: F-IND-011 submitted · Art. II §2 (election integrity)</p>
            <p class="cc-small">
                Residency association is the <strong>only</strong> permissible check. A rejection
                is appealable in court.
            </p>

            <DataTable
                v-if="queueRows.length"
                :columns="[
                    { key: 'name', label: 'Registrant' },
                    { key: 'office', label: 'Office' },
                    { key: 'residency', label: 'Residency record' },
                    { key: 'decision', label: 'Decision' },
                ]"
                :rows="queueRows"
                row-key="candidacy_id"
                caption="Pending candidacy registrations"
            >
                <template #cell-residency="{ row }">
                    <StatusBadge v-if="row.residency.found" tone="success" icon="check">
                        found{{ row.residency.slug ? ` · ${row.residency.slug}` : '' }}{{ row.residency.duplicate ? ' · duplicate registration flag' : '' }}
                    </StatusBadge>
                    <StatusBadge v-else tone="danger" icon="alert-triangle">not found in jurisdiction</StatusBadge>
                </template>
                <template #cell-decision="{ row }">
                    <StatusBadge v-if="row.decision === 'validate'" tone="success" icon="check">
                        validated · in approval pool
                    </StatusBadge>
                    <template v-else-if="row.decision === 'reject'">
                        <StatusBadge tone="danger" icon="x">rejected · appeal path open</StatusBadge>
                        {{ ' ' }}
                        <span class="planned-flag">court appeal · Planned · Phase E</span>
                    </template>
                    <span v-else class="cluster" style="gap: var(--space-1)">
                        <Btn
                            variant="secondary"
                            size="sm"
                            :disabled="!!decideBusy[row.candidacy_id]"
                            @click="decide(row, 'validate')"
                        >Validate</Btn>
                        <Btn
                            variant="ghost"
                            size="sm"
                            :disabled="!!decideBusy[row.candidacy_id]"
                            @click="decide(row, 'reject')"
                        >Reject</Btn>
                    </span>
                </template>
            </DataTable>
            <p v-else class="gloss">No registrations awaiting validation.</p>
        </Card>

        <div class="grid-2">
            <!-- ===================================== district oversight == -->
            <Card as="section">
                <template #title>
                    <h2>
                        District-map oversight
                        <span class="citation">Subdivision boundary drawing · F-ELB-003</span>
                    </h2>
                </template>
                <p class="citation">available to R-08 · prereq: legislature seat count &gt; 9 · Art. II §2; Art. II §8 (Subdivision)</p>
                <DataTable
                    v-if="districtOversight.length"
                    :columns="[
                        { key: 'name', label: 'Plan' },
                        { key: 'districts', label: 'Districts' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="districtOversight"
                    row-key="map_id"
                    caption="District map plans under oversight"
                >
                    <template #cell-districts="{ row }">
                        {{ row.district_count }} · seats {{ row.seat_string }}
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge v-if="row.status === 'active'" tone="success" icon="check">active</StatusBadge>
                        <StatusBadge v-else-if="row.status === 'draft'" tone="info" icon="map">draft · published for observation</StatusBadge>
                        <StatusBadge v-else tone="neutral">{{ row.status }}</StatusBadge>
                    </template>
                </DataTable>
                <p v-else class="gloss">
                    No district maps under oversight — chambers at or below the 9-seat ceiling
                    run at-large by constitutional default (Art. II §8).
                </p>
                <p style="margin-block-start: var(--space-2)">
                    <Link href="/legislatures">Open the legislature browser →</Link>
                </p>
                <p class="citation">Contiguous, equal subdivisions · Art. II §8</p>
            </Card>

            <!-- ===================================== certification ======= -->
            <Card as="section">
                <template #title>
                    <h2>
                        Certification
                        <span class="citation">Election results certification · F-ELB-004</span>
                    </h2>
                </template>
                <p class="citation">available to R-08 · prereq: voting closed + tabulation complete · Art. II §2 (transparent election process)</p>

                <div v-for="row in certifiable" :key="row.election_id" class="card card--inset" style="margin-block-end: var(--space-3)">
                    <p style="margin-block-end: var(--space-1)"><strong>{{ row.label }}</strong></p>
                    <p class="citation">
                        {{ row.rounds }} rounds · {{ row.seats }} seats ·
                        {{ row.tabulation_complete ? 'tabulation complete' : 'tabulation in progress' }}
                    </p>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn as="a" :href="`/elections/${row.election_id}/results`" variant="secondary" size="sm">
                            Review the count record
                        </Btn>
                        <template v-if="row.certified">
                            <StatusBadge tone="success" icon="check">Certified — winners granted roles</StatusBadge>
                        </template>
                        <Btn
                            v-else
                            variant="primary"
                            size="sm"
                            :disabled="!row.tabulation_complete || !!certBusy[row.election_id]"
                            @click="certify(row.election_id)"
                        >Certify results</Btn>
                    </div>
                    <hr />
                    <h3>Recount <span class="citation">Recount/audit order · F-ELB-006</span></h3>
                    <div class="cluster">
                        <StatusBadge v-if="row.recount.ordered" tone="danger" icon="refresh-cw">
                            Recount proceedings open · WF-ELE-05
                        </StatusBadge>
                        <template v-else-if="recountFor !== row.election_id">
                            <Btn
                                variant="danger"
                                size="sm"
                                :disabled="!row.certified"
                                :title="row.certified ? undefined : 'Requires certification first'"
                                @click="recountFor = row.election_id"
                            >Order recount</Btn>
                            <span class="citation">
                                {{ row.certified ? 'cause must be stated on the order' : 'enabled after certification' }}
                                · opens WF-ELE-05
                            </span>
                        </template>
                    </div>
                    <div v-if="recountFor === row.election_id" class="field" style="margin-block-start: var(--space-2)">
                        <label class="field-label" :for="`cause-${row.election_id}`">Cause for the audit re-run (required)</label>
                        <textarea :id="`cause-${row.election_id}`" v-model="recountCause" class="field-input" rows="2"></textarea>
                        <span class="field-hint">The engine rejects an order without a stated cause.</span>
                        <div class="cluster" style="margin-block-start: var(--space-2)">
                            <Btn
                                variant="danger"
                                size="sm"
                                :disabled="!recountCause.trim() || !!certBusy[row.election_id]"
                                @click="orderRecount(row.election_id)"
                            >Confirm recount order</Btn>
                            <Btn variant="ghost" size="sm" @click="recountFor = null; recountCause = ''">Cancel</Btn>
                        </div>
                    </div>
                </div>
                <p v-if="!certifiable.length" class="gloss">
                    Nothing awaiting certification — counts appear here the moment a ranked
                    window closes.
                </p>
            </Card>
        </div>

        <div class="grid-2">
            <!-- ===================================== signature audit ===== -->
            <Card as="section">
                <template #title>
                    <h2>
                        Signature audit
                        <span class="citation">Petition signature audit · F-ELB-005</span>
                    </h2>
                </template>
                <p class="citation">available to R-08 · prereq: petition at threshold · Art. II §6 (independent audit)</p>
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
                            {{ row.signatures.toLocaleString() }} live signatures · threshold {{ row.threshold_count.toLocaleString() }}
                        </p>
                        <p v-if="row.result" class="cc-small">
                            {{ row.result.valid.toLocaleString() }} of {{ row.result.checked.toLocaleString() }} valid
                            ({{ row.result.pct_valid }}%) —
                            {{ row.result.still_above ? 'still above threshold' : 'below threshold — invalidated (kill-path)' }}
                        </p>
                        <Btn
                            v-if="row.due"
                            variant="primary"
                            size="sm"
                            :disabled="auditingPetition === row.petition_id"
                            @click="runPetitionAudit(row)"
                        >Run signature audit (F-ELB-005)</Btn>
                    </div>
                </div>
                <p v-else class="gloss">No petitions at threshold.</p>
            </Card>

            <!-- ===================================== vacancies =========== -->
            <Card as="section" title="Vacancies">
                <div v-if="vacancies.length" class="stack" style="gap: var(--space-3)">
                    <div v-for="vacancy in vacancies" :key="vacancy.vacancy_id" class="card card--inset">
                        <p style="margin-block-end: var(--space-1)"><strong>{{ vacancy.label }}</strong></p>
                        <p class="citation">Vacancy declaration received · F-LEG-036 · countback per Art. II §5</p>
                        <div class="cluster" style="margin-block-start: var(--space-2)">
                            <StatusBadge
                                :tone="vacancy.status === 'filled' ? 'success' : vacancy.status === 'countback_failed' ? 'danger' : 'warning'"
                            >{{ vacancy.status }}</StatusBadge>
                            <Btn as="a" :href="`/vacancies/${vacancy.vacancy_id}`" variant="secondary" size="sm">
                                Open the countback view
                            </Btn>
                        </div>
                    </div>
                </div>
                <p v-else class="gloss">No vacancies — every seat in the jurisdiction is held.</p>
            </Card>
        </div>

        <template #about>
            <p>
                <strong>Bootstrap variant:</strong> a bootstrap board administers only the first
                election and carries the persistent replacement warning until the seated
                legislature appoints a proper board (WF-ELE-10).
            </p>
        </template>
    </PageScaffold>
</template>
