<script setup>
/**
 * System/ConstitutionalQuestions — the maintained ledger of open "why"
 * questions (design contract: mockups/v3/shared/constitutional-questions.html).
 *
 * Surface id `shared/constitutional-questions` (module `shared`) — matches the
 * mockup CGA_PAGE id and the authored Learn copy in registry/education.js.
 * Routed at /system/constitutional-questions (public read): every
 * `· as implemented` marker across the app resolves to an anchor #q1..#q7 on
 * this page (CitationLine's `anchor` prop), and citations can render before
 * sign-in, so this document must be reachable without a session.
 *
 * READ-ONLY. Each entry records the constitutional text touched, what the
 * build surfaced, the answer the build chose, and where to see it working.
 * The anchor ids q1..q7 are a CONTRACT — never renumber or remove them.
 *
 * FIDELITY NOTE (lane 6, 2026-07-29): entry #4's mockup copy described
 * districting as "Webster-apportioned … Webster-rounded seats." That
 * contradicts SETTLED law — CLAUDE.md § Apportionment Law: there is NO
 * Webster, Sainte-Laguë or largest-remainder method anywhere in seat
 * allocation. #4 here states the actual giant-cascade doctrine (drawn
 * districts round to NEAREST, exactness over drift). The mockup carries the
 * stale wording — flagged to the desk for a spec-side fix (plan §9).
 */
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

defineProps({
    surface: { type: Object, required: true },
});
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_system.constitutional_questions.intro_a', 'Places where building the game forced a decision the constitution\'s text didn\'t make. This is the home of the open "why" debates — each entry records the question, the answer the build chose, and where to see it working. Every') }}
            <span class="citation">{{ t('c_system.constitutional_questions.as_implemented', '· as implemented') }}</span> {{ t('c_system.constitutional_questions.intro_b', 'marker across the app links here, and the whole list seeds the next draft of') }} <em>{{ t('c_system.constitutional_questions.fair_constitution', 'A Fair Constitution') }}</em>.
        </template>

        <template #about>
            <p>
                {{ t('c_system.constitutional_questions.about_a', 'A margin note beside the amendment surface, not a second amendment door. Amendments to the running system go through the two doors on') }}
                <Link href="/system/amendments">{{ t('c_system.constitutional_questions.amendments_page_link', 'the amendments page') }}</Link>{{ t('c_system.constitutional_questions.about_b', '; this ledger records questions the constitutional text under-determined and the answers the build settled on (WF-SYS-05).') }}
            </p>
        </template>

        <p class="citation">
            {{ t('c_system.constitutional_questions.cross_links', 'Cross-links:') }} <Link href="/system/amendments">{{ t('c_system.constitutional_questions.amendment_surface_link', 'the amendment surface') }}</Link>
        </p>

        <Banner tone="info" :title="t('c_system.constitutional_questions.ledger_title', 'Maintained ledger')">
            {{ t('c_system.constitutional_questions.ledger_body', 'Entries are appended as the build surfaces new questions; existing entries are only ever extended, never rewritten. Each entry records the constitutional text touched, what the attempt to build it surfaced, the implemented answer, and a status badge tracking it toward the post-game redraft.') }}
        </Banner>

        <!-- ══════════════════════════════════════════════════════ q1 ══ -->
        <section class="card" id="q1" aria-labelledby="q1-h">
            <h2 id="q1-h">{{ t('c_system.constitutional_questions.q1_h', '1 · Factions → direct endorsements') }}</h2>
            <p class="citation">
                {{ t('c_system.constitutional_questions.q1_touches', 'Touches: proportional voting systems · election security and integrity · committees · composition and selection of executive committees') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_surfaced', 'What the build surfaced:') }}</strong> {{ t('c_system.constitutional_questions.q1_surfaced', 'the running experiment removed the faction layer entirely. STV satisfies factional-makeup matching at the individual-preference level with no party layer; observer/audit standing transfers to endorsing organizations and candidates.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_answer', 'Implemented answer:') }}</strong> {{ t('c_system.constitutional_questions.q1_answer_a', 'a universal organizations model') }}
                (<code data-no-i18n>political_party | business | nonprofit | common_good_corp | informal</code>)
                {{ t('c_system.constitutional_questions.q1_answer_b', 'plus direct endorsements — any organization') }} <em>{{ t('c_system.constitutional_questions.or_individual', 'or individual') }}</em> {{ t('c_system.constitutional_questions.q1_answer_c', 'can endorse any candidate; members with no endorsing organization stand on equal footing.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_where', 'Where to see it:') }}</strong> {{ t('c_system.constitutional_questions.q1_where', 'the open ballot filters by endorsing organization or individual endorsements with no party column anywhere; the committee surfaces show proportionality staying legible through endorsements alone. Observer and audit standing during counts transfers to endorsing organizations and the candidates themselves.') }}
            </p>
            <p>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
            </p>
        </section>

        <!-- ══════════════════════════════════════════════════════ q2 ══ -->
        <section class="card" id="q2" aria-labelledby="q2-h">
            <h2 id="q2-h">{{ t('c_system.constitutional_questions.q2_h', '2 · Committee tie-break — normalized-quota vote share') }}</h2>
            <p class="citation">{{ t('c_system.constitutional_questions.q2_touches', 'Touches: the committee seat assignment process') }}</p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_surfaced', 'What the build surfaced:') }}</strong> {{ t('c_system.constitutional_questions.q2_surfaced', 'the constitutional tie-break ("each member\'s own 1st-choice vote performance in the prior election") assumes raw 1st-choice counts are comparable — but STV quota transfers make them incommensurable across winners.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_answer', 'Implemented answer:') }}</strong> {{ t('c_system.constitutional_questions.q2_answer', 'ties go to the seat holder with the largest share of votes after normalizing quotas — preserving the proportionality the STV election produced and one-person-one-vote, with no faction layer.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_where', 'Where to see it:') }}</strong> {{ t('c_system.constitutional_questions.q2_where', 'the committee assignment run — each member\'s normalized vote share is displayed beside the tie-break so the math is auditable on sight.') }}
            </p>
            <p>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
            </p>
        </section>

        <!-- ══════════════════════════════════════════════════════ q3 ══ -->
        <section class="card" id="q3" aria-labelledby="q3-h">
            <h2 id="q3-h">{{ t('c_system.constitutional_questions.q3_h', '3 · Legislature sizing — the cube-root law') }}</h2>
            <p class="citation">{{ t('c_system.constitutional_questions.q3_touches', 'Touches: independent election boards (min 5 / max 9 seats per district + subdivision)') }}</p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_surfaced', 'What the build surfaced:') }}</strong> {{ t('c_system.constitutional_questions.q3_surfaced', 'the constitution bounds a district (5–9 seats) but is silent on how many population-apportioned seats a parent legislature should have in total.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_answer', 'Implemented answer:') }}</strong> {{ t('c_system.constitutional_questions.q3_answer_a', 'a legislature sizing law (v1: cube-root only) — total seats =') }} <code data-no-i18n>max(5, round(pop^(1/3)))</code> {{ t('c_system.constitutional_questions.q3_answer_b', 'over the summed direct-children population, with no ceiling on the total. Future alternative laws reserved.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_where', 'Where to see it:') }}</strong> {{ t('c_system.constitutional_questions.q3_where', 'the district mapper derives every seat budget from the sizing law and displays the quota (population ÷ seats) beside it. At Earth scale the law yields 1,999 population-apportioned seats across 282 districts — a number the constitutional text never had to confront.') }}
            </p>
            <p>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
            </p>
        </section>

        <!-- ══════════════════════════════════════════════════════ q4 ══
             CORRECTED from the mockup: the mockup said "Webster-apportioned …
             Webster-rounded seats." SETTLED law (CLAUDE.md § Apportionment)
             forbids every textbook method. #4 states the giant-cascade
             doctrine instead. Mockup flagged to the desk (plan §9). -->
        <section class="card" id="q4" aria-labelledby="q4-h">
            <h2 id="q4-h">{{ t('c_system.constitutional_questions.q4_h', '4 · Districting — composites of child jurisdictions') }}</h2>
            <p class="citation">
                {{ t('c_system.constitutional_questions.q4_touches', 'Touches: independent election boards ("drawn equally, contiguously, and fairly") · subdivision of legislatures') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_surfaced', 'What the build surfaced:') }}</strong> {{ t('c_system.constitutional_questions.q4_surfaced', 'free-form line-drawing fights community integrity and auditability — and no textbook apportionment method (Webster, Sainte-Laguë, largest-remainder) keeps seat counts faithful to a chamber size that the cube-root law fixes exactly.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_answer', 'Implemented answer:') }}</strong> {{ t('c_system.constitutional_questions.q4_answer_a', 'districts are composites of direct child jurisdictions, each within the constitutional 5–9 seat range, built from real jurisdictional lines — never free-form geometry, and never a textbook method. Seats flow from a giant-cascade allocation: the chamber\'s cube-root total splits to children by population share (with the children\'s own sum as the denominator); a child whose share would round past the ceiling locks to its nearest whole at once, and its budget redistributes among the rest, layer by layer. Drawn districts then round to the') }} <em>{{ t('c_system.constitutional_questions.nearest', 'nearest') }}</em> {{ t('c_system.constitutional_questions.q4_answer_b', 'whole independently — the chamber size is fixed, so a drawing whose seats miss the pool budget is a defect to be redrawn, never a rounding to be forced. Where a jurisdiction exceeds the ceiling with no child subdivisions in the dataset, district lines must be drawn manually — the open frontier.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_where', 'Where to see it:') }}</strong> {{ t('c_system.constitutional_questions.q4_where', 'the district mapper renders draft / active / archived plans with nearest-rounded seats, contiguity and integrity indicators, and the optimal / suboptimal / current grouping stats; election-board observation runs through the board console. There is no Webster or Sainte-Laguë step anywhere in the allocation.') }}
            </p>
            <p>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
            </p>
        </section>

        <!-- ══════════════════════════════════════════════════════ q5 ══ -->
        <section class="card" id="q5" aria-labelledby="q5-h">
            <h2 id="q5-h">{{ t('c_system.constitutional_questions.q5_h', '5 · Open-ballot two-phase elections') }}</h2>
            <p class="citation">{{ t('c_system.constitutional_questions.q5_touches', 'Touches: the right to vote and stand for election · the right to stand for office') }}</p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_surfaced', 'What the build surfaced:') }}</strong> {{ t('c_system.constitutional_questions.q5_surfaced', 'a continuous, filterable approval phase (opening the moment the prior election certifies) with a finalist line (top X per race, scaled to the seat count) makes the right-to-stand guarantee operational at planetary candidate counts — while write-in of any validated candidate preserves the right absolutely.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_answer', 'Implemented answer:') }}</strong> {{ t('c_system.constitutional_questions.q5_answer', 'two-phase elections: continuous approval → finalist cutoff → time-boxed ranked window → one-count PR-STV (Droop quota, Gregory fractional transfers) filling all seats per district.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_where', 'Where to see it:') }}</strong> {{ t('c_system.constitutional_questions.q5_where', 'the open ballot (the finalist line, revocable approvals, jockeying deltas) and the ranked ballot with its always-available write-in of any validated candidate.') }}
            </p>
            <p>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
            </p>
        </section>

        <!-- ══════════════════════════════════════════════════════ q6 ══ -->
        <section class="card" id="q6" aria-labelledby="q6-h">
            <h2 id="q6-h">{{ t('c_system.constitutional_questions.q6_h', '6 · Countback without factions') }}</h2>
            <p class="citation">{{ t('c_system.constitutional_questions.q6_touches', 'Touches: the countback procedure for filling vacancies') }}</p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_surfaced', 'What the build surfaced:') }}</strong> {{ t('c_system.constitutional_questions.q6_surfaced', 'the countback procedure leaned on the faction layer — and with factions replaced by polymorphic endorsements (entry #1), the faction-scoped parts of the procedure no longer mean anything.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_answer', 'Implemented answer:') }}</strong> {{ t('c_system.constitutional_questions.q6_answer', 'countback runs universally — the prior election\'s ballots are re-run with the vacated member removed as a candidate, with no faction filtering anywhere in the procedure. Failure still falls through to a special election within 90–180 days.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_where', 'Where to see it:') }}</strong> {{ t('c_system.constitutional_questions.q6_where', 'the countback engine view and its flow walkthrough.') }}
            </p>
            <p>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
            </p>
        </section>

        <!-- ══════════════════════════════════════════════════════ q7 ══ -->
        <section class="card" id="q7" aria-labelledby="q7-h">
            <h2 id="q7-h">{{ t('c_system.constitutional_questions.q7_h', '7 · Bicameral dual agreement — the per-kind threshold') }}</h2>
            <p class="citation">
                {{ t('c_system.constitutional_questions.q7_touches', 'Touches: independent agreement for legislative actions · pegging the quorum requirement to full membership') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_surfaced', 'What the build surfaced:') }}</strong> {{ t('c_system.constitutional_questions.q7_surfaced', 'the rule requires both kinds of members to agree independently but names no per-kind threshold or quorum. The intent is to preserve the equal-parts and population-proportional parts over the existing boundaries of the nation-state system, with dual agreement required.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_answer', 'Implemented answer:') }}</strong> {{ t('c_system.constitutional_questions.q7_answer', 'each kind must meet its own peg quorum and pass independently — a majority of ALL serving members of that kind (supermajority where the act type requires it). Needs resolving in the constitutional text.') }}
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.label_where', 'Where to see it:') }}</strong> {{ t('c_system.constitutional_questions.q7_where', 'the dual-agreement meters on bill detail (bicameral toggle).') }}
            </p>
            <p>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
            </p>
        </section>

        <!-- ─────────────────────────────────────── how an entry lands ── -->
        <Card as="section" :title="t('c_system.constitutional_questions.how_lands_title', 'How an entry lands here')">
            <p>
                {{ t('c_system.constitutional_questions.how_lands_a', 'A question qualifies when the constitutional text under-determines a mechanism the software must decide — and the build picks an answer. The entry names only the part of the text touched, states the implemented answer in one paragraph, and describes the surfaces where the answer is visible. Every') }}
                <span class="citation">{{ t('c_system.constitutional_questions.as_implemented', '· as implemented') }}</span> {{ t('c_system.constitutional_questions.how_lands_b', 'marker elsewhere in the app resolves to its anchor here') }} (<span class="citation" data-no-i18n>#q1–#q7</span>).
            </p>
            <p>
                <strong>{{ t('c_system.constitutional_questions.status_legend_label', 'Status legend:') }}</strong>
                <StatusBadge tone="warning" icon="clock">{{ t('c_system.constitutional_questions.candidate_next_draft', 'Candidate for next draft') }}</StatusBadge>
                {{ t('c_system.constitutional_questions.status_legend_a', '— proposed for the post-game redraft of') }} <em>{{ t('c_system.constitutional_questions.fair_constitution', 'A Fair Constitution') }}</em> {{ t('c_system.constitutional_questions.status_legend_b', 'and the workbooks. Entries adopted by a redraft will be re-badged rather than removed, preserving the question\'s history.') }}
            </p>
            <p>
                <a href="https://cosmopolitancoalition.org/cosmopolitan-template/" rel="external noopener">
                    {{ t('c_system.constitutional_questions.read_template', 'Read the Template →') }}
                </a>
                {{ t('c_system.constitutional_questions.read_template_after', '— the source text this ledger seeds the redraft of.') }}
            </p>
            <p class="citation">
                {{ t('c_system.constitutional_questions.cross_links', 'Cross-links:') }} <Link href="/system/amendments">{{ t('c_system.constitutional_questions.amendment_surface_link', 'the amendment surface') }}</Link>
            </p>
        </Card>
    </PageScaffold>
</template>
