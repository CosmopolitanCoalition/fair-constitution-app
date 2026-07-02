<script setup>
/**
 * Executive/Departments — FE-D3 (PHASE_D_DESIGN_frontend.md §B.2; surface
 * executive/departments).
 *
 * The department registry (DepartmentCards with the co-determination cell)
 * · the create-department reference (F-LEG-016 — an ordinary-majority BILL,
 * never a side-door POST; the CTA is the pre-targeted bill deep-link) · the
 * BoG pipeline (Ui/Stepper: Nomination F-EXE-001 → Consent F-LEG-020 →
 * Seated R-18) with each consented row's chamber VoteTally (MAJORITY class
 * — peg-quorum ordinary majority) · the removal-by-majority gloss · the
 * R-30 civil-officer card.
 *
 * CONSTITUTIONAL POSTURE — pure renderer: every consent number is an engine
 * snapshot off the chamber_votes row (via the controller's
 * ChamberVotePresenter); nothing here recomputes a threshold or the scale.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import DepartmentCard from '@/Components/Executive/DepartmentCard.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.1 header block. */
    executive: { type: Object, required: true },
    departments: { type: Array, default: () => [] },
    /** ESM-17 legend. */
    machine: { type: Array, default: () => [] },
    pipeline: { type: Array, default: () => [] },
    civilOfficers: { type: Array, default: () => [] },
    createDeepLink: { type: String, required: true },
    can: { type: Object, default: () => ({ nominate: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/** Art. II §9's named set — the honest starting menu for the empty state. */
const CONSTITUTIONAL_KINDS = ['Chief Executive', 'Treasury', 'Defense', 'State', 'Justice'];

const civilColumns = [
    { key: 'name', label: 'Officer' },
    { key: 'department', label: 'Department' },
    { key: 'role_label', label: 'Role' },
    { key: 'term', label: 'Term ends', mono: true },
];

function consentSummary(consent) {
    const tally = consent?.vote;
    if (!tally) return null;
    const yes = tally.tallies?.yes ?? 0;
    return `${yes} of ${tally.serving} serving`;
}
</script>

<template>
    <PageScaffold :surface="surface" :title="`Departments — ${executive.jurisdiction.name}`">
        <template #intro>
            Departments are created by legislative act, chartered for a specific function, and
            run by an appointed Board of Governors under executive oversight. Workers get board
            seats here exactly as they do in private organizations (co-determination): the first
            worker-elected seat arrives at 100 workers.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ============================================ header links ===== -->
        <div class="cluster">
            <Link :href="`/executives/${executive.id}`">Executive home →</Link>
            <Link :href="`/executives/${executive.id}/actions`">Executive actions →</Link>
        </div>

        <!-- ============================================ the registry ===== -->
        <Card as="section" title="Department registry">
            <template v-if="departments.length">
                <div class="grid-2">
                    <DepartmentCard v-for="dep in departments" :key="dep.id" :department="dep" />
                </div>
            </template>
            <Banner v-else tone="info" role="status" title="No departments yet.">
                A department is created by an ordinary-majority legislative act (F-LEG-016). The
                constitution names five — {{ CONSTITUTIONAL_KINDS.join(', ') }} — and others may be
                created by act.
            </Banner>
        </Card>

        <!-- ====================================== create-department ====== -->
        <Card as="section" title="Create a department">
            <p>
                <FormChip form-id="F-LEG-016" name="Department Creation Act" />
            </p>
            <p class="cc-small">
                Department creation is a legislative act at ordinary majority — it names the
                department, its kind (Chief Executive, Treasury, Defense, State, Justice, or other),
                the overseeing executive, the charter, and the reporting interval. Institution-creating
                forms ride the bill flow; there is no side-door POST.
            </p>
            <div class="cluster" style="margin-block-start: var(--space-2)">
                <Btn variant="primary" :as="Link" :href="createDeepLink">Introduce a creation bill →</Btn>
                <span class="citation">Art. II §9 · ordinary-majority act · WF-EXE-04</span>
            </div>
        </Card>

        <!-- ============================================= BoG pipeline ==== -->
        <Card as="section" title="Board of Governors pipeline">
            <p class="citation">
                Nomination dossier · F-EXE-001 → Consent vote · F-LEG-020 → Seated · R-18 —
                live appointments across all departments.
            </p>
            <template v-if="pipeline.length">
                <div
                    v-for="(row, i) in pipeline"
                    :key="i"
                    class="card card--inset"
                    style="margin-block-end: var(--space-3)"
                >
                    <p style="margin-block-end: var(--space-1)">
                        <strong style="color: var(--gov-fg)">{{ row.nominee.name }}</strong>
                        — <Link :href="row.department.href">{{ row.department.name }}</Link>
                    </p>
                    <Stepper :steps="row.stepper" />
                    <p v-if="row.consent" class="cc-small" style="margin-block-start: var(--space-2)">
                        <StatusBadge
                            v-if="row.consent.scheduled"
                            tone="info"
                            icon="clock"
                        >consent open · {{ consentSummary(row.consent) }}</StatusBadge>
                        <StatusBadge
                            v-else-if="row.consent.outcome === 'adopted'"
                            tone="success"
                            icon="check"
                        >consented · {{ consentSummary(row.consent) }}</StatusBadge>
                        <StatusBadge
                            v-else-if="row.consent.outcome"
                            tone="danger"
                            icon="x"
                        >consent failed · {{ consentSummary(row.consent) }} — renomination open (WF-EXE-05)</StatusBadge>
                    </p>
                    <!-- The chamber consent VoteTally, rendered on the executive surface. -->
                    <details v-if="row.consent" style="margin-block-start: var(--space-2)">
                        <summary class="citation" style="cursor: pointer">Consent vote (majority of all serving) →</summary>
                        <div style="margin-block-start: var(--space-2)">
                            <VoteTally v-bind="row.consent.vote" basis="Art. III §4 · peg-quorum majority" />
                            <p v-if="row.consent.chamber_href" class="citation" style="margin-block-start: var(--space-1)">
                                <Link :href="row.consent.chamber_href">Cast / view in the chamber →</Link>
                            </p>
                        </div>
                    </details>
                </div>
            </template>
            <p v-else class="gloss">
                No nominations in flight — open one from a department's detail page (F-EXE-001).
            </p>
        </Card>

        <!-- ============================================= removal gloss === -->
        <Card as="section" title="Removing a governor">
            <p>
                Governor removal is an <strong>ordinary majority of all serving</strong> — hiring and
                firing. Supermajority applies only where the constitution states it; it does not apply
                here.
            </p>
            <p class="citation">owner ruling #14 · Art. III §4 · file from the department detail page (F-EXE-003)</p>
        </Card>

        <!-- ========================================== civil officers ===== -->
        <Card as="section" title="Civil officers (R-30)">
            <DataTable
                v-if="civilOfficers.length"
                :columns="civilColumns"
                :rows="civilOfficers"
                row-key="name"
                caption="Department civil staff — 10-year civil appointments (CLK-09)"
            >
                <template #cell-term="{ row }">
                    <span class="mono" data-no-i18n>{{ row.term.ends_on ?? '—' }}</span>
                    <span class="citation" style="display: block" data-no-i18n>{{ row.clock }}</span>
                </template>
            </DataTable>
            <p v-else class="gloss">
                No civil officers seated — duties run through the chartered departments per their
                charters · Art. II §9 · CLK-09.
            </p>
        </Card>

        <!-- =========================================== ESM-17 legend ===== -->
        <Card v-if="machine.length" as="section" title="Department lifecycle (ESM-17)">
            <StateStrip :states="machine" />
            <p class="citation" style="margin-block-start: var(--space-2)">
                An open governor-removal request splices a live <strong>removal_requested</strong>
                state into the machine without disturbing the stored status · Art. III §4.
            </p>
        </Card>

        <template #about>
            <p>
                Departments hire through the shared worker table — Art. III §6 applies identically to
                a department board and a private company. The governors' 10-year terms (CLK-09) run
                independently of the worker seats' legislative-term lockstep (CLK-10).
                <HardenedChip>co-determination · one engine · Art. III §6</HardenedChip>
            </p>
        </template>
    </PageScaffold>
</template>
