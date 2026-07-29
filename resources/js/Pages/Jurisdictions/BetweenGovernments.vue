<script setup>
/**
 * Jurisdictions/BetweenGovernments — the read-only CITIZEN view at
 * /federation (design contract: mockups/v3/jurisdictions/federation.html;
 * ruling §10 item 9 — the operator console moved to /operator/federation).
 *
 * When two self-governing places share an edge — or a disagreement about
 * where it sits — the constitution gives a peaceful way through. The
 * everyday case is a boundary change: the people inside the moving boundary
 * deliberate, vote, and the map updates (Art. V §2 — a 2/3 supermajority of
 * everyone affected, not just those voting). Bigger moves link out to union
 * formation and disintermediation; the server plumbing lives on the
 * operator's mesh pages.
 */
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    settlements: { type: Array, default: () => [] },
});

const steps = [
    { label: 'Deliberation — the proposal is drafted and discussed in the open', state: 'done' },
    { label: 'Referendum — everyone inside the moving boundary votes', state: 'active' },
    { label: 'Done — the map updates and everyone\'s records follow', state: 'pending' },
];

const statusBadge = (s) =>
    s.status === 'adopted' ? { tone: 'success', icon: 'check', label: 'Ratified' }
        : s.status === 'rejected' ? { tone: 'warning', label: 'Rejected — supermajority not reached' }
            : s.status === 'expired' ? { tone: 'neutral', label: 'Expired' }
                : s.supermajority_met ? { tone: 'info', label: 'Supermajority met — adoption pending' }
                    : { tone: 'neutral', label: 'Proposal open' };
</script>

<template>
    <PageScaffold :surface="surface" title="Between governments">
        <template #intro>
            When two self-governing places share an edge — or a disagreement about where that
            edge sits — the constitution gives a peaceful way through. The everyday case is a
            boundary change: the people who live inside the moving boundary deliberate, vote,
            and the map updates. Bigger moves are
            <a href="/jurisdictions/union-formation">merging into a union</a> and
            <a href="/jurisdictions/disintermediation">removing a middle layer</a>.
        </template>

        <Card as="section">
            <template #title>Border settlement</template>
            <p>
                Boundary changes between places pass by a supermajority of the affected
                population — the people inside the moving boundary decide, not the
                legislatures around them.
            </p>
            <Stepper :steps="steps" />

            <Banner v-if="settlements.length === 0" tone="info" role="status">
                <strong>No boundary changes proposed.</strong> Any two neighbouring places can
                open one; it appears here the moment it exists.
            </Banner>

            <DataTable
                v-else
                :columns="[
                    { key: 'change', label: 'Boundary change' },
                    { key: 'affected', label: 'Affected population' },
                    { key: 'required', label: 'Supermajority needed' },
                    { key: 'status', label: 'Status' },
                ]"
                :rows="settlements"
                row-key="id"
            >
                <template #cell-change="{ row }">{{ row.a }} ↔ {{ row.b }}</template>
                <template #cell-affected="{ row }">
                    <span data-no-i18n>{{ row.affected_population.toLocaleString() }}</span>
                </template>
                <template #cell-required="{ row }">
                    <span data-no-i18n>{{ row.required.toLocaleString() }} (2/3 of all affected)</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="statusBadge(row).tone" :icon="statusBadge(row).icon || null">
                        {{ statusBadge(row).label }}
                    </StatusBadge>
                </template>
            </DataTable>

            <p>
                Once a settlement is ratified, every affected resident's home association is
                re-checked against the new boundary — rights re-attach automatically on the
                new side of the line. Nobody has to re-register.
            </p>
            <p class="citation">Boundary changes pass by a two-thirds supermajority of the affected population · Art. V §2</p>
        </Card>

        <Card as="section">
            <template #title>The servers behind this</template>
            <p>
                Different governments can run on different servers that find each other, agree
                who holds the master copy, and stay in sync. That plumbing has its own pages
                in the operator area: <a href="/operator/mesh">the server mesh</a>.
            </p>
        </Card>

        <template #about>
            <p>
                A proposal goes through open deliberation, then a referendum of the affected
                population — a two-thirds supermajority of everyone affected, not just those
                voting. Recognized peers who want to go further continue in
                <a href="/jurisdictions/union-formation">union formation</a>. The
                peer-discovery, record-sync and server-authority machinery that used to share
                this page now lives on the operator's mesh pages.
            </p>
        </template>
    </PageScaffold>
</template>
