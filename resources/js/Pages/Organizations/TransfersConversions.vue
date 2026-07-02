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
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
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

/* The 5 monopoly-acquisition stages (mockup order). stage_index is a server
   snapshot read off the conversion status — never computed as policy here. */
const acquisitionStages = [
    'Legislative finding',
    'Acquisition vote · F-LEG-026',
    'Compensation ≥ fair market',
    'Conversion to CGC',
    'Founding governor seats offered',
];

function transferStatusTone(status) {
    return { proposed: 'info', consented: 'warning', completed: 'success', abandoned: 'neutral' }[status] ?? 'neutral';
}
function consentBadge(at) {
    return at ? { tone: 'success', icon: 'check', text: `consented ${at}` } : { tone: 'neutral', icon: 'clock', text: 'awaiting consent' };
}
</script>

<template>
    <PageScaffold :surface="surface" title="Transfers and conversions">
        <template #intro>
            An organization's ownership can change five ways, and this page shows all five: sold by
            mutual agreement, acquired by the legislature when a monopoly is found, converted between
            public and private, restructured within private hands, or wound down. Only the monopoly
            path ever overrides the owners' consent — and it carries a protected compensation floor.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner v-if="focus" tone="info" role="status" :title="`Focused on ${focus.name}.`">
            Showing the transfer/conversion register for
            <Link :href="focus.href">{{ focus.name }}</Link>. Drop the <code data-no-i18n>?org=</code> query
            to see the whole registry.
        </Banner>
        <Banner v-else tone="info" role="status" title="Whole-instance registry.">
            Open this page with <code data-no-i18n>?org={id}</code> to file a transfer, conversion request, or
            dissolution against a specific organization.
        </Banner>

        <!-- ============================ 1. mutual transfer ============== -->
        <Card as="section" title="Mutual transfer — both consents required">
            <p>
                Ownership transfers only by mutual consent (<FormChip form-id="F-ORG-005" />, WF-ORG-06): the
                current owner initiates and the transferee consents. Both consents must be on record; the
                engine rejects completion with anything less.
            </p>

            <DataTable
                v-if="transfers.length"
                :columns="[
                    { key: 'from', label: 'From' },
                    { key: 'to', label: 'To' },
                    { key: 'consents', label: 'Consents' },
                    { key: 'ffc', label: 'FF&C sync' },
                    { key: 'status', label: 'Status' },
                ]"
                :rows="transfers"
                row-key="id"
                caption="Ownership transfers"
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
                        from: {{ consentBadge(row.consent_a_at).text }}
                    </StatusBadge>
                    <StatusBadge
                        :tone="consentBadge(row.consent_b_at).tone"
                        :icon="consentBadge(row.consent_b_at).icon"
                        style="margin-inline-start: var(--space-1)"
                    >
                        to: {{ consentBadge(row.consent_b_at).text }}
                    </StatusBadge>
                    <div v-if="can.initiateTransfer && row.status === 'proposed' && !row.consent_b_at" style="margin-block-start: var(--space-1)">
                        <button type="button" class="form-chip" @click="consentTransfer(row.consent_url)">
                            Consent as transferee →
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
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">No transfers on record.</p>

            <div v-if="urls?.transfer" style="margin-block-start: var(--space-4)">
                <FormCard
                    :form="surface.forms.find((f) => f.id === 'F-ORG-005')"
                    :inertia-form="transferForm"
                    submit-label="Initiate transfer"
                    processing-label="Initiating…"
                    @submit="submitTransfer"
                >
                    <Field label="Transferee type" :error="transferForm.errors.to_party_type" required>
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="transferForm.to_party_type" class="select" required :aria-describedby="describedBy">
                                <option value="organizations">Organization</option>
                                <option value="users">Individual</option>
                            </select>
                        </template>
                    </Field>
                    <Field label="Transferee id" hint="The receiving organization or individual." :error="transferForm.errors.to_party_id" required>
                        <template #control="{ id, describedBy, invalid }">
                            <input :id="id" v-model="transferForm.to_party_id" class="field-input" type="text" required :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <Field label="Terms" :error="transferForm.errors.terms">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="transferForm.terms" class="field-input" rows="2" :aria-describedby="describedBy"></textarea>
                        </template>
                    </Field>
                </FormCard>
            </div>
        </Card>

        <!-- ====================== 2. monopoly acquisition =============== -->
        <Card as="section" title="Monopoly acquisition — the one path that overrides owner consent">
            <p>
                When the legislature finds monopolistic control, it may acquire the enterprise for the public
                (<FormChip form-id="F-LEG-026" />, WF-ORG-07). This is a legislative act at ordinary majority
                of all serving — the only path that proceeds without owner consent — and the owners are paid
                at or above fair market value. It is initiated through the bill flow.
            </p>
            <p v-if="deepLinks.monopolyAcquisition" class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="deepLinks.monopolyAcquisition">Introduce a monopoly-acquisition bill →</Link>
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
                        <span v-else class="gloss">unknown organization</span>
                    </p>
                    <LifecycleTracker :stages="acquisitionStages" :current="acquisitionStages[acq.stage_index] ?? acquisitionStages[0]" />

                    <!-- vote stage: ordinary MAJORITY of all serving (owner ruling #13) -->
                    <div v-if="acq.vote" style="margin-block-start: var(--space-3)">
                        <p class="citation">
                            Acquisition vote — ordinary majority of all serving; the only path overriding owner
                            consent · owner ruling #13.
                        </p>
                        <VoteTally v-bind="acq.vote.tally" :basis="'Art. III §5 · ordinary majority'" />
                    </div>

                    <!-- compensation stage: the hardened fair-market floor -->
                    <div v-if="acq.compensation?.fair_market_floor" class="cluster" style="margin-block-start: var(--space-3); gap: var(--space-2)">
                        <HardenedChip>shareholders paid ≥ fair market — the engine blocks underpayment</HardenedChip>
                        <span class="cc-small" data-no-i18n>
                            compensation {{ acq.compensation.amount ?? '—' }} · floor {{ acq.compensation.fair_market_floor }}
                        </span>
                    </div>

                    <!-- final stage: governor offers to the prior board -->
                    <div v-if="acq.governor_offers?.length" style="margin-block-start: var(--space-3)">
                        <p class="citation">Founding governor seats offered to the prior board</p>
                        <DataTable
                            :columns="[{ key: 'user_id', label: 'Prior board member', mono: true }, { key: 'status', label: 'Offer' }]"
                            :rows="acq.governor_offers"
                            caption="Founding-governor offers"
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
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">No monopoly acquisitions on record.</p>
        </Card>

        <!-- ==================== 3. public ↔ private conversion ========== -->
        <Card as="section" title="Public ↔ private conversion">
            <p>
                A conversion request (<FormChip form-id="F-ORG-006" />) routes to the legislature — a request,
                not an act. Both directions are legislature-only; the legislature authorizes the conversion by
                act (<FormChip form-id="F-LEG-027" />). Public-domain IP irreversibly stays public — new works
                after privatization follow private rules.
            </p>
            <p v-if="deepLinks.cgcReorgSale" class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="deepLinks.cgcReorgSale">Introduce a CGC reorganization/sale bill →</Link>
            </p>

            <DataTable
                v-if="conversions.length"
                :columns="[
                    { key: 'org', label: 'Organization' },
                    { key: 'direction', label: 'Direction' },
                    { key: 'via', label: 'Via' },
                    { key: 'act', label: 'Authorizing act' },
                    { key: 'status', label: 'Status' },
                ]"
                :rows="conversions"
                caption="Conversions"
                style="margin-block-start: var(--space-3)"
            >
                <template #cell-org="{ row }">
                    <Link v-if="row.org" :href="row.org.href">{{ row.org.name }}</Link>
                    <span v-else class="gloss">—</span>
                </template>
                <template #cell-direction="{ row }"><span data-no-i18n>{{ row.direction.replaceAll('_', ' ') }}</span></template>
                <template #cell-via="{ row }"><span data-no-i18n>{{ row.via.replaceAll('_', ' ') }}</span></template>
                <template #cell-act="{ row }">
                    <Link v-if="row.authorizing_act" :href="row.authorizing_act.href">record →</Link>
                    <span v-else class="gloss">—</span>
                </template>
                <template #cell-status="{ row }"><StatusBadge tone="info">{{ row.status }}</StatusBadge></template>
            </DataTable>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">No conversions on record.</p>

            <div v-if="urls?.conversionRequest" style="margin-block-start: var(--space-4)">
                <FormCard
                    :form="surface.forms.find((f) => f.id === 'F-ORG-006')"
                    :inertia-form="conversionForm"
                    submit-label="File conversion request"
                    processing-label="Filing…"
                    @submit="submitConversion"
                >
                    <Field label="Direction" :error="conversionForm.errors.direction" required>
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="conversionForm.direction" class="select" required :aria-describedby="describedBy">
                                <option value="private_to_cgc">Private → Common Good Corporation</option>
                                <option value="cgc_to_private">Common Good Corporation → private (sale)</option>
                            </select>
                        </template>
                    </Field>
                    <Field label="Rationale" :error="conversionForm.errors.rationale">
                        <template #control="{ id, describedBy }">
                            <textarea :id="id" v-model="conversionForm.rationale" class="field-input" rows="3" :aria-describedby="describedBy"></textarea>
                        </template>
                    </Field>
                </FormCard>
            </div>
        </Card>

        <!-- ============ 4. internal restructuring + dissolution ========= -->
        <Card as="section" title="Internal restructuring and dissolution">
            <p>
                Internal restructuring needs no legislature — owner consent per the current structure's own
                rules (a partnership change, for instance, requires unanimity of partners). Structure history
                is preserved on the public record.
            </p>

            <template v-if="restructurings.length">
                <DataTable
                    :columns="[
                        { key: 'org', label: 'Organization' },
                        { key: 'change', label: 'Change' },
                        { key: 'rule', label: 'Rule applied' },
                        { key: 'at', label: 'When', mono: true },
                    ]"
                    :rows="restructurings"
                    caption="Internal restructurings"
                    style="margin-block-start: var(--space-3)"
                >
                    <template #cell-org="{ row }">
                        <Link v-if="row.org" :href="row.org.href">{{ row.org.name }}</Link>
                        <span v-else class="gloss">—</span>
                    </template>
                    <template #cell-change="{ row }"><span data-no-i18n>{{ row.from_structure }} → {{ row.to_structure }}</span></template>
                    <template #cell-rule="{ row }">{{ row.rule_applied }}</template>
                </DataTable>
            </template>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                No internal restructurings recorded here — structure history renders on each organization's
                ownership panel.
            </p>

            <!-- dissolution -->
            <div class="card card--inset" style="margin-block-start: var(--space-4)">
                <p style="margin-block-end: var(--space-2)">
                    <strong style="color: var(--gov-fg)">Dissolution</strong> — obligations settled, records
                    archived, the audit chain preserved (<FormChip form-id="F-ORG-007" />, voluntary path).
                    Judicial dissolution (WF-ORG-10) arrives with the judiciary.
                    <StatusBadge tone="neutral" icon="clock" style="margin-inline-start: var(--space-1)">judicial path · planned · Phase E</StatusBadge>
                </p>

                <DataTable
                    v-if="dissolutions.length"
                    :columns="[
                        { key: 'org', label: 'Organization' },
                        { key: 'kind', label: 'Kind' },
                        { key: 'record', label: 'Archive' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="dissolutions"
                    caption="Dissolutions"
                >
                    <template #cell-org="{ row }"><Link :href="row.org.href">{{ row.org.name }}</Link></template>
                    <template #cell-record="{ row }">
                        <Link v-if="row.archived_record_href" :href="row.archived_record_href">archived record →</Link>
                        <span v-else class="gloss">—</span>
                    </template>
                    <template #cell-status="{ row }"><StatusBadge tone="neutral">{{ row.status }}</StatusBadge></template>
                </DataTable>
                <p v-else class="gloss">No dissolutions on record.</p>

                <div v-if="urls?.dissolution" style="margin-block-start: var(--space-3)">
                    <FormCard
                        :form="surface.forms.find((f) => f.id === 'F-ORG-007')"
                        :inertia-form="dissolutionForm"
                        submit-label="Dissolve voluntarily"
                        processing-label="Dissolving…"
                        @submit="submitDissolution"
                    >
                        <Field label="Reason" :error="dissolutionForm.errors.reason">
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
                Four paths, one principle: ownership never moves against its owner's will except where the
                constitution names a public interest (monopoly), and even then at fair-market compensation.
                Public↔private conversion runs through the legislature; public-domain IP stays public
                irreversibly (Art. III §5).
            </p>
        </template>
    </PageScaffold>
</template>
