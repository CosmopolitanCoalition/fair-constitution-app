<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Elections/VacancyCountback — FE-B8 (PHASE_B_DESIGN_frontend.md §B.8).
 *
 * Trigger card (F-LEG-036 inset record with the catalog-alias citation) ·
 * re-run card (StvBar list: struck member removed, winner reaches quota,
 * exhausted ballots) · branch cards driven by rerun.outcome (real data,
 * not a scenario toggle) — winner-found certifies (F-ELB-004), exhausted
 * schedules the special election (F-ELB-001) with a window-bounded date
 * Field whose min/max are UX only: the ENGINE rejects out-of-window dates
 * with the Art. II §5 / CLK-04 citation and the page surfaces that 422 as
 * the Field error.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import StvBar from '@/Components/Electoral/StvBar.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    vacancy: { type: Object, required: true },
    machine: { type: Array, default: () => [] },
    rerun: { type: Object, required: true },
    certification: { type: Object, default: null },
    specialElection: { type: Object, default: null },
    can: { type: Object, default: () => ({ certify: false, schedule: false }) },
});

const { t } = useI18n();
const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

function fmt(iso) {
    return iso ? localeFmt.dateTime(new Date(iso)) : '—';
}

const running = computed(() => props.rerun.outcome === 'running');
const winnerFound = computed(() => props.rerun.outcome === 'winner');
const exhausted = computed(() => props.rerun.outcome === 'exhausted');

/* Countback-running → poll the live record (§B.8 edge). */
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (props.vacancy.status === 'countback_running') {
            router.reload({ only: ['vacancy', 'rerun', 'certification', 'specialElection', 'can'] });
        }
    }, 5000);
});
onBeforeUnmount(() => clearInterval(pollTimer));

/* StvBar rows from the presenter's countback bars. */
const bars = computed(() =>
    (props.rerun.bars ?? []).map((bar) => ({
        ...bar,
        chips: bar.removed
            ? [t('c_elections.countback.chip_removed', 'removed from the count')]
            : bar.elected
              ? [t('c_elections.countback.chip_reaches', 'reaches quota')]
              : bar.exhausted
                ? [t('c_elections.countback.chip_no_pref', 'no remaining preference')]
                : [],
    })),
);

/* --------------------------------------------- winner branch (certify) - */

const certifying = ref(false);
function certify() {
    certifying.value = true;
    router.post(`/vacancies/${props.vacancy.id}/certify`, {}, {
        preserveScroll: true,
        onFinish: () => {
            certifying.value = false;
        },
    });
}

/* ------------------------------------------- exhausted branch (special) */

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const specialForm = useForm({
    scheduled_for: props.specialElection?.scheduled_for ?? props.vacancy.window?.opens_on ?? '',
});

function submitSpecial() {
    specialForm.post(`/vacancies/${props.vacancy.id}/special-election`, { preserveScroll: true });
}

/* The engine 422 arrives as errors.constitution — surfaced ON the Field
   (the page's signature moment, §B.8). */
const dateError = computed(
    () => specialForm.errors.scheduled_for ?? specialForm.errors.constitution ?? null,
);
</script>

<template>
    <PageScaffold
        :surface="surface"
        :title="vacancy.seat_no != null
            ? t('c_elections.countback.title_seat', 'Vacancy countback — {office}, seat {no}', { named: { office: vacancy.office_label, no: vacancy.seat_no } })
            : t('c_elections.countback.title', 'Vacancy countback — {office}', { named: { office: vacancy.office_label } })"
    >
        <template #intro>
            {{ vacancy.member_name ?? t('c_elections.countback.the_member', 'The member') }}
            {{ vacancy.reason === 'resigned' || !vacancy.reason ? t('c_elections.countback.resigned', 'resigned') : t('c_elections.countback.was_reason', 'was {reason}', { named: { reason: vacancy.reason } }) }}.
            {{ t('c_elections.countback.intro', 'No new election is needed yet. The game re-runs the prior election ballots with the vacated member removed as a candidate (a vacancy countback). The voters\' original preferences decide the replacement. Only if those ballots run out does a special election follow.') }}
        </template>

        <p class="citation">{{ t('c_elections.countback.cite_method', 'Vacancies filled by countback of prior ballots · Art. II §5 · countback engine — hardened') }}</p>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ============================================ trigger ========== -->
        <Card as="section" :title="t('c_elections.countback.trigger_title', 'Trigger')">
            <Card inset>
                <p style="margin-block-end: var(--space-1)">
                    <strong>{{ t('c_elections.countback.vacancy_declaration', 'Vacancy declaration') }}</strong>
                    {{ ' ' }}
                    <FormChip form-id="F-LEG-036" />
                </p>
                <p class="cc-small" style="margin-block-end: var(--space-1)">
                    {{ t('c_elections.countback.declare_body', 'Declare a seat vacant due to death, resignation, removal, or incapacity.') }}
                </p>
                <p class="citation">
                    {{ t('c_elections.countback.declare_cite', 'available to R-09 Legislative Representative / R-10 Speaker · creates a vacancy record → triggers countback · Art. II §5') }}
                </p>
                <p class="citation">{{ t('c_elections.countback.catalog_alias', 'catalog alias: F-LEG-030 · workflows catalog (renumbering drift)') }}</p>
            </Card>
            <p class="cc-small" style="margin-block-start: var(--space-3)">
                {{ t('c_elections.countback.declared_by', 'Declared by {who} on {when} — shown in your timezone, stored as UTC.', { named: { who: vacancy.declared_by, when: fmt(vacancy.declared_at) } }) }}
            </p>
            <StateStrip :states="machine" :current="vacancy.status" :aria-label="t('c_elections.countback.vacancy_machine', 'Vacancy state machine')" />
        </Card>

        <!-- ============================================ the re-run ======= -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ t('c_elections.countback.rerun_title', 'The re-run') }}
                    <StatusBadge v-if="winnerFound" tone="success" icon="check">
                        {{ rerun.winner ? t('c_elections.countback.winner_found_named', 'Winner found — {name}', { named: { name: rerun.winner.name } }) : t('c_elections.countback.winner_found', 'Winner found') }}
                    </StatusBadge>
                    <StatusBadge v-else-if="exhausted" tone="danger" icon="alert-triangle">
                        {{ t('c_elections.countback.failed_exhausted', 'Countback failed — ballots exhausted') }}
                    </StatusBadge>
                    <StatusBadge v-else tone="warning" icon="clock">{{ t('c_elections.countback.running', 'Countback running') }}</StatusBadge>
                </h2>
            </template>

            <p v-if="rerun.source" class="cc-small">
                {{ t('c_elections.countback.rerun_source_lead', 'Re-run source: the prior {label} — {total} valid ballots, {seats} {seatWord}, Droop quota', { named: { label: rerun.source.election_label, total: localeFmt.number(rerun.source.total_valid), seats: rerun.source.seats, seatWord: rerun.source.seats === 1 ? t('c_elections.countback.seat_one', 'seat') : t('c_elections.countback.seat_other', 'seats') } }) }}
                <span class="citation" data-no-i18n>{{ rerun.source.quota_formula }}</span>.
                {{ t('c_elections.countback.rerun_source_body', 'The engine strikes the vacated member and continues the count from the voters\' next preferences. The re-run is universal — every prior ballot counts, with no faction filtering anywhere in the procedure.') }}
            </p>

            <template v-if="bars.length && rerun.quota">
                <span class="visually-hidden">{{ t('c_elections.countback.droop_quota_sr', 'Droop quota {n}', { named: { n: localeFmt.number(rerun.quota) } }) }}</span>
                <StvBar
                    v-for="bar in bars"
                    :key="bar.candidacy_id ?? bar.name"
                    :name="bar.name"
                    :votes="bar.votes"
                    :quota="rerun.quota"
                    :scale="rerun.scale"
                    :elected="bar.elected"
                    :eliminated="bar.removed"
                    :transfer-fill="bar.exhausted"
                    :write-in="bar.write_in"
                    :chips="bar.chips"
                    :quota-title="t('c_elections.countback.droop_quota_sr', 'Droop quota {n}', { named: { n: localeFmt.number(rerun.quota) } })"
                />
            </template>
            <p v-else-if="running" class="gloss">
                {{ t('c_elections.countback.running_body', 'The countback is re-running the stored ballots. This page refreshes itself until the record lands.') }}
            </p>

            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_elections.countback.gold_tick', 'Gold tick = the Droop quota. A continuing candidate who reaches it fills the seat. Ballots with no remaining preference become exhausted.') }}
            </p>
            <p class="citation">
                {{ t('c_elections.countback.rerun_cite', 'Re-run of prior ballots with the vacated member removed · Art. II §5 · universal — no faction filtering · STV with Droop quota — hardened') }}
            </p>
        </Card>

        <!-- ============================================ branches ========= -->
        <div class="grid-2">
            <section
                class="card"
                :style="{ opacity: exhausted ? '.65' : '1' }"
                aria-labelledby="found-h"
            >
                <h2 id="found-h">
                    {{ t('c_elections.countback.branch_found', 'Branch: winner found') }}
                    <StatusBadge v-if="winnerFound" tone="success" icon="check">{{ t('c_elections.countback.active_branch', 'active branch') }}</StatusBadge>
                    <StatusBadge v-else-if="exhausted" tone="neutral">{{ t('c_elections.countback.not_taken', 'not taken') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral">{{ t('c_elections.countback.pending', 'pending') }}</StatusBadge>
                </h2>
                <p class="cc-small">
                    {{ t('c_elections.countback.found_body', 'The countback yields a replacement. The election board certifies, the winner is seated by oath (F-LEG-001), and committee proportionality is re-checked.') }}
                </p>
                <Card inset style="margin-block-end: var(--space-3)">
                    <p style="margin-block-end: var(--space-1)">
                        <strong>{{ t('c_elections.countback.cert_form', 'Election results certification') }}</strong>
                        {{ ' ' }}
                        <FormChip form-id="F-ELB-004" />
                    </p>
                    <p class="citation">{{ t('c_elections.countback.cert_cite', 'available to R-08 Election Board Member · Art. II §2 (transparent election process)') }}</p>
                </Card>
                <div class="cluster">
                    <template v-if="certification">
                        <StatusBadge tone="success" icon="check">
                            {{ t('c_elections.countback.certified_badge', '{name} certified · seated via oath F-LEG-001', { named: { name: certification.winner_name } }) }}
                        </StatusBadge>
                        <span class="citation">
                            {{ t('c_elections.countback.committed_at', 'F-ELB-004 committed {when} · proportionality re-check queued (WF-LEG-13)', { named: { when: fmt(certification.certified_at) } }) }}
                            <span class="planned-flag">{{ t('c_elections.countback.planned_c', 'Planned · Phase C') }}</span>
                        </span>
                    </template>
                    <template v-else-if="can.certify">
                        <Btn variant="primary" size="sm" :disabled="certifying" @click="certify">
                            {{ t('c_elections.countback.certify_btn', 'Certify countback winner') }}
                        </Btn>
                        <span v-if="rerun.winner" class="citation">{{ t('c_elections.countback.certifies', 'certifies {name}', { named: { name: rerun.winner.name } }) }}</span>
                    </template>
                    <Btn
                        v-else
                        variant="secondary"
                        size="sm"
                        disabled
                        :title="exhausted ? t('c_elections.countback.no_winner', 'No winner in this countback') : t('c_elections.countback.cert_auto', 'Certification follows the countback automatically')"
                    >{{ t('c_elections.countback.certify_btn', 'Certify countback winner') }}</Btn>
                </div>
                <p class="citation" style="margin-block-start: var(--space-3)">
                    {{ t('c_elections.countback.prop_recheck', 'Committee proportionality re-checked after seating · WF-LEG-13') }}
                </p>
            </section>

            <section
                class="card"
                :style="{ opacity: winnerFound || running ? '.65' : '1' }"
                aria-labelledby="failed-h"
            >
                <h2 id="failed-h">
                    {{ t('c_elections.countback.branch_exhausted', 'Branch: ballots exhausted') }}
                    <StatusBadge v-if="exhausted" tone="danger" icon="alert-triangle">{{ t('c_elections.countback.active_branch', 'active branch') }}</StatusBadge>
                    <StatusBadge v-else-if="winnerFound" tone="neutral">{{ t('c_elections.countback.not_taken', 'not taken') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral">{{ t('c_elections.countback.pending', 'pending') }}</StatusBadge>
                </h2>
                <p class="cc-small">
                    {{ t('c_elections.countback.exhausted_body', 'No continuing candidate can reach the quota. The countback fails and a special election must be held no sooner than {min} and no later than {max} days after the vacancy.', { named: { min: vacancy.window?.min_days ?? 90, max: vacancy.window?.max_days ?? 180 } }) }}
                </p>
                <p class="citation">
                    {{ t('c_elections.countback.special_window', 'Special election window · {min}–{max} days · CLK-04 · Art. II §5', { named: { min: vacancy.window?.min_days ?? 90, max: vacancy.window?.max_days ?? 180 } }) }}
                </p>

                <Banner v-if="specialElection" tone="info" role="status">
                    {{ t('c_elections.countback.special_scheduled', 'Special election scheduled — ranked window opens {when} (status: {status}). The order below refines dates within the window.', { named: { when: specialElection.scheduled_for, status: specialElection.status } }) }}
                </Banner>

                <FormCard
                    v-if="can.schedule && formMeta('F-ELB-001')"
                    :form="formMeta('F-ELB-001')"
                    :inertia-form="specialForm"
                    :submit-label="t('c_elections.countback.schedule_special', 'Schedule special election')"
                    :processing-label="t('c_elections.countback.scheduling', 'Scheduling…')"
                    @submit="submitSpecial"
                >
                    <Field
                        :label="t('c_elections.countback.special_date_label', 'Special election date (ranked window opens)')"
                        :hint="t('c_elections.countback.special_date_hint', 'Window for this vacancy: {opens} to {closes} (latest start {latest} — the {days}-day ranked window must fit inside). The engine rejects dates outside the window.', { named: { opens: vacancy.window?.opens_on, closes: vacancy.window?.closes_on, latest: vacancy.window?.latest_start, days: vacancy.window?.ranked_window_days } })"
                        :error="dateError"
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="specialForm.scheduled_for"
                                class="field-input"
                                type="date"
                                :min="vacancy.window?.opens_on"
                                :max="vacancy.window?.latest_start"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                </FormCard>
                <p v-else-if="!exhausted" class="gloss">
                    {{ t('c_elections.countback.scheduling_gated', 'Scheduling opens only if the countback exhausts.') }}
                </p>

                <p class="citation" style="margin-block-start: var(--space-3)">
                    {{ t('c_elections.countback.opens_forms', 'Opens WF-ELE-04 · forms F-ELB-001, F-IND-011, F-IND-007, F-ELB-004') }}
                </p>
            </section>
        </div>

        <!-- ============================================ knock-on effects = -->
        <Card as="section" :title="t('c_elections.countback.knockon_title', 'Knock-on effects')">
            <div class="stack" style="gap: var(--space-3)">
                <Card inset>
                    <strong>{{ t('c_elections.countback.prop_pending', 'Committee proportionality re-check pending') }}</strong>
                    <span class="citation" style="display: block">
                        {{ t('c_elections.countback.prop_pending_cite', 're-check runs when the replacement is seated · WF-LEG-13 · Art. II §4') }}
                        <span class="planned-flag">{{ t('c_elections.countback.planned_c', 'Planned · Phase C') }}</span>
                    </span>
                </Card>
                <Card inset>
                    <strong>{{ t('c_elections.countback.term_inherit', 'Term inheritance (CLK-10)') }}</strong>
                    <span class="citation" style="display: block">
                        {{ t('c_elections.countback.term_inherit_cite', 'a replacement term never outlives the term it fills. The inherited ends_on is written once and never moves · Art. II §5') }}
                    </span>
                </Card>
            </div>
        </Card>

        <template #about>
            <p>
                {{ t('c_elections.countback.about_machine', 'Entity state machine: Vacancy. The strip above tracks the live record.') }}
            </p>
            <p>
                {{ t('c_elections.countback.about_devseed', 'Phase B vacancies are dev-seeded (php artisan vacancy:declare). The F-LEG-036 declaration form itself arrives with Phase C Speaker tooling.') }}
            </p>
        </template>
    </PageScaffold>
</template>
