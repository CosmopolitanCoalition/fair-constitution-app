<script setup>
/**
 * Economy/ResidentAgreements — the person-to-person / N-party consent plane
 * (Design Round 2 ③, F-IND-020; the #9 reversal).
 *
 * An agreement between residents, no organization. Art. I: it takes effect
 * ONLY when every party signs — a one-sided contract never takes effect.
 * Parties are shown BY NAME (a signature is a name); this is the consent
 * plane, not the pseudonymous money plane. A clause can never waive a right
 * (Art. I floor) — a redline that tries is refused with a citation.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Link, useForm, useRemember, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    agreements: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    party_directory: { type: Object, default: () => ({ query: '', searched: false, previous: null, next: null }) },
    my_id: { type: String, default: null },
    compose: { type: Boolean, default: false },
});

const draft = useForm(`resident-agreement-draft:${props.my_id ?? 'guest'}`, { title: '', terms: '', signers: [] });
const selectedNames = useRemember(reactive({}), `resident-agreement-parties:${props.my_id ?? 'guest'}`);
const selectedContexts = useRemember(reactive({}), `resident-agreement-party-context:${props.my_id ?? 'guest'}`);
const searchInput = ref(props.party_directory.query ?? '');
const searching = ref(false);
const searchError = ref('');
const selectedParties = computed(() => draft.signers.map(id => ({ ...selectedContexts[id], id, name: selectedNames[id] ?? t('c_economy.resident_agreements.previously_selected', 'Previously selected party') })));

watch(() => props.party_directory.query, query => { searchInput.value = query ?? ''; });
watch(() => props.candidates, candidates => {
    for (const person of candidates) {
        if (draft.signers.includes(person.id)) { selectedNames[person.id] = person.name; selectedContexts[person.id] = { ...person }; }
    }
}, { immediate: true });

function chooseParty(person, selected) {
    if (selected) {
        if (!draft.signers.includes(person.id)) draft.signers.push(person.id);
        selectedNames[person.id] = person.name;
        selectedContexts[person.id] = { ...person };
    } else {
        removeParty(person.id);
    }
}
function removeParty(id) {
    draft.signers = draft.signers.filter(signer => signer !== id);
    delete selectedNames[id];
    delete selectedContexts[id];
}
const searchOptions = () => ({
    preserveState: true,
    preserveScroll: true,
    only: ['candidates', 'party_directory'],
    onStart: () => { searching.value = true; searchError.value = ''; },
    onFinish: () => { searching.value = false; },
    onError: errors => { searchError.value = errors.party_q ?? errors.party_cursor ?? t('c_economy.resident_agreements.search_failed', 'The search could not be completed. Try again.'); },
});
function searchParties() {
    router.get('/economy/resident-agreements', { new: 1, party_q: searchInput.value.trim() }, searchOptions());
}
const submit = () => draft.post('/economy/resident-agreements', {
    preserveScroll: true,
    onSuccess: () => {
        draft.reset();
        for (const id of Object.keys(selectedNames)) delete selectedNames[id];
        for (const id of Object.keys(selectedContexts)) delete selectedContexts[id];
        router.visit('/economy/agreements');
    },
});

const sign = (id) => router.post(`/economy/resident-agreements/${id}/sign`, {}, { preserveScroll: true });
const resolve = (redlineId, how) => router.post(`/economy/redlines/${redlineId}/${how}`, {}, { preserveScroll: true });

// One lightweight redline composer, keyed by agreement id.
const redline = useForm({ subject_type: 'resident', subject_id: '', clause_id: '', kind: 'edit', body: '', rationale: '' });
const proposeOn = (agreementId, clauseId) => {
    redline.subject_id = agreementId;
    redline.clause_id = clauseId ?? '';
    redline.post('/economy/redlines', { preserveScroll: true, onSuccess: () => { redline.body = ''; redline.rationale = ''; } });
};
</script>

<template>
    <PageScaffold :title="compose ? t('c_economy.resident_agreements.offer_title', 'Offer an agreement') : (agreements[0]?.title ?? t('c_economy.resident_agreements.agreement_fallback', 'Agreement'))">
        <template #intro>
            {{ compose ? t('c_economy.resident_agreements.compose_intro', 'Write terms and invite the other people to sign.') : t('c_economy.resident_agreements.review_intro', 'Review the terms, signatures and proposed changes with the other parties.') }}
        </template>
        <WorkTradeNav active="agreements" back-href="/economy/agreements" :back-label="t('c_economy.resident_agreements.back_label', 'My agreements')" />
        <p class="econ-note">{{ t('c_economy.resident_agreements.privacy_note', 'Terms are private to the parties. Every party must sign, and no term may waive a constitutional right.') }}</p>

        <!-- ------------------------------------------------- new agreement -->
        <Card v-if="compose" as="section" :title="t('c_economy.resident_agreements.terms_parties', 'Terms & parties')">
            <form class="ra-form" @submit.prevent="submit">
                <label>{{ t('c_economy.resident_agreements.title_label', 'Title') }}<input v-model="draft.title" type="text" maxlength="200" required /></label>
                <label>{{ t('c_economy.resident_agreements.terms_label', 'Terms') }}<textarea v-model="draft.terms" rows="3" maxlength="10000" required /></label>
                <fieldset>
                    <legend>{{ t('c_economy.resident_agreements.other_parties_legend', 'Other parties (all must sign)') }}</legend>
                    <div v-if="selectedParties.length" class="ra-selected">
                        <h3>{{ t('c_economy.resident_agreements.selected_parties', { count: selectedParties.length }) }}</h3>
                        <ul>
                            <li v-for="person in selectedParties" :key="person.id">
                                <SelectionIdentity :person="person" />
                                <button type="button" :aria-label="t('c_economy.resident_agreements.remove_aria', { name: person.name, id: person.id })" @click="removeParty(person.id)">{{ t('c_economy.resident_agreements.remove', 'Remove') }}</button>
                            </li>
                        </ul>
                    </div>
                    <label for="agreement-party-search">{{ t('c_economy.resident_agreements.find_party_label', 'Find another party by name') }}</label>
                    <div class="ra-search-row">
                        <input id="agreement-party-search" v-model="searchInput" type="search" maxlength="120"
                            autocomplete="off" aria-describedby="agreement-party-search-hint" @keydown.enter.prevent="searchParties" />
                        <button type="button" :disabled="searching" @click="searchParties">{{ searching ? t('c_economy.resident_agreements.searching', 'Searching…') : t('c_economy.resident_agreements.search', 'Search') }}</button>
                    </div>
                    <p id="agreement-party-search-hint" class="econ-note">{{ t('c_economy.resident_agreements.search_hint', 'Enter the beginning of a person\'s name. Selected parties stay selected when you search again.') }}</p>
                    <p v-if="searchError" class="ra-err" role="alert">{{ searchError }}</p>
                    <div :aria-busy="searching" class="ra-search-results">
                        <p v-if="!party_directory.searched" class="econ-note" role="status">{{ t('c_economy.resident_agreements.search_to_find', 'Search to find people to invite.') }}</p>
                        <p v-else-if="!candidates.length" class="econ-note" role="status">{{ t('c_economy.resident_agreements.no_matching', { query: party_directory.query }) }}</p>
                        <p v-else class="econ-note" role="status">{{ t('c_economy.resident_agreements.results_count', { count: candidates.length, query: party_directory.query }) }}</p>
                        <div v-for="person in candidates" :key="person.id" class="ra-check">
                            <input type="checkbox" :checked="draft.signers.includes(person.id)" :value="person.id"
                                :aria-label="t('c_economy.resident_agreements.invite_aria', { name: person.name, id: person.id })" @change="chooseParty(person, $event.target.checked)" />
                            <SelectionIdentity :person="person" />
                        </div>
                        <nav v-if="party_directory.previous || party_directory.next" class="ra-search-pages" :aria-label="t('c_economy.resident_agreements.people_pages', 'People search pages')">
                            <Link v-if="party_directory.previous" :href="party_directory.previous" v-bind="searchOptions()">{{ t('c_economy.resident_agreements.previous_people', 'Previous people') }}</Link>
                            <Link v-if="party_directory.next" :href="party_directory.next" v-bind="searchOptions()">{{ t('c_economy.resident_agreements.more_people', 'More people') }}</Link>
                        </nav>
                    </div>
                    <p v-if="draft.errors.signers" class="ra-err">{{ draft.errors.signers }}</p>
                </fieldset>
                <p v-if="draft.errors.constitution" class="ra-err">{{ draft.errors.constitution }}</p>
                <button type="submit" :disabled="draft.processing || !draft.signers.length">{{ t('c_economy.resident_agreements.offer_it', 'Offer it') }}</button>
            </form>
        </Card>

        <!-- ---------------------------------------------------- my agreements -->
        <Card v-for="a in agreements" :key="a.id" as="section" :title="t('c_economy.resident_agreements.agreement_record', 'Agreement record')">
            <p class="ra-status">
                <StatusBadge>{{ a.status }}</StatusBadge>
                <span v-if="a.is_initiator" class="econ-note">{{ t('c_economy.resident_agreements.you_offered', '· you offered this') }}</span>
            </p>

            <p class="ra-terms">{{ a.terms }}</p>

            <div class="ra-signers">
                <span v-for="(s, i) in a.signers" :key="i" class="ra-signer" :class="{ signed: s.signed }">
                    {{ s.signed ? '✓' : '○' }} {{ s.name }}<template v-if="s.is_me">{{ t('c_economy.resident_agreements.you_suffix', ' (you)') }}</template>
                </span>
            </div>

            <button v-if="a.can_sign" class="ra-sign" @click="sign(a.id)">{{ t('c_economy.resident_agreements.sign_agreement', 'Sign this agreement') }}</button>

            <!-- pending redlines -->
            <div v-if="a.redlines.length" class="ra-redlines">
                <h3>{{ t('c_economy.resident_agreements.proposed_changes', 'Proposed changes') }}</h3>
                <div v-for="r in a.redlines" :key="r.id" class="ra-redline">
                    <p><strong>{{ r.kind }}</strong>: {{ r.body }}</p>
                    <p v-if="r.rationale" class="econ-note">{{ t('c_economy.resident_agreements.redline_why', { rationale: r.rationale }) }}</p>
                    <div class="ra-redline-acts">
                        <template v-if="r.is_mine">
                            <button @click="resolve(r.id, 'withdraw')">{{ t('c_economy.resident_agreements.withdraw', 'Withdraw') }}</button>
                        </template>
                        <template v-else>
                            <button @click="resolve(r.id, 'accept')">{{ t('c_economy.resident_agreements.accept_voids', 'Accept (voids signatures)') }}</button>
                            <button @click="resolve(r.id, 'reject')">{{ t('c_economy.resident_agreements.reject', 'Reject') }}</button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- propose a redline on a clause -->
            <details class="ra-propose">
                <summary>{{ t('c_economy.resident_agreements.propose_change', 'Propose a change') }}</summary>
                <div v-for="c in a.clauses" :key="c.id" class="ra-clause">
                    <p class="econ-note">{{ c.heading || t('c_economy.resident_agreements.clause_fallback', 'Clause') }}: {{ c.body }}</p>
                    <div class="ra-propose-row">
                        <select v-model="redline.kind" :aria-label="t('c_economy.resident_agreements.kind_of_change_aria', 'Kind of change')"><option value="edit">{{ t('c_economy.resident_agreements.opt_edit', 'Edit') }}</option><option value="strike">{{ t('c_economy.resident_agreements.opt_strike', 'Strike') }}</option></select>
                        <input v-model="redline.body" type="text" :placeholder="t('c_economy.resident_agreements.your_language_placeholder', 'Your language')" :aria-label="t('c_economy.resident_agreements.proposed_wording_aria', 'Proposed wording')" />
                        <button @click="proposeOn(a.id, c.id)">{{ t('c_economy.resident_agreements.propose', 'Propose') }}</button>
                    </div>
                </div>
                <p v-if="redline.errors.constitution" class="ra-err">{{ redline.errors.constitution }}</p>
            </details>
        </Card>

    </PageScaffold>
</template>

<style scoped>
.ra-form { display: flex; flex-direction: column; gap: var(--space-3, 0.75rem); max-inline-size: 40rem; }
.ra-form label { display: flex; flex-direction: column; gap: var(--space-1, 0.25rem); }
.ra-form fieldset { min-inline-size: 0; }
.ra-search-row { display: flex; flex-wrap: wrap; gap: .5rem; }
.ra-search-row input { flex: 1; min-inline-size: 12rem; }
.ra-search-row button, .ra-selected button, .ra-search-pages a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .4rem .65rem; }
.ra-selected { margin-block-end: 1rem; padding: .75rem; border: 1px solid var(--gov-border); border-radius: .4rem; }
.ra-selected h3 { font-size: 1rem; margin: 0 0 .5rem; }
.ra-selected ul { list-style: none; margin: 0; padding: 0; }
.ra-selected li, .ra-search-pages { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; }
.ra-search-results { margin-block-start: .75rem; }
.ra-search-pages { margin-block-start: .75rem; }
.ra-form button:focus-visible, .ra-search-pages a:focus-visible, .ra-form input:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.ra-check { flex-direction: row !important; align-items: center; gap: var(--space-2, 0.5rem); }
.ra-status { display: flex; gap: var(--space-2, 0.5rem); align-items: center; }
.ra-terms { color: var(--gov-fg, #223); }
.ra-signers { display: flex; flex-wrap: wrap; gap: var(--space-3, 1rem); margin-block: var(--space-3, 0.75rem); }
.ra-signer { color: var(--gov-fg-muted, #667); }
.ra-signer.signed { color: var(--gov-success, #262); font-weight: 600; }
.ra-sign { margin-block: var(--space-2, 0.5rem); }
.ra-redlines { margin-block-start: var(--space-3, 1rem); border-block-start: 1px solid var(--gov-border, #dde); padding-block-start: var(--space-3, 0.75rem); }
.ra-redline { margin-block-end: var(--space-3, 0.75rem); }
.ra-redline-acts { display: flex; gap: var(--space-2, 0.5rem); }
.ra-propose { margin-block-start: var(--space-3, 1rem); }
.ra-propose-row { display: flex; gap: var(--space-2, 0.5rem); margin-block-end: var(--space-2, 0.5rem); }
.ra-propose-row input { flex: 1 1 auto; }
.econ-note { font-size: var(--text-sm, 0.875rem); color: var(--gov-fg-muted, #778); }
.econ-absent { color: var(--gov-fg-muted, #667); font-style: italic; padding: var(--space-3, 1rem); background: var(--gov-surface-subtle, #eef); border-radius: 0.5rem; }
.ra-err { color: var(--gov-danger, #b00); font-size: var(--text-sm, 0.875rem); }
</style>
