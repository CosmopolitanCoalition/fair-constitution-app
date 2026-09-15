<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router, useForm, usePage, useRemember } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import ConsentVoteCard from '@/Components/Legislature/ConsentVoteCard.vue';

const { t } = useI18n();

const props = defineProps({
    organization: { type: Object, required: true }, context: { type: Object, required: true },
    directory: { type: Object, default: () => ({ query: '', by: 'name', candidates: [], searched: false }) },
    appointments: { type: Array, default: () => [] }, pages: { type: Object, default: () => ({}) },
});
const page = usePage();
const path = computed(() => `/organizations/${props.organization.id}/board-elections`);
const draftKey = `governor:${page.props.auth?.user?.id ?? 'guest'}:${props.organization.id}`;
const nomination = useForm(draftKey, { nominee_user_id: '', dossier: '' });
const selected = useRemember(ref(null), `${draftKey}:person`);
const query = ref(props.directory.query);
const by = ref(props.directory.by);
const searching = ref(false);
const error = ref('');
const selectionError = ref('');
watch(() => props.directory, directory => {
    query.value = directory.query; by.value = directory.by;
    const refreshed = directory.candidates.find(person => person.id === nomination.nominee_user_id);
    if (refreshed) selected.value = { ...refreshed };
});
function search(url = null) {
    if (!props.context.canNominate || searching.value) return;
    const current = new URL(page.url || path.value, 'http://fixture.invalid');
    const target = url ? new URL(url, current) : null;
    const values = { nominee_q: query.value.trim(), nominee_by: by.value };
    for (const key of ['nominee_q', 'nominee_by', 'nominee_cursor']) {
        current.searchParams.delete(key);
        const value = target ? target.searchParams.get(key) : values[key];
        if (value !== null && value !== undefined) current.searchParams.set(key, value);
    }
    router.get(path.value + current.search + current.hash, {}, {
        only: ['nomineeDirectory'], preserveState: true, preserveScroll: true,
        onStart: () => { searching.value = true; error.value = ''; },
        onError: errors => { error.value = Object.values(errors)[0] || t('c_institution_components.cgc_governors.search_failed', 'The nominee search could not be loaded. Try again.'); },
        onFinish: () => { searching.value = false; },
    });
}
function choose(person) {
    nomination.nominee_user_id = person.id; selected.value = { ...person }; selectionError.value = '';
    nomination.clearErrors('nominee_user_id');
}
function clear() { nomination.nominee_user_id = ''; selected.value = null; }
function submit() {
    if (!props.context.canNominate || nomination.processing) return;
    if (!nomination.nominee_user_id || selected.value?.id !== nomination.nominee_user_id) {
        selectionError.value = t('c_institution_components.cgc_governors.select_prompt', 'Select the person you want to nominate.'); return;
    }
    nomination.post(props.context.nominate_href, { preserveScroll: true,
        onSuccess: () => { nomination.reset(); selected.value = null; selectionError.value = ''; },
    });
}
const statusLabel = status => ({ nominated: t('c_institution_components.cgc_governors.status_nominated', 'Awaiting legislative consent'), consented: t('c_institution_components.cgc_governors.status_consented', 'Consent granted'), seated: t('c_institution_components.cgc_governors.status_seated', 'In office'), rejected: t('c_institution_components.cgc_governors.status_rejected', 'Consent declined'), ended: t('c_institution_components.cgc_governors.status_ended', 'Term ended') })[status] || t('c_institution_components.cgc_governors.status_default', 'Appointment record');
</script>

<template>
    <section id="governor-appointments" class="governor-workspace" aria-labelledby="governor-heading">
        <h2 id="governor-heading">{{ t('c_institution_components.cgc_governors.heading', 'Governor appointments') }}</h2>
        <p>{{ t('c_institution_components.cgc_governors.intro', 'Governors take office through executive nomination and legislative consent.') }}</p>
        <p class="governor-links">
            <Link v-if="context.executive_href" :href="context.executive_href">{{ t('c_institution_components.cgc_governors.overseeing_executive', 'Overseeing executive: {name}', { name: context.executive_name }) }}</Link>
            <Link v-if="context.legislature_href" :href="context.legislature_href">{{ t('c_institution_components.cgc_governors.creating_legislature', 'Creating legislature: {name}', { name: context.legislature_name }) }}</Link>
        </p>
        <p v-if="context.preview" role="status"><strong>{{ t('c_institution_components.cgc_governors.role_preview_strong', 'Role preview.') }}</strong> {{ t('c_institution_components.cgc_governors.role_preview_body', 'Explore this board’s appointments and legislative consent records.') }}</p>
        <p v-else>{{ t('c_institution_components.cgc_governors.acting_as', 'Acting as {name}, a member of the overseeing executive.', { name: context.actor_name }) }}</p>
        <p v-if="context.reason" role="status">{{ context.reason }}</p>

        <details class="governor-composer" :open="context.canNominate">
            <summary>{{ t('c_institution_components.cgc_governors.nominate_summary', 'Nominate a governor') }}</summary>
            <p>{{ t('c_institution_components.cgc_governors.nominee_requirement', 'The nominee must have an active association with this jurisdiction. The engine assigns an available governor seat.') }}</p>
            <fieldset :disabled="!context.canNominate">
                <legend>{{ t('c_institution_components.cgc_governors.find_nominee', 'Find a nominee') }}</legend>
                <form class="governor-search" @submit.prevent="search()">
                    <label for="governor-search-by">{{ t('c_institution_components.cgc_governors.find_by', 'Find by') }}</label>
                    <select id="governor-search-by" v-model="by"><option value="name">{{ t('c_institution_components.cgc_governors.find_by_name', 'Public name starts with') }}</option><option value="reference">{{ t('c_institution_components.cgc_governors.find_by_reference', 'Profile reference') }}</option></select>
                    <label for="governor-search-query">{{ by === 'reference' ? t('c_institution_components.cgc_governors.query_reference', 'Complete profile reference') : t('c_institution_components.cgc_governors.query_name', 'Public name or {\'@\'}handle') }}</label>
                    <input id="governor-search-query" v-model="query" maxlength="120" autocomplete="off" />
                    <button type="submit" :disabled="searching">{{ t('c_institution_components.cgc_governors.search', 'Search') }}</button>
                </form>
                <div v-if="context.canNominate" :aria-busy="searching">
                    <p v-if="searching" role="status">{{ t('c_institution_components.cgc_governors.searching', 'Searching for nominees…') }}</p>
                    <p v-if="error" role="alert">{{ error }}</p>
                    <p v-if="!directory.searched">{{ t('c_institution_components.cgc_governors.search_prompt', 'Search by public name, {\'@\'}handle or profile reference to choose a person.') }}</p>
                    <p v-else-if="!directory.candidates.length" role="status">{{ t('c_institution_components.cgc_governors.no_nominees', 'No matching nominees on this page. Try another search.') }}</p>
                    <ul class="governor-results">
                        <li v-for="person in directory.candidates" :key="person.id">
                            <SelectionIdentity :person="person" />
                            <button type="button" :disabled="person.id === nomination.nominee_user_id" :aria-label="t('c_institution_components.cgc_governors.select_aria', 'Select {name}, reference {reference}', { name: person.name, reference: person.id })" @click="choose(person)">{{ t('c_institution_components.cgc_governors.select', 'Select') }}</button>
                        </li>
                    </ul>
                    <nav :aria-label="t('c_institution_components.cgc_governors.nominee_pager_aria', 'Nominee search pages')" class="governor-links">
                        <button v-if="directory.previous" type="button" :disabled="searching" @click="search(directory.previous)">{{ t('c_institution_components.cgc_governors.previous', 'Previous') }}</button>
                        <button v-if="directory.next" type="button" :disabled="searching" @click="search(directory.next)">{{ t('c_institution_components.cgc_governors.next', 'Next') }}</button>
                        <button v-if="directory.previous || error" type="button" :disabled="searching" @click="search()">{{ t('c_institution_components.cgc_governors.search_first_page', 'Search from first page') }}</button>
                    </nav>
                </div>
                <form class="governor-form" @submit.prevent="submit">
                    <div v-if="selected && selected.id === nomination.nominee_user_id" class="governor-selected">
                        <strong>{{ t('c_institution_components.cgc_governors.selected_nominee', 'Selected nominee') }}</strong><SelectionIdentity :person="selected" />
                        <button type="button" @click="clear">{{ t('c_institution_components.cgc_governors.clear_selection', 'Clear selection') }}</button>
                    </div>
                    <p v-if="selectionError || nomination.errors.nominee_user_id" role="alert">{{ selectionError || nomination.errors.nominee_user_id }}</p>
                    <label for="governor-dossier">{{ t('c_institution_components.cgc_governors.dossier_label', 'Nomination dossier (optional)') }}</label>
                    <p id="governor-dossier-hint">{{ t('c_institution_components.cgc_governors.dossier_hint', 'This statement will be published with the nomination.') }}</p>
                    <textarea id="governor-dossier" v-model="nomination.dossier" rows="5" maxlength="20000" aria-describedby="governor-dossier-hint" />
                    <p v-if="nomination.errors.dossier || nomination.errors.constitution" role="alert">{{ nomination.errors.dossier || nomination.errors.constitution }}</p>
                    <button type="submit" :disabled="nomination.processing">{{ nomination.processing ? t('c_institution_components.cgc_governors.submitting', 'Submitting nomination…') : t('c_institution_components.cgc_governors.submit', 'Submit nomination') }}</button>
                </form>
            </fieldset>
        </details>

        <p v-if="!appointments.length">{{ t('c_institution_components.cgc_governors.no_appointments', 'No governor appointments are recorded on this page.') }}</p>
        <article v-for="appointment in appointments" :id="`governor-${appointment.id}`" :key="appointment.id" class="governor-record">
            <h3>{{ t('c_institution_components.cgc_governors.seat_label', 'Governor seat {n}', { n: appointment.seat_no }) }} · {{ statusLabel(appointment.status) }}</h3>
            <SelectionIdentity :person="appointment.nominee" />
            <p v-if="appointment.term">{{ t('c_institution_components.cgc_governors.term', 'Term: {starts} – {ends}', { starts: appointment.term.starts_on, ends: appointment.term.ends_on }) }}</p>
            <details v-if="appointment.dossier"><summary>{{ t('c_institution_components.cgc_governors.published_dossier', 'Published nomination dossier') }}</summary><p class="governor-dossier">{{ appointment.dossier.body || t('c_institution_components.cgc_governors.no_statement', 'No accompanying statement was supplied.') }}</p></details>
            <ConsentVoteCard v-if="appointment.consent" :consent="appointment.consent" :can-cast="appointment.consent.can_cast" />
            <p v-if="appointment.consent_notice" role="status">{{ appointment.consent_notice }}</p>
        </article>
        <HistoryPager :pages="pages" :only="['governorAppointments', 'governorPages', 'appointmentContext', 'seated', 'composition']" :first="pages.first || path" cursor-key="governor_cursor" :label="t('c_institution_components.cgc_governors.pager_label', 'Governor appointment pages')" />
    </section>
</template>

<style scoped>
.governor-workspace { padding: var(--space-4, 1.5rem); border: 1px solid var(--gov-border); border-radius: var(--radius-md, .5rem); }
.governor-links { display: flex; flex-wrap: wrap; gap: 1rem; margin-block: 1rem; }
.governor-composer, .governor-record { border-block-start: 1px solid var(--gov-border); padding-block: 1rem; margin-block-start: 1rem; }
.governor-search, .governor-form { display: grid; gap: .6rem; margin-block: 1rem; }
.governor-results { list-style: none; padding: 0; }
.governor-results li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; padding-block: .5rem; }
.governor-selected { padding: 1rem; border: 1px solid var(--gov-border); }
.governor-dossier { white-space: pre-wrap; overflow-wrap: anywhere; }
input, select, textarea, button { font: inherit; max-inline-size: 100%; }
button, input, select { min-block-size: 44px; }
fieldset { min-width: 0; border: 0; padding: 0; }
fieldset:disabled { opacity: .7; }
</style>
