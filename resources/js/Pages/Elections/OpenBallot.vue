<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import CandidateRow from '@/Components/Electoral/CandidateRow.vue';
import FinalistLine from '@/Components/Electoral/FinalistLine.vue';
import PhaseBanner from '@/Components/Electoral/PhaseBanner.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useAnnounce } from '@/composables/useAnnounce';
import { useJourneyNudge } from '@/composables/useJourneyNudge';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    surface: { type: Object, required: true },
    race: { type: Object, default: null },
    races: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    standings: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    directoryNotice: { type: String, default: null },
    myApprovals: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    endorsementDetails: { type: Object, default: null },
    approvable: { type: Boolean, default: false },
    inFootprint: { type: Boolean, default: false },
});
const { t } = useI18n();
const page = usePage();
const nudge = useJourneyNudge('election');
const { announce } = useAnnounce();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});
const phase = computed(() => props.race?.phase ?? 'approval');
const approvalOpen = computed(() => phase.value === 'approval');
const finalistX = computed(() => props.race?.finalist_count ?? 0);
const path = computed(() => `/elections/${props.race?.election_id}/open-ballot`);
const standingStatuses = ['validated', 'in_pool', 'finalist'];

// Only this page's switches are delivered; the private total covers the whole race.
// Serialize mutations so an Inertia visit cannot cancel another approval request.
const approved = reactive({});
const saving = ref(false);
const loading = ref(false);
const ownDelta = ref(0);
const notice = ref('');
const busy = computed(() => saving.value || loading.value);
watch(() => props.myApprovals, (ids) => {
    Object.keys(approved).forEach((key) => delete approved[key]);
    (ids ?? []).forEach((id) => { approved[id] = true; });
    ownDelta.value = 0;
}, { immediate: true });
const myActiveApprovals = computed(() => (props.stats.myActiveApprovals ?? 0) + ownDelta.value);

function toggleApprove(candidacyId, next) {
    if (busy.value || !props.approvable) return;
    const before = !!approved[candidacyId];
    if (before === next) return;
    saving.value = true;
    notice.value = '';
    approved[candidacyId] = next;
    ownDelta.value = next ? 1 : -1;
    const revert = () => { approved[candidacyId] = before; ownDelta.value = 0; };
    const options = {
        preserveScroll: true, preserveState: true,
        onSuccess: () => {
            ownDelta.value = 0;
            announce(next ? t('c_elections.open.announce_approved', 'Approved. Revocable until the finalist cutoff.') : t('c_elections.open.announce_withdrawn', 'Approval withdrawn'));
        },
        onError: revert,
        onCancel: () => {
            revert();
            notice.value = t('c_elections.open.interrupted', 'The request was interrupted. Refresh this page to confirm your saved approvals.');
        },
        onFinish: () => { saving.value = false; },
    };
    if (next) router.post(`/elections/${props.race.election_id}/approvals`, { candidacy_id: candidacyId }, options);
    else router.delete(`/elections/${props.race.election_id}/approvals/${candidacyId}`, options);
}

const defaults = { q: '', organization: '', endorser: 'any', incumbents: false, approved: false };
const filters = reactive({ ...defaults });
watch(() => props.filters, (value) => Object.assign(filters, defaults, value), { immediate: true });
const filtersActive = computed(() => props.filters.q || props.filters.organization ||
    (props.filters.endorser && props.filters.endorser !== 'any') || props.filters.incumbents || props.filters.approved);
const detailsFor = ref(null);
function visit(url, data = {}) {
    if (busy.value) return;
    loading.value = true;
    detailsFor.value = null;
    router.get(url, data, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => announce(t('c_elections.open.announce_loaded', '{n} candidates loaded', { named: { n: props.standings.length } })),
        onFinish: () => { loading.value = false; },
    });
}
function applyFilters() {
    visit(path.value, { race: props.race.id, ...filters, incumbents: Number(filters.incumbents), approved: Number(filters.approved) });
}
function clearFilters() { Object.assign(filters, defaults); applyFilters(); }
function switchRace(raceId) { visit(path.value, { race: raceId }); }
function firstPage() { visit(path.value, { race: props.race.id, ...props.filters, incumbents: Number(props.filters.incumbents), approved: Number(props.filters.approved) }); }

// The cutoff is a full-race rank. A page break must never imply a finalist cutoff.
const lineBeforeId = computed(() => {
    const before = props.standings.findIndex((row) => row.rank !== null && row.rank > finalistX.value);
    return before >= 0 && (before === 0 || props.standings[before - 1].rank <= finalistX.value)
        ? props.standings[before].candidacy_id : null;
});
const lineAtEnd = computed(() => props.standings.at(-1)?.rank === finalistX.value);

function loadEndorsements(candidateId, url = null) {
    if (busy.value) return;
    detailsFor.value = candidateId;
    const current = new URL(page.url, window.location.origin);
    current.searchParams.set('endorsements_for', candidateId);
    current.searchParams.delete('endorsement_cursor');
    loading.value = true;
    router.get(url ?? (current.pathname + current.search), {}, {
        only: ['endorsementDetails'], preserveScroll: true, preserveState: true,
        onSuccess: () => announce(t('c_elections.open.announce_endorsements', 'Organization endorsements loaded')),
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="race ? t('c_elections.open.title_race', 'Open ballot — {race}', { named: { race: race.label } }) : t('c_elections.open.title', 'Open ballot')">
        <template #intro>
            {{ t('c_elections.open.intro', 'Approve the candidates you trust. You can change your mind until the phase closes. The top finalists then go on the ranked ballot, where write-ins stay open.') }}
        </template>
        <template #about>
            <p>{{ t('c_elections.open.about', 'Approval counts and ranks update daily and freeze at the finalist cutoff. Individual approvals remain secret. You can search every candidate in this race without loading the whole ballot at once.') }}</p>
        </template>
        <Banner v-if="nudge.show.value" tone="info">
            {{ t('c_elections.open.nudge', 'First time voting here? Explore how the election works.') }}
            <span class="cluster" style="margin-block-start: var(--space-1)">
                <Btn :as="Link" :href="nudge.href" variant="secondary" size="sm">{{ t('c_elections.open.take_journey', 'Take the journey') }}</Btn>
                <Btn variant="ghost" size="sm" @click="nudge.dismiss()">{{ t('c_elections.open.dismiss', 'Dismiss') }}</Btn>
            </span>
        </Banner>
        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="notice" tone="warning">{{ notice }}</Banner>
        <Banner v-if="directoryNotice" tone="info">{{ directoryNotice }}</Banner>
        <Banner v-if="Object.keys(errors).length" tone="warning" :title="t('c_elections.open.check_request', 'Please check this request')">
            <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
            <Btn v-if="errors.cursor" :disabled="busy" @click="firstPage">{{ t('c_elections.open.return_first', 'Return to first page') }}</Btn>
        </Banner>
        <PhaseBanner :phase="phase" context="open-ballot" :links="race ? {
            rankedBallot: `/elections/${race.election_id}/ranked-ballot?race=${race.id}`,
            results: `/elections/${race.election_id}/results?race=${race.id}`,
        } : {}" />
        <Card v-if="!race" as="section" :title="t('c_elections.open.no_races_title', 'No races yet')">
            <p class="gloss">{{ t('c_elections.open.no_races_body', 'The election board has not finished preparing this election\'s races.') }}</p>
        </Card>
        <template v-else>
            <Card v-if="races.length" as="section">
                <div class="cluster" style="justify-content: space-between; align-items: center">
                    <p style="margin: 0"><strong>{{ inFootprint ? t('c_elections.open.your_race', 'Your race:') : t('c_elections.open.viewing', 'Viewing:') }}</strong> {{ race.label }}.</p>
                    <label class="cluster" style="gap: var(--space-2); align-items: center">
                        <span class="gloss">{{ t('c_elections.open.see_another', 'See another race') }}</span>
                        <select class="select" :value="race.id" :disabled="busy" @change="switchRace($event.target.value)">
                            <option v-for="option in races" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                    </label>
                </div>
            </Card>
            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="stats.seats" :label="t('c_elections.open.stat_seats', 'seats in this race')" />
                <Stat :value="stats.finalistPlaces" :label="t('c_elections.open.stat_finalist_places', 'finalist places')" accent />
                <Stat :value="stats.validatedCandidates" :label="t('c_elections.open.stat_candidates', 'candidates in this race')" />
                <Stat :value="myActiveApprovals" :label="t('c_elections.open.stat_my_approvals', 'your active approvals across this race')" />
            </div>
            <Banner tone="info" :title="t('c_elections.open.secret_title', 'Your approvals are secret.')">
                {{ t('c_elections.open.secret_body', 'Public counts update daily. Your approval appears immediately in your switch and personal total. It does not change the public count until the next update.') }}
            </Banner>
            <Banner v-if="!inFootprint" tone="info">{{ t('c_elections.open.browse_only', 'You can browse this race. Approving requires a residency association here.') }}</Banner>

            <form @submit.prevent="applyFilters">
                <FilterBar :label="t('c_elections.open.filter_label', 'Search the entire race — ranks always reflect the full race')">
                    <Field :label="t('c_elections.open.filter_name', 'Candidate name, statement or topic')" id="ob-search">
                        <template #control="{ id }"><input :id="id" v-model="filters.q" type="search" class="field-input" maxlength="160" autocomplete="off" /></template>
                    </Field>
                    <Field :label="t('c_elections.open.filter_org', 'Endorsing organization name')" id="ob-organization">
                        <template #control="{ id }"><input :id="id" v-model="filters.organization" type="search" class="field-input" maxlength="160" autocomplete="off" /></template>
                    </Field>
                    <Field :label="t('c_elections.open.filter_endorsements', 'Endorsements')" id="ob-endorser">
                        <template #control="{ id }">
                            <select :id="id" v-model="filters.endorser" class="field-input">
                                <option value="any">{{ t('c_elections.open.endorser_any', 'Any') }}</option><option value="organizations">{{ t('c_elections.open.endorser_orgs', 'Organizations') }}</option>
                                <option value="individuals">{{ t('c_elections.open.endorser_individuals', 'Individuals') }}</option><option value="none">{{ t('c_elections.open.endorser_none', 'No endorsements') }}</option>
                            </select>
                        </template>
                    </Field>
                    <div class="cluster">
                        <label><input v-model="filters.incumbents" type="checkbox" /> {{ t('c_elections.open.filter_incumbents', 'Current officeholders') }}</label>
                        <label><input v-model="filters.approved" type="checkbox" /> {{ t('c_elections.open.filter_approved', 'My active approvals') }}</label>
                        <Btn type="submit" variant="primary" :disabled="busy">{{ t('c_elections.open.search_btn', 'Search') }}</Btn>
                        <Btn v-if="filtersActive" variant="ghost" :disabled="busy" @click="clearFilters">{{ t('c_elections.open.clear_filters', 'Clear filters') }}</Btn>
                    </div>
                </FilterBar>
            </form>

            <p role="status" aria-live="polite">{{ saving ? t('c_elections.open.saving', 'Saving your approval…') : loading ? t('c_elections.open.loading', 'Loading…') : t('c_elections.open.page_count', '{n} candidates on this page. Search and page through the entire race.', { named: { n: standings.length } }) }}</p>
            <Card as="section" style="padding: 0" :aria-busy="busy">
                <div style="padding-block: var(--space-4) 0; padding-inline: var(--space-6)">
                    <h2>{{ t('c_elections.open.standings', 'Standings') }} <span class="citation">{{ approvalOpen ? t('c_elections.open.updated_daily', 'Updated daily · as of {when}', { named: { when: race.asOf ?? t('c_elections.open.first_update_pending', 'the first update is pending') } }) : t('c_elections.open.frozen_cutoff', 'Frozen at the finalist cutoff') }}</span></h2>
                    <p v-if="approvable" class="gloss">{{ t('c_elections.open.toggle_hint', 'Toggle a candidate\'s switch to save or withdraw your approval.') }}</p>
                </div>
                <template v-for="row in standings" :key="row.candidacy_id">
                    <FinalistLine v-if="row.candidacy_id === lineBeforeId" :count="finalistX" />
                    <CandidateRow :candidacy="row.candidacy" :rank="row.rank" :approvals="row.approvals" :delta="row.delta"
                        :approved="!!approved[row.candidacy_id]" :approvable="approvable && (approved[row.candidacy_id] || standingStatuses.includes(row.status))"
                        :busy="busy" :show-switch="inFootprint" @toggle-approve="toggleApprove">
                        <template #meta>
                            <StatusBadge v-if="row.status === 'withdrawn'" tone="danger">{{ t('c_elections.open.withdrawn', 'Withdrawn') }}</StatusBadge>
                            <Btn v-if="row.candidacy.endorsements.more_organizations" variant="ghost" size="sm" :disabled="busy"
                                :aria-expanded="detailsFor === row.candidacy_id" @click="loadEndorsements(row.candidacy_id)">
                                {{ t('c_elections.open.more_endorsements', 'More organization endorsements for {name}', { named: { name: row.candidacy.name } }) }}
                            </Btn>
                        </template>
                    </CandidateRow>
                    <div v-if="detailsFor === row.candidacy_id" class="stack" style="padding: var(--space-4) var(--space-6)">
                        <h3>{{ t('c_elections.open.orgs_endorsing', 'Organizations endorsing {name}', { named: { name: row.candidacy.name } }) }}</h3>
                        <template v-if="endorsementDetails?.candidateId === row.candidacy_id">
                            <p v-if="endorsementDetails.notice" role="status">{{ endorsementDetails.notice }}</p>
                            <ul><li v-for="org in endorsementDetails.organizations" :key="org.id"><Link :href="`/organizations/${org.id}`">{{ org.name }}</Link></li></ul>
                            <p v-if="!endorsementDetails.organizations.length" class="gloss">{{ t('c_elections.open.no_org_endorsements', 'No active public organization endorsements.') }}</p>
                            <nav class="cluster" :aria-label="t('c_elections.open.endorsement_pages', 'Organization endorsement pages')">
                                <Btn v-if="endorsementDetails.previous" :disabled="busy" @click="loadEndorsements(row.candidacy_id, endorsementDetails.previous)">{{ t('c_elections.open.prev_orgs', 'Previous organizations') }}</Btn>
                                <Btn v-if="endorsementDetails.next" :disabled="busy" @click="loadEndorsements(row.candidacy_id, endorsementDetails.next)">{{ t('c_elections.open.next_orgs', 'Next organizations') }}</Btn>
                            </nav>
                        </template>
                        <Btn variant="ghost" :disabled="busy" @click="detailsFor = null">{{ t('c_elections.open.close_endorsements', 'Close endorsements') }}</Btn>
                    </div>
                </template>
                <FinalistLine v-if="lineAtEnd" :count="finalistX" />
                <div v-if="!standings.length" style="padding: var(--space-4) var(--space-6)">
                    <p class="gloss">{{ stats.validatedCandidates ? t('c_elections.open.no_match_page', 'No candidates on this page match your search.') : t('c_elections.open.no_candidates', 'No validated candidates yet. Any associated resident can stand.') }}</p>
                    <Btn v-if="stats.validatedCandidates" :disabled="busy" @click="clearFilters">{{ t('c_elections.open.show_first_all', 'Show first page of all candidates') }}</Btn>
                </div>
                <nav class="cluster" :aria-label="t('c_elections.open.candidate_pages', 'Candidate pages')" style="padding: var(--space-4) var(--space-6)">
                    <Btn v-if="pagination.previous" :disabled="busy" @click="visit(pagination.previous)">{{ t('c_elections.open.prev_candidates', 'Previous candidates') }}</Btn>
                    <Btn v-if="pagination.next" :disabled="busy" @click="visit(pagination.next)">{{ t('c_elections.open.next_candidates', 'Next candidates') }}</Btn>
                    <Btn v-if="pagination.previous" variant="ghost" :disabled="busy" @click="firstPage">{{ t('c_elections.open.first_page', 'First page') }}</Btn>
                </nav>
            </Card>
            <Card v-if="approvalOpen" as="section" :title="t('c_elections.open.stand_title', 'Stand for office')">
                <p class="cc-small">{{ t('c_elections.open.stand_body', 'Any associated resident may register. Registration stays open until the finalist cutoff.') }}</p>
                <Btn :as="Link" :href="`/elections/${race.election_id}/candidacy`" variant="primary" icon="user">{{ t('c_elections.open.register_btn', 'Register candidacy') }}</Btn>
            </Card>
        </template>
    </PageScaffold>
</template>
