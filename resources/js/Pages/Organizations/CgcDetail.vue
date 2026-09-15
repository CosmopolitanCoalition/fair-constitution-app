<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
import { useI18n } from 'vue-i18n';
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
const { t } = useI18n();

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
    can: { type: Object, default: () => ({ registerIp: false, requestRemoval: false }) },
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
    { key: 'asset', label: t('c_institutions.cgc_detail.col_asset', 'Asset') },
    { key: 'kind', label: t('c_institutions.cgc_detail.col_kind', 'Kind') },
    { key: 'published_at', label: t('c_institutions.cgc_detail.col_published', 'Published'), mono: true },
    { key: 'status', label: t('c_institutions.cgc_detail.col_status', 'Status') },
];

/* The conversion lifecycle (org_conversions) — rendered when one exists. */
const conversionStages = [
    t('c_institutions.cgc_detail.stage_proposed', 'Proposed'),
    t('c_institutions.cgc_detail.stage_voted', 'Voted'),
    t('c_institutions.cgc_detail.stage_compensation', 'Compensation'),
    t('c_institutions.cgc_detail.stage_converting', 'Converting'),
    t('c_institutions.cgc_detail.stage_completed', 'Completed'),
];
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

/* F-EXE-003 — a CGC governor is removed through its overseeing executive and
   creating legislature (IO-7). Only a seated principal of the overseeing
   executive sees this form; the service is the real wall. */
const removal = useForm({ board_seat_id: '', grounds: '' });

/* Removal runs against a currently SEATED governor seat only. */
const removableSeats = computed(() =>
    (props.board?.seats ?? []).filter((s) => s.seat_class === 'governor' && s.status === 'seated'),
);

const removalDisabledReason = computed(() => {
    if (props.organization.status !== 'active') {
        return t('c_institutions.cgc_detail.removal_not_active', 'This corporation is not active — its governors cannot be removed.');
    }
    if (!removableSeats.value.length) {
        return t('c_institutions.cgc_detail.removal_no_governor', 'There is no seated governor to file a removal against.');
    }
    return null;
});

function submitRemoval() {
    if (!props.urls.governorRemovals) return;
    removal.post(props.urls.governorRemovals, {
        preserveScroll: true,
        onSuccess: () => removal.reset(),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_institutions.cgc_detail.page_title', { name: organization.name })">
        <template #intro>
            {{ t('c_institutions.cgc_detail.intro', 'A Common Good Corporation is a public enterprise chartered by a legislature to provide goods or services to its jurisdiction. It competes and is regulated exactly like its private peers — with one permanent difference: everything it creates belongs to everyone.') }}
        </template>

        <OrganizationNav :organization="{ ...organization, is_cgc: true }" current="overview" />

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
            <Stat :value="localeFmt.number(organization.worker_count)" :label="t('c_institutions.cgc_detail.stat_workers', 'Workers')" accent />
            <Stat :value="organization.status.replaceAll('_', ' ')" :label="t('c_institutions.cgc_detail.stat_status', 'Status')" />
        </div>

        <!-- ========================================= charter card ======= -->
        <Card as="section" :title="t('c_institutions.cgc_detail.charter_title', 'Charter')">
            <p v-if="charter?.purpose" style="margin-block-end: var(--space-2)">{{ charter.purpose }}</p>
            <p v-else class="gloss">{{ t('c_institutions.cgc_detail.no_charter_purpose', 'No charter purpose recorded.') }}</p>
            <p class="citation">
                <template v-if="charter?.act">
                    {{ t('c_institutions.cgc_detail.chartered_by', 'chartered by') }} <Link :href="charter.act.href">{{ charter.act.act_number ?? t('c_institutions.cgc_detail.creation_act', 'creation act') }}</Link> ·
                    <FormChip form-id="F-LEG-019" />
                </template>
                <template v-else>
                    {{ t('c_institutions.cgc_detail.chartered_by_act', 'chartered by act') }} · <FormChip form-id="F-LEG-019" />
                </template>
                <template v-if="charter?.effective_at"> {{ t('c_institutions.cgc_detail.effective', { date: charter.effective_at }) }}</template>
            </p>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.cgc_detail.charter_gloss', 'The legislature creates; the executive oversees · Art. III §5.') }}
            </p>
        </Card>

        <!-- ========================================= oversight card ===== -->
        <Card as="section" :title="t('c_institutions.cgc_detail.oversight_title', 'Oversight')">
            <template v-if="oversight">
                <p>
                    {{ t('c_institutions.cgc_detail.overseen_by', 'Overseen by') }}
                    <Link :href="oversight.executive.href">{{ oversight.executive.name }}</Link><template v-if="oversight.department">{{ t('c_institutions.cgc_detail.through_sep', ', through') }}
                    <Link :href="oversight.department.href">{{ oversight.department.name }}</Link></template>.
                    <template v-if="oversight.reporting_interval">
                        {{ t('c_institutions.cgc_detail.reports_every', { n: oversight.reporting_interval }) }}
                    </template>
                </p>
            </template>
            <p v-else class="gloss">{{ t('c_institutions.cgc_detail.no_oversight', 'No overseeing executive assigned yet.') }}</p>
            <p style="margin-block-start: var(--space-2)">
                <HardenedChip>{{ t('c_institutions.cgc_detail.same_regulation_chip', 'Same regulation as private organizations') }}</HardenedChip>
            </p>
            <p class="citation" style="margin-block-start: var(--space-1)">
                {{ t('c_institutions.cgc_detail.same_regulation_cite', 'A CGC is subject to the same regulation as any private peer; its public ownership confers no regulatory privilege · Art. III §5.') }}
            </p>
        </Card>

        <!-- The detailed scale belongs to the Worker representation workspace. -->
        <Card as="section" :title="t('c_institutions.cgc_detail.board_title', 'Board')">
            <div v-if="codet" class="cluster" style="gap: var(--space-5); margin-block-end: var(--space-3)">
                <Stat :value="codet.ownerSeats" :label="t('c_institutions.cgc_detail.appointed_seats', 'Appointed governor seats')" />
                <Stat :value="codet.workerSeats" :label="t('c_institutions.cgc_detail.worker_seats_required', 'Worker seats required')" />
            </div>
            <BoardStrip
                v-if="board"
                :seats="board.seats"
                :composition-valid="board.compositionValid"
                :required-worker-seats="board.requiredWorkerSeats"
            />
            <p v-else class="gloss">{{ t('c_institutions.cgc_detail.no_board', 'No board constituted yet.') }}</p>
            <p style="margin-block-start: var(--space-3)">
                <Link :href="`/organizations/co-determination?org=${organization.id}`">{{ t('c_institutions.cgc_detail.how_worker_rep', 'How worker representation is determined →') }}</Link>
            </p>
        </Card>

        <!-- ================================ F-EXE-003 governor removal === -->
        <Card v-if="can.requestRemoval" as="section" :title="t('c_institutions.cgc_detail.remove_governor_title', 'Remove a governor')">
            <p class="gloss" style="margin-block-end: var(--space-3)">
                {{ t('c_institutions.cgc_detail.removal_gloss_before', "A governor is removed through this corporation's overseeing executive: the creating legislature decides by an") }} <strong>{{ t('c_institutions.cgc_detail.removal_gloss_strong', 'ordinary majority of all serving members') }}</strong> {{ t('c_institutions.cgc_detail.removal_gloss_after', '— hiring and firing, never the supermajority machinery.') }}
            </p>
            <FormCard
                :form="surface.forms.find((f) => f.id === 'F-EXE-003')"
                :inertia-form="removal"
                :submit-label="t('c_institutions.cgc_detail.request_removal', 'Request removal')"
                :processing-label="t('c_institutions.cgc_detail.filing', 'Filing…')"
                :disabled="!!removalDisabledReason"
                @submit="submitRemoval"
            >
                <Field
                    :label="t('c_institutions.cgc_detail.governor_label', 'Governor')"
                    :hint="t('c_institutions.cgc_detail.governor_hint', 'Removal runs against a currently seated governor of this corporation.')"
                    :error="removal.errors.board_seat_id"
                    required
                >
                    <template #control="{ id, describedBy, invalid }">
                        <select
                            :id="id"
                            v-model="removal.board_seat_id"
                            class="select"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        >
                            <option value="" disabled>{{ t('c_institutions.cgc_detail.select_governor', 'Select a seated governor…') }}</option>
                            <option v-for="seat in removableSeats" :key="seat.id" :value="seat.id">
                                {{ seat.holder?.name ?? t('c_institutions.cgc_detail.seat_fallback', 'Seat') }} {{ t('c_institutions.cgc_detail.appointed_governor_suffix', '— appointed governor') }}
                            </option>
                        </select>
                    </template>
                </Field>

                <Field
                    :label="t('c_institutions.cgc_detail.grounds_label', 'Grounds')"
                    :hint="t('c_institutions.cgc_detail.grounds_hint', 'A good-faith competence/ethics finding — published at filing.')"
                    :error="removal.errors.grounds"
                    required
                >
                    <template #control="{ id, describedBy, invalid }">
                        <textarea
                            :id="id"
                            v-model="removal.grounds"
                            class="field-input"
                            rows="4"
                            maxlength="20000"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>

                <p class="citation" style="margin-block-start: var(--space-2)">
                    {{ t('c_institutions.cgc_detail.removal_cite', 'Opens a vote in the creating legislature requiring an ordinary majority of all serving members · Art. III §5.') }}
                </p>
                <Banner
                    v-if="removalDisabledReason"
                    tone="info"
                    role="status"
                    :title="t('c_institutions.cgc_detail.removal_unavailable_title', 'Removal is not available.')"
                    style="margin-block-start: var(--space-2)"
                >
                    {{ removalDisabledReason }}
                </Banner>
            </FormCard>
        </Card>

        <!-- ================================ public-domain IP register === -->
        <Card as="section" :title="t('c_institutions.cgc_detail.ip_title', 'Public-domain intellectual property register')">
            <div class="card card--inset" style="margin-block-end: var(--space-3)">
                <p style="margin: 0">
                    <HardenedChip>{{ t('c_institutions.cgc_detail.ip_chip', 'Every work is public domain from the moment of creation') }}</HardenedChip>
                </p>
                <p class="citation" style="margin-block-start: var(--space-1)">
                    {{ t('c_institutions.cgc_detail.ip_cite', 'Every work this corporation produces is public domain from the moment of creation — universally, eternally, irreversibly · Art. III §5.') }}
                </p>
            </div>

            <DataTable
                v-if="ipRegister.length"
                :columns="ipColumns"
                :rows="ipRegister"
                :caption="t('c_institutions.cgc_detail.ip_caption', 'Public-domain works')"
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
            <Banner v-else tone="info" role="status" :title="t('c_institutions.cgc_detail.no_works_title', 'No works registered yet.')">
                {{ t('c_institutions.cgc_detail.no_works_body', 'No works registered yet — the public-domain rule attaches at creation, not at registration.') }}
            </Banner>

            <!-- add-asset form — NO status field (the column admits one value) -->
            <div v-if="can.registerIp" style="margin-block-start: var(--space-4)">
                <FormCard
                    :form="surface.forms.find((f) => f.id === 'F-LEG-019')"
                    :inertia-form="ipForm"
                    :submit-label="t('c_institutions.cgc_detail.dedicate_submit', 'Dedicate to the public domain')"
                    :processing-label="t('c_institutions.cgc_detail.dedicating', 'Dedicating…')"
                    @submit="submitIp"
                >
                    <Field :label="t('c_institutions.cgc_detail.asset_label', 'Asset')" :error="ipForm.errors.asset" required>
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
                    <Field :label="t('c_institutions.cgc_detail.kind_label', 'Kind')" :error="ipForm.errors.kind" required>
                        <template #control="{ id, describedBy, invalid }">
                            <select
                                :id="id"
                                v-model="ipForm.kind"
                                class="select"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            >
                                <option value="" disabled>{{ t('c_institutions.cgc_detail.select_kind', 'Select a kind…') }}</option>
                                <option v-for="k in ipKinds" :key="k" :value="k">{{ ipKindLabel(k) }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        :label="t('c_institutions.cgc_detail.description_label', 'Description')"
                        :hint="t('c_institutions.cgc_detail.description_hint', 'Describe the work being dedicated. A public-domain dedication cannot be revoked.')"
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
        <Card as="section" :title="t('c_institutions.cgc_detail.reorg_title', 'Reorganization, sale, and dissolution')">
            <p>
                {{ t('c_institutions.cgc_detail.reorg_body', 'Only the legislature may reorganize, sell, or dissolve this public corporation. Its existing works remain public domain after a sale.') }}
            </p>
            <p v-if="actionsDeepLinks.reorganize" class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="actionsDeepLinks.reorganize">{{ t('c_institutions.cgc_detail.introduce_reorg', 'Introduce a reorganization/sale bill →') }}</Link>
            </p>

            <template v-if="conversions.length">
                <p class="citation" style="margin-block-start: var(--space-3)">{{ t('c_institutions.cgc_detail.conversion_history', 'conversion history') }}</p>
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
                {{ t('c_institutions.cgc_detail.about', 'A Common Good Corporation is public property held for the common good. The legislature charters it (F-LEG-019), an executive department oversees it, and its intellectual property is perpetually public domain (Art. III §5). It is otherwise regulated exactly like a private enterprise — and runs the same co-determination scale as every other board (Art. III §6).') }}
            </p>
        </template>
    </PageScaffold>
</template>
