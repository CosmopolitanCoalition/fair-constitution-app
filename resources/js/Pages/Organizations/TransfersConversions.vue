<script setup>
/**
 * Organizations/TransfersConversions — FE-D9 (PHASE_D_DESIGN_frontend.md
 * §B.11; surface organizations/transfers-conversions).
 *
 * Four constitutional ownership paths, each its own card:
 *   1. Mutual transfer — F-ORG-005, BOTH consents on record (no hostile path).
 *   2. Monopoly acquisition — F-LEG-026, a legislative act at ORDINARY
 *      majority of all serving (the ONLY path overriding owner consent),
 *      compensation ≥ the recorded fair-market floor (hardened · Art. III
 *      §5). LifecycleTracker over the 5 stages; the vote stage renders a
 *      MAJORITY VoteTally; compensation renders the HardenedChip floor.
 *   3. Public↔private conversion — F-ORG-006 request → F-LEG-027 bill.
 *   4. Internal restructuring (owner consent per the structure's own rules)
 *      + voluntary dissolution (F-ORG-007); judicial dissolution is Phase E.
 *
 * CONSTITUTIONAL POSTURE — pure renderer: the acquisition vote numbers are
 * engine snapshots; the floor + compensation are recorded facts. Every POST
 * runs through the engine; a ConstitutionalViolation renders verbatim in
 * the emergency Banner with its citation.
 */
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import OrganizationNav from '@/Components/Organizations/OrganizationNav.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import LifecycleTracker from '@/Components/Ui/LifecycleTracker.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    focus: { type: Object, default: null },
    transfers: { type: Array, default: () => [] },
    acquisitions: { type: Array, default: () => [] },
    conversions: { type: Array, default: () => [] },
    restructurings: { type: Array, default: () => [] },
    dissolutions: { type: Array, default: () => [] },
    deepLinks: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: null },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* Forms — all target the focused org (?org=); the engine binds the agent. */
const transferForm = useForm({ to_party_type: 'organizations', to_party_id: '', terms: '' });
const conversionForm = useForm({ direction: 'private_to_cgc', rationale: '' });
const dissolutionForm = useForm({ reason: '' });

function submitTransfer() {
    if (!props.urls?.transfer) return;
    transferForm.post(props.urls.transfer, { preserveScroll: true, onSuccess: () => transferForm.reset() });
}
function consentTransfer(consentUrl) {
    router.post(consentUrl, {}, { preserveScroll: true });
}
function submitConversion() {
    if (!props.urls?.conversionRequest) return;
    conversionForm.post(props.urls.conversionRequest, { preserveScroll: true, onSuccess: () => conversionForm.reset() });
}
function submitDissolution() {
    if (!props.urls?.dissolution) return;
    dissolutionForm.post(props.urls.dissolution, { preserveScroll: true, onSuccess: () => dissolutionForm.reset() });
}

/* F-ORG-009 — the owners' act. Proposing consents; the consent that meets
   the current structure's own rule adopts, in the same act. */
const restructureForm = useForm({ to_structure: 'partnership' });

function submitRestructure() {
    if (!props.urls?.restructure) return;
    restructureForm.post(props.urls.restructure, { preserveScroll: true, onSuccess: () => restructureForm.reset() });
}
function consentRestructure(consentUrl) {
    router.post(consentUrl, {}, { preserveScroll: true });
}

/* The 5 monopoly-acquisition stages (mockup order). stage_index is a server
   snapshot read off the conversion status — never computed as policy here. */
const acquisitionStages = [
    t('c_institutions.transfers_conversions.stage_finding', 'Legislative finding'),
    t('c_institutions.transfers_conversions.stage_vote', 'Acquisition vote · F-LEG-026'),
    t('c_institutions.transfers_conversions.stage_compensation', 'Compensation ≥ fair market'),
    t('c_institutions.transfers_conversions.stage_conversion', 'Conversion to CGC'),
    t('c_institutions.transfers_conversions.stage_governors', 'Founding governor seats offered'),
];

function transferStatusTone(status) {
    return { proposed: 'info', consented: 'warning', completed: 'success', abandoned: 'neutral' }[status] ?? 'neutral';
}
function consentBadge(at) {
    return at
        ? { tone: 'success', icon: 'check', text: t('c_institutions.transfers_conversions.consented_at', { at }) }
        : { tone: 'neutral', icon: 'clock', text: t('c_institutions.transfers_conversions.awaiting_consent', 'awaiting consent') };
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_institutions.transfers_conversions.page_title', 'Transfers and conversions')">
        <template #intro>
            {{ t('c_institutions.transfers_conversions.intro', 'An organization\'s ownership can change five ways, and this page shows all five: sold by mutual agreement, acquired by the legislature when a monopoly is found, converted between public and private, restructured within private hands, or wound down. Only the monopoly path ever overrides the owners\' consent — and it carries a protected compensation floor.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <OrganizationNav v-if="focus" :organization="focus" current="ownership" />
        <Banner v-else tone="info" role="status" :title="t('c_institutions.transfers_conversions.choose_org_title', 'Choose an organization to manage ownership changes.')">
            <Link href="/organizations">{{ t('c_institutions.transfers_conversions.find_org_link', 'Find an organization') }}</Link> {{ t('c_institutions.transfers_conversions.choose_org_after', 'and open its Ownership changes workspace.') }}
        </Banner>

        <!-- ============================ 1. mutual transfer ============== -->
        <Card as="section" :title="t('c_institutions.transfers_conversions.transfer_title', 'Mutual transfer — both consents required')">
            <p>
                {{ t('c_institutions.transfers_conversions.transfer_body_before', 'Ownership transfers only by mutual consent (') }}<FormChip form-id="F-ORG-005" />{{ t('c_institutions.transfers_conversions.transfer_body_after', ', WF-ORG-06): the current owner initiates and the transferee consents. Both consents must be on record; the engine rejects completion with anything less.') }}
            </p>

            <DataTable
                v-if="transfers.length"
                :columns="[
                    { key: 'from', label: t('c_institutions.transfers_conversions.col_from', 'From') },
                    { key: 'to', label: t('c_institutions.transfers_conversions.col_to', 'To') },
                    { key: 'consents', label: t('c_institutions.transfers_conversions.col_consents', 'Consents') },
                    { key: 'ffc', label: t('c_institutions.transfers_conversions.col_ffc', 'FF&C sync') },
                    { key: 'status', label: t('c_institutions.transfers_conversions.col_status', 'Status') },
                ]"
                :rows="transfers"
                row-key="id"
                :caption="t('c_institutions.transfers_conversions.transfers_caption', 'Ownership transfers')"
                style="margin-block-start: var(--space-3)"
            >
                <template #cell-from="{ row }">
                    <Link v-if="row.from" :href="row.from.href">{{ row.from.name }}</Link>
                    <span v-else class="gloss">—</span>
                </template>
                <template #cell-to="{ row }">
                    <span data-no-i18n>{{ row.to.type }} · {{ row.to.name }}</span>
                </template>
                <template #cell-consents="{ row }">
                    <StatusBadge :tone="consentBadge(row.consent_a_at).tone" :icon="consentBadge(row.consent_a_at).icon">
                        {{ t('c_institutions.transfers_conversions.consent_from', 'from:') }} {{ consentBadge(row.consent_a_at).text }}
                    </StatusBadge>
                    <StatusBadge
                        :tone="consentBadge(row.consent_b_at).tone"
                        :icon="consentBadge(row.consent_b_at).icon"
                        style="margin-inline-start: var(--space-1)"
                    >
                        {{ t('c_institutions.transfers_conversions.consent_to', 'to:') }} {{ consentBadge(row.consent_b_at).text }}
                    </StatusBadge>
                    <div v-if="can.initiateTransfer && row.status === 'proposed' && !row.consent_b_at" style="margin-block-start: var(--space-1)">
                        <button type="button" class="form-chip" @click="consentTransfer(row.consent_url)">
                            {{ t('c_institutions.transfers_conversions.consent_transferee', 'Consent as transferee →') }}
                        </button>
                    </div>
                </template>
                <template #cell-ffc="{ row }">
                    <span v-if="row.ffc_synced_at" class="mono">{{ row.ffc_synced_at }}</span>
                    <span v-else class="citation" data-no-i18n>syncs on federation · Phase F</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="transferStatusTone(row.status)">{{ row.status }}</StatusBadge>
                </template>
            </DataTable>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">{{ t('c_institutions.transfers_conversions.no_transfers', 'No transfers on record.') }}</p>

            <div v-if="urls?.transfer" style="margin-block-start: var(--space-4)">
                <FormCard
                    :form="surface.forms.find((f) => f.id === 'F-ORG-005')"
                    :inertia-form="transferForm"
                    :submit-label="t('c_institutions.transfers_conversions.transfer_submit', 'Initiate transfer')"
                    :processing-label="t('c_institutions.transfers_conversions.transfer_processing', 'Initiating…')"
                    @submit="submitTransfer"
                >
                    <Field :label="t('c_institutions.transfers_conversions.f_transferee_type', 'Transferee type')" :error="transferForm.errors.to_party_type" required>
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="transferForm.to_party_type" class="select" required :aria-describedby="describedBy">
                                <option value="organizations">{{ t('c_institutions.transfers_conversions.opt_organization', 'Organization') }}</option>
                                <option value="users">{{ t('c_institutions.transfers_conversions.opt_individual', 'Individual') }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field :label="t('c_institutions.transfers_conversions.f_transferee_id', 'Transferee id')" :hint="t('c_institutions.transfers_conversions.f_transferee_id_hint', 'The receiving organization or individual.')" :error="transferForm.errors.to_party_id" required>
                        <template #control="{ id, describedBy, invalid }">
                            <input :id="id" v-model="transferForm.to_party_id" class="field-input" type="text" required :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <Field :label="t('c_institutions.transfers_conversions.f_terms', 'Terms')" :error="transferForm.errors.terms">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="transferForm.terms" class="field-input" rows="2" :aria-describedby="describedBy"></textarea>
                        </template>
                    </Field>
                </FormCard>
            </div>
        </Card>

        <!-- ====================== 2. monopoly acquisition =============== -->
        <Card as="section" :title="t('c_institutions.transfers_conversions.acquisition_title', 'Monopoly acquisition — the one path that overrides owner consent')">
            <p>
                {{ t('c_institutions.transfers_conversions.acquisition_body_before', 'When the legislature finds monopolistic control, it may acquire the enterprise for the public (') }}<FormChip form-id="F-LEG-026" />{{ t('c_institutions.transfers_conversions.acquisition_body_after', ', WF-ORG-07). This is a legislative act at ordinary majority of all serving — the only path that proceeds without owner consent — and the owners are paid at or above fair market value. It is initiated through the bill flow.') }}
            </p>
            <p v-if="deepLinks.monopolyAcquisition" class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="deepLinks.monopolyAcquisition">{{ t('c_institutions.transfers_conversions.intro_monopoly_bill', 'Introduce a monopoly-acquisition bill →') }}</Link>
            </p>

            <template v-if="acquisitions.length">
                <div
                    v-for="(acq, i) in acquisitions"
                    :key="i"
                    class="card card--inset"
                    style="margin-block-start: var(--space-3)"
                >
                    <p style="margin-block-end: var(--space-2)">
                        <strong v-if="acq.org" style="color: var(--gov-fg)">{{ acq.org.name }}</strong>
                        <span v-else class="gloss">{{ t('c_institutions.transfers_conversions.unknown_org', 'unknown organization') }}</span>
                    </p>
                    <LifecycleTracker :stages="acquisitionStages" :current="acquisitionStages[acq.stage_index] ?? acquisitionStages[0]" />

                    <!-- vote stage: ordinary MAJORITY of all serving (owner ruling #13) -->
                    <div v-if="acq.vote" style="margin-block-start: var(--space-3)">
                        <p class="citation">
                            {{ t('c_institutions.transfers_conversions.acquisition_vote_note', 'Acquisition vote — ordinary majority of all serving; the only path overriding owner consent · owner ruling #13.') }}
                        </p>
                        <VoteTally v-bind="acq.vote.tally" :basis="t('c_institutions.transfers_conversions.acquisition_vote_basis', 'Art. III §5 · ordinary majority')" />
                    </div>

                    <!-- compensation stage: the hardened fair-market floor -->
                    <div v-if="acq.compensation?.fair_market_floor" class="cluster" style="margin-block-start: var(--space-3); gap: var(--space-2)">
                        <HardenedChip>{{ t('c_institutions.transfers_conversions.acquisition_floor_chip', 'shareholders paid ≥ fair market — the engine blocks underpayment') }}</HardenedChip>
                        <span class="cc-small" data-no-i18n>
                            compensation {{ acq.compensation.amount ?? '—' }} · floor {{ acq.compensation.fair_market_floor }}
                        </span>
                    </div>

                    <!-- final stage: governor offers to the prior board -->
                    <div v-if="acq.governor_offers?.length" style="margin-block-start: var(--space-3)">
                        <p class="citation">{{ t('c_institutions.transfers_conversions.governor_offers_note', 'Founding governor seats offered to the prior board') }}</p>
                        <DataTable
                            :columns="[{ key: 'user_id', label: t('c_institutions.transfers_conversions.col_prior_board', 'Prior board member'), mono: true }, { key: 'status', label: t('c_institutions.transfers_conversions.col_offer', 'Offer') }]"
                            :rows="acq.governor_offers"
                            :caption="t('c_institutions.transfers_conversions.governor_offers_caption', 'Founding-governor offers')"
                        >
                            <template #cell-status="{ row }">
                                <StatusBadge :tone="{ accepted: 'success', declined: 'neutral' }[row.status] ?? 'info'">
                                    {{ row.status }}
                                </StatusBadge>
                            </template>
                        </DataTable>
                    </div>
                </div>
            </template>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">{{ t('c_institutions.transfers_conversions.no_acquisitions', 'No monopoly acquisitions on record.') }}</p>
        </Card>

        <!-- ==================== 3. public ↔ private conversion ========== -->
        <Card as="section" :title="t('c_institutions.transfers_conversions.conversion_title', 'Public ↔ private conversion')">
            <p>
                {{ t('c_institutions.transfers_conversions.conversion_body_before', 'A conversion request (') }}<FormChip form-id="F-ORG-006" />{{ t('c_institutions.transfers_conversions.conversion_body_between', ') routes to the legislature — a request, not an act. Both directions are legislature-only; the legislature authorizes the conversion by act (') }}<FormChip form-id="F-LEG-027" />{{ t('c_institutions.transfers_conversions.conversion_body_after', '). Public-domain IP irreversibly stays public — new works after privatization follow private rules.') }}
            </p>
            <p v-if="deepLinks.cgcReorgSale" class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="deepLinks.cgcReorgSale">{{ t('c_institutions.transfers_conversions.intro_cgc_bill', 'Introduce a CGC reorganization/sale bill →') }}</Link>
            </p>

            <DataTable
                v-if="conversions.length"
                :columns="[
                    { key: 'org', label: t('c_institutions.transfers_conversions.col_org', 'Organization') },
                    { key: 'direction', label: t('c_institutions.transfers_conversions.col_direction', 'Direction') },
                    { key: 'via', label: t('c_institutions.transfers_conversions.col_via', 'Via') },
                    { key: 'act', label: t('c_institutions.transfers_conversions.col_act', 'Authorizing act') },
                    { key: 'status', label: t('c_institutions.transfers_conversions.col_status', 'Status') },
                ]"
                :rows="conversions"
                :caption="t('c_institutions.transfers_conversions.conversions_caption', 'Conversions')"
                style="margin-block-start: var(--space-3)"
            >
                <template #cell-org="{ row }">
                    <Link v-if="row.org" :href="row.org.href">{{ row.org.name }}</Link>
                    <span v-else class="gloss">—</span>
                </template>
                <template #cell-direction="{ row }"><span data-no-i18n>{{ row.direction.replaceAll('_', ' ') }}</span></template>
                <template #cell-via="{ row }"><span data-no-i18n>{{ row.via.replaceAll('_', ' ') }}</span></template>
                <template #cell-act="{ row }">
                    <Link v-if="row.authorizing_act" :href="row.authorizing_act.href">{{ t('c_institutions.transfers_conversions.record_link', 'record →') }}</Link>
                    <span v-else class="gloss">—</span>
                </template>
                <template #cell-status="{ row }"><StatusBadge tone="info">{{ row.status }}</StatusBadge></template>
            </DataTable>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">{{ t('c_institutions.transfers_conversions.no_conversions', 'No conversions on record.') }}</p>

            <div v-if="urls?.conversionRequest" style="margin-block-start: var(--space-4)">
                <FormCard
                    :form="surface.forms.find((f) => f.id === 'F-ORG-006')"
                    :inertia-form="conversionForm"
                    :submit-label="t('c_institutions.transfers_conversions.conversion_submit', 'File conversion request')"
                    :processing-label="t('c_institutions.transfers_conversions.conversion_processing', 'Filing…')"
                    @submit="submitConversion"
                >
                    <Field :label="t('c_institutions.transfers_conversions.f_direction', 'Direction')" :error="conversionForm.errors.direction" required>
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="conversionForm.direction" class="select" required :aria-describedby="describedBy">
                                <option value="private_to_cgc">{{ t('c_institutions.transfers_conversions.opt_private_to_cgc', 'Private → Common Good Corporation') }}</option>
                                <option value="cgc_to_private">{{ t('c_institutions.transfers_conversions.opt_cgc_to_private', 'Common Good Corporation → private (sale)') }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field :label="t('c_institutions.transfers_conversions.f_rationale', 'Rationale')" :error="conversionForm.errors.rationale">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="conversionForm.rationale" class="field-input" rows="3" :aria-describedby="describedBy"></textarea>
                        </template>
                    </Field>
                </FormCard>
            </div>
        </Card>

        <!-- ============ 4. internal restructuring + dissolution ========= -->
        <Card as="section" :title="t('c_institutions.transfers_conversions.restructuring_title', 'Internal restructuring and dissolution')">
            <p>
                {{ t('c_institutions.transfers_conversions.restructuring_body', 'Internal restructuring needs no legislature — owner consent per the current structure\'s own rules (a partnership change, for instance, requires unanimity of partners). Structure history is preserved on the public record.') }}
            </p>

            <template v-if="restructurings.length">
                <DataTable
                    :columns="[
                        { key: 'org', label: t('c_institutions.transfers_conversions.col_org', 'Organization') },
                        { key: 'change', label: t('c_institutions.transfers_conversions.col_change', 'Change') },
                        { key: 'rule', label: t('c_institutions.transfers_conversions.col_rule', 'Rule applied') },
                        { key: 'standing', label: t('c_institutions.transfers_conversions.col_consent', 'Consent') },
                        { key: 'at', label: t('c_institutions.transfers_conversions.col_when', 'When'), mono: true },
                    ]"
                    :rows="restructurings"
                    :caption="t('c_institutions.transfers_conversions.restructurings_caption', 'Internal restructurings')"
                    style="margin-block-start: var(--space-3)"
                >
                    <template #cell-org="{ row }">
                        <Link v-if="row.org" :href="row.org.href">{{ row.org.name }}</Link>
                        <span v-else class="gloss">—</span>
                    </template>
                    <template #cell-change="{ row }"><span data-no-i18n>{{ row.from_structure }} → {{ row.to_structure }}</span></template>
                    <template #cell-rule="{ row }">{{ row.rule_applied }}</template>
                    <template #cell-standing="{ row }">
                        <template v-if="row.status === 'adopted'">
                            <StatusBadge tone="success" icon="check">{{ t('c_institutions.transfers_conversions.adopted', 'adopted') }}</StatusBadge>
                        </template>
                        <template v-else>
                            {{ t('c_institutions.transfers_conversions.holders_consent', { consented: row.consented, holders: row.holders }) }}
                            <button
                                v-if="!row.i_consented && row.status === 'proposed'"
                                type="button"
                                class="btn btn--secondary btn--sm"
                                style="margin-inline-start: var(--space-2)"
                                @click="consentRestructure(row.consent_url)"
                            >
                                {{ t('c_institutions.transfers_conversions.consent_btn', 'Consent') }}
                            </button>
                            <span v-else-if="row.i_consented" class="gloss"> {{ t('c_institutions.transfers_conversions.yours_on_it', '· yours is on it') }}</span>
                        </template>
                    </template>
                </DataTable>
            </template>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.transfers_conversions.no_restructurings', 'No internal restructurings recorded here — structure history renders on each organization\'s ownership panel.') }}
            </p>

            <!-- propose a restructuring (owners only — the engine refuses anyone else) -->
            <FormCard
                v-if="urls?.restructure"
                :form="{ id: 'F-ORG-009', name: 'Internal Restructuring' }"
                :inertia-form="restructureForm"
                :submit-label="t('c_institutions.transfers_conversions.restructure_submit', 'Propose (F-ORG-009)')"
                style="margin-block-start: var(--space-3)"
                @submit="submitRestructure"
            >
                <Field :label="t('c_institutions.transfers_conversions.f_target_structure', 'Target structure')" :error="restructureForm.errors.to_structure">
                    <template #control="{ id, describedBy }">
                        <select :id="id" v-model="restructureForm.to_structure" :aria-describedby="describedBy">
                            <option value="stock">{{ t('c_institutions.transfers_conversions.opt_stock', 'Stock') }}</option>
                            <option value="partnership">{{ t('c_institutions.transfers_conversions.opt_partnership', 'Partnership') }}</option>
                            <option value="equal_partnership">{{ t('c_institutions.transfers_conversions.opt_equal_partnership', 'Equal partnership') }}</option>
                            <option value="member_owned">{{ t('c_institutions.transfers_conversions.opt_member_owned', 'Member-owned') }}</option>
                            <option value="worker_owned">{{ t('c_institutions.transfers_conversions.opt_worker_owned', 'Worker-owned') }}</option>
                            <option value="nonprofit">{{ t('c_institutions.transfers_conversions.opt_nonprofit', 'Nonprofit') }}</option>
                        </select>
                    </template>
                </Field>
                <p class="gloss">
                    {{ t('c_institutions.transfers_conversions.restructure_gloss', 'The consent threshold comes from the current structure\'s own rules — an equal partnership requires unanimity; a stock structure counts voting shares instead. Proposing records your consent. Only stake holders act; the engine refuses anyone else, with the reason.') }}
                </p>
            </FormCard>

            <!-- dissolution -->
            <div class="card card--inset" style="margin-block-start: var(--space-4)">
                <p style="margin-block-end: var(--space-2)">
                    <strong style="color: var(--gov-fg)">{{ t('c_institutions.transfers_conversions.dissolution_strong', 'Dissolution') }}</strong> {{ t('c_institutions.transfers_conversions.dissolution_body_before', '— obligations settled, records archived, the audit chain preserved (') }}<FormChip form-id="F-ORG-007" />{{ t('c_institutions.transfers_conversions.dissolution_body_after', ', voluntary path). Judicial dissolution (WF-ORG-10) arrives with the judiciary.') }}
                    <StatusBadge tone="neutral" icon="clock" style="margin-inline-start: var(--space-1)">{{ t('c_institutions.transfers_conversions.judicial_path_badge', 'judicial path · planned · Phase E') }}</StatusBadge>
                </p>

                <DataTable
                    v-if="dissolutions.length"
                    :columns="[
                        { key: 'org', label: t('c_institutions.transfers_conversions.col_org', 'Organization') },
                        { key: 'kind', label: t('c_institutions.transfers_conversions.col_kind', 'Kind') },
                        { key: 'record', label: t('c_institutions.transfers_conversions.col_archive', 'Archive') },
                        { key: 'status', label: t('c_institutions.transfers_conversions.col_status', 'Status') },
                    ]"
                    :rows="dissolutions"
                    :caption="t('c_institutions.transfers_conversions.dissolutions_caption', 'Dissolutions')"
                >
                    <template #cell-org="{ row }"><Link :href="row.org.href">{{ row.org.name }}</Link></template>
                    <template #cell-record="{ row }">
                        <Link v-if="row.archived_record_href" :href="row.archived_record_href">{{ t('c_institutions.transfers_conversions.archived_record_link', 'archived record →') }}</Link>
                        <span v-else class="gloss">—</span>
                    </template>
                    <template #cell-status="{ row }"><StatusBadge tone="neutral">{{ row.status }}</StatusBadge></template>
                </DataTable>
                <p v-else class="gloss">{{ t('c_institutions.transfers_conversions.no_dissolutions', 'No dissolutions on record.') }}</p>

                <div v-if="urls?.dissolution" style="margin-block-start: var(--space-3)">
                    <FormCard
                        :form="surface.forms.find((f) => f.id === 'F-ORG-007')"
                        :inertia-form="dissolutionForm"
                        :submit-label="t('c_institutions.transfers_conversions.dissolution_submit', 'Dissolve voluntarily')"
                        :processing-label="t('c_institutions.transfers_conversions.dissolution_processing', 'Dissolving…')"
                        @submit="submitDissolution"
                    >
                        <Field :label="t('c_institutions.transfers_conversions.f_reason', 'Reason')" :error="dissolutionForm.errors.reason">
                            <template #control="{ id, describedBy }">
                                <textarea :id="id" v-model="dissolutionForm.reason" class="field-input" rows="2" :aria-describedby="describedBy"></textarea>
                            </template>
                        </Field>
                    </FormCard>
                </div>
            </div>
        </Card>

        <template #about>
            <p>
                {{ t('c_institutions.transfers_conversions.about', 'Four paths, one principle: ownership never moves against its owner\'s will except where the constitution names a public interest (monopoly), and even then at fair-market compensation. Public↔private conversion runs through the legislature; public-domain IP stays public irreversibly (Art. III §5).') }}
            </p>
        </template>
    </PageScaffold>
</template>
