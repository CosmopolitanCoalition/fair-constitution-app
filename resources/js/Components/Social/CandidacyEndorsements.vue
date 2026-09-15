<script setup>
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';

const { t } = useI18n();

const props = defineProps({
    organizations: { type: Object, default: null },
    individuals: { type: Object, default: null },
    web: { type: Object, default: null },
});
const page = usePage();
const busy = ref(false);
const error = ref('');
function expand(userId) {
    if (busy.value) return;
    const url = new URL(page.url, 'http://profile.invalid');
    if (userId) url.searchParams.set('public_endorser', userId);
    else url.searchParams.delete('public_endorser');
    url.searchParams.delete('endorsement_web_cursor');
    router.get(url.pathname + url.search, {}, {
        only: ['endorsementWeb'], preserveState: true, preserveScroll: true,
        onStart: () => { busy.value = true; error.value = ''; },
        onFinish: () => { busy.value = false; },
        onError: errors => { error.value = Object.values(errors)[0] || t('c_civic_components.candidacy_endorsements.error_default', 'Connections could not load. Select the person again to retry.'); },
    });
}
</script>

<template>
    <section v-if="organizations" :aria-label="t('c_civic_components.candidacy_endorsements.aria_org', 'Organization endorsements')">
        <h3>{{ t('c_civic_components.candidacy_endorsements.organizations', 'Organizations') }}</h3>
        <p v-if="organizations.notice" role="status">{{ organizations.notice }}</p>
        <ul class="endorsement-list">
            <li v-for="org in organizations.rows" :key="org.id"><Link :href="org.href">{{ org.name }}</Link></li>
        </ul>
        <p v-if="!organizations.rows.length">{{ t('c_civic_components.candidacy_endorsements.no_org', 'No organization endorsements on this page.') }}</p>
        <HistoryPager :pages="organizations.pages" :first="organizations.pages.first" :only="['endorsementOrganizations']"
            cursor-key="endorsement_orgs_cursor" :label="t('c_civic_components.candidacy_endorsements.org_pages', 'Organization endorsement pages')" />
    </section>
    <section v-if="individuals" :aria-label="t('c_civic_components.candidacy_endorsements.aria_individual', 'Individual endorsements')">
        <h3>{{ t('c_civic_components.candidacy_endorsements.individuals', 'Individuals') }}</h3>
        <p>{{ t('c_civic_components.candidacy_endorsements.counts', { total: individuals.counts.total, pub: individuals.counts.public, priv: individuals.counts.private }) }}</p>
        <p v-if="individuals.notice" role="status">{{ individuals.notice }}</p>
        <ul class="endorsement-list">
            <li v-for="endorser in individuals.rows" :key="endorser.user_id">
                <Link :href="`/people?who=${endorser.user_id}`">{{ endorser.name }}</Link>
                <span v-if="endorser.alsoCandidate"> {{ t('c_civic_components.candidacy_endorsements.also_candidate', '· also a candidate') }}</span>
                <button type="button" :disabled="busy" :aria-expanded="web?.endorser?.user_id === endorser.user_id"
                    aria-controls="public-endorsement-connections" @click="expand(endorser.user_id)">
                    {{ t('c_civic_components.candidacy_endorsements.other_public', 'Other public endorsements') }}<span class="sr-only"> {{ t('c_civic_components.candidacy_endorsements.by_name', { name: endorser.name }) }}</span>
                </button>
            </li>
        </ul>
        <p v-if="!individuals.rows.length">{{ t('c_civic_components.candidacy_endorsements.no_individual', 'No public individual endorsements on this page.') }}</p>
        <HistoryPager :pages="individuals.pages" :first="individuals.pages.first" :only="['endorsementIndividuals']"
            cursor-key="endorsement_people_cursor" :label="t('c_civic_components.candidacy_endorsements.individual_pages', 'Individual endorsement pages')" />
    </section>
    <div id="public-endorsement-connections" :aria-busy="busy">
        <span role="status">{{ busy ? t('c_civic_components.candidacy_endorsements.loading', 'Loading public connections…') : '' }}</span>
        <p v-if="error" role="alert">{{ error }}</p>
        <section v-if="web" :aria-label="t('c_civic_components.candidacy_endorsements.aria_selected', 'Selected person\'s public endorsements')">
            <p v-if="web.notice" role="status">{{ web.notice }}</p>
            <template v-if="web.endorser">
                <h3>{{ t('c_civic_components.candidacy_endorsements.other_by_name', { name: web.endorser.name }) }}</h3>
                <p class="gloss">{{ t('c_civic_components.candidacy_endorsements.gloss', 'In this election. Private endorsements are never listed.') }}</p>
                <ul class="endorsement-list">
                    <li v-for="target in web.rows" :key="target.candidacy_id">
                        <Link :href="`/people?who=${target.user_id}&tab=candidacy&candidacy=${target.candidacy_id}`">{{ target.name }}</Link>
                    </li>
                </ul>
                <p v-if="!web.rows.length">{{ t('c_civic_components.candidacy_endorsements.no_other', 'No other public endorsements on this page.') }}</p>
                <HistoryPager :pages="web.pages" :first="web.pages.first" :only="['endorsementWeb']"
                    cursor-key="endorsement_web_cursor" :label="t('c_civic_components.candidacy_endorsements.web_pages', 'Public connection pages')" />
            </template>
            <button type="button" :disabled="busy" @click="expand(null)">{{ t('c_civic_components.candidacy_endorsements.close', 'Close connections') }}</button>
        </section>
    </div>
    <p class="citation">{{ t('c_civic_components.candidacy_endorsements.citation', 'Endorsements inform your choice; they do not limit who can run. Individuals choose whether to make their endorsements public.') }}</p>
</template>

<style scoped>
.endorsement-list { padding-inline-start: 1.25rem; }
.endorsement-list li { margin-block: 0.75rem; }
.endorsement-list button { margin-inline-start: 0.75rem; }
</style>
