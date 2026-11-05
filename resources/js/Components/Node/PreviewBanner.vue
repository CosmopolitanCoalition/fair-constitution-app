<script setup>
import { useI18n } from 'vue-i18n';
const { t } = useI18n();
</script>

<template>
    <p class="app-setting-card" role="note">
        <strong>{{ t('c_node.preview.title', 'Saved on the node.') }}</strong>
        {{ t('c_node.preview.body', 'Choices on this page are saved on this node for your account. Some of them only take effect once the matching wiring item in the rubric lands.') }}
    </p>
</template>
