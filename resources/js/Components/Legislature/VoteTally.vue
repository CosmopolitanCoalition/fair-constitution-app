<script setup>
/**
 * Legislature/VoteTally — THE vote surface (FE-C1;
 * PHASE_C_DESIGN_frontend.md §C). One component renders every chamber /
 * committee decision, unicameral or bicameral, all threshold classes.
 *
 * CONSTITUTIONAL POSTURE — pure renderer: every number here
 * (serving / requiredYes / quorum.required, per kind) is an engine
 * snapshot from the chamber_votes row. The component NEVER re-derives
 * ceil(serving × 2/3) or any threshold — if the UI and the engine ever
 * disagree, the audit chain shows the engine. Mode is data-driven by the
 * chamber, never a toggle (the mockup bill-detail preview-toggle was a
 * demo affordance).
 *
 * Threshold grammar ported from mockups/legislature/bill-detail.html
 * (meters + dualMeter()) and session-console.html (tie-break record).
 *
 * Casting is emit-only: the page owns the POST /votes/{vote}/cast.
 */
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

const { t } = useI18n();

const props = defineProps({
    /** 'unicameral' | 'bicameral' — legislatures.type_b_seats > 0. */
    mode: { type: String, required: true },
    /** 'committee' | 'floor' — caption grammar only. */
    stage: { type: String, default: 'floor' },
    /**
     * 'majority' | 'supermajority' | 'committee_majority' |
     * 'bicameral_majority' | 'bicameral_supermajority' | 'rcv'
     */
    thresholdClass: { type: String, required: true },
    /* UNICAMERAL — single block (chamber_votes.serving_snapshot / required_yes). */
    serving: { type: Number, default: null },
    requiredYes: { type: Number, default: null },
    /** { yes, no, abstain } | null (pending). */
    tallies: { type: Object, default: null },
    /** { present, required } | null — session-level quorum context. */
    quorum: { type: Object, default: null },
    /* BICAMERAL — one entry per kind, ALL numbers server-computed:
       [{ kind, label, serving, requiredYes, yes, no, abstain,
          quorum: {present, required}, agreed: bool|null }] */
    kinds: { type: Array, default: null },
    /** 'pending' | 'adopted' | 'failed' | 'tied' | 'tied_broken'. */
    outcome: { type: String, default: 'pending' },
    speakerTiebreak: { type: Boolean, default: false },
    basis: { type: String, default: 'Art. II §2' },
    /** Render the yes/no/abstain casting cluster (viewer may vote). */
    canCast: { type: Boolean, default: false },
    /** In-flight POST — disables the cluster. */
    casting: { type: Boolean, default: false },
});

const emit = defineEmits(['cast']);

const PEG_GLOSS = computed(() =>
    t(
        'c_institution_components.vote_tally.peg_gloss',
        'Peg quorum: the denominator is every serving seat. An absent member counts the same as a no.',
    ),
);

const isSupermajority = computed(() => props.thresholdClass.includes('supermajority'));
const stageLabel = computed(() =>
    props.stage === 'committee'
        ? t('c_institution_components.vote_tally.stage_committee', 'at committee')
        : t('c_institution_components.vote_tally.stage_floor', 'on the floor'),
);

/* ------------------------------------------------------- unicameral ---- */
const yes = computed(() => props.tallies?.yes ?? 0);
const no = computed(() => props.tallies?.no ?? 0);

const leftCaption = computed(() => {
    if (props.tallies === null) return t('c_institution_components.vote_tally.left_pending', 'pending — 0 of {serving} recorded', { named: { serving: props.serving } });
    if (props.thresholdClass === 'committee_majority') {
        return t('c_institution_components.vote_tally.left_committee', '{yes} yes of {serving} committee members', { named: { yes: yes.value, serving: props.serving } });
    }
    return t('c_institution_components.vote_tally.left_all_serving', '{yes} yes of {serving} (all serving)', { named: { yes: yes.value, serving: props.serving } });
});

const rightCaption = computed(() => {
    if (props.thresholdClass === 'committee_majority') {
        return t('c_institution_components.vote_tally.right_committee', 'needs {req} of {serving} — all members, not those present · {basis}', { named: { req: props.requiredYes, serving: props.serving, basis: props.basis } });
    }
    if (isSupermajority.value || props.thresholdClass === 'rcv') {
        /* Display of the server snapshot — the formula gloss is composed
           from snapshotted numbers, never computed here. */
        return t('c_institution_components.vote_tally.right_supermajority', 'needs ceil({serving} × 2/3) = {req} of {serving} · Art. VII', { named: { serving: props.serving, req: props.requiredYes } });
    }
    return t('c_institution_components.vote_tally.right_majority', 'needs {req} of {serving} · {basis}', { named: { req: props.requiredYes, serving: props.serving, basis: props.basis } });
});

const outcomeBadge = computed(() => {
    switch (props.outcome) {
        case 'adopted':
            return { tone: 'success', icon: 'check', text: t('c_institution_components.vote_tally.adopted', 'Adopted {yes}–{no}', { named: { yes: yes.value, no: no.value } }) };
        case 'failed':
            return { tone: 'danger', icon: 'x', text: t('c_institution_components.vote_tally.failed', 'Failed {yes}–{no}', { named: { yes: yes.value, no: no.value } }) };
        case 'tied':
            return { tone: 'warning', icon: 'clock', text: t('c_institution_components.vote_tally.tied', 'Tied {yes}–{no} — awaiting the Speaker', { named: { yes: yes.value, no: no.value } }) };
        case 'tied_broken':
            return { tone: 'success', icon: 'check', text: t('c_institution_components.vote_tally.tied_broken', 'Adopted {yes}–{no} — tie broken by the Speaker', { named: { yes: yes.value, no: no.value } }) };
        default:
            return { tone: 'info', icon: 'clock', text: t('c_institution_components.vote_tally.vote_open', 'Vote open') };
    }
});

const tiebreakLine = computed(() => {
    if (props.outcome !== 'tied_broken' && !props.speakerTiebreak) return null;
    /* The mockup record: "4–4 → Speaker broke the tie (F-SPK-004)". */
    return `${yes.value - 1}–${no.value} → Speaker broke the tie · F-SPK-004 · Art. II §3 (Tie-Breaking Vote)`;
});

/* -------------------------------------------------------- bicameral ---- */
const combined = computed(() => {
    if (props.mode !== 'bicameral') return null;
    const failBody = t('c_institution_components.vote_tally.combined_body', 'A failure in either kind, at either stage, fails the act.');
    if (props.outcome === 'adopted') {
        return {
            tone: 'info',
            icon: 'check',
            title: t('c_institution_components.vote_tally.combined_pass', 'Both kinds agree {stage} — the act passes', { named: { stage: stageLabel.value } }),
            body: failBody,
        };
    }
    if (props.outcome === 'failed') {
        const failing = (props.kinds ?? []).filter((k) => k.agreed === false).map((k) => k.label);
        const which = failing.length ? failing.join(t('c_institution_components.vote_tally.and_join', ' and ')) : t('c_institution_components.vote_tally.a_kind', 'a kind');
        return {
            tone: 'warning',
            icon: 'x',
            title: t('c_institution_components.vote_tally.combined_fail', 'The act fails — {which} did not agree {stage}', { named: { which, stage: stageLabel.value } }),
            body: failBody,
        };
    }
    return {
        tone: 'info',
        icon: 'clock',
        title: t('c_institution_components.vote_tally.combined_open', 'Vote open — both kinds must independently agree {stage}', { named: { stage: stageLabel.value } }),
        body: failBody,
    };
});

const combinedCitation = computed(
    () =>
        `Independent agreement of both seat kinds — ${isSupermajority.value ? 'supermajority' : 'majority'} ` +
        'of all serving of each kind · Art. V §3 · as implemented (ledger #q7) · WF-LEG-07',
);

function kindAgreement(kind) {
    if (kind.agreed === true) return { tone: 'success', icon: 'check', text: t('c_institution_components.vote_tally.kind_agrees', 'This kind agrees') };
    if (kind.agreed === false) return { tone: 'danger', icon: 'x', text: t('c_institution_components.vote_tally.kind_disagrees', 'This kind does not agree') };
    return { tone: 'info', icon: 'clock', text: t('c_institution_components.vote_tally.kind_pending', 'Pending') };
}

function kindRightCaption(kind) {
    if (isSupermajority.value) {
        return t('c_institution_components.vote_tally.kind_right_supermajority', 'needs ceil({serving} × 2/3) = {req} · Art. V §3 · ledger #q7', { named: { serving: kind.serving, req: kind.requiredYes } });
    }
    return t('c_institution_components.vote_tally.kind_right_majority', 'needs {req} · Art. V §3 · ledger #q7', { named: { req: kind.requiredYes } });
}

/* ---------------------------------------------------------- casting ---- */
const explanationId = useId();
const explanation = ref('');
function cast(value) {
    emit('cast', { value, explanation: explanation.value.trim() || null });
}
</script>

<template>
    <div class="stack" style="gap: var(--space-3)">
        <!-- ============================================== UNICAMERAL ==== -->
        <template v-if="mode === 'unicameral'">
            <div v-if="quorum" class="meter-block">
                <ThresholdMeter
                    :value="quorum.present"
                    :max="serving"
                    :threshold="quorum.required"
                    :label="t('c_institution_components.vote_tally.quorum_label', 'Quorum — present of all serving')"
                >
                    {{ t('c_institution_components.vote_tally.serving_present', '{present} of {serving} serving present', { named: { present: quorum.present, serving } }) }}
                    <template #note>{{ t('c_institution_components.vote_tally.peg_quorum_note', 'peg quorum: {req} of {serving} serving · Art. II §2', { named: { req: quorum.required, serving } }) }}</template>
                </ThresholdMeter>
            </div>

            <ThresholdMeter
                :value="yes"
                :max="serving"
                :threshold="requiredYes"
                :label="t('c_institution_components.vote_tally.favor_label', '{prefix} — of all serving', { named: { prefix: thresholdClass === 'rcv' ? t('c_institution_components.vote_tally.rcv_outcome', 'Supermajority RCV outcome') : t('c_institution_components.vote_tally.votes_in_favor', 'Votes in favor') } })"
            >
                {{ leftCaption }}
                <template #note>{{ rightCaption }}</template>
            </ThresholdMeter>

            <p class="gloss">{{ PEG_GLOSS }}</p>

            <div class="cluster">
                <StatusBadge :tone="outcomeBadge.tone" :icon="outcomeBadge.icon">{{ outcomeBadge.text }}</StatusBadge>
                <span v-if="tiebreakLine" class="citation" data-no-i18n>{{ tiebreakLine }}</span>
            </div>
        </template>

        <!-- =============================================== BICAMERAL ==== -->
        <template v-else>
            <div class="grid-2">
                <div
                    v-for="kind in kinds ?? []"
                    :key="kind.kind"
                    class="card card--inset tally-kind"
                    :class="{ 'tally-kind--type-b': kind.kind === 'type_b' }"
                >
                    <span class="eyebrow">{{ kind.label }}</span>

                    <div class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                        <ThresholdMeter
                            v-if="kind.quorum"
                            :value="kind.quorum.present"
                            :max="kind.serving"
                            :threshold="kind.quorum.required"
                            :label="t('c_institution_components.vote_tally.kind_quorum_label', 'Quorum of this kind — {label}', { named: { label: kind.label } })"
                        >
                            {{ t('c_institution_components.vote_tally.serving_present', '{present} of {serving} serving present', { named: { present: kind.quorum.present, serving: kind.serving } }) }}
                            <template #note>
                                {{ t('c_institution_components.vote_tally.kind_peg_note', 'peg quorum of this kind: {req} of {serving} serving', { named: { req: kind.quorum.required, serving: kind.serving } }) }}
                            </template>
                        </ThresholdMeter>

                        <ThresholdMeter
                            :value="kind.yes ?? 0"
                            :max="kind.serving"
                            :threshold="kind.requiredYes"
                            :label="t('c_institution_components.vote_tally.kind_favor_label', 'Votes in favor — {label}', { named: { label: kind.label } })"
                        >
                            {{ t('c_institution_components.vote_tally.kind_yes_of', '{yes} yes of {serving} (all serving of this kind)', { named: { yes: kind.yes ?? 0, serving: kind.serving } }) }}
                            <template #note>{{ kindRightCaption(kind) }}</template>
                        </ThresholdMeter>

                        <div class="cluster">
                            <StatusBadge
                                :tone="kindAgreement(kind).tone"
                                :icon="kindAgreement(kind).icon"
                            >{{ kindAgreement(kind).text }}</StatusBadge>
                        </div>
                    </div>
                </div>
            </div>

            <p class="gloss">{{ PEG_GLOSS }} {{ t('c_institution_components.vote_tally.bicameral_peg_extra', 'Each kind meets its own peg quorum — vacancies stay in the denominator.') }}</p>

            <Banner v-if="combined" :tone="combined.tone" :icon="combined.icon" role="status" :title="combined.title">
                {{ combined.body }}
                <span class="citation" data-no-i18n>{{ combinedCitation }}</span>
            </Banner>
        </template>

        <!-- ================================================= CASTING ==== -->
        <div v-if="canCast && outcome === 'pending'" class="stack" style="gap: var(--space-2)">
            <div class="field">
                <label class="field-label" :for="explanationId">{{ t('c_institution_components.vote_tally.explanation_label', 'Explanation (optional)') }}</label>
                <textarea
                    :id="explanationId"
                    v-model="explanation"
                    class="field-input"
                    rows="2"
                    :disabled="casting"
                ></textarea>
                <span class="field-hint">{{ t('c_institution_components.vote_tally.published_hint', 'Published with your vote · Art. II §2') }}</span>
            </div>
            <div class="cluster">
                <Btn variant="primary" size="sm" icon="check" :disabled="casting" @click="cast('yes')">{{ t('c_institution_components.vote_tally.vote_yes', 'Vote yes') }}</Btn>
                <Btn variant="danger" size="sm" icon="x" :disabled="casting" @click="cast('no')">{{ t('c_institution_components.vote_tally.vote_no', 'Vote no') }}</Btn>
                <Btn variant="secondary" size="sm" :disabled="casting" @click="cast('abstain')">{{ t('c_institution_components.vote_tally.abstain', 'Abstain') }}</Btn>
            </div>
        </div>
    </div>
</template>
