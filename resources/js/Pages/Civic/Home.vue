<script setup>
/**
 * Civic/Home — the post-login civic dashboard (civic/civic-home contract,
 * EXPLORE_civic_electoral.md §2; mockups/civic/civic-home.html).
 *
 * Pure dashboard/navigation. Everything renders from server facts:
 * rights badges from shared auth.roles (derived, never stored — Art. I),
 * the residency status card from the open claim, association chips from
 * residency_confirmations. Elections (Phase B) and petitions (Phase C)
 * show HONEST empty states — no fixtures.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    claim: { type: Object, default: null },
    machine: { type: Array, default: () => [] },
    associations: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    elections: { type: Array, default: () => [] },
    petitions: { type: Array, default: () => [] },
    /** Emergency banner slot — dormant until Phase C (Art. II §7). */
    emergency: { type: Object, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const roles = computed(() => page.props.auth?.roles ?? []);
const flash = computed(() => page.props.flash?.status ?? null);

const firstName = computed(() => {
    const name = user.value?.display_name || user.value?.name || '';
    return name.split(/\s+/)[0] || name;
});

const isVoter = computed(() => roles.value.includes('R-04'));
const hasClaim = computed(() => props.claim !== null);
const claimActive = computed(() => props.claim?.status === 'active');

const scopeName = computed(
    () =>
        props.claim?.jurisdiction?.name ??
        page.props.jurisdiction?.current?.name ??
        null,
);

/* Election phase → badge tone + label (elections.status vocabulary). */
const ELECTION_STATUS = {
    scheduled:       { tone: 'neutral', label: 'Scheduled' },
    approval_open:   { tone: 'info',    label: 'Approval open' },
    finalist_cutoff: { tone: 'info',    label: 'Finalist cutoff' },
    ranked_open:     { tone: 'warning', label: 'Ranked open' },
    voting_closed:   { tone: 'warning', label: 'Voting closed' },
    tabulating:      { tone: 'warning', label: 'Tabulating' },
    certified:       { tone: 'success', label: 'Certified' },
    audit_rerun:     { tone: 'warning', label: 'Audit rerun' },
    final:           { tone: 'success', label: 'Final' },
};

const electionBadge = (status) => ELECTION_STATUS[status] ?? { tone: 'neutral', label: status };
</script>

<template>
    <PageScaffold :surface="surface" :title="`Welcome${firstName ? `, ${firstName}` : ''}`">
        <template #intro>
            <template v-if="scopeName">
                Your civic dashboard for <strong>{{ scopeName }}</strong> — everything below is
                scoped to the jurisdictions you are associated with, from your declared boundary up
                to Earth.
            </template>
            <template v-else>
                Your civic dashboard. Declare residency to associate with every enclosing
                jurisdiction at once — voting and candidacy unlock automatically, with no other
                requirements.
            </template>
        </template>
        <template #about>
            <p>
                Entry point after residency establishment (WF-CIV-02); launches ballots, petitions,
                and approval browsing in their shipping phases. Rights derive from residency facts
                alone — never from anything on this page.
            </p>
        </template>

        <!-- Emergency banner slot — dormant (Phase C machinery, Art. II §7). -->
        <Banner v-if="emergency" tone="emergency" :title="emergency.title">
            {{ emergency.body }}
            <span class="citation">Art. II §7 · CLK-03</span>
        </Banner>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>

        <!-- ───────────────────────────────────────────── Your rights -->
        <Card as="section" title="Your rights">
            <div class="cluster" style="gap: var(--space-4)">
                <template v-if="isVoter">
                    <StatusBadge tone="success" icon="check">Voting unlocked</StatusBadge>
                    <StatusBadge tone="success" icon="check">Candidacy unlocked</StatusBadge>
                </template>
                <template v-else>
                    <StatusBadge tone="neutral" icon="clock">Voting — unlocks on residency verification</StatusBadge>
                    <StatusBadge tone="neutral" icon="clock">Candidacy — unlocks on residency verification</StatusBadge>
                </template>
                <HardenedChip />
            </div>
            <p style="margin-block-start: var(--space-3)">
                <template v-if="isVoter">
                    Voting and candidacy unlocked automatically when your residency was verified —
                    there are no other requirements, and your association exists at every nesting
                    level simultaneously.
                </template>
                <template v-else>
                    Voting and candidacy unlock automatically the moment residency verifies —
                    jurisdictional residency is the only requirement, ever. No identity check,
                    course, or fee can be added between you and your rights.
                </template>
            </p>
            <p class="citation">Voting and candidacy — no other requirements · Art. I; Art. V §1</p>
            <div v-if="associations.length" class="cluster" style="margin-block-start: var(--space-3)">
                <AdmChip
                    v-for="assoc in associations"
                    :key="assoc.id"
                    :level="assoc.adm_level"
                    :label="assoc.name"
                />
            </div>
        </Card>

        <!-- ───────────────────────────────────────── Residency status -->
        <Card as="section" title="Residency">
            <template v-if="!hasClaim">
                <p>
                    You have not declared residency yet. Declare the smallest boundary you live
                    inside; every enclosing level associates automatically once your presence
                    pattern verifies.
                </p>
                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn :as="Link" href="/civic/residency" variant="primary" icon="map-pin">
                        Declare residency
                    </Btn>
                </div>
            </template>
            <template v-else>
                <StateStrip :states="machine" :current="claim.status" />
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    Declared boundary: <strong>{{ claim.jurisdiction?.name ?? '—' }}</strong>
                </p>
                <ThresholdMeter
                    v-if="!claimActive"
                    :value="claim.qualifying_days"
                    :max="claim.threshold"
                    :threshold="claim.threshold"
                    label="Qualifying days toward the residency threshold"
                >
                    {{ claim.qualifying_days }} of {{ claim.threshold }} qualifying days
                    <template #note>residency_confirmation_days · CLK-05</template>
                </ThresholdMeter>
                <p v-else>
                    Residency verified — you are associated at {{ associations.length }}
                    nesting level{{ associations.length === 1 ? '' : 's' }}.
                </p>
                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn :as="Link" href="/civic/residency" variant="secondary" size="sm">
                        Open residency
                        <Icon name="arrow-right" size="sm" />
                    </Btn>
                </div>
            </template>
            <p class="citation">Residency verified → all associations → rights unlocked · Art. I · CLK-05</p>
        </Card>

        <!-- ──────────────────────────────────────────────── Elections -->
        <Card as="section" :title="`Active elections${scopeName ? ` — ${scopeName}` : ''}`">
            <p v-if="elections.length === 0" class="gloss">
                No elections in your footprint yet — when a race opens in any jurisdiction you
                are associated with, it appears here with its phase and ballot call-to-action.
            </p>
            <ul v-else class="election-list">
                <li v-for="e in elections" :key="e.id" class="election-row">
                    <Link :href="`/elections/${e.id}`">
                        <AdmChip :level="e.adm_level" :label="e.jurisdiction" />
                        <span style="margin-inline-start: var(--space-2)">{{ e.kind }} election</span>
                    </Link>
                    <StatusBadge :tone="electionBadge(e.status).tone">
                        {{ electionBadge(e.status).label }}
                    </StatusBadge>
                    <Link v-if="e.status === 'approval_open'" :href="`/elections/${e.id}/open-ballot`">
                        Open ballot →
                    </Link>
                    <Link v-else-if="e.status === 'ranked_open'" :href="`/elections/${e.id}/ranked-ballot`">
                        Ranked ballot →
                    </Link>
                    <Link v-else-if="e.status === 'certified' || e.status === 'final'" :href="`/elections/${e.id}/results`">
                        Results →
                    </Link>
                </li>
            </ul>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Approval phase opens the moment the prior election certifies · CLK-18 · Art. II §2
            </p>
        </Card>

        <div class="grid-2">
            <!-- ──────────────────────────────────────────── Petitions -->
            <Card as="section" title="Petitions near you">
                <p v-if="petitions.length === 0" class="gloss">
                    No open petitions in your association chain — any associated resident can
                    start one; signature thresholds derive from jurisdiction population
                    (5%, amendable · CLK-17).
                </p>
                <ul v-else class="stack" style="gap: var(--space-2); list-style: none; padding: 0; margin: 0">
                    <li v-for="p in petitions" :key="p.id" class="cluster" style="justify-content: space-between">
                        <span>
                            <Link :href="p.href">{{ p.title }}</Link>
                            <span class="citation" style="display: block">
                                {{ p.jurisdiction }} · {{ p.signatures.toLocaleString() }} of {{ p.threshold_count.toLocaleString() }} signatures
                            </span>
                        </span>
                        <StatusBadge :tone="p.state === 'gathering' ? 'info' : 'neutral'">{{ p.state }}</StatusBadge>
                    </li>
                </ul>
                <p style="margin-block-start: var(--space-3)">
                    <Link href="/civic/petitions">
                        All petitions
                        <Icon name="arrow-right" size="sm" />
                    </Link>
                </p>
            </Card>

            <!-- ──────────────────────────────────────────── My record -->
            <Card as="section" title="My record">
                <p class="cc-small">Your public civic record — it travels with any future candidacy.</p>
                <div class="cluster" style="gap: var(--space-6)">
                    <Stat :value="stats.record_entries" label="record entries" />
                    <Stat :value="stats.associations" label="associations" />
                    <Stat :value="stats.ballots_cast" label="ballots cast" />
                </div>
                <p style="margin-block-start: var(--space-3)">
                    <Link href="/civic/record">
                        Open my record
                        <Icon name="arrow-right" size="sm" />
                    </Link>
                </p>
            </Card>
        </div>
    </PageScaffold>
</template>

<style scoped>
.election-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.election-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
    padding-block: var(--space-2);
    border-block-end: 1px solid var(--gov-border, #d6d9de);
}

.election-row:last-child {
    border-block-end: 0;
}
</style>
