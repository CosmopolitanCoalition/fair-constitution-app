<script setup>
/**
 * Ui/StateStrip — full entity state machine, current node highlighted.
 * Machine definitions are PHP-owned and arrive in the entity payload —
 * the UI never hardcodes state lists.
 *
 * DISPLAY is plain language (V3 synthesis S8): the raw tokens are machine
 * grammar ("ping_monitoring") and the player chrome never speaks it —
 * plainState humanizes at this single point, so every state machine in the
 * app reads plainly at one stroke. MATCHING stays on the raw token; the
 * transform is display-only.
 */
import { plainState } from '@/lib/plain.js';

defineProps({
    states: { type: Array, required: true },
    current: { type: String, default: null },
});
</script>

<template>
    <div class="state-strip">
        <template v-for="(state, i) in states" :key="state">
            <span v-if="i > 0" class="state-arrow" aria-hidden="true">→</span>
            <span
                class="state-node"
                :class="{ 'state-node--current': state === current }"
                :aria-current="state === current ? 'step' : undefined"
            >{{ plainState(state) }}</span>
        </template>
    </div>
</template>
