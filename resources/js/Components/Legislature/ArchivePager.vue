<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
const { t } = useI18n();
defineProps({ page: { type: Object, required: true }, label: { type: String, required: true } });
</script>

<template>
    <nav v-if="page.previous || page.next" class="archive-pagination" :aria-label="label">
        <Link v-if="page.previous" :href="page.previous" preserve-scroll>{{ t('c_institution_components.archive_pager.previous', 'Previous page') }}</Link>
        <Link v-if="page.next" :href="page.next" preserve-scroll>{{ t('c_institution_components.archive_pager.next', 'Next page') }}</Link>
    </nav>
</template>

<style scoped>
.archive-pagination { display: flex; gap: 1rem; margin-block-start: 1rem; }
.archive-pagination a { display: inline-flex; align-items: center; min-block-size: 44px; padding-inline: .5rem; }
</style>
