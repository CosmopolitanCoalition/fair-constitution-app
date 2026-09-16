<script setup>
/**
 * Legislature/ConstituentConsentPanel — THE dual-supermajority component:
 * the `multi_jurisdiction_votes` UX (FE-D1; PHASE_D_DESIGN_frontend.md
 * §A.1). Promotes BillDetail's inline constituent-consent card into a
 * shared component; composes the existing VoteTally + ThresholdMeter +
 * DataTable — it adds the PAIRING, not new meter math.
 *
 * CONSTITUTIONAL POSTURE — pure renderer: `process.required` is the
 * engine's ceil(total × 2/3) snapshot from the multi_jurisdiction_votes
 * row and is rendered VERBATIM; the component never computes a threshold.
 * The "= ceil({total} × 2/3)" right-caption is a formula GLOSS composed
 * from snapshotted numbers (the VoteTally supermajority-caption idiom) —
 * feed required=99 and it honestly displays 99.
 *
 * Caption grammar verbatim from mockups/executive/executive-home.html
 * lines 76–79; footer gloss verbatim from lines 81–82.
 *
 * Used by: Executive/Home (F-LEG-015 conversion), Legislature/BillDetail
 * (any dual_supermajority act — the FE-D1 call-site migration), forward by
 * Phase E (F-LEG-018) and Phase F (F-LEG-028/029) — designed once here.
 *
 * `legislatureVote` is nullable for the BillDetail call-site, whose floor
 * vote may not have opened while the process row already renders; when
 * null only the constituent block renders (the pre-migration card).
 * Consent rows tolerate BOTH shapes: the Phase C BillController feed
 * ({ jurisdiction: '<name>', result }) and the full Phase D contract
 * ({ jurisdiction: {id,name,adm_chip}, result, chamber_vote, decided_at }).
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';

const { t } = useI18n();

const props = defineProps({
    /**
     * Meter 1 — the initiating legislature's own supermajority (a
     * chamber_votes row): full VoteTally props (mode, thresholdClass:
     * 'supermajority'|'bicameral_supermajority', serving, requiredYes,
     * tallies, kinds, quorum, outcome). Null → block omitted (BillDetail
     * pre-floor-vote).
     */
    legislatureVote: { type: Object, default: null },
    /** "San Marino legislature". */
    legislatureLabel: { type: String, default: null },
    /**
     * Meter 2 — the constituent-jurisdiction supermajority
     * (multi_jurisdiction_votes row):
     * { id, kind, status:'open'|'passed'|'failed'|'expired', total,
     *   required ← engine ceil(total × 2/3), NEVER client math,
     *   yes, no?, pending?, closes_at|null,
     *   consents: [{ jurisdiction:{id,name,adm_chip}|string,
     *                result:'pending'|'yes'|'no',
     *                chamber_vote:{href,summary}|null, decided_at|null }] }
     */
    process: { type: Object, required: true },
    basis: { type: String, default: 'Art. III §3 · Art. VII' },
    /** "Conversion to elected individual office". */
    subjectLabel: { type: String, default: null },
});

const GLOSS = computed(() =>
    t(
        'c_institution_components.constituent_consent_panel.gloss',
        'Both meters must clear their threshold — the legislature’s own supermajority and a supermajority of the constituent jurisdictions, each counted independently.',
    ),
);
const RESULT_LABELS = () => ({
    yes: t('c_institution_components.constituent_consent_panel.result_yes', 'yes'),
    no: t('c_institution_components.constituent_consent_panel.result_no', 'no'),
    pending: t('c_institution_components.constituent_consent_panel.result_pending', 'pending'),
});
function resultLabel(r) {
    return RESULT_LABELS()[r] ?? r;
}

/* ---------------------------------------------- consent-row tolerance --- */
function jurName(row) {
    return typeof row.jurisdiction === 'string' ? row.jurisdiction : (row.jurisdiction?.name ?? '—');
}
function jurAdm(row) {
    return typeof row.jurisdiction === 'object' ? (row.jurisdiction?.adm_chip ?? null) : null;
}

const consents = computed(() => props.process.consents ?? []);
const hasVoteLinks = computed(() => consents.value.some((row) => row.chamber_vote));

/* ------------------------------------------------------------ captions -- */
/* "(New York, Kings, Queens, Bronx, Richmond, Westchester, Erie + 55 more)"
   — first 7 constituent names, remainder counted against the SNAPSHOT
   total (executive-home.html line 78). */
const namesParenthetical = computed(() => {
    const names = consents.value.slice(0, 7).map(jurName);
    if (!names.length) return '';
    const more = Math.max(0, (props.process.total ?? names.length) - names.length);
    return ` (${names.join(', ')}${more > 0 ? t('c_institution_components.constituent_consent_panel.more', ' + {n} more', { named: { n: more } }) : ''})`;
});

const leftCaption = computed(
    () =>
        t('c_institution_components.constituent_consent_panel.left_caption', 'Constituent jurisdictions: {yes} of {total} in favor', { named: { yes: props.process.yes, total: props.process.total } }) +
        namesParenthetical.value,
);

/* Display of the server snapshot — composed from snapshotted numbers,
   never computed here (the VoteTally rightCaption idiom). */
const rightCaption = computed(
    () => `threshold ${props.process.required} = ceil(${props.process.total} × 2/3) · ${props.basis}`,
);

/* ------------------------------------------------------ result badges --- */
const RESULT_TONES = { yes: 'success', no: 'danger', pending: 'neutral' };

/* ------------------------------------------------- combined outcome ----- */
const combined = computed(() => {
    const leg = props.legislatureVote?.outcome ?? null; // null when block 1 omitted
    const proc = props.process.status;

    const legPassed = leg === 'adopted' || leg === 'tied_broken';
    const legFailed = leg === 'failed';
    const procPassed = proc === 'passed';
    const procFailed = proc === 'failed' || proc === 'expired';

    if (legFailed || procFailed) {
        const failing = [];
        if (legFailed) failing.push(props.legislatureLabel ?? t('c_institution_components.constituent_consent_panel.own_supermajority', 'the legislature’s own supermajority'));
        if (procFailed) failing.push(t('c_institution_components.constituent_consent_panel.constituent_supermajority', 'the constituent-jurisdiction supermajority'));
        return {
            tone: 'warning',
            icon: 'x',
            title: t('c_institution_components.constituent_consent_panel.act_fails', 'The act fails — {which} did not clear the threshold', { named: { which: failing.join(t('c_institution_components.constituent_consent_panel.and_join', ' and ')) } }),
        };
    }
    if (procPassed && (legPassed || leg === null)) {
        return {
            tone: 'info',
            icon: 'check',
            title:
                leg === null
                    ? t('c_institution_components.constituent_consent_panel.constituent_reached', 'Constituent supermajority reached')
                    : t('c_institution_components.constituent_consent_panel.both_cleared', 'Both supermajorities cleared — the act is adopted'),
        };
    }
    return {
        tone: 'info',
        icon: 'clock',
        title: t('c_institution_components.constituent_consent_panel.open_both', 'Open — both supermajorities must clear, each counted independently'),
    };
});
</script>

<template>
    <div class="stack" style="gap: var(--space-3)">
        <p v-if="subjectLabel || basis" class="cluster" style="gap: var(--space-2)">
            <strong v-if="subjectLabel" style="color: var(--gov-fg)">{{ subjectLabel }}</strong>
            <span class="citation" data-no-i18n>{{ basis }}</span>
        </p>

        <!-- Block 1 — the legislature's own supermajority -->
        <div v-if="legislatureVote" class="card card--inset">
            <span class="eyebrow">{{ t('c_institution_components.constituent_consent_panel.own_supermajority_eyebrow', '{name}: own supermajority', { named: { name: legislatureLabel ?? t('c_institution_components.constituent_consent_panel.legislature', 'Legislature') } }) }}</span>
            <div style="margin-block-start: var(--space-2)">
                <VoteTally
                    :mode="legislatureVote.mode"
                    :threshold-class="legislatureVote.thresholdClass"
                    :serving="legislatureVote.serving"
                    :required-yes="legislatureVote.requiredYes"
                    :tallies="legislatureVote.tallies"
                    :quorum="legislatureVote.quorum"
                    :kinds="legislatureVote.kinds"
                    :outcome="legislatureVote.outcome"
                    :speaker-tiebreak="legislatureVote.speakerTiebreak"
                />
            </div>
        </div>

        <!-- Block 2 — the constituent-jurisdiction supermajority -->
        <div class="card card--inset">
            <span class="eyebrow">{{ t('c_institution_components.constituent_consent_panel.bodies_eyebrow', 'Constituent jurisdictions: supermajority of the bodies') }}</span>
            <div style="margin-block-start: var(--space-2)">
                <ThresholdMeter
                    :value="process.yes"
                    :max="process.total"
                    :threshold="process.required"
                    :label="t('c_institution_components.constituent_consent_panel.meter_label', 'Constituent jurisdictions in favor')"
                >
                    {{ leftCaption }}
                    <template #note><span data-no-i18n>{{ rightCaption }}</span></template>
                </ThresholdMeter>
            </div>
            <p v-if="process.closes_at" class="citation" style="margin-block-start: var(--space-1)">
                {{ t('c_institution_components.constituent_consent_panel.window_closes', 'window closes {date} · stored as UTC', { named: { date: process.closes_at } }) }}
            </p>

            <DataTable
                :columns="[
                    { key: 'jurisdiction', label: t('c_institution_components.constituent_consent_panel.col_jurisdiction', 'Constituent legislature') },
                    { key: 'result', label: t('c_institution_components.constituent_consent_panel.col_result', 'Consent') },
                    ...(hasVoteLinks ? [{ key: 'record', label: t('c_institution_components.constituent_consent_panel.col_record', 'Chamber vote') }] : []),
                ]"
                :rows="consents"
                :caption="t('c_institution_components.constituent_consent_panel.table_caption', 'Constituent consents — each constituent legislature votes as a body')"
            >
                <template #cell-jurisdiction="{ row }">
                    <span class="cluster" style="gap: var(--space-2)">
                        <AdmChip v-if="jurAdm(row)" :level="jurAdm(row).level" :label="jurAdm(row).label" />
                        {{ jurName(row) }}
                    </span>
                </template>
                <template #cell-result="{ row }">
                    <StatusBadge
                        :tone="RESULT_TONES[row.result] ?? 'neutral'"
                        :icon="row.result === 'yes' ? 'check' : row.result === 'no' ? 'x' : 'clock'"
                    >{{ resultLabel(row.result) }}</StatusBadge>
                </template>
                <template v-if="hasVoteLinks" #cell-record="{ row }">
                    <a v-if="row.chamber_vote" :href="row.chamber_vote.href">{{ row.chamber_vote.summary }}</a>
                    <span v-else class="gloss">—</span>
                </template>
            </DataTable>
            <p v-if="hasVoteLinks" class="citation" style="margin-block-start: var(--space-1)">
                {{ t('c_institution_components.constituent_consent_panel.body_vote_note', 'each constituent legislature votes as a body — its own chamber-vote record linked above') }}
            </p>
        </div>

        <p class="gloss">{{ GLOSS }}</p>

        <Banner :tone="combined.tone" :icon="combined.icon" role="status" :title="combined.title" />
    </div>
</template>
