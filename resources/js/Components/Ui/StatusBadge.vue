<script setup>
/**
 * Ui/StatusBadge — .badge--success/warning/danger/info/neutral.
 * Never color-only: the default slot label is required content.
 */
import Icon from '@/Components/Ui/Icon.vue';

defineProps({
    tone: {
        type: String,
        required: true,
        validator: (v) => ['success', 'warning', 'danger', 'info', 'neutral'].includes(v),
    },
    icon: { type: String, default: null },
});
</script>

<template>
    <span class="badge" :class="`badge--${tone}`">
        <Icon v-if="icon" :name="icon" size="sm" />
        <slot />
    </span>
</template>
