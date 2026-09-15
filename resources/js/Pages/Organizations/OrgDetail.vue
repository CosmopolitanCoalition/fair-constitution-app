<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
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
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

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
    /** IO-4 — agent-only. Bounded pending-application queue {rows, pages}; null for non-agents. */
    pendingMembers: { type: Object, default: null },
    /** IO-4 — agent-only. Agent-transfer person search {query, by, searched, candidates, previous, next}; null for non-agents. */
    agentSearch: { type: Object, default: null },
    /** IO-5 — agent-only. Bounded active staff-delegation list {rows, pages}; null for non-agents. */
    delegations: { type: Object, default: null },
    can: { type: Object, default: () => ({ manage: false, join: false, registerWorker: false, cosign: false, steerEconomy: false }) },
});

/* Plain labels for the org type + ownership structure — titleize() prints
   "common good corp" / "equal partnership" as bare snake_case; the player chrome
   speaks plainly (S8). Friendly, so lane 5 can translate (no data-no-i18n). */
const TYPE_LABELS = {
    political_party: t('c_institutions.org_detail.type_political_party', 'Political party'),
    business: t('c_institutions.org_detail.type_business', 'Business'),
    nonprofit: t('c_institutions.org_detail.type_nonprofit', 'Nonprofit'),
    common_good_corp: t('c_institutions.org_detail.type_common_good_corp', 'Common-good corporation'),
    informal: t('c_institutions.org_detail.type_informal', 'Informal group'),
};
const STRUCTURE_LABELS = {
    sole: t('c_institutions.org_detail.struct_sole', 'Sole — one owner'),
    equal_partnership: t('c_institutions.org_detail.struct_equal_partnership', 'Equal partnership'),
    unequal_partnership: t('c_institutions.org_detail.struct_unequal_partnership', 'Unequal partnership'),
    stock: t('c_institutions.org_detail.struct_stock', 'Stock / shareholders'),
    member_owned: t('c_institutions.org_detail.struct_member_owned', 'Member-owned'),
    nonprofit: t('c_institutions.org_detail.struct_nonprofit', 'Nonprofit — no owners'),
};
const typeLabel = (v) => TYPE_LABELS[v] ?? titleize(v);
const structureLabel = (v) => STRUCTURE_LABELS[v] ?? titleize(v);

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
        return localeFmt.date(new Date(iso));
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

/* F-ORG-002 withdraw / re-endorse (operator ruling 2026-09-13 — an
 * organization may withdraw and re-endorse at any time while the candidacy
 * stands). Per-row busy tracking, with error/success feedback. */
const endorseBusyId = ref(null);
const endorseNotice = ref('');
const endorseError = ref('');

function withdrawEndorsement(requestId) {
    if (endorseBusyId.value) return;
    router.post(`/organizations/${props.organization.id}/endorsements/${requestId}/withdraw`, {}, {
        preserveScroll: true,
        onStart: () => { endorseBusyId.value = requestId; endorseError.value = ''; endorseNotice.value = ''; },
        onFinish: () => { endorseBusyId.value = null; },
        onError: (errs) => { endorseError.value = Object.values(errs)[0] || t('c_institutions.org_detail.err_withdraw', 'The withdrawal could not be filed. Please retry.'); },
        onSuccess: () => { endorseNotice.value = t('c_institutions.org_detail.note_withdrawn', 'Endorsement withdrawn.'); },
    });
}

function reEndorse(requestId) {
    if (endorseBusyId.value) return;
    router.post(`/organizations/${props.organization.id}/endorsements/${requestId}/re-endorse`, {}, {
        preserveScroll: true,
        onStart: () => { endorseBusyId.value = requestId; endorseError.value = ''; endorseNotice.value = ''; },
        onFinish: () => { endorseBusyId.value = null; },
        onError: (errs) => { endorseError.value = Object.values(errs)[0] || t('c_institutions.org_detail.err_reendorse', 'The re-endorsement could not be filed. Please retry.'); },
        onSuccess: () => { endorseNotice.value = t('c_institutions.org_detail.note_reendorsed', 'Endorsement re-made.'); },
    });
}

/* ============================================ IO-4 — membership review +
 * agent reassignment (F-ORG-001, agent only). Accept/decline a pending
 * application; transfer agency to any registered user. The transfer is
 * immediate and unilateral (operator ruling 2026-09-13). Per-row busy state
 * with role=status / role=alert feedback; the engine 422 renders into the
 * constitutionError banner. */
const decideBusyId = ref(null);
const decideNotice = ref('');
const decideError = ref('');

function decideMember(membershipId, decision) {
    if (decideBusyId.value) return;
    router.post(`/organizations/${props.organization.id}/memberships/${membershipId}/decision`, { decision }, {
        preserveScroll: true,
        only: ['pendingMembers', 'ownership', 'organization'],
        onStart: () => { decideBusyId.value = membershipId; decideError.value = ''; decideNotice.value = ''; },
        onFinish: () => { decideBusyId.value = null; },
        onError: (errs) => { decideError.value = Object.values(errs)[0] || t('c_institutions.org_detail.err_decision', 'The decision could not be filed. Please retry.'); },
        onSuccess: () => { decideNotice.value = decision === 'accept' ? t('c_institutions.org_detail.note_accepted', 'Application accepted.') : t('c_institutions.org_detail.note_declined', 'Application declined.'); },
    });
}

/* Agent transfer — pick a person by public name or profile reference, then
 * reassign. Search partial-reloads only its own prop. */
const agentQuery = ref(props.agentSearch?.query || '');
const agentBy = ref(props.agentSearch?.by || 'name');
const agentPick = ref(null);
const agentSearching = ref(false);
const agentSearchError = ref('');
const reassignBusy = ref(false);
const reassignError = ref('');
const reassignNotice = ref('');

function searchAgent() {
    if (agentSearching.value) return;
    const url = new URL(page.url || `/organizations/${props.organization.id}`, 'http://fixture.invalid');
    url.searchParams.set('agent_q', agentQuery.value.trim());
    url.searchParams.set('agent_by', agentBy.value);
    url.searchParams.delete('agent_cursor');
    router.get(url.pathname + url.search, {}, {
        only: ['agentSearch'], preserveState: true, preserveScroll: true,
        onStart: () => { agentSearching.value = true; agentSearchError.value = ''; },
        onFinish: () => { agentSearching.value = false; },
        onError: (errs) => { agentSearchError.value = Object.values(errs)[0] || t('c_institutions.org_detail.err_search', 'Search could not be loaded. Please retry.'); },
    });
}

function reassignAgent() {
    if (reassignBusy.value || !agentPick.value) return;
    router.post(`/organizations/${props.organization.id}/agent`, { agent_user_id: agentPick.value.id }, {
        preserveScroll: true,
        onStart: () => { reassignBusy.value = true; reassignError.value = ''; reassignNotice.value = ''; },
        onFinish: () => { reassignBusy.value = false; },
        onError: (errs) => { reassignError.value = Object.values(errs)[0] || t('c_institutions.org_detail.err_transfer', 'The transfer could not be filed. Please retry.'); },
        onSuccess: () => { reassignNotice.value = t('c_institutions.org_detail.note_transferred', 'Agency transferred.'); agentPick.value = null; },
    });
}

/* ============================================ IO-5 — scoped staff delegation
 * (F-ORG-011, agent only; operator ruling 2026-09-13). The agent grants one
 * person one coarse task bucket (profile · membership · contracts · documents ·
 * hiring · shares) and can revoke it at any time. A delegate acts only within
 * that bucket and holds no constitutional office. The grantee search reuses the
 * IO-4 person search (agentSearch). Agency and delegation are never delegable. */
const DELEGATION_BUCKETS = [
    { k: 'profile', t: t('c_institutions.org_detail.bucket_profile', 'Profile and settings') },
    { k: 'membership', t: t('c_institutions.org_detail.bucket_membership', 'Membership review') },
    { k: 'contracts', t: t('c_institutions.org_detail.bucket_contracts', 'Contracts') },
    { k: 'documents', t: t('c_institutions.org_detail.bucket_documents', 'Document packages') },
    { k: 'hiring', t: t('c_institutions.org_detail.bucket_hiring', 'Hiring') },
    { k: 'shares', t: t('c_institutions.org_detail.bucket_shares', 'Share issuance') },
];
const bucketLabel = (k) => DELEGATION_BUCKETS.find((b) => b.k === k)?.t ?? titleize(k);
const delegateBucket = ref('membership');
const delegatePick = ref(null);
const grantBusy = ref(false);
const grantError = ref('');
const grantNotice = ref('');
const revokeBusyId = ref(null);
const revokeError = ref('');
const revokeNotice = ref('');

function grantTask() {
    if (grantBusy.value || !delegatePick.value) return;
    router.post(`/organizations/${props.organization.id}/delegations`, {
        grantee_user_id: delegatePick.value.id, bucket: delegateBucket.value,
    }, {
        preserveScroll: true,
        onStart: () => { grantBusy.value = true; grantError.value = ''; grantNotice.value = ''; },
        onFinish: () => { grantBusy.value = false; },
        onError: (errs) => { grantError.value = Object.values(errs)[0] || t('c_institutions.org_detail.err_delegation', 'The delegation could not be filed. Please retry.'); },
        onSuccess: () => { grantNotice.value = t('c_institutions.org_detail.note_delegated', 'Task delegated.'); delegatePick.value = null; },
    });
}

function revokeGrant(grantId) {
    if (revokeBusyId.value) return;
    router.delete(`/organizations/${props.organization.id}/delegations/${grantId}`, {
        preserveScroll: true,
        only: ['delegations'],
        onStart: () => { revokeBusyId.value = grantId; revokeError.value = ''; revokeNotice.value = ''; },
        onFinish: () => { revokeBusyId.value = null; },
        onError: (errs) => { revokeError.value = Object.values(errs)[0] || t('c_institutions.org_detail.err_revoke', 'The revocation could not be filed. Please retry.'); },
        onSuccess: () => { revokeNotice.value = t('c_institutions.org_detail.note_revoked', 'Delegation revoked.'); },
    });
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
    if (contract.status === 'active') return { tone: 'success', text: t('c_institutions.org_detail.contract_active', 'Active · co-signed') };
    if (contract.status === 'voided' || contract.status === 'ended') return { tone: 'neutral', text: titleize(contract.status) };
    if (contract.signed_a && contract.signed_b) return { tone: 'success', text: t('c_institutions.org_detail.contract_both', 'Both signatures on record') };
    return { tone: 'warning', text: t('c_institutions.org_detail.contract_pending', 'Pending co-signature') };
}

const documentColumns = [
    { key: 'package', label: t('c_institutions.org_detail.col_package', 'Package') },
    { key: 'kind', label: t('c_institutions.org_detail.col_kind', 'Kind') },
    { key: 'version', label: t('c_institutions.org_detail.col_version', 'Version'), mono: true, align: 'right' },
    { key: 'status', label: t('c_institutions.org_detail.col_status', 'Status') },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="organization.name">
        <template #intro>
            {{ t('c_institutions.org_detail.intro', 'Get to know this organization, find its work, or join its activities.') }}
        </template>

        <OrganizationNav :organization="organization" current="overview" />

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" role="alert">{{ constitutionError }}</Banner>

        <!-- ============================================ profile ========= -->
        <Card as="section" :title="t('c_institutions.org_detail.profile_title', { name: organization.name })">
            <div class="cluster" style="gap: var(--space-2)">
                <TagChip>{{ typeLabel(organization.type) }}</TagChip>
                <TagChip v-if="organization.structure">{{ structureLabel(organization.structure) }}</TagChip>
                <StatusBadge :tone="statusTone">{{ organization.status }}</StatusBadge>
                <FormChip :form-id="formMeta('F-ORG-001').id" :name="formMeta('F-ORG-001').name" :alias="formMeta('F-ORG-001').alias" />
            </div>

            <!-- Head stat strip — the profile's numbers at a glance (mockup). -->
            <div class="cluster" style="gap: var(--space-4); margin-block-start: var(--space-3)">
                <Stat :value="jobs.length" :label="t('c_institutions.org_detail.stat_open_jobs', 'Open jobs')" />
                <Stat :value="organization.worker_count ?? 0" :label="t('c_institutions.org_detail.stat_workers', 'Workers')" />
                <Stat :value="endorsements.total ?? 0" :label="t('c_institutions.org_detail.stat_endorsements', 'Endorsements')" />
                <Stat v-if="board?.exists" :value="board.strip?.seats?.length ?? 0" :label="t('c_institutions.org_detail.stat_board_seats', 'Board seats')" />
            </div>

            <dl class="cluster" style="gap: var(--space-6); margin-block-start: var(--space-3)">
                <div>
                    <dt class="cc-small">{{ t('c_institutions.org_detail.dt_jurisdiction', 'Jurisdiction') }}</dt>
                    <dd style="margin: 0">{{ organization.jurisdiction?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="cc-small">{{ t('c_institutions.org_detail.dt_registered', 'Registered') }}</dt>
                    <dd style="margin: 0">{{ fmtDate(organization.registered_at) }}</dd>
                </div>
                <div>
                    <dt class="cc-small">{{ t('c_institutions.org_detail.dt_representative', 'Organization representative') }}</dt>
                    <dd style="margin: 0">
                        {{ organization.agent?.name ?? '—' }}
                        <span v-if="organization.agent?.is_viewer" class="citation"> {{ t('c_institutions.org_detail.citation_you', '· you') }}</span>
                    </dd>
                </div>
            </dl>
            <p v-if="organization.purpose" style="margin-block-start: var(--space-2)">{{ organization.purpose }}</p>

            <details v-if="can.manage || can.profile" style="margin-block-start: var(--space-3)">
                <summary>{{ t('c_institutions.org_detail.summary_edit_profile', 'Edit profile') }}</summary>
                <form class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)" novalidate @submit.prevent="submitProfile">
                    <input type="hidden" name="form_id" value="F-ORG-001" />
                    <Field :label="t('c_institutions.org_detail.f_name', 'Name')" :error="profileForm.errors.name">
                        <template #control="{ id }">
                            <input :id="id" v-model="profileForm.name" class="field-input" />
                        </template>
                    </Field>
                    <Field :label="t('c_institutions.org_detail.f_purpose', 'Purpose')" :error="profileForm.errors.purpose">
                        <template #control="{ id }">
                            <textarea :id="id" v-model="profileForm.purpose" class="field-input" rows="2"></textarea>
                        </template>
                    </Field>
                    <Field :label="t('c_institutions.org_detail.f_website', 'Website')" :error="profileForm.errors.website_url">
                        <template #control="{ id }">
                            <input :id="id" v-model="profileForm.website_url" class="field-input" type="url" />
                        </template>
                    </Field>
                    <div class="cluster">
                        <Btn type="submit" variant="primary" size="sm" :disabled="profileForm.processing">{{ t('c_institutions.org_detail.save_profile', 'Save profile') }}</Btn>
                    </div>
                </form>
            </details>

            <details v-if="can.manage || can.profile" style="margin-block-start: var(--space-3)">
                <summary>{{ t('c_institutions.org_detail.summary_org_settings', 'Org settings — board elections') }}</summary>
                <form class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)" novalidate @submit.prevent="submitSettings">
                    <Field
                        :label="t('c_institutions.org_detail.f_nomination_window', 'Open nomination window (days before ranking opens)')"
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
                        {{ t('c_institutions.org_detail.nomination_gloss_1', "How long a board election's nomination phase runs before ranking opens.") }}
                        <template v-if="organization.settings?.board_nomination_window_days">
                            {{ t('c_institutions.org_detail.currently_days', { days: organization.settings.board_nomination_window_days }) }}
                        </template>
                        <template v-else>
                            {{ t('c_institutions.org_detail.currently_unset', "Currently unset — the jurisdiction's default schedule applies.") }}
                        </template>
                        {{ t('c_institutions.org_detail.nomination_gloss_2', "An organization's own rule about itself — recorded on the audit chain, never a constitutional value.") }}
                    </p>
                    <div class="cluster">
                        <Btn type="submit" variant="primary" size="sm" :disabled="settingsForm.processing">{{ t('c_institutions.org_detail.set_window', 'Set the window') }}</Btn>
                    </div>
                </form>
            </details>
        </Card>

        <!-- ==================================== IO-4 applications ======= -->
        <Card v-if="can.manage || can.membership" as="section" :title="t('c_institutions.org_detail.membership_apps_title', 'Membership applications')">
            <p class="gloss">
                {{ t('c_institutions.org_detail.membership_apps_gloss', 'Review people who applied to join. Accepting grants membership; declining is final for that application, and the person may apply again.') }}
            </p>
            <template v-if="pendingMembers && pendingMembers.rows.length">
                <div
                    v-for="row in pendingMembers.rows"
                    :key="row.id"
                    class="card card--inset"
                    style="margin-block-end: var(--space-2)"
                >
                    <SelectionIdentity :person="row.user" />
                    <p class="citation" style="margin-block: var(--space-1) 0">
                        {{ titleize(row.kind) }} {{ t('c_institutions.org_detail.applied_at', { at: fmtDate(row.applied_at) }) }}
                    </p>
                    <div class="cluster io4-controls" style="margin-block-start: var(--space-2)">
                        <Btn
                            variant="primary"
                            size="sm"
                            :disabled="decideBusyId === row.id"
                            @click="decideMember(row.id, 'accept')"
                        >{{ decideBusyId === row.id ? t('c_institutions.org_detail.working', 'Working…') : t('c_institutions.org_detail.accept', 'Accept') }}</Btn>
                        <Btn
                            variant="ghost"
                            size="sm"
                            :disabled="decideBusyId === row.id"
                            @click="decideMember(row.id, 'decline')"
                        >{{ t('c_institutions.org_detail.decline', 'Decline') }}</Btn>
                        <FormChip :form-id="formMeta('F-ORG-001').id" :name="formMeta('F-ORG-001').name" :alias="formMeta('F-ORG-001').alias" />
                    </div>
                </div>
                <HistoryPager
                    :pages="pendingMembers.pages"
                    :first="`/organizations/${organization.id}`"
                    :only="['pendingMembers']"
                    cursor-key="members_cursor"
                    :label="t('c_institutions.org_detail.pager_membership', 'Membership application pages')"
                />
            </template>
            <p v-else class="gloss">{{ t('c_institutions.org_detail.no_applications', 'No applications are waiting.') }}</p>
            <p v-if="decideNotice" role="status">{{ decideNotice }}</p>
            <p v-if="decideError" role="alert">{{ decideError }}</p>
        </Card>

        <!-- ==================================== IO-4 agent transfer ===== -->
        <Card v-if="can.manage" as="section" :title="t('c_institutions.org_detail.agent_title', 'Representative (agent)')">
            <p style="margin: 0">
                {{ t('c_institutions.org_detail.current_representative', 'Current representative:') }}
                <strong>{{ organization.agent?.name ?? '—' }}</strong>
                <span v-if="organization.agent?.is_viewer" class="citation"> {{ t('c_institutions.org_detail.citation_you', '· you') }}</span>
            </p>
            <p class="gloss">
                {{ t('c_institutions.org_detail.agent_gloss', 'Transfer agency to any registered person. The transfer is immediate and unilateral — the person becomes the representative at once, and you lose management of this organization.') }}
            </p>

            <form class="stack io4-controls" style="gap: var(--space-2)" :aria-busy="agentSearching" novalidate @submit.prevent="searchAgent">
                <Field :label="t('c_institutions.org_detail.f_find_by', 'Find by')">
                    <template #control="{ id }">
                        <select :id="id" v-model="agentBy" class="field-input">
                            <option value="name">{{ t('c_institutions.org_detail.opt_name', 'Public name or {\'@\'}handle') }}</option>
                            <option value="reference">{{ t('c_institutions.org_detail.opt_reference', 'Profile reference') }}</option>
                        </select>
                    </template>
                </Field>
                <Field :label="agentBy === 'reference' ? t('c_institutions.org_detail.f_complete_reference', 'Complete profile reference') : t('c_institutions.org_detail.f_start_name', 'Start of public name or {\'@\'}handle')">
                    <template #control="{ id }">
                        <input :id="id" v-model="agentQuery" class="field-input" maxlength="120" />
                    </template>
                </Field>
                <div class="cluster">
                    <Btn type="submit" variant="secondary" size="sm" :disabled="agentSearching">{{ t('c_institutions.org_detail.search_people', 'Search people') }}</Btn>
                </div>
                <p v-if="agentSearching" role="status">{{ t('c_institutions.org_detail.searching_people', 'Searching people…') }}</p>
                <p v-if="agentSearchError" role="alert">{{ agentSearchError }}</p>
            </form>

            <p v-if="agentSearch && agentSearch.searched && !agentSearch.candidates.length" role="status">
                {{ t('c_institutions.org_detail.no_matching_people', 'No matching people.') }}
            </p>
            <div
                v-for="person in (agentSearch ? agentSearch.candidates : [])"
                :key="person.id"
                class="card card--inset io4-controls"
                style="margin-block-end: var(--space-2)"
            >
                <SelectionIdentity :person="person" />
                <Btn
                    variant="ghost"
                    size="sm"
                    :pressed="agentPick && agentPick.id === person.id"
                    @click="agentPick = person"
                >{{ agentPick && agentPick.id === person.id ? t('c_institutions.org_detail.selected', 'Selected') : t('c_institutions.org_detail.select', 'Select') }}</Btn>
            </div>
            <HistoryPager
                v-if="agentSearch"
                :pages="{ previous: agentSearch.previous, next: agentSearch.next }"
                :first="`/organizations/${organization.id}`"
                :only="['agentSearch']"
                cursor-key="agent_cursor"
                :label="t('c_institutions.org_detail.pager_agent', 'Agent search pages')"
            />

            <div v-if="agentPick" class="stack io4-controls" style="gap: var(--space-2); margin-block-start: var(--space-3)">
                <p style="margin: 0">
                    {{ t('c_institutions.org_detail.transfer_to_before', 'Transfer agency to') }} <strong>{{ agentPick.name }}</strong>{{ t('c_institutions.org_detail.transfer_to_after', '. This takes effect immediately and cannot be undone by you afterward.') }}
                </p>
                <div class="cluster">
                    <Btn variant="primary" size="sm" :disabled="reassignBusy" @click="reassignAgent">
                        {{ reassignBusy ? t('c_institutions.org_detail.transferring', 'Transferring…') : t('c_institutions.org_detail.transfer_agency', 'Transfer agency') }}
                    </Btn>
                    <FormChip :form-id="formMeta('F-ORG-001').id" :name="formMeta('F-ORG-001').name" :alias="formMeta('F-ORG-001').alias" />
                </div>
            </div>
            <p v-if="reassignNotice" role="status">{{ reassignNotice }}</p>
            <p v-if="reassignError" role="alert">{{ reassignError }}</p>
        </Card>

        <!-- ==================================== IO-5 staff delegation ===== -->
        <Card v-if="can.manage" as="section" :title="t('c_institutions.org_detail.delegation_title', 'Staff delegation')">
            <p class="gloss">
                {{ t('c_institutions.org_detail.delegation_gloss', 'Let a person handle one kind of task for this organization. A delegate acts only within the task you grant and holds no constitutional office. Agency itself, delegation itself, and dissolution are never delegable. Revoke any grant at any time.') }}
            </p>

            <div class="stack io5-controls" style="gap: var(--space-2)">
                <p style="margin: 0">
                    {{ t('c_institutions.org_detail.delegation_search_hint', 'Search a person in the "Representative (agent)" section above, then choose them here.') }}
                </p>
                <Field :label="t('c_institutions.org_detail.f_task', 'Task to delegate')">
                    <template #control="{ id }">
                        <select :id="id" v-model="delegateBucket" class="field-input">
                            <option v-for="b in DELEGATION_BUCKETS" :key="b.k" :value="b.k">{{ b.t }}</option>
                        </select>
                    </template>
                </Field>
                <div
                    v-for="person in (agentSearch ? agentSearch.candidates : [])"
                    :key="'grant-' + person.id"
                    class="card card--inset io5-controls"
                    style="margin-block-end: var(--space-2)"
                >
                    <SelectionIdentity :person="person" />
                    <Btn
                        variant="ghost"
                        size="sm"
                        :pressed="delegatePick && delegatePick.id === person.id"
                        @click="delegatePick = person"
                    >{{ delegatePick && delegatePick.id === person.id ? t('c_institutions.org_detail.selected', 'Selected') : t('c_institutions.org_detail.select', 'Select') }}</Btn>
                </div>
                <div v-if="delegatePick" class="cluster io5-controls" style="gap: var(--space-2)">
                    <p style="margin: 0">
                        {{ t('c_institutions.org_detail.grant_before', 'Grant') }} <strong>{{ bucketLabel(delegateBucket) }}</strong> {{ t('c_institutions.org_detail.grant_to', 'to') }}
                        <strong>{{ delegatePick.name }}</strong>{{ t('c_institutions.org_detail.grant_period', '.') }}
                    </p>
                    <Btn variant="primary" size="sm" :disabled="grantBusy" @click="grantTask">
                        {{ grantBusy ? t('c_institutions.org_detail.granting', 'Granting…') : t('c_institutions.org_detail.grant_task', 'Grant task') }}
                    </Btn>
                    <FormChip v-if="formMeta('F-ORG-011')" :form-id="formMeta('F-ORG-011').id" :name="formMeta('F-ORG-011').name" :alias="formMeta('F-ORG-011').alias" />
                </div>
                <p v-if="grantNotice" role="status">{{ grantNotice }}</p>
                <p v-if="grantError" role="alert">{{ grantError }}</p>
            </div>

            <h3 style="margin-block-start: var(--space-3)">{{ t('c_institutions.org_detail.active_delegations', 'Active delegations') }}</h3>
            <template v-if="delegations && delegations.rows.length">
                <div
                    v-for="row in delegations.rows"
                    :key="row.id"
                    class="card card--inset io5-controls"
                    style="margin-block-end: var(--space-2)"
                >
                    <SelectionIdentity :person="row.grantee" />
                    <p class="citation" style="margin-block: var(--space-1) 0">
                        {{ bucketLabel(row.bucket) }} {{ t('c_institutions.org_detail.granted_at', { at: fmtDate(row.granted_at) }) }}
                    </p>
                    <Btn
                        variant="ghost"
                        size="sm"
                        :disabled="revokeBusyId === row.id"
                        @click="revokeGrant(row.id)"
                    >{{ revokeBusyId === row.id ? t('c_institutions.org_detail.revoking', 'Revoking…') : t('c_institutions.org_detail.revoke', 'Revoke') }}</Btn>
                </div>
                <HistoryPager
                    :pages="delegations.pages"
                    :first="`/organizations/${organization.id}`"
                    :only="['delegations']"
                    cursor-key="grants_cursor"
                    :label="t('c_institutions.org_detail.pager_delegation', 'Delegation pages')"
                />
            </template>
            <p v-else class="gloss">{{ t('c_institutions.org_detail.no_delegations', 'No active delegations.') }}</p>
            <p v-if="revokeNotice" role="status">{{ revokeNotice }}</p>
            <p v-if="revokeError" role="alert">{{ revokeError }}</p>
        </Card>

        <!-- ======================================== endorsements ======== -->
        <Card as="section" :title="t('c_institutions.org_detail.endorsements_title', 'Endorsements')">
            <p class="gloss">{{ t('c_institutions.org_detail.endorsements_gloss', 'Candidates can ask for this organization’s endorsement. Its representative reviews requests, and granted endorsements become public.') }}</p>

            <template v-if="can.manage && endorsements.incoming.length">
                <h3 style="margin-block: var(--space-3) var(--space-1)">{{ t('c_institutions.org_detail.pending_requests', 'Pending requests') }}</h3>
                <div v-for="req in endorsements.incoming" :key="req.id" class="card card--inset" style="margin-block-end: var(--space-2)">
                    <p style="margin: 0">
                        <Link v-if="req.candidate.href" :href="req.candidate.href"><strong>{{ req.candidate.name }}</strong></Link>
                        <strong v-else>{{ req.candidate.name }}</strong>
                        <span class="citation"> {{ t('c_institutions.org_detail.requested_at', { at: fmtDate(req.requested_at) }) }}</span>
                    </p>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <Btn variant="primary" size="sm" :disabled="grantForm.processing" @click="decide(req.id, 'grant')">{{ t('c_institutions.org_detail.grant_btn', 'Grant') }}</Btn>
                        <Btn variant="ghost" size="sm" :disabled="grantForm.processing" @click="decide(req.id, 'decline')">{{ t('c_institutions.org_detail.decline', 'Decline') }}</Btn>
                        <FormChip :form-id="formMeta('F-ORG-002').id" :name="formMeta('F-ORG-002').name" />
                    </div>
                </div>
            </template>

            <h3 style="margin-block: var(--space-3) var(--space-1)">{{ t('c_institutions.org_detail.granted_heading', { total: endorsements.total }) }}</h3>
            <ul v-if="endorsements.granted.length" class="stack" style="gap: var(--space-2); list-style: none; padding: 0; margin: 0">
                <li v-for="grant in endorsements.granted" :key="grant.id ?? grant.candidate.href">
                    <Link v-if="grant.candidate.href" :href="grant.candidate.href">{{ grant.candidate.name }}</Link>
                    <span v-else>{{ grant.candidate.name }}</span>
                    <span class="citation"> {{ t('c_institutions.org_detail.granted_at', { at: fmtDate(grant.granted_at) }) }}</span>
                    <span v-if="grant.active === false" class="citation"> {{ t('c_institutions.org_detail.citation_withdrawn', '· withdrawn') }}</span>
                    <!-- Withdraw / re-endorse at any time while the candidacy stands (F-ORG-002). -->
                    <span v-if="can.manage && grant.id" class="cluster" style="margin-inline-start: var(--space-2)">
                        <Btn
                            v-if="grant.active !== false"
                            variant="secondary"
                            size="sm"
                            :disabled="endorseBusyId === grant.id"
                            @click="withdrawEndorsement(grant.id)"
                        >{{ endorseBusyId === grant.id ? t('c_institutions.org_detail.withdrawing', 'Withdrawing…') : t('c_institutions.org_detail.withdraw', 'Withdraw') }}</Btn>
                        <Btn
                            v-else
                            variant="primary"
                            size="sm"
                            :disabled="endorseBusyId === grant.id"
                            @click="reEndorse(grant.id)"
                        >{{ endorseBusyId === grant.id ? t('c_institutions.org_detail.reendorsing', 'Re-endorsing…') : t('c_institutions.org_detail.reendorse', 'Re-endorse') }}</Btn>
                    </span>
                </li>
            </ul>
            <p v-else class="gloss">{{ t('c_institutions.org_detail.no_endorsements', 'No endorsements granted yet.') }}</p>
            <p v-if="endorseNotice" role="status">{{ endorseNotice }}</p>
            <p v-if="endorseError" role="alert">{{ endorseError }}</p>
        </Card>

        <!-- ============================================ job board ======= -->
        <Card as="section" :title="t('c_institutions.org_detail.job_board_title', 'Job board')">
            <Link v-if="can.manage || can.hiring" :href="`/economy/work?tab=hiring&organization=${organization.id}`">{{ t('c_institutions.org_detail.manage_hiring', 'Manage hiring') }}</Link>
            <p class="gloss">
                {{ t('c_institutions.org_detail.job_board_gloss', 'Explore this organization’s open roles and apply for work. The organization reviews applications.') }}
            </p>
            <ul v-if="jobs.length" class="offer-grid" style="margin-block-start: var(--space-3); list-style: none; padding: 0">
                <li v-for="job in jobs" :key="job.id" class="card card--inset">
                    <strong style="color: var(--gov-fg)">{{ job.title }}</strong>
                    <p v-if="job.terms" class="cc-small" style="margin-block: var(--space-1)">{{ job.terms }}</p>
                    <div class="cluster" style="gap: var(--space-2); justify-content: space-between; margin-block-start: var(--space-2)">
                        <span class="citation">
                            <template v-if="job.rate">{{ job.currency }}{{ fmtRate(job.rate) }}</template>
                            <template v-else>{{ t('c_institutions.org_detail.unpaid', 'Unpaid / by agreement') }}</template>
                            {{ t('c_institutions.org_detail.job_applications', { count: job.applications, label: job.applications === 1 ? t('c_institutions.org_detail.application_one', 'application') : t('c_institutions.org_detail.application_other', 'applications') }) }}
                        </span>
                        <Link :href="`/economy/requests/${job.id}`">{{ t('c_institutions.org_detail.review_apply', 'Review & apply') }}</Link>
                    </div>
                </li>
            </ul>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.org_detail.no_roles', 'No open roles right now — postings appear here when the organization opens one.') }}
            </p>
        </Card>

        <!-- ============================================ join cards ====== -->
        <div class="grid-2">
            <Card as="section" :title="t('c_institutions.org_detail.become_member_title', 'Become a member')">
                <template v-if="myMembership">
                    <Banner tone="info" role="status" :title="t('c_institutions.org_detail.member_banner_title', 'You are a member.')">
                        {{ t('c_institutions.org_detail.membership_is_before', { kind: titleize(myMembership.kind) }) }} <strong>{{ myMembership.status }}</strong>{{ t('c_institutions.org_detail.membership_is_after', '.') }}
                    </Banner>
                </template>
                <template v-else-if="can.join">
                    <p class="cc-small" style="margin-block-end: var(--space-2)">
                        {{ t('c_institutions.org_detail.apply_join_gloss', 'Apply to join this organization. Membership begins when your request is accepted under its bylaws.') }}
                    </p>
                    <form novalidate @submit.prevent="submitMembership">
                        <input type="hidden" name="form_id" value="F-IND-013" />
                        <div class="cluster">
                            <Btn type="submit" variant="primary" size="sm" :disabled="membershipForm.processing">{{ t('c_institutions.org_detail.apply_membership', 'Apply for membership') }}</Btn>
                            <FormChip :form-id="formMeta('F-IND-013').id" :name="formMeta('F-IND-013').name" />
                        </div>
                    </form>
                    <Banner v-if="membershipForm.errors.constitution" tone="warning" role="alert" style="margin-block-start: var(--space-2)">
                        {{ membershipForm.errors.constitution }}
                    </Banner>
                </template>
                <p v-else class="gloss">{{ t('c_institutions.org_detail.confirm_residency_join', 'Confirm your residency to join (Art. I).') }}</p>
            </Card>

            <Card as="section" :title="t('c_institutions.org_detail.register_worker_title', 'Register as a worker')">
                <p class="citation" style="margin-block-end: var(--space-2)">
                    {{ t('c_institutions.org_detail.worker_citation', 'Registered workers count toward the organization’s worker representation.') }}
                </p>
                <template v-if="myWorker">
                    <Banner tone="info" role="status" :title="t('c_institutions.org_detail.worker_banner_title', 'You are registered as a worker.')">
                        {{ t('c_institutions.org_detail.worker_reg_before', 'Your worker registration is') }} <strong>{{ myWorker.status }}</strong><template v-if="myWorker.since"> {{ t('c_institutions.org_detail.worker_since', { at: fmtDate(myWorker.since) }) }}</template>{{ t('c_institutions.org_detail.worker_reg_after', ". It activates on the organization's countersign.") }}
                    </Banner>
                </template>
                <template v-else-if="can.registerWorker">
                    <form novalidate @submit.prevent="submitWorker">
                        <input type="hidden" name="form_id" value="F-IND-014" />
                        <Field :label="t('c_institutions.org_detail.f_contract_ref', 'Contract reference')" :error="workerForm.errors.contract_terms" :hint="t('c_institutions.org_detail.f_contract_ref_hint', 'A recurring labor contract backs the registration; it counts toward headcount once the organization countersigns.')">
                            <template #control="{ id, describedBy }">
                                <input :id="id" v-model="workerForm.contract_terms" class="field-input" :aria-describedby="describedBy" />
                            </template>
                        </Field>
                        <div class="cluster">
                            <Btn type="submit" variant="primary" size="sm" :disabled="workerForm.processing">{{ t('c_institutions.org_detail.register_worker_btn', 'Register as worker') }}</Btn>
                            <FormChip :form-id="formMeta('F-IND-014').id" :name="formMeta('F-IND-014').name" />
                        </div>
                    </form>
                    <Banner v-if="workerForm.errors.constitution" tone="warning" role="alert" style="margin-block-start: var(--space-2)">
                        {{ workerForm.errors.constitution }}
                    </Banner>
                </template>
                <p v-else class="gloss">{{ t('c_institutions.org_detail.confirm_residency_worker', 'Confirm your residency to register as a worker (Art. I).') }}</p>
            </Card>
        </div>

        <!-- ============================================ ownership ======= -->
        <Card as="section" :title="t('c_institutions.org_detail.ownership_title', 'Ownership')">
            <OwnershipPanel
                :structure="ownership.structure"
                :is-cgc="ownership.isCgc"
                :stakes="ownership.stakes"
                :member-counts="ownership.memberCounts"
                :structure-history="ownership.structureHistory"
            />
        </Card>

        <!-- ============================================ the board ======= -->
        <Card as="section" :title="t('c_institutions.org_detail.board_title', 'Board')">
            <template v-if="board && board.exists">
                <div v-if="board.codet" class="cluster" style="gap: var(--space-5); margin-block-end: var(--space-3)">
                    <Stat :value="board.codet.ownerSeats" :label="t('c_institutions.org_detail.stat_owner_seats', 'Owner-side seats')" />
                    <Stat :value="board.codet.workerSeats" :label="t('c_institutions.org_detail.stat_worker_seats', 'Worker seats required')" />
                </div>
                <BoardStrip
                    :seats="board.strip.seats"
                    :composition-valid="board.strip.compositionValid"
                    :required-worker-seats="board.strip.requiredWorkerSeats"
                    compact
                />
            </template>
            <Banner v-else tone="info" role="status" :title="t('c_institutions.org_detail.no_board_title', 'No board constituted.')">
                {{ t('c_institutions.org_detail.no_board_body', 'No board has been constituted yet.') }}
            </Banner>
            <p style="margin-block-start: var(--space-3)">
                <Link :href="`/organizations/co-determination?org=${organization.id}`">{{ t('c_institutions.org_detail.worker_rep_link', 'How worker representation is determined →') }}</Link>
            </p>
        </Card>

        <!-- ============================================ documents ======= -->
        <Card as="section" :title="t('c_institutions.org_detail.documents_title', 'Document packages')">
            <DataTable v-if="documents.length" :columns="documentColumns" :rows="documents" row-key="key" :caption="t('c_institutions.org_detail.documents_caption', 'Internal document packages')">
                <template #cell-package="{ row }">
                    <strong style="color: var(--gov-fg)">{{ row.package }}</strong>
                    <span class="citation" style="display: block" data-no-i18n>{{ row.key }}</span>
                </template>
                <template #cell-kind="{ row }">{{ titleize(row.kind) }}</template>
                <template #cell-version="{ row }">{{ t('c_institutions.org_detail.version_prefix', { version: row.version }) }}</template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="row.status === 'active' ? 'success' : 'neutral'">{{ row.status }}</StatusBadge>
                </template>
            </DataTable>
            <p v-else class="gloss">{{ t('c_institutions.org_detail.no_documents', 'No document packages on record.') }}</p>

            <p class="citation" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.org_detail.documents_note', 'Internal packages never override the constitutional forms — a package key may not collide with a form ID.') }}
            </p>

            <details v-if="can.manage || can.documents" style="margin-block-start: var(--space-3)">
                <summary>{{ t('c_institutions.org_detail.summary_upload', 'Upload a new version') }}</summary>
                <form class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)" novalidate @submit.prevent="submitDocument">
                    <input type="hidden" name="form_id" value="F-ORG-001" />
                    <Field :label="t('c_institutions.org_detail.f_key', 'Key')" :error="documentForm.errors.key" :hint="t('c_institutions.org_detail.f_key_hint', 'A stable identifier for the package (e.g. bylaws-2031).')">
                        <template #control="{ id, describedBy }">
                            <input :id="id" v-model="documentForm.key" class="field-input" :aria-describedby="describedBy" />
                        </template>
                    </Field>
                    <Field :label="t('c_institutions.org_detail.f_name', 'Name')" :error="documentForm.errors.name">
                        <template #control="{ id }">
                            <input :id="id" v-model="documentForm.name" class="field-input" />
                        </template>
                    </Field>
                    <Field :label="t('c_institutions.org_detail.f_content', 'Content')" :error="documentForm.errors.content">
                        <template #control="{ id }">
                            <textarea :id="id" v-model="documentForm.content" class="field-input" rows="3"></textarea>
                        </template>
                    </Field>
                    <div class="cluster">
                        <Btn type="submit" variant="primary" size="sm" :disabled="documentForm.processing">{{ t('c_institutions.org_detail.record_version', 'Record version') }}</Btn>
                    </div>
                </form>
            </details>
        </Card>

        <!-- ============================================ contracts ======= -->
        <Card as="section" :title="t('c_institutions.org_detail.contracts_title', 'Contracts')">
            <template v-if="contracts.length">
                <div v-for="contract in contracts" :key="contract.id" class="card card--inset" style="margin-block-end: var(--space-2)">
                    <p style="margin: 0">
                        <strong style="color: var(--gov-fg)">{{ contract.title }}</strong>
                        <TagChip style="margin-inline-start: var(--space-1)" data-no-i18n>{{ titleize(contract.kind) }}</TagChip>
                        <TagChip v-if="contract.feeds_headcount" style="margin-inline-start: var(--space-1)">{{ t('c_institutions.org_detail.tag_counts_headcount', 'counts toward the worker headcount') }}</TagChip>
                    </p>
                    <p class="cc-small" style="margin-block: var(--space-1) 0">
                        {{ t('c_institutions.org_detail.counterparty_label', 'Counterparty:') }} {{ contract.counterparty }}
                    </p>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <StatusBadge :tone="contractStatusBadge(contract).tone">{{ contractStatusBadge(contract).text }}</StatusBadge>
                        <span class="citation">
                            {{ t('c_institutions.org_detail.contract_signatures', { org: contract.signed_a ? t('c_institutions.org_detail.signed', 'signed') : t('c_institutions.org_detail.unsigned', 'unsigned'), cp: contract.signed_b ? t('c_institutions.org_detail.signed', 'signed') : t('c_institutions.org_detail.unsigned', 'unsigned') }) }}
                        </span>
                        <Btn
                            v-if="(can.cosign || can.contracts) && !contract.signed_a && contract.status !== 'voided' && contract.status !== 'ended'"
                            variant="primary"
                            size="sm"
                            :disabled="cosignForm.processing"
                            @click="cosign(contract.id)"
                        >{{ t('c_institutions.org_detail.cosign_btn', 'Co-sign') }}</Btn>
                    </div>
                </div>
                <p class="gloss">
                    {{ t('c_institutions.org_detail.contracts_note', 'A contract takes effect only with both signatures — the engine rejects effect before both.') }}
                </p>
            </template>
            <p v-else class="gloss">{{ t('c_institutions.org_detail.no_contracts', 'No contracts on record.') }}</p>
        </Card>

        <!-- ============================================ ESM-18 ========== -->
        <Card as="section" :title="t('c_institutions.org_detail.org_status_title', 'Organization status')">
            <StateStrip :states="machine" :current="organization.status" />
        </Card>

        <template #about>
            <p>
                {{ t('c_institutions.org_detail.about', 'This profile is a public record. The endorsement handshake, the membership and worker join paths, and the co-determination scale all run on one organization model — there is no faction layer.') }}
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
/* IO-4 / IO-5 — 44px minimum for the review, transfer and delegation controls
   (touch target). */
.io4-controls :deep(button),
.io4-controls .field-input,
.io5-controls :deep(button),
.io5-controls .field-input {
    min-block-size: 44px;
}
</style>
