<script setup>
/**
 * Ui/PersonaChip — avatar + name + mono role list (dev-bar only in Phase A).
 */
import { computed } from 'vue';
import Avatar from '@/Components/Ui/Avatar.vue';

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
</script>

<template>
    <span class="persona-chip">
        <Avatar :initials="resolvedInitials" />
        {{ name }}
        <span v-if="roles.length" class="persona-roles">{{ roles.join(' ') }}</span>
    </span>
</template>
