<script setup>
/**
 * Jurisdictions/Bootstrap — "How a place wakes up" (design contract:
 * mockups/v3/jurisdictions/bootstrap.html).
 *
 * Every place starts as a dormant boundary; enough verified residents cross
 * the critical-population threshold (CLK-06) and WF-JUR-01 assembles the
 * institutions. The mockup drills a 30-step registry; no materialized step
 * registry exists server-side (the gap matrix's named hole), so this page
 * renders the SEVEN STAGES derived from observable institutional facts —
 * real queries, never a simulated tracker. Focus via ?jurisdiction=<slug>,
 * default = the most recently advanced activation.
 */
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    focus: { type: Object, default: null },
    activation: { type: Object, default: null },
    threshold: { type: Object, default: null },
    stages: { type: Array, default: () => [] },
    rollup: { type: Object, default: () => ({ dormant: 0, by_state: {} }) },
});

const stageBadge = (state) =>
    state === 'done' ? { tone: 'success', icon: 'check', label: 'Complete' }
        : state === 'active' ? { tone: 'info', icon: 'clock', label: 'In progress' }
            : { tone: 'neutral', icon: 'clock', label: 'Pending' };

const bootstrapBoardActive = () =>
    props.activation !== null
    && props.activation.state === 'bootstrapping';
</script>

<template>
    <PageScaffold :surface="surface" title="How a place wakes up">
        <template #intro>
            Every place starts dormant — a boundary on the map, waiting. When enough verified
            residents live inside it, the first election triggers and the institutions assemble
            in a fixed sequence. Enough people → first election → full governance.
        </template>

        <Banner v-if="bootstrapBoardActive()" tone="warning" role="status">
            <strong>First-election board — temporary, replacement queued.</strong>
            Until a legislature exists, the system itself acts as the election board using the
            constitutional defaults (proportional ranked vote, 5-year terms, 5–9 seats per
            district). The proper independent board replaces it as governance assembles.
        </Banner>

        <Card v-if="focus" as="section">
            <template #title>The life of a place — where {{ focus.name }} sits</template>
            <StateStrip
                v-if="activation"
                :states="activation.states"
                :current="activation.state"
            />
            <p v-else>
                <StatusBadge tone="neutral" icon="clock">Dormant — boundary loaded</StatusBadge>
                This place is tracked but has not crossed its critical population yet.
            </p>
        </Card>

        <Card v-if="focus && threshold" as="section">
            <template #title>Critical population threshold</template>
            <ThresholdMeter
                :value="threshold.verified"
                :max="Math.max(threshold.required, threshold.verified, 1)"
                :threshold="threshold.required"
                label="Critical population threshold"
            >
                {{ threshold.verified.toLocaleString() }} of
                {{ threshold.required.toLocaleString() }} verified residents
                <template #note>critical population threshold</template>
            </ThresholdMeter>
            <p>
                The threshold counts <strong>verified residencies</strong>, not raw
                registrations — the live census from residency verifications drives it.
                Activation is pegged to real population: a county can wake before its state,
                and every boundary is already loaded, waiting for its residents.
            </p>
            <p v-if="activation && activation.critical_population_at" class="citation">
                Crossed {{ new Date(activation.critical_population_at).toLocaleString() }}
                (shown in your timezone · stored as UTC)
            </p>
        </Card>

        <Card v-if="focus" as="section">
            <template #title>The wake-up sequence</template>
            <p>
                Seven stages, fixed order. Steps run automatically where the constitution
                allows it; elected humans take over once the legislature constitutes.
            </p>
            <ol class="flow-steps">
                <li
                    v-for="(stage, i) in stages"
                    :key="stage.label"
                    :aria-current="stage.state === 'active' ? 'step' : undefined"
                >
                    <Icon
                        :name="stage.state === 'done' ? 'check' : stage.state === 'active' ? 'arrow-right' : 'clock'"
                        size="sm"
                    />
                    <strong>Stage {{ i + 1 }} — {{ stage.label }}</strong>
                    <StatusBadge v-bind="{ tone: stageBadge(stage.state).tone, icon: stageBadge(stage.state).icon }">
                        {{ stageBadge(stage.state).label }}
                    </StatusBadge>
                    <p>{{ stage.detail }}</p>
                </li>
            </ol>
        </Card>

        <Card v-if="!focus" as="section">
            <template #title>No place in focus</template>
            <p>
                No jurisdiction has begun waking up yet — every boundary is dormant. Open any
                place from the <a href="/jurisdictions">places browser</a> and follow the link
                here, or pass <span data-no-i18n>?jurisdiction=&lt;slug&gt;</span>.
            </p>
        </Card>

        <Card as="section">
            <template #title>Across the whole world</template>
            <div class="cluster">
                <Stat label="Dormant boundaries" :value="rollup.dormant.toLocaleString()" />
                <Stat
                    v-for="(n, state) in rollup.by_state"
                    :key="state"
                    :label="plainState(state)"
                    :value="n.toLocaleString()"
                />
            </div>
        </Card>

        <template #about>
            <p>
                This page tracks the whole bootstrap sequence for one place — the dormant
                boundary, the critical-population crossing, the temporary first-election
                board, and the institutions assembling. Restoration reuses the same
                machinery when a government is lost:
                <a href="/jurisdictions/restoration">rebuilding a lost government</a>.
            </p>
        </template>
    </PageScaffold>
</template>
