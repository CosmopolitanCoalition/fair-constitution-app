<script setup>
import { inject } from 'vue';
import { useI18n } from 'vue-i18n';

// Teleport preserves the page's slot scope and destroys its guidance with the
// page. The shell target can mount later in the same render (Vue 3.5 defer).
const target = inject('cga:learn-target', null);
const { t } = useI18n();
</script>

<template>
    <Teleport v-if="target" :to="target" defer>
        <section class="page-learn-content"><slot /></section>
    </Teleport>
    <details v-else class="page-learn-content">
        <summary>{{ t('c_learn.ui.title', 'Learn') }}</summary>
        <slot />
    </details>
</template>

<style scoped>
.page-learn-content { display: grid; gap: var(--space-2); }
.page-learn-content :deep(p) { margin: 0; }
</style>
