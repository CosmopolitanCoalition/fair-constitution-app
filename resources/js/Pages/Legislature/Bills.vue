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
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

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
    introduced: { tone: 'info', label: 'Introduced' },
    referred: { tone: 'info', label: 'Referred' },
    in_committee: { tone: 'info', label: 'In committee' },
    reported: { tone: 'info', label: 'Reported' },
    tabled: { tone: 'neutral', label: 'Tabled' },
    on_floor: { tone: 'warning', label: 'On floor' },
    passed: { tone: 'success', label: 'Passed' },
    failed: { tone: 'danger', label: 'Failed' },
    enacted: { tone: 'success', label: 'Enacted · Published' },
    withdrawn: { tone: 'neutral', label: 'Withdrawn' },
};

const columns = [
    { key: 'title', label: 'Bill' },
    { key: 'sponsor', label: 'Sponsor' },
    { key: 'act_type', label: 'Act type' },
    { key: 'status', label: 'Status' },
    { key: 'scale_label', label: 'Scale' },
];

/* ------------------------------------------------------ introduction --- */
const query = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const presetSetting = query.get('setting');
const introOpen = ref(query.get('intro') === '1' || presetSetting !== null);

const form = useForm({
    title: '',
    law_text: '',
    act_type: presetSetting ? 'setting_change' : 'ordinary',
    scale: [props.legislature.jurisdiction.id],
    scope_judiciary_id: '',
    targets_setting_key: presetSetting ?? '',
    proposed_value: '',
});

const isSettingBill = computed(() => form.act_type === 'setting_change');
const settingLocked = presetSetting !== null;

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
    <PageScaffold :surface="surface" :title="`Bills — ${legislature.name}`">
        <template #intro>
            Every bill this chamber has seen, end to end: introduction (scale &amp; scope fixed
            there — Art. V §4), committee, floor, enactment as a versioned law. Failed bills
            archive with their public casts and explanations.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" title="Rejected by the Constitutional Engine.">
            {{ constitutionError }}
            <span class="citation">recorded as a rejected=true chain entry — rejections are first-class records</span>
        </Banner>

        <!-- ============================================ lifecycle ======= -->
        <Card as="section" title="Bill lifecycle (ESM-07)">
            <StateStrip :states="machine" aria-label="Bill state machine" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Bicameral chambers pass only when committee AND floor votes each adopt per
                kind (Art. V §3 · ledger #q7); the lifecycle consumes the vote engine's outcome.
            </p>
        </Card>

        <!-- ============================================= registry ======= -->
        <Card as="section" title="Registry">
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <label class="field-label" for="bill-status-filter" style="margin-block-end: 0">Status</label>
                <select id="bill-status-filter" v-model="statusFilter" class="select" style="inline-size: auto">
                    <option value="">all</option>
                    <option v-for="status in filters.status" :key="status" :value="status">{{ status }}</option>
                </select>
                <label class="field-label" for="bill-type-filter" style="margin-block-end: 0">Act type</label>
                <select id="bill-type-filter" v-model="typeFilter" class="select" style="inline-size: auto">
                    <option value="">all</option>
                    <option v-for="type in filters.act_type" :key="type" :value="type">{{ type }}</option>
                </select>
            </div>

            <p v-if="!filteredBills.length" class="gloss">
                {{ bills.length === 0
                    ? 'No bills introduced this term — any member may introduce (F-LEG-003).'
                    : 'No bills match the filter.' }}
            </p>

            <DataTable v-else :columns="columns" :rows="filteredBills" row-key="id" caption="Bills of this chamber">
                <template #cell-title="{ row }">
                    <Link :href="`/bills/${row.id}`"><strong>{{ row.title }}</strong></Link>
                    <span v-if="row.committee" class="cc-small" style="display: block">
                        committee: {{ row.committee.name }}
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
                </template>
            </DataTable>
        </Card>

        <!-- ========================================== introduction ====== -->
        <Card v-if="can.introduce" as="section">
            <template #title>
                <h2>
                    Introduce a bill
                    <Btn variant="secondary" size="sm" style="margin-inline-start: var(--space-2)" @click="introOpen = !introOpen">
                        {{ introOpen ? 'Hide' : 'Open the form' }}
                    </Btn>
                </h2>
            </template>

            <FormCard
                v-if="introOpen && formMeta('F-LEG-003')"
                :form="formMeta('F-LEG-003')"
                :inertia-form="form"
                submit-label="Introduce bill"
                processing-label="Introducing…"
                @submit="submit"
            >
                <Field label="Title" :error="form.errors.title" required>
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
                    label="Law text"
                    hint="The binding text — versioned at introduction (v1), amended only by adopted motions."
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
                    label="Act type"
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
                    label="Scale — jurisdictions bound"
                    hint="Cannot exceed this legislature's authority — a parent act may bind named constituent jurisdictions; engine-validated against the full subtree. Fixed at introduction (Art. V §4)."
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
                    label="Scope — which judiciary hears disputes"
                    hint="Phase C lists the forming judiciary stubs honestly; leave blank for the default."
                    :error="form.errors.scope_judiciary_id"
                >
                    <template #control="{ id }">
                        <select :id="id" v-model="form.scope_judiciary_id" class="select">
                            <option value="">default judiciary (forming · Phase E)</option>
                            <option v-for="option in introForm.scopeOptions" :key="option.id" :value="option.id">
                                {{ option.label }}
                            </option>
                        </select>
                    </template>
                </Field>

                <!-- setting_change reveals the key + value + live pre-flight -->
                <template v-if="isSettingBill">
                    <Field
                        label="Setting key"
                        :hint="settingLocked ? 'Pre-targeted from the settings register — key locked.' : 'The amendable constitutional setting this bill changes.'"
                        :error="form.errors.targets_setting_key"
                    >
                        <template #control="{ id }">
                            <select :id="id" v-model="form.targets_setting_key" class="select" :disabled="settingLocked">
                                <option value="">— pick a key —</option>
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
                        label="Proposed value"
                        hint="Validated live against the hardened bounds — and again pre-vote and at enactment."
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

                    <Banner v-if="preflight && preflight.ok" tone="info" role="status" title="In range — the bill may proceed to a vote.">
                        Proceeds to the bill flow · F-LEG-031 · WF-LEG-14 · Art. VII.
                    </Banner>
                    <Banner v-else-if="preflight && !preflight.ok" tone="emergency" title="Rejected pre-vote — outside hardened bounds.">
                        {{ preflight.message }}
                        No UI, admin panel, or legislative act can carry an out-of-range value.
                        <span class="citation" data-no-i18n>{{ preflight.citation }} · hardened · WF-LEG-14</span>
                    </Banner>
                </template>
            </FormCard>
        </Card>
        <Card v-else as="section" title="Introduction">
            <p class="gloss">
                Any seated member of this chamber may introduce a bill (F-LEG-003) —
                you hold no seat here. Reading is public: legislature business is
                public record · Art. II §2.
            </p>
        </Card>
    </PageScaffold>
</template>
