<script setup>
/**
 * Ui/StateStrip — full entity state machine, current node highlighted.
 * Machine definitions are PHP-owned and arrive in the entity payload —
 * the UI never hardcodes state lists.
 */
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
            >{{ state }}</span>
        </template>
    </div>
</template>
