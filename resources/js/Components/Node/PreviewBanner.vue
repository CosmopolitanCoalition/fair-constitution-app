<script setup>
import { useI18n } from 'vue-i18n';
const { t } = useI18n();
</script>

<template>
    <p class="app-setting-card" role="note">
        <strong>{{ t('c_node.preview.title', 'Preview.') }}</strong>
        {{ t('c_node.preview.body', 'Choices on this page are held in this browser. Saving them on the node comes with the wiring work listed in the rubric.') }}
    </p>
</template>
