<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Department;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\ExecutiveOrder;
use App\Models\Law;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use App\Services\Executive\ExecutiveOrderService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. III §2 · Art. II §7 (executive-order scope
 * validation, F-EXE-005). Writes the pin the committed backend already
 * names (ExecutiveOrderService docblock; ConstitutionalValidator
 * ::assertOrderCivicProcessProtection / checkExecutiveOrder).
 *
 * THE Phase D exit criterion (#3): an out-of-scope executive order is
 * rejected PRE-ISSUANCE with the verbatim citation, AND the rejection
 * lands on the public record + the audit chain. preflight() runs at the
 * VALIDATOR stage, OUTSIDE the engine transaction; on a scope violation it
 * commits a `rejected_pre_issuance` ExecutiveOrder row + a public_records
 * entry in its OWN transaction, THEN throws; the engine appends the
 * rejected=true chain row and surfaces 422 — FOUR artifacts.
 *
 * Pins:
 *  1. PURE (DB-free, always run): ORDER_PROTECTED_DOMAINS pinned verbatim;
 *     assertOrderCivicProcessProtection throws Art. II §7 for EVERY
 *     protected domain and passes for ordinary ones; the model's
 *     auto-reject domain constants match the validator list; the validator
 *     delegates F-EXE-005 to the service preflight (rejection-on-record),
 *     and F-EXE-005 is an emergency-ENABLED form (source-pinned).
 *  2. LIVE rolled-back E2E (guarded pg, WorkerRepresentationTest posture —
 *     skipped when pg unreachable; one transaction ALWAYS rolled back):
 *     a throwaway DELEGATED executive with a seated PRINCIPAL member, a
 *     department it oversees, and a live enabling law. Two out-of-scope
 *     orders — (a) a civic-process target_domain and (b) a department this
 *     executive does NOT oversee — are BOTH rejected with the right
 *     citation through the REAL validator gate, and EACH produces the
 *     rejected_pre_issuance order row + its public_records entry (sealed
 *     into the chain by audit_seq). A WELL-SCOPED order issues cleanly
 *     through the real service: EO-YYYY-NN allocated, status issued,
 *     record_id set, an order.issued chain entry sealed.
 *  3. FULL ENGINE-FILED E2E (the Phase D exit criterion #3, end to end
 *     through app(ConstitutionalEngine::class)->file('F-EXE-005', …)):
 *     the SAME two out-of-scope orders are filed through the engine; the
 *     seated principal's user resolves to R-14 via RoleService so the
 *     engine's AUTHORIZE stage passes and the rejection lands at the SCOPE
 *     stage with the verbatim citation. Each rejection now carries ALL
 *     FOUR artifacts: (a) the rejected_pre_issuance order row + verbatim
 *     rejection_citation, (b) its public_records entry (via_form
 *     F-EXE-005), (c) the NEWLY-UNBLOCKED engine-appended rejected=true
 *     audit_log chain row carrying the citation in blocked_reason, and
 *     (d) the thrown ConstitutionalViolation (the 422-equivalent). A
 *     well-scoped order filed through the engine ISSUES cleanly:
 *     EO-YYYY-NN, status issued, and an order.issued rejected=false chain
 *     row.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class OrderScopeValidationTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_order_scope_validation';

    // ======================================================================
    // 1. Pure Art. II §7 / Art. III §2 pins (DB-free, always run)
    // ======================================================================

    /**
     * The hardened civic-process domain list is the engine-checkable floor
     * (Art. II §7). This list may only ever GROW, under constitutional
     * review — it is pinned VERBATIM.
     */
    public function test_protected_domains_are_pinned_verbatim(): void
    {
        $this->assertSame(
            ['electoral_process', 'judicial_process', 'legislative_process'],
            ConstitutionalValidator::ORDER_PROTECTED_DOMAINS,
            'Art. II §7 — the order-protected civic-process domains are the hardened floor.'
        );

        // The model keeps the SAME three values in its column enum so the
        // ATTEMPT is typed honestly (the auto-reject constants).
        $this->assertSame(
            ConstitutionalValidator::ORDER_PROTECTED_DOMAINS,
            [
                ExecutiveOrder::DOMAIN_ELECTORAL_PROCESS,
                ExecutiveOrder::DOMAIN_JUDICIAL_PROCESS,
                ExecutiveOrder::DOMAIN_LEGISLATIVE_PROCESS,
            ],
            'Art. II §7 — the model auto-reject domain constants must mirror the validator list.'
        );
    }

    /**
     * The hardened shield (Art. II §7): every protected domain is rejected
     * UNCONDITIONALLY with the Art. II §7 citation — no enabling
     * instrument, including an active emergency power, can reach a civic
     * process; and an ordinary domain passes silently.
     */
    public function test_civic_process_protection_rejects_every_protected_domain(): void
    {
        foreach (ConstitutionalValidator::ORDER_PROTECTED_DOMAINS as $domain) {
            try {
                ConstitutionalValidator::assertOrderCivicProcessProtection($domain);
                $this->fail("Domain [{$domain}] must be rejected unconditionally (Art. II §7).");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation, "Art. II §7 — [{$domain}] rejection citation.");
            }
        }

        // Ordinary order domains pass the shield byte-identically (no
        // exception) — the shield gates the THREE civic processes only.
        foreach ([
            ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
            ExecutiveOrder::DOMAIN_PUBLIC_WORKS,
            ExecutiveOrder::DOMAIN_EMERGENCY_RESPONSE,
            ExecutiveOrder::DOMAIN_ADMINISTRATION,
            ExecutiveOrder::DOMAIN_OTHER,
        ] as $ordinary) {
            ConstitutionalValidator::assertOrderCivicProcessProtection($ordinary);
            $this->addToAssertionCount(1);
        }

        // An unknown / empty domain is not a PROTECTED process — the
        // hardened shield is exact, never a catch-all (semantic evasion is
        // Phase E judicial-review territory).
        ConstitutionalValidator::assertOrderCivicProcessProtection('');
        ConstitutionalValidator::assertOrderCivicProcessProtection('not_a_real_domain');
        $this->addToAssertionCount(2);
    }

    /**
     * Architecture pin (source-scanned): the F-EXE-005 validator gate
     * delegates to ExecutiveOrderService::preflight (the rejection-on-record
     * mechanism), and F-EXE-005 is declared an emergency-ENABLED form (it
     * may cite an active power — bounded elsewhere to the power's declared
     * area + duration). These two facts are the spine of the exit
     * criterion; a refactor that severs either silently disables the pin.
     */
    public function test_validator_wires_the_rejection_on_record_mechanism(): void
    {
        $validatorSource = file_get_contents(
            (new \ReflectionClass(ConstitutionalValidator::class))->getFileName()
        );

        // The F-EXE-005 arm of check() routes to checkExecutiveOrder…
        $this->assertMatchesRegularExpression(
            "/'F-EXE-005'\s*=>\s*\\\$this->checkExecutiveOrder/",
            $validatorSource,
            'Art. III §2 — F-EXE-005 must route through the executive-order scope gate.'
        );

        // …which delegates to the service preflight that PERSISTS the
        // rejection artifacts before rethrowing.
        $this->assertMatchesRegularExpression(
            '/ExecutiveOrderService::class\)->preflight\(/',
            $validatorSource,
            'Art. III §2 — the gate delegates to ExecutiveOrderService::preflight (rejection-on-record).'
        );

        // F-EXE-005 is in the emergency-enabled allowlist (Phase D narrow
        // door) — and the civic-process shield above is untouched.
        $this->assertContains(
            'F-EXE-005',
            ConstitutionalValidator::EMERGENCY_ENABLED_FORMS,
            'Art. II §7 — executive orders are the declared emergency-enabled form.'
        );
    }

    // ======================================================================
    // 2. Live rolled-back E2E (skipped when pg unreachable)
    // ======================================================================

    /**
     * THE Phase D exit criterion against the REAL backend: two out-of-scope
     * orders are rejected pre-issuance with the verbatim citation and the
     * rejection-on-record artifacts (rejected_pre_issuance order row + its
     * public_records entry), and a well-scoped order issues cleanly
     * (EO-YYYY-NN, status issued, record_id + an order.issued chain entry).
     *
     * The mechanism is exercised exactly where the engine invokes it: the
     * VALIDATOR stage — ConstitutionalValidator::check('F-EXE-005', …) →
     * checkExecutiveOrder → ExecutiveOrderService::preflight — and live
     * issuance through ExecutiveOrderService::issue() (the exact call the
     * F-EXE-005 handler makes).
     *
     * This isolates the rejection-on-record mechanism (artifacts a + b) and
     * live issuance at the exact stages the engine drives. The companion
     * test below proves the SAME outcomes end to end through
     * app(ConstitutionalEngine::class)->file('F-EXE-005', …), where the
     * engine additionally appends the rejected=true chain row (artifact c)
     * and surfaces the 422 (artifact d).
     */
    public function test_out_of_scope_orders_reject_on_record_and_a_well_scoped_order_issues(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            // ── Fixtures ──────────────────────────────────────────────────
            // Two distinct jurisdictions that hold NO executive (the
            // (jurisdiction_id, deleted_at) unique index forbids a second
            // executive per jurisdiction). Resolved at runtime — never
            // hardcoded (the DB is reset between snapshots).
            [$j1, $j2] = $this->twoExecutivelessJurisdictions($conn);

            $legislatureId = $conn->table('legislatures')->whereNull('deleted_at')->value('id');
            $this->assertNotNull($legislatureId, 'Live DB has no legislature — seed it first.');

            // Executive E1 (the acting executive) + its seated principal.
            $principalUser = $this->throwawayUser('principal');
            [$exec, $member] = $this->throwawayDelegatedExecutive($j1, $principalUser);

            // A department E1 oversees + a live enabling law in E1's
            // jurisdiction (covers it at depth 0).
            $deptInScope = $this->throwawayDepartment($j1, (string) $exec->id, (string) $legislatureId);
            $law = $this->throwawayLaw($j1, (string) $legislatureId);

            // Executive E2 in a DIFFERENT jurisdiction + a department IT
            // oversees — the out-of-scope target for rejection (b).
            [$execOther] = $this->throwawayDelegatedExecutive($j2, $this->throwawayUser('other-principal'));
            $deptOutOfScope = $this->throwawayDepartment($j2, (string) $execOther->id, (string) $legislatureId);

            $validator = app(ConstitutionalValidator::class);
            $orders = app(ExecutiveOrderService::class);

            // The validator/handler resolve issued_by_member_id from the
            // acting seat — the engine derives it from the actor, never
            // input; we mirror that here.
            $base = [
                'executive_id' => (string) $exec->id,
                'issued_by_member_id' => (string) $member->id,
                'enabling_type' => ExecutiveOrder::ENABLING_LAW,
                'enabling_id' => (string) $law->id,
                'title' => 'OrderScopeValidationTest throwaway order',
                'body' => 'Throwaway body — rolled back.',
                'jurisdiction_id' => (string) $j1,
            ];

            // ════════════════════════════════════════════════════════════
            // Rejection (a): a civic-process target_domain (Art. II §7) —
            // through the REAL validator gate (the engine's validate stage).
            // ════════════════════════════════════════════════════════════
            try {
                $validator->check('F-EXE-005', $base + [
                    'target_domain' => ExecutiveOrder::DOMAIN_ELECTORAL_PROCESS,
                    'department_id' => (string) $deptInScope->id,
                ]);
                $this->fail('A civic-process order must be rejected pre-issuance (Art. II §7).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation, 'Art. II §7 — civic-process rejection citation.');
            }

            $this->assertRejectionArtifacts(
                $conn,
                executiveId: (string) $exec->id,
                domain: ExecutiveOrder::DOMAIN_ELECTORAL_PROCESS,
                citation: 'Art. II §7',
            );

            // ════════════════════════════════════════════════════════════
            // Rejection (b): a department this executive does NOT oversee
            // (Art. III §2 — an order cannot reach outside the executive's
            // delegated scope).
            // ════════════════════════════════════════════════════════════
            try {
                $validator->check('F-EXE-005', $base + [
                    'target_domain' => ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
                    'department_id' => (string) $deptOutOfScope->id,
                ]);
                $this->fail('An order naming an un-overseen department must be rejected (Art. III §2).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. III §2', $e->citation, 'Art. III §2 — out-of-scope department citation.');
            }

            $this->assertRejectionArtifacts(
                $conn,
                executiveId: (string) $exec->id,
                domain: ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
                citation: 'Art. III §2',
                departmentId: (string) $deptOutOfScope->id,
            );

            // A well-scoped payload PASSES the validator gate (no throw) —
            // the scope rules let a legitimate order through.
            $validator->check('F-EXE-005', $base + [
                'target_domain' => ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
                'department_id' => (string) $deptInScope->id,
            ]);
            $this->addToAssertionCount(1);

            // ════════════════════════════════════════════════════════════
            // A WELL-SCOPED order ISSUES cleanly through the real service
            // (EO-YYYY-NN, status issued, record_id + an order.issued chain
            // entry) — the exact call the F-EXE-005 handler makes.
            // ════════════════════════════════════════════════════════════
            $seqBeforeIssue = (int) $conn->table('audit_log')->max('seq');

            $order = $orders->issue($base + [
                'target_domain' => ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
                'department_id' => (string) $deptInScope->id,
            ]);

            $this->assertSame(ExecutiveOrder::STATUS_ISSUED, $order->status, 'Art. III §2 — a well-scoped order issues.');
            $this->assertMatchesRegularExpression(
                '/^EO-\d{4}-\d{2}$/',
                (string) $order->order_no,
                'Art. III §2 — issuance allocates EO-YYYY-NN.'
            );
            $this->assertNotNull($order->record_id, 'Art. III §2 — an issued order publishes to the public record.');
            $this->assertNotNull($order->issued_at);
            $this->assertSame((string) $deptInScope->id, (string) $order->department_id);

            // The issued public record exists and is the act kind.
            $issuedRecord = $conn->table('public_records')->where('id', (string) $order->record_id)->first();
            $this->assertNotNull($issuedRecord, 'Art. III §2 — the issued order is on the public register.');
            $this->assertSame('act', $issuedRecord->kind);
            $this->assertSame('executive_orders', $issuedRecord->subject_type);
            $this->assertSame((string) $order->id, (string) $issuedRecord->subject_id);

            // The order.issued chain entry sealed (NOT rejected).
            $issuedEntry = $conn->table('audit_log')
                ->where('seq', '>', $seqBeforeIssue)
                ->where('module', 'executive')
                ->where('event', 'order.issued')
                ->where('payload->order_id', (string) $order->id)
                ->first();
            $this->assertNotNull($issuedEntry, 'Art. III §2 — issuance seals an order.issued chain entry.');
            $this->assertFalse((bool) $issuedEntry->rejected, 'A successful issuance is NOT a rejection.');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * THE Phase D exit criterion (#3) end to end through the engine:
     * app(ConstitutionalEngine::class)->file('F-EXE-005', $actor, …). The
     * handler is now registered (FormRegistry::HANDLERS['F-EXE-005'] ⇒
     * Handlers\ExecutiveOrder), so the full pipeline runs:
     *
     *   authorize → the seated DELEGATED principal's user resolves to R-14
     *               via RoleService (the engine's role gate PASSES — proven
     *               by the rejection landing at the SCOPE stage with the
     *               scope citation, not the 'CGA Roles & Forms Chart'
     *               authorize citation);
     *   validate  → ConstitutionalValidator::check → checkExecutiveOrder →
     *               ExecutiveOrderService::preflight persists artifacts
     *               (a) the rejected_pre_issuance order row + verbatim
     *               citation and (b) its public_records entry, then throws;
     *   catch     → the engine appends (c) the rejected=true audit_log
     *               chain row carrying the citation in blocked_reason and
     *               rethrows (d) the ConstitutionalViolation — the 422.
     *
     * Both out-of-scope filings — (a) a civic-process target_domain
     * (Art. II §7) and (b) a department this executive does NOT oversee
     * (Art. III §2) — are asserted to produce ALL FOUR artifacts. A
     * well-scoped filing ISSUES cleanly through the engine: EO-YYYY-NN,
     * status issued, and an order.issued rejected=false chain row.
     *
     * NEW-BUG TRIPWIRE: if RoleService failed to derive the executive role
     * for a seated principal, the engine's authorize stage would reject
     * FIRST with citation 'CGA Roles & Forms Chart' before scope ever ran —
     * the citation assertions below would fail and surface that as a new
     * backend bug. They pass, so authorize derives R-14 correctly.
     */
    public function test_out_of_scope_order_rejected_pre_issuance_with_four_artifacts_via_engine(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        // The engine reads roles via the request-cached RoleService
        // singleton; flush so the fresh throwaway users derive cleanly.
        app(\App\Services\RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            // ── Fixtures (same substrate the live sibling builds) ─────────
            [$j1, $j2] = $this->twoExecutivelessJurisdictions($conn);

            $legislatureId = $conn->table('legislatures')->whereNull('deleted_at')->value('id');
            $this->assertNotNull($legislatureId, 'Live DB has no legislature — seed it first.');

            // Executive E1 + its seated PRINCIPAL (delegated_proportional on
            // a DELEGATED executive — the R-14 fact source RoleService
            // derives). The engine authorizes through THIS user.
            $principalUser = $this->throwawayUser('engine-principal');
            [$exec, $member] = $this->throwawayDelegatedExecutive($j1, $principalUser);

            // Sanity: RoleService derives the executive role for this seated
            // principal — the engine's authorize stage will pass. (If this
            // is empty the authorize stage rejects before scope: a NEW bug.)
            $held = app(\App\Services\RoleService::class)->rolesFor($principalUser->fresh());
            $this->assertContains(
                'R-14',
                $held,
                'Art. III §1–2 — a seated delegated principal must derive R-14 so the engine authorizes the filing.'
            );

            $deptInScope = $this->throwawayDepartment($j1, (string) $exec->id, (string) $legislatureId);
            $law = $this->throwawayLaw($j1, (string) $legislatureId);

            // Executive E2 in a DIFFERENT jurisdiction + a department IT
            // oversees — the out-of-scope target for rejection (b).
            [$execOther] = $this->throwawayDelegatedExecutive($j2, $this->throwawayUser('engine-other'));
            $deptOutOfScope = $this->throwawayDepartment($j2, (string) $execOther->id, (string) $legislatureId);

            $engine = app(ConstitutionalEngine::class);

            // The engine derives the issuing member from the ACTOR's seat,
            // but the validator stage (preflight) resolves the member from
            // issued_by_member_id in the payload — mirror the engine's own
            // derivation so the rejection reaches the SCOPE rules.
            $base = [
                'executive_id' => (string) $exec->id,
                'issued_by_member_id' => (string) $member->id,
                'enabling_type' => ExecutiveOrder::ENABLING_LAW,
                'enabling_id' => (string) $law->id,
                'title' => 'OrderScopeValidationTest engine-filed order',
                'body' => 'Throwaway body — rolled back.',
                'jurisdiction_id' => (string) $j1,
            ];

            // ════════════════════════════════════════════════════════════
            // Rejection (a): a civic-process target_domain (Art. II §7) —
            // filed THROUGH the engine. FOUR artifacts.
            // ════════════════════════════════════════════════════════════
            $seqBeforeA = (int) $conn->table('audit_log')->max('seq');

            try {
                $engine->file('F-EXE-005', $principalUser, $base + [
                    'target_domain' => ExecutiveOrder::DOMAIN_ELECTORAL_PROCESS,
                    'department_id' => (string) $deptInScope->id,
                ]);
                $this->fail('A civic-process order must be rejected pre-issuance through the engine (Art. II §7).');
            } catch (ConstitutionalViolation $e) {
                // (d) the 422-equivalent — and proof authorize PASSED (a
                // failed role gate would carry 'CGA Roles & Forms Chart').
                $this->assertSame('Art. II §7', $e->citation, 'Art. II §7 — civic-process rejection citation (engine).');
            }

            $this->assertFourArtifacts(
                $conn,
                executiveId: (string) $exec->id,
                domain: ExecutiveOrder::DOMAIN_ELECTORAL_PROCESS,
                citation: 'Art. II §7',
                seqBefore: $seqBeforeA,
            );

            // ════════════════════════════════════════════════════════════
            // Rejection (b): a department this executive does NOT oversee
            // (Art. III §2) — filed THROUGH the engine. FOUR artifacts.
            // ════════════════════════════════════════════════════════════
            $seqBeforeB = (int) $conn->table('audit_log')->max('seq');

            try {
                $engine->file('F-EXE-005', $principalUser, $base + [
                    'target_domain' => ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
                    'department_id' => (string) $deptOutOfScope->id,
                ]);
                $this->fail('An order naming an un-overseen department must be rejected through the engine (Art. III §2).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. III §2', $e->citation, 'Art. III §2 — out-of-scope department citation (engine).');
            }

            $this->assertFourArtifacts(
                $conn,
                executiveId: (string) $exec->id,
                domain: ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
                citation: 'Art. III §2',
                seqBefore: $seqBeforeB,
                departmentId: (string) $deptOutOfScope->id,
            );

            // ════════════════════════════════════════════════════════════
            // A WELL-SCOPED order ISSUES cleanly through the engine:
            // EO-YYYY-NN, status issued, and an order.issued (NOT rejected)
            // chain row. The engine ran authorize → validate → handle →
            // audit end to end.
            // ════════════════════════════════════════════════════════════
            $seqBeforeIssue = (int) $conn->table('audit_log')->max('seq');

            $result = $engine->file('F-EXE-005', $principalUser, $base + [
                'target_domain' => ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
                'department_id' => (string) $deptInScope->id,
            ]);

            $orderId = (string) $result->recorded['order_id'];
            $order = ExecutiveOrder::query()->find($orderId);

            $this->assertNotNull($order, 'Art. III §2 — a well-scoped engine filing persists the issued order.');
            $this->assertSame(ExecutiveOrder::STATUS_ISSUED, $order->status, 'Art. III §2 — a well-scoped engine order issues.');
            $this->assertMatchesRegularExpression(
                '/^EO-\d{4}-\d{2}$/',
                (string) $order->order_no,
                'Art. III §2 — engine issuance allocates EO-YYYY-NN.'
            );
            $this->assertNotNull($order->record_id, 'Art. III §2 — an issued order publishes to the public record.');
            $this->assertNotNull($order->issued_at);
            $this->assertSame((string) $deptInScope->id, (string) $order->department_id);

            // The issuance service seals an order.issued chain row; the
            // engine then seals its own order.filed row for the same filing
            // — BOTH are rejected=false (a clean issuance is no rejection).
            $issuedEntry = $conn->table('audit_log')
                ->where('seq', '>', $seqBeforeIssue)
                ->where('module', 'executive')
                ->where('event', 'order.issued')
                ->where('payload->order_id', $orderId)
                ->first();
            $this->assertNotNull($issuedEntry, 'Art. III §2 — issuance seals an order.issued chain entry.');
            $this->assertFalse((bool) $issuedEntry->rejected, 'A successful issuance is NOT a rejection.');

            $engineEntry = $conn->table('audit_log')
                ->where('seq', '>', $seqBeforeIssue)
                ->where('module', 'executive')
                ->where('event', 'order.filed')
                ->where('ref', 'F-EXE-005')
                ->where('payload->order_id', $orderId)
                ->first();
            $this->assertNotNull($engineEntry, 'Art. III §2 — the engine seals the F-EXE-005 order.filed entry.');
            $this->assertFalse((bool) $engineEntry->rejected, 'A successful engine filing is NOT a rejection.');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
            app(\App\Services\RoleService::class)->flush();
        }
    }

    /**
     * The FULL four-artifact rejection set produced by an engine-filed
     * F-EXE-005 (the Phase D exit criterion): artifacts (a) the
     * rejected_pre_issuance order row + (b) its public_records entry
     * (asserted by assertRejectionArtifacts), PLUS (c) the engine-appended
     * rejected=true audit_log chain row carrying the citation in
     * blocked_reason. (d) the 422-equivalent — the thrown
     * ConstitutionalViolation — is asserted at the call site.
     */
    private function assertFourArtifacts(
        Connection $conn,
        string $executiveId,
        string $domain,
        string $citation,
        int $seqBefore,
        ?string $departmentId = null,
    ): void {
        // (a) + (b) — the rejected order row and its public record.
        $this->assertRejectionArtifacts(
            $conn,
            executiveId: $executiveId,
            domain: $domain,
            citation: $citation,
            departmentId: $departmentId,
        );

        // (c) — the engine's own rejected=true chain row, appended by
        // ConstitutionalEngine::file()'s catch block (event =
        // handler.event() . '.rejected' = 'order.filed.rejected', module =
        // 'executive', ref = 'F-EXE-005', citation in blocked_reason).
        $rejectedEntry = $conn->table('audit_log')
            ->where('seq', '>', $seqBefore)
            ->where('module', 'executive')
            ->where('event', 'order.filed.rejected')
            ->where('ref', 'F-EXE-005')
            ->where('rejected', true)
            ->orderByDesc('seq')
            ->first();

        $this->assertNotNull(
            $rejectedEntry,
            "{$citation} — the engine appends a rejected=true order.filed.rejected chain row (artifact c)."
        );
        $this->assertTrue((bool) $rejectedEntry->rejected, "{$citation} — the chain row is flagged rejected.");
        $this->assertStringContainsString(
            $citation,
            (string) $rejectedEntry->blocked_reason,
            "{$citation} — the rejected chain row carries the verbatim citation in blocked_reason."
        );

        // The rejection payload is provenance-stamped F-EXE-005 with the
        // citation (credential material stripped by the engine).
        $payload = json_decode((string) $rejectedEntry->payload, true) ?? [];
        $this->assertSame('F-EXE-005', $payload['form_id'] ?? null, "{$citation} — the rejected chain row records the form id.");
        $this->assertSame($citation, $payload['citation'] ?? null, "{$citation} — the rejected chain row records the citation.");
    }

    /**
     * The rejection-on-record artifacts (design §D) reachable at the
     * validator stage: the rejected_pre_issuance ExecutiveOrder row (with
     * its verbatim citation) and its public_records entry. The engine's
     * rejected=true audit_log row (artifact c) is asserted by
     * assertFourArtifacts for the engine-filed path.
     */
    private function assertRejectionArtifacts(
        Connection $conn,
        string $executiveId,
        string $domain,
        string $citation,
        ?string $departmentId = null,
    ): void {
        // (a) the rejected_pre_issuance order row.
        $order = ExecutiveOrder::query()
            ->where('executive_id', $executiveId)
            ->where('target_domain', $domain)
            ->where('status', ExecutiveOrder::STATUS_REJECTED_PRE_ISSUANCE)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($order, "{$citation} — a rejected order PERSISTS (the attempt is the record).");
        $this->assertSame($citation, (string) $order->rejection_citation, "{$citation} — the verbatim citation is on the row.");
        $this->assertNotNull($order->rejection_reason, "{$citation} — the rejection reason is recorded.");
        $this->assertNull($order->order_no, 'A rejected order never allocates an EO number.');
        $this->assertNull($order->issued_at);
        $this->assertNotNull($order->record_id, "{$citation} — the rejected order links its public record.");

        if ($departmentId !== null) {
            $this->assertSame($departmentId, (string) $order->department_id);
        }

        // (b) the public_records entry for the rejection — the attempt IS
        // the record (Phase D exit criterion: rejection on the PUBLIC
        // register, not only an exception).
        $record = $conn->table('public_records')->where('id', (string) $order->record_id)->first();
        $this->assertNotNull($record, "{$citation} — the rejection lands on the public register.");
        $this->assertSame('executive_orders', $record->subject_type);
        $this->assertSame((string) $order->id, (string) $record->subject_id);
        $this->assertSame('F-EXE-005', $record->via_form, "{$citation} — the record is provenance-stamped F-EXE-005.");
        $this->assertStringContainsString($citation, (string) $record->title, "{$citation} — the record title carries the citation.");

        // The public register is sealed into the audit chain by audit_seq
        // (PublicRecordService appends the records/published entry in the
        // same transaction) — the rejection is on the chain via its record
        // even while the engine's own rejected=true row is blocked.
        $this->assertNotNull($record->audit_seq, "{$citation} — the public record is sealed into the audit chain.");
    }

    // ======================================================================
    // Fixtures (WorkerRepresentationTest posture — Str::uuid for every
    // unique field; ZERO residue — the whole transaction is rolled back)
    // ======================================================================

    /**
     * Two distinct, non-deleted jurisdictions that hold no executive row.
     *
     * @return array{0: string, 1: string}
     */
    private function twoExecutivelessJurisdictions(Connection $conn): array
    {
        $ids = $conn->table('jurisdictions as j')
            ->whereNull('j.deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('executives as e')
                    ->whereColumn('e.jurisdiction_id', 'j.id')
                    ->whereNull('e.deleted_at');
            })
            ->orderBy('j.id')
            ->limit(2)
            ->pluck('j.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (count($ids) < 2) {
            $this->markTestSkipped('Live DB has fewer than two executiveless jurisdictions — seed it first.');
        }

        return [$ids[0], $ids[1]];
    }

    /**
     * A DELEGATED executive with one seated PRINCIPAL member of the given
     * user (selection delegated_proportional — the R-14 fact source).
     *
     * @return array{0: Executive, 1: ExecutiveMember}
     */
    private function throwawayDelegatedExecutive(string $jurisdictionId, User $principal): array
    {
        $executive = new Executive;
        $executive->forceFill([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'type' => Executive::TYPE_COMMITTEE,
            'status' => Executive::STATUS_DELEGATED,
            'term_number' => 1,
            'delegated_member_count' => 5,
        ])->save();

        $member = new ExecutiveMember;
        $member->forceFill([
            'id' => (string) Str::uuid(),
            'executive_id' => (string) $executive->id,
            'user_id' => (string) $principal->getKey(),
            'role' => ExecutiveMember::ROLE_PRINCIPAL,
            'rank' => 0,
            'joined_at' => now()->toDateString(),
            'selection' => ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL,
            'status' => ExecutiveMember::STATUS_SEATED,
        ])->save();

        return [$executive, $member];
    }

    /** A department overseen by the given executive (charter law in-jurisdiction). */
    private function throwawayDepartment(string $jurisdictionId, string $executiveId, string $legislatureId): Department
    {
        $charter = $this->throwawayLaw($jurisdictionId, $legislatureId, Law::KIND_CHARTER);

        return Department::create([
            'jurisdiction_id' => $jurisdictionId,
            'executive_id' => $executiveId,
            'kind' => Department::KIND_OTHER,
            'name' => 'OrderScope Throwaway Dept '.Str::random(6),
            'charter_law_id' => (string) $charter->id,
            'status' => Department::STATUS_OPERATING,
        ]);
    }

    /** An in-force law bound to the given jurisdiction (the enabling instrument). */
    private function throwawayLaw(string $jurisdictionId, string $legislatureId, string $kind = Law::KIND_ORDINARY): Law
    {
        return Law::create([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => $legislatureId,
            'act_number' => 'OST-'.strtoupper(Str::random(10)),
            'title' => 'OrderScope Throwaway Enabling Law '.Str::random(6),
            'kind' => $kind,
            'scale' => ['scope' => 'throwaway'],
            'origin' => Law::ORIGIN_BILL,
            'status' => Law::STATUS_IN_FORCE,
            'current_version_no' => 1,
            'effective_at' => now(),
            'enacted_at' => now(),
        ]);
    }

    private function throwawayUser(string $label): User
    {
        return User::create([
            'name' => "OrderScope {$label}",
            'email' => 'order-scope-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
