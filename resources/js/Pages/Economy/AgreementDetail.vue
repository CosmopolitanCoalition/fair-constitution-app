<script setup>
/**
 * Economy/AgreementDetail — one instrument, in full (design contract:
 * mockups/v3/economy/agreement-detail.html).
 *
 * PARTIES ONLY. The controller 404s this page to anyone who is not a party
 * — a non-party is not told the instrument exists, let alone its terms.
 *
 * NEGOTIATION (Wave 4): the base terms stay authoritative; a clause is a
 * negotiated AMENDMENT and a redline a pending change, both through the
 * F-IND-020 engine door (RedlineService). Accepting a change VOIDS the
 * signatures — a signature is on a specific text — so the parties re-sign the
 * changed instrument. A clause can never waive a right (Art. I floor); a
 * redline that declares a waiver is refused with a citation.
 */
import { computed } from 'vue';
import { Link, useForm, usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    agreement: { type: Object, required: true },
    /** Negotiated amendments to the base terms (overlay); [] until proposed. */
    clauses: { type: Array, default: () => [] },
    /** Pending redlines awaiting accept / reject / withdraw. */
    redlines: { type: Array, default: () => [] },
    /** True for a live instrument (draft/offered/active); false once history. */
    can_negotiate: { type: Boolean, default: false },
    can_cosign: { type: Boolean, default: false },
    cosign_url: { type: String, default: null },
    my_id: { type: String, default: null },
});
const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const cosign = useForm({});
function countersign() {
    if (!props.can_cosign || !props.cosign_url || cosign.processing) return;
    cosign.post(props.cosign_url, { preserveScroll: true });
}

const resolve = (redlineId, how) =>
    router.post(`/economy/redlines/${redlineId}/${how}`, {}, { preserveScroll: true });

// One redline composer, subject_type fixed to this agreement's contract.
const redline = useForm({
    subject_type: 'org_contract',
    subject_id: props.agreement.id,
    clause_id: '',
    kind: 'add',
    body: '',
    rationale: '',
});
const propose = (clauseId, kind) => {
    redline.clause_id = clauseId ?? '';
    redline.kind = kind;
    redline.post('/economy/redlines', {
        preserveScroll: true,
        onSuccess: () => { redline.body = ''; redline.rationale = ''; },
    });
};

const KIND_LABEL = computed(() => ({
    labor_recurring: t('c_economy.agreement_detail.kind_labor_recurring', 'Labor — recurring'),
    labor_single: t('c_economy.agreement_detail.kind_labor_single', 'Labor — one-off'),
    commercial: t('c_economy.agreement_detail.kind_commercial', 'Commercial'),
    other: t('c_economy.agreement_detail.kind_other', 'Free-form'),
}));

const STATUS_LABEL = computed(() => ({
    draft: t('c_economy.agreement_detail.status_draft', 'Draft — not yet offered'),
    offered: t('c_economy.agreement_detail.status_offered', 'Offered — awaiting a signature'),
    active: t('c_economy.agreement_detail.status_active', 'Active — signed by both parties'),
    ended: t('c_economy.agreement_detail.status_ended', 'Ended'),
    voided: t('c_economy.agreement_detail.status_voided', 'Voided'),
}));
</script>

<template>
    <PageScaffold :title="t('c_economy.agreement_detail.title', 'Agreement')">
        <WorkTradeNav active="agreements" back-href="/economy/agreements" :back-label="t('c_economy.agreement_detail.back_label', 'My agreements')" />
        <template #intro>
            {{ t('c_economy.agreement_detail.intro', 'One instrument, on the record: the parties, the terms, and both signatures. The floor beneath it cannot be lowered by any clause.') }}
        </template>

        <p class="econ-note">
            {{ t('c_economy.agreement_detail.parties_only', 'This agreement is visible to its parties only.') }}
        </p>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-for="(error, key) in cosign.errors" :key="key" tone="warning" role="alert">{{ error }}</Banner>

        <Card as="section" inset>
            <p class="agr-kind">{{ KIND_LABEL[agreement.kind] ?? agreement.kind }}</p>
            <h2 class="agr-title">{{ agreement.org_name }} ↔ {{ agreement.counterparty }}</h2>
            <p class="agr-status-line">{{ STATUS_LABEL[agreement.status] ?? agreement.status }}</p>
            <dl class="agr-dates">
                <div v-if="agreement.created_at"><dt>{{ t('c_economy.agreement_detail.drafted', 'Drafted') }}</dt><dd>{{ formatWhen(agreement.created_at) }}</dd></div>
                <div v-if="agreement.effective_at"><dt>{{ t('c_economy.agreement_detail.in_force_since', 'In force since') }}</dt><dd>{{ formatWhen(agreement.effective_at) }}</dd></div>
                <div v-if="agreement.ended_at"><dt>{{ t('c_economy.agreement_detail.date_ended', 'Ended') }}</dt><dd>{{ formatWhen(agreement.ended_at) }}</dd></div>
            </dl>
        </Card>

        <Card as="section" :title="t('c_economy.agreement_detail.signatures_title', 'The signatures')">
            <ul class="agr-sig-list">
                <li :class="agreement.signed_by_org ? 'agr-signed' : 'agr-unsigned'">
                    <strong>{{ agreement.org_name }}</strong>
                    <template v-if="agreement.signed_by_org">
                        {{ t('c_economy.agreement_detail.signed_when', { when: formatWhen(agreement.signed_by_org_at) }) }}
                        <template v-if="agreement.org_signer">{{ t('c_economy.agreement_detail.signed_by', { who: agreement.org_signer }) }}</template>
                    </template>
                    <template v-else>{{ t('c_economy.agreement_detail.not_yet_signed', '— not yet signed') }}</template>
                </li>
                <li :class="agreement.signed_by_counterparty ? 'agr-signed' : 'agr-unsigned'">
                    <strong>{{ agreement.counterparty }}</strong>
                    <template v-if="agreement.signed_by_counterparty">
                        {{ t('c_economy.agreement_detail.signed_when', { when: formatWhen(agreement.signed_by_counterparty_at) }) }}
                    </template>
                    <template v-else>{{ t('c_economy.agreement_detail.not_yet_signed', '— not yet signed') }}</template>
                </li>
            </ul>
            <p class="econ-note">
                {{ t('c_economy.agreement_detail.both_sign_note', 'Both parties sign, or the agreement never takes effect — the record itself refuses an active contract with a missing signature.') }}
            </p>
        </Card>

        <Card as="section" :title="t('c_economy.agreement_detail.terms_title', 'The terms')">
            <p class="agr-terms-full">{{ agreement.terms_full }}</p>
        </Card>
        <Card v-if="can_cosign" as="section" :title="t('c_economy.agreement_detail.org_signature_title', 'Organization’s signature')">
            <p>{{ t('c_economy.agreement_detail.review_before_sign', { name: agreement.org_name }) }}</p>
            <p class="econ-note">{{ agreement.signed_by_counterparty ? t('c_economy.agreement_detail.cosign_note_signed', 'The other party has signed. Your countersignature puts this agreement into effect.') : t('c_economy.agreement_detail.cosign_note_unsigned', 'Your signature will be recorded. The agreement takes effect only after both parties sign.') }}</p>
            <form @submit.prevent="countersign">
                <Btn type="submit" :disabled="cosign.processing || redline.processing">{{ cosign.processing ? t('c_economy.agreement_detail.countersigning', 'Countersigning…') : t('c_economy.agreement_detail.countersign', 'Countersign for the organization') }}</Btn>
            </form>
        </Card>

        <!-- ----------------------------------------------- negotiation -->
        <Card as="section" :title="t('c_economy.agreement_detail.negotiation_title', 'Negotiation')">
            <p class="econ-note">
                {{ t('c_economy.agreement_detail.negotiation_note_before', 'The terms above are the agreed base. A change is proposed as a redline; when the other party accepts, it amends the instrument and') }}
                <strong>{{ t('c_economy.agreement_detail.negotiation_note_strong', 'both signatures clear') }}</strong>
                {{ t('c_economy.agreement_detail.negotiation_note_after', '— the parties re-sign the changed text. No clause may waive a constitutional right.') }}
            </p>

            <!-- amendments already accepted onto the overlay -->
            <div v-if="clauses.length" class="agr-clauses">
                <h3>{{ t('c_economy.agreement_detail.amendments_heading', 'Amendments') }}</h3>
                <div v-for="c in clauses" :key="c.id" class="agr-clause">
                    <p><strong v-if="c.heading">{{ c.heading }}: </strong>{{ c.body }}</p>
                    <div v-if="can_negotiate" class="agr-clause-acts">
                        <button type="button" @click="propose(c.id, 'edit')">{{ t('c_economy.agreement_detail.propose_edit', 'Propose an edit') }}</button>
                        <button type="button" @click="propose(c.id, 'strike')">{{ t('c_economy.agreement_detail.propose_strike', 'Propose to strike') }}</button>
                    </div>
                </div>
            </div>

            <!-- pending redlines -->
            <div v-if="redlines.length" class="agr-redlines">
                <h3>{{ t('c_economy.agreement_detail.proposed_changes_heading', 'Proposed changes') }}</h3>
                <div v-for="r in redlines" :key="r.id" class="agr-redline">
                    <p><strong>{{ r.kind }}</strong>: {{ r.body }}</p>
                    <p v-if="r.rationale" class="econ-note">{{ t('c_economy.agreement_detail.redline_why', { rationale: r.rationale }) }}</p>
                    <div v-if="can_negotiate" class="agr-redline-acts">
                        <template v-if="r.is_mine">
                            <button type="button" @click="resolve(r.id, 'withdraw')">{{ t('c_economy.agreement_detail.withdraw', 'Withdraw') }}</button>
                        </template>
                        <template v-else>
                            <button type="button" @click="resolve(r.id, 'accept')">{{ t('c_economy.agreement_detail.accept_voids', 'Accept (voids signatures)') }}</button>
                            <button type="button" @click="resolve(r.id, 'reject')">{{ t('c_economy.agreement_detail.reject', 'Reject') }}</button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- propose a new amendment -->
            <details v-if="can_negotiate" class="agr-propose">
                <summary>{{ t('c_economy.agreement_detail.propose_change_summary', 'Propose a change') }}</summary>
                <div class="agr-propose-body">
                    <textarea v-model="redline.body" rows="2" maxlength="10000" :aria-label="t('c_economy.agreement_detail.aria_proposed_amendment', 'Proposed amendment')" :placeholder="t('c_economy.agreement_detail.placeholder_amendment', 'The amendment you propose (added as a new clause)')"></textarea>
                    <input v-model="redline.rationale" type="text" maxlength="500" :aria-label="t('c_economy.agreement_detail.aria_reason', 'Reason for the amendment')" :placeholder="t('c_economy.agreement_detail.placeholder_why', 'Why (optional)')" />
                    <button type="button" :disabled="redline.processing || !redline.body" @click="propose(null, 'add')">{{ t('c_economy.agreement_detail.propose_amendment_btn', 'Propose amendment') }}</button>
                    <p v-if="redline.errors.constitution" class="agr-err">{{ redline.errors.constitution }}</p>
                </div>
            </details>
            <p v-else-if="!clauses.length && !redlines.length" class="econ-note">
                {{ t('c_economy.agreement_detail.history_note', { status: agreement.status }) }}
            </p>
        </Card>

        <details class="agr-propose">
            <summary>{{ t('c_economy.agreement_detail.rights_summary', 'Rights protected in every agreement') }}</summary>
            <p>
                {{ t('c_economy.agreement_detail.rights_body', 'No clause in this or any agreement can waive, sell, or sign away a constitutional right — voting, candidacy, residency, petitioning, due process. A clause that tries is void in that part; the rest stands. No agreement may attach a fee or cost to exercising a civic right.') }}
            </p>
        </details>
    </PageScaffold>
</template>

<style scoped>
.agr-kind {
    margin: 0;
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.agr-title {
    margin: 0;
    color: var(--gov-fg, #223);
    font-size: var(--text-lg, 1.25rem);
}
.agr-status-line {
    margin-block: var(--space-1, 0.25rem) 0;
    color: var(--gov-fg-muted, #667);
}
.agr-dates {
    display: flex;
    gap: var(--space-3, 1rem);
    flex-wrap: wrap;
    margin: var(--space-3, 1rem) 0 0;
}
.agr-dates dt {
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.agr-dates dd {
    margin: 0;
    color: var(--gov-fg, #223);
}
.agr-sig-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-2, 0.5rem);
}
.agr-signed {
    color: var(--gov-fg, #223);
}
.agr-unsigned {
    color: var(--gov-fg-muted, #667);
}
.agr-terms-full {
    white-space: pre-wrap;
    margin: 0;
}
.econ-note {
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #778);
}
.agr-clauses,
.agr-redlines {
    margin-block-start: var(--space-3, 1rem);
    border-block-start: 1px solid var(--gov-border, #dde);
    padding-block-start: var(--space-3, 0.75rem);
}
.agr-clause,
.agr-redline {
    margin-block-end: var(--space-3, 0.75rem);
}
.agr-clause-acts,
.agr-redline-acts {
    display: flex;
    gap: var(--space-2, 0.5rem);
    flex-wrap: wrap;
}
.agr-propose {
    margin-block-start: var(--space-3, 1rem);
}
.agr-propose-body {
    display: flex;
    flex-direction: column;
    gap: var(--space-2, 0.5rem);
    max-inline-size: 40rem;
    margin-block-start: var(--space-2, 0.5rem);
}
.agr-err {
    color: var(--gov-danger, #b00);
    font-size: var(--text-sm, 0.875rem);
}
</style>
