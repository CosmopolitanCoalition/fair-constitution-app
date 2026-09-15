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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

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

/* Plain labels for the residency lifecycle strip — the raw tokens are machine
   grammar ("ping_monitoring"); the player chrome speaks plainly (S8). */
const CLAIM_LABELS = {
    declared: t('c_civic.relocation.label_declared', 'Declared'),
    ping_monitoring: t('c_civic.relocation.label_ping_monitoring', 'Confirming by presence'),
    threshold_met: t('c_civic.relocation.label_threshold_met', 'Threshold met'),
    verified: t('c_civic.relocation.label_verified', 'Verified'),
    active: t('c_civic.relocation.label_active', 'Active — home'),
    superseded: t('c_civic.relocation.label_superseded', 'Superseded'),
};

const travellingForm = useForm({});
function declareTravelling() {
    travellingForm.post(props.urls.travelling, { preserveScroll: true });
}

const currentMachineState = computed(() => props.newClaim?.status ?? props.homeClaim?.status ?? null);
</script>

<template>
    <PageScaffold :surface="surface" :title="detection ? t('c_civic.relocation.title_moved', 'It looks like you may have moved') : t('c_civic.relocation.title_default', 'Relocation')">
        <template #intro>
            {{ t('c_civic.relocation.intro', 'Your residency follows where you actually live. When a sustained presence pattern forms outside your home jurisdiction, the system asks — it never reassigns you silently, and it never penalizes travel.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- The zero-rights-gap promise — hardened, renders always. -->
        <Banner tone="info" icon="lock" :title="t('c_civic.relocation.zero_gap_title', 'Your old claim stays Active until the new one Verifies — no rights gap, ever.')">
            {{ t('c_civic.relocation.zero_gap_body', 'Voting, candidacy, and every association hold through the whole move') }}
            <span class="citation" data-no-i18n> · Art. I · Art. V §1–2 · hardened</span>
        </Banner>

        <!-- ==================================== detection ================ -->
        <Card as="section" :title="t('c_civic.relocation.detection_title', 'Away-pattern detection')">
            <template v-if="detection">
                <Banner tone="warning" icon="map-pin" :title="t('c_civic.relocation.away_banner_title', { days: detection.away_days, near: detection.detected_near?.label ?? t('c_civic.relocation.another_jurisdiction', 'another jurisdiction'), home: homeClaim?.jurisdiction?.name ?? t('c_civic.relocation.your_home', 'your home jurisdiction') })">
                    {{ t('c_civic.relocation.away_banner_body', 'Detection uses the same encrypted ping log as verification; only day-counts are visible.') }} <span class="citation" data-no-i18n>CLK-05 · residency_confirmation_days · Art. V §1</span>
                </Banner>
                <ThresholdMeter
                    :value="detection.away_days"
                    :max="detection.threshold_days"
                    :threshold="detection.threshold_days"
                    :label="t('c_civic.relocation.away_meter_label', 'Qualifying days away — CLK-05 threshold')"
                    style="margin-block-start: var(--space-3)"
                >
                    {{ t('c_civic.relocation.away_meter', { days: detection.away_days, threshold: detection.threshold_days, near: detection.detected_near?.label ?? '—' }) }}
                    <template #note>{{ t('c_civic.relocation.threshold_note', 'threshold · CLK-05') }}</template>
                </ThresholdMeter>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    {{ t('c_civic.relocation.away_note', 'A move only completes when the away-pattern reaches the full residency threshold — the same standard your home verification used.') }}
                </p>
            </template>
            <template v-else>
                <div class="cluster">
                    <StatusBadge tone="neutral" icon="map-pin">{{ t('c_civic.relocation.detect_not_built', 'Away-pattern detection isn’t built yet') }}</StatusBadge>
                </div>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    {{ t('c_civic.relocation.detect_empty_a', 'This card will light up when sustained pings appear outside your declared jurisdiction — but nothing is watching for that yet: automatic away-detection needs the mobile geofenced pinging that arrives in') }}
                    <strong>{{ t('c_civic.relocation.phase_6', 'Phase 6') }}</strong>{{ t('c_civic.relocation.detect_empty_b', '. Until then, if you move, you tell us yourself below. When it does exist it will read the same encrypted ping log verification uses — only day-counts ever visible, pings always pausable.') }}
                </p>
                <p v-if="homeClaim" class="cc-small" style="margin-block-start: var(--space-2)">
                    {{ t('c_civic.relocation.home_residency', 'Home residency:') }} <strong>{{ homeClaim.jurisdiction.name }}</strong>
                    <StatusBadge tone="success" style="margin-inline-start: var(--space-1)">{{ homeClaim.status }}</StatusBadge>
                    <span class="citation"> {{ t('c_civic.relocation.declared_at', { when: homeClaim.declared_at }) }}</span>
                </p>
            </template>
        </Card>

        <!-- ==================================== travel or move =========== -->
        <Card as="section" :title="t('c_civic.relocation.travel_or_move', 'Travel, or a move?')">
            <p class="cc-small">
                {{ t('c_civic.relocation.travel_or_move_body', 'Tell the system which this is. Either answer is final only when you say so — and a move still requires the full threshold pattern before anything transfers.') }}
            </p>
            <div class="cluster" role="group" :aria-label="t('c_civic.relocation.travel_or_move_aria', 'Travel or move')">
                <Btn
                    variant="secondary"
                    :disabled="travellingForm.processing"
                    @click="declareTravelling"
                >{{ t('c_civic.relocation.im_travelling', "I'm travelling — keep my residency") }}</Btn>
                <Btn as="a" :href="urls.residency" variant="secondary">{{ t('c_civic.relocation.im_moving', "I'm moving — start re-association") }}</Btn>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                <template v-if="detection">
                    {{ t('c_civic.relocation.travelling_detection', 'Travelling: detection resets, nothing changes — pings pausable in personal settings; the declaration is audit-chained (WF-CIV-03).') }}
                </template>
                <template v-else>
                    {{ t('c_civic.relocation.travelling_none', 'Travelling: no away-pattern has been detected — nothing needs resetting — so this is a standing declaration that a future trip is travel, not a move. It is recorded on the audit chain (WF-CIV-03) and changes nothing about your residency or your rights.') }}
                </template>
                {{ t('c_civic.relocation.moving_note', 'Moving: a new residency declaration (F-IND-003 on the residency screen) alongside your active claim IS the move — no new form exists here, by design.') }}
            </p>

            <ol class="flow-steps" style="margin-block-start: var(--space-3)">
                <li class="flow-step" :class="{ 'flow-step--current': newClaim !== null }">
                    <div class="flow-step-head">
                        <span class="flow-step-n">1</span><span class="flow-actor">{{ t('c_civic.relocation.actor_you', 'You') }}</span>
                        <span class="flow-action">{{ t('c_civic.relocation.step1_action', 'Declare residency in the new jurisdiction') }}</span>
                    </div>
                    <p class="flow-outcome">
                        {{ t('c_civic.relocation.step1_outcome_a', 'Residency declaration · F-IND-003 on') }} <Link :href="urls.residency">{{ t('c_civic.relocation.residency_screen', 'the residency screen') }}</Link>{{ t('c_civic.relocation.step1_outcome_b', '; ping monitoring restarts there.') }}
                    </p>
                </li>
                <li class="flow-step">
                    <div class="flow-step-head">
                        <span class="flow-step-n">2</span><span class="flow-actor">{{ t('c_civic.relocation.actor_system', 'System') }}</span>
                        <span class="flow-action">{{ t('c_civic.relocation.step2_action', 'Away-pattern accumulates to the threshold (CLK-05)') }}</span>
                    </div>
                    <p class="flow-outcome">{{ t('c_civic.relocation.step2_outcome', 'Old associations remain fully active until then — no gap in voting or candidacy.') }}</p>
                </li>
                <li class="flow-step">
                    <div class="flow-step-head">
                        <span class="flow-step-n">3</span><span class="flow-actor">{{ t('c_civic.relocation.actor_system', 'System') }}</span>
                        <span class="flow-action">{{ t('c_civic.relocation.step3_action', 'Associations transfer; held offices resolve via the grace period') }}</span>
                    </div>
                    <p class="flow-outcome">
                        {{ t('c_civic.relocation.step3_outcome', 'Old roles gracefully expire; a held seat vacates into countback (F-LEG-036 → WF-ELE-03); federation peers are notified.') }}
                    </p>
                </li>
            </ol>
        </Card>

        <!-- ==================================== in-flight move =========== -->
        <Card v-if="newClaim" as="section" :title="t('c_civic.relocation.move_in_progress', 'Move in progress')">
            <p class="cc-small">
                {{ t('c_civic.relocation.new_claim', 'New claim:') }} <strong>{{ newClaim.jurisdiction }}</strong>
                <StatusBadge tone="info" style="margin-inline-start: var(--space-1)">{{ newClaim.status }}</StatusBadge>
            </p>
            <ThresholdMeter
                :value="newClaim.qualifying_days"
                :max="newClaim.threshold_days"
                :threshold="newClaim.threshold_days"
                :label="t('c_civic.relocation.new_claim_meter_label', 'New claim qualifying days — CLK-05')"
                style="margin-block-start: var(--space-2)"
            >
                {{ t('c_civic.relocation.new_claim_meter', { days: newClaim.qualifying_days, threshold: newClaim.threshold_days, place: newClaim.jurisdiction }) }}
                <template #note>{{ t('c_civic.relocation.new_claim_note', 'the constitutional grace IS this threshold · CLK-05') }}</template>
            </ThresholdMeter>
            <p v-if="homeClaim" class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_civic.relocation.stays_active_a', { name: homeClaim.jurisdiction.name }) }} <strong>{{ t('c_civic.relocation.active', 'Active') }}</strong> {{ t('c_civic.relocation.stays_active_b', '(Superseded-pending) until this claim verifies — the hand-over is atomic at verification.') }}
            </p>
        </Card>

        <!-- ==================================== held offices ============= -->
        <Card as="section" :title="t('c_civic.relocation.held_offices_title', 'Held offices and the grace period')">
            <p class="cc-small">
                {{ t('c_civic.relocation.held_offices_body', 'An office tied to a jurisdiction is not dropped the instant you move — the grace period lets the institution prepare while you remain accountable.') }}
            </p>
            <div v-if="!heldOffices.length" class="cluster" style="margin-block-start: var(--space-3)">
                <StatusBadge tone="neutral" icon="check">
                    {{ t('c_civic.relocation.no_office', 'You hold no office tied to your home jurisdiction — nothing to hand over') }}
                </StatusBadge>
            </div>
            <Card v-for="(office, oi) in heldOffices" :key="oi" inset>
                <div class="cluster" style="justify-content: space-between">
                    <div>
                        <strong>{{ office.label }}</strong>
                        <span class="citation" style="display: block">{{ t('c_civic.relocation.r09_rep', 'R-09 seated representative') }}</span>
                    </div>
                    <StatusBadge :tone="office.grace ? 'warning' : 'neutral'" icon="clock">
                        {{ office.grace ? t('c_civic.relocation.grace_running', 'Grace period running') : t('c_civic.relocation.no_move', 'No move in flight — nothing changes') }}
                    </StatusBadge>
                </div>
                <template v-if="office.grace">
                    <ThresholdMeter
                        :value="office.grace.day"
                        :max="office.grace.of"
                        :threshold="office.grace.of"
                        :label="t('c_civic.relocation.grace_meter_label', 'Grace period — the new claim\'s CLK-05 threshold')"
                        style="margin-block-start: var(--space-3)"
                    >
                        {{ t('c_civic.relocation.grace_meter', { day: office.grace.day, of: office.grace.of }) }}
                        <template #note>{{ t('c_civic.relocation.seat_vacates', { into: office.vacates_into }) }}</template>
                    </ThresholdMeter>
                </template>
                <p class="cc-small" style="margin-block-start: var(--space-3)">
                    {{ t('c_civic.relocation.reassoc_note', 'If re-association completes, the seat is declared vacant (F-LEG-036, system-filed) and fills by countback (WF-ELE-03) — prior ballots re-run with the vacated member removed. If you stay, nothing changes.') }}
                </p>
                <p class="citation">{{ t('c_civic.relocation.vacancy_cite', 'Vacancy → countback → special election fallback (90–180 d · CLK-04) · Art. II §5 · Art. V §1–2') }}</p>
            </Card>
        </Card>

        <!-- ==================================== lifecycle ================ -->
        <Card as="section" :title="t('c_civic.relocation.lifecycle_title', 'Where you are in the residency lifecycle')">
            <StateStrip :states="machine" :current="currentMachineState" :labels="CLAIM_LABELS" :aria-label="t('c_civic.relocation.lifecycle_aria', 'Residency claim state machine')" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_civic.relocation.lifecycle_body', 'Your old residency claim becomes Superseded only when the new one verifies —') }}
                <HardenedChip>{{ t('c_civic.relocation.never_gap', 'there is never a gap in your rights') }}</HardenedChip>
            </p>
            <p class="citation" data-no-i18n>Art. V §1–2 · CLK-05</p>
        </Card>

        <template #about>
            <p>
                {{ t('c_civic.relocation.about_a', 'No forms on this surface: the move path reuses Residency declaration (F-IND-003) on') }} <Link :href="urls.residency">{{ t('c_civic.relocation.residency_screen', 'the residency screen') }}</Link>{{ t('c_civic.relocation.about_b', '; "I\'m travelling" is an audited engine action, not a catalog form.') }}
            </p>
        </template>
    </PageScaffold>
</template>
