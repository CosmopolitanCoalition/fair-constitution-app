<script setup>
/**
 * ════════════════════════════════════════════════════════════════════════
 * PLACEHOLDER — WI-8 builds the real Civic/Residency page (StateStrip,
 * ThresholdMeter, declared-boundary Leaflet map, F-IND-003 declare form,
 * F-IND-006 confirmation panel). This stub only proves the WI-5 backend
 * contract by dumping the controller props verbatim. Replace wholesale.
 * ════════════════════════════════════════════════════════════════════════
 */
import { usePage } from '@inertiajs/vue3';

defineProps({
    claim: { type: Object, default: null },
    threshold: { type: Number, default: null },
    panel: { type: String, default: 'undeclared' },
    associations: { type: Array, default: () => [] },
});

const page = usePage();
</script>

<template>
    <div style="padding: 1.5rem; font-family: monospace;">
        <h1 style="font-size: 1.1rem; margin-bottom: 0.5rem;">
            Civic / Residency — PLACEHOLDER (real page lands in WI-8)
        </h1>
        <p style="opacity: 0.7; margin-bottom: 1rem;">
            Props from ResidencyController::show — panel state: <strong>{{ panel }}</strong>
        </p>
        <pre style="white-space: pre-wrap; word-break: break-all; font-size: 0.8rem;">{{
            JSON.stringify({ claim, threshold, panel, associations, shared: page.props }, null, 2)
        }}</pre>
    </div>
</template>
