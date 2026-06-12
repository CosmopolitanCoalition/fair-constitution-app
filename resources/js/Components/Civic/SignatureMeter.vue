<script setup>
/**
 * Civic/SignatureMeter — petition signature meter (FE-C1;
 * PHASE_C_DESIGN_frontend.md §A.6). Thin ThresholdMeter wrapper so the
 * Petitions index rows and PetitionDetail render the IDENTICAL grammar —
 * and so the denominator is always the SNAPSHOT petitions.threshold_count,
 * never recomputed client-side (CLK-17; Art. II §6).
 *
 * Caption grammar from mockups/civic/petition-detail.html: "{signatures}
 * of {threshold} signatures" / "threshold {n} = {pct}% of population".
 */
import { computed } from 'vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

const props = defineProps({
    signatures: { type: Number, required: true },
    /** petitions.threshold_count snapshot. */
    threshold: { type: Number, required: true },
    /** initiative_petition_threshold_pct at snapshot. */
    pct: { type: String, default: '5.00' },
    /** List-row variant (Petitions index) — no met badge. */
    compact: { type: Boolean, default: false },
});

/* Headroom so a met meter still shows the threshold tick inside the track. */
const max = computed(() => Math.max(props.threshold * 1.15, props.signatures));
const met = computed(() => props.signatures >= props.threshold);
</script>

<template>
    <div class="stack" style="gap: var(--space-1)">
        <ThresholdMeter
            :value="signatures"
            :max="max"
            :threshold="threshold"
            :label="`Petition signatures — threshold ${threshold.toLocaleString()}`"
        >
            {{ signatures.toLocaleString() }} signatures
            <template #note>
                <template v-if="compact">{{ threshold.toLocaleString() }} needed · {{ pct }}%</template>
                <template v-else>threshold {{ threshold.toLocaleString() }} = {{ pct }}% of population · CLK-17</template>
            </template>
        </ThresholdMeter>
        <div v-if="met && !compact" class="cluster">
            <StatusBadge tone="success" icon="check">Threshold reached</StatusBadge>
        </div>
    </div>
</template>
