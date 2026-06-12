<script setup>
/**
 * System/PublicRecords — FE-C11 (PHASE_C_DESIGN_frontend.md §B.15/§D).
 *
 * The CURATED, citizen-readable register — distinct from the raw audit
 * chain. LogRow reuse (seq, NO hash — hashes live on the audit page;
 * instead the trailing "sealed · audit #N" chip links the chain at that
 * seq) · FilterBar (the per-legislature filter is the citizen's view
 * into any chamber) · cursor pagination · F-LEG-006 statement composer
 * (R-09 — hidden entirely otherwise) · corrections render both entries
 * ("corrections append, never edit").
 */
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import ChipToggle from '@/Components/Ui/ChipToggle.vue';
import Field from '@/Components/Ui/Field.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    composer: { type: Object, default: () => ({ legislatures: [], subjects: [] }) },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const KIND_LABELS = {
    registration: 'Registration', residency: 'Residency', participation: 'Participation',
    statement: 'Statement', vote: 'Vote', bill: 'Bill', act: 'Act', minutes: 'Minutes',
    opinion: 'Opinion', certification: 'Certification', testimony: 'Testimony',
    violation: 'Violation', correction: 'Correction', other: 'Record',
};

/* ------------------------------------------------------------- filters -- */
const q = ref(props.filters.active.q ?? '');
const activeKinds = ref([...(props.filters.active.kinds ?? [])]);
const legislature = ref(props.filters.active.legislature ?? '');

function applyFilters() {
    router.get('/system/public-records', {
        q: q.value || undefined,
        kinds: activeKinds.value.length ? activeKinds.value : undefined,
        legislature: legislature.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function toggleKind(kind) {
    activeKinds.value = activeKinds.value.includes(kind)
        ? activeKinds.value.filter((k) => k !== kind)
        : [...activeKinds.value, kind];
    applyFilters();
}

function clearFilters() {
    q.value = '';
    activeKinds.value = [];
    legislature.value = '';
    applyFilters();
}

const hasFilters = computed(() => q.value !== '' || activeKinds.value.length > 0 || legislature.value !== '');

function loadOlder() {
    router.get('/system/public-records', {
        q: q.value || undefined,
        kinds: activeKinds.value.length ? activeKinds.value : undefined,
        legislature: legislature.value || undefined,
        cursor: props.records.next_cursor,
    }, { preserveState: true, preserveScroll: true });
}

/* ---------------------------------------------- composer (F-LEG-006) ---- */
const statement = useForm({
    legislature_id: props.composer.legislatures[0]?.id ?? null,
    body: '',
    subject: 'general', // 'general' | '{type}:{id}'
});

function submitStatement() {
    statement
        .transform((data) => {
            const [type, id] = data.subject === 'general' ? [null, null] : data.subject.split(':');
            return {
                form_id: 'F-LEG-006',
                legislature_id: data.legislature_id,
                body: data.body,
                subject_type: type,
                subject_id: id,
            };
        })
        .post(props.urls.statement, {
            preserveScroll: true,
            onSuccess: () => statement.reset('body', 'subject'),
        });
}

function viaChip(via) {
    return via.form ?? via.workflow ?? via.clock ?? null;
}

function dateOf(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Every statement, bill, vote, and explanation made in your jurisdictions — published
            the moment it is recorded, readable by anyone, and never edited in place. This is the
            surface the constitution calls "public and readily available records".
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner tone="info" icon="lock" title="This record is append-only.">
            Corrections append a superseding entry; nothing is deleted or rewritten. Every entry is
            sealed into the <Link href="/system/audit-chain">cryptographically chained audit log</Link>
            at commit time. <span class="citation">Art. II §2 · WF-SYS-03 · WF-SYS-04</span>
        </Banner>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="stats.total.toLocaleString()" label="entries on the record" accent />
            <Stat :value="stats.acts.toLocaleString()" label="acts" />
            <Stat :value="stats.votes.toLocaleString()" label="votes (with explanations)" />
            <Stat :value="stats.statements.toLocaleString()" label="statements" />
        </div>

        <!-- ==================================== filters ================== -->
        <FilterBar label="Filter the public record">
            <label class="cc-small" style="color: var(--gov-fg-muted)">
                <span class="visually-hidden">Search the record</span>
                <input
                    v-model="q"
                    class="field-input"
                    style="inline-size: 13rem; padding-block: var(--space-1)"
                    type="search"
                    placeholder="Search title or author"
                    @keyup.enter="applyFilters"
                    @change="applyFilters"
                />
            </label>
            <label class="cc-small" style="color: var(--gov-fg-muted)">
                <span class="visually-hidden">Legislature</span>
                <select v-model="legislature" class="select" style="inline-size: auto" @change="applyFilters">
                    <option value="">All legislatures</option>
                    <option v-for="l in filters.legislatures" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
            </label>
            <span class="eyebrow">Kind</span>
            <span class="cluster" style="gap: var(--space-1)">
                <ChipToggle
                    v-for="kind in filters.kinds"
                    :key="kind"
                    :pressed="activeKinds.includes(kind)"
                    @update:pressed="toggleKind(kind)"
                >{{ KIND_LABELS[kind] ?? kind }}</ChipToggle>
            </span>
            <Btn variant="ghost" size="sm" :disabled="!hasFilters" @click="clearFilters">Clear filters</Btn>
        </FilterBar>

        <!-- ==================================== the feed ================= -->
        <Card as="section" title="The record">
            <p class="citation" style="margin-block-end: var(--space-2)">stored as UTC · shown in your timezone</p>

            <div v-if="records.data.length" class="stack" style="gap: var(--space-1)" aria-live="polite">
                <LogRow v-for="record in records.data" :key="record.seq" :seq="record.seq.toLocaleString()">
                    <StatusBadge tone="neutral" icon="file-text">{{ KIND_LABELS[record.kind] ?? record.kind }}</StatusBadge>
                    <div style="flex: 1 1 18rem; min-inline-size: 0">
                        <strong style="color: var(--gov-fg)">{{ record.title }}</strong>
                        <span class="citation" style="display: block">
                            {{ record.actor_display }}
                            <template v-if="record.jurisdiction?.name"> · {{ record.jurisdiction.name }}</template>
                            <template v-if="viaChip(record.via)"> · via <span data-no-i18n>{{ viaChip(record.via) }}</span></template>
                            · {{ dateOf(record.published_at) }}
                            <template v-if="record.subject?.href"> · <Link :href="record.subject.href">{{ record.subject.label }} →</Link></template>
                            <template v-else-if="record.subject"> · {{ record.subject.label }}</template>
                        </span>
                        <span v-if="record.supersedes" class="citation" style="display: block">
                            supersedes <span data-no-i18n>#{{ record.supersedes.seq.toLocaleString() }}</span> —
                            corrections append, never edit; both entries stay visible.
                        </span>
                    </div>
                    <StatusBadge v-if="record.translations.total > 0" :tone="record.translations.done >= record.translations.total ? 'success' : 'warning'"
                        :title="record.translations.locales.map((l) => `${l.code}: ${l.quality}`).join(' · ')">
                        {{ record.translations.done }}/{{ record.translations.total }} languages
                    </StatusBadge>
                    <StatusBadge v-else tone="neutral" title="machine translation pipeline · Planned · Phase F">original</StatusBadge>
                    <Link
                        v-if="record.audit_seq !== null"
                        class="form-chip"
                        :href="`/system/audit-chain?seq=${record.audit_seq}`"
                        title="sealed into the audit chain at commit"
                    >sealed · audit <span class="form-id" data-no-i18n>#{{ record.audit_seq }}</span></Link>
                </LogRow>
            </div>
            <p v-else class="cc-small gloss">
                No records match
                {{ hasFilters ? 'the current filters — the empty view is the filter, not the record.' : '— the register fills as institutions act.' }}
            </p>

            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn v-if="records.next_cursor" variant="secondary" size="sm" @click="loadOlder">Older entries →</Btn>
                <span v-if="records.next_cursor" class="citation">cursor pagination — the record is append-only; pages never shift</span>
            </div>
        </Card>

        <!-- ==================================== composer (F-LEG-006) ===== -->
        <FormCard
            v-if="can.statement && formMeta('F-LEG-006')"
            :form="formMeta('F-LEG-006')"
            :inertia-form="statement"
            submit-label="Submit to the public record"
            @submit="submitStatement"
        >
            <Field v-if="composer.legislatures.length > 1" label="Chamber" :error="statement.errors.legislature_id">
                <template #control="{ id }">
                    <select :id="id" v-model="statement.legislature_id" class="select">
                        <option v-for="l in composer.legislatures" :key="l.id" :value="l.id">{{ l.name }}</option>
                    </select>
                </template>
            </Field>
            <Field
                label="Attach to"
                hint="Statements attach to the bill, session, or vote they explain — readers see them in context."
                :error="statement.errors.subject_type"
            >
                <template #control="{ id }">
                    <select :id="id" v-model="statement.subject" class="select">
                        <option value="general">General record (no attachment)</option>
                        <option v-for="s in composer.subjects" :key="`${s.type}:${s.id}`" :value="`${s.type}:${s.id}`">
                            {{ s.label }}
                        </option>
                    </select>
                </template>
            </Field>
            <Field
                label="Statement"
                hint="Once submitted, a statement can be superseded but never edited or withdrawn."
                :error="statement.errors.body ?? statement.errors.constitution"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="statement.body"
                        class="field-input"
                        rows="4"
                        placeholder="Your statement, explanation, or position — published verbatim and permanently."
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>
            <p class="citation" style="margin-block-end: var(--space-2)">
                entered verbatim into the immutable public record · WF-SYS-03 · sealed into the audit chain at commit
            </p>
        </FormCard>

        <!-- ==================================== hardened footer ========== -->
        <Card as="section" title="Public records vs the audit chain">
            <p class="cc-small">
                <strong>This page</strong> is the curated register citizens read — statements, votes
                with explanations, acts, certifications; corrections append superseding entries.
                {{ ' ' }}
                <Link href="/system/audit-chain">The audit chain</Link> is the raw hash-linked log
                auditors verify — every state transition including rejections, payload hashes, and
                chain verification; nothing ever supersedes there.
            </p>
            <p class="cc-small" style="margin-block-start: var(--space-2)">
                <HardenedChip>Record-keeping cannot be suspended under emergency powers · nothing is publishable-optional · Art. II §2 · WF-SYS-03</HardenedChip>
            </p>
        </Card>
    </PageScaffold>
</template>
