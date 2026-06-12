<script setup>
/**
 * Ui/Avatar — initials disc. Decorative by default (aria-hidden) — pair it
 * with the visible name; pass `title` when it stands alone.
 */
defineProps({
    initials: { type: String, required: true },
    title: { type: String, default: null },
});
</script>

<template>
    <span
        class="avatar"
        :title="title || undefined"
        :aria-hidden="title ? undefined : 'true'"
    >{{ initials }}</span>
</template>
