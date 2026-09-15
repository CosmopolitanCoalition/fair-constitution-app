<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Card from '@/Components/Ui/Card.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';
import ConsentVoteCard from '@/Components/Legislature/ConsentVoteCard.vue';

const { t } = useI18n();
const proposalStatus = (status) =>
    ({
        open: t('c_institution_components.judicial_nominations.proposal_open', 'Vote open'),
        adopted: t('c_institution_components.judicial_nominations.proposal_adopted', 'Authorized'),
        rejected: t('c_institution_components.judicial_nominations.proposal_rejected', 'Not authorized'),
    })[status] || status;

const props = defineProps({
    judiciary: { type: Object, required: true }, context: { type: Object, default: () => ({}) },
    seats: { type: Object, default: () => ({ rows: [], pages: {} }) }, committees: { type: Object, default: () => ({ rows: [], pages: {} }) },
    proposals: { type: Object, default: () => ({ rows: [], pages: {} }) }, nominees: { type: Object, default: () => ({ candidates: [] }) },
});
const page = usePage();
const seat = ref(null), nominee = ref(null), committee = ref(null), statement = ref(''), designationStatement = ref('');
const query = ref(props.nominees.query || ''), by = ref(props.nominees.by || 'name');
const busy = ref(false), searching = ref(false), error = ref(''), searchError = ref(''), notice = ref('');
const path = computed(() => `/judiciaries/${props.judiciary.id}`);
watch(() => props.judiciary.id, () => { seat.value = null; nominee.value = null; committee.value = null; statement.value = ''; designationStatement.value = ''; error.value = ''; notice.value = ''; });
function search() {
    if (searching.value) return;
    const url = new URL(page.url || path.value, 'http://fixture.invalid');
    url.searchParams.set('nominee_q', query.value.trim()); url.searchParams.set('nominee_by', by.value); url.searchParams.delete('nominee_cursor');
    router.get(url.pathname + url.search, {}, { only: ['judicialNominees'], preserveState: true, preserveScroll: true,
        onStart: () => { searching.value = true; searchError.value = ''; }, onFinish: () => { searching.value = false; },
        onError: errors => { searchError.value = Object.values(errors)[0] || t('c_institution_components.judicial_nominations.search_failed', 'Search could not be loaded. Please retry.'); } });
}
function submit(designation = false) {
    if (busy.value) return;
    if (designation ? !props.context.can_designate || !committee.value || !designationStatement.value.trim()
        : !seat.value?.can_propose || !nominee.value || !statement.value.trim()) return;
    const data = designation ? { committee_id: committee.value.id, statement: designationStatement.value.trim() }
        : { seat_id: seat.value.id, legislature_id: seat.value.legislature_id, nominee_user_id: nominee.value.id, statement: statement.value.trim() };
    router.post(designation ? props.context.designate_url : props.context.nominate_url, data, { preserveScroll: true,
        onStart: () => { busy.value = true; error.value = ''; notice.value = ''; }, onFinish: () => { busy.value = false; },
        onError: errors => { error.value = Object.values(errors)[0] || t('c_institution_components.judicial_nominations.proposal_failed', 'The proposal could not be filed. Please retry.'); },
        onSuccess: () => { notice.value = t('c_institution_components.judicial_nominations.proposal_filed', 'Proposal filed. Follow its vote below.');
            if (designation) { committee.value = null; designationStatement.value = ''; }
            else { seat.value = null; nominee.value = null; statement.value = ''; } },
    });
}
</script>

<template>
    <Card as="section" id="judicial-nominations" :title="t('c_institution_components.judicial_nominations.propose_title', 'Propose a judge')">
        <p v-if="context.mode === 'constituent'">{{ t('c_institution_components.judicial_nominations.constituent_intro', 'A serving member of the seat’s constituent legislature may propose a person. That legislature must authorize the nomination by an ordinary majority of all serving members.') }}</p>
        <p v-else>{{ t('c_institution_components.judicial_nominations.committee_intro', 'Any serving member of the designated judicial committee, including its chair, may propose a person. The committee must authorize the nomination by supermajority.') }}</p>
        <p>{{ t('c_institution_components.judicial_nominations.authorization_note', 'Authorization opens a separate confirmation vote in the legislature that created this court. Review each person’s public profile before proposing or voting.') }}</p>
        <p v-if="context.reason" role="status">{{ context.reason }}</p>
        <p v-if="context.committee">{{ t('c_institution_components.judicial_nominations.designated_committee', 'Designated committee:') }} <Link :href="context.committee.href">{{ context.committee.name }}</Link></p>

        <details v-if="context.mode === 'committee'" class="nomination-section">
            <summary>{{ t('c_institution_components.judicial_nominations.designate_summary', 'Designate or change the judicial committee') }}</summary>
            <p>{{ t('c_institution_components.judicial_nominations.designate_note', 'The creating legislature assigns this responsibility through a recorded supermajority act.') }}</p>
            <Link v-if="context.create_committee_url" :href="context.create_committee_url">{{ t('c_institution_components.judicial_nominations.manage_committees', 'Create or manage this legislature’s committees →') }}</Link>
            <p v-if="!context.can_designate">{{ t('c_institution_components.judicial_nominations.designate_preview', 'Public preview: a serving member of the creating legislature files this proposal.') }}</p>
            <p v-if="!committees.rows.length">{{ t('c_institution_components.judicial_nominations.no_committees', 'No eligible committees are available on this page. Create a committee, then return here to designate it.') }}</p>
            <div v-for="item in committees.rows" :key="item.id" class="choice">
                <Link :href="item.href">{{ item.name }}</Link>
                <button type="button" :aria-pressed="committee?.id === item.id" @click="committee = item">{{ committee?.id === item.id ? t('c_institution_components.judicial_nominations.selected_committee', 'Selected committee') : t('c_institution_components.judicial_nominations.select_committee', 'Select committee') }}</button>
            </div>
            <HistoryPager :pages="committees.pages" :first="path" :only="['judicialCommittees']" cursor-key="committees_cursor" :label="t('c_institution_components.judicial_nominations.committee_pager', 'Committee selection pages')" />
            <form @submit.prevent="submit(true)">
                <p v-if="committee">{{ t('c_institution_components.judicial_nominations.selected_name', 'Selected: {name}', { name: committee.name }) }}</p>
                <label for="judicial-designation-statement">{{ t('c_institution_components.judicial_nominations.designation_statement_label', 'Public designation statement') }}</label>
                <textarea id="judicial-designation-statement" v-model="designationStatement" rows="3" maxlength="10000" required />
                <button type="submit" :disabled="busy || !context.can_designate || !committee || !designationStatement.trim()">{{ t('c_institution_components.judicial_nominations.propose_designation_btn', 'Propose committee designation') }}</button>
            </form>
        </details>

        <div class="nomination-section">
            <h3>{{ t('c_institution_components.judicial_nominations.vacant_seats', 'Vacant seats') }}</h3>
            <p v-if="!seats.rows.length">{{ t('c_institution_components.judicial_nominations.no_vacant_seats', 'No vacant seats are available on this page.') }}</p>
            <div v-for="item in seats.rows" :key="item.id" class="choice">
                <span>{{ t('c_institution_components.judicial_nominations.seat_num', 'Seat {n}', { n: item.number }) }} · {{ item.nominator }}</span>
                <button type="button" :aria-pressed="seat?.id === item.id" @click="seat = item">{{ seat?.id === item.id ? t('c_institution_components.judicial_nominations.selected_seat', 'Selected seat') : t('c_institution_components.judicial_nominations.select_seat', 'Select seat') }}</button>
            </div>
            <HistoryPager :pages="seats.pages" :first="path" :only="['vacantSeats']" cursor-key="seats_cursor" :label="t('c_institution_components.judicial_nominations.seats_pager', 'Vacant court seat pages')" />
            <p v-if="seat">{{ t('c_institution_components.judicial_nominations.selected_seat_num', 'Selected: seat {n}', { n: seat.number }) }} · {{ seat.nominator }}</p>
            <p v-if="seat && !seat.can_propose" role="status">{{ seat.reason || t('c_institution_components.judicial_nominations.seat_preview', 'Public preview: a serving member of this seat’s nominating body files the proposal.') }}</p>
        </div>

        <form class="nomination-section" :aria-busy="searching" @submit.prevent="search">
            <h3>{{ t('c_institution_components.judicial_nominations.find_person', 'Find a person to propose') }}</h3>
            <label for="judicial-nominee-by">{{ t('c_institution_components.judicial_nominations.find_by', 'Find by') }}</label>
            <select id="judicial-nominee-by" v-model="by"><option value="name">{{ t('c_institution_components.judicial_nominations.find_by_name', 'Public name or {\'@\'}handle') }}</option><option value="reference">{{ t('c_institution_components.judicial_nominations.find_by_reference', 'Profile reference') }}</option></select>
            <label for="judicial-nominee-query">{{ by === 'reference' ? t('c_institution_components.judicial_nominations.query_reference', 'Complete profile reference') : t('c_institution_components.judicial_nominations.query_name', 'Start of public name or {\'@\'}handle') }}</label>
            <input id="judicial-nominee-query" v-model="query" maxlength="120" />
            <button type="submit" :disabled="searching">{{ t('c_institution_components.judicial_nominations.search_btn', 'Search eligible people') }}</button>
            <p v-if="searching" role="status">{{ t('c_institution_components.judicial_nominations.searching', 'Searching people…') }}</p><p v-if="searchError" role="alert">{{ searchError }}</p>
        </form>
        <p v-if="nominees.searched && !nominees.candidates.length" role="status">{{ t('c_institution_components.judicial_nominations.no_matches', 'No matching people with an active association in this court’s jurisdiction.') }}</p>
        <div v-for="person in nominees.candidates" :key="person.id" class="choice">
            <SelectionIdentity :person="person" />
            <button type="button" :aria-pressed="nominee?.id === person.id" @click="nominee = person">{{ nominee?.id === person.id ? t('c_institution_components.judicial_nominations.selected_person', 'Selected person') : t('c_institution_components.judicial_nominations.select_person', 'Select person') }}</button>
        </div>
        <HistoryPager :pages="nominees" :first="path" :only="['judicialNominees']" cursor-key="nominee_cursor" :label="t('c_institution_components.judicial_nominations.nominee_pager', 'Judicial nominee search pages')" />
        <form class="nomination-section" :aria-busy="busy" @submit.prevent="submit(false)">
            <h3>{{ t('c_institution_components.judicial_nominations.nomination_proposal', 'Nomination proposal') }}</h3>
            <div v-if="nominee"><p>{{ t('c_institution_components.judicial_nominations.selected_person_label', 'Selected person') }}</p><SelectionIdentity :person="nominee" /></div>
            <label for="judicial-nomination-statement">{{ t('c_institution_components.judicial_nominations.nomination_statement_label', 'Public nomination statement') }}</label>
            <textarea id="judicial-nomination-statement" v-model="statement" rows="4" maxlength="10000" required />
            <button type="submit" :disabled="busy || !seat?.can_propose || !nominee || !statement.trim()">{{ t('c_institution_components.judicial_nominations.propose_person_btn', 'Propose this person') }}</button>
        </form>
        <p v-if="busy" role="status">{{ t('c_institution_components.judicial_nominations.filing', 'Filing proposal…') }}</p><p v-if="error" role="alert">{{ error }}</p><p v-if="notice" role="status">{{ notice }}</p>
    </Card>

    <Card as="section" id="judicial-proposals" :title="t('c_institution_components.judicial_nominations.decisions_title', 'Nomination and committee decisions')">
        <p v-if="!proposals.rows.length">{{ t('c_institution_components.judicial_nominations.no_proposals', 'No nomination or committee-designation proposals have been filed here.') }}</p>
        <article v-for="proposal in proposals.rows" :key="proposal.id" class="nomination-section">
            <h3>{{ proposal.title }} · {{ proposalStatus(proposal.status) }}</h3>
            <p><span v-if="proposal.seat_number != null">{{ t('c_institution_components.judicial_nominations.seat_num', 'Seat {n}', { n: proposal.seat_number }) }} · </span>{{ proposal.body_name }}</p>
            <SelectionIdentity v-if="proposal.nominee" :person="proposal.nominee" />
            <details><summary>{{ t('c_institution_components.judicial_nominations.read_statement', 'Read the public statement') }}</summary><p class="statement">{{ proposal.statement }}</p></details>
            <p v-if="proposal.reason" role="status">{{ proposal.reason }}</p>
            <ConsentVoteCard v-if="proposal.vote" :consent="proposal.vote" :can-cast="proposal.vote.can_cast" />
            <Link v-if="proposal.result_href" :href="proposal.result_href">{{ t('c_institution_components.judicial_nominations.follow_confirmation', 'Follow the court’s confirmation process →') }}</Link>
        </article>
        <HistoryPager :pages="proposals.pages" :first="path" :only="['judicialProposals']" cursor-key="judicial_proposals_cursor" :label="t('c_institution_components.judicial_nominations.proposals_pager', 'Judicial proposal pages')" />
    </Card>
</template>

<style scoped>
.nomination-section { border-block-start: 1px solid var(--border, #344054); margin-block-start: 1rem; padding-block: 1rem; }
form { display: grid; gap: .65rem; }
.choice { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding-block: .65rem; }
button, input, select, summary { min-block-size: 44px; font: inherit; }
textarea, input, select { inline-size: 100%; max-inline-size: 42rem; font: inherit; }
button { inline-size: fit-content; }
button[aria-pressed="true"] { outline: 2px solid var(--gov-accent, #60a5fa); }
summary { cursor: pointer; }
.statement { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
