<script setup>
/**
 * Judiciary/JurorScreening — the voir-dire conflict questionnaire (FE-E1;
 * PHASE_E_DESIGN_frontend.md §A.4; juror-view.html QUESTIONS[]). Composes
 * the already-ported Ui/RadioGroup (No/Yes per question, default No) +
 * Banner result branch.
 *
 * CONSTITUTIONAL POSTURE: the questionnaire binds to the R-22 summons
 * holder (`can.submitScreening`) — you cannot answer another juror's
 * questionnaire (the one real per-record gate in the phase). Screening
 * answers are a record the court reads, not a constitutional instrument
 * (no F-* form; flagged for the q-ledger, §B.6) — the submit emits and the
 * PAGE owns the thin POST /judiciary/jury/{summons}/screening; this
 * component never POSTs. Answers go to the panel judges only.
 *
 * `flagged` is a local UX echo of the submitted answers (any "yes"); the
 * authoritative voir-dire outcome is the court's, recorded server-side.
 *
 * Classes: .radio-group/.radio, .banner--*, .field — all already ported,
 * no new CSS.
 */
import { computed, reactive } from 'vue';
import Banner from '@/Components/Ui/Banner.vue';
import RadioGroup from '@/Components/Ui/RadioGroup.vue';

const props = defineProps({
    /**
     * jury_summonses row (server-shaped):
     * { id, case:{id, title, href}, drawn_at, pool_size, report_at,
     *   location, seed_audit_href, service_state }
     */
    summons: { type: Object, required: true },
    /** [{ id, text }] — the server-authored conflict questions. */
    questions: { type: Array, required: true },
    /** { submitScreening } — R-22 of THIS summons only. */
    can: { type: Object, required: true },
    /** Submitted answers (read-only render for a discharged/answered summons). */
    answers: { type: Object, default: null },
    /** In-flight POST — disables the form. */
    submitting: { type: Boolean, default: false },
    /** Result tone after submit: null (unsubmitted) | 'flagged' | 'clean'. */
    outcome: { type: String, default: null },
});

const emit = defineEmits(['submit']);

/* Local form state — defaults to 'no' per question (the mockup's checked No). */
const form = reactive({});
const OPTIONS = [
    { value: 'no', label: 'No' },
    { value: 'yes', label: 'Yes' },
];
for (const q of props.questions) {
    form[q.id] = props.answers?.[q.id] ?? 'no';
}

const readOnly = computed(() => !!props.answers || !props.can.submitScreening);

function submit() {
    emit('submit', { answers: { ...form } });
}
</script>

<template>
    <form class="stack" style="gap: var(--space-3)" novalidate @submit.prevent="submit">
        <p>
            Answer honestly — screening removes conflicts of interest, never opinions, demographics, or
            politics. Your answers go to the panel judges only.
        </p>

        <fieldset style="border: 0; padding: 0; margin: 0">
            <legend class="visually-hidden">Conflict screening questions</legend>
            <div
                v-for="(q, i) in questions"
                :key="q.id"
                class="field"
                style="margin-block-end: var(--space-3)"
            >
                <span :id="`lbl-${q.id}`" class="field-label">{{ i + 1 }}. {{ q.text }}</span>
                <RadioGroup
                    v-model="form[q.id]"
                    :options="readOnly ? OPTIONS.map((o) => ({ ...o, disabled: true })) : OPTIONS"
                    :name="q.id"
                    :label="q.text"
                />
            </div>
        </fieldset>

        <div v-if="!readOnly" class="cluster">
            <button type="submit" class="btn btn--primary" :disabled="submitting">
                {{ submitting ? 'Submitting…' : 'Submit screening answers' }}
            </button>
        </div>

        <Banner
            v-if="outcome === 'flagged'"
            tone="warning"
            role="status"
            title="Flagged for voir dire review"
        >
            A panel judge follows up on the answers you flagged. If a conflict is confirmed you are
            excused without penalty and the draw selects a replacement.
            <span class="citation" data-no-i18n>conflict screening · Art. IV §4 · WF-JUD-04</span>
        </Banner>
        <Banner
            v-else-if="outcome === 'clean'"
            tone="info"
            role="status"
            title="No conflicts declared"
        >
            You remain in the panel pool. Empanelment is confirmed at voir dire.
            <span class="citation" data-no-i18n>conflict screening · Art. IV §4 · WF-JUD-04</span>
        </Banner>
    </form>
</template>
