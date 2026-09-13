<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router, useForm, usePage, useRemember } from '@inertiajs/vue3';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import ConsentVoteCard from '@/Components/Legislature/ConsentVoteCard.vue';

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
        onError: errors => { error.value = Object.values(errors)[0] || 'The nominee search could not be loaded. Try again.'; },
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
        selectionError.value = 'Select the person you want to nominate.'; return;
    }
    nomination.post(props.context.nominate_href, { preserveScroll: true,
        onSuccess: () => { nomination.reset(); selected.value = null; selectionError.value = ''; },
    });
}
const statusLabel = status => ({ nominated: 'Awaiting legislative consent', consented: 'Consent granted', seated: 'In office', rejected: 'Consent declined', ended: 'Term ended' })[status] || 'Appointment record';
</script>

<template>
    <section id="governor-appointments" class="governor-workspace" aria-labelledby="governor-heading">
        <h2 id="governor-heading">Governor appointments</h2>
        <p>Governors take office through executive nomination and legislative consent.</p>
        <p class="governor-links">
            <Link v-if="context.executive_href" :href="context.executive_href">Overseeing executive: {{ context.executive_name }}</Link>
            <Link v-if="context.legislature_href" :href="context.legislature_href">Creating legislature: {{ context.legislature_name }}</Link>
        </p>
        <p v-if="context.preview" role="status"><strong>Role preview.</strong> Explore this board’s appointments and legislative consent records.</p>
        <p v-else>Acting as {{ context.actor_name }}, a member of the overseeing executive.</p>
        <p v-if="context.reason" role="status">{{ context.reason }}</p>

        <details class="governor-composer" :open="context.canNominate">
            <summary>Nominate a governor</summary>
            <p>The nominee must have an active association with this jurisdiction. The engine assigns an available governor seat.</p>
            <fieldset :disabled="!context.canNominate">
                <legend>Find a nominee</legend>
                <form class="governor-search" @submit.prevent="search()">
                    <label for="governor-search-by">Find by</label>
                    <select id="governor-search-by" v-model="by"><option value="name">Public name starts with</option><option value="reference">Profile reference</option></select>
                    <label for="governor-search-query">{{ by === 'reference' ? 'Complete profile reference' : 'Public name or @handle' }}</label>
                    <input id="governor-search-query" v-model="query" maxlength="120" autocomplete="off" />
                    <button type="submit" :disabled="searching">Search</button>
                </form>
                <div v-if="context.canNominate" :aria-busy="searching">
                    <p v-if="searching" role="status">Searching for nominees…</p>
                    <p v-if="error" role="alert">{{ error }}</p>
                    <p v-if="!directory.searched">Search by public name, @handle or profile reference to choose a person.</p>
                    <p v-else-if="!directory.candidates.length" role="status">No matching nominees on this page. Try another search.</p>
                    <ul class="governor-results">
                        <li v-for="person in directory.candidates" :key="person.id">
                            <SelectionIdentity :person="person" />
                            <button type="button" :disabled="person.id === nomination.nominee_user_id" :aria-label="`Select ${person.name}, reference ${person.id}`" @click="choose(person)">Select</button>
                        </li>
                    </ul>
                    <nav aria-label="Nominee search pages" class="governor-links">
                        <button v-if="directory.previous" type="button" :disabled="searching" @click="search(directory.previous)">Previous</button>
                        <button v-if="directory.next" type="button" :disabled="searching" @click="search(directory.next)">Next</button>
                        <button v-if="directory.previous || error" type="button" :disabled="searching" @click="search()">Search from first page</button>
                    </nav>
                </div>
                <form class="governor-form" @submit.prevent="submit">
                    <div v-if="selected && selected.id === nomination.nominee_user_id" class="governor-selected">
                        <strong>Selected nominee</strong><SelectionIdentity :person="selected" />
                        <button type="button" @click="clear">Clear selection</button>
                    </div>
                    <p v-if="selectionError || nomination.errors.nominee_user_id" role="alert">{{ selectionError || nomination.errors.nominee_user_id }}</p>
                    <label for="governor-dossier">Nomination dossier (optional)</label>
                    <p id="governor-dossier-hint">This statement will be published with the nomination.</p>
                    <textarea id="governor-dossier" v-model="nomination.dossier" rows="5" maxlength="20000" aria-describedby="governor-dossier-hint" />
                    <p v-if="nomination.errors.dossier || nomination.errors.constitution" role="alert">{{ nomination.errors.dossier || nomination.errors.constitution }}</p>
                    <button type="submit" :disabled="nomination.processing">{{ nomination.processing ? 'Submitting nomination…' : 'Submit nomination' }}</button>
                </form>
            </fieldset>
        </details>

        <p v-if="!appointments.length">No governor appointments are recorded on this page.</p>
        <article v-for="appointment in appointments" :id="`governor-${appointment.id}`" :key="appointment.id" class="governor-record">
            <h3>Governor seat {{ appointment.seat_no }} · {{ statusLabel(appointment.status) }}</h3>
            <SelectionIdentity :person="appointment.nominee" />
            <p v-if="appointment.term">Term: {{ appointment.term.starts_on }} – {{ appointment.term.ends_on }}</p>
            <details v-if="appointment.dossier"><summary>Published nomination dossier</summary><p class="governor-dossier">{{ appointment.dossier.body || 'No accompanying statement was supplied.' }}</p></details>
            <ConsentVoteCard v-if="appointment.consent" :consent="appointment.consent" :can-cast="appointment.consent.can_cast" />
            <p v-if="appointment.consent_notice" role="status">{{ appointment.consent_notice }}</p>
        </article>
        <HistoryPager :pages="pages" :only="['governorAppointments', 'governorPages', 'appointmentContext', 'seated', 'composition']" :first="pages.first || path" cursor-key="governor_cursor" label="Governor appointment pages" />
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
