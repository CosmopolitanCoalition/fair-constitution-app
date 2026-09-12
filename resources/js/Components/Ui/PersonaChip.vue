<script setup>
/**
 * Ui/PersonaChip — avatar + name + mono role list (dev-bar only in Phase A).
 */
import { computed } from 'vue';
import Avatar from '@/Components/Ui/Avatar.vue';
import { useI18n } from 'vue-i18n';
import { referenceLabel } from '@/lib/referenceLabels.js';

const props = defineProps({
    name: { type: String, required: true },
    initials: { type: String, default: null },
    /** Role IDs, e.g. ['R-03', 'R-04']. */
    roles: { type: Array, default: () => [] },
});

const resolvedInitials = computed(
    () =>
        props.initials ??
        props.name
            .split(/\s+/)
            .map((part) => part.charAt(0))
            .join('')
            .slice(0, 2)
            .toUpperCase(),
);
const { t } = useI18n();
const roleLabels = computed(() => props.roles.map((role) => referenceLabel(role, { translate: (key, fallback) => t(key, fallback) })));
</script>

<template>
    <span class="persona-chip">
        <Avatar :initials="resolvedInitials" />
        {{ name }}
        <span v-if="roles.length" class="persona-roles">{{ roleLabels.join(', ') }}</span>
    </span>
</template>
