<script setup>
/**
 * Ui/Banner — .banner--info/warning/emergency/demo.
 * role auto-derives from tone: warnings/emergencies assert (alert),
 * info/demo announce politely (status) — overridable via `role` for the
 * mockup-contracted cases where a warning-toned banner is ambient state,
 * not an interruption (electoral phase banners are role="status" in every
 * mockup; PHASE_B_DESIGN_frontend.md §A.7).
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
    /** Override the tone-derived ARIA role ('status' | 'alert'). */
    role: { type: String, default: null },
});

const resolvedRole = computed(
    () => props.role ?? (['warning', 'emergency'].includes(props.tone) ? 'alert' : 'status'),
);
const iconName = computed(() => props.icon ?? DEFAULT_ICONS[props.tone]);
</script>

<template>
    <div class="banner" :class="`banner--${tone}`" :role="resolvedRole">
        <Icon :name="iconName" />
        <div>
            <span v-if="title" class="banner-title">{{ title }}</span>
            <slot />
        </div>
    </div>
</template>
