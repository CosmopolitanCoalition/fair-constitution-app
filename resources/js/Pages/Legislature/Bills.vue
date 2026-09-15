<script setup>
/**
 * Legislature/Bills — FE-C4 (PHASE_C_DESIGN_frontend.md §B.3; surface
 * legislature/bills).
 *
 * Bill lifecycle legend (PHP-owned machine) · filterable registry ·
 * the F-LEG-003 introduction FormCard: act_type with threshold glosses,
 * scale multiselect (engine-validated ⊆ the legislature's authority),
 * scope select (forming judiciary stubs labeled honestly), and the
 * setting_change path with the LIVE bounds pre-flight
 * (POST /legislatures/{l}/bills/validate — a pure validator check; the
 * real rejected=true chain row is written when an out-of-range
 * introduction is actually filed).
 *
 * Deep-link contract (§B.11): ?intro=1&setting={key} pre-targets the
 * form at act_type=setting_change with the key locked.
 */
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    machine: { type: Array, default: () => [] },
    bills: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ status: [], act_type: [] }) },
    introForm: { type: Object, required: true },
    can: { type: Object, default: () => ({ introduce: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

/* ----------------------------------------------------------- filters --- */
const statusFilter = ref('');
const typeFilter = ref('');

const filteredBills = computed(() =>
    props.bills.filter(
        (bill) =>
            (!statusFilter.value || bill.status === statusFilter.value) &&
            (!typeFilter.value || bill.act_type === typeFilter.value),
    ),
);

const STATUS_TONES = {
    introduced: { tone: 'info', label: t('c_legislature_pages.bills.status_introduced', 'Introduced') },
    referred: { tone: 'info', label: t('c_legislature_pages.bills.status_referred', 'Referred') },
    in_committee: { tone: 'info', label: t('c_legislature_pages.bills.status_in_committee', 'In committee') },
    reported: { tone: 'info', label: t('c_legislature_pages.bills.status_reported', 'Reported') },
    tabled: { tone: 'neutral', label: t('c_legislature_pages.bills.status_tabled', 'Tabled') },
    on_floor: { tone: 'warning', label: t('c_legislature_pages.bills.status_on_floor', 'On floor') },
    passed: { tone: 'success', label: t('c_legislature_pages.bills.status_passed', 'Passed') },
    failed: { tone: 'danger', label: t('c_legislature_pages.bills.status_failed', 'Failed') },
    enacted: { tone: 'success', label: t('c_legislature_pages.bills.status_enacted', 'Enacted · Published') },
    withdrawn: { tone: 'neutral', label: t('c_legislature_pages.bills.status_withdrawn', 'Withdrawn') },
};

const columns = [
    { key: 'title', label: t('c_legislature_pages.bills.col_bill', 'Bill') },
    { key: 'sponsor', label: t('c_legislature_pages.bills.col_sponsor', 'Sponsor') },
    { key: 'act_type', label: t('c_legislature_pages.bills.col_act_type', 'Act type') },
    { key: 'status', label: t('c_legislature_pages.bills.col_status', 'Status') },
    { key: 'scale_label', label: t('c_legislature_pages.bills.col_scale', 'Scale') },
];

/* ------------------------------------------------------ introduction --- */
const query = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const presetSetting = query.get('setting');
/* Phase E Path 1 (IO-3): a remedial bill deep-linked from the Art. IV §5
   tracker carries the challenge id, so the enacted bill closes that challenge
   (ConstitutionalChallengeService::onRemedialEnactment). */
const presetChallenge = query.get('targets_challenge_id');
const introOpen = ref(query.get('intro') === '1' || presetSetting !== null || presetChallenge !== null);

const form = useForm({
    title: '',
    law_text: '',
    act_type: presetSetting ? 'setting_change' : 'ordinary',
    scale: [props.legislature.jurisdiction.id],
    scope_judiciary_id: '',
    targets_setting_key: presetSetting ?? '',
    proposed_value: '',
    targets_challenge_id: presetChallenge ?? '',
});

const isSettingBill = computed(() => form.act_type === 'setting_change');
const settingLocked = presetSetting !== null;

/* F-LEG-028 — cultural-institution recognition (a supermajority chamber act;
   the powerless institution row is created only on adoption). */
const culturalOpen = ref(false);
const culturalForm = useForm({ name: '', description: '' });
function submitCultural() {
    culturalForm.post(`/legislatures/${props.legislature.id}/cultural-institutions`, {
        preserveScroll: true,
        onSuccess: () => { culturalForm.reset(); culturalOpen.value = false; },
    });
}

const selectedSetting = computed(() =>
    props.introForm.settingKeys.find((s) => s.key === form.targets_setting_key) ?? null,
);

const actTypeGloss = computed(
    () => props.introForm.actTypes.find((t) => t.value === form.act_type)?.threshold_gloss ?? '',
);

/* The LIVE bounds pre-flight — a pure validator check, debounced. */
const preflight = ref(null);
let preflightTimer = null;

watch(
    () => [form.targets_setting_key, form.proposed_value, form.act_type],
    () => {
        preflight.value = null;
        if (!isSettingBill.value || !form.targets_setting_key || form.proposed_value === '') return;
        clearTimeout(preflightTimer);
        preflightTimer = setTimeout(async () => {
            try {
                const response = await fetch(`/legislatures/${props.legislature.id}/bills/validate`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        setting_key: form.targets_setting_key,
                        value: form.proposed_value,
                    }),
                });
                preflight.value = await response.json();
            } catch {
                preflight.value = null;
            }
        }, 350);
    },
);

function submit() {
    form.transform((data) => ({
        ...data,
        form_id: 'F-LEG-003',
        scope_judiciary_id: data.scope_judiciary_id || null,
        targets_setting_key: isSettingBill.value ? data.targets_setting_key : null,
        proposed_value: isSettingBill.value ? data.proposed_value : null,
    }));
    form.post(`/legislatures/${props.legislature.id}/bills`, {
        preserveScroll: true,
        onSuccess: () => form.reset('title', 'law_text', 'proposed_value'),
    });
}

function boundsLabel(bounds) {
    if (!bounds) return 'engine-validated';
    if (bounds.allowed) return `allowed: ${JSON.stringify(bounds.allowed)}`;
    return `hardened range [${bounds.min}, ${bounds.max}]`;
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_legislature_pages.bills.title', { name: legislature.name })">
        <template #intro>
            {{ t('c_legislature_pages.bills.intro', 'Every bill declares its scale (which jurisdictions are bound) and scope (which court level hears disputes) at introduction. Acts pass by majority of all serving members, and some act types require a supermajority.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" :title="t('c_legislature_pages.bills.rejected_title', 'Rejected by the Constitutional Engine.')">
            {{ constitutionError }}
            <span class="citation">{{ t('c_legislature_pages.bills.rejected_cite', 'recorded as a rejected=true chain entry — rejections are first-class records') }}</span>
        </Banner>

        <!-- ============================================ lifecycle ======= -->
        <Card as="section" :title="t('c_legislature_pages.bills.lifecycle_title', 'Bill lifecycle (ESM-07)')">
            <StateStrip :states="machine" :aria-label="t('c_legislature_pages.bills.lifecycle_aria', 'Bill state machine')" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_legislature_pages.bills.lifecycle_gloss', 'Bicameral chambers pass only when committee AND floor votes each adopt per kind (Art. V §3 · ledger #q7); the lifecycle consumes the vote engine\'s outcome.') }}
            </p>
        </Card>

        <!-- ============================================= registry ======= -->
        <Card as="section" :title="t('c_legislature_pages.bills.registry_title', 'Registry')">
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <label class="field-label" for="bill-status-filter" style="margin-block-end: 0">{{ t('c_legislature_pages.bills.filter_status', 'Status') }}</label>
                <select id="bill-status-filter" v-model="statusFilter" class="select" style="inline-size: auto">
                    <option value="">{{ t('c_legislature_pages.bills.filter_all', 'all') }}</option>
                    <option v-for="status in filters.status" :key="status" :value="status">{{ status }}</option>
                </select>
                <label class="field-label" for="bill-type-filter" style="margin-block-end: 0">{{ t('c_legislature_pages.bills.filter_act_type', 'Act type') }}</label>
                <select id="bill-type-filter" v-model="typeFilter" class="select" style="inline-size: auto">
                    <option value="">{{ t('c_legislature_pages.bills.filter_all', 'all') }}</option>
                    <option v-for="type in filters.act_type" :key="type" :value="type">{{ type }}</option>
                </select>
            </div>

            <p v-if="!filteredBills.length" class="gloss">
                {{ bills.length === 0
                    ? t('c_legislature_pages.bills.empty_none', 'No bills introduced this term — any member may introduce (F-LEG-003).')
                    : t('c_legislature_pages.bills.empty_no_match', 'No bills match the filter.') }}
            </p>

            <DataTable v-else :columns="columns" :rows="filteredBills" row-key="id" :caption="t('c_legislature_pages.bills.table_caption', 'Bills of this chamber')">
                <template #cell-title="{ row }">
                    <Link :href="`/bills/${row.id}`"><strong>{{ row.title }}</strong></Link>
                    <span v-if="row.committee" class="cc-small" style="display: block">
                        {{ t('c_legislature_pages.bills.committee_row', { name: row.committee.name }) }}
                    </span>
                </template>
                <template #cell-sponsor="{ row }">{{ row.sponsor.name }}</template>
                <template #cell-act_type="{ row }">
                    <span class="mono">{{ row.act_type }}</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="STATUS_TONES[row.status]?.tone ?? 'neutral'">
                        {{ STATUS_TONES[row.status]?.label ?? row.status }}
                    </StatusBadge>
                    <Link
                        v-if="row.enacted_law"
                        :href="row.enacted_law.href"
                        style="margin-inline-start: var(--space-2)"
                    >{{ row.enacted_law.act_number }}</Link>
                    <!-- Art. IV §5 challenge feed — a bill's enacted law under challenge. -->
                    <Link
                        v-if="row.challenge"
                        :href="row.challenge.href"
                        style="margin-inline-start: var(--space-2)"
                        :title="t('c_legislature_pages.bills.challenge_title', { suffix: row.challenge.count > 1 ? ` (${row.challenge.count})` : '' })"
                    >
                        <StatusBadge :tone="row.challenge.active ? 'danger' : 'neutral'">{{ t('c_legislature_pages.bills.challenged_badge', 'Challenged') }}</StatusBadge>
                    </Link>
                </template>
            </DataTable>
        </Card>

        <!-- ========================================== introduction ====== -->
        <Card v-if="can.introduce" as="section">
            <template #title>
                <h2>
                    {{ t('c_legislature_pages.bills.introduce_h2', 'Introduce a bill') }}
                    <Btn variant="secondary" size="sm" style="margin-inline-start: var(--space-2)" @click="introOpen = !introOpen">
                        {{ introOpen ? t('c_legislature_pages.bills.toggle_hide', 'Hide') : t('c_legislature_pages.bills.toggle_open', 'Open the form') }}
                    </Btn>
                </h2>
            </template>

            <FormCard
                v-if="introOpen && formMeta('F-LEG-003')"
                :form="formMeta('F-LEG-003')"
                :inertia-form="form"
                :submit-label="t('c_legislature_pages.bills.submit_introduce', 'Introduce bill')"
                :processing-label="t('c_legislature_pages.bills.processing_introduce', 'Introducing…')"
                @submit="submit"
            >
                <p
                    v-if="form.targets_challenge_id"
                    role="status"
                    class="citation"
                    style="margin-block-end: var(--space-3)"
                >
                    {{ t('c_legislature_pages.bills.challenge_note', 'This bill answers a constitutional challenge (Art. IV §5.3, Path 1). Enacting it within the remedy timeframe closes the challenge.') }}
                    <a :href="`/constitutional-challenges/${form.targets_challenge_id}`" data-no-i18n>
                        Open the challenge →
                    </a>
                </p>

                <Field :label="t('c_legislature_pages.bills.field_title', 'Title')" :error="form.errors.title" required>
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="form.title"
                            class="field-input"
                            type="text"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>

                <Field
                    :label="t('c_legislature_pages.bills.field_law_text', 'Law text')"
                    :hint="t('c_legislature_pages.bills.hint_law_text', 'The binding text — versioned at introduction (v1), amended only by adopted motions.')"
                    :error="form.errors.law_text"
                    required
                >
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="form.law_text"
                            class="field-input"
                            rows="5"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>

                <Field
                    :label="t('c_legislature_pages.bills.field_act_type', 'Act type')"
                    :hint="actTypeGloss"
                    :error="form.errors.act_type"
                >
                    <template #control="{ id }">
                        <select :id="id" v-model="form.act_type" class="select" :disabled="settingLocked">
                            <option v-for="type in introForm.actTypes" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </select>
                    </template>
                </Field>

                <Field
                    :label="t('c_legislature_pages.bills.field_scale', 'Scale — jurisdictions bound')"
                    :hint="t('c_legislature_pages.bills.hint_scale', 'Cannot exceed this legislature\'s authority — a parent act may bind named constituent jurisdictions; engine-validated against the full subtree. Fixed at introduction (Art. V §4).')"
                    :error="form.errors.scale"
                >
                    <template #control="{ id }">
                        <select :id="id" v-model="form.scale" class="select" multiple size="4">
                            <option v-for="option in introForm.scaleOptions" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                    </template>
                </Field>

                <Field
                    :label="t('c_legislature_pages.bills.field_scope', 'Scope — which judiciary hears disputes')"
                    :hint="t('c_legislature_pages.bills.hint_scope', 'Phase C lists the forming judiciary stubs honestly; leave blank for the default.')"
                    :error="form.errors.scope_judiciary_id"
                >
                    <template #control="{ id }">
                        <select :id="id" v-model="form.scope_judiciary_id" class="select">
                            <option value="">{{ t('c_legislature_pages.bills.scope_default', 'default judiciary (forming · Phase E)') }}</option>
                            <option v-for="option in introForm.scopeOptions" :key="option.id" :value="option.id">
                                {{ option.label }}
                            </option>
                        </select>
                    </template>
                </Field>

                <!-- setting_change reveals the key + value + live pre-flight -->
                <template v-if="isSettingBill">
                    <Field
                        :label="t('c_legislature_pages.bills.field_setting_key', 'Setting key')"
                        :hint="settingLocked ? t('c_legislature_pages.bills.hint_setting_locked', 'Pre-targeted from the settings register — key locked.') : t('c_legislature_pages.bills.hint_setting_key', 'The amendable constitutional setting this bill changes.')"
                        :error="form.errors.targets_setting_key"
                    >
                        <template #control="{ id }">
                            <select :id="id" v-model="form.targets_setting_key" class="select" :disabled="settingLocked">
                                <option value="">{{ t('c_legislature_pages.bills.setting_pick', '— pick a key —') }}</option>
                                <option v-for="setting in introForm.settingKeys" :key="setting.key" :value="setting.key">
                                    {{ setting.key }}
                                </option>
                            </select>
                        </template>
                    </Field>

                    <p v-if="selectedSetting" class="citation" data-no-i18n>
                        current value: <strong>{{ selectedSetting.current ?? '(inherited default)' }}</strong> ·
                        {{ boundsLabel(selectedSetting.bounds) }}
                        <template v-if="selectedSetting.bounds?.citation"> · {{ selectedSetting.bounds.citation }}</template>
                    </p>

                    <Field
                        :label="t('c_legislature_pages.bills.field_proposed_value', 'Proposed value')"
                        :hint="t('c_legislature_pages.bills.hint_proposed_value', 'Validated live against the hardened bounds — and again pre-vote and at enactment.')"
                        :error="form.errors.proposed_value ?? form.errors.constitution"
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.proposed_value"
                                class="field-input"
                                style="inline-size: 10rem"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <Banner v-if="preflight && preflight.ok" tone="info" role="status" :title="t('c_legislature_pages.bills.preflight_ok_title', 'In range — the bill may proceed to a vote.')">
                        {{ t('c_legislature_pages.bills.preflight_ok_body', 'Proceeds to the bill flow · F-LEG-031 · WF-LEG-14 · Art. VII.') }}
                    </Banner>
                    <Banner v-else-if="preflight && !preflight.ok" tone="emergency" :title="t('c_legislature_pages.bills.preflight_bad_title', 'Rejected pre-vote — outside hardened bounds.')">
                        {{ preflight.message }}
                        {{ t('c_legislature_pages.bills.preflight_bad_body', 'No UI, admin panel, or legislative act can carry an out-of-range value.') }}
                        <span class="citation" data-no-i18n>{{ preflight.citation }} · hardened · WF-LEG-14</span>
                    </Banner>
                </template>
            </FormCard>
        </Card>

        <!-- F-LEG-028 — recognise a cultural institution (supermajority chamber act) -->
        <Card v-if="can.introduce" as="section">
            <template #title>
                <h2>
                    {{ t('c_legislature_pages.bills.cultural_h2', 'Recognise a cultural institution') }}
                    <Btn variant="secondary" size="sm" style="margin-inline-start: var(--space-2)" @click="culturalOpen = !culturalOpen">
                        {{ culturalOpen ? t('c_legislature_pages.bills.toggle_hide', 'Hide') : t('c_legislature_pages.bills.toggle_open', 'Open the form') }}
                    </Btn>
                </h2>
            </template>
            <p class="gloss">
                {{ t('c_legislature_pages.bills.cultural_gloss', 'A recognition vote (F-LEG-028) opens a supermajority chamber decision. The institution it names holds no power — recognition is honorary — and the record is created only if the chamber adopts it.') }} <span class="citation">Art. V §2</span>
            </p>
            <FormCard
                v-if="culturalOpen && formMeta('F-LEG-028')"
                :form="formMeta('F-LEG-028')"
                :inertia-form="culturalForm"
                :submit-label="t('c_legislature_pages.bills.submit_cultural', 'Propose recognition')"
                :processing-label="t('c_legislature_pages.bills.processing_cultural', 'Proposing…')"
                @submit="submitCultural"
            >
                <Field :label="t('c_legislature_pages.bills.field_name', 'Name')" :error="culturalForm.errors.name" required>
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="culturalForm.name"
                            class="field-input"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                            maxlength="200"
                        />
                    </template>
                </Field>
                <Field :label="t('c_legislature_pages.bills.field_description', 'Description (optional)')" :error="culturalForm.errors.description">
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="culturalForm.description"
                            class="field-input"
                            rows="3"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>
            </FormCard>
        </Card>

        <Card v-else as="section" :title="t('c_legislature_pages.bills.introduction_title', 'Introduction')">
            <p class="gloss">
                {{ t('c_legislature_pages.bills.introduction_gloss', 'Any seated member of this chamber may introduce a bill (F-LEG-003) — you hold no seat here. Reading is public: legislature business is public record · Art. II §2.') }}
            </p>
        </Card>
    </PageScaffold>
</template>
