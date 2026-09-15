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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
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

/* Phase-1 pilot (MASTER_PLAN): this page rides the v3 player chrome —
   floating header, tour-as-a-mode, bottom command bar (Menu + Learn). */
defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

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

const KIND_LABELS = computed(() => ({
    registration: t('c_system.public_records.kind_registration', 'Registration'),
    residency: t('c_system.public_records.kind_residency', 'Residency'),
    participation: t('c_system.public_records.kind_participation', 'Participation'),
    statement: t('c_system.public_records.kind_statement', 'Statement'),
    vote: t('c_system.public_records.kind_vote', 'Vote'),
    bill: t('c_system.public_records.kind_bill', 'Bill'),
    act: t('c_system.public_records.kind_act', 'Act'),
    minutes: t('c_system.public_records.kind_minutes', 'Minutes'),
    opinion: t('c_system.public_records.kind_opinion', 'Opinion'),
    certification: t('c_system.public_records.kind_certification', 'Certification'),
    testimony: t('c_system.public_records.kind_testimony', 'Testimony'),
    violation: t('c_system.public_records.kind_violation', 'Violation'),
    correction: t('c_system.public_records.kind_correction', 'Correction'),
    other: t('c_system.public_records.kind_other', 'Record'),
}));

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

/* W-0440: typed legislature search. The server never lists every chamber on
   the box; it answers a name prefix with at most 20 matches, and the page
   keeps the active legislature's name in filters.legislatures (one row). */
const legislatureOptions = ref([]);
const legislatureQuery = ref('');
const activeLegislatureName = computed(() => props.filters.legislatures.find((l) => l.id === legislature.value)?.name ?? '');
let searchTimer = null;
function searchLegislatures() {
    const term = legislatureQuery.value.trim();
    clearTimeout(searchTimer);
    if (term.length < 2) { legislatureOptions.value = []; return; }
    searchTimer = setTimeout(async () => {
        try {
            const res = await fetch('/api/public-records/legislatures?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
            legislatureOptions.value = res.ok ? (await res.json()).options ?? [] : [];
        } catch { legislatureOptions.value = []; }
    }, 250);
}
function pickLegislature() {
    const hit = legislatureOptions.value.find((l) => l.name === legislatureQuery.value.trim());
    if (!hit) return;
    legislature.value = hit.id;
    legislatureQuery.value = '';
    applyFilters();
}
function clearLegislature() {
    legislature.value = '';
    legislatureQuery.value = '';
    applyFilters();
}

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
            {{ t('c_system.public_records.intro', 'Every statement, bill, vote, and explanation made in your jurisdictions — published the moment it is recorded, readable by anyone, and never edited in place. This is the surface the constitution calls "public and readily available records".') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner tone="info" icon="lock" :title="t('c_system.public_records.append_only_title', 'This record is append-only.')">
            {{ t('c_system.public_records.append_only_a', 'Corrections append a superseding entry; nothing is deleted or rewritten. Every entry is sealed into the') }} <Link href="/system/audit-chain" class="prose-link">{{ t('c_system.public_records.chained_log_link', 'cryptographically chained audit log') }}</Link>
            {{ t('c_system.public_records.append_only_b', 'at commit time.') }} <span class="citation">Art. II §2 · WF-SYS-03 · WF-SYS-04</span>
        </Banner>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="stats.total.toLocaleString()" :label="t('c_system.public_records.stat_entries', 'entries on the record')" accent />
            <Stat :value="stats.acts.toLocaleString()" :label="t('c_system.public_records.stat_acts', 'acts')" />
            <Stat :value="stats.votes.toLocaleString()" :label="t('c_system.public_records.stat_votes', 'votes (with explanations)')" />
            <Stat :value="stats.statements.toLocaleString()" :label="t('c_system.public_records.stat_statements', 'statements')" />
        </div>

        <!-- ==================================== filters ================== -->
        <FilterBar :label="t('c_system.public_records.filter_label', 'Filter the public record')">
            <label class="cc-small" style="color: var(--gov-fg-muted)">
                <span class="visually-hidden">{{ t('c_system.public_records.search_record', 'Search the record') }}</span>
                <input
                    v-model="q"
                    class="field-input"
                    style="inline-size: 13rem; padding-block: var(--space-1)"
                    type="search"
                    :placeholder="t('c_system.public_records.search_placeholder', 'Search title or author')"
                    @keyup.enter="applyFilters"
                    @change="applyFilters"
                />
            </label>
            <!-- W-0440: a typed legislature search (prefix, at most 20 matches) replaces the
                 planet-wide dropdown; the active legislature keeps its name from the server. -->
            <label class="cc-small" style="color: var(--gov-fg-muted)">
                <span class="visually-hidden">{{ t('c_system.public_records.legislature_label', 'Legislature') }}</span>
                <input
                    v-model="legislatureQuery"
                    class="field-input"
                    style="inline-size: 14rem; padding-block: var(--space-1)"
                    type="search"
                    list="public-records-legislatures"
                    :placeholder="t('c_system.public_records.legislature_placeholder', 'Legislature name')"
                    autocomplete="off"
                    @input="searchLegislatures"
                    @change="pickLegislature"
                />
                <datalist id="public-records-legislatures">
                    <option v-for="l in legislatureOptions" :key="l.id" :value="l.name" />
                </datalist>
            </label>
            <span v-if="activeLegislatureName" class="badge">
                {{ activeLegislatureName }}
                <button type="button" class="form-chip" style="margin-inline-start: var(--space-1)" @click="clearLegislature">{{ t('c_system.public_records.clear', 'clear') }}</button>
            </span>
            <span class="eyebrow">{{ t('c_system.public_records.kind_eyebrow', 'Kind') }}</span>
            <span class="cluster" style="gap: var(--space-1)">
                <ChipToggle
                    v-for="kind in filters.kinds"
                    :key="kind"
                    :pressed="activeKinds.includes(kind)"
                    @update:pressed="toggleKind(kind)"
                >{{ KIND_LABELS[kind] ?? kind }}</ChipToggle>
            </span>
            <Btn variant="ghost" size="sm" :disabled="!hasFilters" @click="clearFilters">{{ t('c_system.public_records.clear_filters', 'Clear filters') }}</Btn>
        </FilterBar>

        <!-- ==================================== the feed ================= -->
        <Card as="section" :title="t('c_system.public_records.record_title', 'The record')">
            <p class="citation" style="margin-block-end: var(--space-2)">{{ t('c_system.public_records.stored_utc', 'stored as UTC · shown in your timezone') }}</p>

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
                            {{ t('c_system.public_records.supersedes', 'supersedes') }} <span data-no-i18n>#{{ record.supersedes.seq.toLocaleString() }}</span> {{ t('c_system.public_records.supersedes_note', '— corrections append, never edit; both entries stay visible.') }}
                        </span>
                    </div>
                    <StatusBadge v-if="record.translations.total > 0" :tone="record.translations.done >= record.translations.total ? 'success' : 'warning'"
                        :title="record.translations.locales.map((l) => `${l.code}: ${l.quality}`).join(' · ')">
                        {{ t('c_system.public_records.languages_count', '{done}/{total} languages', { done: record.translations.done, total: record.translations.total }) }}
                    </StatusBadge>
                    <StatusBadge v-else tone="neutral" :title="t('c_system.public_records.mt_pipeline', 'machine translation pipeline · Planned · Phase F')">{{ t('c_system.public_records.original', 'original') }}</StatusBadge>
                    <Link
                        v-if="record.audit_seq !== null"
                        class="form-chip"
                        :href="`/system/audit-chain?seq=${record.audit_seq}`"
                        :title="t('c_system.public_records.sealed_title', 'sealed into the audit chain at commit')"
                    >{{ t('c_system.public_records.sealed_audit', 'sealed · audit') }} <span class="form-id" data-no-i18n>#{{ record.audit_seq }}</span></Link>
                </LogRow>
            </div>
            <p v-else class="cc-small gloss">
                {{ t('c_system.public_records.no_records', 'No records match') }}
                {{ hasFilters ? t('c_system.public_records.no_records_filtered', 'the current filters — the empty view is the filter, not the record.') : t('c_system.public_records.no_records_empty', '— the register fills as institutions act.') }}
            </p>

            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn v-if="records.next_cursor" variant="secondary" size="sm" @click="loadOlder">{{ t('c_system.public_records.older_entries', 'Older entries →') }}</Btn>
                <span v-if="records.next_cursor" class="citation">{{ t('c_system.public_records.cursor_note', 'cursor pagination — the record is append-only; pages never shift') }}</span>
            </div>
        </Card>

        <!-- ==================================== composer (F-LEG-006) ===== -->
        <FormCard
            v-if="can.statement && formMeta('F-LEG-006')"
            :form="formMeta('F-LEG-006')"
            :inertia-form="statement"
            :submit-label="t('c_system.public_records.submit_label', 'Submit to the public record')"
            @submit="submitStatement"
        >
            <Field v-if="composer.legislatures.length > 1" :label="t('c_system.public_records.field_chamber', 'Chamber')" :error="statement.errors.legislature_id">
                <template #control="{ id }">
                    <select :id="id" v-model="statement.legislature_id" class="select">
                        <option v-for="l in composer.legislatures" :key="l.id" :value="l.id">{{ l.name }}</option>
                    </select>
                </template>
            </Field>
            <Field
                :label="t('c_system.public_records.field_attach', 'Attach to')"
                :hint="t('c_system.public_records.field_attach_hint', 'Statements attach to the bill, session, or vote they explain — readers see them in context.')"
                :error="statement.errors.subject_type"
            >
                <template #control="{ id }">
                    <select :id="id" v-model="statement.subject" class="select">
                        <option value="general">{{ t('c_system.public_records.subject_general', 'General record (no attachment)') }}</option>
                        <option v-for="s in composer.subjects" :key="`${s.type}:${s.id}`" :value="`${s.type}:${s.id}`">
                            {{ s.label }}
                        </option>
                    </select>
                </template>
            </Field>
            <Field
                :label="t('c_system.public_records.field_statement', 'Statement')"
                :hint="t('c_system.public_records.field_statement_hint', 'Once submitted, a statement can be superseded but never edited or withdrawn.')"
                :error="statement.errors.body ?? statement.errors.constitution"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="statement.body"
                        class="field-input"
                        rows="4"
                        :placeholder="t('c_system.public_records.statement_placeholder', 'Your statement, explanation, or position — published verbatim and permanently.')"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>
            <p class="citation" style="margin-block-end: var(--space-2)">
                {{ t('c_system.public_records.composer_cite', 'entered verbatim into the immutable public record · WF-SYS-03 · sealed into the audit chain at commit') }}
            </p>
        </FormCard>

        <!-- ==================================== hardened footer ========== -->
        <Card as="section" :title="t('c_system.public_records.vs_title', 'Public records vs the audit chain')">
            <p class="cc-small">
                <strong>{{ t('c_system.public_records.this_page', 'This page') }}</strong> {{ t('c_system.public_records.vs_body_a', 'is the curated register citizens read — statements, votes with explanations, acts, certifications; corrections append superseding entries.') }}
                {{ ' ' }}
                <Link href="/system/audit-chain">{{ t('c_system.public_records.audit_chain_link', 'The audit chain') }}</Link> {{ t('c_system.public_records.vs_body_b', 'is the raw hash-linked log auditors verify — every state transition including rejections, payload hashes, and chain verification; nothing ever supersedes there.') }}
            </p>
            <p class="cc-small" style="margin-block-start: var(--space-2)">
                <HardenedChip>{{ t('c_system.public_records.hardened_chip', 'Record-keeping cannot be suspended under emergency powers · nothing is publishable-optional · Art. II §2 · WF-SYS-03') }}</HardenedChip>
            </p>
        </Card>
    </PageScaffold>
</template>
