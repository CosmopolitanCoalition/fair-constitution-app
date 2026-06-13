<script setup>
/**
 * Executive/DepartmentReporting — FE-D5 (PHASE_D_DESIGN_frontend.md §B.4;
 * surface executive/department-reporting).
 *
 * The department's two F-BOG registers, publicly readable:
 *   • Rules register (F-BOG-001) — each row carries its enabling-act chip;
 *     an emergency-enabled rule renders the warning chip "expires with the
 *     power · CLK-03" and flips to `expired` when CLK-03 fires (the
 *     cross-domain cascade made visible).
 *   • Report filings (F-BOG-002 → public_records) — recipients fixed
 *     "Executive + legislature"; filed rows link their public-record entry.
 *
 * Filing is gated R-18 of THIS board (`viewerIsGovernor`); the engine is
 * the boundary — non-governors see both registers read-only with the
 * "implement, don't exceed" rule card rendered regardless. Every status /
 * version_no shown is an engine snapshot off the row.
 */
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.4 department header. */
    department: { type: Object, required: true },
    /** ESM-17 (department_board) — PHP-owned. */
    machine: { type: Array, default: () => [] },
    viewerIsGovernor: { type: Boolean, default: false },
    /** [{ id, rule_code, name, status, version_no, enabling:{type,label,href,expires_with_power}, note }] */
    rules: { type: Array, default: () => [] },
    /** [{ id, kind, label, recipients, due_on, filed_at, status, record_href }] */
    reports: { type: Array, default: () => [] },
    /** { enablingOptions: [{ type, id, label }] } — charter + bills + ACTIVE powers, server-filtered. */
    ruleForm: { type: Object, default: () => ({ enablingOptions: [] }) },
    can: { type: Object, default: () => ({ fileRule: false, fileReport: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* ----------------------------------------------------------- badges ----- */
const RULE_BADGES = {
    draft: ['neutral', 'file-text', 'Draft'],
    in_force: ['success', 'check', 'In force'],
    superseded: ['neutral', 'minus', 'Superseded'],
    expired: ['danger', 'x', 'Expired'],
};
function ruleBadge(status) {
    const [tone, icon, text] = RULE_BADGES[status] ?? ['neutral', null, status];
    return { tone, icon, text };
}

const REPORT_BADGES = {
    due: ['info', 'clock', 'Due'],
    due_soon: ['warning', 'clock', 'Due soon'],
    filed: ['success', 'check', 'Filed'],
    overdue: ['danger', 'alert-triangle', 'Overdue'],
};
function reportBadge(status) {
    const [tone, icon, text] = REPORT_BADGES[status] ?? ['neutral', null, status];
    return { tone, icon, text };
}

function fmtDate(value) {
    if (!value) return '—';
    try {
        return new Date(value).toLocaleDateString();
    } catch {
        return value;
    }
}

/* --------------------------------------------------------- columns ------ */
const ruleColumns = [
    { key: 'rule_code', label: 'Rule', mono: true },
    { key: 'name', label: 'Name' },
    { key: 'enabling', label: 'Enabling basis' },
    { key: 'version_no', label: 'Ver.', align: 'right', mono: true },
    { key: 'status', label: 'Status' },
];
const reportColumns = [
    { key: 'label', label: 'Report' },
    { key: 'recipients', label: 'Recipients' },
    { key: 'due_on', label: 'Due' },
    { key: 'filed_at', label: 'Filed' },
    { key: 'status', label: 'Status' },
];

/* -------------------------------------------------------- F-BOG-001 ----- */
const hasEnablingOptions = computed(() => (props.ruleForm.enablingOptions?.length ?? 0) > 0);

const ruleFiling = useForm({
    name: '',
    text: '',
    // One select feeds both engine fields; the submit transform splits it.
    enabling_basis: '',
    enabling_type: '',
    enabling_id: '',
});

function submitRule() {
    ruleFiling
        .transform((data) => {
            // The select carries "type:id" so one control covers all bases;
            // keep form_id (FormCard's canonical merge) on the payload.
            const [type, ...idParts] = (data.enabling_basis ?? '').split(':');
            return {
                form_id: 'F-BOG-001',
                name: data.name,
                text: data.text,
                enabling_type: type ?? '',
                enabling_id: idParts.join(':'),
            };
        })
        .post(`/departments/${props.department.id}/rules`, {
            preserveScroll: true,
            onSuccess: () => ruleFiling.reset(),
        });
}

/* -------------------------------------------------------- F-BOG-002 ----- */
const reportFiling = useForm({
    kind: 'periodic',
    period_label: '',
    body: '',
});

function submitReport() {
    reportFiling.post(`/departments/${props.department.id}/reports`, {
        preserveScroll: true,
        onSuccess: () => reportFiling.reset('body', 'period_label'),
    });
}

/* The basis select binds to a single string the transform splits apart. */
const ruleBasis = computed({
    get: () => ruleFiling.enabling_basis ?? '',
    set: (v) => {
        ruleFiling.enabling_basis = v;
    },
});
</script>

<template>
    <PageScaffold :surface="surface" :title="`Reporting — ${department.name}`">
        <template #intro>
            The department's implementation rules and its report obligations. Rules implement —
            they cannot exceed — the charter and the acts that enable them; reports file to the
            executive and the legislature and land on the public record. Every register here is
            publicly readable; only this board's seated governors (R-18) file.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ============================================ header ========== -->
        <Card as="section" :title="department.name">
            <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                <Stat :value="department.kind.replaceAll('_', ' ')" label="kind" />
                <Stat :value="department.worker_count" label="workers" />
                <Stat
                    :value="department.charter.reporting_interval_months ?? '—'"
                    label="reporting interval (months) — charter data, not a clock"
                />
            </div>
            <p v-if="machine.length" style="margin-block-start: var(--space-3)">
                <StateStrip :states="machine" :current="department.status" />
            </p>
            <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                <Link :href="department.detail_href">Department detail →</Link>
                <span v-if="department.charter.act_number" class="cluster" style="gap: var(--space-2)">
                    <FormChip form-id="F-LEG-016" />
                    <Link v-if="department.charter.href" :href="department.charter.href" class="tag-chip" data-no-i18n>
                        Act {{ department.charter.act_number }}
                    </Link>
                </span>
            </p>
        </Card>

        <!-- =========================== read-only posture (non-gov) ====== -->
        <Banner
            v-if="!viewerIsGovernor"
            tone="info"
            role="status"
            title="Registers are public; filing is the board's."
        >
            Both registers below read publicly. Filed by this department's seated governors
            (R-18) — the engine is the boundary, so the filing forms appear only for them.
        </Banner>

        <!-- ============================ rules register ================== -->
        <Card as="section" title="Implementation rules (F-BOG-001)">
            <p class="gloss" style="margin-block-end: var(--space-3)">
                Rules implement — they cannot exceed — the charter and the enabling acts.
                An emergency-enabled rule expires with its power; nothing rolls over silently.
            </p>

            <DataTable
                v-if="rules.length"
                :columns="ruleColumns"
                :rows="rules"
                row-key="id"
                caption="Department rules"
            >
                <template #cell-rule_code="{ row }">
                    <span class="mono" data-no-i18n>{{ row.rule_code }}</span>
                </template>
                <template #cell-name="{ row }">
                    <strong style="color: var(--gov-fg)">{{ row.name }}</strong>
                    <span v-if="row.note" class="citation" style="display: block">{{ row.note }}</span>
                </template>
                <template #cell-enabling="{ row }">
                    <a v-if="row.enabling" class="form-chip" :href="row.enabling.href">
                        <span class="form-id" data-no-i18n>
                            {{ row.enabling.type === 'emergency_power' ? 'EMERGENCY POWER' : 'ENABLING ACT' }}
                        </span>
                        {{ row.enabling.label }}
                    </a>
                    <span
                        v-if="row.enabling?.expires_with_power"
                        class="citation"
                        style="display: block; margin-block-start: var(--space-1)"
                    >
                        expires with the emergency power · CLK-03
                    </span>
                </template>
                <template #cell-version_no="{ row }">
                    <span class="mono">{{ row.version_no }}</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="ruleBadge(row.status).tone" :icon="ruleBadge(row.status).icon">
                        {{ ruleBadge(row.status).text }}
                    </StatusBadge>
                </template>
            </DataTable>

            <Banner v-else tone="info" role="status" title="No rules filed yet.">
                When a governor files the first rule, it appears here with the act that enables it.
                The implement-don't-exceed rule binds from the first rule.
            </Banner>
        </Card>

        <!-- ===================== F-BOG-001 filing form ================== -->
        <FormCard
            v-if="can.fileRule"
            :form="surface.forms.find((f) => f.id === 'F-BOG-001')"
            :inertia-form="ruleFiling"
            submit-label="File rule"
            processing-label="Filing…"
            :disabled="!hasEnablingOptions"
            @submit="submitRule"
        >
            <Field label="Rule name" :error="ruleFiling.errors.name" required>
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="ruleFiling.name"
                        class="field-input"
                        type="text"
                        maxlength="240"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <Field
                label="Rule text"
                hint="Rules implement — they cannot exceed — the charter and enabling acts."
                :error="ruleFiling.errors.text"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="ruleFiling.text"
                        class="field-input"
                        rows="4"
                        maxlength="20000"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>

            <Field
                label="Enabling basis"
                hint="Charter law, an in-force enabling act, or an ACTIVE emergency power — the engine rejects scope overruns with the citation."
                :error="ruleFiling.errors.enabling_id || ruleFiling.errors.enabling_type"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="ruleBasis"
                        class="select"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option value="" disabled>Select the enabling instrument…</option>
                        <option
                            v-for="opt in ruleForm.enablingOptions"
                            :key="`${opt.type}:${opt.id}`"
                            :value="`${opt.type}:${opt.id}`"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </template>
            </Field>

            <p class="citation" style="margin-block-start: var(--space-2)">
                catalog alias: F-GOV-001 · drafts publish for comment · emergency-enabled rules expire with the power (CLK-03).
            </p>
            <Banner
                v-if="!hasEnablingOptions"
                tone="warning"
                role="status"
                title="No live enabling instrument."
                style="margin-block-start: var(--space-2)"
            >
                There is no charter, in-force act, or active emergency power to cite yet — a rule
                cannot issue without a live instrument behind it (Art. III §2).
            </Banner>
        </FormCard>

        <!-- ============================ report register ================= -->
        <Card as="section" title="Report filings (F-BOG-002)">
            <p class="gloss" style="margin-block-end: var(--space-3)">
                Reports file to the executive AND the legislature, published to the public record.
                Filing a periodic report seeds the next obligation; a missed due date is swept to
                overdue (WF-EXE-09).
            </p>

            <DataTable
                v-if="reports.length"
                :columns="reportColumns"
                :rows="reports"
                row-key="id"
                caption="Department reports"
            >
                <template #cell-label="{ row }">
                    <strong style="color: var(--gov-fg)">{{ row.label }}</strong>
                    <span class="cc-small" style="display: block; color: var(--gov-fg-muted)">{{ row.kind }}</span>
                </template>
                <template #cell-due_on="{ row }">
                    <span class="mono">{{ fmtDate(row.due_on) }}</span>
                </template>
                <template #cell-filed_at="{ row }">
                    <template v-if="row.filed_at">
                        <span class="mono">{{ fmtDate(row.filed_at) }}</span>
                        <a v-if="row.record_href" :href="row.record_href" class="tag-chip" data-no-i18n style="display: inline-block; margin-block-start: var(--space-1)">
                            on the public record →
                        </a>
                    </template>
                    <span v-else class="gloss">not yet filed</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="reportBadge(row.status).tone" :icon="reportBadge(row.status).icon">
                        {{ reportBadge(row.status).text }}
                    </StatusBadge>
                </template>
            </DataTable>

            <Banner v-else tone="info" role="status" title="No report obligations yet.">
                The first periodic obligation seeds when the department begins operating and its
                charter sets a reporting interval.
            </Banner>
        </Card>

        <!-- ===================== F-BOG-002 filing form ================== -->
        <FormCard
            v-if="can.fileReport"
            :form="surface.forms.find((f) => f.id === 'F-BOG-002')"
            :inertia-form="reportFiling"
            submit-label="File report"
            processing-label="Filing…"
            @submit="submitReport"
        >
            <Field label="Report kind" :error="reportFiling.errors.kind" required>
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="reportFiling.kind"
                        class="select"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option value="periodic">Periodic — files the due obligation</option>
                        <option value="special">Special — an ad-hoc filing</option>
                    </select>
                </template>
            </Field>

            <Field
                v-if="reportFiling.kind === 'special'"
                label="Period label"
                hint="A short label for this special report."
                :error="reportFiling.errors.period_label"
            >
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="reportFiling.period_label"
                        class="field-input"
                        type="text"
                        maxlength="120"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <Field label="Report body" :error="reportFiling.errors.body" required>
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="reportFiling.body"
                        class="field-input"
                        rows="6"
                        maxlength="50000"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>

            <p class="citation" style="margin-block-start: var(--space-2)">
                catalog alias: F-GOV-002 · recipients fixed: Executive + legislature · published · WF-SYS-03.
            </p>
        </FormCard>

        <template #about>
            <p>
                Rules and reports are the Board of Governors' implementation layer (Art. III §4):
                rules carry the act that enables them and can never exceed it; reports keep the
                executive and the legislature informed and become public record. An emergency-
                enabled rule is bound to the life of its power — when CLK-03 expires the power,
                the rule expires with it.
            </p>
            <p>
                <HardenedChip>rules implement, they cannot exceed · Art. III §4</HardenedChip>
            </p>
        </template>
    </PageScaffold>
</template>
