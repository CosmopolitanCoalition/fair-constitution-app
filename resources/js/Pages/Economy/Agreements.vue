<script setup>
/**
 * Economy/Agreements — the instruments register (design contract:
 * mockups/v3/economy/agreements.html).
 *
 * PARTY-SCOPED BY CONSTRUCTION. A contract is consent between parties, so
 * parties see each other by name — that is what a signature is. But the
 * instrument itself is private: this register lists only agreements the
 * viewer is a party to, and the controller never ships terms to anyone
 * else. No raw user ids cross the boundary — names only.
 *
 * THE FLOOR RENDERS ON EVERY INSTRUMENT because it holds on every
 * instrument: no clause may waive a right, and both sides must sign —
 * the second rule is enforced by the DATABASE (a contract cannot reach
 * 'active' without both signatures; org_contracts_cosign_check).
 *
 * READ-ONLY v1. Drafting free-form agreements arrives with the
 * negotiation/redline model (Wave 3, design-gated). Today an instrument
 * comes into existence through the acts that imply it: a hire records a
 * labor agreement (F-IND-014 chain), a market settlement records a
 * commercial one (F-IND-022).
 */
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

defineProps({
    agreements: { type: Array, default: () => [] },
});

const KIND_LABEL = {
    labor_recurring: 'Labor — recurring',
    labor_single: 'Labor — one-off',
    commercial: 'Commercial',
    other: 'Free-form',
};

const STATUS_LABEL = {
    draft: 'Draft',
    offered: 'Offered — awaiting a signature',
    active: 'Active — signed by both parties',
    ended: 'Ended',
    voided: 'Voided',
};

const KINDS_REFERENCE = [
    {
        kind: 'Labor',
        note: 'Recurring or one-off work between a worker and an organization. Feeds co-determination headcount.',
        formId: 'F-IND-014',
        formName: 'Worker Registration',
    },
    {
        kind: 'Commercial',
        note: 'A sale settled on the open market — money and thing moved together, and the agreement records it.',
        formId: 'F-IND-022',
        formName: 'Marketplace Listing / Order',
    },
    {
        kind: 'Free-form',
        note: 'Any other agreement parties freely enter — still bound by the constitutional floor. Drafting arrives with the negotiation model.',
        formId: null,
        formName: null,
    },
];
</script>

<template>
    <PageScaffold title="Agreements">
        <template #intro>
            Contracts here are an expression of the freedom to contract — parties freely set terms
            between themselves. But every agreement sits on a floor: no clause may waive, sell, or
            sign away a constitutional right. A contract that tries to is void in that part; the
            rest stands.
        </template>

        <p class="econ-note">
            The terms of an agreement are private to its parties — like a ballot, nobody else can
            read them. This page shows only agreements you are a party to.
        </p>

        <Card as="section" title="Your agreements">
            <p v-if="!agreements.length" class="econ-empty">
                You are not a party to any agreement yet. A hire records a labor agreement; a
                settled sale records a commercial one.
            </p>
            <article v-for="a in agreements" :key="a.id" class="agr-card">
                <p class="agr-kind">{{ KIND_LABEL[a.kind] ?? a.kind }}</p>
                <div class="agr-head">
                    <h3>{{ a.org_name }} ↔ {{ a.counterparty }}</h3>
                    <span class="agr-status" :data-status="a.status">{{ STATUS_LABEL[a.status] ?? a.status }}</span>
                </div>
                <p class="agr-terms">{{ a.terms }}</p>
                <p class="agr-signatures">
                    <span :class="a.signed_by_org ? 'agr-signed' : 'agr-unsigned'">
                        {{ a.org_name }}: {{ a.signed_by_org ? `signed ${formatWhen(a.signed_by_org_at)}` : 'not yet signed' }}
                    </span>
                    <span :class="a.signed_by_counterparty ? 'agr-signed' : 'agr-unsigned'">
                        {{ a.counterparty }}: {{ a.signed_by_counterparty ? `signed ${formatWhen(a.signed_by_counterparty_at)}` : 'not yet signed' }}
                    </span>
                </p>
                <p class="agr-floor">
                    No clause may waive a right. Both parties sign — a one-sided contract never
                    takes effect.
                </p>
                <p><Link :href="`/economy/agreements/${a.id}`" class="econ-back">Open this agreement</Link></p>
            </article>
        </Card>

        <Card as="section" title="The floor under every agreement">
            <ul class="agr-rules">
                <li>
                    <strong>No clause can waive a right.</strong> An agreement may not touch voting,
                    candidacy, residency, petitioning, due process, or any constitutional right.
                </li>
                <li>
                    <strong>Both sides must sign.</strong> Consent of every party is on the record —
                    a one-sided contract never takes effect. The database itself refuses an active
                    contract with a missing signature.
                </li>
                <li>
                    <strong>No paywall on a civic act.</strong> No agreement may attach a fee or
                    cost to exercising a civic right or obligation.
                </li>
                <li>
                    <strong>Shared resources stay jointly controlled.</strong> A joint ledger moves
                    only by the agreement of its co-owners.
                </li>
            </ul>
            <p class="econ-note">No law, panel, or clause can lower this floor.</p>
        </Card>

        <Card as="section" title="The kinds of agreement">
            <table class="agr-kinds">
                <thead>
                    <tr><th scope="col">Kind</th><th scope="col">What it does</th><th scope="col">Recorded by</th></tr>
                </thead>
                <tbody>
                    <tr v-for="k in KINDS_REFERENCE" :key="k.kind">
                        <td>{{ k.kind }}</td>
                        <td>{{ k.note }}</td>
                        <td>
                            <FormChip v-if="k.formId" :form-id="k.formId" :name="k.formName" />
                            <span v-else>Free-form (the floor still binds)</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.agr-card {
    border: 1px solid var(--gov-border, #dde);
    border-radius: 0.5rem;
    padding: var(--space-3, 1rem);
    margin-block-end: var(--space-3, 1rem);
}
.agr-kind {
    margin: 0;
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.agr-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-2, 0.5rem);
    flex-wrap: wrap;
}
.agr-head h3 {
    margin: 0;
    color: var(--gov-fg, #223);
}
.agr-status {
    font-size: var(--text-sm, 0.875rem);
    color: var(--gov-fg-muted, #667);
}
.agr-status[data-status='active'] {
    color: var(--gov-fg, #223);
    font-weight: 600;
}
.agr-terms {
    margin-block: var(--space-2, 0.5rem);
}
.agr-signatures {
    display: flex;
    gap: var(--space-3, 1rem);
    flex-wrap: wrap;
    margin: 0;
    font-size: var(--text-sm, 0.875rem);
}
.agr-signed {
    color: var(--gov-fg, #223);
}
.agr-unsigned {
    color: var(--gov-fg-muted, #667);
}
.agr-floor {
    background: var(--gov-surface-subtle, #eef);
    border-radius: 0.5rem;
    padding: var(--space-2, 0.5rem) var(--space-3, 1rem);
    font-size: var(--text-sm, 0.875rem);
    margin-block: var(--space-2, 0.5rem);
}
.agr-rules {
    margin: 0;
    padding-inline-start: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: var(--space-2, 0.5rem);
}
.agr-kinds {
    inline-size: 100%;
    border-collapse: collapse;
}
.agr-kinds th,
.agr-kinds td {
    text-align: start;
    padding: var(--space-2, 0.5rem);
    border-block-end: 1px solid var(--gov-border, #dde);
}
</style>
