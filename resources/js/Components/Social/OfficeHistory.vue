<script setup>
import { Link } from '@inertiajs/vue3';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
defineProps({ history: { type: Object, required: true } });
const statuses = { active: 'Active term', completed: 'Term completed', vacated: 'Vacated', removed: 'Removed',
    seated: 'Serving', elected: 'Elected; seating pending', left: 'Left office', succeeded: 'Succeeded',
    term_ended: 'Term ended', retired: 'Retired', removal_requested: 'Removal requested' };
</script>

<template>
    <section aria-labelledby="office-history-title">
        <h2 id="office-history-title">Office history</h2>
        <p>Current and past recorded terms and appointments, newest start first. A scheduled term end is not necessarily the date someone left office.</p>
        <p v-if="history.notice" role="status">{{ history.notice }}</p>
        <article v-for="office in history.rows" :key="office.record_key" class="office-history-entry">
            <h3>{{ office.title }}<template v-if="office.jurisdiction"> · {{ office.jurisdiction }}</template></h3>
            <p>{{ statuses[office.status] || office.status.replaceAll('_', ' ') }}</p>
            <p>
                <template v-if="office.starts !== '0001-01-01'">Started {{ office.starts }}</template>
                <template v-else>Start date not recorded</template>
                <template v-if="office.scheduled_end"> · Scheduled term end {{ office.scheduled_end }}</template>
                <template v-if="office.left_on"> · Left office {{ office.left_on.slice(0, 10) }}</template>
            </p>
            <Link v-if="office.href" :href="office.href">Open institution or jurisdiction →</Link>
        </article>
        <p v-if="!history.rows.length">No recorded offices on this page.</p>
        <HistoryPager :pages="history.pages" :first="history.pages.first" :only="['officeHistory']"
            cursor-key="profile_offices_cursor" label="Office history pages" />
    </section>
</template>

<style scoped>
.office-history-entry { padding-block: 1rem; border-bottom: 1px solid var(--gov-line, #334155); }
</style>
