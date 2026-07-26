<script setup>
/**
 * Social/Reach — Phase I, the enrolment gauge.
 *
 * reach = verified residents ÷ population estimate, so a hamlet and a metropolis
 * are measured on equal footing.
 *
 * ⚑ A GAUGE, NEVER A LEVER. It changes no vote, no seat, no right. There is
 * deliberately NO action on this page — not a form, not a button that does
 * anything. A surface that could act on reach would be the first step toward
 * the thing the charter forbids.
 *
 * ⚑ NOT the Art. VI §3 legitimacy verdict. That is the wartime allegiance test
 * tied to restoration. This is a headcount ratio.
 *
 * The page switches on `gauge.state` and NEVER infers from the numbers. Four
 * honest states, and the order matters:
 *   unmeasurable — no population estimate. Never rendered as 0%: "we don't know
 *                  how many live here" and "nobody lives here" are different
 *                  claims, and collapsing them slanders the place.
 *   activating   — the count is below the k-anonymity floor, or is suppressed
 *                  because publishing it would reveal a smaller neighbour by
 *                  subtraction. No number, no curve.
 *   measured     — a real ratio.
 *   capped       — more verified residents than the estimate admits, i.e. the
 *                  ESTIMATE lags the place. Bar shows full; the raw figure is
 *                  disclosed rather than silently clamped.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    jurisdictions: { type: Array, default: () => [] },
    selected: { type: String, default: null },
    gauge: { type: Object, default: null },
    series: { type: Array, default: () => [] },
    tier: { type: Object, default: null },
});

const MICRO = 1_000_000;

const pct = computed(() => {
    if (!props.gauge?.ratio_micro) return null;
    return Math.min(100, (props.gauge.ratio_micro / MICRO) * 100);
});

const rawPct = computed(() =>
    props.gauge?.ratio_micro ? (props.gauge.ratio_micro / MICRO) * 100 : null,
);

const fmt = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString());

/** Points only where a ratio was actually published — a gap is honest, a zero is not. */
const points = computed(() => props.series.filter((s) => s.ratio_micro !== null));

const sparkline = computed(() => {
    const pts = points.value;
    if (pts.length < 2) return null;
    const vals = pts.map((p) => p.ratio_micro);
    const max = Math.max(...vals, 1);
    return pts
        .map((p, i) => `${(i / (pts.length - 1)) * 100},${30 - (p.ratio_micro / max) * 28}`)
        .join(' ');
});

function selectJurisdiction(event) {
    router.get('/reach', { jurisdiction: event.target.value }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <PageScaffold :surface="surface" title="Reach">
        <template #intro>
            <p class="text-sm text-gray-400">
                How many of the people who live in a place have verified their residency here.
                A signal of how alive a place is, and nothing more.
            </p>
        </template>

        <Banner tone="info" class="mb-4">
            A gauge, never a lever — reach changes no vote, no seat and no right. Only places are
            measured, never people. It is not the Art. VI §3 legitimacy verdict, which is a
            question about allegiance and restoration, not a headcount.
        </Banner>

        <div class="mb-4">
            <label class="block text-xs uppercase tracking-wide text-gray-400 mb-1" for="reach-jurisdiction">Place</label>
            <select
                id="reach-jurisdiction"
                class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm w-full max-w-md"
                :value="selected"
                @change="selectJurisdiction"
            >
                <option v-for="j in jurisdictions" :key="j.id" :value="j.id">
                    {{ j.name }} <template v-if="j.adm_level === 0">· the planet</template>
                </option>
            </select>
        </div>

        <Card v-if="gauge" class="mb-4">
            <!-- No estimate: never shown as 0%. -->
            <div v-if="gauge.state === 'unmeasurable'">
                <StatusBadge tone="neutral">No estimate</StatusBadge>
                <p class="text-sm text-gray-400 mt-2">
                    There is no population estimate for this place yet, so reach cannot be measured.
                    That is not the same as nobody living here.
                </p>
            </div>

            <!-- Suppressed: no number, no curve. -->
            <div v-else-if="gauge.state === 'activating'">
                <StatusBadge tone="warning">Waking up</StatusBadge>
                <p class="text-sm text-gray-400 mt-2">
                    Too few people have verified here to show a number safely — publishing it could
                    point at individual people. The gauge appears once enough residents have joined.
                </p>
            </div>

            <!-- Measured / capped. -->
            <div v-else>
                <div class="flex items-baseline gap-3">
                    <span class="text-4xl font-semibold text-amber-400 tabular-nums">
                        {{ rawPct !== null ? rawPct.toFixed(1) : '—' }}%
                    </span>
                    <StatusBadge v-if="gauge.state === 'capped'" tone="warning">Estimate lags</StatusBadge>
                </div>

                <div
                    class="h-2 bg-gray-800 rounded overflow-hidden mt-3"
                    role="meter"
                    :aria-valuenow="pct ? Math.round(pct) : 0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="Verified residents as a share of the population estimate"
                >
                    <div class="h-full bg-amber-500 transition-all" :style="{ width: (pct ?? 0) + '%' }" />
                </div>

                <p class="text-xs text-gray-400 mt-2">
                    {{ fmt(gauge.verified_residents) }} verified residents of
                    {{ fmt(gauge.population_estimate) }}
                    <template v-if="gauge.population_year">· estimate {{ gauge.population_year }}</template>
                    <template v-if="gauge.population_provenance">· {{ gauge.population_provenance }}</template>
                </p>

                <p v-if="gauge.state === 'capped'" class="text-xs text-amber-400/80 mt-1">
                    More people have verified here than the estimate allows for — the estimate lags
                    this place. The true figure is {{ rawPct !== null ? rawPct.toFixed(1) : '—' }}%.
                </p>
            </div>
        </Card>

        <Card v-if="sparkline" class="mb-4">
            <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">Over time</div>
            <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="w-full h-16" aria-hidden="true">
                <polyline :points="sparkline" fill="none" stroke="currentColor" stroke-width="1" class="text-amber-500" />
            </svg>
            <p class="text-xs text-gray-500 mt-1">
                Nightly snapshots. On any night the count was too small to be safe, the curve simply
                stops rather than showing a zero.
            </p>
        </Card>

        <Card v-if="tier">
            <div class="text-xs uppercase tracking-wide text-gray-400 mb-2">
                When can a government start here?
            </div>

            <div class="flex flex-wrap gap-6">
                <Stat label="Residents needed" :value="fmt(tier.threshold)" />
                <Stat label="Verified so far" :value="tier.count_hidden ? 'hidden' : fmt(tier.verified)" />
                <Stat
                    label="Still to go"
                    :value="tier.count_hidden ? 'hidden' : (tier.remaining === 0 ? 'threshold met' : fmt(tier.remaining))"
                />
            </div>

            <p v-if="tier.count_hidden" class="text-xs text-gray-400 mt-3">
                The count stays hidden while it is small, so this page cannot say how many more are
                needed without revealing how many are already here.
            </p>

            <p class="text-xs text-gray-500 mt-3">
                <template v-if="tier.enabled">
                    The threshold comes from the population: about the cube root of how many people
                    live here, held between {{ tier.params.floor }} and {{ tier.params.cap }}.
                </template>
                <template v-else>
                    Thresholds are not switched on in this world yet — a single verified resident is
                    enough to start a government.
                </template>
            </p>

            <Banner tone="neutral" class="mt-3">
                This gates only when a government may <em>start</em> — never anyone's vote or right to
                stand for office. Those need residency and nothing else.
            </Banner>
        </Card>
    </PageScaffold>
</template>
