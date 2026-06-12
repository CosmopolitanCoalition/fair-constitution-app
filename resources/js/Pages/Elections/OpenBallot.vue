<script setup>
/**
 * Elections/OpenBallot — FE-B4 (PHASE_B_DESIGN_frontend.md §B.4 + §D;
 * mockups/electoral/open-ballot.html).
 *
 * Standings list (CandidateRow + FinalistLine at full-race rank X) over
 * the DAILY approval_standings aggregate — never a live count. The
 * approve/revoke flow is optimistic per design §D: the switch and the
 * "your active approvals" stat flip immediately; the PUBLIC AGGREGATE
 * NEVER MOVES on the viewer's action (a single-voter live delta would
 * de-anonymize the approval — Art. II §2). Failures revert the switch and
 * surface the engine's citation banner.
 *
 * Filters are entirely client-side over the delivered rows; ranks always
 * reflect the full race, and the finalist line holds its position no
 * matter what is hidden.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import CandidateRow from '@/Components/Electoral/CandidateRow.vue';
import FinalistLine from '@/Components/Electoral/FinalistLine.vue';
import PhaseBanner from '@/Components/Electoral/PhaseBanner.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import ChipToggle from '@/Components/Ui/ChipToggle.vue';
import Field from '@/Components/Ui/Field.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useAnnounce } from '@/composables/useAnnounce';

const props = defineProps({
    surface: { type: Object, required: true },
    race: { type: Object, default: null },
    races: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    standings: { type: Array, default: () => [] },
    standingsTruncated: { type: Object, default: null },
    myApprovals: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ orgs: [], tags: [] }) },
    approvable: { type: Boolean, default: false },
    inFootprint: { type: Boolean, default: false },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});
const { announce } = useAnnounce();

const phase = computed(() => props.race?.phase ?? 'approval');
const approvalOpen = computed(() => phase.value === 'approval');
const finalistX = computed(() => props.race?.finalist_count ?? 0);

/* ─────────────────────── viewer's own approvals (optimistic, local) */

const approved = reactive({});
const busy = reactive({});

watch(
    () => props.myApprovals,
    (ids) => {
        Object.keys(approved).forEach((key) => delete approved[key]);
        (ids ?? []).forEach((id) => (approved[id] = true));
    },
    { immediate: true },
);

const myActiveApprovals = computed(
    () => Object.values(approved).filter(Boolean).length,
);

const standingStatuses = ['validated', 'in_pool', 'finalist', 'non_finalist'];

function toggleApprove(candidacyId, next) {
    if (busy[candidacyId]) return;
    busy[candidacyId] = true;
    approved[candidacyId] = next; // optimistic — the aggregate never moves

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () =>
            announce(next ? 'Approved — revocable until the finalist cutoff' : 'Approval withdrawn'),
        onError: () => {
            approved[candidacyId] = !next; // revert; the engine banner explains
        },
        onFinish: () => {
            busy[candidacyId] = false;
        },
    };

    if (next) {
        router.post(
            `/elections/${props.race.election_id}/approvals`,
            { candidacy_id: candidacyId },
            options,
        );
    } else {
        router.delete(
            `/elections/${props.race.election_id}/approvals/${candidacyId}`,
            options,
        );
    }
}

/* ─────────────────────────────────────── client-side filters (§B.4) */

const search = ref('');
const endorserFilter = ref('');
const selectedTags = reactive({});
const incumbentsOnly = ref(false);

const filtersActive = computed(
    () =>
        search.value.trim() !== '' ||
        endorserFilter.value !== '' ||
        incumbentsOnly.value ||
        Object.values(selectedTags).some(Boolean),
);

function clearFilters() {
    search.value = '';
    endorserFilter.value = '';
    incumbentsOnly.value = false;
    Object.keys(selectedTags).forEach((key) => delete selectedTags[key]);
}

function rowVisible(row) {
    const c = row.candidacy;

    const q = search.value.trim().toLowerCase();
    if (q && !`${c.name} ${c.statement ?? ''}`.toLowerCase().includes(q)) return false;

    if (endorserFilter.value === '__none') {
        if (c.endorsements.orgs.length > 0 || c.endorsements.individual_count > 0) return false;
    } else if (endorserFilter.value === '__individuals') {
        if (c.endorsements.individual_count === 0) return false;
    } else if (endorserFilter.value) {
        if (!c.endorsements.orgs.some((org) => org.id === endorserFilter.value)) return false;
    }

    const activeTags = Object.keys(selectedTags).filter((tag) => selectedTags[tag]);
    if (activeTags.length && !activeTags.some((tag) => c.position_tags.includes(tag))) return false;

    if (incumbentsOnly.value && !c.incumbent) return false;

    return true;
}

const visibleRows = computed(() => props.standings.filter(rowVisible));
const hiddenCount = computed(() => props.standings.length - visibleRows.value.length);

/** FinalistLine before the first VISIBLE row past full-race rank X. */
const lineBeforeId = computed(() => {
    const firstPast = visibleRows.value.find((row) => row.rank > finalistX.value);
    return firstPast ? firstPast.candidacy_id : null;
});
/* No visible row sits past X → the line closes the list (every visible
   candidate is on the finalist track; hidden/capped rows never move it). */
const lineAtEnd = computed(
    () => lineBeforeId.value === null && visibleRows.value.length > 0,
);

/* "Show all" partial reload for capped Earth-scale races (§B.4). */
function showAll() {
    router.get(
        `/elections/${props.race.election_id}/open-ballot`,
        { race: props.race.id, full: 1 },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['standings', 'standingsTruncated', 'stats', 'filters'],
        },
    );
}

function switchRace(raceId) {
    router.get(
        `/elections/${props.race.election_id}/open-ballot`,
        { race: raceId },
        { preserveScroll: true },
    );
}
</script>

<template>
    <PageScaffold :surface="surface" :title="race ? `Open ballot — ${race.label}` : 'Open ballot'">
        <template #intro>
            Approve as many candidates as you like — approvals are revocable any time during the
            approval phase, and the top X by approvals advance to the ranked ballot.
        </template>
        <template #about>
            <p>
                WF-CIV-08 approval phase. Standings are the daily
                <code data-no-i18n>approval_standings</code> aggregate (frozen at the cutoff);
                individual approvals are constitutionally secret and never leave the system as
                rows.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Action rejected by the constitutional engine">
            {{ errors.constitution }}
        </Banner>

        <PhaseBanner
            :phase="phase"
            context="open-ballot"
            :links="race ? {
                rankedBallot: `/elections/${race.election_id}/ranked-ballot?race=${race.id}`,
                results: `/elections/${race.election_id}/results?race=${race.id}`,
            } : {}"
        />

        <template v-if="!race">
            <Card as="section" title="No races yet">
                <p class="gloss">
                    This election has no races — race generation is pending the scheduling order
                    (or subdivision · Art. II §8).
                </p>
            </Card>
        </template>

        <template v-else>
            <!-- race picker (multi-race elections only — §B race-resolution rule) -->
            <Card v-if="races.length" as="section" title="Race">
                <div class="cluster" role="group" aria-label="Choose a race to view">
                    <ChipToggle
                        v-for="option in races"
                        :key="option.id"
                        :pressed="option.id === race.id"
                        @update:pressed="switchRace(option.id)"
                    >{{ option.label }}</ChipToggle>
                </div>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    Your own race is pre-selected from your associations; browsing other races is
                    public — approving in them is not (Art. I).
                </p>
            </Card>

            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="stats.seats" label="seats in this race" />
                <Stat :value="stats.finalistPlaces" label="finalist places (X) — pre-published" accent />
                <Stat :value="stats.validatedCandidates" label="validated candidates" />
                <Stat :value="myActiveApprovals" label="your active approvals (revocable)" />
            </div>

            <Banner tone="info" title="Your approvals are secret.">
                Standings are aggregate counts updated on a daily cycle — never live, never
                individual. Your own approval shows only in your switches and the stat above;
                <strong>the public number does not move when you act</strong>.
                <span class="citation" style="display: block">ballot secrecy · Art. II §2 · approval_standings (daily rollup)</span>
            </Banner>

            <Banner v-if="!inFootprint" tone="info" role="status">
                You can browse this race; approving requires jurisdictional association here.
                <span class="citation">Art. I</span>
            </Banner>

            <!-- ─────────────────────────────────────────────── filters -->
            <FilterBar v-if="standings.length" label="Filter the standings (display only — ranks reflect the full race)">
                <Field label="Search candidates" id="ob-search">
                    <template #control="{ id }">
                        <input
                            :id="id"
                            v-model="search"
                            class="field-input"
                            type="search"
                            placeholder="name or statement…"
                            autocomplete="off"
                        />
                    </template>
                </Field>
                <Field label="Endorsed by" id="ob-endorser">
                    <template #control="{ id }">
                        <select :id="id" v-model="endorserFilter" class="field-input">
                            <option value="">— any —</option>
                            <option v-for="org in filters.orgs" :key="org.id" :value="org.id">{{ org.name }}</option>
                            <option value="__individuals">individual endorsers</option>
                            <option value="__none">no endorsements</option>
                        </select>
                    </template>
                </Field>
                <div class="cluster" style="gap: var(--space-1); align-items: center">
                    <ChipToggle
                        v-for="tag in filters.tags"
                        :key="tag"
                        :pressed="!!selectedTags[tag]"
                        @update:pressed="(next) => (selectedTags[tag] = next)"
                    >{{ tag }}</ChipToggle>
                    <ChipToggle :pressed="incumbentsOnly" @update:pressed="(next) => (incumbentsOnly = next)">
                        incumbents
                    </ChipToggle>
                    <Btn v-if="filtersActive" variant="ghost" size="sm" @click="clearFilters">Clear filters</Btn>
                </div>
            </FilterBar>

            <!-- ──────────────────────────────────────── standings list -->
            <Card as="section" style="padding: 0">
                <div style="padding-block: var(--space-4) 0; padding-inline: var(--space-6)">
                    <h2>
                        Standings
                        <span class="citation">
                            {{ approvalOpen ? `aggregate · updated daily · as of ${race.asOf ?? '—'}` : 'frozen at the finalist cutoff' }}
                        </span>
                    </h2>
                    <p v-if="hiddenCount > 0" class="gloss" role="status">
                        {{ hiddenCount }} hidden by filters — ranks reflect the full race.
                    </p>
                    <p v-if="approvable" class="gloss">
                        Your approval is recorded the moment you toggle — aggregates update daily.
                    </p>
                </div>

                <div v-if="standings.length" aria-live="polite">
                    <template v-for="row in visibleRows" :key="row.candidacy_id">
                        <FinalistLine v-if="row.candidacy_id === lineBeforeId" :count="finalistX" />
                        <CandidateRow
                            :candidacy="row.candidacy"
                            :rank="row.rank"
                            :approvals="row.approvals"
                            :delta="row.delta"
                            :approved="!!approved[row.candidacy_id]"
                            :approvable="approvable && standingStatuses.includes(row.status)"
                            :busy="!!busy[row.candidacy_id]"
                            :show-switch="inFootprint"
                            @toggle-approve="toggleApprove"
                        >
                            <template v-if="row.status === 'withdrawn'" #meta>
                                <StatusBadge tone="danger">withdrawn</StatusBadge>
                            </template>
                        </CandidateRow>
                    </template>
                    <FinalistLine v-if="lineAtEnd" :count="finalistX" />
                    <p v-if="!visibleRows.length" class="gloss" style="padding: var(--space-4) var(--space-6)">
                        Every candidate is hidden by the current filters.
                    </p>
                </div>

                <div v-else style="padding: var(--space-4) var(--space-6)">
                    <p class="gloss">
                        No validated candidates yet — any associated resident can stand.
                    </p>
                    <Btn :as="Link" :href="`/elections/${race.election_id}/candidacy`" variant="primary" icon="user" style="margin-block-end: var(--space-4)">
                        Stand for office — F-IND-011
                    </Btn>
                </div>

                <p v-if="standingsTruncated" style="padding: 0 var(--space-6) var(--space-4)">
                    Showing the top {{ standingsTruncated.shown }} of {{ standingsTruncated.total }}.
                    <Btn variant="secondary" size="sm" @click="showAll">
                        Show all {{ standingsTruncated.total }}
                    </Btn>
                </p>
            </Card>

            <!-- ─────────────────────────────────────────── footer cards -->
            <div class="grid-2">
                <Card as="section" title="Alignment questionnaire">
                    <p class="cc-small">
                        Answer policy questions and see which candidates' positions align with
                        yours. Informational only —
                        <strong>the system never auto-approves on your behalf</strong>.
                    </p>
                    <p><span class="planned-flag">Future scope</span></p>
                    <Btn variant="secondary" disabled title="Future scope — not yet built">
                        Start the questionnaire
                    </Btn>
                </Card>

                <Card as="section" title="Stand for office">
                    <p class="cc-small">
                        Any associated resident may register — residency is the only requirement
                        (Art. I). Registration stays open until the finalist cutoff.
                    </p>
                    <Btn :as="Link" :href="`/elections/${race.election_id}/candidacy`" variant="primary" icon="user">
                        Register candidacy — F-IND-011
                    </Btn>
                </Card>
            </div>
        </template>
    </PageScaffold>
</template>
