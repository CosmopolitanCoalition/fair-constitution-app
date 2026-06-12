<script setup>
/**
 * Ui/Banner — .banner--info/warning/emergency/demo.
 * role auto-derives from tone: warnings/emergencies assert (alert),
 * info/demo announce politely (status).
 */
import { computed } from 'vue';
import Icon from '@/Components/Ui/Icon.vue';

const DEFAULT_ICONS = {
    info: 'info',
    warning: 'alert-triangle',
    emergency: 'alert-triangle',
    demo: 'info',
};

const props = defineProps({
    tone: {
        type: String,
        required: true,
        validator: (v) => ['info', 'warning', 'emergency', 'demo'].includes(v),
    },
    title: { type: String, default: null },
    icon: { type: String, default: null },
});

const role = computed(() => (['warning', 'emergency'].includes(props.tone) ? 'alert' : 'status'));
const iconName = computed(() => props.icon ?? DEFAULT_ICONS[props.tone]);
</script>

<template>
    <div class="banner" :class="`banner--${tone}`" :role="role">
        <Icon :name="iconName" />
        <div>
            <span v-if="title" class="banner-title">{{ title }}</span>
            <slot />
        </div>
    </div>
</template>
