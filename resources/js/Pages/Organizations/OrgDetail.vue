<script setup>
/**
 * Organizations/OrgDetail — FE-D6 (PHASE_D_DESIGN_frontend.md §B.7; surface
 * organizations/org-detail). The organization profile.
 *
 * Composes: the profile card (F-ORG-001 edit when R-23) · the endorsements
 * handshake (F-CAN-002 request → F-ORG-002 grant → R-07) · the join cards
 * (F-IND-013 membership → R-24; F-IND-014 worker → R-25, THE headcount
 * feed) · document packages · contracts with the two-signature co-sign gate ·
 * OwnershipPanel · the current board summary and compact roster
 * when a board exists · the ESM-18 StateStrip.
 *
 * Public read; actions gate by `can.*` + engine 422 (the bootstrap
 * ConstitutionalViolation handler renders the citation). worker_seats /
 * composition_valid / nextStepAt are ENGINE SNAPSHOTS from rows — nothing
 * here recomputes the co-determination scale.
 */
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import BoardStrip from '@/Components/Organizations/BoardStrip.vue';
import OwnershipPanel from '@/Components/Organizations/OwnershipPanel.vue';
import OrganizationNav from '@/Components/Organizations/OrganizationNav.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    organization: { type: Object, required: true },
    machine: { type: Array, default: () => [] },
    ownership: { type: Object, required: true },
    board: { type: Object, default: null },
    endorsements: { type: Object, default: () => ({ incoming: [], granted: [], total: 0 }) },
    documents: { type: Array, default: () => [] },
    contracts: { type: Array, default: () => [] },
    myMembership: { type: Object, default: null },
    myWorker: { type: Object, default: null },
    /** The org's own open work postings — [{id,title,terms,rate,currency,applications}]. */
    jobs: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ manage: false, join: false, registerWorker: false, cosign: false, steerEconomy: false }) },
});

/* Plain labels for the org type + ownership structure — titleize() prints
   "common good corp" / "equal partnership" as bare snake_case; the player chrome
   speaks plainly (S8). Friendly, so lane 5 can translate (no data-no-i18n). */
const TYPE_LABELS = {
    political_party: 'Political party',
    business: 'Business',
    nonprofit: 'Nonprofit',
    common_good_corp: 'Common-good corporation',
    informal: 'Informal group',
};
const STRUCTURE_LABELS = {
    sole: 'Sole — one owner',
    equal_partnership: 'Equal partnership',
    unequal_partnership: 'Unequal partnership',
    stock: 'Stock / shareholders',
    member_owned: 'Member-owned',
    nonprofit: 'Nonprofit — no owners',
};
const typeLabel = (t) => TYPE_LABELS[t] ?? titleize(t);
const structureLabel = (s) => STRUCTURE_LABELS[s] ?? titleize(s);

/* Display-only rate tidy: strip trailing zeros off the numeric(24,6) STRING
   ("18.000000" → "18", "18.500000" → "18.5"). Pure string surgery — never
   parseFloat, per ECONOMY_PROP_CONTRACT (money is a string, format never
   compute; a float round-trip corrupts a ledger). This is a wage rate for
   display, not a balance, but the rule holds either way. */
const fmtRate = (s) => (s == null ? null : String(s).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''));

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const titleize = (s) => (s ? String(s).replaceAll('_', ' ') : '—');
function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return iso;
    }
}

const statusTone = computed(() =>
    props.organization.status === 'active' ? 'success' : props.organization.status === 'registered' ? 'info' : 'neutral',
);

/* ---------------------------------------------- profile edit (F-ORG-001) */
const profileForm = useForm({
    name: props.organization.name,
    purpose: props.organization.purpose ?? '',
    description: '',
    website_url: '',
});
function submitProfile() {
    profileForm.patch(`/organizations/${props.organization.id}`, { preserveScroll: true });
}

/* ---------------------------------------------- endorsement grant (F-ORG-002) */
const grantForm = useForm({ decision: 'grant', statement: '' });

/* F-ORG-001 'update_settings' — the org's own dials (v3.2 item 0d). */
const settingsForm = useForm({ key: 'board_nomination_window_days', value: '' });

function submitSettings() {
    settingsForm.post(`/organizations/${props.organization.id}/settings`, {
        preserveScroll: true,
    });
}
function decide(requestId, decision) {
    grantForm.transform((d) => ({ ...d, decision })).post(
        `/organizations/${props.organization.id}/endorsements/${requestId}/grant`,
        { preserveScroll: true, onSuccess: () => grantForm.reset('statement') },
    );
}

/* ---------------------------------------------- join (F-IND-013 / F-IND-014) */
const membershipForm = useForm({ kind: null });
function submitMembership() {
    membershipForm.post(`/organizations/${props.organization.id}/memberships`, { preserveScroll: true });
}

const workerForm = useForm({ contract_terms: '' });
function submitWorker() {
    workerForm.post(`/organizations/${props.organization.id}/workers`, { preserveScroll: true });
}

/* ---------------------------------------------- documents (F-ORG-001) --- */
const documentForm = useForm({ key: '', name: '', kind: 'bylaws', content: '' });
function submitDocument() {
    documentForm.post(`/organizations/${props.organization.id}/documents`, {
        preserveScroll: true,
        onSuccess: () => documentForm.reset('content'),
    });
}

/* ---------------------------------------------- contract co-sign (F-ORG-001) */
const cosignForm = useForm({});
function cosign(contractId) {
    cosignForm.post(`/contracts/${contractId}/cosign`, { preserveScroll: true });
}

function contractStatusBadge(contract) {
    if (contract.status === 'active') return { tone: 'success', text: 'Active · co-signed' };
    if (contract.status === 'voided' || contract.status === 'ended') return { tone: 'neutral', text: titleize(contract.status) };
    if (contract.signed_a && contract.signed_b) return { tone: 'success', text: 'Both signatures on record' };
    return { tone: 'warning', text: 'Pending co-signature' };
}

const documentColumns = [
    { key: 'package', label: 'Package' },
    { key: 'kind', label: 'Kind' },
    { key: 'version', label: 'Version', mono: true, align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="organization.name">
        <template #intro>
            Get to know this organization, find its work, or join its activities.
        </template>

        <OrganizationNav :organization="organization" current="overview" />

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" role="alert">{{ constitutionError }}</Banner>

        <!-- ============================================ profile ========= -->
        <Card as="section" :title="`Profile — ${organization.name}`">
            <div class="cluster" style="gap: var(--space-2)">
                <TagChip>{{ typeLabel(organization.type) }}</TagChip>
                <TagChip v-if="organization.structure">{{ structureLabel(organization.structure) }}</TagChip>
                <StatusBadge :tone="statusTone">{{ organization.status }}</StatusBadge>
                <FormChip :form-id="formMeta('F-ORG-001').id" :name="formMeta('F-ORG-001').name" :alias="formMeta('F-ORG-001').alias" />
            </div>

            <!-- Head stat strip — the profile's numbers at a glance (mockup). -->
            <div class="cluster" style="gap: var(--space-4); margin-block-start: var(--space-3)">
                <Stat :value="jobs.length" label="Open jobs" />
                <Stat :value="organization.worker_count ?? 0" label="Workers" />
                <Stat :value="endorsements.total ?? 0" label="Endorsements" />
                <Stat v-if="board?.exists" :value="board.strip?.seats?.length ?? 0" label="Board seats" />
            </div>

            <dl class="cluster" style="gap: var(--space-6); margin-block-start: var(--space-3)">
                <div>
                    <dt class="cc-small">Jurisdiction</dt>
                    <dd style="margin: 0">{{ organization.jurisdiction?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="cc-small">Registered</dt>
                    <dd style="margin: 0">{{ fmtDate(organization.registered_at) }}</dd>
                </div>
                <div>
                    <dt class="cc-small">Organization representative</dt>
                    <dd style="margin: 0">
                        {{ organization.agent?.name ?? '—' }}
                        <span v-if="organization.agent?.is_viewer" class="citation"> · you</span>
                    </dd>
                </div>
            </dl>
            <p v-if="organization.purpose" style="margin-block-start: var(--space-2)">{{ organization.purpose }}</p>

            <details v-if="can.manage" style="margin-block-start: var(--space-3)">
                <summary>Edit profile</summary>
                <form class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)" novalidate @submit.prevent="submitProfile">
                    <input type="hidden" name="form_id" value="F-ORG-001" />
                    <Field label="Name" :error="profileForm.errors.name">
                        <template #control="{ id }">
                            <input :id="id" v-model="profileForm.name" class="field-input" />
                        </template>
                    </Field>
                    <Field label="Purpose" :error="profileForm.errors.purpose">
                        <template #control="{ id }">
                            <textarea :id="id" v-model="profileForm.purpose" class="field-input" rows="2"></textarea>
                        </template>
                    </Field>
                    <Field label="Website" :error="profileForm.errors.website_url">
                        <template #control="{ id }">
                            <input :id="id" v-model="profileForm.website_url" class="field-input" type="url" />
                        </template>
                    </Field>
                    <div class="cluster">
                        <Btn type="submit" variant="primary" size="sm" :disabled="profileForm.processing">Save profile</Btn>
                    </div>
                </form>
            </details>

            <details v-if="can.manage" style="margin-block-start: var(--space-3)">
                <summary>Org settings — board elections</summary>
                <form class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)" novalidate @submit.prevent="submitSettings">
                    <Field
                        label="Open nomination window (days before ranking opens)"
                        :error="settingsForm.errors.value"
                    >
                        <template #control="{ id }">
                            <input
                                :id="id"
                                v-model="settingsForm.value"
                                class="field-input"
                                type="number"
                                min="1"
                                max="90"
                                inputmode="numeric"
                            />
                        </template>
                    </Field>
                    <p class="gloss">
                        How long a board election's nomination phase runs before ranking opens.
                        <template v-if="organization.settings?.board_nomination_window_days">
                            Currently {{ organization.settings.board_nomination_window_days }} days.
                        </template>
                        <template v-else>
                            Currently unset — the jurisdiction's default schedule applies.
                        </template>
                        An organization's own rule about itself — recorded on the audit chain,
                        never a constitutional value.
                    </p>
                    <div class="cluster">
                        <Btn type="submit" variant="primary" size="sm" :disabled="settingsForm.processing">Set the window</Btn>
                    </div>
                </form>
            </details>
        </Card>

        <!-- ======================================== endorsements ======== -->
        <Card as="section" title="Endorsements">
            <p class="gloss">Candidates can ask for this organization’s endorsement. Its representative
                reviews requests, and granted endorsements become public.</p>

            <template v-if="can.manage && endorsements.incoming.length">
                <h3 style="margin-block: var(--space-3) var(--space-1)">Pending requests</h3>
                <div v-for="req in endorsements.incoming" :key="req.id" class="card card--inset" style="margin-block-end: var(--space-2)">
                    <p style="margin: 0">
                        <Link v-if="req.candidate.href" :href="req.candidate.href"><strong>{{ req.candidate.name }}</strong></Link>
                        <strong v-else>{{ req.candidate.name }}</strong>
                        <span class="citation"> · requested {{ fmtDate(req.requested_at) }}</span>
                    </p>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn variant="primary" size="sm" :disabled="grantForm.processing" @click="decide(req.id, 'grant')">Grant</Btn>
                        <Btn variant="ghost" size="sm" :disabled="grantForm.processing" @click="decide(req.id, 'decline')">Decline</Btn>
                        <FormChip :form-id="formMeta('F-ORG-002').id" :name="formMeta('F-ORG-002').name" />
                    </div>
                </div>
            </template>

            <h3 style="margin-block: var(--space-3) var(--space-1)">Granted endorsements ({{ endorsements.total }})</h3>
            <ul v-if="endorsements.granted.length" class="stack" style="gap: var(--space-1); list-style: none; padding: 0; margin: 0">
                <li v-for="(grant, i) in endorsements.granted" :key="i">
                    <Link v-if="grant.candidate.href" :href="grant.candidate.href">{{ grant.candidate.name }}</Link>
                    <span v-else>{{ grant.candidate.name }}</span>
                    <span class="citation"> · granted {{ fmtDate(grant.granted_at) }}</span>
                </li>
            </ul>
            <p v-else class="gloss">No endorsements granted yet.</p>
        </Card>

        <!-- ============================================ job board ======= -->
        <Card as="section" title="Job board">
            <Link v-if="can.manage" :href="`/economy/work?tab=hiring&organization=${organization.id}`">Manage hiring</Link>
            <p class="gloss">
                Explore this organization’s open roles and apply for work. The organization reviews applications.
            </p>
            <ul v-if="jobs.length" class="offer-grid" style="margin-block-start: var(--space-3); list-style: none; padding: 0">
                <li v-for="job in jobs" :key="job.id" class="card card--inset">
                    <strong style="color: var(--gov-fg)">{{ job.title }}</strong>
                    <p v-if="job.terms" class="cc-small" style="margin-block: var(--space-1)">{{ job.terms }}</p>
                    <div class="cluster" style="gap: var(--space-2); justify-content: space-between; margin-block-start: var(--space-2)">
                        <span class="citation">
                            <template v-if="job.rate">{{ job.currency }}{{ fmtRate(job.rate) }}</template>
                            <template v-else>Unpaid / by agreement</template>
                            · {{ job.applications }} {{ job.applications === 1 ? 'application' : 'applications' }}
                        </span>
                        <Link :href="`/economy/requests/${job.id}`">Review &amp; apply</Link>
                    </div>
                </li>
            </ul>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                No open roles right now — postings appear here when the organization opens one.
            </p>
        </Card>

        <!-- ============================================ join cards ====== -->
        <div class="grid-2">
            <Card as="section" title="Become a member">
                <template v-if="myMembership">
                    <Banner tone="info" role="status" title="You are a member.">
                        Your membership ({{ titleize(myMembership.kind) }}) is <strong>{{ myMembership.status }}</strong>.
                    </Banner>
                </template>
                <template v-else-if="can.join">
                    <p class="cc-small" style="margin-block-end: var(--space-2)">
                        Apply to join this organization. Membership begins when your request is accepted under its bylaws.
                    </p>
                    <form novalidate @submit.prevent="submitMembership">
                        <input type="hidden" name="form_id" value="F-IND-013" />
                        <div class="cluster">
                            <Btn type="submit" variant="primary" size="sm" :disabled="membershipForm.processing">Apply for membership</Btn>
                            <FormChip :form-id="formMeta('F-IND-013').id" :name="formMeta('F-IND-013').name" />
                        </div>
                    </form>
                    <Banner v-if="membershipForm.errors.constitution" tone="warning" role="alert" style="margin-block-start: var(--space-2)">
                        {{ membershipForm.errors.constitution }}
                    </Banner>
                </template>
                <p v-else class="gloss">Confirm your residency to join (Art. I).</p>
            </Card>

            <Card as="section" title="Register as a worker">
                <p class="citation" style="margin-block-end: var(--space-2)">
                    Registered workers count toward the organization’s worker representation.
                </p>
                <template v-if="myWorker">
                    <Banner tone="info" role="status" title="You are registered as a worker.">
                        Your worker registration is <strong>{{ myWorker.status }}</strong
                        ><template v-if="myWorker.since"> since {{ fmtDate(myWorker.since) }}</template>. It activates on the
                        organization's countersign.
                    </Banner>
                </template>
                <template v-else-if="can.registerWorker">
                    <form novalidate @submit.prevent="submitWorker">
                        <input type="hidden" name="form_id" value="F-IND-014" />
                        <Field label="Contract reference" :error="workerForm.errors.contract_terms" hint="A recurring labor contract backs the registration; it counts toward headcount once the organization countersigns.">
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model="workerForm.contract_terms" class="field-input" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                        <div class="cluster">
                            <Btn type="submit" variant="primary" size="sm" :disabled="workerForm.processing">Register as worker</Btn>
                            <FormChip :form-id="formMeta('F-IND-014').id" :name="formMeta('F-IND-014').name" />
                        </div>
                    </form>
                    <Banner v-if="workerForm.errors.constitution" tone="warning" role="alert" style="margin-block-start: var(--space-2)">
                        {{ workerForm.errors.constitution }}
                    </Banner>
                </template>
                <p v-else class="gloss">Confirm your residency to register as a worker (Art. I).</p>
            </Card>
        </div>

        <!-- ============================================ ownership ======= -->
        <Card as="section" title="Ownership">
            <OwnershipPanel
                :structure="ownership.structure"
                :is-cgc="ownership.isCgc"
                :stakes="ownership.stakes"
                :member-counts="ownership.memberCounts"
                :structure-history="ownership.structureHistory"
            />
        </Card>

        <!-- ============================================ the board ======= -->
        <Card as="section" title="Board">
            <template v-if="board && board.exists">
                <div v-if="board.codet" class="cluster" style="gap: var(--space-5); margin-block-end: var(--space-3)">
                    <Stat :value="board.codet.ownerSeats" label="Owner-side seats" />
                    <Stat :value="board.codet.workerSeats" label="Worker seats required" />
                </div>
                <BoardStrip
                    :seats="board.strip.seats"
                    :composition-valid="board.strip.compositionValid"
                    :required-worker-seats="board.strip.requiredWorkerSeats"
                    compact
                />
            </template>
            <Banner v-else tone="info" role="status" title="No board constituted.">
                No board has been constituted yet.
            </Banner>
            <p style="margin-block-start: var(--space-3)">
                <Link :href="`/organizations/co-determination?org=${organization.id}`">How worker representation is determined →</Link>
            </p>
        </Card>

        <!-- ============================================ documents ======= -->
        <Card as="section" title="Document packages">
            <DataTable v-if="documents.length" :columns="documentColumns" :rows="documents" row-key="key" caption="Internal document packages">
                <template #cell-package="{ row }">
                    <strong style="color: var(--gov-fg)">{{ row.package }}</strong>
                    <span class="citation" style="display: block" data-no-i18n>{{ row.key }}</span>
                </template>
                <template #cell-kind="{ row }">{{ titleize(row.kind) }}</template>
                <template #cell-version="{ row }">v{{ row.version }}</template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="row.status === 'active' ? 'success' : 'neutral'">{{ row.status }}</StatusBadge>
                </template>
            </DataTable>
            <p v-else class="gloss">No document packages on record.</p>

            <p class="citation" style="margin-block-start: var(--space-2)">
                Internal packages never override the constitutional forms — a package key may not collide
                with a form ID.
            </p>

            <details v-if="can.manage" style="margin-block-start: var(--space-3)">
                <summary>Upload a new version</summary>
                <form class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)" novalidate @submit.prevent="submitDocument">
                    <input type="hidden" name="form_id" value="F-ORG-001" />
                    <Field label="Key" :error="documentForm.errors.key" hint="A stable identifier for the package (e.g. bylaws-2031).">
                        <template #control="{ id, describedBy }">
                            <input :id="id" v-model="documentForm.key" class="field-input" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <Field label="Name" :error="documentForm.errors.name">
                        <template #control="{ id }">
                            <input :id="id" v-model="documentForm.name" class="field-input" />
                        </template>
                    </Field>
                    <Field label="Content" :error="documentForm.errors.content">
                        <template #control="{ id }">
                            <textarea :id="id" v-model="documentForm.content" class="field-input" rows="3"></textarea>
                        </template>
                    </Field>
                    <div class="cluster">
                        <Btn type="submit" variant="primary" size="sm" :disabled="documentForm.processing">Record version</Btn>
                    </div>
                </form>
            </details>
        </Card>

        <!-- ============================================ contracts ======= -->
        <Card as="section" title="Contracts">
            <template v-if="contracts.length">
                <div v-for="contract in contracts" :key="contract.id" class="card card--inset" style="margin-block-end: var(--space-2)">
                    <p style="margin: 0">
                        <strong style="color: var(--gov-fg)">{{ contract.title }}</strong>
                        <TagChip style="margin-inline-start: var(--space-1)" data-no-i18n>{{ titleize(contract.kind) }}</TagChip>
                        <TagChip v-if="contract.feeds_headcount" style="margin-inline-start: var(--space-1)">counts toward the worker headcount</TagChip>
                    </p>
                    <p class="cc-small" style="margin-block: var(--space-1) 0">
                        Counterparty: {{ contract.counterparty }}
                    </p>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <StatusBadge :tone="contractStatusBadge(contract).tone">{{ contractStatusBadge(contract).text }}</StatusBadge>
                        <span class="citation">
                            org {{ contract.signed_a ? 'signed' : 'unsigned' }} · counterparty {{ contract.signed_b ? 'signed' : 'unsigned' }}
                        </span>
                        <Btn
                            v-if="can.cosign && !contract.signed_a && contract.status !== 'voided' && contract.status !== 'ended'"
                            variant="primary"
                            size="sm"
                            :disabled="cosignForm.processing"
                            @click="cosign(contract.id)"
                        >Co-sign</Btn>
                    </div>
                </div>
                <p class="gloss">
                    A contract takes effect only with both signatures — the engine rejects effect before both.
                </p>
            </template>
            <p v-else class="gloss">No contracts on record.</p>
        </Card>

        <!-- ============================================ ESM-18 ========== -->
        <Card as="section" title="Organization status">
            <StateStrip :states="machine" :current="organization.status" />
        </Card>

        <template #about>
            <p>
                This profile is a public record. The endorsement handshake, the membership and worker
                join paths, and the co-determination scale all run on one organization model — there is
                no faction layer.
            </p>
        </template>
    </PageScaffold>
</template>
