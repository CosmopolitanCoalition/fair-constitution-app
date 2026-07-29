<script setup>
/**
 * Support/Tickets — the lifecycle queue (design contract:
 * mockups/v3/support/tickets.html; lifecycle ruling §10 item 7).
 *
 * The missing read/triage door. A person sees the reports THEY filed (track
 * your report); an operator sees every report (the triage queue). Filter by
 * status (open = still needs attention, or a specific state) and by subject.
 * Rows link to the detail page. Abuse rides the moderation & legal floor —
 * flagged off-queue here, never silently hidden.
 */
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Field from '@/Components/Ui/Field.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    isOperator: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({ status: 'open', category: 'all' }) },
    statuses: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    openCount: { type: Number, default: 0 },
    totalCount: { type: Number, default: 0 },
    reports: { type: Array, default: () => [] },
});

const status = ref(props.filters.status ?? 'open');
const category = ref(props.filters.category ?? 'all');

function applyFilters() {
    router.get('/support/tickets', { status: status.value, category: category.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/* A tone for each lifecycle state, so colour never carries meaning alone. */
const STATUS_TONE = {
    open: 'info',
    triaged: 'info',
    in_progress: 'warning',
    resolved: 'success',
    closed: 'neutral',
    wont_fix: 'neutral',
};

const columns = [
    { key: 'public_id', label: 'Ref', mono: true },
    { key: 'subject', label: 'Subject' },
    { key: 'category_label', label: 'Kind' },
    { key: 'status', label: 'Status' },
    { key: 'route_label', label: 'Routed to' },
    { key: 'updated_at', label: 'Updated' },
];

function dateOf(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}
</script>

<template>
    <PageScaffold :surface="surface" :title="isOperator ? 'Support queue' : 'Your reports'">
        <template #intro>
            <template v-if="isOperator">
                Every report filed on this instance, newest activity first — the triage queue.
                Abuse and illegal-content reports route to the moderation &amp; legal floor and are
                flagged off-queue here.
            </template>
            <template v-else>
                The reports you have filed and where each one stands. You only ever see your own —
                filing is attributed, reading is private to you.
            </template>
        </template>

        <p class="cluster" style="gap: var(--space-3)">
            <StatusBadge tone="info">{{ openCount }} still open</StatusBadge>
            <span class="citation">of {{ totalCount }} total</span>
            <Link href="/support/report" class="citation">File a new report →</Link>
        </p>

        <!-- ───────────────────────────────────────────────── filters ── -->
        <Card as="section" title="Filter">
            <div class="cluster" style="gap: var(--space-4); align-items: flex-end">
                <Field label="Status">
                    <template #control="{ id }">
                        <select :id="id" v-model="status" class="select" @change="applyFilters">
                            <option value="open">Open (needs attention)</option>
                            <option value="all">All</option>
                            <option v-for="s in statuses" :key="s" :value="s">{{ plainState(s) }}</option>
                        </select>
                    </template>
                </Field>
                <Field label="Kind">
                    <template #control="{ id }">
                        <select :id="id" v-model="category" class="select" @change="applyFilters">
                            <option value="all">All kinds</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
                        </select>
                    </template>
                </Field>
            </div>
        </Card>

        <!-- ─────────────────────────────────────────────── the queue ── -->
        <DataTable
            v-if="reports.length"
            :columns="columns"
            :rows="reports"
            row-key="public_id"
            caption="Support reports, newest activity first"
        >
            <template #cell-public_id="{ row }">
                <Link :href="`/support/ticket/${row.public_id}`" data-no-i18n>{{ row.public_id }}</Link>
            </template>
            <template #cell-subject="{ row }">
                <Link :href="`/support/ticket/${row.public_id}`">{{ row.subject || '(no subject)' }}</Link>
                <span v-if="row.off_queue" class="planned-flag" style="margin-inline-start: var(--space-1)">off-queue</span>
                <span v-if="isOperator && row.reporter" class="citation" style="display: block">{{ row.reporter }}</span>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :tone="STATUS_TONE[row.status] ?? 'neutral'">{{ plainState(row.status) }}</StatusBadge>
                <span v-if="row.severity" class="citation" style="display: block" data-no-i18n>{{ row.severity }}</span>
            </template>
            <template #cell-updated_at="{ row }">
                <span data-no-i18n>{{ dateOf(row.updated_at) }}</span>
            </template>
        </DataTable>
        <p v-else class="gloss">
            No reports match this filter.
            <Link href="/support/report">File one →</Link>
        </p>
    </PageScaffold>
</template>
