<script setup>
/**
 * Dev/JudiciaryKit — FE-E1 fixture-first harness (/dev/judiciary-kit,
 * dev-gated). Renders the Phase E judiciary component kit in every state
 * from resources/js/fixtures/judiciary.json (mockup-extracted; see the
 * file's `_source` provenance). NOT product UI — the per-page WIs
 * (FE-E2…E6) wire these against real engine-snapshotted props.
 *
 * Reference mockups: mockups/judiciary/{judiciary-home,case-docket,
 * case-detail,constitutional-challenge,advocate-console,juror-view}.html.
 *
 * The §A.0 finding: zero new CSS — the judiciary frontend is pure
 * composition over the Phase A–D kit. Everything below draws from already
 * ported classes.
 */
import { useI18n } from 'vue-i18n';
import AppShell from '@/Layouts/AppShell.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import ConstituentConsentPanel from '@/Components/Legislature/ConstituentConsentPanel.vue';
import PanelTable from '@/Components/Judiciary/PanelTable.vue';
import CaseLifecycle from '@/Components/Judiciary/CaseLifecycle.vue';
import Art4Section5Tracker from '@/Components/Judiciary/Art4Section5Tracker.vue';
import JurorScreening from '@/Components/Judiciary/JurorScreening.vue';
import fixtures from '@/fixtures/judiciary.json';

/* KEEP-class (V3 synthesis S1): pinned to the v1 shell when AppShellV2 became
   the app-wide default — dev harness, not product UI; zero change intended. */
defineOptions({ layout: AppShell });

defineProps({ surface: { type: Object, default: null } });

const { t } = useI18n();

const M = fixtures.machines;
const P = fixtures.panel;
const C = fixtures.case;
const CH = fixtures.challenge;
const J = fixtures.juror;
const CONV = fixtures.conversion;

/* --------------------------------------------------- PanelTable pins ---- */
/* The §A.1 unit contract: panelSize / isFullCourt are ENGINE snapshots, never
   derived from severity. The pinFullCourtFromMajor fixture feeds a 'major'
   severity with panelSize=3 — the row below proves the component honors the
   snapshot (renders 3) rather than computing a full court from the severity. */
const panelPins = [
    { label: "serious → 3 (snapshot)", size: P.serious.panelSize, full: P.serious.isFullCourt, expectedSize: 3, expectedFull: false },
    { label: "constitutional_major → 5, full court (snapshot)", size: P.fullCourt.panelSize, full: P.fullCourt.isFullCourt, expectedSize: 5, expectedFull: true },
    { label: "major severity BUT panelSize=3 snapshot → renders 3, NOT full court", size: P.pinFullCourtFromMajor.panelSize, full: P.pinFullCourtFromMajor.isFullCourt, expectedSize: 3, expectedFull: false },
];
const panelPinsGreen = panelPins.every((p) => p.size === p.expectedSize && p.full === p.expectedFull);

/* ------------------------------------------- Art4Section5Tracker pin ---- */
/* requiredOverride feeds override.required=99 against serving=9 — the right
   caption must display 99, proving the tracker performs no client ceil(). */
const overridePin = {
    fed: CH.requiredOverride.override.required,
    expected: 99,
};
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_operator_pages.judiciary_kit.intro_before', 'FE-E1 harness — the Phase E judiciary components rendered from') }}
            <code data-no-i18n>resources/js/fixtures/judiciary.json</code>
            {{ t('c_operator_pages.judiciary_kit.intro_after', '(mockup-extracted). Dev-gated; not product UI. Every panel size, clock window, and override threshold below is a frozen "server snapshot" — the components never recompute CLK-16 panel sizes or ceil(serving × 2/3) override thresholds.') }}
        </template>

        <Banner tone="demo" :title="t('c_operator_pages.judiciary_kit.fixture_data_only', 'Fixture data only.')">
            {{ t('c_operator_pages.judiciary_kit.fixture_body', "Nothing on this page touches the database — the State v. Whitfield panel, the Novák / Curfew §3 Art. IV §5 tracker, and the New York judiciary conversion are the mockups' fixtures frozen into JSON.") }}
        </Banner>

        <!-- ============================ 1. PanelTable ==================== -->
        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_panel_serious', 'PanelTable — serious case, 3 seated + 1 recused (draw re-runs)')">
            <p class="citation">
                {{ t('c_operator_pages.judiciary_kit.cite_panel_serious', 'Judiciary/PanelTable · panelSize + isFullCourt are ENGINE snapshots (panels.size / .is_en_banc, CLK-16) — never derived from severity · Art. IV §4') }}
            </p>
            <PanelTable
                :seats="P.serious.seats"
                :severity="P.serious.severity"
                :panel-size="P.serious.panelSize"
                :is-full-court="P.serious.isFullCourt"
                :rule="P.serious.rule"
            />
        </Card>

        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_panel_fullcourt', 'PanelTable — full court (major constitutional question)')">
                <PanelTable
                    :seats="P.fullCourt.seats"
                    :severity="P.fullCourt.severity"
                    :panel-size="P.fullCourt.panelSize"
                    :is-full-court="P.fullCourt.isFullCourt"
                    :rule="P.fullCourt.rule"
                />
            </Card>
            <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_panel_pending', 'PanelTable — pending (excluded judge, draw re-runs)')">
                <PanelTable
                    :seats="P.pending.seats"
                    :severity="P.pending.severity"
                    :panel-size="P.pending.panelSize"
                    :is-full-court="P.pending.isFullCourt"
                    :rule="P.pending.rule"
                />
            </Card>
        </div>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_panel_pins', 'PanelTable — engine-snapshot pins (the §A.1 unit contract)')">
            <p class="citation" data-no-i18n>
                panelSize / isFullCourt come from the panels row only — a 'major' severity with
                panelSize=3 still renders 3
            </p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th scope="col">{{ t('c_operator_pages.judiciary_kit.th_case', 'Case') }}</th><th scope="col" class="mono">{{ t('c_operator_pages.judiciary_kit.th_size', 'size') }}</th><th scope="col" class="mono">{{ t('c_operator_pages.judiciary_kit.th_full', 'full') }}</th><th scope="col">{{ t('c_operator_pages.judiciary_kit.th_pin', 'Pin') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="pin in panelPins" :key="pin.label">
                            <td data-no-i18n>{{ pin.label }}</td>
                            <td class="mono" data-no-i18n>{{ pin.size }}</td>
                            <td class="mono" data-no-i18n>{{ pin.full }}</td>
                            <td>
                                <StatusBadge
                                    :tone="pin.size === pin.expectedSize && pin.full === pin.expectedFull ? 'success' : 'danger'"
                                    :icon="pin.size === pin.expectedSize && pin.full === pin.expectedFull ? 'check' : 'x'"
                                >{{ pin.size === pin.expectedSize && pin.full === pin.expectedFull ? t('c_operator_pages.judiciary_kit.pin_holds', 'holds') : t('c_operator_pages.judiciary_kit.pin_broken', 'BROKEN') }}</StatusBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Banner v-if="!panelPinsGreen" tone="emergency" :title="t('c_operator_pages.judiciary_kit.pin_broken_banner', 'Panel pin broken — the component recomputed a size instead of reading the snapshot.')" />
        </Card>

        <!-- ============================ 2. CaseLifecycle ================ -->
        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_case_whitfield', 'CaseLifecycle — State v. Whitfield, stage 6 (jury selection), interactive')">
            <p class="citation">
                {{ t('c_operator_pages.judiciary_kit.cite_case_whitfield', 'Judiciary/CaseLifecycle · the Case ESM StateStrip + the 10-stage ordinal lifecycle · interactive = the dev/demo simulation (OFF in product; the record is append-only)') }}
            </p>
            <CaseLifecycle
                :case="C.whitfield"
                :machine="M.case"
                :stages="fixtures.stages"
                :stage-state-map="fixtures.stageStateMap"
                interactive
            >
                <template #stage-6="{ case: kase }">
                    <Banner tone="info" role="status" :title="t('c_operator_pages.judiciary_kit.stage6_title', 'Random draw complete — voir dire under way')">
                        {{ t('c_operator_pages.judiciary_kit.stage6_body', { court: kase.court.name }) }}
                        <span class="citation" data-no-i18n>Art. IV §4 · WF-JUD-04</span>
                    </Banner>
                </template>
            </CaseLifecycle>
        </Card>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_case_major', 'CaseLifecycle — major constitutional variant (stage 3, full court, no jury), live (non-interactive)')">
            <p class="citation">{{ t('c_operator_pages.judiciary_kit.cite_case_major', 'live record render — no Back/Advance; the strip highlights the resting state') }}</p>
            <CaseLifecycle
                :case="C.major"
                :machine="M.case"
                :stages="fixtures.stages"
                :stage-state-map="fixtures.stageStateMap"
            >
                <template #stage-3>
                    <PanelTable
                        :seats="P.fullCourt.seats"
                        :severity="P.fullCourt.severity"
                        :panel-size="P.fullCourt.panelSize"
                        :is-full-court="P.fullCourt.isFullCourt"
                        :rule="P.fullCourt.rule"
                    />
                </template>
            </CaseLifecycle>
        </Card>

        <!-- ====================== 3. Art4Section5Tracker (EXIT CRITERION) = -->
        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_tracker_open', 'Art4§5Tracker — Novák / Curfew §3, window open (override 4 of 9, needs 6)')">
            <p class="citation">
                {{ t('c_operator_pages.judiciary_kit.cite_tracker_open', 'Judiciary/Art4Section5Tracker · THE exit-criterion component · finding → remedy → window → three paths → direct law edit · every threshold + due date is an engine snapshot · Art. IV §5 · CLK-11 · CLK-12') }}
            </p>
            <Art4Section5Tracker :challenge="CH.windowOpen" :machine="M.constitutional_challenge" />
        </Card>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_tracker_overridden', 'Art4§5Tracker — overridden (Path B: supermajority override recorded)')">
            <Art4Section5Tracker :challenge="CH.overridden" :machine="M.constitutional_challenge" />
        </Card>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_tracker_applied', 'Art4§5Tracker — applied (Path C: judiciary edits the law · the judicial_remedy LawDiff)')">
            <p class="citation">
                {{ t('c_operator_pages.judiciary_kit.cite_tracker_applied', 'the exit-criterion render: LawDiff shows the judicial_remedy version (del/ins) + the preserved-history link (version_no vs prior_version_no)') }}
            </p>
            <Art4Section5Tracker :challenge="CH.applied" :machine="M.constitutional_challenge" />
        </Card>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_tracker_empty', 'Art4§5Tracker — empty state (no open challenge) + F-IND-016 filing card')">
            <Art4Section5Tracker :challenge="null" :machine="M.constitutional_challenge" :file-form="fixtures.fileForm">
                <template #file-fields>
                    <p class="field-hint">{{ t('c_operator_pages.judiciary_kit.file_hint', 'F-IND-016 filing fields render here on the live surface (R-03).') }}</p>
                </template>
            </Art4Section5Tracker>
        </Card>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_tracker_verbatim', 'Art4§5Tracker — override `required` rendered verbatim (the pure-renderer pin)')">
            <p class="citation" data-no-i18n>
                fixture feeds override.required={{ overridePin.fed }} against serving=9 — the caption
                must display {{ overridePin.expected }}, proving the tracker performs no client ceil( )
            </p>
            <Art4Section5Tracker :challenge="CH.requiredOverride" :machine="M.constitutional_challenge" />
        </Card>

        <!-- ============================ 4. JurorScreening =============== -->
        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_juror_screening', 'JurorScreening — the voir-dire questionnaire (No/Yes, default No)')">
            <p class="citation">
                {{ t('c_operator_pages.judiciary_kit.cite_juror_screening', 'Judiciary/JurorScreening · Ui/RadioGroup per question · binds to the R-22 summons holder · answers go to the panel judges only · Art. IV §4') }}
            </p>
            <div class="grid-2">
                <div>
                    <p style="margin-block-end:var(--space-1)"><strong style="color:var(--gov-fg)">{{ t('c_operator_pages.judiciary_kit.label_drawn', 'Drawn:') }}</strong> <span class="citation">{{ J.summons.drawn_at }}</span></p>
                    <p style="margin-block-end:var(--space-1)"><strong style="color:var(--gov-fg)">{{ t('c_operator_pages.judiciary_kit.label_pool', 'Pool:') }}</strong> {{ J.summons.pool_size }}</p>
                    <p style="margin-block-end:var(--space-1)"><strong style="color:var(--gov-fg)">{{ t('c_operator_pages.judiciary_kit.label_draw_integrity', 'Draw integrity:') }}</strong> {{ t('c_operator_pages.judiciary_kit.seed_published_before', 'the seed is published to the') }} <a :href="J.summons.seed_audit_href">{{ t('c_operator_pages.judiciary_kit.audit_chain', 'audit chain') }}</a></p>
                    <p style="margin-block-end:var(--space-1)"><strong style="color:var(--gov-fg)">{{ t('c_operator_pages.judiciary_kit.label_report', 'Report:') }}</strong> <span class="citation">{{ J.summons.report_at }}</span></p>
                    <p><strong style="color:var(--gov-fg)">{{ t('c_operator_pages.judiciary_kit.label_where', 'Where:') }}</strong> {{ J.summons.location }}</p>
                </div>
                <FormCard :form="J.sourceForm"><span /></FormCard>
            </div>
            <hr />
            <JurorScreening :summons="J.summons" :questions="J.questions" :can="J.can" />
        </Card>

        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_juror_clean', 'JurorScreening — clean result branch')">
                <JurorScreening :summons="J.summons" :questions="J.questions" :can="J.can" outcome="clean" />
            </Card>
            <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_juror_flagged', 'JurorScreening — flagged result branch')">
                <JurorScreening :summons="J.summons" :questions="J.questions" :can="J.can" outcome="flagged" />
            </Card>
        </div>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_juror_readonly', 'JurorScreening — read-only (answered / discharged summons)')">
            <p class="citation">{{ t('c_operator_pages.judiciary_kit.cite_juror_readonly', 'answers prop + no submitScreening → the questionnaire renders the recorded answers, disabled') }}</p>
            <JurorScreening
                :summons="J.summons"
                :questions="J.questions"
                :can="{ submitScreening: false }"
                :answers="{ q1: 'no', q2: 'no', q3: 'yes', q4: 'no', q5: 'no' }"
                outcome="flagged"
            />
        </Card>

        <!-- ====================== 5. ConstituentConsentPanel (reuse) ===== -->
        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_conv_open', 'ConstituentConsentPanel — judiciary conversion (Art. IV §3 dual supermajority), open')">
            <p class="citation">
                {{ t('c_operator_pages.judiciary_kit.cite_conv_open', 'Legislature/ConstituentConsentPanel REUSED VERBATIM (the Phase D exec-conversion component) for the Art. IV §3 judiciary conversion · multi_jurisdiction_votes.kind jud_office_create · `required` is the engine ceil snapshot — NEVER client math · Art. IV §3 · Art. VII') }}
            </p>
            <ConstituentConsentPanel
                :legislature-vote="CONV.open.legislatureVote"
                :legislature-label="CONV.legislatureLabel"
                :process="CONV.open.process"
                :subject-label="CONV.subjectLabel"
                basis="Art. IV §3 · Art. VII"
            />
        </Card>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_conv_passed', 'ConstituentConsentPanel — judiciary conversion passed (both supermajorities cleared)')">
            <p class="citation">{{ t('c_operator_pages.judiciary_kit.cite_conv_passed', 'on both-passed: I-JUD → I-JDE; judges thereafter elected in groups of ≥5 via STV · CLK-15 · Art. IV §4') }}</p>
            <ConstituentConsentPanel
                :legislature-vote="CONV.passed.legislatureVote"
                :legislature-label="CONV.legislatureLabel"
                :process="CONV.passed.process"
                :subject-label="CONV.subjectLabel"
                basis="Art. IV §3 · Art. VII"
            />
        </Card>

        <!-- ============================ 6. Phase E state machines ======= -->
        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_state_machines', 'State machines — FE-E0 config entries (display contract)')">
            <p class="citation">{{ t('c_operator_pages.judiciary_kit.cite_state_machines', 'config/cga/state_machines.php · PHP-owned, prop-fed on the real pages — shapes shown here for the kit only') }}</p>
            <div class="stack" style="gap: var(--space-3)">
                <div>
                    <span class="eyebrow">{{ t('c_operator_pages.judiciary_kit.eyebrow_case', 'Case (ESM-CASE) — current: jury_empaneled (the WF-JUD-03 spine)') }}</span>
                    <StateStrip :states="M.case" current="jury_empaneled" />
                </div>
                <div>
                    <span class="eyebrow">{{ t('c_operator_pages.judiciary_kit.eyebrow_challenge', 'Constitutional Challenge (ESM-CC) — current: legislative_window_open (Art. IV §5)') }}</span>
                    <StateStrip :states="M.constitutional_challenge" current="legislative_window_open" />
                </div>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_operator_pages.judiciary_kit.gloss_state_machines', "The three challenge resolution terminals (amended_by_legislature {'|'} overridden {'|'} judicial_remedy_applied) are mutually exclusive — judicial_remedy_applied → closed is the exit criterion. The case 'appealed' state is the resting state of an appealed judgement (IO-2): the appeal is a new linked case heard by the parent court or the same court en banc, and the original rests with its verdict preserved (Art. II §8).") }}
            </p>
        </Card>

        <Card as="section" :title="t('c_operator_pages.judiciary_kit.card_public_read', 'Public-read posture — the defining Phase E rule')">
            <div class="cluster" style="margin-block-end:var(--space-3)">
                <HardenedChip />
            </div>
            <p>
                {{ t('c_operator_pages.judiciary_kit.public_read_before', 'Dockets, opinions, challenges, findings, and panel assignments are public record (Art. II §2). Every judiciary surface is publicly readable; actions gate by derived role via') }}
                <code data-no-i18n>can.*</code> {{ t('c_operator_pages.judiciary_kit.public_read_after', "+ engine 422 — never a page 403. The single non-public space is deliberation (judges' chambers + jury room), rendered as locked cards.") }}
            </p>
            <p class="citation">{{ t('c_operator_pages.judiciary_kit.cite_public_read', 'Full Faith & Credit · public Acts, Records, and Judicial proceedings · Art. V §2 · Art. II §2') }}</p>
        </Card>
    </PageScaffold>
</template>
