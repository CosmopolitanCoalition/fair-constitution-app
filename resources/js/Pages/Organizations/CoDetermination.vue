<script setup>
/**
 * Organizations/CoDetermination — FE-D7 (PHASE_D_DESIGN_frontend.md §B.10;
 * surface organizations/co-determination) ← the CLK-13 exit surface.
 *
 * The constitutional centerpiece: the CoDetScale explorer (bound to ?org
 * when present, else the generic explorer at the resolved thresholds), the
 * composition-change → joint-chair rule, and THE applies-equally table —
 * one row per LIVE board across all three entity kinds (private orgs, CGCs,
 * departments): ONE boards table, ONE engine. A row with composition_valid
 * = false carries the warning + the worker-track election link — this is
 * where the CLK-13 flip is observed (an org's headcount crossing the
 * minimum flips its row below → scaling · 1 seat, composition_valid=false).
 *
 * EVERY threshold/seat-count here is an ENGINE SNAPSHOT from the controller
 * (boards.worker_seats / .owner_seats / .composition_valid; the CLK-13/14
 * thresholds resolved server-side). The page renders them — the only client
 * arithmetic lives inside CoDetScale's explicitly-labelled explorer.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AmendableSetting from '@/Components/Ui/AmendableSetting.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import CoDetScale from '@/Components/Organizations/CoDetScale.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** Bound org/department (CoDetScale props), or null = generic explorer. */
    focus: { type: Object, default: null },
    /**
     * Every LIVE board row joined to its boardable:
     * [{ entity:{name,href}, kind, workers, owner_side:{seats,label}|null,
     *    worker_seats, state:'below'|'scaling'|'parity', composition_valid,
     *    election:{status, href}|null }]
     */
    appliesTable: { type: Array, default: () => [] },
    /** CLK-13 amendable card: { value, default, basis, bounds_gloss, enacted_by:{act,href}|null }. */
    clk13: { type: Object, required: true },
    /** CLK-14 amendable card: same shape. */
    clk14: { type: Object, required: true },
    /** F-ORG-004 SurfaceMeta form record (registry reference card). */
    jointChairForm: { type: Object, default: null },
});

/* The thresholds backing the generic explorer come from the resolved
   CLK-13/14 values — NEVER the hardcoded 100/2000 (those are the AMENDABLE
   defaults shown for comparison only). */
const thresholds = computed(() => ({ min: props.clk13.value, parity: props.clk14.value }));

/* When no org is bound, the explorer renders honestly at zero live state:
   a representative owner-side board to explore the published formula, no
   live entity numbers asserted. */
const explorerScale = computed(() => ({
    workers: 0,
    ownerSeats: 9,
    workerSeats: 0,
    thresholds: thresholds.value,
    nextStepAt: thresholds.value.min,
}));

const appliesColumns = [
    { key: 'entity', label: 'Entity' },
    { key: 'kind', label: 'Kind' },
    { key: 'workers', label: 'Workers', mono: true, align: 'right' },
    { key: 'owner_side', label: 'Owner side' },
    { key: 'worker_seats', label: 'Worker seats', mono: true, align: 'right' },
    { key: 'state', label: 'State' },
];

const STATE_BADGE = {
    below: { tone: 'neutral', icon: 'minus', text: 'below threshold' },
    scaling: { tone: 'info', icon: 'users', text: 'scaling' },
    parity: { tone: 'success', icon: 'users', text: 'parity' },
};
function stateBadge(row) {
    return STATE_BADGE[row.state] ?? STATE_BADGE.below;
}
</script>

<template>
    <PageScaffold :surface="surface" title="Co-determination scaling">
        <template #intro>
            The same Art. III §6 scale binds every employer with a board — private enterprises,
            Common Good Corporations, and executive departments alike. The first worker-elected
            seat arrives at the CLK-13 minimum; worker seats scale linearly to parity with the
            owner side at the CLK-14 threshold. Every number below is the engine's, read from one
            shared boards table.
        </template>

        <!-- ============================== the CoDetScale explorer ======== -->
        <Card as="section" :title="focus ? `On the scale — ${focus.entity.name}` : 'The scale — explorer'">
            <p v-if="focus" class="citation" style="margin-block-end: var(--space-3)">
                {{ focus.entity.kind }} ·
                <Link :href="focus.entity.href">open the entity →</Link>
            </p>
            <p v-else class="gloss" style="margin-block-end: var(--space-3)">
                No entity bound — drag the slider to explore the published formula at this
                instance's resolved thresholds. Append <span class="mono">?org=&lt;id&gt;</span>
                (or follow a row below) to bind the meter to a live organization's own numbers.
            </p>

            <CoDetScale
                v-if="focus"
                v-bind="focus.scale"
                :entity-label="focus.entity.name"
                interactive
            />
            <CoDetScale v-else v-bind="explorerScale" interactive />
        </Card>

        <!-- ===================== composition change → joint chair ======== -->
        <Card as="section" title="Composition change re-triggers the joint chair election">
            <p style="margin-block-end: var(--space-2)">
                <HardenedChip>chair elected jointly by the entire board · Art. III §6</HardenedChip>
            </p>
            <p style="margin: 0">
                Any composition change — a seat added by the scale, a vacancy, a transfer —
                triggers a fresh joint chair election by the entire board. The board is valid
                only while its composition matches the scale; until the worker-track election and
                the joint chair election complete, the board cannot act.
            </p>

            <div v-if="jointChairForm" class="card card--inset" style="margin-block-start: var(--space-3)">
                <p style="margin-block-end: var(--space-1)">
                    <strong style="color: var(--gov-fg)">{{ jointChairForm.name }}</strong>
                    {{ ' ' }}
                    <FormChip :form-id="jointChairForm.id" :alias="jointChairForm.alias" />
                </p>
                <p class="citation" style="margin-block-end: var(--space-2)">
                    <template v-if="jointChairForm.availableTo?.length">available to {{ jointChairForm.availableTo.join(', ') }}</template>
                    <template v-if="jointChairForm.availableTo?.length && jointChairForm.citation"> · </template>
                    <template v-if="jointChairForm.citation">{{ jointChairForm.citation }}</template>
                </p>
                <p class="cc-small" style="margin: 0">
                    The worker track and the joint chair election run on the elections machinery —
                    administered from a board's
                    <Link href="/organizations">board-elections page</Link>.
                </p>
            </div>
        </Card>

        <!-- ============================ the applies-equally table ======== -->
        <Card as="section" title="The scale applies equally — every board, one engine">
            <p class="citation" style="margin-block-end: var(--space-3)">
                one row per live board across private enterprises, Common Good Corporations, and
                executive departments · the owner side runs shareholder-elected or appointed
                governors, but the worker-side scale is identical · Art. III §6
            </p>

            <template v-if="appliesTable.length">
                <DataTable
                    :columns="appliesColumns"
                    :rows="appliesTable"
                    caption="Co-determination state of every live board"
                >
                    <template #cell-entity="{ row }">
                        <Link v-if="row.entity.href" :href="row.entity.href">
                            <strong>{{ row.entity.name }}</strong>
                        </Link>
                        <strong v-else>{{ row.entity.name }}</strong>
                        <StatusBadge
                            v-if="!row.composition_valid"
                            tone="warning"
                            icon="alert-triangle"
                            style="margin-inline-start: var(--space-2)"
                        >composition invalid</StatusBadge>
                    </template>
                    <template #cell-workers="{ row }">
                        <span class="mono">{{ row.workers.toLocaleString() }}</span>
                    </template>
                    <template #cell-owner_side="{ row }">
                        <template v-if="row.owner_side">
                            {{ row.owner_side.seats }} · {{ row.owner_side.label }}
                        </template>
                        <span v-else class="gloss">—</span>
                    </template>
                    <template #cell-worker_seats="{ row }">
                        <span class="mono">{{ row.worker_seats }}</span>
                    </template>
                    <template #cell-state="{ row }">
                        <StatusBadge :tone="stateBadge(row).tone" :icon="stateBadge(row).icon">
                            {{ stateBadge(row).text }}
                        </StatusBadge>
                        <span
                            v-if="!row.composition_valid"
                            class="citation"
                            style="display: block; margin-block-start: var(--space-1)"
                        >
                            worker-track election
                            <template v-if="row.election">
                                <Link :href="row.election.href">open ({{ row.election.status.replaceAll('_', ' ') }}) →</Link>
                            </template>
                            <template v-else>required · WF-ORG-04 → WF-ORG-05</template>
                        </span>
                    </template>
                </DataTable>
            </template>

            <Banner v-else tone="info" role="status" title="No board has reached the first-seat threshold yet.">
                The scale binds from the first qualifying organization — the first time an
                employer's active worker headcount crosses the CLK-13 minimum
                ({{ clk13.value.toLocaleString() }}), its row appears here flipping
                <span class="mono">below → scaling · 1 seat</span> with composition_valid=false and
                a worker-track election. The explorer above is fully functional in the meantime.
            </Banner>
        </Card>

        <!-- ===================== CLK-13 / CLK-14 amendable cards ========= -->
        <div class="grid-2">
            <Card as="section" title="CLK-13 — first worker seat">
                <p style="margin-block-end: var(--space-2)">
                    <AmendableSetting
                        :value="clk13.value.toLocaleString()"
                        setting-key="worker_rep_min_employees"
                        :default-value="clk13.default.toLocaleString()"
                        :citation="clk13.basis"
                    />
                </p>
                <p class="cc-small" style="margin: 0">
                    The active worker headcount at which the first worker-elected seat is required.
                    Amendable within bounds — it {{ clk13.bounds_gloss }}.
                </p>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    <template v-if="clk13.enacted_by">
                        enacted by {{ clk13.enacted_by.act }} ·
                        <Link :href="clk13.enacted_by.href">record →</Link>
                    </template>
                    <template v-else>Template default · founding value</template>
                </p>
            </Card>

            <Card as="section" title="CLK-14 — worker / owner parity">
                <p style="margin-block-end: var(--space-2)">
                    <AmendableSetting
                        :value="clk14.value.toLocaleString()"
                        setting-key="worker_rep_parity_employees"
                        :default-value="clk14.default.toLocaleString()"
                        :citation="clk14.basis"
                    />
                </p>
                <p class="cc-small" style="margin: 0">
                    The headcount at which worker seats reach parity with the owner side — the
                    ceiling. Amendable within bounds — it {{ clk14.bounds_gloss }}.
                </p>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    <template v-if="clk14.enacted_by">
                        enacted by {{ clk14.enacted_by.act }} ·
                        <Link :href="clk14.enacted_by.href">record →</Link>
                    </template>
                    <template v-else>Template default · founding value</template>
                </p>
            </Card>
        </div>

        <template #about>
            <p>
                One boards table, one co-determination engine. The worker-side seat count is a
                stored snapshot written only by the protected co-determination service — this
                surface renders it, never recomputes it. The owner side differs by entity (a
                stock company's shareholders elect their seats; a Common Good Corporation's and a
                department's governors are appointed), but the worker-side scale is byte-for-byte
                identical, which is exactly what the applies-equally table proves.
            </p>
        </template>
    </PageScaffold>
</template>
