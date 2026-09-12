<script setup>
/**
 * Ui/HardenedChip — marks a hardened (constitutionally protected) value.
 * Fixed title per the styleguide; lock glyph in gold.
 */
import Icon from '@/Components/Ui/Icon.vue';
import ReferenceText from './ReferenceText.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <span class="hardened" :title="t('c_references.rule_hint', 'Checked against the governing rules')">
        <Icon name="lock" size="sm" />
        <ReferenceText><slot>{{ t('c_references.constitutional_rule', 'Constitutional rule') }}</slot></ReferenceText>
    </span>
</template>
