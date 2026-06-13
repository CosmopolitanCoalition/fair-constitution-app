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
 *   - "Confirmation record": F-LEG-021 FormChip + the consent-vote DataTable
 *     (nominee, nominated-by, "{yes} of {serving} serving", confirmed/not,
 *     10-yr CLK-09 term dates)
 *   - "Conversion to an elected judiciary": F-LEG-018 FormChip +
 *     ConstituentConsentPanel (the SAME Phase D component — Art. IV §3 dual
 *     supermajority) when a process exists, else the reference + deep-link
 *   - "Term length": AmendableSetting (10 yrs · CLK-09 · lockstep CLK-10)
 *
 * Every threshold/required number is an engine snapshot; nothing is computed
 * in the Vue. PUBLIC READ — the only "actions" are R-09 deep-links into the
 * bill flow; this page renders the record, it never originates a vote.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AmendableSetting from '@/Components/Ui/AmendableSetting.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import ConstituentConsentPanel from '@/Components/Legislature/ConstituentConsentPanel.vue';

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
const TYPE_LABELS = { appointed: 'Appointed', elected: 'Elected' };
const typeLabel = computed(() => TYPE_LABELS[props.judiciary.type] ?? props.judiciary.type);
const isAppointed = computed(() => props.judiciary.type === 'appointed');

const creationForm = computed(() => props.surface.forms?.find((f) => f.id === 'F-LEG-017') ?? null);
const consentForm = computed(() => props.surface.forms?.find((f) => f.id === 'F-LEG-021') ?? null);
const conversionForm = computed(() => props.surface.forms?.find((f) => f.id === 'F-LEG-018') ?? null);

/* Dual-supermajority acts ride the Phase C bill flow; the legislature votes
   and the engine opens the constituent leg (same idiom as the executive). */
const creationDeepLink = computed(
    () => `/legislature/bills?intro=1&subject=judiciary_creation&judiciary=${props.judiciary.id}`,
);
const conversionDeepLink = computed(
    () => `/legislature/bills?intro=1&subject=judiciary_conversion&judiciary=${props.judiciary.id}`,
);

const MODE_LABELS = {
    constituent: 'equal number from every constituent jurisdiction',
    committee: 'judicial committee (the constitutional fallback)',
};
const nominationModeLabel = computed(
    () => MODE_LABELS[props.creation?.nomination_mode] ?? props.creation?.nomination_mode ?? null,
);

const consentColumns = [
    { key: 'nominee', label: 'Nominee' },
    { key: 'nominated_by', label: 'Nominated by' },
    { key: 'consent', label: 'Consent vote', mono: true },
    { key: 'outcome', label: 'Outcome' },
    { key: 'term', label: 'Term', mono: true },
];

const panelColumns = [
    { key: 'severity', label: 'Case severity' },
    { key: 'panel', label: 'Panel' },
    { key: 'rule', label: 'Rule', mono: true },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="judiciary.name">
        <template #intro>
            An independent court serving this jurisdiction and its constituents. Created by
            supermajority act of the legislature; judges nominated by the constituent jurisdictions
            in equal numbers (or the judicial committee as fallback) and confirmed by consent vote.
            Appointed is the default judiciary type — conversion to elected requires two independent
            supermajorities.
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
            <Link href="/judiciary/docket">Case docket →</Link>
            <Link href="/judiciary/challenges">Constitutional challenges →</Link>
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
        <Card as="section" title="How this court sits">
            <p class="cluster" style="gap: var(--space-2)">
                <HardenedChip>panel sizing is a hard constraint · CLK-16 · Art. IV §4</HardenedChip>
                <StatusBadge tone="neutral" icon="scale">
                    {{ judiciary.judges_on_bench }} judge{{ judiciary.judges_on_bench === 1 ? '' : 's' }} on the bench
                </StatusBadge>
            </p>
            <div style="margin-block-start: var(--space-3)">
                <DataTable
                    :columns="panelColumns"
                    :rows="panelRule.rows"
                    caption="Panel size by case severity"
                />
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Severity scaling: the heavier the possible consequence, the more judges must hear
                it — the panel is always odd so no case can deadlock.
            </p>
            <p class="citation">
                Panels of at least 3, odd, scaled to severity; full court for major constitutional
                questions · Art. IV §4 · CLK-16 — elected judges (if converted) run in groups of at
                least {{ judiciary.min_judges_per_race }} · Art. IV §4 · CLK-15
            </p>
        </Card>

        <!-- ===================================== creation act ========== -->
        <Card as="section" title="How this court was created">
            <template v-if="creation">
                <p class="cluster" style="gap: var(--space-2)">
                    <FormChip form-id="F-LEG-017" :name="creationForm?.name" :alias="creationForm?.alias" />
                    <a class="tag-chip" :href="creation.act.href" data-no-i18n>{{ creation.act.act_number }}</a>
                    <span v-if="creation.act.effective_on" class="citation">effective {{ creation.act.effective_on }}</span>
                    <span v-else-if="creation.act.enacted_at" class="citation">enacted {{ creation.act.enacted_at }}</span>
                </p>

                <div v-if="creation.vote" class="card card--inset" style="margin-block-start: var(--space-3)">
                    <span class="eyebrow">The supermajority that chartered the court</span>
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
                        Supermajority: two thirds of all serving members — counted against everyone
                        holding a seat, never just those present (ceil(serving × 2/3) · Art. VII).
                    </p>
                </div>

                <h3 style="font-size: var(--text-base); margin-block-start: var(--space-4)">
                    Nomination
                </h3>
                <p v-if="nominationModeLabel">
                    This court's {{ creation.judge_count }} seat{{ creation.judge_count === 1 ? '' : 's' }} were
                    nominated by {{ nominationModeLabel }}. Equal numbers from each constituent
                    jurisdiction are mandatory; where a constituent declines, the legislature's
                    judicial committee supplies the nomination in its stead.
                </p>
                <p class="citation">
                    Constituent jurisdictions nominate equal numbers; judicial committee as fallback · Art. IV §2
                </p>
            </template>
            <template v-else>
                <p class="gloss">
                    No creation act on record — this judiciary is a constitutional placeholder. The
                    legislature creates the court by supermajority act, deriving the nomination mode
                    from the jurisdiction's constituent structure (Art. IV §1–§2).
                </p>
                <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <FormChip form-id="F-LEG-017" :name="creationForm?.name" :alias="creationForm?.alias" />
                    <Link v-if="can.proposeCreationBill" :href="creationDeepLink">
                        Introduce a creation bill →
                    </Link>
                    <span v-else class="citation">filed by a member of the source legislature (R-09)</span>
                </p>
            </template>
        </Card>

        <!-- ===================================== confirmation ========= -->
        <Card as="section" title="Confirmation record — consent votes for the bench">
            <p class="cluster" style="gap: var(--space-2)">
                <FormChip form-id="F-LEG-021" :name="consentForm?.name" :alias="consentForm?.alias" />
            </p>
            <template v-if="nominations.length">
                <div style="margin-block-start: var(--space-3)">
                    <DataTable
                        :columns="consentColumns"
                        :rows="nominations"
                        caption="Judicial nomination consent votes, one per nominee"
                    >
                        <template #cell-nominee="{ row }">{{ row.nominee.name }}</template>
                        <template #cell-consent="{ row }">{{ row.consent.summary }}</template>
                        <template #cell-outcome="{ row }">
                            <StatusBadge
                                :tone="row.consent.outcome === 'confirmed' ? 'success' : 'neutral'"
                                :icon="row.consent.outcome === 'confirmed' ? 'check' : 'x'"
                            >
                                {{ row.consent.outcome === 'confirmed' ? 'Confirmed' : 'Not confirmed' }}
                            </StatusBadge>
                        </template>
                        <template #cell-term="{ row }">
                            <template v-if="row.term">{{ row.term.starts_on }} → {{ row.term.ends_on }}</template>
                            <template v-else>—</template>
                        </template>
                    </DataTable>
                </div>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    Confirmation needs the same threshold the creation act met; one consent vote is
                    held per nominee. Constituents keep their nomination rights; replacement nominees
                    go to a fresh consent vote when a seat next opens (WF-JUD-07).
                </p>
            </template>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                No nominations on record yet. Once the creation act passes, each constituent (or the
                judicial committee) nominates a judge onto a vacant seat, and each nomination flows
                into its own F-LEG-021 consent vote.
            </p>
            <p class="citation">Judicial nomination consent vote, one per nominee · F-LEG-021 · Art. IV §2</p>
        </Card>

        <!-- ===================================== conversion =========== -->
        <Card as="section" title="Conversion to an elected judiciary">
            <p>
                Converting this appointed court to a directly elected one needs
                <strong>two independent supermajorities</strong>: the legislature's own, and a
                supermajority of the constituent jurisdictions themselves. Neither alone is enough.
            </p>
            <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                <FormChip form-id="F-LEG-018" :name="conversionForm?.name" :alias="conversionForm?.alias" />
            </p>

            <!-- live or historical conversion process -->
            <div v-if="conversion && conversion.process" style="margin-block-start: var(--space-3)">
                <ConstituentConsentPanel
                    :legislature-vote="conversion.legislatureVote"
                    :legislature-label="judiciary.legislature?.name ?? 'The legislature'"
                    :process="conversion.process"
                    :subject-label="conversion.subjectLabel"
                    basis="Art. IV §3 · Art. VII"
                />
            </div>

            <!-- conversion adopted, but no constituent process (no constituents to consent) -->
            <template v-else-if="conversion">
                <Banner
                    tone="info"
                    role="status"
                    title="Conversion adopted on the chamber supermajority alone"
                    style="margin-block-start: var(--space-3)"
                >
                    No direct constituent jurisdiction holds a legislature able to vote, so the
                    conversion completes on the chamber's own supermajority (Art. IV §3). A judicial
                    election schedules from here.
                </Banner>
                <div v-if="conversion.legislatureVote" style="margin-block-start: var(--space-3)">
                    <span class="eyebrow">The chamber supermajority</span>
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
                    No conversion on record. If conversion passes, judges are thereafter elected in
                    groups of at least {{ judiciary.min_judges_per_race }} via STV, and judicial
                    terms sync to the general election clock (Art. IV §3 · CLK-15 · CLK-10).
                </p>
                <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <Link v-if="can.proposeConversionBill" :href="conversionDeepLink">
                        Introduce a conversion bill →
                    </Link>
                    <span v-else class="citation">filed by a member of the source legislature (R-09)</span>
                </p>
            </template>

            <p class="citation" style="margin-block-start: var(--space-2)">
                Supermajority of legislature + supermajority of constituent jurisdictions · Art. IV §3 —
                groups of at least {{ judiciary.min_judges_per_race }} per race · CLK-15 · Art. IV §4 —
                terms synced · CLK-10
            </p>
        </Card>

        <!-- ===================================== term lockstep ========= -->
        <Card as="section" title="Term length — judicial appointments">
            <AmendableSetting
                :value="`${term.years} years`"
                setting-key="judicial_appointment_years"
                :default-value="10"
                :citation="`must stay in lockstep with civil appointments · ${term.clk} · ${term.civilLockstep} · Art. IV §4; Art. II §9`"
            />
            <p style="margin-block-start: var(--space-2)">
                <HardenedChip>civil + judicial appointment lengths move in lockstep · Art. IV §1 · Art. II §9</HardenedChip>
            </p>
            <p class="cc-small" style="margin-block-start: var(--space-2)">
                Judicial and civil appointment lengths move together — a legislative act changing one
                changes both. Renewals re-run the nomination and consent process (WF-JUD-07).
                <template v-if="isAppointed">
                    Appointed judges hold a {{ term.years }}-year term ({{ term.clk }}); converted
                    elected judges instead run in lockstep with the general election ({{ term.civilLockstep }}).
                </template>
            </p>
            <p v-if="judiciary.legislature" class="citation" style="margin-block-start: var(--space-1)">
                <Link :href="judiciary.legislature.chamber_href">term sync on the chamber page →</Link>
            </p>
        </Card>

        <!-- ===================================== ESM-18 strip ========== -->
        <Card as="section" title="Judiciary lifecycle">
            <span class="eyebrow">Judiciary lifecycle · ESM-18</span>
            <StateStrip :states="machine" :current="status" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                One judiciary row per jurisdiction with a legislature — the same row evolves through
                its lifecycle (Art. IV §1–§3). There is no per-case entity behind this surface; cases
                live on the <Link href="/judiciary/docket">docket</Link>.
            </p>
        </Card>

        <template #about>
            <p>
                The judiciary is created by supermajority act and exists as a stub from the moment a
                jurisdiction with a legislature is set up. The appointed footing is the default —
                conversion to a directly elected court is the only path to elected judges, and it is
                deliberately hard: a supermajority of the chamber AND a supermajority of the
                constituent jurisdictions must each agree (Art. IV §3). Every meter on this page is
                the engine's own snapshot — never a demo toggle.
            </p>
        </template>
    </PageScaffold>
</template>
