<script setup>
/**
 * Support/Ticket — one report's detail + operator triage (design contract:
 * mockups/v3/support/ticket.html; lifecycle ruling §10 item 7).
 *
 * Visible to the report's own reporter (track your report) or any operator.
 * The header shows where it stands and where it routed; a route note links the
 * off-queue paths (abuse → moderation & legal, translation → the review queue).
 * Operators get a small triage form (status + severity) — an attributed model
 * write, NOT an engine filing, and it removes nothing.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    isOperator: { type: Boolean, default: false },
    statuses: { type: Array, default: () => [] },
    severities: { type: Array, default: () => [] },
    report: { type: Object, required: true },
});

const { t } = useI18n();

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

const STATUS_TONE = {
    open: 'info', triaged: 'info', in_progress: 'warning',
    resolved: 'success', closed: 'neutral', wont_fix: 'neutral',
};

const routeNote = computed(() => {
    if (props.report.route_target === 'moderation') {
        return { text: t('c_front.ticket.route_moderation_text', 'This report rides the moderation & legal floor — off the tech-support queue. It removes nothing; content removal follows the constitutional carve-outs (F-SOC-003).'), href: '/operator/moderation', label: t('c_front.ticket.route_moderation_label', 'Moderation & the legal floor') };
    }
    if (props.report.route_target === 'translation') {
        return { text: t('c_front.ticket.route_translation_text', 'Routed to translation support.'), href: '/system/translations', label: t('c_front.ticket.route_translation_label', 'The translation review queue') };
    }
    return null;
});

const form = useForm({
    status: props.report.status,
    severity: props.report.severity ?? '',
});

function saveTriage() {
    form.post(`/support/ticket/${props.report.public_id}`, { preserveScroll: true });
}

function dateOf(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_front.ticket.page_title', { id: report.public_id })">
        <template #intro>
            {{ report.subject || t('c_front.ticket.default_subject', 'A support report.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- ─────────────────────────────────────────────── the header ── -->
        <p class="cluster" style="gap: var(--space-2)">
            <StatusBadge :tone="STATUS_TONE[report.status] ?? 'neutral'">{{ plainState(report.status) }}</StatusBadge>
            <StatusBadge tone="neutral">{{ report.category_label }}</StatusBadge>
            <StatusBadge v-if="report.severity" tone="warning" data-no-i18n>{{ report.severity }}</StatusBadge>
            <span class="citation">{{ t('c_front.ticket.routed_to', { target: report.route_label }) }}</span>
        </p>

        <Banner v-if="routeNote" tone="info">
            {{ routeNote.text }}
            <Link :href="routeNote.href">{{ routeNote.label }} →</Link>
        </Banner>

        <!-- ──────────────────────────────────────────── what was said ── -->
        <Card as="section" :title="t('c_front.ticket.report_card', 'The report')">
            <p style="white-space: pre-wrap">{{ report.body }}</p>
            <dl class="stack" style="gap: var(--space-1); margin-block-start: var(--space-4)">
                <div v-if="isOperator && report.reporter">
                    <span class="citation">{{ t('c_front.ticket.filed_by', 'Filed by:') }}</span> {{ report.reporter }}
                </div>
                <div v-if="report.ref">
                    <span class="citation">{{ t('c_front.ticket.from_page', 'From page:') }}</span> <span data-no-i18n>{{ report.ref }}</span>
                </div>
                <div><span class="citation">{{ t('c_front.ticket.filed', 'Filed:') }}</span> <span data-no-i18n>{{ dateOf(report.created_at) }}</span></div>
                <div><span class="citation">{{ t('c_front.ticket.last_update', 'Last update:') }}</span> <span data-no-i18n>{{ dateOf(report.updated_at) }}</span></div>
            </dl>
        </Card>

        <!-- ─────────────────────────────────────── operator triage ── -->
        <Card v-if="isOperator" as="section" :title="t('c_front.ticket.triage', 'Triage')">
            <form class="stack" @submit.prevent="saveTriage">
                <div class="cluster" style="gap: var(--space-4); align-items: flex-end">
                    <Field :label="t('c_front.ticket.status_label', 'Status')" :error="form.errors.status">
                        <template #control="{ id }">
                            <select :id="id" v-model="form.status" class="select">
                                <option v-for="s in statuses" :key="s" :value="s">{{ plainState(s) }}</option>
                            </select>
                        </template>
                    </Field>
                    <Field :label="t('c_front.ticket.severity_label', 'Severity')" :error="form.errors.severity">
                        <template #control="{ id }">
                            <select :id="id" v-model="form.severity" class="select">
                                <option value="">{{ t('c_front.ticket.severity_none', '— none —') }}</option>
                                <option v-for="s in severities" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </template>
                    </Field>
                    <Btn type="submit" variant="primary" :disabled="form.processing">{{ t('c_front.ticket.save', 'Save') }}</Btn>
                </div>
                <p class="citation">{{ t('c_front.ticket.triage_note', 'Triage is an attributed record change — it routes and tracks, it never removes content.') }}</p>
            </form>
        </Card>

        <p>
            <Link href="/support/tickets">{{ isOperator ? t('c_front.ticket.back_to_queue', '← Back to the queue') : t('c_front.ticket.back_to_reports', '← Back to your reports') }}</Link>
        </p>
    </PageScaffold>
</template>
