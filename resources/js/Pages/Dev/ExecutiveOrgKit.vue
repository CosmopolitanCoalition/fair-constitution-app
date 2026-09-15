<script setup>
/**
 * Dev/ExecutiveOrgKit — FE-D1 fixture-first harness (/dev/executive-kit,
 * dev-gated). Renders the Phase D component kit in every state from
 * resources/js/fixtures/executive.json (mockup-extracted; see the file's
 * `_source` provenance). NOT product UI — the per-page WIs (FE-D2…D9)
 * wire these against real engine-snapshotted props.
 *
 * Reference mockups: mockups/executive/{executive-home,departments,
 * department-detail,executive-actions}.html, mockups/organizations/
 * {co-determination,board-elections,org-detail,cgc-detail,
 * transfers-conversions}.html.
 */
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShell from '@/Layouts/AppShell.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import ConstituentConsentPanel from '@/Components/Legislature/ConstituentConsentPanel.vue';
import DepartmentCard from '@/Components/Executive/DepartmentCard.vue';
import OrderScopeCard from '@/Components/Executive/OrderScopeCard.vue';
import BoardStrip from '@/Components/Organizations/BoardStrip.vue';
import CoDetScale, { workerSeatsFromThresholds } from '@/Components/Organizations/CoDetScale.vue';
import OwnershipPanel from '@/Components/Organizations/OwnershipPanel.vue';
import fixtures from '@/fixtures/executive.json';

/* KEEP-class (V3 synthesis S1): pinned to the v1 shell when AppShellV2 became
   the app-wide default — dev harness, not product UI; zero change intended. */
defineOptions({ layout: AppShell });

defineProps({ surface: { type: Object, default: null } });

const { t } = useI18n();

const C = fixtures.consent;

/* ----------------------------------------- CoDetScale formula pins ----- */
/* The §A.2 unit pins, evaluated against the EXPORTED formula (the same
   function the explorer uses) — 99→0, 100→1 (the max(1,…) floor),
   740/9→3, 2000/9→9 (the min(owner,…) cap). Rendered so a wrong formula
   is visible on this page before any backend exists. */
const DEFAULTS = { min: 100, parity: 2000 };
const pins = [
    { args: '99 workers · 9 owner seats', expected: 0, actual: workerSeatsFromThresholds(99, 9, DEFAULTS) },
    { args: '100 workers · 9 owner seats (max(1,…) floor)', expected: 1, actual: workerSeatsFromThresholds(100, 9, DEFAULTS) },
    { args: '740 workers · 9 owner seats', expected: 3, actual: workerSeatsFromThresholds(740, 9, DEFAULTS) },
    { args: '2,000 workers · 9 owner seats (min(owner,…) cap)', expected: 9, actual: workerSeatsFromThresholds(2000, 9, DEFAULTS) },
    { args: '740 workers · 9 owner seats · thresholds {50, 1000}', expected: 7, actual: workerSeatsFromThresholds(740, 9, { min: 50, parity: 1000 }) },
];
const pinsGreen = pins.every((pin) => pin.actual === pin.expected);

/* ------------------------------------------------ OrderScopeCard ------- */
const detailedOrder = ref(false);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_operator_pages.executive_org_kit.intro_before', 'FE-D1 harness — the Phase D executive + organizations components rendered from') }}
            <code data-no-i18n>resources/js/fixtures/executive.json</code>
            {{ t('c_operator_pages.executive_org_kit.intro_after', '(mockup-extracted). Dev-gated; not product UI. Every threshold and seat count below is a frozen "server snapshot" — the one exception is the co-determination EXPLORER, which recomputes a published formula and labels itself a projection.') }}
        </template>

        <Banner tone="demo" :title="t('c_operator_pages.executive_org_kit.fixture_data_only', 'Fixture data only.')">
            {{ t('c_operator_pages.executive_org_kit.fixture_body', "Nothing on this page touches the database — the New York State conversion record, the Bluefin Logistics scale, and the Public Works & Utilities board are the mockups' fixtures frozen into JSON.") }}
        </Banner>

        <!-- ============================== 1. ConstituentConsentPanel ==== -->
        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_consent_passed', 'ConstituentConsentPanel — dual supermajority (passed: 8/9 + 51/62)')">
            <p class="citation">
                {{ t('c_operator_pages.executive_org_kit.cite_consent_passed', "Legislature/ConstituentConsentPanel · the multi_jurisdiction_votes UX · `required` is the engine's ceil snapshot — NEVER client math · Art. III §3 · Art. VII") }}
            </p>
            <ConstituentConsentPanel
                :legislature-vote="C.legislatureVote"
                :legislature-label="C.legislatureLabel"
                :process="C.processPassed"
                :subject-label="C.subjectLabel"
            />
        </Card>

        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_consent_open', 'ConstituentConsentPanel — open (both meters pending)')">
                <ConstituentConsentPanel
                    :legislature-vote="C.legislatureVotePending"
                    :legislature-label="C.legislatureLabel"
                    :process="C.processOpen"
                    :subject-label="C.subjectLabel"
                />
            </Card>
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_consent_constituent_failed', 'ConstituentConsentPanel — constituent leg failed (38 of 62, needed 42)')">
                <ConstituentConsentPanel
                    :legislature-vote="C.legislatureVote"
                    :legislature-label="C.legislatureLabel"
                    :process="C.processFailed"
                    :subject-label="C.subjectLabel"
                />
            </Card>
        </div>

        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_consent_one_failed', 'ConstituentConsentPanel — one leg failed (own supermajority short)')">
                <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_one_failed', '5 of 9 meets a majority but NOT ceil(9 × 2/3) = 6 — the combined banner names the failing leg') }}</p>
                <ConstituentConsentPanel
                    :legislature-vote="C.legislatureVoteFailed"
                    :legislature-label="C.legislatureLabel"
                    :process="C.processPassed"
                    :subject-label="C.subjectLabel"
                />
            </Card>
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_consent_required_verbatim', 'ConstituentConsentPanel — server `required` rendered verbatim')">
                <p class="citation" data-no-i18n>
                    fixture feeds required=99 against total=62 — the right caption displays 99, proving the
                    component performs no client ceil( ) of its own
                </p>
                <ConstituentConsentPanel
                    :legislature-vote="C.legislatureVotePending"
                    :legislature-label="C.legislatureLabel"
                    :process="C.requiredOverride"
                    :subject-label="C.subjectLabel"
                />
            </Card>
        </div>

        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_consent_billdetail', 'ConstituentConsentPanel — BillDetail call-site shape (legacy consent rows, no Block 1)')">
            <p class="citation">
                {{ t('c_operator_pages.executive_org_kit.cite_billdetail', 'Phase C BillController feed: consents carry string jurisdiction names, no chamber-vote links; legislatureVote null → only the constituent block renders. The live BillDetail page now composes this component (the FE-D1 call-site migration).') }}
            </p>
            <ConstituentConsentPanel :process="C.legacyBillShape" basis="Art. V §6" />
        </Card>

        <!-- ======================================== 2. CoDetScale ======= -->
        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_codet_static', 'CoDetScale — static (Bluefin Logistics, 740 workers · engine says 3 of 9)')">
            <p class="citation">
                {{ t('c_operator_pages.executive_org_kit.cite_codet_static', "Org/CoDetScale · workerSeats is THE ENGINE'S number; thresholds are server-resolved CLK-13/14 values (amendable — never hardcoded) · Art. III §6") }}
            </p>
            <CoDetScale
                :workers="fixtures.codet.bluefin.workers"
                :owner-seats="fixtures.codet.bluefin.ownerSeats"
                :worker-seats="fixtures.codet.bluefin.workerSeats"
                :thresholds="fixtures.codet.bluefin.thresholds"
                :next-step-at="fixtures.codet.bluefin.nextStepAt"
                :entity-label="fixtures.codet.bluefin.entityLabel"
            />
        </Card>

        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_codet_explorer', 'CoDetScale — interactive explorer (keyboard-operable slider)')">
            <p class="citation">
                {{ t('c_operator_pages.executive_org_kit.cite_codet_explorer', 'the ONE Phase D component with client arithmetic — an explorer of the published formula, never a record; moved values flag "projection · WF-ORG-04"; the live badge ignores the slider') }}
            </p>
            <CoDetScale
                :workers="fixtures.codet.bluefin.workers"
                :owner-seats="fixtures.codet.bluefin.ownerSeats"
                :worker-seats="fixtures.codet.bluefin.workerSeats"
                :thresholds="fixtures.codet.bluefin.thresholds"
                :next-step-at="fixtures.codet.bluefin.nextStepAt"
                :entity-label="fixtures.codet.bluefin.entityLabel"
                interactive
            />
        </Card>

        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_codet_nondefault', `CoDetScale — thresholds ≠ defaults ({'{'}50, 1,000{'}'} by act)`)">
                <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_codet_nondefault', 'same 740 workers now yield 7 seats — the marks, captions, and formula all moved: nothing is hardcoded 100/2000') }}</p>
                <CoDetScale
                    :workers="fixtures.codet.nonDefaultThresholds.workers"
                    :owner-seats="fixtures.codet.nonDefaultThresholds.ownerSeats"
                    :worker-seats="fixtures.codet.nonDefaultThresholds.workerSeats"
                    :thresholds="fixtures.codet.nonDefaultThresholds.thresholds"
                    :next-step-at="fixtures.codet.nonDefaultThresholds.nextStepAt"
                    :entity-label="fixtures.codet.nonDefaultThresholds.entityLabel"
                />
            </Card>
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_codet_below', 'CoDetScale — below threshold + parity')">
                <CoDetScale
                    :workers="fixtures.codet.belowThreshold.workers"
                    :owner-seats="fixtures.codet.belowThreshold.ownerSeats"
                    :worker-seats="fixtures.codet.belowThreshold.workerSeats"
                    :thresholds="fixtures.codet.belowThreshold.thresholds"
                    :next-step-at="fixtures.codet.belowThreshold.nextStepAt"
                    :entity-label="fixtures.codet.belowThreshold.entityLabel"
                />
                <hr />
                <CoDetScale
                    :workers="fixtures.codet.parity.workers"
                    :owner-seats="fixtures.codet.parity.ownerSeats"
                    :worker-seats="fixtures.codet.parity.workerSeats"
                    :thresholds="fixtures.codet.parity.thresholds"
                    :next-step-at="fixtures.codet.parity.nextStepAt"
                    :entity-label="fixtures.codet.parity.entityLabel"
                />
            </Card>
        </div>

        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_codet_pins', 'CoDetScale — formula pins (the §A.2 unit contract)')">
            <p class="citation" data-no-i18n>
                workerSeatsFromThresholds() — exported from the component, the same function the explorer runs
            </p>
            <div class="table-wrap" tabindex="0" role="region" :aria-label="t('c_operator_pages.executive_org_kit.table_region', 'Results table (scrollable)')">
                <table class="table">
                    <thead>
                        <tr><th scope="col">{{ t('c_operator_pages.executive_org_kit.th_case', 'Case') }}</th><th scope="col" class="mono">{{ t('c_operator_pages.executive_org_kit.th_expected', 'expected') }}</th><th scope="col" class="mono">{{ t('c_operator_pages.executive_org_kit.th_actual', 'actual') }}</th><th scope="col">{{ t('c_operator_pages.executive_org_kit.th_pin', 'Pin') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="pin in pins" :key="pin.args">
                            <td data-no-i18n>{{ pin.args }}</td>
                            <td class="mono" data-no-i18n>{{ pin.expected }}</td>
                            <td class="mono" data-no-i18n>{{ pin.actual }}</td>
                            <td>
                                <StatusBadge :tone="pin.actual === pin.expected ? 'success' : 'danger'" :icon="pin.actual === pin.expected ? 'check' : 'x'">
                                    {{ pin.actual === pin.expected ? t('c_operator_pages.executive_org_kit.pin_holds', 'holds') : t('c_operator_pages.executive_org_kit.pin_broken', 'BROKEN') }}
                                </StatusBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Banner v-if="!pinsGreen" tone="emergency" :title="t('c_operator_pages.executive_org_kit.pin_broken_banner', 'Formula pin broken — the explorer no longer matches the engine contract.')" />
        </Card>

        <!-- ========================================= 3. BoardStrip ====== -->
        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_boardstrip', 'BoardStrip — Public Works & Utilities (7 governors + 4 worker-elected, valid)')">
            <p class="citation">
                {{ t('c_operator_pages.executive_org_kit.cite_boardstrip', 'Org/BoardStrip · the two clock regimes side by side: governors 2030-07-01 → 2040-07-01 (CLK-09) beside worker seats → 2035-11-01 (legislative term end · CLK-10) · chair joint-elected · Art. III §4, §6') }}
            </p>
            <BoardStrip
                :seats="fixtures.boards.publicWorks.seats"
                :composition-valid="fixtures.boards.publicWorks.compositionValid"
                :required-worker-seats="fixtures.boards.publicWorks.requiredWorkerSeats"
            />
        </Card>

        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_boardstrip_invalid', 'BoardStrip — composition invalid (vacant worker pip)')">
                <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_boardstrip_invalid', 'the exit-criterion-2 moment: the scale demanded a seat, the row is vacant, composition_valid=false — the banner rule verbatim') }}</p>
                <BoardStrip
                    :seats="fixtures.boards.invalid.seats"
                    :composition-valid="fixtures.boards.invalid.compositionValid"
                    :required-worker-seats="fixtures.boards.invalid.requiredWorkerSeats"
                />
            </Card>
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_boardstrip_compact', 'BoardStrip — compact (pip strip only)')">
                <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_boardstrip_compact', 'table rows + DepartmentCard variant — pips keep their aria-labels') }}</p>
                <BoardStrip
                    :seats="fixtures.boards.publicWorks.seats"
                    :composition-valid="fixtures.boards.publicWorks.compositionValid"
                    :required-worker-seats="fixtures.boards.publicWorks.requiredWorkerSeats"
                    compact
                />
                <hr />
                <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_boardstrip_compact_invalid', 'compact + invalid still surfaces the banner') }}</p>
                <BoardStrip
                    :seats="fixtures.boards.invalid.seats"
                    :composition-valid="fixtures.boards.invalid.compositionValid"
                    :required-worker-seats="fixtures.boards.invalid.requiredWorkerSeats"
                    compact
                />
            </Card>
        </div>

        <!-- ===================================== 4. OwnershipPanel ====== -->
        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_ownership_stock', 'OwnershipPanel — stock (Bluefin: 1,204 shareholders)')">
                <OwnershipPanel
                    :structure="fixtures.ownership.stock.structure"
                    :is-cgc="fixtures.ownership.stock.isCgc"
                    :stakes="fixtures.ownership.stock.stakes"
                    :member-counts="fixtures.ownership.stock.memberCounts"
                    :structure-history="fixtures.ownership.stock.structureHistory"
                />
            </Card>
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_ownership_partnership', 'OwnershipPanel — equal partnership (unanimity rule) + history')">
                <OwnershipPanel
                    :structure="fixtures.ownership.equalPartnership.structure"
                    :is-cgc="fixtures.ownership.equalPartnership.isCgc"
                    :stakes="fixtures.ownership.equalPartnership.stakes"
                    :member-counts="fixtures.ownership.equalPartnership.memberCounts"
                    :structure-history="fixtures.ownership.equalPartnership.structureHistory"
                />
            </Card>
        </div>

        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_ownership_cgc', 'OwnershipPanel — CGC variant (the ledger-#12 owner-ruling card)')">
            <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_ownership_cgc', 'no stakes table — the Board of Governors stands where shareholders would · Art. III §5') }}</p>
            <OwnershipPanel
                :structure="fixtures.ownership.cgc.structure"
                :is-cgc="fixtures.ownership.cgc.isCgc"
                :stakes="fixtures.ownership.cgc.stakes"
                :member-counts="fixtures.ownership.cgc.memberCounts"
                :structure-history="fixtures.ownership.cgc.structureHistory"
            />
        </Card>

        <!-- ===================================== 5. DepartmentCard ====== -->
        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_departmentcard', 'DepartmentCard — the 5-department registry grid')">
            <p class="citation">
                {{ t('c_operator_pages.executive_org_kit.cite_departmentcard', 'Executive/DepartmentCard · co-determination cell from ENGINE seat counts (parity = worker seats equal owner seats — no client threshold math) · Treasury 152w → 1 seat · scaling; Public Works 1,240w → 4; the rest below threshold · Emergency Management carries the overdue-report chip') }}
            </p>
            <div class="grid-2">
                <DepartmentCard
                    v-for="department in fixtures.departments"
                    :key="department.id"
                    :department="department"
                />
            </div>
        </Card>

        <!-- ===================================== 6. OrderScopeCard ====== -->
        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_orderscopecard', 'OrderScopeCard — the order register (issued / emergency-enabled / rejected)')">
            <p class="citation">
                {{ t('c_operator_pages.executive_org_kit.cite_orderscopecard', 'Executive/OrderScopeCard · rejected rows carry the engine citation VERBATIM + the load-bearing public-record chip · Art. III §2 · Art. II §7 · Art. IV §5') }}
            </p>
            <div>
                <OrderScopeCard v-for="order in fixtures.orders" :key="order.id_display" :order="order" />
            </div>
            <hr />
            <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_order_detailed', 'detailed variant — order body + the order-lifecycle StateStrip (machine prop-fed, PHP-owned)') }}</p>
            <div class="cluster" style="margin-block-end: var(--space-2)">
                <StatusBadge tone="info" icon="info">{{ t('c_operator_pages.executive_org_kit.badge_detailed_true', 'detailed = true on the rejected fixture') }}</StatusBadge>
            </div>
            <OrderScopeCard
                :order="{ ...fixtures.orders[3], body: fixtures.orderBody }"
                :machine="fixtures.orderMachine"
                detailed
            />
        </Card>

        <!-- ========================================= 7. Ui/Stepper ====== -->
        <div class="grid-2">
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_stepper_seated', 'Stepper — BoG pipeline, seated (departments.html lines 90–93)')">
                <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_stepper_seated', 'Ui/Stepper · Nomination dossier · F-EXE-001 → Consent vote · F-LEG-020 → Seated · R-18') }}</p>
                <Stepper :steps="fixtures.stepper.bogSeated" />
            </Card>
            <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_stepper_pending', 'Stepper — consent vote pending')">
                <Stepper :steps="fixtures.stepper.bogConsentPending" />
            </Card>
        </div>

        <!-- ============================== 8. Phase D state machines ===== -->
        <Card as="section" :title="t('c_operator_pages.executive_org_kit.card_state_machines', 'State machines — FE-D0 config entries (display contract)')">
            <p class="citation">{{ t('c_operator_pages.executive_org_kit.cite_state_machines', 'config/cga/state_machines.php · PHP-owned, prop-fed on the real pages — shapes shown here for the kit only') }}</p>
            <div class="stack" style="gap: var(--space-3)">
                <div>
                    <span class="eyebrow">{{ t('c_operator_pages.executive_org_kit.eyebrow_executive_office', 'Executive office (ESM-16) — current: forming (the day-one stub)') }}</span>
                    <StateStrip :states="fixtures.machines.executive_office" current="forming" />
                </div>
                <div>
                    <span class="eyebrow">{{ t('c_operator_pages.executive_org_kit.eyebrow_department_board', 'Department / Board (ESM-17) — current: operating') }}</span>
                    <StateStrip :states="fixtures.machines.department_board" current="operating" />
                </div>
                <div>
                    <span class="eyebrow">{{ t('c_operator_pages.executive_org_kit.eyebrow_organization', 'Organization (ESM-18) — current: active') }}</span>
                    <StateStrip :states="fixtures.machines.organization" current="active" />
                </div>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_operator_pages.executive_org_kit.gloss_state_machines', "'modified' is an event, not an executive resting state; 'removal_requested' splices into the department display machine from an open removal row; [Endorsing] and [Co-determination tiers] are derived organization display states — none of the three is a stored status.") }}
            </p>
        </Card>
    </PageScaffold>
</template>
