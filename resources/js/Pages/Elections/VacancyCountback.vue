<script setup>
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
import { router, useForm, usePage } from '@inertiajs/vue3';
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

const props = defineProps({
    surface: { type: Object, required: true },
    vacancy: { type: Object, required: true },
    machine: { type: Array, default: () => [] },
    rerun: { type: Object, required: true },
    certification: { type: Object, default: null },
    specialElection: { type: Object, default: null },
    can: { type: Object, default: () => ({ certify: false, schedule: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
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
            ? ['removed from the count']
            : bar.elected
              ? ['reaches quota']
              : bar.exhausted
                ? ['no remaining preference']
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
        :title="`Vacancy countback — ${vacancy.office_label}${vacancy.seat_no != null ? `, seat ${vacancy.seat_no}` : ''}`"
    >
        <template #intro>
            {{ vacancy.member_name ?? 'The member' }}
            {{ vacancy.reason === 'resigned' || !vacancy.reason ? 'resigned' : `was ${vacancy.reason}` }}.
            No new election is needed yet: the countback engine re-runs the prior election's
            ballots with the vacated member removed as a candidate. The voters' original
            preferences decide the replacement — only if those ballots exhaust does a special
            election follow.
        </template>

        <p class="citation">Vacancies filled by countback of prior ballots · Art. II §5 · countback engine — hardened</p>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ============================================ trigger ========== -->
        <Card as="section" title="Trigger">
            <Card inset>
                <p style="margin-block-end: var(--space-1)">
                    <strong>Vacancy declaration</strong>
                    {{ ' ' }}
                    <FormChip form-id="F-LEG-036" />
                </p>
                <p class="cc-small" style="margin-block-end: var(--space-1)">
                    Declare a seat vacant due to death, resignation, removal, or incapacity.
                </p>
                <p class="citation">
                    available to R-09 Legislative Representative / R-10 Speaker · creates a vacancy
                    record → triggers countback · Art. II §5
                </p>
                <p class="citation">catalog alias: F-LEG-030 · workflows catalog (renumbering drift)</p>
            </Card>
            <p class="cc-small" style="margin-block-start: var(--space-3)">
                Declared by {{ vacancy.declared_by }} on {{ fmt(vacancy.declared_at) }} — shown in
                your timezone · stored as UTC.
            </p>
            <StateStrip :states="machine" :current="vacancy.status" aria-label="Vacancy state machine" />
        </Card>

        <!-- ============================================ the re-run ======= -->
        <Card as="section">
            <template #title>
                <h2>
                    The re-run
                    <StatusBadge v-if="winnerFound" tone="success" icon="check">
                        Winner found{{ rerun.winner ? ` — ${rerun.winner.name}` : '' }}
                    </StatusBadge>
                    <StatusBadge v-else-if="exhausted" tone="danger" icon="alert-triangle">
                        Countback failed — ballots exhausted
                    </StatusBadge>
                    <StatusBadge v-else tone="warning" icon="clock">Countback running</StatusBadge>
                </h2>
            </template>

            <p v-if="rerun.source" class="cc-small">
                Re-run source: the prior {{ rerun.source.election_label }} —
                {{ rerun.source.total_valid.toLocaleString() }} valid ballots,
                {{ rerun.source.seats }} {{ rerun.source.seats === 1 ? 'seat' : 'seats' }}, Droop quota
                <span class="citation" data-no-i18n>{{ rerun.source.quota_formula }}</span>.
                The engine strikes the vacated member and continues the count from the voters'
                next preferences. The re-run is universal — every prior ballot counts, with no
                faction filtering anywhere in the procedure.
            </p>

            <template v-if="bars.length && rerun.quota">
                <span class="visually-hidden">Droop quota {{ rerun.quota.toLocaleString() }}</span>
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
                    :quota-title="`Droop quota ${rerun.quota.toLocaleString()}`"
                />
            </template>
            <p v-else-if="running" class="gloss">
                The countback is re-running the stored ballots — this page refreshes itself
                until the record lands.
            </p>

            <p class="gloss" style="margin-block-start: var(--space-2)">
                Gold tick = the Droop quota. A continuing candidate who reaches it fills the
                seat; ballots with no remaining preference become exhausted.
            </p>
            <p class="citation">
                Re-run of prior ballots with the vacated member removed · Art. II §5 ·
                universal — no faction filtering · STV with Droop quota — hardened
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
                    Branch: winner found
                    <StatusBadge v-if="winnerFound" tone="success" icon="check">active branch</StatusBadge>
                    <StatusBadge v-else-if="exhausted" tone="neutral">not taken</StatusBadge>
                    <StatusBadge v-else tone="neutral">pending</StatusBadge>
                </h2>
                <p class="cc-small">
                    The countback yields a replacement. The election board certifies, the winner
                    is seated by oath (F-LEG-001), and committee proportionality is re-checked.
                </p>
                <Card inset style="margin-block-end: var(--space-3)">
                    <p style="margin-block-end: var(--space-1)">
                        <strong>Election results certification</strong>
                        {{ ' ' }}
                        <FormChip form-id="F-ELB-004" />
                    </p>
                    <p class="citation">available to R-08 Election Board Member · Art. II §2 (transparent election process)</p>
                </Card>
                <div class="cluster">
                    <template v-if="certification">
                        <StatusBadge tone="success" icon="check">
                            {{ certification.winner_name }} certified · seated via oath F-LEG-001
                        </StatusBadge>
                        <span class="citation">
                            F-ELB-004 committed {{ fmt(certification.certified_at) }} ·
                            proportionality re-check queued (WF-LEG-13)
                            <span class="planned-flag">Planned · Phase C</span>
                        </span>
                    </template>
                    <template v-else-if="can.certify">
                        <Btn variant="primary" size="sm" :disabled="certifying" @click="certify">
                            Certify countback winner
                        </Btn>
                        <span v-if="rerun.winner" class="citation">certifies {{ rerun.winner.name }}</span>
                    </template>
                    <Btn
                        v-else
                        variant="secondary"
                        size="sm"
                        disabled
                        :title="exhausted ? 'No winner in this countback' : 'Certification follows the countback automatically'"
                    >Certify countback winner</Btn>
                </div>
                <p class="citation" style="margin-block-start: var(--space-3)">
                    Committee proportionality re-checked after seating · WF-LEG-13
                </p>
            </section>

            <section
                class="card"
                :style="{ opacity: winnerFound || running ? '.65' : '1' }"
                aria-labelledby="failed-h"
            >
                <h2 id="failed-h">
                    Branch: ballots exhausted
                    <StatusBadge v-if="exhausted" tone="danger" icon="alert-triangle">active branch</StatusBadge>
                    <StatusBadge v-else-if="winnerFound" tone="neutral">not taken</StatusBadge>
                    <StatusBadge v-else tone="neutral">pending</StatusBadge>
                </h2>
                <p class="cc-small">
                    No continuing candidate can reach the quota — the countback fails and a
                    special election must be held no sooner than
                    {{ vacancy.window?.min_days ?? 90 }} and no later than
                    {{ vacancy.window?.max_days ?? 180 }} days after the vacancy.
                </p>
                <p class="citation">
                    Special election window · {{ vacancy.window?.min_days ?? 90 }}–{{ vacancy.window?.max_days ?? 180 }}
                    days · CLK-04 · Art. II §5
                </p>

                <Banner v-if="specialElection" tone="info" role="status">
                    Special election scheduled — ranked window opens
                    {{ specialElection.scheduled_for }} (status: {{ specialElection.status }}).
                    The order below refines dates within the window.
                </Banner>

                <FormCard
                    v-if="can.schedule && formMeta('F-ELB-001')"
                    :form="formMeta('F-ELB-001')"
                    :inertia-form="specialForm"
                    submit-label="Schedule special election"
                    processing-label="Scheduling…"
                    @submit="submitSpecial"
                >
                    <Field
                        label="Special election date (ranked window opens)"
                        :hint="`Window for this vacancy: ${vacancy.window?.opens_on} → ${vacancy.window?.closes_on} (latest start ${vacancy.window?.latest_start} — the ${vacancy.window?.ranked_window_days}-day ranked window must fit inside). The engine rejects dates outside the window.`"
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
                    Scheduling opens only if the countback exhausts.
                </p>

                <p class="citation" style="margin-block-start: var(--space-3)">
                    Opens WF-ELE-04 · forms F-ELB-001, F-IND-011, F-IND-007, F-ELB-004
                </p>
            </section>
        </div>

        <!-- ============================================ knock-on effects = -->
        <Card as="section" title="Knock-on effects">
            <div class="stack" style="gap: var(--space-3)">
                <Card inset>
                    <strong>Committee proportionality re-check pending</strong>
                    <span class="citation" style="display: block">
                        re-check runs when the replacement is seated · WF-LEG-13 · Art. II §4
                        <span class="planned-flag">Planned · Phase C</span>
                    </span>
                </Card>
                <Card inset>
                    <strong>Term inheritance (CLK-10)</strong>
                    <span class="citation" style="display: block">
                        a replacement term never outlives the term it fills — the inherited
                        ends_on is written once and never moves · Art. II §5
                    </span>
                </Card>
            </div>
        </Card>

        <template #about>
            <p>
                <strong>Entity state machine:</strong> Vacancy —
                {{ machine.join(' → ') }}. The strip above tracks the live record.
            </p>
            <p>
                Phase B vacancies are dev-seeded (<code data-no-i18n>php artisan vacancy:declare</code>);
                the F-LEG-036 declaration form itself arrives with Phase C Speaker tooling.
            </p>
        </template>
    </PageScaffold>
</template>
