<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Models\UnionProcess;
use App\Services\ConstitutionalValidator;
use App\Services\Jurisdictions\UnionService;
use App\Services\MultiJurisdictionVoteService;
use App\Support\CivicPopulation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. V §7 (Union). A union change requires BOTH meters:
 * a SUPERMAJORITY of the APPLICANT population AND a SUPERMAJORITY of the UNION's
 * CONSTITUENT jurisdictions. Either alone is rejected; only both passing applies
 * the change. The constituent meter rides the PROTECTED MultiJurisdictionVote
 * supermajority math (ceil(serving·2/3)).
 *
 * If an edit breaks this test, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class UnionDualSupermajorityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_union_dual';

    public function test_union_requires_both_applicant_and_constituent_supermajority(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $union = app(UnionService::class);
            $mjvSvc = app(MultiJurisdictionVoteService::class);

            $legislature = Legislature::query()->whereNotNull('jurisdiction_id')->whereNull('deleted_at')->first();
            $applicant = (string) DB::table('residency_confirmations')->where('is_active', true)->value('jurisdiction_id');
            $constituents = DB::table('jurisdictions')->whereNull('deleted_at')->limit(3)->pluck('id')->map('strval')->all();

            if ($legislature === null || $applicant === '' || count($constituents) < 3) {
                $this->markTestSkipped('Live DB needs a legislature, a resident-bearing jurisdiction, and ≥3 jurisdictions.');
            }

            $applicantPop = CivicPopulation::of($applicant);
            $applicantRequired = ConstitutionalValidator::supermajority($applicantPop);

            // ── Case A: constituents PASS, applicant FAILS → rejected ────────
            $a = $union->open(UnionProcess::KIND_JOIN, $legislature, [$applicant], $constituents);
            $this->drivePassed($mjvSvc, $a->constituentProcess, $constituents);
            $this->assertSame(MultiJurisdictionVote::STATUS_PASSED, $a->constituentProcess->refresh()->status);
            $union->markApplicantReferendum($a, 0); // applicant referendum fails
            $this->assertViolation(fn () => $union->finalize($a->refresh()), 'Art. V §7');
            $this->assertSame(UnionProcess::STATUS_FAILED, $a->refresh()->status);

            // ── Case B: applicant PASSES, constituents FAIL → rejected ───────
            $b = $union->open(UnionProcess::KIND_JOIN, $legislature, [$applicant], $constituents);
            $union->markApplicantReferendum($b, $applicantRequired); // applicant referendum passes
            // constituent MJV left OPEN (no consents) → not a supermajority
            $this->assertViolation(fn () => $union->finalize($b->refresh()), 'Art. V §7');

            // ── Case C: BOTH pass → the union change applies ─────────────────
            $c = $union->open(UnionProcess::KIND_JOIN, $legislature, [$applicant], $constituents);
            $this->drivePassed($mjvSvc, $c->constituentProcess, $constituents);
            $union->markApplicantReferendum($c, $applicantRequired);
            $passed = $union->finalize($c->refresh());
            $this->assertSame(UnionProcess::STATUS_PASSED, $passed->status, 'both meters met → the union change passes');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /** Record yes consents until the supermajority MJV passes. */
    private function drivePassed(MultiJurisdictionVoteService $svc, MultiJurisdictionVote $mjv, array $constituents): void
    {
        $required = (int) $mjv->required;
        for ($i = 0; $i < $required; $i++) {
            $svc->recordConsent($mjv->refresh(), $constituents[$i], true);
        }
    }

    private function assertViolation(callable $fn, string $citation): void
    {
        try {
            $fn();
            $this->fail('Expected a ConstitutionalViolation.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame($citation, $e->citation);
        }
    }
}
