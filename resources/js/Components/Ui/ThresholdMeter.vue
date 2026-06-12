<script setup>
/**
 * Ui/ThresholdMeter — supermajority / threshold meter with EXPLICIT
 * denominator (`max` is ALL serving members, never just present).
 * The caption slots carry the honest wording, e.g.
 * "5 of 9 serving members in favor" / "needs 6 (2/3 of ALL serving) · Art. VII".
 */
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, required: true },
    /** The explicit denominator — ALL serving members. */
    max: { type: Number, required: true },
    threshold: { type: Number, default: null },
    label: { type: String, default: null },
});

const pct = (n) => (props.max > 0 ? Math.min(Math.max((n / props.max) * 100, 0), 100) : 0);
const fillPct = computed(() => pct(props.value));
const thresholdPct = computed(() => (props.threshold === null ? null : pct(props.threshold)));
const met = computed(() => props.threshold !== null && props.value >= props.threshold);
</script>

<template>
    <div class="meter-block">
        <div
            class="meter"
            role="meter"
            :aria-valuemin="0"
            :aria-valuemax="max"
            :aria-valuenow="value"
            :aria-label="label || undefined"
        >
            <span
                class="meter-fill"
                :class="{ 'meter-fill--met': met }"
                :style="{ 'inline-size': `${fillPct}%` }"
            ></span>
            <span
                v-if="thresholdPct !== null"
                class="meter-threshold"
                :style="{ 'inset-inline-start': `${thresholdPct}%` }"
            ></span>
        </div>
        <div v-if="$slots.default || $slots.note" class="meter-caption">
            <span><slot /></span>
            <span><slot name="note" /></span>
        </div>
    </div>
</template>
