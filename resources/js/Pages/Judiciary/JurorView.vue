<script setup>
/**
 * Judiciary/JurorView — FE-E6 (PHASE_E_DESIGN_frontend.md §B.6; surface
 * judiciary/juror-view; mockups/judiciary/juror-view.html).
 *
 * The summoned juror's surface: the 6-step service Stepper · the summons
 * facts (drawn / pool / draw-integrity → audit chain / report / where + the
 * F-JDG-002 "Source of this summons" reference card + the case-detail
 * cross-link) · the Judiciary/JurorScreening voir-dire questionnaire
 * (RadioGroup per question, No/Yes default No, result Banner branch) · the
 * two Art. II §8 protections (HardenedChip) · the locked deliberation room.
 *
 * CONSTITUTIONAL POSTURE (the defining Phase E rule): the CASE this summons
 * belongs to is public record (Art. II §2) — reachable via the docket and
 * the cross-link. The one real per-record gate in the phase is the
 * SCREENING questionnaire: it binds to the R-22 holder of THIS summons
 * (`can.submitScreening`) — you cannot answer another juror's questionnaire.
 * The screening submit rides a THIN endpoint (the q-ledger deferral, §B.6:
 * juror answers are a record the court reads, not a constitutional
 * instrument) — the page POSTs /judiciary/jury/{summons}/screening; the
 * component never POSTs. The deliberation room `unlocked` flag and every
 * date are ENGINE SNAPSHOTS off the controller — never client-computed.
 *
 * Classes: all already ported (.stepper, .radio-group/.radio, .banner--*,
 * .hardened, .grid-2, .card/.card--inset, .form-chip, .citation, .eyebrow) —
 * zero new CSS (the §A.0 finding).
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import JurorScreening from '@/Components/Judiciary/JurorScreening.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.6 / A.4 summons block — jury_members joined to its jury + case. */
    summons: { type: Object, required: true },
    /** [{ id, text }] — the 5 server-authored conflict questions. */
    questions: { type: Array, required: true },
    /** jury_members.screening_status → the service-stepper position. */
    serviceState: {
        type: String,
        default: 'summoned',
        validator: (v) =>
            ['summoned', 'conflict_screening', 'empaneled', 'trial', 'deliberation', 'discharged'].includes(v),
    },
    /** Engine snapshot — the room unlocks only at the case's deliberation state. */
    deliberationRoom: { type: Object, default: () => ({ unlocked: false }) },
    /** Previously recorded answers (read-only render once screened). */
    recordedAnswers: { type: Object, default: null },
    /** null (unanswered) | 'flagged' | 'clean' — the juror's own self-report echo. */
    recordedOutcome: { type: String, default: null },
    /** { submitScreening } — R-22 of THIS summons only. */
    can: { type: Object, default: () => ({ submitScreening: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id) ?? null;

/* ---------------------------------------------------- service stepper ---- */
/* The 6-step jury-service track (juror-view.html: Summoned → Conflict
   screening → Empaneled → Trial → Deliberation → Discharged), positioned
   from the server `serviceState`. */
const STEPS = [
    { key: 'summoned', label: 'Summoned' },
    { key: 'conflict_screening', label: 'Conflict screening' },
    { key: 'empaneled', label: 'Empaneled' },
    { key: 'trial', label: 'Trial' },
    { key: 'deliberation', label: 'Deliberation' },
    { key: 'discharged', label: 'Discharged' },
];

const steps = computed(() => {
    const at = STEPS.findIndex((s) => s.key === props.serviceState);
    const idx = at === -1 ? 0 : at;
    return STEPS.map((s, i) => ({
        label: s.label,
        icon: i < idx ? 'check' : undefined,
        state: i < idx ? 'done' : i === idx ? 'active' : 'pending',
    }));
});

/* ---------------------------------------------------- screening submit --- */
/* The page owns the thin POST /judiciary/jury/{summons}/screening (the
   q-ledger deferral: juror answers are a record the court reads, not a
   constitutional instrument, so this does NOT route through the engine);
   JurorScreening emits { answers } and never POSTs itself. */
const screeningSubmitting = ref(false);

function submitScreening({ answers }) {
    if (!props.can.submitScreening) return;
    screeningSubmitting.value = true;
    router.post(`/judiciary/jury/${props.summons.id}/screening`, { answers }, {
        preserveScroll: true,
        onFinish: () => {
            screeningSubmitting.value = false;
        },
    });
}
</script>

<template>
    <PageScaffold :surface="surface">
        <div class="stack">
            <header>
                <span class="eyebrow" data-no-i18n>Jury service · WF-JUD-04</span>
                <h1>Juror summons — {{ summons.case.title }}</h1>
                <p class="page-intro">
                    You were drawn at random from the eligible pool of verified residents. Service is
                    a protected civic obligation — nobody can interfere with it, and nothing about it
                    can ever cost you a fee.
                </p>
                <p class="citation" data-no-i18n>
                    Jury of peers · Art. IV §4 — service protected · Art. II §8
                </p>
            </header>

            <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

            <!-- Service status — the 6-step stepper -->
            <Card as="section" title="Your service status">
                <Stepper :steps="steps" />
            </Card>

            <!-- The summons facts -->
            <Card as="section" title="The summons">
                <div class="grid-2">
                    <div class="stack" style="gap: var(--space-1)">
                        <p>
                            <strong style="color: var(--gov-fg)">Drawn:</strong>
                            <span class="citation" data-no-i18n>{{ summons.drawn_at ?? 'pending' }}</span>
                        </p>
                        <p>
                            <strong style="color: var(--gov-fg)">Pool:</strong>
                            {{ summons.pool_label }}
                        </p>
                        <p>
                            <strong style="color: var(--gov-fg)">Draw integrity:</strong>
                            the random-selection seed is published to the
                            <Link :href="summons.seed_audit_href">audit chain</Link> — anyone can verify the draw
                        </p>
                        <p>
                            <strong style="color: var(--gov-fg)">Report:</strong>
                            <span class="citation" data-no-i18n>{{ summons.report_at ?? 'to be set' }}</span>
                        </p>
                        <p>
                            <strong style="color: var(--gov-fg)">Where:</strong>
                            {{ summons.location }}
                        </p>
                        <p class="citation" data-no-i18n>
                            Random selection from the eligible pool · Art. IV §4 (jury of peers)
                        </p>
                    </div>

                    <!-- F-JDG-002 — the source of this summons (reference card, not interactive) -->
                    <Card v-if="formMeta('F-JDG-002')" inset eyebrow="Source of this summons">
                        <div
                            class="cluster"
                            style="justify-content: space-between; align-items: baseline; margin-block-start: var(--space-1)"
                        >
                            <strong style="color: var(--gov-fg)">{{ formMeta('F-JDG-002').name }}</strong>
                            <FormChip :form-id="formMeta('F-JDG-002').id" :alias="formMeta('F-JDG-002').alias" />
                        </div>
                        <p
                            v-if="formMeta('F-JDG-002').availableTo?.length || formMeta('F-JDG-002').citation"
                            class="citation"
                            style="margin-block-start: var(--space-2)"
                            data-no-i18n
                        >
                            <template v-if="formMeta('F-JDG-002').availableTo?.length"
                                >available to {{ formMeta('F-JDG-002').availableTo.join(', ') }}</template
                            >
                            <template v-if="formMeta('F-JDG-002').availableTo?.length && formMeta('F-JDG-002').citation">
                                ·
                            </template>
                            <template v-if="formMeta('F-JDG-002').citation">{{
                                formMeta('F-JDG-002').citation
                            }}</template>
                        </p>
                    </Card>
                </div>

                <p style="margin-block-start: var(--space-3)">
                    <Link :href="summons.case.href">
                        See the case this summons belongs to
                        <Icon name="arrow-right" size="sm" />
                    </Link>
                </p>
            </Card>

            <!-- The voir-dire conflict questionnaire (composes JurorScreening) -->
            <Card as="section" title="Conflict screening questionnaire">
                <JurorScreening
                    :summons="summons"
                    :questions="questions"
                    :answers="recordedAnswers"
                    :outcome="recordedOutcome"
                    :submitting="screeningSubmitting"
                    :can="can"
                    @submit="submitScreening"
                />
            </Card>

            <!-- Your service is protected — the two Art. II §8 shields -->
            <Card as="section" title="Your service is protected">
                <div class="cluster" style="margin-block-end: var(--space-3)">
                    <HardenedChip />
                </div>
                <ul>
                    <li>
                        <strong style="color: var(--gov-fg)">No interference.</strong> Your employer cannot
                        penalize, dismiss, or obstruct you for serving; nobody — public or private — may impede
                        a civic obligation.
                        <span class="citation" style="display: block" data-no-i18n
                            >Art. II §8 · Non-Interference with Civic Obligations</span
                        >
                    </li>
                    <li style="margin-block-start: var(--space-3)">
                        <strong style="color: var(--gov-fg)">No fees, ever.</strong> No payment, fee, or fine
                        can be required of you to exercise a civic right or fulfill this obligation — attendance,
                        filings, and verification cost you nothing.
                        <span class="citation" style="display: block" data-no-i18n
                            >Art. II §8 · Prohibition of Compulsory Payments for Civic Rights</span
                        >
                    </li>
                </ul>
                <p class="gloss">
                    Art. II §8 is the constitution's list of actions forbidden to legislatures — these two
                    subsections shield jurors from both interference and charges.
                </p>
            </Card>

            <!-- Jury deliberation room — locked until the deliberation state -->
            <Card as="section" title="Jury deliberation room">
                <div class="cluster" style="margin-block-end: var(--space-3)">
                    <StatusBadge
                        :tone="deliberationRoom.unlocked ? 'success' : 'neutral'"
                        :icon="deliberationRoom.unlocked ? 'check' : 'lock'"
                    >
                        {{
                            deliberationRoom.unlocked
                                ? 'Open — the case is in deliberation'
                                : 'Locked — opens when the case reaches deliberation'
                        }}
                    </StatusBadge>
                </div>
                <p>
                    The deliberation room is access-controlled: jurors only. The jury deliberates separately
                    from the judges' chambers, with no contact from parties, advocates, or judges. Deliberation
                    is the only unrecorded space in the trial — the verdict itself is recorded.
                </p>
                <Btn
                    variant="secondary"
                    :disabled="!deliberationRoom.unlocked"
                    :title="
                        deliberationRoom.unlocked
                            ? 'Enter the jury deliberation room'
                            : 'The room unlocks at the deliberation stage'
                    "
                    style="margin-block-start: var(--space-3)"
                >
                    <Icon name="lock" size="sm" />
                    Enter deliberation room
                </Btn>
                <p class="citation" style="margin-block-start: var(--space-3)" data-no-i18n>
                    Separate deliberation preserves the independence of the jury of peers · Art. IV §4
                </p>
            </Card>
        </div>
    </PageScaffold>
</template>
