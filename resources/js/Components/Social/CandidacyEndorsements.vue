<script setup>
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';

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
        onError: errors => { error.value = Object.values(errors)[0] || 'Connections could not load. Select the person again to retry.'; },
    });
}
</script>

<template>
    <section v-if="organizations" aria-label="Organization endorsements">
        <h3>Organizations</h3>
        <p v-if="organizations.notice" role="status">{{ organizations.notice }}</p>
        <ul class="endorsement-list">
            <li v-for="org in organizations.rows" :key="org.id"><Link :href="org.href">{{ org.name }}</Link></li>
        </ul>
        <p v-if="!organizations.rows.length">No organization endorsements on this page.</p>
        <HistoryPager :pages="organizations.pages" :first="organizations.pages.first" :only="['endorsementOrganizations']"
            cursor-key="endorsement_orgs_cursor" label="Organization endorsement pages" />
    </section>
    <section v-if="individuals" aria-label="Individual endorsements">
        <h3>Individuals</h3>
        <p>{{ individuals.counts.total }} endorsements · {{ individuals.counts.public }} public / {{ individuals.counts.private }} private</p>
        <p v-if="individuals.notice" role="status">{{ individuals.notice }}</p>
        <ul class="endorsement-list">
            <li v-for="endorser in individuals.rows" :key="endorser.user_id">
                <Link :href="`/people?who=${endorser.user_id}`">{{ endorser.name }}</Link>
                <span v-if="endorser.alsoCandidate"> · also a candidate</span>
                <button type="button" :disabled="busy" :aria-expanded="web?.endorser?.user_id === endorser.user_id"
                    aria-controls="public-endorsement-connections" @click="expand(endorser.user_id)">
                    Other public endorsements<span class="sr-only"> by {{ endorser.name }}</span>
                </button>
            </li>
        </ul>
        <p v-if="!individuals.rows.length">No public individual endorsements on this page.</p>
        <HistoryPager :pages="individuals.pages" :first="individuals.pages.first" :only="['endorsementIndividuals']"
            cursor-key="endorsement_people_cursor" label="Individual endorsement pages" />
    </section>
    <div id="public-endorsement-connections" :aria-busy="busy">
        <span role="status">{{ busy ? 'Loading public connections…' : '' }}</span>
        <p v-if="error" role="alert">{{ error }}</p>
        <section v-if="web" aria-label="Selected person's public endorsements">
            <p v-if="web.notice" role="status">{{ web.notice }}</p>
            <template v-if="web.endorser">
                <h3>Other public endorsements by {{ web.endorser.name }}</h3>
                <p class="gloss">In this election. Private endorsements are never listed.</p>
                <ul class="endorsement-list">
                    <li v-for="target in web.rows" :key="target.candidacy_id">
                        <Link :href="`/people?who=${target.user_id}&tab=candidacy&candidacy=${target.candidacy_id}`">{{ target.name }}</Link>
                    </li>
                </ul>
                <p v-if="!web.rows.length">No other public endorsements on this page.</p>
                <HistoryPager :pages="web.pages" :first="web.pages.first" :only="['endorsementWeb']"
                    cursor-key="endorsement_web_cursor" label="Public connection pages" />
            </template>
            <button type="button" :disabled="busy" @click="expand(null)">Close connections</button>
        </section>
    </div>
    <p class="citation">Endorsements inform your choice; they do not limit who can run. Individuals choose whether to make their endorsements public.</p>
</template>

<style scoped>
.endorsement-list { padding-inline-start: 1.25rem; }
.endorsement-list li { margin-block: 0.75rem; }
.endorsement-list button { margin-inline-start: 0.75rem; }
</style>
