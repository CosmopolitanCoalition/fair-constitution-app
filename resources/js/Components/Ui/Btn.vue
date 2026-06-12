<script setup>
/**
 * Ui/Btn — thin wrapper over the ported .btn classes.
 * `as` accepts 'button', 'a', or a component (e.g. Inertia Link).
 */
import { computed } from 'vue';
import Icon from '@/Components/Ui/Icon.vue';

const props = defineProps({
    variant: {
        type: String,
        default: null,
        validator: (v) => ['primary', 'secondary', 'ghost', 'gold', 'danger'].includes(v),
    },
    size: {
        type: String,
        default: null,
        validator: (v) => v === 'sm',
    },
    as: { type: [String, Object], default: 'button' },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    /** Tri-state: undefined → no aria-pressed at all (non-toggle button). */
    pressed: { type: Boolean, default: undefined },
    icon: { type: String, default: null },
});

const isNativeButton = computed(() => props.as === 'button');
const classes = computed(() => [
    props.variant ? `btn--${props.variant}` : null,
    props.size === 'sm' ? 'btn--sm' : null,
]);
</script>

<template>
    <component
        :is="as"
        class="btn"
        :class="classes"
        :type="isNativeButton ? type : undefined"
        :disabled="isNativeButton && disabled ? true : undefined"
        :aria-disabled="!isNativeButton && disabled ? 'true' : undefined"
        :aria-pressed="pressed === undefined ? undefined : String(pressed)"
    >
        <Icon v-if="icon" :name="icon" size="sm" />
        <slot />
    </component>
</template>
