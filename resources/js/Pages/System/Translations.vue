<script setup>
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
import { router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** scripts/i18n/check.mjs output, or null when it has never been run here. */
    coverage: { type: Object, default: null },
    /** Live run deck, read off the workers' own heartbeat files. */
    live: { type: Object, default: null },
});

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
    return new Date(props.coverage.generated_at).toLocaleString();
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

const CODE_LABELS = {
    'C1-missing': 'Key missing from a locale',
    'C2-orphan': 'Key present in a locale but not in English',
    'C3-placeholder': 'Placeholder mismatch ({name} tokens differ)',
    'C4-idtoken': 'ID token or citation not byte-identical',
    'C5-compile': 'Message does not compile in vue-i18n',
    'C6-empty': 'Empty message',
    'C7-registry': 'PHP and JS locale lists disagree',
    'C0-parse': 'Catalog file could not be parsed',
};

const failures = computed(() => props.coverage?.failures ?? 0);
const failureCodes = computed(() =>
    Object.entries(props.coverage?.failure_codes ?? {})
        .sort((a, b) => b[1] - a[1])
        .map(([code, count]) => ({ code, count, label: CODE_LABELS[code] ?? code })),
);

const localeColumns = [
    { key: 'locale', label: 'Locale', mono: true },
    { key: 'bar', label: 'Coverage' },
    { key: 'pct', label: '%', align: 'right' },
    { key: 'present', label: 'Carried', align: 'right' },
    { key: 'missing', label: 'Missing', align: 'right' },
    { key: 'identical', label: 'Same as English', align: 'right' },
];

const localeRows = computed(() => locales.value.map((l) => ({ ...l, bar: l.pct })));

/* Namespace grid: one row per namespace, one column per locale. */
const nsColumns = computed(() => [
    { key: 'namespace', label: 'Namespace', mono: true },
    { key: 'total', label: 'Messages', align: 'right' },
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
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            <p class="page-intro">
                How much of this application a person can read in their own language. Every figure
                below is produced by the translation gate
                (<code data-no-i18n>scripts/i18n/check.mjs</code>) — the same run that fails a build
                when a language falls behind. Nothing on this page is measured a second way.
            </p>
        </template>

        <!-- ── LIVE RUN DECK ───────────────────────────────────────────────
             Shown whenever workers exist. The coverage half below answers
             "where are we"; this half answers "what is happening right now",
             which is a different question and needs its own surface. -->
        <Card v-if="workers.length" :title="runLive ? 'Translating now' : 'Last run'"
              eyebrow="live">
            <div class="stat-row">
                <Stat :value="activeWorkers" label="workers active" :accent="activeWorkers > 0" />
                <Stat :value="`${deck?.rate ?? 0}/s`" label="strings per second" />
                <Stat :value="(deck?.strings_done ?? 0).toLocaleString()" label="translated this run" />
                <Stat :value="etaText" label="estimated remaining" />
            </div>

            <p v-if="halted" class="muted">
                <StatusBadge tone="warning">Halt requested</StatusBadge>
                Workers stop at their next committed chunk — at most one chunk is redone.
            </p>
            <p v-if="deckError" class="muted">{{ deckError }}</p>

            <!-- one line per worker: what it is, what it is doing, how fast -->
            <DataTable
                :columns="[
                    { key: 'locale', label: 'Language', mono: true },
                    { key: 'state', label: 'State' },
                    { key: 'namespace', label: 'Area', mono: true },
                    { key: 'bar', label: 'Progress' },
                    { key: 'done', label: 'Done', align: 'right' },
                    { key: 'rate', label: '/sec', align: 'right' },
                    { key: 'device', label: 'On', mono: true },
                ]"
                :rows="workers"
                row-key="id"
                caption="Translation workers currently running"
            >
                <template #cell-state="{ row }">
                    <StatusBadge
                        :tone="row.stale ? 'danger' : row.state === 'translating' ? 'success'
                               : row.state === 'done' ? 'neutral' : 'info'"
                    >{{ row.stale ? 'silent' : row.state }}</StatusBadge>
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
                    <span data-no-i18n>{{ w.locale }}</span> — in flight right now:
                </p>
                <ul class="inflight-list">
                    <li v-for="(t, i) in w.current" :key="i" class="inflight-item">{{ t }}</li>
                </ul>
            </div>

            <p class="muted">
                Each worker holds its own copy of the translation model, so the GPU — not the CPU —
                sets how many can run at once. Work commits in small batches: halting, or a crash,
                costs at most one batch and never leaves a half-written language.
            </p>
        </Card>

        <!-- Never measured on this box: say so plainly rather than render zeros. -->
        <Card v-if="!measured" title="Not measured yet">
            <p>
                No coverage artifact on this instance. Run the gate to produce one:
            </p>
            <p><code data-no-i18n>node scripts/i18n/check.mjs</code></p>
            <p class="muted">
                It writes <code data-no-i18n>resources/js/i18n/coverage.json</code> and exits
                non-zero while any language is behind.
            </p>
        </Card>

        <template v-else>
            <Card title="Where we are" eyebrow="headline">
                <div class="stat-row">
                    <Stat :value="sourceKeys.toLocaleString()" label="translatable messages in the app" />
                    <Stat :value="namespaces" label="namespaces" />
                    <Stat :value="locales.length" label="languages present" />
                    <Stat
                        :value="`${bestPct}%`"
                        label="best-covered language"
                        :accent="bestPct >= 90"
                    />
                </div>
                <p class="muted">
                    <template v-if="totalMissing > 0">
                        {{ totalMissing.toLocaleString() }} message-translations are still owed across
                        the languages below.
                    </template>
                    <template v-else>Every registered language carries every message.</template>
                </p>
                <p v-if="generatedAt" class="muted">
                    Measured {{ generatedAt }}.
                </p>
            </Card>

            <Card title="By language">
                <DataTable
                    :columns="localeColumns"
                    :rows="localeRows"
                    row-key="locale"
                    caption="Translation coverage per language"
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
                        <span data-no-i18n>{{ row.present.toLocaleString() }}</span>
                    </template>
                    <template #cell-missing="{ row }">
                        <span data-no-i18n>{{ row.missing.toLocaleString() }}</span>
                    </template>
                </DataTable>
                <p class="muted">
                    <strong>Same as English</strong> counts values byte-identical to the source — a
                    proper noun that legitimately does not translate, or a string nobody has
                    translated yet. It is a hint for reviewers, not a failure.
                </p>
            </Card>

            <Card :title="`Gate findings (${failures.toLocaleString()})`">
                <p v-if="!failures">The gate passes. No language is behind and every message compiles.</p>
                <template v-else>
                    <DataTable
                        :columns="[
                            { key: 'label', label: 'Finding' },
                            { key: 'code', label: 'Code', mono: true },
                            { key: 'count', label: 'Count', align: 'right' },
                        ]"
                        :rows="failureCodes"
                        row-key="code"
                        caption="Translation gate findings by code"
                    >
                        <template #cell-count="{ row }">
                            <span data-no-i18n>{{ row.count.toLocaleString() }}</span>
                        </template>
                    </DataTable>
                    <p class="muted">
                        Run <code data-no-i18n>node scripts/i18n/check.mjs</code> for the per-key
                        detail behind each code.
                    </p>
                </template>
            </Card>

            <Card title="By namespace">
                <p class="muted">
                    Messages per area of the app, and how many of them each language carries.
                    A namespace maps to a page folder.
                </p>
                <DataTable
                    :columns="nsColumns"
                    :rows="nsRows"
                    row-key="namespace"
                    caption="Messages per namespace per language"
                />
            </Card>

            <CitationLine text="Records publish with translations · WF-SYS-03" />
        </template>
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
</style>
