<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\DisintermediationProcess;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Services\Jurisdictions\DisintermediationService;
use App\Services\MultiJurisdictionVoteService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. V §8 (Disintermediation). The intermediary dissolves
 * only on UNANIMITY of ALL its constituents AND the encompassing jurisdiction's
 * consent. A single non-consenting constituent defeats it; missing encompassing
 * consent defeats it. The unanimity rides the PROTECTED MultiJurisdictionVote at
 * BASIS_UNANIMITY (required = total, not a supermajority).
 *
 * If an edit breaks this test, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class DisintermediationUnanimityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_disinter_unanimity';

    public function test_disintermediation_requires_constituent_unanimity_and_encompassing_consent(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $svc = app(DisintermediationService::class);
            $mjvSvc = app(MultiJurisdictionVoteService::class);

            $legislature = Legislature::query()->whereNotNull('jurisdiction_id')->whereNull('deleted_at')->first();
            $rows = DB::table('jurisdictions')->whereNull('deleted_at')->limit(5)->pluck('id')->map('strval')->all();

            if ($legislature === null || count($rows) < 5) {
                $this->markTestSkipped('Live DB needs a legislature and ≥5 jurisdictions.');
            }

            $intermediary = $rows[0];
            $encompassing = $rows[1];
            $constituents = [$rows[2], $rows[3], $rows[4]]; // unanimity required = 3

            // ── Case A: a single non-consenting constituent defeats it ───────
            $a = $svc->open($legislature, $intermediary, $encompassing, $constituents);
            $mjv = $a->constituentProcess;
            $this->assertSame(MultiJurisdictionVote::BASIS_UNANIMITY, $mjv->basis);
            $this->assertSame(3, (int) $mjv->required, 'unanimity required = the count of constituents');
            $mjvSvc->recordConsent($mjv->refresh(), $constituents[0], true);
            $mjvSvc->recordConsent($mjv->refresh(), $constituents[1], true);
            $mjvSvc->recordConsent($mjv->refresh(), $constituents[2], false); // one NO
            $this->assertSame(MultiJurisdictionVote::STATUS_FAILED, $mjv->refresh()->status, 'one dissent fails unanimity');
            $svc->recordEncompassingConsent($a->refresh(), true);
            $this->assertViolation(fn () => $svc->finalize($a->refresh()), 'Art. V §8');

            // ── Case B: unanimous, but NO encompassing consent → defeated ────
            $b = $svc->open($legislature, $intermediary, $encompassing, $constituents);
            foreach ($constituents as $c) {
                $mjvSvc->recordConsent($b->constituentProcess->refresh(), $c, true);
            }
            $this->assertSame(MultiJurisdictionVote::STATUS_PASSED, $b->constituentProcess->refresh()->status);
            $svc->recordEncompassingConsent($b->refresh(), false);
            $this->assertViolation(fn () => $svc->finalize($b->refresh()), 'Art. V §8');

            // ── Case C: unanimous + encompassing consent → merged ────────────
            $c = $svc->open($legislature, $intermediary, $encompassing, $constituents);
            foreach ($constituents as $cj) {
                $mjvSvc->recordConsent($c->constituentProcess->refresh(), $cj, true);
            }
            $svc->recordEncompassingConsent($c->refresh(), true);
            $merged = $svc->finalize($c->refresh());
            $this->assertSame(DisintermediationProcess::STATUS_MERGED, $merged->status);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
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
