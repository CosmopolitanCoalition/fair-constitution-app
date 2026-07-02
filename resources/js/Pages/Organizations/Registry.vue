<script setup>
/**
 * Organizations/Registry — FE-D6 (PHASE_D_DESIGN_frontend.md §B.6; surface
 * organizations/org-registry). The one registry, no faction layer.
 *
 * Stat tiles · FilterBar (type ChipToggles, structure select, jurisdiction
 * select, search) · the registry DataTable with the CO-DETERMINATION CELL
 * (server-computed state + the engine's worker_seats snapshot — never
 * recomputed client-side) · the F-IND-012 registration FormCard (with the
 * CGC carve-out stated verbatim) · the ESM-18 StateStrip legend.
 *
 * Public read (visibility 'all'); registration requires R-03 — the page
 * explains and shows a residency CTA, it never 403s (CandidacyRegistration
 * pattern). Every threshold here (min / parity) is a server-resolved
 * CLK-13/14 value — the constants are never hardcoded.
 */
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import ChipToggle from '@/Components/Ui/ChipToggle.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stat from '@/Components/Ui/Stat.vue';
import TagChip from '@/Components/Ui/TagChip.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    stats: { type: Object, required: () => ({ total: 0, endorsing: 0, in_codetermination: 0, cgcs: 0 }) },
    organizations: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ types: [], structures: [], jurisdictions: [] }) },
    machine: { type: Array, default: () => [] },
    createForm: { type: Object, default: () => ({ types: [], structures: [], jurisdictionOptions: [] }) },
    isAssociated: { type: Boolean, default: false },
    thresholds: { type: Object, default: () => ({ min: 100, parity: 2000 }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const titleize = (s) => (s ? String(s).replaceAll('_', ' ') : '—');
const fmt = (n) => Number(n ?? 0).toLocaleString();

/* ------------------------------------------------------ client filters -- */
const q = ref('');
const activeTypes = ref([]);
const structure = ref('');
const jurisdiction = ref('');

function toggleType(type) {
    activeTypes.value = activeTypes.value.includes(type)
        ? activeTypes.value.filter((t) => t !== type)
        : [...activeTypes.value, type];
}

const filtered = computed(() =>
    props.organizations.filter((org) => {
        if (activeTypes.value.length && !activeTypes.value.includes(org.type)) return false;
        if (structure.value && org.structure !== structure.value) return false;
        if (jurisdiction.value && org.jurisdiction?.name !== jurisdiction.value) return false;
        if (q.value && !org.name.toLowerCase().includes(q.value.toLowerCase())) return false;
        return true;
    }),
);

const hasFilters = computed(
    () => q.value !== '' || activeTypes.value.length > 0 || structure.value !== '' || jurisdiction.value !== '',
);

function clearFilters() {
    q.value = '';
    activeTypes.value = [];
    structure.value = '';
    jurisdiction.value = '';
}

/* ------------------------------------------------- co-determination cell */
function codetBadge(org) {
    if (!org.codet) {
        return { tone: 'neutral', text: 'no board', citation: null };
    }
    if (org.codet.state === 'parity') {
        return { tone: 'success', text: 'parity', citation: 'worker seats equal owner seats · CLK-14' };
    }
    if (org.codet.state === 'scaling') {
        return {
            tone: 'info',
            text: `${org.codet.worker_seats} worker seat${org.codet.worker_seats === 1 ? '' : 's'} · scaling`,
            citation: 'first seat at the CLK-13 minimum · Art. III §6',
        };
    }
    return { tone: 'neutral', text: 'below threshold', citation: null };
}

function statusTone(status) {
    return status === 'active' ? 'success' : status === 'registered' ? 'info' : 'neutral';
}

/* ------------------------------------------------- registration form ---- */
const registerForm = useForm({
    type: props.createForm.types[0] ?? 'business',
    structure: props.createForm.structures[0]?.value ?? null,
    name: '',
    jurisdiction_id: props.createForm.jurisdictionOptions[0]?.id ?? null,
    purpose: '',
});

const selectedStructureGloss = computed(
    () => props.createForm.structures.find((s) => s.value === registerForm.structure)?.rule_gloss ?? null,
);

function submitRegistration() {
    registerForm.post('/organizations', { preserveScroll: true });
}

const columns = [
    { key: 'name', label: 'Organization' },
    { key: 'type', label: 'Type / structure' },
    { key: 'workers', label: 'Workers', mono: true, align: 'right' },
    { key: 'codet', label: 'Co-determination' },
    { key: 'endorsement_count', label: 'Endorsements', mono: true, align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <PageScaffold :surface="surface" title="Organization registry">
        <template #intro>
            Every registered organization where you live — parties, businesses, nonprofits, and
            public companies share one open list. Anyone who lives here can start one.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" role="alert">{{ constitutionError }}</Banner>

        <!-- ============================================ stat tiles ======= -->
        <Card as="section" title="The registry at a glance">
            <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                <Stat :value="fmt(stats.total)" label="organizations" />
                <Stat :value="fmt(stats.endorsing)" label="currently endorsing a candidate" />
                <Stat
                    :value="fmt(stats.in_codetermination)"
                    :label="`in co-determination — workers ≥ ${fmt(thresholds.min)} (CLK-13)`"
                    accent
                />
                <Stat :value="fmt(stats.cgcs)" label="Common Good Corporations" />
            </div>
        </Card>

        <!-- ============================================== registry ======= -->
        <Card as="section" title="Registered organizations">
            <FilterBar label="Filter organizations">
                <ChipToggle
                    v-for="type in filters.types"
                    :key="type"
                    :pressed="activeTypes.includes(type)"
                    @update:pressed="toggleType(type)"
                >{{ titleize(type) }}</ChipToggle>

                <label class="field" style="margin: 0">
                    <span class="visually-hidden">Structure</span>
                    <select v-model="structure" class="select">
                        <option value="">All structures</option>
                        <option v-for="s in filters.structures" :key="s" :value="s">{{ titleize(s) }}</option>
                    </select>
                </label>

                <label v-if="filters.jurisdictions.length" class="field" style="margin: 0">
                    <span class="visually-hidden">Jurisdiction</span>
                    <select v-model="jurisdiction" class="select">
                        <option value="">All jurisdictions</option>
                        <option v-for="j in filters.jurisdictions" :key="j.id" :value="j.name">{{ j.name }}</option>
                    </select>
                </label>

                <input v-model="q" type="search" class="field-input" placeholder="Search by name" style="inline-size: 14rem" />
            </FilterBar>

            <template v-if="organizations.length">
                <DataTable :columns="columns" :rows="filtered" row-key="id" caption="Registered organizations">
                    <template #cell-name="{ row }">
                        <Link :href="row.href"><strong style="color: var(--gov-fg)">{{ row.name }}</strong></Link>
                        <TagChip v-if="row.is_cgc" style="margin-inline-start: var(--space-1)" data-no-i18n>CGC</TagChip>
                        <span v-if="row.jurisdiction" class="cc-small" style="display: block">
                            <AdmChip :level="row.jurisdiction.adm_level" :label="row.jurisdiction.name" />
                        </span>
                    </template>
                    <template #cell-type="{ row }">
                        <TagChip data-no-i18n>{{ titleize(row.type) }}</TagChip>
                        <TagChip v-if="row.structure" style="margin-inline-start: var(--space-1)" data-no-i18n>{{ titleize(row.structure) }}</TagChip>
                    </template>
                    <template #cell-workers="{ row }">{{ fmt(row.workers) }}</template>
                    <template #cell-codet="{ row }">
                        <StatusBadge :tone="codetBadge(row).tone" icon="users">{{ codetBadge(row).text }}</StatusBadge>
                        <span v-if="codetBadge(row).citation" class="citation" style="display: block">{{ codetBadge(row).citation }}</span>
                    </template>
                    <template #cell-endorsement_count="{ row }">{{ fmt(row.endorsement_count) }}</template>
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="statusTone(row.status)">{{ row.status }}</StatusBadge>
                    </template>
                </DataTable>
                <div v-if="!filtered.length" class="cluster" style="margin-block-start: var(--space-2)">
                    <span class="gloss">No organizations match the current filters.</span>
                    <Btn v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">Clear filters</Btn>
                </div>
            </template>
            <Banner v-else tone="info" role="status" title="No organizations registered in your association chain.">
                Any associated resident may register one — association is the only requirement (Art. I).
            </Banner>
        </Card>

        <!-- ============================== registration / residency CTA == -->
        <FormCard
            v-if="isAssociated"
            :form="formMeta('F-IND-012')"
            :inertia-form="registerForm"
            submit-label="Register organization"
            processing-label="Registering…"
            @submit="submitRegistration"
        >
            <Field label="Name" :error="registerForm.errors.name" required>
                <template #control="{ id, describedBy, invalid }">
                    <input :id="id" v-model="registerForm.name" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedBy" />
                </template>
            </Field>

            <Field label="Type" :error="registerForm.errors.type">
                <template #control="{ id }">
                    <select :id="id" v-model="registerForm.type" class="select">
                        <option v-for="type in createForm.types" :key="type" :value="type">{{ titleize(type) }}</option>
                    </select>
                </template>
            </Field>

            <Field label="Ownership structure" :error="registerForm.errors.structure" :hint="selectedStructureGloss">
                <template #control="{ id, describedBy }">
                    <select :id="id" v-model="registerForm.structure" class="select" :aria-describedby="describedBy">
                        <option v-for="s in createForm.structures" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </template>
            </Field>

            <Field label="Jurisdiction" :error="registerForm.errors.jurisdiction_id">
                <template #control="{ id }">
                    <select :id="id" v-model="registerForm.jurisdiction_id" class="select">
                        <option v-for="j in createForm.jurisdictionOptions" :key="j.id" :value="j.id">{{ j.name }}</option>
                    </select>
                </template>
            </Field>

            <Field label="Purpose" :error="registerForm.errors.purpose">
                <template #control="{ id }">
                    <textarea :id="id" v-model="registerForm.purpose" class="field-input" rows="2"></textarea>
                </template>
            </Field>

            <template #actions>
                <span class="citation">
                    Common Good Corporations are not self-registered — the legislature creates them by act
                    (F-LEG-019 · WF-ORG-08).
                </span>
            </template>
        </FormCard>

        <Card v-else as="section" title="Registering an organization">
            <Banner tone="info" role="status" title="Confirm residency to register.">
                Registration is an absolute right of any associated resident (Art. I, Economic Freedom) —
                you just need an active residency association first.
            </Banner>
            <p class="cc-small" style="margin-block-start: var(--space-3)">
                <Link href="/civic">Confirm your residency →</Link>
            </p>
        </Card>

        <!-- ============================================ ESM-18 legend ==== -->
        <Card as="section" title="Organization lifecycle (ESM-18)">
            <StateStrip :states="machine" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                "Endorsing" and the co-determination tiers are derived display states — read from
                live endorsement rows and the worker headcount against the CLK-13 / CLK-14 thresholds —
                never stored statuses. The stored machine is registration → active, with the transfer,
                conversion, and dissolution branches.
            </p>
        </Card>
    </PageScaffold>
</template>
