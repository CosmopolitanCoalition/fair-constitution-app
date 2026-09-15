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
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    focus: { type: Object, default: null },
    activation: { type: Object, default: null },
    threshold: { type: Object, default: null },
    stages: { type: Array, default: () => [] },
    rollup: { type: Object, default: () => ({ dormant: 0, by_state: {} }) },
});

const stageBadge = (state) =>
    state === 'done' ? { tone: 'success', icon: 'check', label: t('c_jurisdictions.bootstrap.badge_complete', 'Complete') }
        : state === 'active' ? { tone: 'info', icon: 'clock', label: t('c_jurisdictions.bootstrap.badge_in_progress', 'In progress') }
            : { tone: 'neutral', icon: 'clock', label: t('c_jurisdictions.bootstrap.badge_pending', 'Pending') };

const bootstrapBoardActive = () =>
    props.activation !== null
    && props.activation.state === 'bootstrapping';
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_jurisdictions.bootstrap.title', 'How a place wakes up')">
        <template #intro>
            {{ t('c_jurisdictions.bootstrap.intro', 'Every place starts dormant — a boundary on the map, waiting. When enough verified residents live inside it, the first election triggers and the institutions assemble in a fixed sequence. Enough people → first election → full governance.') }}
        </template>

        <Banner v-if="bootstrapBoardActive()" tone="warning" role="status">
            <strong>{{ t('c_jurisdictions.bootstrap.board_strong', 'First-election board — temporary, replacement queued.') }}</strong>
            {{ t('c_jurisdictions.bootstrap.board_rest', 'Until a legislature exists, the system itself acts as the election board using the constitutional defaults (proportional ranked vote, 5-year terms, 5–9 seats per district). The proper independent board replaces it as governance assembles.') }}
        </Banner>

        <Card v-if="focus" as="section">
            <template #title>{{ t('c_jurisdictions.bootstrap.life_title', { name: focus.name }) }}</template>
            <StateStrip
                v-if="activation"
                :states="activation.states"
                :current="activation.state"
            />
            <p v-else>
                <StatusBadge tone="neutral" icon="clock">{{ t('c_jurisdictions.bootstrap.dormant_badge', 'Dormant — boundary loaded') }}</StatusBadge>
                {{ t('c_jurisdictions.bootstrap.dormant_note', 'This place is tracked but has not crossed its critical population yet.') }}
            </p>
        </Card>

        <Card v-if="focus && threshold" as="section">
            <template #title>{{ t('c_jurisdictions.bootstrap.threshold_title', 'Critical population threshold') }}</template>
            <ThresholdMeter
                :value="threshold.verified"
                :max="Math.max(threshold.required, threshold.verified, 1)"
                :threshold="threshold.required"
                :label="t('c_jurisdictions.bootstrap.threshold_label', 'Critical population threshold')"
            >
                {{ t('c_jurisdictions.bootstrap.verified_of', { verified: threshold.verified.toLocaleString(), required: threshold.required.toLocaleString() }) }}
                <template #note>{{ t('c_jurisdictions.bootstrap.threshold_note', 'critical population threshold') }}</template>
            </ThresholdMeter>
            <p>
                {{ t('c_jurisdictions.bootstrap.threshold_before', 'The threshold counts ') }}<strong>{{ t('c_jurisdictions.bootstrap.threshold_verified', 'verified residencies') }}</strong>{{ t('c_jurisdictions.bootstrap.threshold_after', ', not raw registrations — the live census from residency verifications drives it. Activation is pegged to real population: a county can wake before its state, and every boundary is already loaded, waiting for its residents.') }}
            </p>
            <p v-if="activation && activation.critical_population_at" class="citation">
                {{ t('c_jurisdictions.bootstrap.crossed', { when: new Date(activation.critical_population_at).toLocaleString() }) }}
            </p>
        </Card>

        <Card v-if="focus" as="section">
            <template #title>{{ t('c_jurisdictions.bootstrap.sequence_title', 'The wake-up sequence') }}</template>
            <p>
                {{ t('c_jurisdictions.bootstrap.sequence_body', 'Seven stages, fixed order. Steps run automatically where the constitution allows it; elected humans take over once the legislature constitutes.') }}
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
                    <strong>{{ t('c_jurisdictions.bootstrap.stage_heading', { n: i + 1, label: stage.label }) }}</strong>
                    <StatusBadge v-bind="{ tone: stageBadge(stage.state).tone, icon: stageBadge(stage.state).icon }">
                        {{ stageBadge(stage.state).label }}
                    </StatusBadge>
                    <p>{{ stage.detail }}</p>
                </li>
            </ol>
        </Card>

        <Card v-if="!focus" as="section">
            <template #title>{{ t('c_jurisdictions.bootstrap.no_focus_title', 'No place in focus') }}</template>
            <p>
                {{ t('c_jurisdictions.bootstrap.no_focus_before', 'No jurisdiction has begun waking up yet — every boundary is dormant. Open any place from the ') }}<a href="/jurisdictions">{{ t('c_jurisdictions.bootstrap.no_focus_link', 'places browser') }}</a>{{ t('c_jurisdictions.bootstrap.no_focus_mid', ' and follow the link here, or pass ') }}<span data-no-i18n>?jurisdiction=&lt;slug&gt;</span>.
            </p>
        </Card>

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.bootstrap.world_title', 'Across the whole world') }}</template>
            <div class="cluster">
                <Stat :label="t('c_jurisdictions.bootstrap.dormant_boundaries', 'Dormant boundaries')" :value="rollup.dormant.toLocaleString()" />
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
                {{ t('c_jurisdictions.bootstrap.about_before', 'This page tracks the whole bootstrap sequence for one place — the dormant boundary, the critical-population crossing, the temporary first-election board, and the institutions assembling. Restoration reuses the same machinery when a government is lost: ') }}<a href="/jurisdictions/restoration">{{ t('c_jurisdictions.bootstrap.about_link', 'rebuilding a lost government') }}</a>.
            </p>
        </template>
    </PageScaffold>
</template>
