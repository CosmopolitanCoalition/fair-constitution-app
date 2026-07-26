<script setup>
/**
 * StageBars — ONE progress idiom for every build stage in the app.
 *
 * The operator's end state is that accepting geodata on a fresh box builds the
 * whole world before a player arrives — boundaries, district maps,
 * legislatures, seats, institutions, rooms — and he watches it happen. That is
 * a PIPELINE OF STAGES that will keep growing, not a series of bespoke
 * screens, so the bars live in one component and every stage renders the same.
 *
 * Extracted from the district mapper's Step-3 bars, which were the original,
 * and reconciled with the sim console's `stages[]` contract so the two agree.
 * If you are adding a build stage: emit the contract below and render it here.
 * Do not write a third set of bars.
 *
 * THE CONTRACT (one object per stage):
 *   kind        machine name, shown small and monospaced
 *   label       what a person calls it
 *   phase       optional grouping key
 *   total       denominator — how many there should be
 *   done        numerator — how many there are
 *   running     currently in flight (drives the pulse dot)
 *   review      needs attention; rendered amber, never hidden
 *   is_current  highlights the stage the run is working now
 *
 * Counts tween rather than jump, so a number that climbs reads as motion
 * instead of flicker. Duration is deliberately shorter than the caller's poll
 * interval so a value always settles before the next one arrives.
 */
import { reactive, watch } from 'vue';

const props = defineProps({
    stages: { type: Array, default: () => [] },
    /** Caller's poll interval in ms — the tween stays comfortably inside it. */
    pollMs: { type: Number, default: 2000 },
});

const shown = reactive({});
const anim = {};

function easeOutCubic(t) {
    return 1 - Math.pow(1 - t, 3);
}

function tweenTo(key, target) {
    const start = shown[key] ?? target;

    if (start === target) {
        shown[key] = target;
        return;
    }

    if (anim[key]) cancelAnimationFrame(anim[key]);

    const t0 = performance.now();
    const dur = Math.max(300, props.pollMs - 300);

    const step = () => {
        const p = Math.min((performance.now() - t0) / dur, 1);
        shown[key] = Math.round(start + (target - start) * easeOutCubic(p));
        if (p < 1) anim[key] = requestAnimationFrame(step);
    };

    anim[key] = requestAnimationFrame(step);
}

watch(
    () => props.stages,
    (stages) => {
        for (const s of stages ?? []) tweenTo(s.kind, s.done ?? 0);
    },
    { immediate: true, deep: true },
);

const fmt = (n) => Number(n ?? 0).toLocaleString();

function pct(done, total) {
    if (!total) return 0;
    return Math.min(100, Math.max(0, (done / total) * 100));
}

/** A stage is complete only when nothing is left needing attention. */
function isSettled(s) {
    return s.total > 0 && s.done >= s.total && !s.review;
}
</script>

<template>
    <div class="space-y-2">
        <div
            v-for="s in stages"
            :key="s.kind"
            class="rounded-lg border p-3"
            :class="s.is_current
                ? 'border-emerald-800/60 bg-emerald-950/20'
                : 'border-gray-700/50 bg-gray-900/30'"
        >
            <div class="flex items-baseline justify-between gap-3">
                <div class="flex items-baseline gap-2">
                    <span v-if="isSettled(s)" class="text-emerald-500" aria-hidden="true">✓</span>
                    <span
                        v-else-if="s.running"
                        class="inline-flex h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400"
                        aria-hidden="true"
                    />
                    <span class="text-sm text-gray-200">{{ s.label }}</span>
                    <span class="font-mono text-xs text-gray-500">{{ s.kind }}</span>
                </div>
                <div class="font-mono text-xs text-gray-400 tabular-nums">
                    {{ fmt(shown[s.kind] ?? s.done) }}/{{ fmt(s.total) }}
                    <span v-if="s.review" class="ml-2 text-amber-400">{{ fmt(s.review) }} to review</span>
                </div>
            </div>

            <div
                class="mt-2 h-1.5 overflow-hidden rounded bg-gray-800"
                role="meter"
                :aria-valuenow="Math.round(pct(s.done, s.total))"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="s.label"
            >
                <div
                    class="h-full rounded transition-[width] duration-700 ease-out"
                    :class="s.review && s.done + s.review >= s.total ? 'bg-amber-500' : 'bg-emerald-500'"
                    :style="{ width: pct(s.done, s.total) + '%' }"
                />
            </div>

            <p v-if="s.note" class="mt-1.5 text-xs text-gray-500">{{ s.note }}</p>
        </div>

        <p v-if="!stages.length" class="text-sm text-gray-500">
            Nothing to build yet — this fills in once the world has boundaries.
        </p>
    </div>
</template>
