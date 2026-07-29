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
import { Link, useForm, router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    agreement: { type: Object, required: true },
    /** Negotiated amendments to the base terms (overlay); [] until proposed. */
    clauses: { type: Array, default: () => [] },
    /** Pending redlines awaiting accept / reject / withdraw. */
    redlines: { type: Array, default: () => [] },
    /** True for a live instrument (draft/offered/active); false once history. */
    can_negotiate: { type: Boolean, default: false },
    my_id: { type: String, default: null },
});

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

const KIND_LABEL = {
    labor_recurring: 'Labor — recurring',
    labor_single: 'Labor — one-off',
    commercial: 'Commercial',
    other: 'Free-form',
};

const STATUS_LABEL = {
    draft: 'Draft — not yet offered',
    offered: 'Offered — awaiting a signature',
    active: 'Active — signed by both parties',
    ended: 'Ended',
    voided: 'Voided',
};
</script>

<template>
    <PageScaffold title="Agreement">
        <template #intro>
            One instrument, on the record: the parties, the terms, and both signatures. The floor
            beneath it cannot be lowered by any clause.
        </template>

        <p class="econ-note">
            This agreement is visible to its parties only.
        </p>

        <Card as="section" inset>
            <p class="agr-kind">{{ KIND_LABEL[agreement.kind] ?? agreement.kind }}</p>
            <h2 class="agr-title">{{ agreement.org_name }} ↔ {{ agreement.counterparty }}</h2>
            <p class="agr-status-line">{{ STATUS_LABEL[agreement.status] ?? agreement.status }}</p>
            <dl class="agr-dates">
                <div v-if="agreement.created_at"><dt>Drafted</dt><dd>{{ formatWhen(agreement.created_at) }}</dd></div>
                <div v-if="agreement.effective_at"><dt>In force since</dt><dd>{{ formatWhen(agreement.effective_at) }}</dd></div>
                <div v-if="agreement.ended_at"><dt>Ended</dt><dd>{{ formatWhen(agreement.ended_at) }}</dd></div>
            </dl>
        </Card>

        <Card as="section" title="The signatures">
            <ul class="agr-sig-list">
                <li :class="agreement.signed_by_org ? 'agr-signed' : 'agr-unsigned'">
                    <strong>{{ agreement.org_name }}</strong>
                    <template v-if="agreement.signed_by_org">
                        — signed {{ formatWhen(agreement.signed_by_org_at) }}
                        <template v-if="agreement.org_signer"> by {{ agreement.org_signer }}</template>
                    </template>
                    <template v-else> — not yet signed</template>
                </li>
                <li :class="agreement.signed_by_counterparty ? 'agr-signed' : 'agr-unsigned'">
                    <strong>{{ agreement.counterparty }}</strong>
                    <template v-if="agreement.signed_by_counterparty">
                        — signed {{ formatWhen(agreement.signed_by_counterparty_at) }}
                    </template>
                    <template v-else> — not yet signed</template>
                </li>
            </ul>
            <p class="econ-note">
                Both parties sign, or the agreement never takes effect — the record itself refuses
                an active contract with a missing signature.
            </p>
        </Card>

        <Card as="section" title="The terms">
            <p class="agr-terms-full">{{ agreement.terms_full }}</p>
        </Card>

        <!-- ----------------------------------------------- negotiation -->
        <Card as="section" title="Negotiation">
            <p class="econ-note">
                The terms above are the agreed base. A change is proposed as a redline; when the
                other party accepts, it amends the instrument and <strong>both signatures clear</strong>
                — the parties re-sign the changed text. No clause may waive a constitutional right.
            </p>

            <!-- amendments already accepted onto the overlay -->
            <div v-if="clauses.length" class="agr-clauses">
                <h3>Amendments</h3>
                <div v-for="c in clauses" :key="c.id" class="agr-clause">
                    <p><strong v-if="c.heading">{{ c.heading }}: </strong>{{ c.body }}</p>
                    <div v-if="can_negotiate" class="agr-clause-acts">
                        <button type="button" @click="propose(c.id, 'edit')">Propose an edit</button>
                        <button type="button" @click="propose(c.id, 'strike')">Propose to strike</button>
                    </div>
                </div>
            </div>

            <!-- pending redlines -->
            <div v-if="redlines.length" class="agr-redlines">
                <h3>Proposed changes</h3>
                <div v-for="r in redlines" :key="r.id" class="agr-redline">
                    <p><strong>{{ r.kind }}</strong>: {{ r.body }}</p>
                    <p v-if="r.rationale" class="econ-note">Why: {{ r.rationale }}</p>
                    <div v-if="can_negotiate" class="agr-redline-acts">
                        <template v-if="r.is_mine">
                            <button type="button" @click="resolve(r.id, 'withdraw')">Withdraw</button>
                        </template>
                        <template v-else>
                            <button type="button" @click="resolve(r.id, 'accept')">Accept (voids signatures)</button>
                            <button type="button" @click="resolve(r.id, 'reject')">Reject</button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- propose a new amendment -->
            <details v-if="can_negotiate" class="agr-propose">
                <summary>Propose a change</summary>
                <div class="agr-propose-body">
                    <textarea v-model="redline.body" rows="2" maxlength="10000" placeholder="The amendment you propose (added as a new clause)"></textarea>
                    <input v-model="redline.rationale" type="text" maxlength="500" placeholder="Why (optional)" />
                    <button type="button" :disabled="redline.processing || !redline.body" @click="propose(null, 'add')">Propose amendment</button>
                    <p v-if="redline.errors.constitution" class="agr-err">{{ redline.errors.constitution }}</p>
                </div>
            </details>
            <p v-else-if="!clauses.length && !redlines.length" class="econ-note">
                This instrument is {{ agreement.status }} — it is history, and can no longer be negotiated.
            </p>
        </Card>

        <Card as="section" title="The floor">
            <p>
                No clause in this or any agreement can waive, sell, or sign away a constitutional
                right — voting, candidacy, residency, petitioning, due process. A clause that tries
                is void in that part; the rest stands. No agreement may attach a fee or cost to
                exercising a civic right.
            </p>
        </Card>

        <p>
            <Link href="/economy/agreements" class="econ-back">Back to your agreements</Link>
        </p>
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
