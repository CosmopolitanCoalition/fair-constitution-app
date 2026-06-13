<script setup>
/**
 * Executive/Actions — FE-D4 (PHASE_D_DESIGN_frontend.md §B.5; surface
 * executive/executive-actions) — THE order-rejection exit surface.
 *
 * The hardened scope-validation rails banner renders REGARDLESS of whether
 * any order exists (that is the point). The order composer (F-EXE-005) is
 * the exit-criterion-1 door: an out-of-scope POST returns 422 — the engine
 * citation renders verbatim in the error Banner AND the rejected order
 * reloads at the TOP of the register as an OrderScopeCard --rejected with
 * its public-record #seq chip, because ExecutiveOrderService::preflight
 * persisted the rejected_pre_issuance row + record before the rethrow.
 *
 * Every number here is a row snapshot — the engine owns the scope rules
 * (preflight) and the grant arithmetic (GrantService FOR UPDATE). This
 * page renders rows and opens form doors; it computes nothing.
 */
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import OrderScopeCard from '@/Components/Executive/OrderScopeCard.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.1 header: { id, type, status, jurisdiction, delegated_scope_text }. */
    executive: { type: Object, required: true },
    /** ESM order lifecycle (PHP-owned). */
    orderMachine: { type: Array, default: () => [] },
    /** { delegation_act:{label,href}|null, active_powers:[{label,area,expires_at,href}] }. */
    scopeBanner: { type: Object, default: () => ({ delegation_act: null, active_powers: [] }) },
    /** OrderScopeCard props, newest first. */
    orders: { type: Array, default: () => [] },
    /** { departmentOptions:[{id,name}], enablingOptions:[{type,id,label}] }. */
    orderForm: { type: Object, default: () => ({ departmentOptions: [], enablingOptions: [] }) },
    proposals: { type: Array, default: () => [] },
    investigations: { type: Array, default: () => [] },
    appropriations: { type: Array, default: () => [] },
    applications: { type: Array, default: () => [] },
    /** { orgOptions:[{id,name}] }. */
    grantForm: { type: Object, default: () => ({ orgOptions: [] }) },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
/* The engine 422: ConstitutionalViolation surfaces as errors.constitution
   carrying "{message} ({citation})" — the verbatim rejection (criterion 1). */
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const surfaceForm = (id) => props.surface.forms.find((f) => f.id === id) ?? null;

const forming = computed(() => !['delegated', 'elected'].includes(props.executive.status));

/* The option lists arrive on the `orderForm` PROP; alias them so the
   Inertia form below can safely take a non-colliding name. */
const departmentOptions = computed(() => props.orderForm.departmentOptions ?? []);
const enablingOptions = computed(() => props.orderForm.enablingOptions ?? []);

/* ----------------------------------------------------------- order ----- */
const issueForm = useForm({
    title: '',
    department_id: '',
    body: '',
    enabling: '',
    target_domain: '',
});

const DOMAINS = [
    { value: 'department_operations', label: 'Department operations' },
    { value: 'public_works', label: 'Public works' },
    { value: 'emergency_response', label: 'Emergency response' },
    { value: 'administration', label: 'Administration' },
    { value: 'other', label: 'Other' },
];

function submitOrder() {
    const [enabling_type, enabling_id] = (issueForm.enabling || '::').split('::');
    router.post(
        `/executives/${props.executive.id}/orders`,
        {
            form_id: 'F-EXE-005',
            title: issueForm.title,
            department_id: issueForm.department_id || null,
            body: issueForm.body,
            enabling_type,
            enabling_id,
            target_domain: issueForm.target_domain,
        },
        {
            preserveScroll: true,
            onSuccess: () => issueForm.reset('title', 'body'),
        },
    );
}

/* -------------------------------------------------------- proposal ----- */
const proposalForm = useForm({ department_id: '', title: '', text: '' });
function submitProposal() {
    proposalForm.post(`/executives/${props.executive.id}/policy-proposals`, { preserveScroll: true });
}

const proposalColumns = [
    { key: 'title', label: 'Proposal' },
    { key: 'department', label: 'Department' },
    { key: 'status', label: 'Board decision' },
];
const PROPOSAL_TONE = {
    pending: 'info',
    adopted: 'success',
    amended: 'warning',
    declined: 'danger',
};

/* --------------------------------------------------- investigation ----- */
const investigationForm = useForm({ department_id: '', scope: '' });
function submitInvestigation() {
    investigationForm.post(`/executives/${props.executive.id}/investigations`, { preserveScroll: true });
}

const investigationColumns = [
    { key: 'title', label: 'Investigation' },
    { key: 'department', label: 'Department' },
    { key: 'status', label: 'Outcome' },
];
const INVESTIGATION_TONE = {
    open: 'info',
    policy_proposal: 'success',
    removal_request: 'warning',
    legislative_referral: 'info',
    closed_no_finding: 'neutral',
};

/* ---------------------------------------------------------- grants ----- */
const appropriationColumns = [
    { key: 'line', label: 'Line' },
    { key: 'act', label: 'Enacting act' },
    { key: 'appropriated', label: 'Appropriated', mono: true, align: 'right' },
    { key: 'remaining', label: 'Remaining', mono: true, align: 'right' },
];
const applicationColumns = [
    { key: 'org', label: 'Organization' },
    { key: 'line', label: 'Line' },
    { key: 'amount', label: 'Amount', mono: true, align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'disbursements', label: 'Disbursements' },
];
const APPLICATION_TONE = { submitted: 'info', awarded: 'success', declined: 'danger', withdrawn: 'neutral' };

const grantApply = useForm({ appropriation_id: '', applicant_org_id: '', amount: '', purpose: '' });
function submitApplication() {
    if (!grantApply.appropriation_id) return;
    grantApply.post(`/appropriations/${grantApply.appropriation_id}/applications`, {
        preserveScroll: true,
        onSuccess: () => grantApply.reset('amount', 'purpose'),
    });
}

function money(n) {
    return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <PageScaffold :surface="surface" title="Executive actions">
        <template #intro>
            Orders, policy proposals, investigations, and grants — every one inside the
            executive's delegated scope. An order's scope is validated <em>before</em> it can
            issue; a rejected order never takes effect, but the attempt itself goes on the
            public record. The rails apply from the first order.
        </template>

        <!-- engine 422: the rejection citation, verbatim (exit criterion 1) -->
        <Banner v-if="constitutionError" tone="emergency" role="alert" title="The order was not issued.">
            {{ constitutionError }}
            <span class="cc-small" style="display: block; margin-block-start: var(--space-1)">
                The order never took effect; the rejected attempt is on the public record —
                see the top of the register below.
            </span>
        </Banner>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- ==================================== the hardened rails ====== -->
        <Card as="section" title="Scope validation happens before issuance">
            <Banner tone="info" role="status">
                Scope validation happens <strong>before</strong> issuance — an order outside the
                delegated scope is rejected and never takes effect. Elections, sessions, and
                courts cannot be disrupted, even under emergency powers.
                <span style="display: block; margin-block-start: var(--space-2)">
                    <HardenedChip>civic-process protection · Art. II §7 — hardened</HardenedChip>
                </span>
            </Banner>

            <div class="stack" style="gap: var(--space-2); margin-block-start: var(--space-3)">
                <p class="cc-small">
                    <strong>Delegated scope:</strong>
                    <template v-if="executive.delegated_scope_text">{{ executive.delegated_scope_text }}</template>
                    <span v-else class="gloss">no delegated scope yet — the delegation act has not enacted (F-LEG-014)</span>
                </p>
                <p v-if="scopeBanner.delegation_act" class="citation">
                    enabling delegation ·
                    <Link :href="scopeBanner.delegation_act.href">{{ scopeBanner.delegation_act.label }} →</Link>
                </p>
                <div v-if="scopeBanner.active_powers.length">
                    <p class="cc-small" style="margin-block-end: var(--space-1)">
                        <strong>Active emergency powers</strong> — these widen the delegated scope
                        only within their declared area and duration · Art. II §7 · CLK-03:
                    </p>
                    <p v-for="power in scopeBanner.active_powers" :key="power.label" class="citation">
                        <Link :href="power.href">{{ power.label }} →</Link>
                        <template v-if="power.area"> · area {{ power.area }}</template>
                        <template v-if="power.expires_at"> · expires {{ power.expires_at }}</template>
                    </p>
                </div>
            </div>
        </Card>

        <!-- ==================================== order composer ========== -->
        <template v-if="surfaceForm('F-EXE-005')">
            <FormCard
                :form="surfaceForm('F-EXE-005')"
                :inertia-form="issueForm"
                :disabled="!can.issueOrder"
                submit-label="Issue order"
                processing-label="Validating scope…"
                @submit="submitOrder"
            >
                <Banner v-if="forming" tone="warning" role="status" title="No delegated scope exists yet — F-LEG-014.">
                    This executive is still <strong>{{ executive.status }}</strong>. Orders issue
                    from a delegated or elected executive only.
                </Banner>

                <Field label="Title" :error="issueForm.errors.title">
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="issueForm.title"
                            class="field-input"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>

                <Field label="Department" hint="The order may name a department this executive oversees." :error="issueForm.errors.department_id">
                    <template #control="{ id, describedBy }">
                        <select :id="id" v-model="issueForm.department_id" class="select" :aria-describedby="describedBy">
                            <option value="">— jurisdiction-wide (no department) —</option>
                            <option v-for="d in departmentOptions" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </template>
                </Field>

                <Field label="Enabling basis" hint="The live instrument the order executes — a delegation/charter act or an active emergency power. The order may not exceed it." :error="issueForm.errors.enabling_id">
                    <template #control="{ id, describedBy }">
                        <select :id="id" v-model="issueForm.enabling" class="select" :aria-describedby="describedBy">
                            <option value="">— select an enabling instrument —</option>
                            <option
                                v-for="opt in enablingOptions"
                                :key="`${opt.type}::${opt.id}`"
                                :value="`${opt.type}::${opt.id}`"
                            >{{ opt.label }}</option>
                        </select>
                    </template>
                </Field>

                <Field label="Target domain" :error="issueForm.errors.target_domain">
                    <template #control="{ id, describedBy }">
                        <select :id="id" v-model="issueForm.target_domain" class="select" :aria-describedby="describedBy">
                            <option value="">— select a domain —</option>
                            <option v-for="dom in DOMAINS" :key="dom.value" :value="dom.value">{{ dom.label }}</option>
                        </select>
                    </template>
                </Field>

                <Field label="Order body" :error="issueForm.errors.body">
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="issueForm.body"
                            class="field-input"
                            rows="4"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>

                <p class="citation">
                    electoral, judicial, and legislative process domains are rejected
                    unconditionally — the engine validates the order pre-issuance · Art. III §2 · Art. II §7
                </p>
            </FormCard>
        </template>

        <!-- ==================================== order register ========== -->
        <Card as="section" title="Order register — issued and rejected on one record">
            <p class="citation">
                A rejected order is appended to the same register it would have joined — the
                rejection itself is the public record (Art. II §2).
            </p>
            <div v-if="orders.length">
                <OrderScopeCard v-for="order in orders" :key="order.id_display" :order="order" />
            </div>
            <Banner v-else tone="info" role="status">
                No orders issued — the scope-validation rails apply from the first order.
            </Banner>
        </Card>

        <!-- ==================================== proposals + invest. ===== -->
        <div class="grid-2">
            <Card as="section" title="Policy proposals">
                <p class="citation">
                    The executive proposes; the department <strong>board</strong> adopts, amends, or
                    declines — proposals do not bypass the board · Art. III §4.
                </p>
                <DataTable
                    v-if="proposals.length"
                    :columns="proposalColumns"
                    :rows="proposals"
                    caption="Policy proposals"
                >
                    <template #cell-department="{ row }">
                        <Link v-if="row.department" :href="row.department.href">{{ row.department.name }}</Link>
                        <span v-else class="gloss">—</span>
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="PROPOSAL_TONE[row.status] ?? 'neutral'">{{ row.status }}</StatusBadge>
                        <span v-if="row.decided_at" class="cc-small" style="display: block">{{ row.decided_at }}</span>
                    </template>
                </DataTable>
                <p v-else class="gloss">No policy proposals filed.</p>

                <FormCard
                    v-if="surfaceForm('F-EXE-002')"
                    :form="surfaceForm('F-EXE-002')"
                    :inertia-form="proposalForm"
                    :disabled="!can.propose"
                    submit-label="Propose to the board"
                    @submit="submitProposal"
                >
                    <Field label="Department" :error="proposalForm.errors.department_id">
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="proposalForm.department_id" class="select" :aria-describedby="describedBy">
                                <option value="">— select a department —</option>
                                <option v-for="d in departmentOptions" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field label="Title" :error="proposalForm.errors.title">
                        <template #control="{ id, describedBy }">
                            <input :id="id" v-model="proposalForm.title" class="field-input" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <Field label="Proposal text" :error="proposalForm.errors.text">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="proposalForm.text" class="field-input" rows="3" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                </FormCard>
            </Card>

            <Card as="section" title="Investigations">
                <p class="citation">
                    Full and equal investigative power over overseen departments · Art. III §4 —
                    findings publish to the public record; the outcome branch files a proposal,
                    a removal request, or a legislative referral, or closes with no finding.
                </p>
                <DataTable
                    v-if="investigations.length"
                    :columns="investigationColumns"
                    :rows="investigations"
                    caption="Investigations"
                >
                    <template #cell-department="{ row }">
                        <span>{{ row.department ?? '—' }}</span>
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="INVESTIGATION_TONE[row.status] ?? 'neutral'">
                            {{ (row.status ?? '').replaceAll('_', ' ') }}
                        </StatusBadge>
                        <Link v-if="row.findings_record_href" :href="row.findings_record_href" class="cc-small" style="display: block">
                            findings record →
                        </Link>
                    </template>
                </DataTable>
                <p v-else class="gloss">No investigations ordered.</p>

                <FormCard
                    v-if="surfaceForm('F-EXE-004')"
                    :form="surfaceForm('F-EXE-004')"
                    :inertia-form="investigationForm"
                    :disabled="!can.investigate"
                    submit-label="Order investigation"
                    @submit="submitInvestigation"
                >
                    <Field label="Department" hint="Leave blank for an executive-wide investigation." :error="investigationForm.errors.department_id">
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="investigationForm.department_id" class="select" :aria-describedby="describedBy">
                                <option value="">— executive-wide —</option>
                                <option v-for="d in departmentOptions" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field label="Scope" :error="investigationForm.errors.scope">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="investigationForm.scope" class="field-input" rows="3" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                </FormCard>
            </Card>
        </div>

        <!-- ==================================== grants & approp. ======== -->
        <Card as="section" title="Grants &amp; appropriations">
            <p class="citation">
                The legislature appropriates by act; the executive administers. Awards never
                exceed the line's remaining balance, and every award and disbursement is
                appended to the audit chain · WF-SYS-04.
            </p>

            <DataTable
                v-if="appropriations.length"
                :columns="appropriationColumns"
                :rows="appropriations"
                row-key="id"
                caption="Appropriation lines"
            >
                <template #cell-act="{ row }">
                    <Link v-if="row.act" :href="row.act.href">{{ row.act.act_number }}</Link>
                    <span v-else class="gloss">—</span>
                </template>
                <template #cell-appropriated="{ row }">
                    <span class="mono">{{ money(row.appropriated) }}</span>
                </template>
                <template #cell-remaining="{ row }">
                    <span class="mono">{{ money(row.remaining) }}</span>
                </template>
            </DataTable>
            <Banner v-else tone="info" role="status">
                The legislature has appropriated no funds — appropriation is an act.
                <Link href="/legislature/bills?intro=1">Open a bill →</Link>
            </Banner>

            <template v-if="appropriations.length">
                <h3 style="margin-block: var(--space-4) var(--space-2)">Applications</h3>
                <DataTable
                    v-if="applications.length"
                    :columns="applicationColumns"
                    :rows="applications"
                    row-key="id"
                    caption="Grant applications"
                >
                    <template #cell-org="{ row }">
                        <Link v-if="row.org" :href="row.org.href">{{ row.org.name }}</Link>
                        <span v-else class="gloss">—</span>
                    </template>
                    <template #cell-amount="{ row }">
                        <span class="mono">{{ money(row.amount) }}</span>
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="APPLICATION_TONE[row.status] ?? 'neutral'">{{ row.status }}</StatusBadge>
                    </template>
                    <template #cell-disbursements="{ row }">
                        <template v-if="row.disbursements.length">
                            <span
                                v-for="(d, i) in row.disbursements"
                                :key="i"
                                class="tag-chip"
                                style="margin-inline-end: var(--space-1)"
                                data-no-i18n
                            >{{ money(d.amount) }} · seq #{{ d.audit_seq ?? '—' }}</span>
                        </template>
                        <span v-else class="gloss">none</span>
                    </template>
                </DataTable>
                <p v-else class="gloss">No applications submitted against these lines.</p>

                <Card inset eyebrow="Apply for a grant" style="margin-block-start: var(--space-3)">
                    <form novalidate @submit.prevent="submitApplication">
                        <Field label="Appropriation line" :error="grantApply.errors.appropriation_id">
                            <template #control="{ id, describedBy }">
                                <select :id="id" v-model="grantApply.appropriation_id" class="select" :aria-describedby="describedBy">
                                    <option value="">— select a line —</option>
                                    <option v-for="a in appropriations" :key="a.id" :value="a.id">
                                        {{ a.line }} ({{ money(a.remaining) }} remaining)
                                    </option>
                                </select>
                            </template>
                        </Field>
                        <Field label="Organization" :error="grantApply.errors.applicant_org_id">
                            <template #control="{ id, describedBy }">
                                <select :id="id" v-model="grantApply.applicant_org_id" class="select" :aria-describedby="describedBy">
                                    <option value="">— select an organization —</option>
                                    <option v-for="o in grantForm.orgOptions" :key="o.id" :value="o.id">{{ o.name }}</option>
                                </select>
                            </template>
                        </Field>
                        <Field label="Amount" :error="grantApply.errors.amount">
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model="grantApply.amount" type="number" min="0" step="0.01" class="field-input" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                        <Field label="Purpose" :error="grantApply.errors.purpose">
                            <template #control="{ id, describedBy }">
                                <textarea :id="id" v-model="grantApply.purpose" class="field-input" rows="2" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                        <div class="cluster">
                            <button type="submit" class="btn btn--primary" :disabled="grantApply.processing">
                                {{ grantApply.processing ? 'Submitting…' : 'Submit application' }}
                            </button>
                        </div>
                    </form>
                </Card>
            </template>
        </Card>

        <template #about>
            <p>
                Executive orders execute the delegated scope; they are validated before issuance
                and remain judicially reviewable at any time (Art. IV §5). The order lifecycle:
            </p>
            <StateStrip v-if="orderMachine.length" :states="orderMachine" />
        </template>
    </PageScaffold>
</template>
