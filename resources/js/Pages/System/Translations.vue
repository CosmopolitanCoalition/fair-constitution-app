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
import { computed } from 'vue';
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
});

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
</style>
