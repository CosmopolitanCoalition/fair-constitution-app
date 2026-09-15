<script setup>
/**
 * Executive/Home — FE-D2 (PHASE_D_DESIGN_frontend.md §B.1; surface
 * executive/executive-home).
 *
 * The LIVE model of one executive office, rendered by type + status
 * (ESM-16). The mockup's 3-way model toggle is a demo affordance; in
 * product the model card renders ONE model, driven by `executive.type` +
 * `executive.status`:
 *   forming   → honest empty state (stub awaits the F-LEG-014 delegation act)
 *   delegated → Westminster panel — member rows are ex-officio legislators
 *               (each carries "remains a seated legislator · seat {n}")
 *   elected individual → principal + R-17 advisors by rank (succession order)
 *   elected committee  → officer rows, equal decision-making power
 *
 * Every threshold/required number on this page is an engine snapshot read
 * by the controller from the chamber_votes / multi_jurisdiction_votes rows
 * (ChamberVotePresenter); nothing is computed in the Vue. Public read —
 * institution acts link to their source legislature's filing workspace; this page
 * renders the conversion process, it never originates a vote.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import OrgChip from '@/Components/Ui/OrgChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import ConstituentConsentPanel from '@/Components/Legislature/ConstituentConsentPanel.vue';
import DepartmentCard from '@/Components/Executive/DepartmentCard.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    /**
     * { id, type:'committee'|'individual', status (ESM-16),
     *   scope_text, member_count,
     *   jurisdiction:{id,name,href}|null,
     *   legislature:{id,name,chamber_href}|null,
     *   term:{starts_on, ends_on, days_remaining, number} }
     */
    executive: { type: Object, required: true },
    /** ESM-16 legend (PHP-owned), current highlighted by status. */
    machine: { type: Array, default: () => [] },
    /** { act:{act_number,href,enacted_at}, scope_text, vote: VoteTallyProps|null } | null. */
    delegation: { type: Object, default: null },
    /** { subjectLabel, act, legislatureVote: VoteTallyProps|null, process: ConstituentConsentPanelProps|null } | null. */
    conversion: { type: Object, default: null },
    /** ex-officio / elected member rows. */
    members: { type: Array, default: () => [] },
    /** { cards:[DepartmentCardProps], total, href }. */
    departmentsSummary: { type: Object, default: () => ({ cards: [], total: 0, href: '' }) },
    can: { type: Object, default: () => ({ proposeDelegationBill: false, proposeConversionBill: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* ---------------------------------------------------- live model mode --- */
const status = computed(() => props.executive.status);
const isForming = computed(() => status.value === 'forming');
const isDelegated = computed(() => status.value === 'delegated');
const isElected = computed(() => status.value === 'elected');
const isIndividual = computed(() => props.executive.type === 'individual');

const principals = computed(() => props.members.filter((m) => m.role === 'principal'));
const advisors = computed(() =>
    props.members.filter((m) => m.role === 'advisor').slice().sort((a, b) => a.rank - b.rank),
);

/* Institution proposals use their existing constitutional handlers. */
const conversionDeepLink = computed(
    () => props.executive.legislature ? `/legislatures/${props.executive.legislature.id}/institution-acts?action=elect-executive` : null,
);
const delegationDeepLink = computed(
    () => props.executive.legislature ? `/legislatures/${props.executive.legislature.id}/institution-acts?action=delegate-executive` : null,
);

const delegationForm = computed(() => props.surface.forms?.find((f) => f.id === 'F-LEG-014') ?? null);
const conversionForm = computed(() => props.surface.forms?.find((f) => f.id === 'F-LEG-015') ?? null);

const TYPE_LABELS = { committee: t('c_institutions.executive_home.type_committee', 'Committee'), individual: t('c_institutions.executive_home.type_individual', 'Individual') };
const typeLabel = computed(() => TYPE_LABELS[props.executive.type] ?? props.executive.type);
</script>

<template>
    <PageScaffold
        :surface="surface"
        :title="executive.jurisdiction ? t('c_institutions.executive_home.page_title', { name: executive.jurisdiction.name }) : t('c_institutions.executive_home.page_title_fallback', 'Executive')"
    >
        <template #intro>
            {{ t('c_institutions.executive_home.intro', "The executive is the government's doing arm — it carries out the laws, runs the departments, and answers to the legislature that created it. Every executive starts as a committee delegated by the legislature. A supermajority — plus a supermajority of the places that make it up, where they exist — can convert it to a directly elected office, as either a committee of five or more equal officers, or a single individual with four advisors. Executive terms always equal the legislative term.") }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ===================================== header links ========== -->
        <div class="cluster">
            <Link v-if="executive.jurisdiction" :href="executive.jurisdiction.href">{{ executive.jurisdiction.name }} →</Link>
            <Link v-if="executive.legislature" :href="executive.legislature.chamber_href">
                {{ executive.legislature.name }} →
            </Link>
            <Link :href="departmentsSummary.href">{{ t('c_institutions.executive_home.departments_link', 'Departments →') }}</Link>
            <Link :href="`/executives/${executive.id}/actions`">{{ t('c_institutions.executive_home.orders_link', 'Orders & actions →') }}</Link>
        </div>

        <!-- ===================================== hardened cluster ====== -->
        <Card as="section" :title="t('c_institutions.executive_home.constituted_title', 'How an executive is constituted')">
            <p class="cluster" style="gap: var(--space-2)">
                <TagChip data-no-i18n>{{ typeLabel }}</TagChip>
                <StatusBadge :tone="isElected ? 'success' : isDelegated ? 'info' : 'neutral'" icon="landmark">
                    {{ status }}
                </StatusBadge>
            </p>
            <p style="margin-block-start: var(--space-2)">
                <HardenedChip>{{ t('c_institutions.executive_home.constituted_chip', 'delegated by default · converts only by dual supermajority · Art. III §2–3') }}</HardenedChip>
            </p>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.executive_home.constituted_gloss', "Conversion needs the chamber's own supermajority — ceil(serving × 2/3) — AND a supermajority of the constituent jurisdictions, each counted independently. Both meters resolve through the protected functions; nothing on this page is computed client-side.") }}
            </p>
        </Card>

        <!-- ===================================== the live model ======== -->
        <Card as="section" :title="t('c_institutions.executive_home.office_today_title', 'The office today')">
            <!-- forming: honest empty state -->
            <Banner
                v-if="isForming"
                tone="info"
                role="status"
                :title="t('c_institutions.executive_home.forming_title', 'Forming — the stub awaits the delegation act.')"
            >
                {{ t('c_institutions.executive_home.forming_before', 'This executive was created as a stub at jurisdiction setup. It holds no members until the legislature delegates authority to it by supermajority act (') }}<FormChip form-id="F-LEG-014" /> {{ t('c_institutions.executive_home.forming_after', '· WF-EXE-01). Until then, the office exists only as a constitutional placeholder.') }}
            </Banner>

            <!-- delegated: Westminster panel of ex-officio legislators -->
            <template v-else-if="isDelegated">
                <p class="cluster" style="gap: var(--space-3); align-items: flex-start">
                    <Stat :value="members.length" :label="t('c_institutions.executive_home.delegated_members', 'delegated members')" />
                    <Stat
                        v-if="executive.member_count != null"
                        :value="executive.member_count"
                        :label="t('c_institutions.executive_home.act_fixed_size', 'act-fixed committee size')"
                    />
                </p>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    {{ t('c_institutions.executive_home.delegated_gloss', "Selected proportionally — the same method as legislative committees · Art. III §2 · ledger #q2. Delegated members are ex-officio legislators: each remains a seated member of the chamber, and the office's authority is the chamber's.") }}
                </p>
                <div class="stack" style="gap: var(--space-2); margin-block-start: var(--space-3)">
                    <div v-for="member in principals" :key="member.id" class="card card--inset">
                        <p class="cluster" style="gap: var(--space-2)">
                            <strong style="color: var(--gov-fg)">{{ member.name }}</strong>
                            <StatusBadge tone="info" icon="landmark">{{ t('c_institutions.executive_home.principal_equal', 'principal · equal power') }}</StatusBadge>
                        </p>
                        <p class="citation" style="margin: 0">
                            <template v-if="member.legislature_member">
                                {{ t('c_institutions.executive_home.remains_legislator', 'Remains a seated legislator') }}
                                <template v-if="member.legislature_member.seat_no != null">
                                    {{ t('c_institutions.executive_home.seat_no', { n: member.legislature_member.seat_no }) }}
                                </template>
                                · <Link :href="member.legislature_member.href">{{ t('c_institutions.executive_home.chamber_record', 'chamber record →') }}</Link>
                            </template>
                            <template v-else>{{ t('c_institutions.executive_home.ex_officio', 'ex-officio member · selection #q2') }}</template>
                        </p>
                    </div>
                </div>
            </template>

            <!-- elected individual: principal + ranked advisors -->
            <template v-else-if="isElected && isIndividual">
                <div v-for="principal in principals" :key="principal.id" class="card card--inset">
                    <p class="cluster" style="gap: var(--space-2)">
                        <strong style="color: var(--gov-fg)">{{ principal.name }}</strong>
                        <StatusBadge tone="success" icon="check">{{ t('c_institutions.executive_home.elected_principal', 'elected · principal office') }}</StatusBadge>
                    </p>
                    <p v-if="principal.endorsements.length" class="cluster" style="gap: var(--space-1); margin: 0">
                        <OrgChip
                            v-for="org in principal.endorsements"
                            :key="org.name"
                            :name="org.name"
                            :org-type="org.org_type"
                        />
                    </p>
                    <p class="citation" style="margin: 0">
                        <Link v-if="principal.elected_in_race" :href="principal.elected_in_race.href">{{ t('c_institutions.executive_home.election_record', 'election record →') }}</Link>
                    </p>
                </div>

                <h3 v-if="advisors.length" style="font-size: var(--text-base); margin-block-start: var(--space-3)">
                    {{ t('c_institutions.executive_home.advisory_heading', 'Advisory') }}
                </h3>
                <p v-if="advisors.length" class="gloss">
                    {{ t('c_institutions.executive_home.advisory_gloss', 'Steps in, in rank order, if the office vacates — the popular count, not an appointment, decides succession (sequential exclusion · Art. III §3).') }}
                </p>
                <div class="stack" style="gap: var(--space-2)">
                    <div v-for="advisor in advisors" :key="advisor.id" class="card card--inset">
                        <p class="cluster" style="gap: var(--space-2)">
                            <strong style="color: var(--gov-fg)">{{ advisor.name }}</strong>
                            <StatusBadge tone="info" icon="users">{{ t('c_institutions.executive_home.rank_n', { n: advisor.rank }) }}</StatusBadge>
                        </p>
                        <p v-if="advisor.endorsements.length" class="cluster" style="gap: var(--space-1); margin: 0">
                            <OrgChip
                                v-for="org in advisor.endorsements"
                                :key="org.name"
                                :name="org.name"
                                :org-type="org.org_type"
                            />
                        </p>
                    </div>
                </div>
            </template>

            <!-- elected committee: equal-power officers -->
            <template v-else-if="isElected">
                <p class="gloss">
                    {{ t('c_institutions.executive_home.elected_committee_gloss', 'Elected committee · PR-STV winners with equal decision-making power · Art. III §2.') }}
                </p>
                <div class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <div v-for="member in principals" :key="member.id" class="card card--inset">
                        <p class="cluster" style="gap: var(--space-2)">
                            <strong style="color: var(--gov-fg)">{{ member.name }}</strong>
                            <StatusBadge tone="success" icon="check">{{ t('c_institutions.executive_home.officer_equal', 'officer · equal power') }}</StatusBadge>
                            <Link v-if="member.elected_in_race" :href="member.elected_in_race.href" class="citation">
                                {{ t('c_institutions.executive_home.election_record', 'election record →') }}
                            </Link>
                        </p>
                        <p v-if="member.endorsements.length" class="cluster" style="gap: var(--space-1); margin: 0">
                            <OrgChip
                                v-for="org in member.endorsements"
                                :key="org.name"
                                :name="org.name"
                                :org-type="org.org_type"
                            />
                        </p>
                    </div>
                </div>
            </template>

            <!-- transitional / terminal states (conversion_voted, dissolved, reverted) -->
            <Banner v-else tone="info" role="status" :title="t('c_institutions.executive_home.office_status_title', { status })">
                {{ t('c_institutions.executive_home.transitional_body', 'The office is in a transitional state — see the conversion record below for where it stands in the dual-supermajority process.') }}
            </Banner>

            <!-- ESM-16 state strip -->
            <div style="margin-block-start: var(--space-3)">
                <span class="eyebrow">{{ t('c_institutions.executive_home.lifecycle_eyebrow', 'Executive office lifecycle · ESM-16') }}</span>
                <StateStrip :states="machine" :current="status" />
            </div>
        </Card>

        <!-- ===================================== creation act ========== -->
        <Card as="section" :title="t('c_institutions.executive_home.creation_title', 'Creation act — delegation by the legislature')">
            <template v-if="delegation">
                <p class="cluster" style="gap: var(--space-2)">
                    <FormChip form-id="F-LEG-014" />
                    <a class="tag-chip" :href="delegation.act.href" data-no-i18n>{{ delegation.act.act_number }}</a>
                    <span v-if="delegation.act.enacted_at" class="citation">{{ t('c_institutions.executive_home.enacted_at', { at: delegation.act.enacted_at }) }}</span>
                </p>
                <p v-if="delegation.scope_text" class="gloss" style="margin-block-start: var(--space-2)">
                    {{ t('c_institutions.executive_home.delegated_scope', 'Delegated scope:') }} {{ delegation.scope_text }}
                </p>
                <div v-if="delegation.vote" style="margin-block-start: var(--space-3)">
                    <span class="eyebrow">{{ t('c_institutions.executive_home.delegated_super_eyebrow', 'The supermajority that delegated the office') }}</span>
                    <div style="margin-block-start: var(--space-2)">
                        <VoteTally
                            :mode="delegation.vote.mode"
                            :stage="delegation.vote.stage"
                            :threshold-class="delegation.vote.thresholdClass"
                            :serving="delegation.vote.serving"
                            :required-yes="delegation.vote.requiredYes"
                            :tallies="delegation.vote.tallies"
                            :quorum="delegation.vote.quorum"
                            :kinds="delegation.vote.kinds"
                            :outcome="delegation.vote.outcome"
                            :speaker-tiebreak="delegation.vote.speakerTiebreak"
                        />
                    </div>
                </div>
            </template>
            <template v-else>
                <p class="gloss">
                    {{ t('c_institutions.executive_home.no_delegation', 'No delegation act on record. The legislature delegates executive authority by supermajority act — members remain seated legislators (Art. III §2).') }}
                </p>
                <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <FormChip
                        v-if="delegationForm"
                        form-id="F-LEG-014"
                        :name="delegationForm.name"
                        :alias="delegationForm.alias"
                    />
                    <Link v-if="delegationDeepLink" :href="delegationDeepLink">
                        {{ t('c_institutions.executive_home.propose_delegation', 'Propose executive delegation →') }}
                    </Link>
                    <span v-else class="citation">{{ t('c_institutions.executive_home.open_legislature', 'Open the jurisdiction’s legislature to propose its institution act.') }}</span>
                </p>
            </template>
        </Card>

        <!-- ===================================== conversion act ======== -->
        <Card as="section" :title="t('c_institutions.executive_home.conversion_title', 'Conversion to elected office — dual supermajority')">
            <!-- live or historical conversion process -->
            <ConstituentConsentPanel
                v-if="conversion && conversion.process"
                :legislature-vote="conversion.legislatureVote"
                :legislature-label="executive.legislature?.name ?? t('c_institutions.executive_home.the_legislature', 'The legislature')"
                :process="conversion.process"
                :subject-label="conversion.subjectLabel"
                :basis="t('c_institutions.executive_home.conversion_basis', 'Art. III §3 · Art. VII')"
            />

            <!-- conversion adopted, but no constituent process (no constituents to consent) -->
            <template v-else-if="conversion">
                <p class="cluster" style="gap: var(--space-2)">
                    <FormChip form-id="F-LEG-015" />
                    <a class="tag-chip" :href="conversion.act.href" data-no-i18n>{{ conversion.act.act_number }}</a>
                </p>
                <Banner tone="info" role="status" :title="t('c_institutions.executive_home.conversion_alone_title', 'Conversion adopted on the chamber supermajority alone')">
                    {{ t('c_institutions.executive_home.conversion_alone_body', "No direct constituent jurisdiction holds a legislature able to vote, so the conversion completes on the chamber's own supermajority (Art. III §3). The executive election schedules from here.") }}
                </Banner>
                <div v-if="conversion.legislatureVote" style="margin-block-start: var(--space-3)">
                    <span class="eyebrow">{{ t('c_institutions.executive_home.chamber_super_eyebrow', 'The chamber supermajority') }}</span>
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

            <!-- no conversion: the F-LEG-015 reference + deep-link -->
            <template v-else>
                <p class="gloss">
                    {{ t('c_institutions.executive_home.no_conversion', "No conversion on record. Converting a delegated executive into a directly elected office is a dual-supermajority act — the chamber's own supermajority AND a supermajority of the constituent jurisdictions, each counted independently (Art. III §3 · Art. VII).") }}
                </p>
                <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <FormChip
                        v-if="conversionForm"
                        form-id="F-LEG-015"
                        :name="conversionForm.name"
                        :alias="conversionForm.alias"
                    />
                    <Link v-if="conversionDeepLink" :href="conversionDeepLink">
                        {{ t('c_institutions.executive_home.propose_elected', 'Propose an elected executive →') }}
                    </Link>
                    <span v-else class="citation">{{ t('c_institutions.executive_home.open_legislature', 'Open the jurisdiction’s legislature to propose its institution act.') }}</span>
                </p>
                <p class="citation" style="margin-block-start: var(--space-1)">
                    {{ t('c_institutions.executive_home.workspace_note', 'The institution-act workspace holds the proposal vote and any constituent decisions.') }}
                </p>
            </template>
        </Card>

        <!-- ===================================== term lockstep ========= -->
        <Card as="section" :title="t('c_institutions.executive_home.term_title', 'Term — one clock drives both elections')">
            <Stat
                :value="executive.term.days_remaining ?? '—'"
                :label="executive.term.ends_on
                    ? t('c_institutions.executive_home.term_days', { date: executive.term.ends_on })
                    : t('c_institutions.executive_home.term_none', 'no office term — delegated members run on their legislative seats')"
            />
            <p style="margin-block-start: var(--space-2)">
                <HardenedChip>{{ t('c_institutions.executive_home.term_chip', 'one clock drives both elections · Art. III §3 · CLK-10') }}</HardenedChip>
            </p>
            <p class="cc-small" style="margin-block-start: var(--space-2)">
                <template v-if="isDelegated">
                    {{ t('c_institutions.executive_home.term_delegated', "A delegated committee carries no term of its own: each member's term IS their legislative seat's term (ESM-16). The office never outlives the chamber that delegated it.") }}
                </template>
                <template v-else>
                    {{ t('c_institutions.executive_home.term_elected', 'An elected executive runs in lockstep with the legislative election — the two elections share one clock, and neither can be skipped or delayed.') }}
                </template>
            </p>
            <p v-if="executive.legislature" class="citation" style="margin-block-start: var(--space-1)">
                <Link :href="executive.legislature.chamber_href">{{ t('c_institutions.executive_home.term_sync_link', 'term sync on the chamber page →') }}</Link>
            </p>
        </Card>

        <!-- ===================================== departments ========== -->
        <Card as="section" :title="t('c_institutions.executive_home.departments_title', 'Departments')">
            <p v-if="executive.legislature"><Link :href="`/legislatures/${executive.legislature.id}/institution-acts?action=create-department`">{{ t('c_institutions.executive_home.propose_department', 'Propose a department →') }}</Link></p>
            <template v-if="departmentsSummary.cards.length">
                <div class="grid-2">
                    <DepartmentCard
                        v-for="dept in departmentsSummary.cards"
                        :key="dept.id"
                        :department="dept"
                    />
                </div>
                <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-3)">
                    <Link :href="departmentsSummary.href">
                        {{ t('c_institutions.executive_home.all_departments', { count: departmentsSummary.total, s: departmentsSummary.total === 1 ? '' : 's' }) }}
                    </Link>
                </p>
            </template>
            <p v-else class="gloss">
                {{ t('c_institutions.executive_home.no_departments', 'No departments chartered yet. Departments are created by the legislature by ordinary act (F-LEG-016 · Art. II §9); oversight is assigned to this executive in the act.') }}
                <Link :href="departmentsSummary.href">{{ t('c_institutions.executive_home.open_registry', 'Open the department registry →') }}</Link>
            </p>
        </Card>

        <template #about>
            <p>
                {{ t('c_institutions.executive_home.about_body', "The executive is delegated by default and exists as a stub from the moment a jurisdiction is set up. Conversion to a directly elected office is the only path to an independent executive, and it is deliberately hard: a supermajority of the chamber AND a supermajority of the constituent jurisdictions must each agree. The model card above always shows the office's real footing — never a demo toggle.") }}
            </p>
        </template>
    </PageScaffold>
</template>
