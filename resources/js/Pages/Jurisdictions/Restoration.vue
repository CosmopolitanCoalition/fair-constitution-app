<script setup>
/**
 * Jurisdictions/Restoration — "Rebuilding a lost government" (design
 * contract: mockups/v3/jurisdictions/restoration.html).
 *
 * Art. VI §2–3: when a fair government is countermanded, captured, or
 * destroyed, restoration activates — evidence-based and judicially
 * reviewable, never a unilateral switch — and rebuilding elections cascade
 * down three tiers: constituent jurisdictions first, then the encompassing
 * jurisdiction, then individuals organizing themselves. This page is the
 * standing drill (teaching structure) plus a real read of restoration
 * events; a world with none says so plainly.
 */
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    events: { type: Array, default: () => [] },
    conditions: { type: Array, default: () => [] },
});

const active = props.events.filter((e) => ['declared', 'confirmed', 'restoring'].includes(e.status));

const conditionCopy = {
    countermanded: {
        icon: 'alert-triangle',
        title: 'Countermanded',
        body: 'Lawful acts are blocked or replaced by an authority with no constitutional basis — the government is overridden contrary to the constitution.',
    },
    captured: {
        icon: 'shield',
        title: 'Captured or disabled',
        body: 'The institutions exist but no longer answer to their constituents — seized, coerced, or procedurally locked.',
    },
    destroyed: {
        icon: 'x',
        title: 'Destroyed',
        body: 'The institutions no longer exist — disaster, war, or collapse.',
    },
};

const conditionMet = (condition) => active.some((e) => e.condition === condition);

const tiers = [
    { n: 1, label: 'Constituent jurisdictions elect', actor: 'Constituent legislatures and populations' },
    { n: 2, label: 'The encompassing jurisdiction calls elections', actor: 'Encompassing legislature and election board' },
    { n: 3, label: 'Individuals self-organize', actor: 'Individuals — re-entering bootstrap from a dormant boundary' },
];

const tierState = (n) => {
    const activeTiers = active.map((e) => e.tier).filter((t) => t > 0);
    if (activeTiers.length === 0) return 'standby';
    const current = Math.max(...activeTiers);
    return n === current ? 'active' : n < current ? 'bypassed' : 'standby';
};
</script>

<template>
    <PageScaffold :surface="surface" title="Rebuilding a lost government">
        <template #intro>
            When a fair government is countermanded, captured, or destroyed, restoration
            activates — and rebuilding elections cascade down three tiers, each activating
            only when the one above cannot act. Detection triggers a cascade of rebuilding
            elections. This page is the standing drill.
        </template>

        <Banner v-if="active.length === 0" tone="info" role="status">
            <strong>Restoration mode dormant — no activation condition detected.</strong>
            The walkthrough below is the standing drill; a declared and judicially confirmed
            event arms it.
        </Banner>
        <Banner v-else tone="emergency" role="alert">
            <strong>Restoration active.</strong>
            {{ active.length }} event(s) in progress — functioning elections, sessions, and
            courts continue undisturbed while the cascade rebuilds what was lost.
        </Banner>

        <Card as="section">
            <template #title>Activation conditions</template>
            <p>
                Detection is evidence-based and judicially reviewable — never a unilateral
                switch. A declared condition activates nothing until a court confirms it.
            </p>
            <div class="grid-2">
                <div v-for="c in conditions" :key="c">
                    <h4><Icon :name="conditionCopy[c]?.icon || 'alert-triangle'" size="sm" /> {{ conditionCopy[c]?.title || plainState(c) }}</h4>
                    <p>{{ conditionCopy[c]?.body }}</p>
                    <StatusBadge v-if="conditionMet(c)" tone="danger">Condition met</StatusBadge>
                    <StatusBadge v-else tone="neutral">Not detected</StatusBadge>
                </div>
            </div>
        </Card>

        <Card as="section">
            <template #title>The restoration cascade</template>
            <ol class="flow-steps">
                <li v-for="tier in tiers" :key="tier.n" :aria-current="tierState(tier.n) === 'active' ? 'step' : undefined">
                    <strong>Tier {{ tier.n }} — {{ tier.label }}</strong>
                    <StatusBadge v-if="tierState(tier.n) === 'active'" tone="danger">Active</StatusBadge>
                    <StatusBadge v-else-if="tierState(tier.n) === 'bypassed'" tone="neutral">Bypassed</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">Standby</StatusBadge>
                    <p>{{ tier.actor }}</p>
                </li>
            </ol>
            <p>
                Rebuilding elections reuse the first-election machinery —
                <a href="/jurisdictions/bootstrap">how a place wakes up</a>.
            </p>
        </Card>

        <Card v-if="events.length" as="section">
            <template #title>Restoration events</template>
            <DataTable
                :columns="[
                    { key: 'jurisdiction', label: 'Place' },
                    { key: 'condition', label: 'Condition' },
                    { key: 'confirmed', label: 'Judicial review' },
                    { key: 'tier', label: 'Tier' },
                    { key: 'status', label: 'Status' },
                ]"
                :rows="events"
                row-key="id"
            >
                <template #cell-condition="{ row }">{{ conditionCopy[row.condition]?.title || plainState(row.condition) }}</template>
                <template #cell-confirmed="{ row }">
                    <StatusBadge v-if="row.judicially_confirmed" tone="success" icon="check">Confirmed</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">Awaiting review</StatusBadge>
                </template>
                <template #cell-tier="{ row }">
                    <span data-no-i18n>{{ row.tier > 0 ? `Tier ${row.tier}` : '—' }}</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="row.status === 'restored' ? 'success' : ['declared', 'confirmed', 'restoring'].includes(row.status) ? 'danger' : 'neutral'">
                        {{ plainState(row.status) }}
                    </StatusBadge>
                </template>
            </DataTable>
        </Card>

        <div class="grid-2">
            <Card as="section">
                <template #title>Legitimacy scoring</template>
                <p>
                    When more than one body claims to be the government, three criteria decide:
                    minimize consent violations, balance interests uniformly, govern
                    effectively.
                </p>
                <p class="citation">Legitimacy criteria — authority by consent.</p>
            </Card>
            <Card as="section">
                <template #title>Defensive forces</template>
                <p>
                    Forces are bound to protect the <strong>most legitimate</strong>
                    government — not the incumbent, not the strongest, not their own chain of
                    command's preference. During restoration, elections, sessions, and courts
                    that still function cannot be disrupted.
                </p>
                <p class="citation">Defensive forces protect the most legitimate government.</p>
            </Card>
        </div>

        <template #about>
            <p>
                Restoration is also a founding path — standing a world up from existing
                records is the same act as rebuilding one. Tier elections hand off to the
                bootstrap first election; tier 3 re-enters the jurisdiction bootstrap.
                Rebuilding is a branch, not an end state: the place returns to
                self-governing the moment a restored government is seated and certified.
            </p>
        </template>
    </PageScaffold>
</template>
