<script setup>
/**
 * Jurisdictions/UnionFormation — "check differences, agree one rulebook,
 * then vote" (design contract: mockups/v3/jurisdictions/union-formation.html).
 *
 * Art. V §7: two or more independent jurisdictions form a union by checking
 * where their settings differ, agreeing one shared value per difference, and
 * ratifying by supermajority of the applicant population AND of the union's
 * constituent jurisdictions. Exit mirrors entry. The list renders real
 * union_processes with both ratification meters; the F-LEG-029 door files a
 * real chamber proposal (a seat is required — the service re-checks
 * everything, this page only reads capability).
 */
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    processes: { type: Array, default: () => [] },
    door: { type: Object, default: () => ({ seat: null, siblings: [] }) },
});

const chosenSiblings = ref([]);
const submitting = ref(false);

function propose() {
    if (!props.door.seat || chosenSiblings.value.length === 0) return;
    submitting.value = true;
    router.post('/jurisdictions/union-formation/propose', {
        kind: 'formation',
        applicant_ids: [props.door.seat.jurisdiction_id, ...chosenSiblings.value],
    }, {
        preserveScroll: true,
        onFinish: () => { submitting.value = false; },
    });
}

const statusTone = (s) =>
    s === 'passed' ? 'success' : s === 'failed' || s === 'expired' ? 'warning' : 'info';

const diffRows = (diff) =>
    Object.entries(diff || {}).map(([setting, d]) => ({
        setting,
        values: Object.values(d?.values ?? {}).join(' vs '),
        aligned: Boolean(d?.aligned),
    }));
</script>

<template>
    <PageScaffold :surface="surface" title="Union formation">
        <template #intro>
            Two or more independent places form a union by checking where their settings and
            institutions differ, agreeing one shared value for each difference, and ratifying
            by supermajority — of the applicant population and of the union's constituent
            jurisdictions. Exit mirrors entry: no one-way doors.
        </template>

        <Banner v-if="processes.length === 0" tone="info" role="status">
            <strong>No live case.</strong> Earth starts united, so no union is currently
            forming. The process below is real and waiting — a chamber proposal
            (<span class="citation">F-LEG-029</span>) opens it.
        </Banner>

        <Card v-for="p in processes" :key="p.id" as="section">
            <template #title>
                {{ p.kind === 'formation' ? 'Founding union' : p.kind === 'join' ? 'Joining a union' : 'Leaving a union' }}
                — {{ p.applicants.map(a => a.name).join(', ') }}
                <template v-if="p.union_name"> · {{ p.union_name }}</template>
            </template>

            <p>
                <StatusBadge :tone="statusTone(p.status)">{{ plainState(p.status) }}</StatusBadge>
                <span class="citation" data-no-i18n>opened {{ new Date(p.opened_at).toLocaleDateString() }}</span>
            </p>

            <DataTable
                v-if="diffRows(p.compatibility_diff).length"
                :columns="[
                    { key: 'setting', label: 'Setting' },
                    { key: 'values', label: 'Values' },
                    { key: 'aligned', label: 'Status' },
                ]"
                :rows="diffRows(p.compatibility_diff)"
                row-key="setting"
            >
                <template #cell-setting="{ row }"><span data-no-i18n>{{ plainState(row.setting) }}</span></template>
                <template #cell-aligned="{ row }">
                    <StatusBadge v-if="row.aligned" tone="success" icon="check">Aligned</StatusBadge>
                    <StatusBadge v-else tone="warning">Needs one shared value</StatusBadge>
                </template>
            </DataTable>

            <h4>Ratification meters</h4>
            <p>Denominators are whole populations, never just those voting.</p>
            <ThresholdMeter
                :value="p.applicant_supermajority_met ? 1 : 0"
                :max="1"
                :threshold="1"
                label="Applicant population referendum"
            >
                Applicant population referendum
                <template #note>{{ p.applicant_supermajority_met ? 'supermajority met' : 'not yet met' }}</template>
            </ThresholdMeter>
            <ThresholdMeter
                v-if="p.constituent_vote"
                :value="p.constituent_vote.yes"
                :max="p.constituent_vote.total"
                :threshold="p.constituent_vote.required"
                label="Constituent jurisdictions"
            >
                {{ p.constituent_vote.yes }} of {{ p.constituent_vote.required }} needed
                ({{ p.constituent_vote.total }} constituent jurisdictions)
                <template #note>{{ plainState(p.constituent_vote.status) }}</template>
            </ThresholdMeter>

            <DataTable
                v-if="p.constituent_vote && p.constituent_vote.consents.length"
                :columns="[
                    { key: 'jurisdiction', label: 'Constituent' },
                    { key: 'result', label: 'Decision' },
                ]"
                :rows="p.constituent_vote.consents"
                row-key="jurisdiction"
            >
                <template #cell-result="{ row }">
                    <StatusBadge v-if="row.result === 'yes'" tone="success" icon="check">Consented</StatusBadge>
                    <StatusBadge v-else-if="row.result === 'no'" tone="warning">Declined</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">Pending</StatusBadge>
                </template>
            </DataTable>
        </Card>

        <Card as="section">
            <template #title>Join and exit mirror each other</template>
            <p>
                Joining an existing union runs the same entrance-clause process, and departure
                requires the same supermajorities. The entrance process and the exit process
                mirror each other — no one-way doors.
            </p>
        </Card>

        <Card as="section">
            <template #title>The founding act</template>
            <p>
                <FormChip form-id="F-LEG-029" />
                A legislative representative proposes the union in their own chamber; the
                chamber's supermajority opens the ratification vote tracked above.
            </p>

            <template v-if="door.seat">
                <p>
                    Proposing as a member of the <strong>{{ door.seat.jurisdiction_name }}</strong>
                    legislature. Pick the partner place(s):
                </p>
                <div v-if="door.siblings.length" class="stack">
                    <label v-for="s in door.siblings" :key="s.id" class="cluster">
                        <input v-model="chosenSiblings" type="checkbox" :value="s.id" />
                        {{ s.name }}
                    </label>
                    <Btn
                        :disabled="submitting || chosenSiblings.length === 0"
                        @click="propose"
                    >
                        Open the chamber proposal
                    </Btn>
                </div>
                <p v-else>
                    No neighbouring places share a parent with yours — a founding union needs
                    at least two independent partners.
                </p>
            </template>
            <p v-else>
                Proposing requires a current legislative seat. Anyone may watch the process;
                only a seated representative may open it.
            </p>
        </Card>

        <template #about>
            <p>
                Once ratified, the new encompassing jurisdiction is born self-governing, with
                the applicants as its constituents. Boundary questions between neighbours
                live on <a href="/federation">Between governments</a>; removing a middle
                layer is the mirror-image path:
                <a href="/jurisdictions/disintermediation">disintermediation</a>.
            </p>
        </template>
    </PageScaffold>
</template>
