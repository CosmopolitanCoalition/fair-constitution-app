<script setup>
/**
 * Organizations/CgcDetail — FE-D9 (PHASE_D_DESIGN_frontend.md §B.8; surface
 * organizations/cgc-detail).
 *
 * The Common Good Corporation detail: charter (legislature creates) +
 * oversight (executive oversees) with the identical-regulation HardenedChip,
 * the current board summary and roster, and the public-domain IP register — a
 * DataTable whose status column carries one value (public_domain) plus an
 * add-asset FormCard with NO status field at all (the absence of the
 * affordance is the UI statement of irreversibility; the engine enforces it
 * regardless · Art. III §5).
 *
 * CONSTITUTIONAL POSTURE — pure renderer: worker_seats / composition_valid
 * come from the boards row; the IP register status is always public_domain.
 * Nothing here is computed.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import OrganizationNav from '@/Components/Organizations/OrganizationNav.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import LifecycleTracker from '@/Components/Ui/LifecycleTracker.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import BoardStrip from '@/Components/Organizations/BoardStrip.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    organization: { type: Object, required: true },
    charter: { type: Object, default: null },
    oversight: { type: Object, default: null },
    codet: { type: Object, default: null },
    board: { type: Object, default: null },
    ipRegister: { type: Array, default: () => [] },
    actionsDeepLinks: { type: Object, default: () => ({}) },
    conversions: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ registerIp: false }) },
    urls: { type: Object, default: () => ({}) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* The IP register form has NO status field — public_domain is the only
   representable value; the engine + DB triggers enforce it regardless. */
const ipForm = useForm({ asset: '', kind: '', description: '' });
const ipKinds = [
    'software', 'patentable_invention', 'copyrightable_work',
    'design', 'data', 'process', 'other',
];

const ipColumns = [
    { key: 'asset', label: 'Asset' },
    { key: 'kind', label: 'Kind' },
    { key: 'published_at', label: 'Published', mono: true },
    { key: 'status', label: 'Status' },
];

/* The conversion lifecycle (org_conversions) — rendered when one exists. */
const conversionStages = ['Proposed', 'Voted', 'Compensation', 'Converting', 'Completed'];
const STATUS_TO_STAGE = {
    proposed: 'Proposed',
    voted: 'Voted',
    compensation_pending: 'Compensation',
    converting: 'Converting',
    completed: 'Completed',
    abandoned: 'Proposed',
};

function ipKindLabel(kind) {
    return (kind ?? '—').replaceAll('_', ' ');
}

function submitIp() {
    if (!props.urls.ipRegister) return;
    ipForm.post(props.urls.ipRegister, {
        preserveScroll: true,
        onSuccess: () => ipForm.reset(),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="`Common Good Corporation — ${organization.name}`">
        <template #intro>
            A Common Good Corporation is a public enterprise chartered by a legislature to provide
            goods or services to its jurisdiction. It competes and is regulated exactly like its
            private peers — with one permanent difference: everything it creates belongs to everyone.
        </template>

        <OrganizationNav :organization="{ ...organization, is_cgc: true }" current="overview" />

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
            <Stat :value="organization.worker_count.toLocaleString()" label="Workers" accent />
            <Stat :value="organization.status.replaceAll('_', ' ')" label="Status" />
        </div>

        <!-- ========================================= charter card ======= -->
        <Card as="section" title="Charter">
            <p v-if="charter?.purpose" style="margin-block-end: var(--space-2)">{{ charter.purpose }}</p>
            <p v-else class="gloss">No charter purpose recorded.</p>
            <p class="citation">
                <template v-if="charter?.act">
                    chartered by <Link :href="charter.act.href">{{ charter.act.act_number ?? 'creation act' }}</Link> ·
                    <FormChip form-id="F-LEG-019" />
                </template>
                <template v-else>
                    chartered by act · <FormChip form-id="F-LEG-019" />
                </template>
                <template v-if="charter?.effective_at"> · effective {{ charter.effective_at }}</template>
            </p>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                The legislature creates; the executive oversees · Art. III §5.
            </p>
        </Card>

        <!-- ========================================= oversight card ===== -->
        <Card as="section" title="Oversight">
            <template v-if="oversight">
                <p>
                    Overseen by
                    <Link :href="oversight.executive.href">{{ oversight.executive.name }}</Link><template v-if="oversight.department">,
                    through
                    <Link :href="oversight.department.href">{{ oversight.department.name }}</Link></template>.
                    <template v-if="oversight.reporting_interval">
                        Reports every {{ oversight.reporting_interval }} month(s).
                    </template>
                </p>
            </template>
            <p v-else class="gloss">No overseeing executive assigned yet.</p>
            <p style="margin-block-start: var(--space-2)">
                <HardenedChip>Same regulation as private organizations</HardenedChip>
            </p>
            <p class="citation" style="margin-block-start: var(--space-1)">
                A CGC is subject to the same regulation as any private peer; its public ownership confers no
                regulatory privilege · Art. III §5.
            </p>
        </Card>

        <!-- The detailed scale belongs to the Worker representation workspace. -->
        <Card as="section" title="Board">
            <div v-if="codet" class="cluster" style="gap: var(--space-5); margin-block-end: var(--space-3)">
                <Stat :value="codet.ownerSeats" label="Appointed governor seats" />
                <Stat :value="codet.workerSeats" label="Worker seats required" />
            </div>
            <BoardStrip
                v-if="board"
                :seats="board.seats"
                :composition-valid="board.compositionValid"
                :required-worker-seats="board.requiredWorkerSeats"
            />
            <p v-else class="gloss">No board constituted yet.</p>
            <p style="margin-block-start: var(--space-3)">
                <Link :href="`/organizations/co-determination?org=${organization.id}`">How worker representation is determined →</Link>
            </p>
        </Card>

        <!-- ================================ public-domain IP register === -->
        <Card as="section" title="Public-domain intellectual property register">
            <div class="card card--inset" style="margin-block-end: var(--space-3)">
                <p style="margin: 0">
                    <HardenedChip>Every work is public domain from the moment of creation</HardenedChip>
                </p>
                <p class="citation" style="margin-block-start: var(--space-1)">
                    Every work this corporation produces is public domain from the moment of creation —
                    universally, eternally, irreversibly · Art. III §5.
                </p>
            </div>

            <DataTable
                v-if="ipRegister.length"
                :columns="ipColumns"
                :rows="ipRegister"
                caption="Public-domain works"
            >
                <template #cell-kind="{ row }">
                    <span data-no-i18n>{{ ipKindLabel(row.kind) }}</span>
                </template>
                <template #cell-published_at="{ row }">
                    <span class="mono">{{ row.published_at ?? '—' }}</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge tone="success" icon="unlock">{{ row.status.replaceAll('_', ' ') }}</StatusBadge>
                </template>
            </DataTable>
            <Banner v-else tone="info" role="status" title="No works registered yet.">
                No works registered yet — the public-domain rule attaches at creation, not at registration.
            </Banner>

            <!-- add-asset form — NO status field (the column admits one value) -->
            <div v-if="can.registerIp" style="margin-block-start: var(--space-4)">
                <FormCard
                    :form="surface.forms.find((f) => f.id === 'F-LEG-019')"
                    :inertia-form="ipForm"
                    submit-label="Dedicate to the public domain"
                    processing-label="Dedicating…"
                    @submit="submitIp"
                >
                    <Field label="Asset" :error="ipForm.errors.asset" required>
                        <template #control="{ id, describedBy, invalid }">
                            <input
                                :id="id"
                                v-model="ipForm.asset"
                                class="field-input"
                                type="text"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                    <Field label="Kind" :error="ipForm.errors.kind" required>
                        <template #control="{ id, describedBy, invalid }">
                            <select
                                :id="id"
                                v-model="ipForm.kind"
                                class="select"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            >
                                <option value="" disabled>Select a kind…</option>
                                <option v-for="k in ipKinds" :key="k" :value="k">{{ ipKindLabel(k) }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        label="Description"
                        hint="Describe the work being dedicated. A public-domain dedication cannot be revoked."
                        :error="ipForm.errors.description"
                    >
                        <template #control="{ id, describedBy }">
                            <textarea
                                :id="id"
                                v-model="ipForm.description"
                                class="field-input"
                                rows="3"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                </FormCard>
            </div>
        </Card>

        <!-- ================== reorganization / sale / dissolution ======= -->
        <Card as="section" title="Reorganization, sale, and dissolution">
            <p>
                Only the legislature may reorganize, sell, or dissolve this public corporation.
                Its existing works remain public domain after a sale.
            </p>
            <p v-if="actionsDeepLinks.reorganize" class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="actionsDeepLinks.reorganize">Introduce a reorganization/sale bill →</Link>
            </p>

            <template v-if="conversions.length">
                <p class="citation" style="margin-block-start: var(--space-3)">conversion history</p>
                <div
                    v-for="(conversion, i) in conversions"
                    :key="i"
                    style="margin-block-start: var(--space-2)"
                >
                    <p class="cc-small" style="margin-block-end: var(--space-1)" data-no-i18n>
                        {{ conversion.direction.replaceAll('_', ' ') }} · via {{ conversion.via.replaceAll('_', ' ') }}
                    </p>
                    <LifecycleTracker
                        :stages="conversionStages"
                        :current="STATUS_TO_STAGE[conversion.status] ?? 'Proposed'"
                    />
                </div>
            </template>
        </Card>

        <template #about>
            <p>
                A Common Good Corporation is public property held for the common good. The legislature
                charters it (F-LEG-019), an executive department oversees it, and its intellectual property
                is perpetually public domain (Art. III §5). It is otherwise regulated exactly like a private
                enterprise — and runs the same co-determination scale as every other board (Art. III §6).
            </p>
        </template>
    </PageScaffold>
</template>
