<script setup>
/**
 * Ui/Card — .card / .card--inset, optionally rendered as <button class="card">
 * for selectable tiles (the UA ButtonText fix lives in the CSS).
 */
const props = defineProps({
    inset: { type: Boolean, default: false },
    as: {
        type: String,
        default: 'div',
        validator: (v) => ['div', 'section', 'button'].includes(v),
    },
    eyebrow: { type: String, default: null },
    title: { type: String, default: null },
});
</script>

<template>
    <component
        :is="as"
        class="card"
        :class="{ 'card--inset': inset }"
        :type="as === 'button' ? 'button' : undefined"
    >
        <div v-if="eyebrow || title || $slots.title" class="card-title">
            <span v-if="eyebrow" class="eyebrow">{{ eyebrow }}</span>
            <slot name="title">
                <h2 v-if="title">{{ title }}</h2>
            </slot>
        </div>
        <slot />
    </component>
</template>
