<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * System/Translations — Phase N (lane 5) translation status board.
 *
 * READ-ONLY BY DESIGN. Answers one question: how much of this app can a
 * person read in their own language?
 *
 * Every number here is the coverage artifact `scripts/i18n/check.mjs` writes
 * (resources/js/i18n/coverage.json) — the SAME artifact the gate exits
 * non-zero on. Nothing is recomputed client-side: a board that measures
 * coverage its own way is a board that can disagree with the gate, and then
 * neither number is worth reading.
 *
 * When the translation pull-engine lands, the live run decks (per-locale
 * bars, worker strip, review census) join this page from the progress
 * endpoint. This half stays: it is what "where are we" looks like when no
 * run is in flight.
 */
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { csrfFetch } from '@/lib/csrf';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';
import Icon from '@/Components/Ui/Icon.vue';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    /** scripts/i18n/check.mjs output, or null when it has never been run here. */
    coverage: { type: Object, default: null },
    /** Live run deck, read off the workers' own heartbeat files. */
    live: { type: Object, default: null },
    /** Languages × the six kinds of content, one state per cell. */
    matrix: { type: Array, default: () => [] },
    modalities: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    /** Registry entries for the languages shown (name, endonym, dir). */
    registry: { type: Object, default: () => ({}) },
    totals: { type: Object, default: () => ({}) },
    /** The viewer's verifier standing: {authed, isOperator, canVerify: [codes]}. */
    viewer: { type: Object, default: () => ({ authed: false, isOperator: false, canVerify: [] }) },
});

/* ── The matrix ──────────────────────────────────────────────────────────────
   Six kinds of content per language, each with its own state. One percentage
   per language would let "Spanish is 99%" stand in for "the videos are dubbed
   in Spanish"; six cells cannot make that claim. */
const byState = computed(() => Object.fromEntries(props.states.map((s) => [s.id, s])));
const langOf = (code) => props.registry[code] ?? { name: code, endonym: code, dir: 'ltr' };
const cellLabel = (c) => (c.state === 'none' ? '—' : `${c.pct}%`);

/* ── Verifier standing ─────────────────────────────────────────────────────
   The languages this viewer may verify (the reader-of-language gate), each with
   its display name and a link to its worst-first review queue. */
const myVerifyLangs = computed(() =>
    (props.viewer?.canVerify ?? []).map((code) => ({ code, ...langOf(code) })));

/* ── The live half ───────────────────────────────────────────────────────────
   Polls every 2s while a run is in flight, the same contract Step-3's district
   mapper uses. Polling NEVER stops on its own except when a run has terminated:
   a run started from a shell after this page was opened must appear without a
   reload, which is the whole point of leaving it open. */
const deck = ref(props.live);
const deckError = ref('');
let pollTimer = null;
let pollTick = 0;

const workers = computed(() => deck.value?.workers ?? []);
const activeWorkers = computed(() => deck.value?.workers_active ?? 0);
const runStatus = computed(() => deck.value?.run?.status ?? null);
const runLive = computed(() => runStatus.value === 'running' || activeWorkers.value > 0);
const halted = computed(() => deck.value?.halt_requested === true);

const etaText = computed(() => {
    const s = deck.value?.eta_seconds;
    if (s === null || s === undefined) return '—';
    if (s < 90) return `${s}s`;
    if (s < 5400) return `${Math.round(s / 60)} min`;
    return `${(s / 3600).toFixed(1)} h`;
});

function workerPct(w) {
    if (!w.total) return 0;
    return Math.round((w.done / w.total) * 1000) / 10;
}

async function pollDeck() {
    try {
        const res = await fetch('/system/translations/progress', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) { deckError.value = `progress unavailable (HTTP ${res.status})`; return; }
        deckError.value = '';
        deck.value = await res.json();
        /* Coverage is recomputed by the gate, not by this page, so it only
           changes between runs — refresh it far less often than the deck. */
        if (runLive.value && ++pollTick % 30 === 0) router.reload({ only: ['coverage'] });
    } catch (e) {
        deckError.value = String(e);
    }
}

onMounted(() => { pollDeck(); pollTimer = setInterval(pollDeck, 2000); });
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer); });

const measured = computed(() => props.coverage !== null);

const generatedAt = computed(() => {
    if (!props.coverage?.generated_at) return null;
    return localeFmt.dateTime(new Date(props.coverage.generated_at));
});

const sourceKeys = computed(() => props.coverage?.source_keys ?? 0);
const namespaces = computed(() => props.coverage?.namespaces ?? 0);
const locales = computed(() => props.coverage?.locales ?? []);

/* The headline: how much of the app the best-covered locale actually carries.
   Reporting the mean would flatter us — one locale at 100% and three at 0%
   is not "25% translated" in any sense a reader cares about. */
const bestPct = computed(() =>
    locales.value.length ? Math.max(...locales.value.map((l) => l.pct)) : 0,
);

const totalMissing = computed(() =>
    locales.value.reduce((sum, l) => sum + l.missing, 0),
);

const CODE_LABELS = computed(() => ({
    'C1-missing': t('c_system.translations.code_c1', 'Key missing from a locale'),
    'C2-orphan': t('c_system.translations.code_c2', 'Key present in a locale but not in English'),
    'C3-placeholder': t('c_system.translations.code_c3', 'Placeholder mismatch ({token} tokens differ)', { token: '{name}' }),
    'C4-idtoken': t('c_system.translations.code_c4', 'ID token or citation not byte-identical'),
    'C5-compile': t('c_system.translations.code_c5', 'Message does not compile in vue-i18n'),
    'C6-empty': t('c_system.translations.code_c6', 'Empty message'),
    'C7-registry': t('c_system.translations.code_c7', 'PHP and JS locale lists disagree'),
    'C0-parse': t('c_system.translations.code_c0', 'Catalog file could not be parsed'),
}));

const failures = computed(() => props.coverage?.failures ?? 0);
const failureCodes = computed(() =>
    Object.entries(props.coverage?.failure_codes ?? {})
        .sort((a, b) => b[1] - a[1])
        .map(([code, count]) => ({ code, count, label: CODE_LABELS.value[code] ?? code })),
);

const localeColumns = computed(() => [
    { key: 'locale', label: t('c_system.translations.col_locale', 'Locale'), mono: true },
    { key: 'bar', label: t('c_system.translations.col_coverage', 'Coverage') },
    { key: 'pct', label: t('c_system.translations.col_pct', '%'), align: 'right' },
    { key: 'present', label: t('c_system.translations.col_carried', 'Carried'), align: 'right' },
    { key: 'missing', label: t('c_system.translations.col_missing', 'Missing'), align: 'right' },
    { key: 'identical', label: t('c_system.translations.col_same', 'Same as English'), align: 'right' },
]);

const localeRows = computed(() => locales.value.map((l) => ({ ...l, bar: l.pct })));

/* Namespace grid: one row per namespace, one column per locale. */
const nsColumns = computed(() => [
    { key: 'namespace', label: t('c_system.translations.col_namespace', 'Namespace'), mono: true },
    { key: 'total', label: t('c_system.translations.col_messages', 'Messages'), align: 'right' },
    ...locales.value.map((l) => ({ key: `loc_${l.locale}`, label: l.locale, align: 'right' })),
]);

const nsRows = computed(() =>
    (props.coverage?.by_namespace ?? [])
        .slice()
        .sort((a, b) => b.total - a.total)
        .map((ns) => {
            const row = { namespace: ns.namespace, total: ns.total };
            for (const l of locales.value) row[`loc_${l.locale}`] = ns.locales?.[l.locale] ?? 0;
            return row;
        }),
);

function pctOf(part, whole) {
    if (!whole) return 0;
    return Math.round((part / whole) * 1000) / 10;
}

/* ── Language packages (W-0446, operator-only) ─────────────────────────────
   Every package is ENGLISH: the source strings a chosen language still
   lacks, or the whole English master. Import brings a translated copy back
   under its own language. The picker states what is actually in the app
   (operator observation 2026-09-15): English as the source, the machine
   drafts with their measured coverage, and every other registered language
   as "no strings yet". The scripts run in queued jobs; this card only calls
   the operator-gated endpoints and polls the run records every 2s, the same
   contract the live deck uses. */
const pkg = ref({ runs: [], requests: [], targets: [], languages: [], source: null });
const pkgError = ref('');
const pkgBusy = ref(false);
const exportLocale = ref('');
const importLocale = ref('');
const importFile = ref(null);
const requestLocale = ref('');
const requestNote = ref('');
let pkgTimer = null;

const pkgRuns = computed(() => pkg.value?.runs ?? []);
const pkgRequests = computed(() => pkg.value?.requests ?? []);
const pkgTargets = computed(() => pkg.value?.targets ?? []);
const pkgLanguages = computed(() => pkg.value?.languages ?? []);
const pkgPresent = computed(() => pkgLanguages.value.filter((l) => l.present));
const pkgAbsent = computed(() => pkgLanguages.value.filter((l) => !l.present));
const pkgSource = computed(() => pkg.value?.source ?? null);
const pkgSourceCode = computed(() => pkgSource.value?.code ?? 'en');
/** One option line: "Spanish (es) · 29% drafted, 10,722 to go" or "Polish (pl) · no strings yet". */
function pkgOptionLabel(l) {
    const head = `${l.name} (${l.code})`;
    if (!l.present) return `${head} · ${t('c_system.translations.pkg_opt_absent', 'no strings yet')}`;
    return `${head} · ${t('c_system.translations.pkg_opt_present', '{pct}% drafted, {missing} to go', {
        pct: localeFmt.number(Math.round(l.pct ?? 0)),
        missing: localeFmt.number(l.missing ?? 0),
    })}`;
}
function pkgRunLocale(r) {
    if (r.kind === 'export' && r.locale === pkgSourceCode.value) return t('c_system.translations.pkg_source_master', 'English master');
    const row = pkgLanguages.value.find((l) => l.code === r.locale);
    return row ? `${row.name} (${row.code})` : (r.locale || '—');
}

async function pollPackages() {
    if (!props.viewer?.isOperator) return;
    try {
        const res = await fetch('/system/translations/packages', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        pkg.value = await res.json();
    } catch { /* transient; the next tick retries */ }
}

async function pkgPost(url, body, isForm = false) {
    pkgError.value = '';
    pkgBusy.value = true;
    try {
        const opts = { method: 'POST' };
        if (isForm) {
            opts.body = body;
        } else {
            opts.headers = { 'Content-Type': 'application/json' };
            opts.body = JSON.stringify(body ?? {});
        }
        const res = await csrfFetch(url, opts, t);
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            pkgError.value = data.error || t('c_system.translations.pkg_error', 'The action failed. Nothing changed.');
            return null;
        }
        await pollPackages();
        return data;
    } catch (e) {
        pkgError.value = String(e?.message ?? e);
        return null;
    } finally {
        pkgBusy.value = false;
    }
}

async function startExport() {
    if (!exportLocale.value) return;
    await pkgPost('/system/translations/packages/export', { locale: exportLocale.value });
}

function onImportFile(e) {
    importFile.value = e.target.files?.[0] ?? null;
}

async function startImport() {
    if (!importFile.value || !importLocale.value) return;
    const form = new FormData();
    form.append('locale', importLocale.value);
    form.append('package', importFile.value);
    await pkgPost('/system/translations/packages/import', form, true);
}

async function confirmImport(run) {
    await pkgPost(`/system/translations/packages/${encodeURIComponent(run)}/confirm`, {});
}

async function requestLanguageSubmit() {
    if (!requestLocale.value) return;
    const done = await pkgPost('/system/translations/languages/request', {
        locale: requestLocale.value,
        note: requestNote.value,
    });
    if (done) { requestLocale.value = ''; requestNote.value = ''; }
}

function downloadHref(run, locale) {
    return `/system/translations/packages/${encodeURIComponent(run)}/${encodeURIComponent(locale)}/download`;
}

onMounted(() => {
    if (props.viewer?.isOperator) { pollPackages(); pkgTimer = setInterval(pollPackages, 2000); }
});
onUnmounted(() => { if (pkgTimer) clearInterval(pkgTimer); });
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            <p class="page-intro">
                {{ t('c_system.translations.intro_a', 'How much of this application a person can read in their own language. Every figure below is produced by the translation gate') }}
                (<code data-no-i18n>scripts/i18n/check.mjs</code>) {{ t('c_system.translations.intro_b', '— the same run that fails a build when a language falls behind. Nothing on this page is measured a second way.') }}
            </p>
        </template>

        <!-- ── LIVE RUN DECK ───────────────────────────────────────────────
             Shown whenever workers exist. The coverage half below answers
             "where are we"; this half answers "what is happening right now",
             which is a different question and needs its own surface. -->
        <Card v-if="workers.length" :title="runLive ? t('c_system.translations.translating_now', 'Translating now') : t('c_system.translations.last_run', 'Last run')"
              :eyebrow="t('c_system.translations.eyebrow_live', 'live')">
            <div class="stat-row">
                <Stat :value="activeWorkers" :label="t('c_system.translations.workers_active', 'workers active')" :accent="activeWorkers > 0" />
                <Stat :value="`${deck?.rate ?? 0}/s`" :label="t('c_system.translations.strings_per_second', 'strings per second')" />
                <Stat :value="localeFmt.number((deck?.strings_done ?? 0))" :label="t('c_system.translations.translated_this_run', 'translated this run')" />
                <Stat :value="etaText" :label="t('c_system.translations.estimated_remaining', 'estimated remaining')" />
            </div>

            <p v-if="halted" class="muted">
                <StatusBadge tone="warning">{{ t('c_system.translations.halt_requested', 'Halt requested') }}</StatusBadge>
                {{ t('c_system.translations.halt_note', 'Workers stop at their next committed chunk — at most one chunk is redone.') }}
            </p>
            <p v-if="deckError" class="muted">{{ deckError }}</p>

            <!-- one line per worker: what it is, what it is doing, how fast -->
            <DataTable
                :columns="[
                    { key: 'locale', label: t('c_system.translations.wcol_language', 'Language'), mono: true },
                    { key: 'state', label: t('c_system.translations.wcol_state', 'State') },
                    { key: 'namespace', label: t('c_system.translations.wcol_area', 'Area'), mono: true },
                    { key: 'bar', label: t('c_system.translations.wcol_progress', 'Progress') },
                    { key: 'done', label: t('c_system.translations.wcol_done', 'Done'), align: 'right' },
                    { key: 'rate', label: t('c_system.translations.wcol_rate', '/sec'), align: 'right' },
                    { key: 'device', label: t('c_system.translations.wcol_on', 'On'), mono: true },
                ]"
                :rows="workers"
                row-key="id"
                :caption="t('c_system.translations.workers_caption', 'Translation workers currently running')"
            >
                <template #cell-state="{ row }">
                    <StatusBadge
                        :tone="row.stale ? 'danger' : row.state === 'translating' ? 'success'
                               : row.state === 'done' ? 'neutral' : 'info'"
                    >{{ row.stale ? t('c_system.translations.state_silent', 'silent') : row.state }}</StatusBadge>
                </template>
                <template #cell-namespace="{ row }">{{ row.namespace || '—' }}</template>
                <template #cell-bar="{ row }">
                    <div class="cov-bar" :title="`${workerPct(row)}%`">
                        <div class="cov-bar-fill" :style="{ width: `${Math.max(workerPct(row), 0.6)}%` }" />
                    </div>
                </template>
                <template #cell-done="{ row }">
                    <span data-no-i18n>{{ row.done }}/{{ row.total }}</span>
                </template>
                <template #cell-rate="{ row }"><span data-no-i18n>{{ row.rate ?? 0 }}</span></template>
            </DataTable>

            <!-- the actual strings in flight: the thing that makes a run legible -->
            <div v-for="w in workers.filter((x) => x.current?.length)" :key="`cur-${w.id}`"
                 class="inflight">
                <p class="muted inflight-head">
                    <span data-no-i18n>{{ w.locale }}</span> {{ t('c_system.translations.in_flight', '— in flight right now:') }}
                </p>
                <ul class="inflight-list">
                    <li v-for="(t, i) in w.current" :key="i" class="inflight-item">{{ t }}</li>
                </ul>
            </div>

            <p class="muted">
                {{ t('c_system.translations.worker_note', 'Each worker holds its own copy of the translation model, so the GPU — not the CPU — sets how many can run at once. Work commits in small batches: halting, or a crash, costs at most one batch and never leaves a half-written language.') }}
            </p>
        </Card>

        <!-- ── THE MATRIX ──────────────────────────────────────────────────
             Languages × the six kinds of content. This is the honest shape of
             the question "is this app translated?" — one number never was. -->
        <Card v-if="matrix.length" :title="t('c_system.translations.coverage_title', 'Coverage')" :eyebrow="t('c_system.translations.coverage_eyebrow', 'languages × kinds of content')">
            <p class="gloss">
                {{ t('c_system.translations.matrix_gloss_a', 'Six kinds of content per language. A cell shows how complete it is and where it sits in the lifecycle.') }} <strong>{{ totals.mapped }}</strong> {{ t('c_system.translations.matrix_gloss_b', 'languages are registered and') }}
                <strong>{{ totals.translated }}</strong> {{ t('c_system.translations.matrix_gloss_c', 'are marked for translation; the') }}
                {{ totals.shown }} {{ t('c_system.translations.matrix_gloss_d', 'with catalogs on this instance are shown — a row of dashes for the rest would bury these.') }}
            </p>

            <div class="tlegend">
                <span v-for="s in states" :key="s.id" class="leg" :title="s.desc">
                    <span class="swatch" :class="`swatch--${s.id}`" />{{ s.label }}
                </span>
            </div>

            <div class="table-wrap">
                <table class="tmatrix">
                    <caption class="visually-hidden">
                        {{ t('c_system.translations.matrix_caption', 'Translation coverage by language and kind of content') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ t('c_system.translations.th_language', 'Language') }}</th>
                            <th v-for="m in modalities" :key="m.id" scope="col" :title="m.basis">
                                {{ m.label }}
                            </th>
                            <th scope="col">{{ t('c_system.translations.th_overall', 'Overall') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in matrix" :key="r.code">
                            <th scope="row" class="lang-cell">
                                <Link :href="`/system/translations/review/${r.code}`">
                                    <span class="lang-native" :dir="langOf(r.code).dir" :lang="r.code">
                                        {{ langOf(r.code).endonym }}
                                    </span>
                                    <span class="lang-code" data-no-i18n>{{ r.code }}</span>
                                </Link>
                                <span v-if="r.code === 'en'" class="pill pill--pass">{{ t('c_system.translations.source_pill', 'Source') }}</span>
                            </th>
                            <td
                                v-for="m in modalities" :key="m.id"
                                class="tcell" :class="`tcell--${r.cells[m.id].state}`"
                                :aria-label="r.cells[m.id].state === 'none'
                                    ? t('c_system.translations.cell_aria_none', '{modality}: {state}', { modality: m.label, state: byState[r.cells[m.id].state]?.label })
                                    : t('c_system.translations.cell_aria', '{modality}: {state}, {pct} percent', { modality: m.label, state: byState[r.cells[m.id].state]?.label, pct: r.cells[m.id].pct })"
                            >
                                <span class="tdot" :title="byState[r.cells[m.id].state]?.label">
                                    {{ cellLabel(r.cells[m.id]) }}
                                </span>
                            </td>
                            <td class="tcell">
                                <div class="tprog" :title="`${r.overall}% overall`">
                                    <i :style="{ inlineSize: `${r.overall}%` }" />
                                </div>
                                <span class="lang-code" data-no-i18n>{{ r.overall }}%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="citation">
                {{ t('c_system.translations.overall_note', 'Overall is the mean of all six kinds, so the four we do not yet produce pull it down. That is deliberate: a language is not translated because its buttons are.') }}
            </p>
        </Card>

        <Card v-if="modalities.length" :title="t('c_system.translations.six_kinds_title', 'The six kinds of content')">
            <div class="role-grid">
                <div v-for="m in modalities" :key="m.id" class="role-card">
                    <span class="role-name"><Icon :name="m.icon" size="sm" /> {{ m.label }}</span>
                    <span>{{ m.basis }}</span>
                    <span class="citation" data-no-i18n>{{ m.source }}</span>
                    <span v-if="!m.measurable" class="gloss">{{ m.why }}</span>
                </div>
            </div>
            <p class="gloss">
                {{ t('c_system.translations.six_kinds_note', 'Interface and page copy are measured here. The other four are produced outside this app and are reported as not-started rather than left blank — a blank cell reads as "fine", which would be a lie.') }}
            </p>
        </Card>

        <Card :title="t('c_system.translations.sop_title', 'How a language gets translated')" inset>
            <ol class="sop-steps">
                <li>
                    <span class="sop-do">{{ t('c_system.translations.sop1_do', 'Pick the language') }}</span>
                    <span class="sop-detail">{{ t('c_system.translations.sop1_detail', 'Any of the {n} registered languages, or request a new one.', { n: totals.mapped }) }}</span>
                </li>
                <li>
                    <span class="sop-do">{{ t('c_system.translations.sop2_do', 'Generate the first round') }}</span>
                    <span class="sop-detail">{{ t('c_system.translations.sop2_detail', 'The machine drafts everything at once — people never start from a blank box.') }}</span>
                </li>
                <li>
                    <span class="sop-do">{{ t('c_system.translations.sop3_do', 'Open it for review') }}</span>
                    <span class="sop-detail">{{ t('c_system.translations.sop3_detail', 'Readers of that language see the drafts and begin verifying.') }}</span>
                </li>
                <li>
                    <span class="sop-do">{{ t('c_system.translations.sop4_do', 'Publish on quorum') }}</span>
                    <span class="sop-detail">{{ t('c_system.translations.sop4_detail', 'A string settles once enough readers agree and the gate is clean.') }}</span>
                </li>
            </ol>
            <p class="citation">
                <Icon name="lock" size="sm" />
                {{ t('c_system.translations.sop_private', 'Private records never enter the pipeline — a database check forbids it.') }}
            </p>
            <p class="citation">
                <Icon name="users" size="sm" />
                {{ t('c_system.translations.sop_verified', 'Verified by the people who read the interface in that language, never by the machine grading itself.') }}
            </p>
        </Card>

        <!-- Never measured on this box: say so plainly rather than render zeros. -->
        <Card v-if="!measured" :title="t('c_system.translations.not_measured_title', 'Not measured yet')">
            <p>
                {{ t('c_system.translations.not_measured_body', 'No coverage artifact on this instance. Run the gate to produce one:') }}
            </p>
            <p><code data-no-i18n>node scripts/i18n/check.mjs</code></p>
            <p class="muted">
                {{ t('c_system.translations.not_measured_a', 'It writes') }} <code data-no-i18n>resources/js/i18n/coverage.json</code> {{ t('c_system.translations.not_measured_b', 'and exits non-zero while any language is behind.') }}
            </p>
        </Card>

        <template v-else>
            <Card :title="t('c_system.translations.where_title', 'Where we are')" :eyebrow="t('c_system.translations.where_eyebrow', 'headline')">
                <div class="stat-row">
                    <Stat :value="localeFmt.number(sourceKeys)" :label="t('c_system.translations.stat_messages', 'translatable messages in the app')" />
                    <Stat :value="namespaces" :label="t('c_system.translations.stat_namespaces', 'namespaces')" />
                    <Stat :value="locales.length" :label="t('c_system.translations.stat_languages', 'languages present')" />
                    <Stat
                        :value="`${bestPct}%`"
                        :label="t('c_system.translations.stat_best', 'best-covered language')"
                        :accent="bestPct >= 90"
                    />
                </div>
                <p class="muted">
                    <template v-if="totalMissing > 0">
                        {{ t('c_system.translations.owed', '{n} message-translations are still owed across the languages below.', { n: localeFmt.number(totalMissing) }) }}
                    </template>
                    <template v-else>{{ t('c_system.translations.all_carried', 'Every registered language carries every message.') }}</template>
                </p>
                <p v-if="generatedAt" class="muted">
                    {{ t('c_system.translations.measured_at', 'Measured {when}.', { when: generatedAt }) }}
                </p>
            </Card>

            <Card :title="t('c_system.translations.by_language_title', 'By language')">
                <DataTable
                    :columns="localeColumns"
                    :rows="localeRows"
                    row-key="locale"
                    :caption="t('c_system.translations.by_language_caption', 'Translation coverage per language')"
                >
                    <template #cell-bar="{ row }">
                        <div class="cov-bar" :title="`${row.pct}%`">
                            <div class="cov-bar-fill" :style="{ width: `${Math.max(row.pct, 0.6)}%` }" />
                        </div>
                    </template>
                    <template #cell-pct="{ row }">
                        <StatusBadge :tone="row.pct >= 90 ? 'success' : row.pct >= 40 ? 'warning' : 'danger'">
                            {{ row.pct }}%
                        </StatusBadge>
                    </template>
                    <template #cell-present="{ row }">
                        <span data-no-i18n>{{ localeFmt.number(row.present) }}</span>
                    </template>
                    <template #cell-missing="{ row }">
                        <span data-no-i18n>{{ localeFmt.number(row.missing) }}</span>
                    </template>
                </DataTable>
                <p class="muted">
                    <strong>{{ t('c_system.translations.same_english_label', 'Same as English') }}</strong> {{ t('c_system.translations.same_english_body', 'counts values byte-identical to the source — a proper noun that legitimately does not translate, or a string nobody has translated yet. It is a hint for reviewers, not a failure.') }}
                </p>
            </Card>

            <Card :title="t('c_system.translations.gate_findings_title', 'Gate findings ({n})', { n: localeFmt.number(failures) })">
                <p v-if="!failures">{{ t('c_system.translations.gate_passes', 'The gate passes. No language is behind and every message compiles.') }}</p>
                <template v-else>
                    <DataTable
                        :columns="[
                            { key: 'label', label: t('c_system.translations.fcol_finding', 'Finding') },
                            { key: 'code', label: t('c_system.translations.fcol_code', 'Code'), mono: true },
                            { key: 'count', label: t('c_system.translations.fcol_count', 'Count'), align: 'right' },
                        ]"
                        :rows="failureCodes"
                        row-key="code"
                        :caption="t('c_system.translations.findings_caption', 'Translation gate findings by code')"
                    >
                        <template #cell-count="{ row }">
                            <span data-no-i18n>{{ localeFmt.number(row.count) }}</span>
                        </template>
                    </DataTable>
                    <p class="muted">
                        {{ t('c_system.translations.findings_run_a', 'Run') }} <code data-no-i18n>node scripts/i18n/check.mjs</code> {{ t('c_system.translations.findings_run_b', 'for the per-key detail behind each code.') }}
                    </p>
                </template>
            </Card>

            <Card :title="t('c_system.translations.by_namespace_title', 'By namespace')">
                <p class="muted">
                    {{ t('c_system.translations.by_namespace_note', 'Messages per area of the app, and how many of them each language carries. A namespace maps to a page folder.') }}
                </p>
                <DataTable
                    :columns="nsColumns"
                    :rows="nsRows"
                    row-key="namespace"
                    :caption="t('c_system.translations.by_namespace_caption', 'Messages per namespace per language')"
                />
            </Card>

            <CitationLine :text="t('c_system.translations.records_publish', 'Records publish with translations · WF-SYS-03')" />
        </template>

        <!-- ── BECOME A VERIFIER ───────────────────────────────────────────
             Anyone who reads a language may confirm or flag its drafts; a
             string is settled by a quorum of readers, never by the machine.
             The languages you read come from your account and gate what you
             can verify here (mockups/v3/translation/translation-home.html). -->
        <Card>
            <div class="cluster" style="justify-content: space-between; align-items: center">
                <h2><Icon name="users" size="sm" /> {{ t('c_system.translations.verifier_title', 'Become a verifier') }}</h2>
                <StatusBadge tone="info">{{ t('c_system.translations.verifier_role', 'Verifier role · readers of a language') }}</StatusBadge>
            </div>
            <p class="gloss">
                {{ t('c_system.translations.verifier_gloss', 'Anyone who reads a language can verify its translations. A draft is settled by a quorum of readers who agree — the machine never publishes itself. The languages you read come from your account and give weight to your verifications.') }}
            </p>

            <template v-if="myVerifyLangs.length">
                <p class="gloss">{{ t('c_system.translations.can_verify', 'You can verify — pick a language to work its queue, worst-first:') }}</p>
                <div class="cluster" style="gap: var(--space-1)">
                    <Link
                        v-for="l in myVerifyLangs"
                        :key="l.code"
                        class="form-chip"
                        :href="`/system/translations/review/${l.code}`"
                    >
                        <Icon name="check" size="sm" /> {{ l.endonym }}
                        <span class="gloss" data-no-i18n>· {{ l.code }}</span>
                    </Link>
                </div>
                <p v-if="viewer.isOperator" class="muted">
                    {{ t('c_system.translations.operator_note', 'As operator you can verify every language — use this to unstick a queue no reader has reached yet, never to overrule the readers of a language.') }}
                </p>
            </template>

            <template v-else>
                <p class="muted" v-if="!viewer.authed">
                    <Link href="/register">{{ t('c_system.translations.sign_up', 'Sign up') }}</Link> {{ t('c_system.translations.sign_up_after', 'and set the languages you read on your profile to start verifying.') }}
                </p>
                <p class="muted" v-else>
                    {{ t('c_system.translations.add_langs_profile', 'Add the languages you read on your profile to start verifying — the queue is gated to readers so a translation is only ever settled by people who can judge it.') }}
                </p>
            </template>
        </Card>

        <!-- ── LANGUAGE PACKAGES (operator-only) ────────────────────────────
             Every package is English: the source strings a language still
             lacks, or the whole English master. Translate anywhere, import
             the result under its own language. Operator-only: the actions
             run real work against the real catalogs through queued jobs. -->
        <Card v-if="viewer.isOperator" :title="t('c_system.translations.pkg_title', 'Language packages')"
              :eyebrow="t('c_system.translations.pkg_eyebrow', 'operator')">
            <p class="gloss">
                {{ t('c_system.translations.pkg_intro', 'Every package you export is English. Pick the language a translator will produce and the zip holds the English strings that language still lacks, with surface context and the glossary. Translate anywhere, then import the result under that language. Every action runs as a queued job, never in the page.') }}
            </p>
            <p v-if="pkgSource" class="muted" data-no-i18n>
                {{ t('c_system.translations.pkg_state_line', 'In the app now: English (the source, {keys} strings in {files} files), {present} machine drafts in progress, {absent} registered languages with no strings yet.', {
                    keys: localeFmt.number(pkgSource.keys ?? 0),
                    files: localeFmt.number(pkgSource.files ?? 0),
                    present: localeFmt.number(pkgPresent.length),
                    absent: localeFmt.number(pkgAbsent.length),
                }) }}
            </p>

            <p v-if="pkgError" class="muted">
                <StatusBadge tone="danger">{{ pkgError }}</StatusBadge>
            </p>

            <div class="pkg-grid">
                <!-- EXPORT -->
                <div class="pkg-panel">
                    <h3>{{ t('c_system.translations.pkg_export_title', 'Export English strings') }}</h3>
                    <p class="gloss">{{ t('c_system.translations.pkg_export_hint', 'Pick the language the translation is for. The zip holds the English strings that language still lacks. Or export the English master: every source string, no target.') }}</p>
                    <label class="pkg-label">
                        {{ t('c_system.translations.pkg_export_select', 'Export for') }}
                        <select v-model="exportLocale" class="pkg-input">
                            <option value="">{{ t('c_system.translations.pkg_export_placeholder', 'Choose a language') }}</option>
                            <option :value="pkgSourceCode">{{ t('c_system.translations.pkg_source_option', 'English master (en) · the source, every string') }}</option>
                            <optgroup v-if="pkgPresent.length" :label="t('c_system.translations.pkg_group_present', 'Machine drafts in the app')">
                                <option v-for="l in pkgPresent" :key="l.code" :value="l.code">{{ pkgOptionLabel(l) }}</option>
                            </optgroup>
                            <optgroup v-if="pkgAbsent.length" :label="t('c_system.translations.pkg_group_absent', 'Registered, no strings yet')">
                                <option v-for="l in pkgAbsent" :key="l.code" :value="l.code">{{ pkgOptionLabel(l) }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <button class="btn btn--primary" :disabled="pkgBusy || !exportLocale" @click="startExport">
                        {{ pkgBusy ? t('c_system.translations.pkg_busy', 'Working') : t('c_system.translations.pkg_export_submit', 'Export') }}
                    </button>
                </div>

                <!-- IMPORT -->
                <div class="pkg-panel">
                    <h3>{{ t('c_system.translations.pkg_import_title', 'Import a translation') }}</h3>
                    <p class="gloss">{{ t('c_system.translations.pkg_import_hint', 'Upload a translated zip or a single JSON file and name its language. The server checks it first and shows what it would accept before anything is written. English is never imported.') }}</p>
                    <label class="pkg-label">
                        {{ t('c_system.translations.pkg_import_locale', 'Translated into') }}
                        <select v-model="importLocale" class="pkg-input">
                            <option value="">{{ t('c_system.translations.pkg_export_placeholder', 'Choose a language') }}</option>
                            <optgroup v-if="pkgPresent.length" :label="t('c_system.translations.pkg_group_present', 'Machine drafts in the app')">
                                <option v-for="l in pkgPresent" :key="l.code" :value="l.code">{{ pkgOptionLabel(l) }}</option>
                            </optgroup>
                            <optgroup v-if="pkgAbsent.length" :label="t('c_system.translations.pkg_group_absent', 'Registered, no strings yet')">
                                <option v-for="l in pkgAbsent" :key="l.code" :value="l.code">{{ pkgOptionLabel(l) }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <label class="pkg-label">
                        {{ t('c_system.translations.pkg_import_file', 'Package file') }}
                        <input type="file" accept=".zip,.json" class="pkg-input" @change="onImportFile" />
                    </label>
                    <button class="btn btn--primary" :disabled="pkgBusy || !importFile || !importLocale" @click="startImport">
                        {{ pkgBusy ? t('c_system.translations.pkg_busy', 'Working') : t('c_system.translations.pkg_import_submit', 'Check') }}
                    </button>
                </div>
            </div>

            <!-- RUNS -->
            <h3>{{ t('c_system.translations.pkg_runs_title', 'Recent runs') }}</h3>
            <p v-if="!pkgRuns.length" class="muted">{{ t('c_system.translations.pkg_no_runs', 'No package runs yet.') }}</p>
            <table v-else class="pkg-table">
                <thead>
                    <tr>
                        <th scope="col">{{ t('c_system.translations.pkg_col_kind', 'Kind') }}</th>
                        <th scope="col">{{ t('c_system.translations.pkg_col_locale', 'Language') }}</th>
                        <th scope="col">{{ t('c_system.translations.pkg_col_status', 'Status') }}</th>
                        <th scope="col">{{ t('c_system.translations.pkg_col_action', 'Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in pkgRuns" :key="r.run">
                        <td>{{ r.kind }}</td>
                        <td><span data-no-i18n>{{ pkgRunLocale(r) }}</span></td>
                        <td>
                            <StatusBadge :tone="r.status === 'ready' || r.status === 'imported' ? 'success'
                                : r.status === 'failed' ? 'danger'
                                : r.status === 'dry_run_ready' ? 'warning' : 'info'">{{ r.status }}</StatusBadge>
                            <span v-if="r.report" class="gloss" data-no-i18n>
                                · {{ t('c_system.translations.pkg_dry_report', '{accepted} accepted, {rejected} rejected', { accepted: r.report.accepted, rejected: r.report.rejected }) }}
                            </span>
                        </td>
                        <td>
                            <a v-if="r.kind === 'export' && r.status === 'ready'" class="btn btn--sm" :href="downloadHref(r.run, r.locale)">
                                {{ t('c_system.translations.pkg_download', 'Download package') }}
                            </a>
                            <button v-else-if="r.kind === 'import' && r.status === 'dry_run_ready'" class="btn btn--sm btn--primary"
                                    :disabled="pkgBusy" @click="confirmImport(r.run)">
                                {{ t('c_system.translations.pkg_confirm', 'Confirm import') }}
                            </button>
                            <span v-else class="gloss">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- REQUEST A LANGUAGE -->
            <h3>{{ t('c_system.translations.pkg_request_title', 'Request a language') }}</h3>
            <p class="gloss">{{ t('c_system.translations.pkg_request_hint', 'Record a language nobody has opened yet. It appears in the list below.') }}</p>
            <div class="pkg-request">
                <label class="pkg-label">
                    {{ t('c_system.translations.pkg_request_locale', 'Language or code') }}
                    <input v-model="requestLocale" type="text" class="pkg-input" maxlength="64" />
                </label>
                <label class="pkg-label">
                    {{ t('c_system.translations.pkg_request_note', 'Note (optional)') }}
                    <input v-model="requestNote" type="text" class="pkg-input" maxlength="1000" />
                </label>
                <button class="btn" :disabled="pkgBusy || !requestLocale" @click="requestLanguageSubmit">
                    {{ t('c_system.translations.pkg_request_submit', 'Record request') }}
                </button>
            </div>
            <p v-if="!pkgRequests.length" class="muted">{{ t('c_system.translations.pkg_no_requests', 'No language requests yet.') }}</p>
            <ul v-else class="pkg-requests">
                <li v-for="req in pkgRequests" :key="req.id">
                    <span data-no-i18n>{{ req.locale }}</span>
                    <span v-if="req.note" class="gloss">{{ req.note }}</span>
                </li>
            </ul>
        </Card>

        <!-- ── ADD A LANGUAGE ─────────────────────────────────────────────── -->
        <Card>
            <h2>{{ t('c_system.translations.add_language_title', 'Add a language') }}</h2>
            <p>
                {{ t('c_system.translations.add_language_body', 'Don’t see yours? Any of the {n} mapped languages can be opened for translation, and a new one can be requested. The machine drafts every kind of content at once, then it opens for your review — never shipped as final until readers confirm it.', { n: totals.mapped ?? 0 }) }}
            </p>
            <div class="cluster" style="gap: var(--space-1)">
                <Link class="btn btn--primary" :href="`/support/report?ref=${encodeURIComponent('Add a language')}`">
                    {{ t('c_system.translations.request_language', 'Request a language') }} <Icon name="arrow-right" size="sm" />
                </Link>
                <Link class="btn" href="/videos">
                    {{ t('c_system.translations.see_videos', 'See the video library') }} <Icon name="arrow-right" size="sm" />
                </Link>
            </div>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.stat-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-6, 1.5rem);
    margin-bottom: var(--space-4, 1rem);
}

.cov-bar {
    position: relative;
    width: 100%;
    min-width: 6rem;
    height: 0.5rem;
    border-radius: 999px;
    background: var(--surface-sunken, rgba(255, 255, 255, 0.08));
    overflow: hidden;
}

.cov-bar-fill {
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    border-radius: 999px;
    background: var(--accent, #c8a047);
    transition: width 240ms ease-out;
}

.muted {
    color: var(--text-muted, rgba(255, 255, 255, 0.62));
}

.inflight { margin-block: var(--space-3, 0.75rem); }
.inflight-head { margin-bottom: 0.25rem; }
.inflight-list { margin: 0; padding-inline-start: 1.1rem; }
.inflight-item {
    font-size: 0.86rem;
    color: var(--text-muted, rgba(255, 255, 255, 0.62));
    overflow-wrap: anywhere;
}

.pkg-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-6, 1.5rem);
    margin-block: var(--space-4, 1rem);
}
.pkg-panel { flex: 1 1 18rem; min-width: 0; }
.pkg-label {
    display: block;
    margin-block: var(--space-2, 0.5rem);
    font-size: 0.9rem;
}
.pkg-input {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    padding: 0.35rem 0.5rem;
    border-radius: var(--radius-2, 0.4rem);
    border: 1px solid var(--border, rgba(255, 255, 255, 0.16));
    background: var(--surface-sunken, rgba(255, 255, 255, 0.04));
    color: inherit;
}
.pkg-table { width: 100%; border-collapse: collapse; margin-block: var(--space-3, 0.75rem); }
.pkg-table th, .pkg-table td { text-align: left; padding: 0.4rem 0.5rem; vertical-align: top; }
.pkg-request { display: flex; flex-wrap: wrap; gap: var(--space-3, 0.75rem); align-items: flex-end; }
.pkg-request .pkg-label { flex: 1 1 12rem; }
.pkg-requests { margin: var(--space-2, 0.5rem) 0 0; padding-inline-start: 1.1rem; }
.btn--sm { font-size: 0.82rem; padding: 0.25rem 0.6rem; }
</style>
