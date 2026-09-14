<?php

namespace Tests\Unit;

use App\Domain\Forms\Handlers\AttendanceRegistration;
use App\Domain\Forms\Handlers\CandidateValidation;
use App\Models\User;
use App\Services\Achievements\AchievementStateSweep;
use App\Services\AchievementService;
use App\Services\AuditService;
use App\Services\JourneyService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * L1 review — achievements over a REAL PostgreSQL schema, sealed by the REAL
 * audit chain (pg_advisory_xact_lock + hash chain), with the REAL append-only
 * trigger. This complements AchievementWiringTest, which mocks the audit chain
 * on a sqlite fixture; here every award seals to a real audit_log and the
 * achievements_immutable trigger enforces write-once at the database.
 *
 * Posture (the AchievementsPageTest / JourneysTest live-pg posture): a
 * disposable full-schema database loaded via `php artisan migrate` (schema dump
 * + additive migrations). Each test runs inside one transaction that is always
 * rolled back. Requires LIVE_PG_DATABASE to name a disposable cga_ach_* fixture
 * — the guard below refuses the live world.
 *
 * The two verified actions drive the REAL handlers directly (the posture
 * IsolatedLessonWorkflowTest uses for LearnController): the award logic lives in
 * the handler, so the handler is what proves the proper person earns.
 */
final class IsolatedAchievementJourneyTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_ach_journey';

    private function id(int $n): string
    {
        return sprintf('70000000-0000-4000-8000-%012d', $n);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $db = DB::connection(self::LIVE_CONNECTION)->selectOne('select current_database() as name')->name;
        if ($db === 'fair_constitution' || ! preg_match('/\Acga_ach_[0-9]{8}_[a-f0-9]{16}\z/', (string) $db)) {
            $this->markTestSkipped('Refusing to run on non-disposable database ['.$db.']; point LIVE_PG_DATABASE at a cga_ach_* fixture.');
        }

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        // Real writer, real audit seal — bound so the handlers' app(...) resolve here.
        $this->app->instance(AchievementService::class, new AchievementService(new AuditService));

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

    private function achievements(): AchievementService
    {
        return app(AchievementService::class);
    }

    /** The FK target every fact table points at. */
    private function seedJurisdiction(): string
    {
        DB::table('jurisdictions')->insert([
            'id' => $this->id(1), 'name' => 'Fixture jurisdiction', 'slug' => 'fixture-'.substr($this->id(1), -12),
        ]);

        return $this->id(1);
    }

    private function makeUser(int $n, string $name): User
    {
        DB::table('users')->insert([
            'id' => $this->id($n),
            'name' => $name,
            'email' => 'ach-journey-'.$n.'@test.invalid',
            'password' => 'x',
            'terms_accepted_at' => now(),
        ]);

        return User::findOrFail($this->id($n));
    }

    // ── Verified action: earner is the SUBJECT, never the filer (F-ELB-002) ──

    public function test_a_validation_filing_awards_the_candidate_not_the_board_member(): void
    {
        $this->onLivePg(function () {
            $jurisdiction = $this->seedJurisdiction();
            $candidate = $this->makeUser(2, 'Candidate');
            $boardMember = $this->makeUser(3, 'Board member (filer)');

            DB::table('election_boards')->insert([
                'id' => $this->id(11), 'jurisdiction_id' => $jurisdiction, 'status' => 'active',
            ]);
            DB::table('elections')->insert([
                'id' => $this->id(10), 'jurisdiction_id' => $jurisdiction,
                'election_board_id' => $this->id(11),
            ]);
            DB::table('election_board_members')->insert([
                'id' => $this->id(12), 'election_board_id' => $this->id(11),
                'user_id' => $boardMember->id, 'status' => 'seated',
            ]);
            // An at-large race: footprint is the race's own jurisdiction.
            DB::table('election_races')->insert([
                'id' => $this->id(13), 'election_id' => $this->id(10), 'jurisdiction_id' => $jurisdiction,
                'district_id' => null, 'type_b_panel_id' => null,
                'seat_kind' => 'type_a', 'seats' => 5, 'finalist_count' => 5,
            ]);
            DB::table('residency_confirmations')->insert([
                'user_id' => $candidate->id, 'jurisdiction_id' => $jurisdiction,
                'is_active' => true, 'days_confirmed' => 30, 'depth' => 0, 'confirmed_at' => now(),
            ]);
            DB::table('candidacies')->insert([
                'id' => $this->id(14), 'election_id' => $this->id(10), 'user_id' => $candidate->id,
                'status' => 'registered', 'residency_attested_at' => now(),
            ]);

            $result = app(CandidateValidation::class)->handle($boardMember, [
                'candidacy_id' => $this->id(14),
                'decision' => 'validate',
            ]);

            self::assertSame('validated', $result['decision']);

            // The CANDIDATE earns ACH-CAN-005 (EARNER_SUBJECT); the filer does not.
            self::assertTrue($this->achievements()->hasEarned($candidate, 'ACH-CAN-005'));
            self::assertFalse($this->achievements()->hasEarned($boardMember, 'ACH-CAN-005'));
            self::assertSame(1, DB::table('achievements')
                ->where('user_id', $candidate->id)->where('award_key', 'ACH-CAN-005')->count());

            // The award sealed a real audit row (not a mock).
            self::assertSame(1, DB::table('audit_log')
                ->where('event', 'achievement/earned')->where('actor_user_id', $candidate->id)->count());

            // Retry: a second awardSubject is a no-op; no second row, no re-award.
            self::assertFalse($this->achievements()->awardSubject($candidate, 'ACH-CAN-005'));
            self::assertSame(1, DB::table('achievements')
                ->where('user_id', $candidate->id)->where('award_key', 'ACH-CAN-005')->count());

            // Profile source reads the real earned row.
            $rows = (new JourneyService(new AuditService, $this->achievements()))->achievementsFor($candidate);
            self::assertContains('ACH-CAN-005', array_column($rows, 'award_key'));

            self::assertTrue((new AuditService)->verifyChain());
        });
    }

    // ── Verified action: earner is the filer; unrelated actor earns nothing ──

    public function test_an_attendance_filing_awards_the_actor_and_an_unrelated_actor_earns_nothing(): void
    {
        $this->onLivePg(function () {
            $jurisdiction = $this->seedJurisdiction();
            $member = $this->makeUser(2, 'Seated member');
            $stranger = $this->makeUser(3, 'Unrelated actor');

            DB::table('legislatures')->insert([
                'id' => $this->id(20), 'jurisdiction_id' => $jurisdiction,
            ]);
            DB::table('legislature_members')->insert([
                'id' => $this->id(21), 'legislature_id' => $this->id(20),
                'user_id' => $member->id, 'status' => 'seated',
            ]);
            DB::table('legislature_sessions')->insert([
                'id' => $this->id(22), 'legislature_id' => $this->id(20),
                'session_no' => 1, 'status' => 'open',
            ]);

            $result = app(AttendanceRegistration::class)->handle($member, [
                'session_id' => $this->id(22),
            ]);
            self::assertSame('present', $result['status']);

            self::assertTrue($this->achievements()->hasEarned($member, 'ACH-LEG-002'));
            self::assertFalse($this->achievements()->hasEarned($stranger, 'ACH-LEG-002'));
            self::assertSame(1, DB::table('achievements')->where('award_key', 'ACH-LEG-002')->count());

            // Filing again does not re-award (insertOrIgnore is a no-op).
            app(AttendanceRegistration::class)->handle($member, ['session_id' => $this->id(22)]);
            self::assertSame(1, DB::table('achievements')->where('award_key', 'ACH-LEG-002')->count());
        });
    }

    // ── The refusal rail on the real path (not the mocked fixture) ──────────

    public function test_the_wrong_earner_mode_is_refused_before_any_write(): void
    {
        $this->onLivePg(function () {
            $user = $this->makeUser(2, 'Person');

            // ACH-CAN-005 is EARNER_SUBJECT; awarding as self must refuse.
            try {
                $this->achievements()->awardSelf($user, 'ACH-CAN-005');
                self::fail('awarding a subject achievement as self must be refused');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('subject', $e->getMessage());
            }

            // ACH-CIV-002 is EARNER_SELF; awarding as subject must refuse.
            try {
                $this->achievements()->awardSubject($user, 'ACH-CIV-002');
                self::fail('awarding a self achievement as subject must be refused');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('self', $e->getMessage());
            }

            self::assertSame(0, DB::table('achievements')->count());
        });
    }

    // ── The EARNER_STATE sweep over real fact tables ────────────────────────

    public function test_the_state_sweep_awards_holders_idempotently_by_keyset(): void
    {
        $this->onLivePg(function () {
            $jurisdiction = $this->seedJurisdiction();
            $active1 = $this->makeUser(2, 'Active resident 1');
            $active2 = $this->makeUser(3, 'Active resident 2');
            $inactive = $this->makeUser(4, 'Inactive resident');

            DB::table('residency_confirmations')->insert([
                ['id' => $this->id(30), 'user_id' => $active1->id, 'jurisdiction_id' => $jurisdiction, 'is_active' => true, 'days_confirmed' => 30, 'depth' => 0, 'confirmed_at' => now()],
                ['id' => $this->id(31), 'user_id' => $active2->id, 'jurisdiction_id' => $jurisdiction, 'is_active' => true, 'days_confirmed' => 30, 'depth' => 0, 'confirmed_at' => now()],
                ['id' => $this->id(32), 'user_id' => $inactive->id, 'jurisdiction_id' => $jurisdiction, 'is_active' => false, 'days_confirmed' => 30, 'depth' => 0, 'confirmed_at' => now()],
            ]);

            $sweep = new AchievementStateSweep($this->achievements());

            // chunk=1 forces multiple keyset chunks over the 2 active rows.
            $chunks = 0;
            $r = $sweep->runKey('ACH-CIV-005', 1, function () use (&$chunks) { $chunks++; });

            self::assertSame(2, $r['awarded']);
            self::assertGreaterThanOrEqual(2, $chunks, 'chunk=1 must page the keyset in multiple chunks');
            self::assertTrue($this->achievements()->hasEarned($active1, 'ACH-CIV-005'));
            self::assertTrue($this->achievements()->hasEarned($active2, 'ACH-CIV-005'));
            self::assertFalse($this->achievements()->hasEarned($inactive, 'ACH-CIV-005'));

            // A completed run clears its cursor.
            self::assertNull($sweep->cursor('ACH-CIV-005'));

            // A second sweep awards nothing (idempotent).
            $r2 = $sweep->runKey('ACH-CIV-005', 100, function () {});
            self::assertSame(0, $r2['awarded']);
            self::assertSame(2, DB::table('achievements')->where('award_key', 'ACH-CIV-005')->count());
        });
    }

    public function test_the_sweep_resolves_the_holder_across_a_member_join(): void
    {
        $this->onLivePg(function () {
            $this->seedJurisdiction();
            $member = $this->makeUser(2, 'Committee member');
            DB::table('legislatures')->insert(['id' => $this->id(20), 'jurisdiction_id' => $this->id(1)]);
            DB::table('legislature_members')->insert([
                'id' => $this->id(21), 'legislature_id' => $this->id(20), 'user_id' => $member->id, 'status' => 'seated',
            ]);
            DB::table('committees')->insert([
                'id' => $this->id(40), 'legislature_id' => $this->id(20), 'status' => 'created',
                'name' => 'Fixture committee', 'seats' => 5,
            ]);
            DB::table('committee_seats')->insert([
                'id' => $this->id(41), 'committee_id' => $this->id(40), 'member_id' => $this->id(21), 'status' => 'seated',
            ]);

            $sweep = new AchievementStateSweep($this->achievements());
            $r = $sweep->runKey('ACH-LEG-009', 100, function () {});

            self::assertSame(1, $r['awarded']);
            self::assertTrue($this->achievements()->hasEarned($member, 'ACH-LEG-009'));
        });
    }

    // ── No award on rollback; write-once at the database ────────────────────

    public function test_a_rolled_back_filing_leaves_no_award_and_no_audit_growth(): void
    {
        $this->onLivePg(function () {
            $user = $this->makeUser(2, 'Person');
            $auditBefore = (int) DB::table('audit_log')->count();

            try {
                DB::transaction(function () use ($user) {
                    $this->achievements()->awardSelf($user, 'ACH-CIV-002');
                    // A later constitutional violation aborts the engine transaction.
                    throw new \RuntimeException('handler rejected after the award call');
                });
            } catch (\RuntimeException) {
                // expected
            }

            self::assertFalse($this->achievements()->hasEarned($user, 'ACH-CIV-002'));
            self::assertSame(0, DB::table('achievements')->count());
            // The seal rolled back with the row — the chain did not grow.
            self::assertSame($auditBefore, (int) DB::table('audit_log')->count());
        });
    }

    public function test_the_append_only_trigger_rejects_mutation_of_an_awarded_row(): void
    {
        $this->onLivePg(function () {
            $user = $this->makeUser(2, 'Person');
            self::assertTrue($this->achievements()->awardSelf($user, 'ACH-CIV-002'));

            // The achievements_immutable trigger forbids UPDATE — an award is final.
            try {
                DB::table('achievements')->where('user_id', $user->id)->update(['title' => 'tampered']);
                self::fail('the append-only trigger must reject an UPDATE');
            } catch (\Illuminate\Database\QueryException $e) {
                self::assertMatchesRegularExpression('/append-only|immutable/i', $e->getMessage());
            }
        });
    }
}
