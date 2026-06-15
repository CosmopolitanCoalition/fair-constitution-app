<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Legislature;
use App\Models\LocalAutonomyProcess;
use App\Models\MultiJurisdictionVote;
use App\Models\User;
use App\Services\Jurisdictions\LocalAutonomyService;
use App\Services\MultiJurisdictionVoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G6 the autonomy vote). Authority over a real place
 * is EARNED by population (a seated government) and GRANTED by that place's current
 * authoritative (parent) government — never claimed unilaterally, never handed out
 * by an admin. The pins:
 *  1. autonomy can be SOUGHT only by a jurisdiction with a seated government;
 *  2. the flip is DUAL-gated — it requires BOTH the promoting jurisdiction's
 *     supermajority AND the parent's consent; either alone is refused and flips
 *     nothing;
 *  3. on dual passage, and only then, the subtree's authoritative_server_id flips
 *     to the gaining cluster.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class LocalAutonomyGovernedTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_local_autonomy';

    public function test_autonomy_can_only_be_sought_by_a_seated_government(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->leafWithParent();
            $forming = $this->legislatureFor($jurisdictionId, Legislature::STATUS_FORMING);

            $threw = false;
            try {
                app(LocalAutonomyService::class)->open($forming, (string) Str::uuid());
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertStringContainsStringIgnoringCase('seated', $e->getMessage());
            }

            $this->assertTrue($threw, 'a forming (unseated) government cannot seek autonomy');
        });
    }

    public function test_the_flip_is_dual_gated_and_never_unilateral(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->leafWithParent();
            $gaining = (string) Str::uuid();
            $legislature = $this->legislatureFor($jurisdictionId, Legislature::STATUS_ACTIVE);
            $svc = app(LocalAutonomyService::class);

            $before = DB::table('jurisdictions')->where('id', $jurisdictionId)->value('authoritative_server_id');

            // Neither meter → refused, flips nothing.
            $this->assertFinalizeBlocked($svc, $svc->open($legislature, $gaining));
            $this->assertSame($before, DB::table('jurisdictions')->where('id', $jurisdictionId)->value('authoritative_server_id'),
                'no flip with neither meter');

            // PROMOTING supermajority alone (parent has NOT consented) → refused.
            $this->seedResidents($jurisdictionId, 3);
            $promotingOnly = $svc->open($legislature, $gaining);
            $svc->markPromotingSupermajority($promotingOnly, 3);
            $this->assertFinalizeBlocked($svc, $promotingOnly);

            // PARENT consent alone (promoting NOT at supermajority) → refused.
            $parentOnly = $svc->open($legislature, $gaining);
            $this->consentParent($parentOnly);
            $this->assertFinalizeBlocked($svc, $parentOnly);

            $this->assertSame($before, DB::table('jurisdictions')->where('id', $jurisdictionId)->value('authoritative_server_id'),
                'one meter alone never flips authority');
        });
    }

    public function test_dual_passage_flips_authority_to_the_gaining_cluster(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->leafWithParent();
            $gaining = (string) Str::uuid();
            $svc = app(LocalAutonomyService::class);
            $this->seedResidents($jurisdictionId, 3);

            $process = $svc->open($this->legislatureFor($jurisdictionId, Legislature::STATUS_ACTIVE), $gaining);
            $svc->markPromotingSupermajority($process, 3); // population 3 → supermajority 2 → met
            $this->consentParent($process);                 // parent MJV passes (supermajority of 1 = 1)

            $passed = $svc->finalize($process);

            $this->assertSame(LocalAutonomyProcess::STATUS_PASSED, $passed->status);
            $this->assertSame($gaining, $passed->resulting_authoritative_server_id);
            $this->assertSame(
                $gaining,
                (string) DB::table('jurisdictions')->where('id', $jurisdictionId)->value('authoritative_server_id'),
                'on dual passage the subtree authority flips to the gaining cluster'
            );
        });
    }

    private function assertFinalizeBlocked(LocalAutonomyService $svc, LocalAutonomyProcess $process): void
    {
        $threw = false;
        try {
            $svc->finalize($process);
        } catch (ConstitutionalViolation) {
            $threw = true;
        }
        $this->assertTrue($threw, 'finalize must refuse without BOTH meters');
        $this->assertSame(LocalAutonomyProcess::STATUS_FAILED, $process->fresh()->status);
    }

    private function consentParent(LocalAutonomyProcess $process): void
    {
        $mjv = MultiJurisdictionVote::query()->findOrFail($process->parent_process_id);
        app(MultiJurisdictionVoteService::class)->recordConsent($mjv, (string) $process->parent_jurisdiction_id, true);
    }

    /** A leaf jurisdiction with a parent and no existing legislature (room to seat one). */
    private function leafWithParent(): string
    {
        $id = DB::table('jurisdictions as j')
            ->whereNotNull('j.parent_id')
            ->whereNull('j.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('jurisdictions as c')->whereColumn('c.parent_id', 'j.id')->whereNull('c.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('legislatures as l')->whereColumn('l.jurisdiction_id', 'j.id')->whereNull('l.deleted_at'))
            ->value('j.id');

        if ($id === null) {
            $this->markTestSkipped('Live DB has no leaf jurisdiction with a parent and no legislature.');
        }

        return (string) $id;
    }

    private function legislatureFor(string $jurisdictionId, string $status): Legislature
    {
        return Legislature::create([
            'jurisdiction_id' => $jurisdictionId,
            'status'          => $status,
            'total_seats'     => 5,
            'type_a_seats'    => 5,
            'type_b_seats'    => 0,
            'term_number'     => 1,
            'quorum_required' => 3,
        ]);
    }

    private function seedResidents(string $jurisdictionId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $user = User::create([
                'name'              => 'Autonomy Resident '.Str::uuid(),
                'email'             => 'autonomy-'.Str::uuid().'@test.invalid',
                'password'          => Str::random(32),
                'terms_accepted_at' => now(),
            ]);

            DB::table('residency_confirmations')->insert([
                'id'              => (string) Str::uuid(),
                'user_id'         => $user->id,
                'jurisdiction_id' => $jurisdictionId,
                'days_confirmed'  => 30,
                'confirmed_at'    => now(),
                'is_active'       => true,
                'depth'           => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
