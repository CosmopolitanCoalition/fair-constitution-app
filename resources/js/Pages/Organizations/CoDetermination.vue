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
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AmendableSetting from '@/Components/Ui/AmendableSetting.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import CoDetScale from '@/Components/Organizations/CoDetScale.vue';
import OrganizationNav from '@/Components/Organizations/OrganizationNav.vue';
import ReferenceText from '@/Components/Ui/ReferenceText.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** Bound org/department (CoDetScale props), or null = generic explorer. */
    focus: { type: Object, default: null },
    organization: { type: Object, default: null },
    pagination: { type: Object, default: null },
    /**
     * The focused board, or one bounded page of the live register:
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
    <PageScaffold :surface="surface" :title="focus ? `Worker representation — ${focus.entity.name}` : 'Worker representation'">
        <template #intro>
            When a company employs {{ clk13.value.toLocaleString() }} or more people, its workers
            start electing seats on its board. The first worker seat arrives at that threshold,
            and worker seats grow evenly until they match the owners' seats at
            {{ clk14.value.toLocaleString() }} workers. The formal name for this is
            <em>co-determination</em>. The same scale applies to private companies, Common Good
            Corporations, and government departments.
        </template>

        <OrganizationNav v-if="organization" :organization="organization" current="representation" />
        <p v-else-if="focus"><Link :href="focus.entity.href">Back to {{ focus.entity.name }}</Link></p>

        <!-- ============================== the CoDetScale explorer ======== -->
        <Card as="section" :title="focus?.scale ? `On the scale — ${focus.entity.name}` : 'Explore the representation scale'">
            <p v-if="focus && !focus.scale" class="gloss" style="margin-block-end: var(--space-3)">
                {{ focus.entity.name }} has no active board recorded. The explorer below illustrates
                the applicable rules; it does not show a seated board.
            </p>
            <p v-else-if="!focus" class="gloss" style="margin-block-end: var(--space-3)">
                Explore how worker representation changes with headcount, or choose a board
                from the register below to see its recorded numbers.
            </p>

            <CoDetScale
                v-if="focus?.scale"
                v-bind="focus.scale"
                :entity-label="focus.entity.name"
                interactive
            />
            <CoDetScale v-else v-bind="explorerScale" interactive />
        </Card>

        <!-- ===================== composition change → joint chair ======== -->
        <Card as="section" title="Choosing a chair after board changes">
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
                    <FormChip :form-id="jointChairForm.id" :name="jointChairForm.name" :alias="jointChairForm.alias" />
                </p>
                <p class="citation" style="margin-block-end: var(--space-2)">
                    <ReferenceText v-if="jointChairForm.availableTo?.length">Available to {{ jointChairForm.availableTo.join(', ') }}</ReferenceText>
                    <template v-if="jointChairForm.availableTo?.length && jointChairForm.citation"> · </template>
                    <template v-if="jointChairForm.citation">{{ jointChairForm.citation }}</template>
                </p>
                <p class="cc-small" style="margin: 0">
                    Manage worker elections and elect the chair in the
                    <Link v-if="organization" :href="`/organizations/${organization.id}/board-elections`">Board &amp; elections workspace</Link>
                    <Link v-else :href="focus?.entity.href ?? '/organizations'">organization or department</Link>.
                </p>
            </div>
        </Card>

        <!-- ============================ the applies-equally table ======== -->
        <Card as="section" :title="focus ? 'This board’s representation' : 'Browse recorded boards'">
            <p class="citation" style="margin-block-end: var(--space-3)">
                Worker representation follows the same scale in private enterprises, Common Good
                Corporations, and executive departments. The numbers below are recorded board values.
            </p>
            <p v-if="focus"><Link href="/organizations/co-determination">Compare with other boards</Link></p>

            <template v-if="appliesTable.length">
                <DataTable
                    :columns="appliesColumns"
                    :rows="appliesTable"
                    :caption="focus ? `Worker representation at ${focus.entity.name}` : 'Worker representation — current page of boards'"
                >
                    <template #cell-entity="{ row }">
                        <Link v-if="row.entity.representation_href" :href="row.entity.representation_href">
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
                            Worker election
                            <template v-if="row.election">
                                <Link :href="row.election.href">view record ({{ row.election.status.replaceAll('_', ' ') }}) →</Link>
                            </template>
                            <template v-else>required; no election is recorded yet.</template>
                        </span>
                    </template>
                </DataTable>
            </template>

            <Banner v-else tone="info" role="status" :title="focus ? 'No active board is recorded for this entity.' : 'No active boards on this page.'">
                You can explore the representation scale above or
                <Link href="/organizations">choose an organization</Link>.
            </Banner>

            <nav v-if="pagination?.previous || pagination?.next" aria-label="Board register pages" class="board-pagination">
                <Link v-if="pagination.previous" :href="pagination.previous" rel="prev">Previous boards</Link>
                <Link v-if="pagination.next" :href="pagination.next" rel="next">Next boards</Link>
            </nav>
        </Card>

        <!-- ===================== CLK-13 / CLK-14 amendable cards ========= -->
        <div class="grid-2">
            <Card as="section" title="First worker seat">
                <p style="margin-block-end: var(--space-2)">
                    <AmendableSetting
                        :value="clk13.value.toLocaleString()"
                        setting-key="worker_rep_min_employees"
                        label="Workers needed for the first board seat"
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

            <Card as="section" title="Equal worker and owner representation">
                <p style="margin-block-end: var(--space-2)">
                    <AmendableSetting
                        :value="clk14.value.toLocaleString()"
                        setting-key="worker_rep_parity_employees"
                        label="Workers needed for equal representation"
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
                Workers elect representatives to share in their organization’s decisions.
                This page shows the required worker seats and recorded board composition.
                Use the explorer to see how representation changes as the workforce grows.
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.board-pagination { display: flex; flex-wrap: wrap; gap: var(--space-3); margin-block-start: var(--space-4); }
.board-pagination a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .5rem .75rem; border: 1px solid var(--gov-border); border-radius: var(--radius-md, .5rem); }
.board-pagination a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 2px; }
</style>
