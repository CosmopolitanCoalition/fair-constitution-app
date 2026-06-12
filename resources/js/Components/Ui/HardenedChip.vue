<script setup>
/**
 * Ui/HardenedChip — marks a hardened (constitutionally protected) value.
 * Fixed title per the styleguide; lock glyph in gold.
 */
import Icon from '@/Components/Ui/Icon.vue';

const TITLE = 'Hardened · protected by the constitutional test suite';
</script>

<template>
    <span class="hardened" :title="TITLE">
        <Icon name="lock" size="sm" />
        <slot>{{ TITLE }}</slot>
    </span>
</template>
