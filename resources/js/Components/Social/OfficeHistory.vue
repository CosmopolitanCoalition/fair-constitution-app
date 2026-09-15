<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';

const { t } = useI18n();

defineProps({ history: { type: Object, required: true } });
const statuses = computed(() => ({
    active: t('c_civic_components.office_history.status_active', 'Active term'),
    completed: t('c_civic_components.office_history.status_completed', 'Term completed'),
    vacated: t('c_civic_components.office_history.status_vacated', 'Vacated'),
    removed: t('c_civic_components.office_history.status_removed', 'Removed'),
    seated: t('c_civic_components.office_history.status_seated', 'Serving'),
    elected: t('c_civic_components.office_history.status_elected', 'Elected; seating pending'),
    left: t('c_civic_components.office_history.status_left', 'Left office'),
    succeeded: t('c_civic_components.office_history.status_succeeded', 'Succeeded'),
    term_ended: t('c_civic_components.office_history.status_term_ended', 'Term ended'),
    retired: t('c_civic_components.office_history.status_retired', 'Retired'),
    removal_requested: t('c_civic_components.office_history.status_removal_requested', 'Removal requested'),
}));
</script>

<template>
    <section aria-labelledby="office-history-title">
        <h2 id="office-history-title">{{ t('c_civic_components.office_history.title', 'Office history') }}</h2>
        <p>{{ t('c_civic_components.office_history.intro', 'Current and past recorded terms and appointments, newest start first. A scheduled term end is not necessarily the date someone left office.') }}</p>
        <p v-if="history.notice" role="status">{{ history.notice }}</p>
        <article v-for="office in history.rows" :key="office.record_key" class="office-history-entry">
            <h3>{{ office.title }}<template v-if="office.jurisdiction"> · {{ office.jurisdiction }}</template></h3>
            <p>{{ statuses[office.status] || office.status.replaceAll('_', ' ') }}</p>
            <p>
                <template v-if="office.starts !== '0001-01-01'">{{ t('c_civic_components.office_history.started', { date: office.starts }) }}</template>
                <template v-else>{{ t('c_civic_components.office_history.no_start', 'Start date not recorded') }}</template>
                <template v-if="office.scheduled_end"> {{ t('c_civic_components.office_history.scheduled_end', { date: office.scheduled_end }) }}</template>
                <template v-if="office.left_on"> {{ t('c_civic_components.office_history.left_office', { date: office.left_on.slice(0, 10) }) }}</template>
            </p>
            <Link v-if="office.href" :href="office.href">{{ t('c_civic_components.office_history.open_link', 'Open institution or jurisdiction →') }}</Link>
        </article>
        <p v-if="!history.rows.length">{{ t('c_civic_components.office_history.no_offices', 'No recorded offices on this page.') }}</p>
        <HistoryPager :pages="history.pages" :first="history.pages.first" :only="['officeHistory']"
            cursor-key="profile_offices_cursor" :label="t('c_civic_components.office_history.pages', 'Office history pages')" />
    </section>
</template>

<style scoped>
.office-history-entry { padding-block: 1rem; border-bottom: 1px solid var(--gov-line, #334155); }
</style>
