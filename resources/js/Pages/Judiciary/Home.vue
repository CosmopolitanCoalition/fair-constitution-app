<script setup>
/**
 * Judiciary/Home — FE-E2 (PHASE_E_DESIGN_frontend.md §B.1; surface
 * judiciary/judiciary-home).
 *
 * The LIVE model of ONE judiciary, rendered by type (appointed default ·
 * Art. IV §1) + ESM-18 status. The mockup's conversion meter sliders are a
 * demo affordance; in product every meter is an engine snapshot the
 * controller read from the chamber_votes / multi_jurisdiction_votes rows.
 *
 * Composes (zero new CSS — pure composition over the Phase A–D kit):
 *   - "How this court sits": HardenedChip + the seated-bench StatusBadge +
 *     the severity→panel DataTable (CLK-16 rule citations, NOT computed sizes)
 *   - "How this court was created": F-LEG-017 FormChip + the creation-act
 *     card + the supermajority VoteTally that chartered it
 *   - JudicialConfirmations: bounded nomination records and existing
 *     legislative consent / Speaker tie controls, with public names and terms.
 *   - "Conversion to an elected judiciary": F-LEG-018 FormChip +
 *     ConstituentConsentPanel (the SAME Phase D component — Art. IV §3 dual
 *     supermajority) when a process exists, else the reference + deep-link
 *   - "Term length": AmendableSetting (10 yrs · CLK-09 · lockstep CLK-10)
 *
 * Every threshold/required number is an engine snapshot; nothing is computed
 * in the Vue. Records are public; confirmation actions follow the exact
 * source legislature's member and Speaker context.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AmendableSetting from '@/Components/Ui/AmendableSetting.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import ConstituentConsentPanel from '@/Components/Legislature/ConstituentConsentPanel.vue';
import JudicialConfirmations from '@/Components/Judiciary/JudicialConfirmations.vue';
import JudicialNominations from '@/Components/Judiciary/JudicialNominations.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    /**
     * { id, name, type:'appointed'|'elected', status (ESM-18),
     *   judges_on_bench, min_judges_per_race,
     *   jurisdiction:{id,name,href}|null,
     *   legislature:{id,name,chamber_href}|null }
     */
    judiciary: { type: Object, required: true },
    /** ESM-18 legend (the Judiciary lifecycle), current highlighted by status. */
    machine: { type: Array, default: () => [] },
    /** { rows:[{severity,panel,rule}] } — CLK-16 severity→panel table. */
    panelRule: { type: Object, default: () => ({ rows: [] }) },
    /** { act:{act_number,href,enacted_at,effective_on}, nomination_mode, judge_count, vote: VoteTallyProps|null } | null. */
    creation: { type: Object, default: null },
    /** F-LEG-021 ×N consent rows. */
    nominations: { type: Array, default: () => [] },
    nominationContext: { type: Object, default: () => ({}) },
    vacantSeats: { type: Object, default: () => ({ rows: [], pages: {} }) },
    judicialCommittees: { type: Object, default: () => ({ rows: [], pages: {} }) },
    judicialProposals: { type: Object, default: () => ({ rows: [], pages: {} }) },
    judicialNominees: { type: Object, default: () => ({ candidates: [] }) },
    confirmationPages: { type: Object, default: () => ({}) },
    confirmationContext: { type: Object, default: () => ({ preview: true }) },
    /** { subjectLabel, act, legislatureVote: VoteTallyProps|null, process: ConstituentConsentPanelProps|null } | null. */
    conversion: { type: Object, default: null },
    /** { years, clk, civilLockstep, amendable }. */
    term: { type: Object, default: () => ({ years: 10, clk: 'CLK-09', civilLockstep: 'CLK-10', amendable: true }) },
    can: { type: Object, default: () => ({ proposeCreationBill: false, proposeConversionBill: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const status = computed(() => props.judiciary.status);
const TYPE_LABELS = { appointed: t('c_institutions.judiciary_home.type_appointed', 'Appointed'), elected: t('c_institutions.judiciary_home.type_elected', 'Elected') };
const typeLabel = computed(() => TYPE_LABELS[props.judiciary.type] ?? props.judiciary.type);
const isAppointed = computed(() => props.judiciary.type === 'appointed');

const creationForm = computed(() => props.surface.forms?.find((f) => f.id === 'F-LEG-017') ?? null);
const conversionForm = computed(() => props.surface.forms?.find((f) => f.id === 'F-LEG-018') ?? null);

/* Institution acts are filed in the court's exact source legislature. */
const creationDeepLink = computed(
    () => props.judiciary.legislature ? `/legislatures/${props.judiciary.legislature.id}/institution-acts?action=create-court` : null,
);
const conversionDeepLink = computed(
    () => props.judiciary.legislature ? `/legislatures/${props.judiciary.legislature.id}/institution-acts?action=elect-court` : null,
);

const MODE_LABELS = {
    constituent: t('c_institutions.judiciary_home.mode_constituent', 'equal number from every constituent jurisdiction'),
    committee: t('c_institutions.judiciary_home.mode_committee', 'judicial committee (the constitutional fallback)'),
};
const nominationModeLabel = computed(
    () => MODE_LABELS[props.creation?.nomination_mode] ?? props.creation?.nomination_mode ?? null,
);

const panelColumns = [
    { key: 'severity', label: t('c_institutions.judiciary_home.col_severity', 'Case severity') },
    { key: 'panel', label: t('c_institutions.judiciary_home.col_panel', 'Panel') },
    { key: 'rule', label: t('c_institutions.judiciary_home.col_rule', 'Rule'), mono: true },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="judiciary.name">
        <template #intro>
            {{ t('c_institutions.judiciary_home.intro', 'An independent court serving this jurisdiction and its constituents. Created by supermajority act of the legislature; judges nominated by the constituent jurisdictions in equal numbers (or the judicial committee as fallback) and confirmed by consent vote. Appointed is the default kind of court — converting it to an elected court takes two separate supermajorities.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ===================================== header links ========== -->
        <div class="cluster">
            <Link v-if="judiciary.jurisdiction" :href="judiciary.jurisdiction.href">
                {{ judiciary.jurisdiction.name }} →
            </Link>
            <Link v-if="judiciary.legislature" :href="judiciary.legislature.chamber_href">
                {{ judiciary.legislature.name }} →
            </Link>
            <Link href="/judiciary/docket">{{ t('c_institutions.judiciary_home.case_docket', 'Case docket →') }}</Link>
            <Link href="/judiciary/challenges">{{ t('c_institutions.judiciary_home.const_challenges', 'Constitutional challenges →') }}</Link>
        </div>

        <p class="cluster" style="gap: var(--space-2)">
            <TagChip data-no-i18n>{{ typeLabel }}</TagChip>
            <StatusBadge :tone="status === 'appointed' || status === 'elected' ? 'success' : 'neutral'" icon="landmark">
                {{ status }}
            </StatusBadge>
            <span class="citation" data-no-i18n>
                Appointed is the default judiciary type · Art. IV §2 — conversion to elected requires
                dual supermajorities · Art. IV §3
            </span>
        </p>

        <!-- ===================================== how it sits =========== -->
        <Card as="section" :title="t('c_institutions.judiciary_home.sits_title', 'How this court sits')">
            <p class="cluster" style="gap: var(--space-2)">
                <HardenedChip>{{ t('c_institutions.judiciary_home.sits_chip', 'panel sizing is a hard constraint · CLK-16 · Art. IV §4') }}</HardenedChip>
                <StatusBadge tone="neutral" icon="scale">
                    {{ t('c_institutions.judiciary_home.judges_on_bench', { count: judiciary.judges_on_bench, s: judiciary.judges_on_bench === 1 ? '' : 's' }) }}
                </StatusBadge>
            </p>
            <div style="margin-block-start: var(--space-3)">
                <DataTable
                    :columns="panelColumns"
                    :rows="panelRule.rows"
                    :caption="t('c_institutions.judiciary_home.panel_caption', 'Panel size by case severity')"
                />
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.judiciary_home.severity_gloss', 'Severity scaling: the heavier the possible consequence, the more judges must hear it — the panel is always odd so no case can deadlock.') }}
            </p>
            <p class="citation">
                {{ t('c_institutions.judiciary_home.panels_cite', { min: judiciary.min_judges_per_race }) }}
            </p>
        </Card>

        <!-- ===================================== creation act ========== -->
        <Card as="section" :title="t('c_institutions.judiciary_home.created_title', 'How this court was created')">
            <template v-if="creation">
                <p class="cluster" style="gap: var(--space-2)">
                    <FormChip form-id="F-LEG-017" :name="creationForm?.name" :alias="creationForm?.alias" />
                    <a class="tag-chip" :href="creation.act.href" data-no-i18n>{{ creation.act.act_number }}</a>
                    <span v-if="creation.act.effective_on" class="citation">{{ t('c_institutions.judiciary_home.effective_on', { date: creation.act.effective_on }) }}</span>
                    <span v-else-if="creation.act.enacted_at" class="citation">{{ t('c_institutions.judiciary_home.enacted_at', { at: creation.act.enacted_at }) }}</span>
                </p>

                <div v-if="creation.vote" class="card card--inset" style="margin-block-start: var(--space-3)">
                    <span class="eyebrow">{{ t('c_institutions.judiciary_home.chartered_eyebrow', 'The supermajority that chartered the court') }}</span>
                    <div style="margin-block-start: var(--space-2)">
                        <VoteTally
                            :mode="creation.vote.mode"
                            :stage="creation.vote.stage"
                            :threshold-class="creation.vote.thresholdClass"
                            :serving="creation.vote.serving"
                            :required-yes="creation.vote.requiredYes"
                            :tallies="creation.vote.tallies"
                            :quorum="creation.vote.quorum"
                            :kinds="creation.vote.kinds"
                            :outcome="creation.vote.outcome"
                            :speaker-tiebreak="creation.vote.speakerTiebreak"
                        />
                    </div>
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        {{ t('c_institutions.judiciary_home.supermajority_gloss', 'Supermajority: two thirds of all serving members — counted against everyone holding a seat, never just those present (ceil(serving × 2/3) · Art. VII).') }}
                    </p>
                </div>

                <h3 style="font-size: var(--text-base); margin-block-start: var(--space-4)">
                    {{ t('c_institutions.judiciary_home.nomination_heading', 'Nomination') }}
                </h3>
                <p v-if="nominationModeLabel">
                    {{ t('c_institutions.judiciary_home.nomination_intro', { count: creation.judge_count, s: creation.judge_count === 1 ? '' : 's', mode: nominationModeLabel }) }} {{ t('c_institutions.judiciary_home.nomination_intro_after', "Equal numbers from each constituent jurisdiction are mandatory; where a constituent declines, the legislature's judicial committee supplies the nomination in its stead.") }}
                </p>
                <p class="citation">
                    {{ t('c_institutions.judiciary_home.nomination_cite', 'Constituent jurisdictions nominate equal numbers; judicial committee as fallback · Art. IV §2') }}
                </p>
            </template>
            <template v-else>
                <p class="gloss">
                    {{ t('c_institutions.judiciary_home.no_creation', "No creation act on record — this judiciary is a constitutional placeholder. The legislature creates the court by supermajority act, deriving the nomination mode from the jurisdiction's constituent structure (Art. IV §1–§2).") }}
                </p>
                <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <FormChip form-id="F-LEG-017" :name="creationForm?.name" :alias="creationForm?.alias" />
                    <Link v-if="creationDeepLink" :href="creationDeepLink">
                        {{ t('c_institutions.judiciary_home.propose_creation', 'Propose court creation →') }}
                    </Link>
                    <span v-else class="citation">{{ t('c_institutions.judiciary_home.filed_by_member', 'filed by a member of the source legislature (R-09)') }}</span>
                </p>
            </template>
        </Card>

        <JudicialNominations :judiciary="judiciary" :context="nominationContext" :seats="vacantSeats" :committees="judicialCommittees" :proposals="judicialProposals" :nominees="judicialNominees" />
        <JudicialConfirmations :judiciary="judiciary" :nominations="nominations" :pages="confirmationPages" :context="confirmationContext" />

        <!-- ===================================== conversion =========== -->
        <Card as="section" :title="t('c_institutions.judiciary_home.conversion_title', 'Conversion to an elected judiciary')">
            <p>
                {{ t('c_institutions.judiciary_home.conversion_before', 'Converting this appointed court to a directly elected one needs') }} <strong>{{ t('c_institutions.judiciary_home.conversion_strong', 'two independent supermajorities') }}</strong>{{ t('c_institutions.judiciary_home.conversion_after', ": the legislature's own, and a supermajority of the constituent jurisdictions themselves. Neither alone is enough.") }}
            </p>
            <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                <FormChip form-id="F-LEG-018" :name="conversionForm?.name" :alias="conversionForm?.alias" />
            </p>

            <!-- live or historical conversion process -->
            <div v-if="conversion && conversion.process" style="margin-block-start: var(--space-3)">
                <ConstituentConsentPanel
                    :legislature-vote="conversion.legislatureVote"
                    :legislature-label="judiciary.legislature?.name ?? t('c_institutions.judiciary_home.the_legislature', 'The legislature')"
                    :process="conversion.process"
                    :subject-label="conversion.subjectLabel"
                    :basis="t('c_institutions.judiciary_home.conversion_basis', 'Art. IV §3 · Art. VII')"
                />
            </div>

            <!-- conversion adopted, but no constituent process (no constituents to consent) -->
            <template v-else-if="conversion">
                <Banner
                    tone="info"
                    role="status"
                    :title="t('c_institutions.judiciary_home.conversion_alone_title', 'Conversion adopted on the chamber supermajority alone')"
                    style="margin-block-start: var(--space-3)"
                >
                    {{ t('c_institutions.judiciary_home.conversion_alone_body', "No direct constituent jurisdiction holds a legislature able to vote, so the conversion completes on the chamber's own supermajority (Art. IV §3). A judicial election schedules from here.") }}
                </Banner>
                <div v-if="conversion.legislatureVote" style="margin-block-start: var(--space-3)">
                    <span class="eyebrow">{{ t('c_institutions.judiciary_home.chamber_super_eyebrow', 'The chamber supermajority') }}</span>
                    <div style="margin-block-start: var(--space-2)">
                        <VoteTally
                            :mode="conversion.legislatureVote.mode"
                            :stage="conversion.legislatureVote.stage"
                            :threshold-class="conversion.legislatureVote.thresholdClass"
                            :serving="conversion.legislatureVote.serving"
                            :required-yes="conversion.legislatureVote.requiredYes"
                            :tallies="conversion.legislatureVote.tallies"
                            :quorum="conversion.legislatureVote.quorum"
                            :kinds="conversion.legislatureVote.kinds"
                            :outcome="conversion.legislatureVote.outcome"
                            :speaker-tiebreak="conversion.legislatureVote.speakerTiebreak"
                        />
                    </div>
                </div>
            </template>

            <!-- no conversion: the F-LEG-018 reference + deep-link -->
            <template v-else>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    {{ t('c_institutions.judiciary_home.no_conversion', { min: judiciary.min_judges_per_race }) }}
                </p>
                <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <Link v-if="conversionDeepLink" :href="conversionDeepLink">
                        {{ t('c_institutions.judiciary_home.propose_elected', 'Propose elected court →') }}
                    </Link>
                    <span v-else class="citation">{{ t('c_institutions.judiciary_home.filed_by_member', 'filed by a member of the source legislature (R-09)') }}</span>
                </p>
            </template>

            <p class="citation" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.judiciary_home.conversion_cite', { min: judiciary.min_judges_per_race }) }}
            </p>
        </Card>

        <!-- ===================================== term lockstep ========= -->
        <Card as="section" :title="t('c_institutions.judiciary_home.term_title', 'Term length — judicial appointments')">
            <AmendableSetting
                :value="t('c_institutions.judiciary_home.term_years', { years: term.years })"
                setting-key="judicial_appointment_years"
                :default-value="10"
                :citation="t('c_institutions.judiciary_home.term_citation', { clk: term.clk, civil: term.civilLockstep })"
            />
            <p style="margin-block-start: var(--space-2)">
                <HardenedChip>{{ t('c_institutions.judiciary_home.term_chip', 'civil + judicial appointment lengths move in lockstep · Art. IV §1 · Art. II §9') }}</HardenedChip>
            </p>
            <p class="cc-small" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.judiciary_home.term_move', 'Judicial and civil appointment lengths move together — a legislative act changing one changes both. Renewals re-run the nomination and consent process (WF-JUD-07).') }}
                <template v-if="isAppointed">
                    {{ t('c_institutions.judiciary_home.term_appointed', { years: term.years, clk: term.clk, civil: term.civilLockstep }) }}
                </template>
            </p>
            <p v-if="judiciary.legislature" class="citation" style="margin-block-start: var(--space-1)">
                <Link :href="judiciary.legislature.chamber_href">{{ t('c_institutions.judiciary_home.term_sync_link', 'term sync on the chamber page →') }}</Link>
            </p>
        </Card>

        <!-- ===================================== ESM-18 strip ========== -->
        <Card as="section" :title="t('c_institutions.judiciary_home.lifecycle_title', 'Judiciary lifecycle')">
            <span class="eyebrow">{{ t('c_institutions.judiciary_home.lifecycle_eyebrow', 'Judiciary lifecycle · ESM-18') }}</span>
            <StateStrip :states="machine" :current="status" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.judiciary_home.lifecycle_before', 'One judiciary row per jurisdiction with a legislature — the same row evolves through its lifecycle (Art. IV §1–§3). There is no per-case entity behind this surface; cases live on the') }} <Link href="/judiciary/docket">{{ t('c_institutions.judiciary_home.docket_word', 'docket') }}</Link>.
            </p>
        </Card>

        <template #about>
            <p>
                {{ t('c_institutions.judiciary_home.about_body', "The judiciary is created by supermajority act and exists as a stub from the moment a jurisdiction with a legislature is set up. The appointed footing is the default — conversion to a directly elected court is the only path to elected judges, and it is deliberately hard: a supermajority of the chamber AND a supermajority of the constituent jurisdictions must each agree (Art. IV §3). Every meter on this page is the engine's own snapshot — never a demo toggle.") }}
            </p>
        </template>
    </PageScaffold>
</template>
