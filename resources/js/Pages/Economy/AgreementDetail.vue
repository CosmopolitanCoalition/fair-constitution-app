<script setup>
/**
 * Economy/AgreementDetail — one instrument, in full (design contract:
 * mockups/v3/economy/agreement-detail.html, the READ half).
 *
 * PARTIES ONLY. The controller 404s this page to anyone who is not a party
 * — a non-party is not told the instrument exists, let alone its terms.
 *
 * READ-ONLY v1: the negotiation/redline composer (clauses, redlines,
 * threaded comments) is the Wave 3 design-gated build. What is shown here
 * is the record: full terms, both signatures with their dates, status, and
 * the floor no clause can lower.
 */
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

defineProps({
    agreement: { type: Object, required: true },
});

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
</style>
