<script setup>
/**
 * Civic/Relocation — FE-C10 (PHASE_C_DESIGN_frontend.md §B.14, WF-CIV-03).
 *
 * Away-pattern card (ThresholdMeter on the CLK-05 threshold) · the
 * travel-vs-move choice ("I'm travelling" POSTs the audited reset; "I'm
 * moving" is a 3-step explainer → /civic/residency — F-IND-003 reused,
 * no new form) · held-office grace card · the zero-rights-gap hardened
 * banner · in-flight move progress.
 *
 * DETECTION IS HONESTLY EMPTY in Phase C: away-pattern detection needs
 * continuous ping telemetry (Phase F mobile geofencing — deferral,
 * PHASE_C_DESIGN_votes_laws §F.2). The meter grammar is wired for the
 * day it arrives; `detection: null` renders the calm empty state.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    detection: { type: Object, default: null },
    homeClaim: { type: Object, default: null },
    heldOffices: { type: Array, default: () => [] },
    newClaim: { type: Object, default: null },
    machine: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

const travellingForm = useForm({});
function declareTravelling() {
    travellingForm.post(props.urls.travelling, { preserveScroll: true });
}

const currentMachineState = computed(() => props.newClaim?.status ?? props.homeClaim?.status ?? null);
</script>

<template>
    <PageScaffold :surface="surface" :title="detection ? 'It looks like you may have moved' : 'Relocation'">
        <template #intro>
            Your residency follows where you actually live. When a sustained presence pattern
            forms outside your home jurisdiction, the system asks — it never reassigns you
            silently, and it never penalizes travel.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- The zero-rights-gap promise — hardened, renders always. -->
        <Banner tone="info" icon="lock" title="Your old claim stays Active until the new one Verifies — no rights gap, ever.">
            Voting, candidacy, and every association hold through the whole move
            <span class="citation" data-no-i18n> · Art. I · Art. V §1–2 · hardened</span>
        </Banner>

        <!-- ==================================== detection ================ -->
        <Card as="section" title="Away-pattern detection">
            <template v-if="detection">
                <Banner tone="warning" icon="map-pin" :title="`${detection.away_days} qualifying days near ${detection.detected_near?.label ?? 'another jurisdiction'} — outside ${homeClaim?.jurisdiction?.name ?? 'your home jurisdiction'}.`">
                    Detection uses the same encrypted ping log as verification; only day-counts are
                    visible. <span class="citation">CLK-05 · residency_confirmation_days · Art. V §1</span>
                </Banner>
                <ThresholdMeter
                    :value="detection.away_days"
                    :max="detection.threshold_days"
                    :threshold="detection.threshold_days"
                    label="Qualifying days away — CLK-05 threshold"
                    style="margin-block-start: var(--space-3)"
                >
                    {{ detection.away_days }} of {{ detection.threshold_days }} qualifying days near {{ detection.detected_near?.label ?? '—' }}
                    <template #note>threshold · CLK-05</template>
                </ThresholdMeter>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    A move only completes when the away-pattern reaches the full residency
                    threshold — the same standard your home verification used.
                </p>
            </template>
            <template v-else>
                <div class="cluster">
                    <StatusBadge tone="success" icon="check">No relocation pattern detected</StatusBadge>
                    <span class="citation">away detection arrives with mobile pinging · Planned · Phase F</span>
                </div>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    This page activates when sustained pings appear outside your declared
                    jurisdiction. Detection uses the same encrypted ping log as verification —
                    only day-counts are ever visible, and pings stay pausable in personal settings.
                </p>
                <p v-if="homeClaim" class="cc-small" style="margin-block-start: var(--space-2)">
                    Home residency: <strong>{{ homeClaim.jurisdiction.name }}</strong>
                    <StatusBadge tone="success" style="margin-inline-start: var(--space-1)">{{ homeClaim.status }}</StatusBadge>
                    <span class="citation"> · declared {{ homeClaim.declared_at }}</span>
                </p>
            </template>
        </Card>

        <!-- ==================================== travel or move =========== -->
        <Card as="section" title="Travel, or a move?">
            <p class="cc-small">
                Tell the system which this is. Either answer is final only when you say so — and a
                move still requires the full threshold pattern before anything transfers.
            </p>
            <div class="cluster" role="group" aria-label="Travel or move">
                <Btn
                    variant="secondary"
                    :disabled="travellingForm.processing"
                    @click="declareTravelling"
                >I'm travelling — keep my residency</Btn>
                <Btn as="a" :href="urls.residency" variant="secondary">I'm moving — start re-association</Btn>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Travelling: detection resets, nothing changes — pings pausable in personal
                settings; the declaration is audit-chained (WF-CIV-03). Moving: a new residency
                declaration (F-IND-003 on the residency screen) alongside your active claim IS the
                move — no new form exists here, by design.
            </p>

            <ol class="flow-steps" style="margin-block-start: var(--space-3)">
                <li class="flow-step" :class="{ 'flow-step--current': newClaim !== null }">
                    <div class="flow-step-head">
                        <span class="flow-step-n">1</span><span class="flow-actor">You</span>
                        <span class="flow-action">Declare residency in the new jurisdiction</span>
                    </div>
                    <p class="flow-outcome">
                        Residency declaration · F-IND-003 on <Link :href="urls.residency">the residency screen</Link>;
                        ping monitoring restarts there.
                    </p>
                </li>
                <li class="flow-step">
                    <div class="flow-step-head">
                        <span class="flow-step-n">2</span><span class="flow-actor">System</span>
                        <span class="flow-action">Away-pattern accumulates to the threshold (CLK-05)</span>
                    </div>
                    <p class="flow-outcome">Old associations remain fully active until then — no gap in voting or candidacy.</p>
                </li>
                <li class="flow-step">
                    <div class="flow-step-head">
                        <span class="flow-step-n">3</span><span class="flow-actor">System</span>
                        <span class="flow-action">Associations transfer; held offices resolve via the grace period</span>
                    </div>
                    <p class="flow-outcome">
                        Old roles gracefully expire; a held seat vacates into countback (F-LEG-036 → WF-ELE-03);
                        federation peers are notified.
                    </p>
                </li>
            </ol>
        </Card>

        <!-- ==================================== in-flight move =========== -->
        <Card v-if="newClaim" as="section" title="Move in progress">
            <p class="cc-small">
                New claim: <strong>{{ newClaim.jurisdiction }}</strong>
                <StatusBadge tone="info" style="margin-inline-start: var(--space-1)">{{ newClaim.status }}</StatusBadge>
            </p>
            <ThresholdMeter
                :value="newClaim.qualifying_days"
                :max="newClaim.threshold_days"
                :threshold="newClaim.threshold_days"
                label="New claim qualifying days — CLK-05"
                style="margin-block-start: var(--space-2)"
            >
                {{ newClaim.qualifying_days }} of {{ newClaim.threshold_days }} qualifying days in {{ newClaim.jurisdiction }}
                <template #note>the constitutional grace IS this threshold · CLK-05</template>
            </ThresholdMeter>
            <p v-if="homeClaim" class="gloss" style="margin-block-start: var(--space-2)">
                {{ homeClaim.jurisdiction.name }} stays <strong>Active</strong> (Superseded-pending)
                until this claim verifies — the hand-over is atomic at verification.
            </p>
        </Card>

        <!-- ==================================== held offices ============= -->
        <Card v-if="heldOffices.length" as="section" title="Held offices and the grace period">
            <p class="cc-small">
                You hold an office tied to a jurisdiction. It is not dropped the instant you move —
                the grace period lets the institution prepare while you remain accountable.
            </p>
            <Card v-for="(office, oi) in heldOffices" :key="oi" inset>
                <div class="cluster" style="justify-content: space-between">
                    <div>
                        <strong>{{ office.label }}</strong>
                        <span class="citation" style="display: block">R-09 seated representative</span>
                    </div>
                    <StatusBadge :tone="office.grace ? 'warning' : 'neutral'" icon="clock">
                        {{ office.grace ? 'Grace period running' : 'No move in flight — nothing changes' }}
                    </StatusBadge>
                </div>
                <template v-if="office.grace">
                    <ThresholdMeter
                        :value="office.grace.day"
                        :max="office.grace.of"
                        :threshold="office.grace.of"
                        label="Grace period — the new claim's CLK-05 threshold"
                        style="margin-block-start: var(--space-3)"
                    >
                        day {{ office.grace.day }} of {{ office.grace.of }} — grace ends if the move completes
                        <template #note>seat vacates → {{ office.vacates_into }} · Art. II §5</template>
                    </ThresholdMeter>
                </template>
                <p class="cc-small" style="margin-block-start: var(--space-3)">
                    If re-association completes, the seat is declared vacant (F-LEG-036, system-filed)
                    and fills by countback (WF-ELE-03) — prior ballots re-run with the vacated member
                    removed. If you stay, nothing changes.
                </p>
                <p class="citation">Vacancy → countback → special election fallback (90–180 d · CLK-04) · Art. II §5 · Art. V §1–2</p>
            </Card>
        </Card>

        <!-- ==================================== lifecycle ================ -->
        <Card as="section" title="Where you are in the residency lifecycle">
            <StateStrip :states="machine" :current="currentMachineState" aria-label="Residency claim state machine" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Your old residency claim becomes Superseded only when the new one verifies —
                <HardenedChip>there is never a gap in your rights</HardenedChip>
            </p>
            <p class="citation">Art. V §1–2 · CLK-05</p>
        </Card>

        <template #about>
            <p>
                No forms on this surface: the move path reuses Residency declaration (F-IND-003) on
                <Link :href="urls.residency">the residency screen</Link>; "I'm travelling" is an
                audited engine action, not a catalog form.
            </p>
        </template>
    </PageScaffold>
</template>
