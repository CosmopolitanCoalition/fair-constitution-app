<script setup>
/**
 * Jurisdictions/Disintermediation — "Removing a middle layer" (design
 * contract: mockups/v3/jurisdictions/disintermediation.html).
 *
 * Art. V §8: an intermediary jurisdiction dissolves only when EVERY
 * constituent agrees (unanimity — one holdout stops it) AND the encompassing
 * jurisdiction consents. The dissolved layer's acts do not vanish — each
 * former CONSTITUENT inherits its own copy, full history preserved (the
 * ruled direction, 2026-07-28). The F-LEG-030 door files a real chamber
 * proposal; the parties are DERIVED from the proposing chamber's own place
 * in the hierarchy, never picked.
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
    door: { type: Object, default: () => ({ seat: null, proposable: false }) },
});

const submitting = ref(false);

function propose() {
    submitting.value = true;
    router.post('/jurisdictions/disintermediation/propose', {}, {
        preserveScroll: true,
        onFinish: () => { submitting.value = false; },
    });
}

const statusTone = (s) =>
    s === 'merged' ? 'success' : s === 'failed' || s === 'expired' ? 'warning' : 'info';
</script>

<template>
    <PageScaffold :surface="surface" title="Removing a middle layer">
        <template #intro>
            A middle layer of government dissolves — and its constituents answer directly to
            the level above — only when every constituent agrees and the encompassing
            jurisdiction consents. The dissolved layer's laws do not vanish: they fold into
            each remaining place's own law. Everyone inside agrees, the level above agrees,
            and only then do the laws fold down.
        </template>

        <Banner v-if="processes.length === 0" tone="info" role="status">
            <strong>No live case.</strong> No middle layer is currently being dissolved. The
            process below is real and waiting — a chamber proposal
            (<span class="citation">F-LEG-030</span>) opens it.
        </Banner>

        <Card v-for="p in processes" :key="p.id" as="section">
            <template #title>Dissolving {{ p.intermediary }} — constituents fold to {{ p.encompassing }}'s level</template>

            <p>
                <StatusBadge :tone="statusTone(p.status)">{{ plainState(p.status) }}</StatusBadge>
                <span class="citation" data-no-i18n>opened {{ new Date(p.opened_at).toLocaleDateString() }}</span>
            </p>

            <h4>Consent meters</h4>
            <p><strong>Unanimity of constituents — not a supermajority.</strong> One holdout stops the dissolution.</p>
            <ThresholdMeter
                v-if="p.unanimity"
                :value="p.unanimity.yes"
                :max="p.unanimity.total"
                :threshold="p.unanimity.required"
                label="Constituent unanimity"
            >
                Constituent unanimity — {{ p.unanimity.yes }} of {{ p.unanimity.total }}
                (all {{ p.unanimity.required }} required)
                <template #note>unanimity, not a supermajority</template>
            </ThresholdMeter>
            <ThresholdMeter
                :value="p.encompassing_consent === true ? 1 : 0"
                :max="1"
                :threshold="1"
                label="Encompassing consent"
            >
                Encompassing consent — {{ p.encompassing }}
                <template #note>
                    {{ p.encompassing_consent === true ? 'consented' : p.encompassing_consent === false ? 'declined' : 'the encompassing jurisdiction must agree' }}
                </template>
            </ThresholdMeter>

            <DataTable
                v-if="p.unanimity && p.unanimity.consents.length"
                :columns="[
                    { key: 'jurisdiction', label: 'Constituent' },
                    { key: 'result', label: 'Status' },
                ]"
                :rows="p.unanimity.consents"
                row-key="jurisdiction"
            >
                <template #cell-result="{ row }">
                    <StatusBadge v-if="row.result === 'yes'" tone="success" icon="check">Dissolution act passed</StatusBadge>
                    <StatusBadge v-else-if="row.result === 'no'" tone="warning">Declined — dissolution stopped</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">Pending</StatusBadge>
                </template>
            </DataTable>

            <template v-if="p.folded_acts.length">
                <h4>The folded law</h4>
                <p>
                    Each former constituent inherited its own copy of every act, full version
                    history preserved — so each can amend or repeal independently from now on.
                </p>
                <DataTable
                    :columns="[
                        { key: 'law', label: 'Act' },
                        { key: 'inherited_by', label: 'Inherited by' },
                        { key: 'decision', label: 'Resolution' },
                    ]"
                    :rows="p.folded_acts"
                >
                    <template #cell-decision="{ row }">
                        <StatusBadge tone="success" icon="check">{{ plainState(row.decision) }}</StatusBadge>
                    </template>
                </DataTable>
                <p class="citation">Acts are incorporated into the former constituents, then published.</p>
            </template>
        </Card>

        <Card as="section">
            <template #title>The chain after dissolution</template>
            <p>
                Each former constituent answers directly to the level above, and every
                resident's chain of places re-resolves automatically — you belong to every
                remaining level at once. The chain updates the moment the dissolution takes
                effect; nobody re-registers anything.
            </p>
        </Card>

        <Card as="section">
            <template #title>The dissolution vote</template>
            <p>
                <FormChip form-id="F-LEG-030" />
                The vote is held in each constituent legislature and in the encompassing
                legislature. A representative proposes it in their own chamber; the parties
                follow from the chamber's place in the hierarchy.
            </p>

            <template v-if="door.seat">
                <p v-if="door.proposable">
                    Proposing as a member of the <strong>{{ door.seat.jurisdiction_name }}</strong>
                    legislature — the middle layer to dissolve is
                    <strong>{{ door.seat.jurisdiction_name }}</strong> itself
                    ({{ door.seat.child_count }} constituent places fold to its parent's level).
                </p>
                <Btn v-if="door.proposable" :disabled="submitting" @click="propose">
                    Open the chamber proposal
                </Btn>
                <p v-else>
                    {{ door.seat.jurisdiction_name }} is not a middle layer — dissolving needs
                    both a level above and constituent places below.
                </p>
            </template>
            <p v-else>
                Proposing requires a current legislative seat. Anyone may watch the process;
                only a seated representative may open it.
            </p>
        </Card>

        <template #about>
            <p>
                Every constituent and the encompassing jurisdiction vote; the intermediary's
                acts are inherited by each former constituent; the chain of places updates.
                The mirror-image growth path is
                <a href="/jurisdictions/union-formation">union formation</a>. Once dissolved,
                the middle layer is gone; its former constituents remain self-governing
                throughout.
            </p>
        </template>
    </PageScaffold>
</template>
