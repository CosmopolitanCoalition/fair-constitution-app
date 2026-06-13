<script setup>
/**
 * Judiciary/CaseDocket — FE-E3 (PHASE_E_DESIGN_frontend.md §B.2; surface
 * judiciary/case-docket).
 *
 * The public docket for ONE court: open-case Stat tiles by kind, a kind +
 * search FilterBar over the case DataTable (each case links to case-detail,
 * or the constitutional-challenge tracker for an Art. IV §5 case), the
 * F-IND-017 filing composer (claimed scale + claimed severity — the court
 * reclassifies severity at acceptance, so panel size follows the COURT, not
 * the filer), and the reference cards for the other entry points (F-IND-016,
 * F-ADV-001) and what the court does next (F-JDG-001 conflict screening).
 *
 * PUBLIC READ (Art. II §2). Filing gates by derived role (R-03 / R-21) via
 * `can.fileCase` + the engine 422 (errors.constitution) — never a page 403.
 * Every panel summary / severity is a server row snapshot; this page renders
 * rows and opens the filing door, it computes nothing.
 */
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import ChipToggle from '@/Components/Ui/ChipToggle.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** { id, name, jurisdiction, home_href, challenges_href }. */
    judiciary: { type: Object, required: true },
    /** { open, by_kind:{ constitutional, civil, criminal, administrative } }. */
    stats: { type: Object, default: () => ({ open: 0, by_kind: {} }) },
    /** Docket rows (server-shaped) — see DocketController::caseRows. */
    cases: { type: Array, default: () => [] },
    /** Case ESM legend (config/cga/state_machines.php 'case'). */
    machine: { type: Array, default: () => [] },
    /** { kinds:[…4] } — the FilterBar chip labels. */
    filters: { type: Object, default: () => ({ kinds: [] }) },
    /** { kinds:[{value,label}], scales:[{value,label}], severities:[{value,label}] }. */
    filingForm: { type: Object, default: () => ({ kinds: [], scales: [], severities: [] }) },
    isAssociated: { type: Boolean, default: false },
    can: { type: Object, default: () => ({ fileCase: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
/* The engine 422: a ConstitutionalViolation surfaces as errors.constitution
   carrying "{message} ({citation})" — the verbatim rejection. */
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const surfaceForm = (id) => props.surface.forms.find((f) => f.id === id) ?? null;

/* ------------------------------------------------------- filters ------- */
const activeKinds = ref(new Set());
const search = ref('');

function toggleKind(kind, pressed) {
    if (pressed) activeKinds.value.add(kind);
    else activeKinds.value.delete(kind);
    /* Set mutation does not retrigger computed; reassign to force reactivity. */
    activeKinds.value = new Set(activeKinds.value);
}
function clearFilters() {
    activeKinds.value = new Set();
    search.value = '';
}

const shownCases = computed(() =>
    props.cases.filter((c) => {
        if (activeKinds.value.size && !activeKinds.value.has(c.kind)) return false;
        if (search.value) {
            const q = search.value.toLowerCase();
            if (!`${c.title} ${c.docket_no}`.toLowerCase().includes(q)) return false;
        }
        return true;
    }),
);

const docketColumns = [
    { key: 'title', label: 'Case' },
    { key: 'kind', label: 'Kind' },
    { key: 'court', label: 'Court' },
    { key: 'panel', label: 'Panel' },
    { key: 'severity', label: 'Severity' },
    { key: 'state', label: 'State' },
];

/* The mockup STATE_BADGE map — case-docket.html lines 173-179, by ESM state. */
const STATE_TONE = {
    filed: 'neutral',
    accepted: 'info',
    paneled: 'info',
    jury_empaneled: 'info',
    heard: 'info',
    deliberation: 'neutral',
    decided: 'warning',
    sentenced: 'warning',
    closed: 'success',
    dismissed: 'neutral',
    appealed: 'warning',
};
const STATE_ICON = {
    filed: 'file-text',
    accepted: 'check',
    paneled: 'users',
    jury_empaneled: 'users',
    heard: 'scale',
    deliberation: 'lock',
    decided: 'scale',
    sentenced: 'scale',
    closed: 'check',
    dismissed: 'x',
    appealed: 'scale',
};
const stateLabel = (s) => (s ?? '').replaceAll('_', ' ');

/* -------------------------------------------------------- filing ------- */
const filing = useForm({
    kind: props.filingForm.kinds?.[0]?.value ?? 'civil',
    jurisdiction_id: props.filingForm.scales?.[0]?.value ?? '',
    claimed_severity: props.filingForm.severities?.[0]?.value ?? 'minor',
    title: '',
    statement_of_claim: '',
});

function submitFiling() {
    filing.post(`/judiciaries/${props.judiciary.id}/cases`, {
        preserveScroll: true,
        onSuccess: () => filing.reset('title', 'statement_of_claim'),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="`Case docket — ${judiciary.name}`">
        <template #intro>
            Every case filed in this court, from local civil disputes to full-court constitutional
            questions. Anyone jurisdictionally associated can file; the court assigns panels with
            conflict screening, and reclassifies severity at acceptance — panel size follows the
            court's classification, not the filer's claim.
        </template>

        <!-- engine 422: the rejection citation, verbatim -->
        <Banner v-if="constitutionError" tone="emergency" role="alert" title="The filing was not accepted.">
            {{ constitutionError }}
        </Banner>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- ============================================ header links ===== -->
        <div class="cluster">
            <Link :href="judiciary.home_href">Judiciary home →</Link>
            <Link :href="judiciary.challenges_href">Constitutional challenges →</Link>
        </div>

        <!-- =============================================== stat tiles ===== -->
        <Card as="section" title="Open cases">
            <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                <Stat :value="stats.open" label="open" accent />
                <Stat :value="stats.by_kind.constitutional ?? 0" label="constitutional" />
                <Stat :value="stats.by_kind.civil ?? 0" label="civil" />
                <Stat :value="stats.by_kind.criminal ?? 0" label="criminal" />
                <Stat :value="stats.by_kind.administrative ?? 0" label="administrative" />
            </div>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Panels of at least 3, odd, scaled to severity · full court for major constitutional
                questions · Art. IV §4 · CLK-16
            </p>
        </Card>

        <!-- ================================================ the docket ==== -->
        <Card as="section" title="Cases on this docket">
            <FilterBar label="Filter cases">
                <span class="eyebrow">Kind</span>
                <ChipToggle
                    v-for="kind in filters.kinds"
                    :key="kind"
                    :pressed="activeKinds.has(kind)"
                    @update:pressed="(v) => toggleKind(kind, v)"
                >{{ kind }}</ChipToggle>
                <label class="field">
                    <span class="visually-hidden">Search cases</span>
                    <input
                        v-model="search"
                        type="search"
                        class="field-input"
                        placeholder="Search cases"
                        style="inline-size: 11rem; padding-block: var(--space-1)"
                    />
                </label>
                <button type="button" class="btn btn--ghost btn--sm" @click="clearFilters">Clear filters</button>
            </FilterBar>

            <p class="citation" style="margin-block: var(--space-2)">
                {{ shownCases.length }} of {{ cases.length }} cases shown
            </p>

            <DataTable
                v-if="shownCases.length"
                :columns="docketColumns"
                :rows="shownCases"
                row-key="id"
                caption="Open cases with kind, court, panel, severity, and state"
            >
                <template #cell-title="{ row }">
                    <Link :href="row.href">{{ row.title }}</Link>
                    <span class="citation" style="display: block" data-no-i18n>
                        {{ row.docket_no }}<template v-if="row.filed_via"> · filed via {{ row.filed_via }}</template>
                    </span>
                    <span v-if="row.double_jeopardy_note" class="citation" style="display: block">
                        {{ row.double_jeopardy_note }}
                    </span>
                </template>
                <template #cell-court="{ row }">{{ row.court.name }}</template>
                <template #cell-panel="{ row }">{{ row.panel.summary }}</template>
                <template #cell-state="{ row }">
                    <StatusBadge :tone="STATE_TONE[row.state] ?? 'neutral'" :icon="STATE_ICON[row.state] ?? 'info'">
                        {{ stateLabel(row.state) }}
                    </StatusBadge>
                </template>
            </DataTable>
            <Banner v-else tone="info" role="status">
                <template v-if="cases.length">No cases match the current filters.</template>
                <template v-else>
                    No cases on this docket — anyone jurisdictionally associated can file (Art. I).
                </template>
            </Banner>
        </Card>

        <!-- ================================================= file a case == -->
        <template v-if="surfaceForm('F-IND-017') && isAssociated">
            <FormCard
                :form="surfaceForm('F-IND-017')"
                :inertia-form="filing"
                :disabled="!can.fileCase"
                submit-label="Submit filing"
                @submit="submitFiling"
            >
                <p style="margin-block-end: var(--space-3)">
                    Filing states a <strong>claimed scale</strong> (which jurisdiction's law is at
                    issue, so the right court level hears it) and a <strong>claimed severity</strong>
                    (which the court reclassifies on acceptance). You can file yourself or through a
                    registered advocate.
                </p>

                <div class="grid-2">
                    <Field label="Case kind" :error="filing.errors.kind">
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="filing.kind" class="select" :aria-describedby="describedBy">
                                <option v-for="k in filingForm.kinds" :key="k.value" :value="k.value">{{ k.label }}</option>
                            </select>
                        </template>
                    </Field>

                    <Field
                        label="Claimed scale"
                        hint="The jurisdiction whose law the case arises under."
                        :error="filing.errors.jurisdiction_id"
                    >
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="filing.jurisdiction_id" class="select" :aria-describedby="describedBy">
                                <option v-for="s in filingForm.scales" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </template>
                    </Field>

                    <Field
                        label="Claimed severity"
                        hint="The court reclassifies severity at acceptance — panel size follows the court's classification, not yours."
                        :error="filing.errors.claimed_severity"
                    >
                        <template #control="{ id, describedBy }">
                            <select :id="id" v-model="filing.claimed_severity" class="select" :aria-describedby="describedBy">
                                <option v-for="s in filingForm.severities" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </template>
                    </Field>

                    <Field label="Case title" :error="filing.errors.title">
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="filing.title"
                                class="field-input"
                                placeholder="e.g. Okafor v. Crown Ridge LLC"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                </div>

                <Field label="Statement of claim" :error="filing.errors.statement_of_claim">
                    <template #control="{ id, describedBy }">
                        <textarea
                            :id="id"
                            v-model="filing.statement_of_claim"
                            class="field-input"
                            rows="3"
                            placeholder="What happened, and what remedy you seek"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>

                <p class="citation">
                    Civil/criminal case filing · F-IND-017 → F-JDG-001 · the court classifies
                    justiciability and severity, then assigns a panel with conflict screening ·
                    Art. IV §4 · Art. I (Right to Fair Trial)
                </p>
            </FormCard>
        </template>

        <!-- Unassociated reader: read-only docket + residency CTA (never 403). -->
        <Card v-else-if="!isAssociated" as="section" title="File a case">
            <Banner tone="info" role="status" title="Confirm residency to file.">
                The docket is public to read. Filing a case is open to anyone jurisdictionally
                associated (Art. I) — confirm your residency to unlock filing.
                <span style="display: block; margin-block-start: var(--space-2)">
                    <Link href="/civic/residency">Confirm residency →</Link>
                </span>
            </Banner>
        </Card>

        <!-- ===================== other entries + what's next ============= -->
        <div class="grid-2">
            <Card as="section" title="Other ways cases arrive">
                <p class="citation" style="margin-block-end: var(--space-3)">
                    Reference cards — the filing instruments that open a case from a different door.
                </p>
                <div class="stack" style="gap: var(--space-3)">
                    <div
                        v-for="f in [surfaceForm('F-IND-016'), surfaceForm('F-ADV-001')].filter(Boolean)"
                        :key="f.id"
                        class="card card--inset"
                    >
                        <p style="margin-block-end: var(--space-1)">
                            <strong style="color: var(--gov-fg)">{{ f.name }}</strong>
                            {{ ' ' }}
                            <FormChip :form-id="f.id" :alias="f.alias" />
                        </p>
                        <p class="citation">available to {{ (f.availableTo ?? []).join(', ') }} · {{ f.citation }}</p>
                    </div>
                </div>
            </Card>

            <Card as="section" title="What the court does next">
                <div v-if="surfaceForm('F-JDG-001')" class="card card--inset" style="margin-block-end: var(--space-3)">
                    <p style="margin-block-end: var(--space-1)">
                        <strong style="color: var(--gov-fg)">{{ surfaceForm('F-JDG-001').name }}</strong>
                        {{ ' ' }}
                        <FormChip :form-id="surfaceForm('F-JDG-001').id" :alias="surfaceForm('F-JDG-001').alias" />
                    </p>
                    <p class="citation">
                        available to {{ (surfaceForm('F-JDG-001').availableTo ?? []).join(', ') }} ·
                        {{ surfaceForm('F-JDG-001').citation }}
                    </p>
                </div>
                <p>
                    <strong>Conflict screening:</strong> before a panel is fixed, every candidate judge
                    is screened for personal, financial, or prior-involvement conflicts; conflicted
                    judges are excluded and the draw re-runs. Screening results attach to the case
                    record.
                </p>
                <p class="citation" style="margin-block: var(--space-2)">
                    <HardenedChip>Panel assignment with conflict screening · full court for major constitutional questions · Art. IV §4 · CLK-16</HardenedChip>
                </p>
                <p>
                    <Link :href="cases.length ? cases[0].href : judiciary.home_href">
                        Walk a case through the full lifecycle →
                    </Link>
                </p>
            </Card>
        </div>

        <template #about>
            <p>
                The case lifecycle (WF-JUD-03) carries every filing from a claimed scale and severity
                through acceptance, panel assignment with conflict screening, hearing, and judgement.
                Constitutional challenges branch into the Art. IV §5 tracker (WF-JUD-05); jury
                paneling runs as WF-JUD-04. The Case state machine:
            </p>
            <StateStrip v-if="machine.length" :states="machine" />
        </template>
    </PageScaffold>
</template>
