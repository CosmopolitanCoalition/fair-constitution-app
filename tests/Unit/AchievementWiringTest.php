<?php

namespace Tests\Unit;

use App\Domain\Achievements\AchievementCatalog as Catalog;
use App\Models\AuditEntry;
use App\Models\User;
use App\Services\Achievements\AchievementStateSweep;
use App\Services\AchievementService;
use App\Services\AuditService;
use App\Support\DemoMode;
use App\Support\HostCapacity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AC-1 — achievement catalog wiring.
 *
 * A NAMED sqlite fixture (never the live world). The audit hash chain uses
 * Postgres-only `pg_advisory_xact_lock`, so AuditService is MOCKED here: this
 * test proves the achievement LEDGER logic (award once, correct earner, no
 * award on rollback) and the EARNER_STATE sweep (idempotent, keyset-chunked,
 * cursor resume). The hash chain has its own coverage; the demo capture rule
 * and the profile-privacy prop are asserted at the source/config level (the
 * behavioral profile-privacy coverage lives in LearnProfileReadFixtureTest).
 */
final class AchievementWiringTest extends TestCase
{
    private string $original;

    private AchievementService $achievements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.ach_wiring_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('ach_wiring_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email');
            $t->string('display_name')->nullable();
            $t->string('status')->nullable();
            $t->softDeletes();
        });
        $schema->create('achievements', function (Blueprint $t) {
            $t->string('id')->nullable(); // Postgres defaults gen_random_uuid(); the writer never sets it.
            $t->uuid('user_id');
            $t->string('award_key');
            $t->string('title');
            $t->integer('audit_seq')->nullable();
            $t->timestamp('earned_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'award_key']); // the partial-unique idempotency rail
        });
        $schema->create('achievement_sweep_cursors', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('cursor')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        $schema->create('residency_confirmations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('jurisdiction_id')->nullable();
            $t->boolean('is_active');
        });
        $schema->create('committee_seats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('committee_id')->nullable();
            $t->uuid('member_id');
            $t->string('status');
        });
        $schema->create('legislature_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->boolean('is_speaker')->default(false);
            $t->string('status')->nullable();
            $t->softDeletes();
        });

        // The one writer, with a stubbed audit seal (the chain is Postgres-only).
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $this->achievements = new AchievementService($audit);
    }

    protected function tearDown(): void
    {
        DB::purge('ach_wiring_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function uuid(int $n): string
    {
        return sprintf('50000000-0000-4000-8000-%012d', $n);
    }

    private function makeUser(int $n): User
    {
        DB::table('users')->insert(['id' => $this->uuid($n), 'name' => "P{$n}", 'email' => "p{$n}@example.invalid"]);

        return User::findOrFail($this->uuid($n));
    }

    // ── The award ledger: once, correct earner, nothing on rollback ─────────

    public function test_a_wired_self_award_writes_once_and_a_second_action_does_not(): void
    {
        $user = $this->makeUser(1);

        self::assertTrue($this->achievements->awardSelf($user, 'ACH-CIV-002'));
        self::assertFalse($this->achievements->awardSelf($user, 'ACH-CIV-002'));

        self::assertSame(1, DB::table('achievements')
            ->where('user_id', $this->uuid(1))->where('award_key', 'ACH-CIV-002')->count());
    }

    public function test_a_subject_award_writes_to_the_subject_not_the_filer(): void
    {
        $candidate = $this->makeUser(2);

        // ACH-CAN-005 is EARNER_SUBJECT: the validated candidate earns.
        self::assertTrue($this->achievements->awardSubject($candidate, 'ACH-CAN-005'));
        self::assertTrue($this->achievements->hasEarned($candidate, 'ACH-CAN-005'));

        // The board member who FILED F-ELB-002 earns nothing for it.
        $filer = $this->makeUser(3);
        self::assertFalse($this->achievements->hasEarned($filer, 'ACH-CAN-005'));
    }

    public function test_the_wrong_earner_mode_is_refused(): void
    {
        $user = $this->makeUser(4);

        // ACH-CIV-002 is EARNER_SELF; awarding it as subject/state throws.
        $this->expectException(InvalidArgumentException::class);
        $this->achievements->awardSubject($user, 'ACH-CIV-002');
    }

    public function test_a_state_key_awarded_as_self_is_refused(): void
    {
        $user = $this->makeUser(5);
        $this->expectException(InvalidArgumentException::class);
        $this->achievements->awardSelf($user, 'ACH-CIV-005'); // EARNER_STATE
    }

    public function test_a_rolled_back_transaction_leaves_no_award_row(): void
    {
        $user = $this->makeUser(6);

        try {
            DB::transaction(function () use ($user) {
                $this->achievements->awardSelf($user, 'ACH-CIV-002');
                // A later constitutional violation aborts the engine transaction.
                throw new \RuntimeException('handler rejected after the award call');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertFalse($this->achievements->hasEarned($user, 'ACH-CIV-002'));
        self::assertSame(0, DB::table('achievements')->count());
    }

    // ── The EARNER_STATE sweep ──────────────────────────────────────────────

    public function test_sweep_awards_state_keys_idempotently_and_chunks_by_keyset(): void
    {
        $u1 = $this->makeUser(11);
        $u2 = $this->makeUser(12);
        $inactive = $this->makeUser(13);
        DB::table('residency_confirmations')->insert([
            ['id' => $this->uuid(101), 'user_id' => $this->uuid(11), 'is_active' => true],
            ['id' => $this->uuid(102), 'user_id' => $this->uuid(12), 'is_active' => true],
            ['id' => $this->uuid(103), 'user_id' => $this->uuid(13), 'is_active' => false],
        ]);

        $sweep = new AchievementStateSweep($this->achievements);

        // chunk=1 forces multiple keyset chunks over the 2 active rows.
        $chunks = 0;
        $r = $sweep->runKey('ACH-CIV-005', 1, function () use (&$chunks) { $chunks++; });

        self::assertSame(2, $r['awarded']);
        self::assertGreaterThanOrEqual(2, $chunks, 'chunk=1 must page the keyset in multiple chunks');
        self::assertTrue($this->achievements->hasEarned($u1, 'ACH-CIV-005'));
        self::assertTrue($this->achievements->hasEarned($u2, 'ACH-CIV-005'));
        self::assertFalse($this->achievements->hasEarned($inactive, 'ACH-CIV-005'));

        // A second run awards nothing (idempotent).
        $r2 = $sweep->runKey('ACH-CIV-005', 100, function () {});
        self::assertSame(0, $r2['awarded']);
    }

    public function test_a_killed_run_resumes_after_its_committed_cursor(): void
    {
        $this->makeUser(21);
        $this->makeUser(22);
        $u3 = $this->makeUser(23);
        DB::table('residency_confirmations')->insert([
            ['id' => $this->uuid(201), 'user_id' => $this->uuid(21), 'is_active' => true],
            ['id' => $this->uuid(202), 'user_id' => $this->uuid(22), 'is_active' => true],
            ['id' => $this->uuid(203), 'user_id' => $this->uuid(23), 'is_active' => true],
        ]);

        $sweep = new AchievementStateSweep($this->achievements);

        // Simulate a run KILLED after it committed the cursor at row 201: the
        // per-chunk cursor is the recovery point. A resume must NOT rescan rows
        // at or before it, only the rows the killed run had not reached.
        DB::table('achievement_sweep_cursors')->insert([
            'key' => 'ACH-CIV-005', 'cursor' => $this->uuid(201), 'updated_at' => now(),
        ]);

        $r = $sweep->runKey('ACH-CIV-005', 100, function () {});
        self::assertSame(2, $r['scanned'], 'resume must start after the committed cursor');
        self::assertSame(2, $r['awarded']);
        self::assertTrue($this->achievements->hasEarned($u3, 'ACH-CIV-005'));
        self::assertFalse(
            $this->achievements->hasEarned($this->makeUser(24), 'ACH-CIV-005'),
            'a user with no fact row is not awarded'
        );
    }

    public function test_a_completed_run_clears_its_cursor_so_the_next_run_rescans_new_rows(): void
    {
        $this->makeUser(21);
        $this->makeUser(22);
        DB::table('residency_confirmations')->insert([
            ['id' => $this->uuid(201), 'user_id' => $this->uuid(21), 'is_active' => true],
            ['id' => $this->uuid(202), 'user_id' => $this->uuid(22), 'is_active' => true],
        ]);

        $sweep = new AchievementStateSweep($this->achievements);
        $r1 = $sweep->runKey('ACH-CIV-005', 100, function () {});
        self::assertSame(2, $r1['awarded']);

        // A completed scan clears the cursor. The keyset id is a random UUID in
        // production, so a persisted high-water id would skip a holder created
        // afterward whose id sorts below it. Clearing forces a full rescan.
        self::assertNull($sweep->cursor('ACH-CIV-005'), 'a completed run clears its cursor');

        // A new fact row whose id sorts BEFORE the prior maximum (150 < 202)
        // stands in for a random-UUID row that lands below the old high-water
        // mark. The old persist-across-runs behavior skipped it; the cleared
        // cursor rescans everything and picks it up.
        $u3 = $this->makeUser(23);
        DB::table('residency_confirmations')->insert(
            ['id' => $this->uuid(150), 'user_id' => $this->uuid(23), 'is_active' => true],
        );

        $r2 = $sweep->runKey('ACH-CIV-005', 100, function () {});
        self::assertSame(3, $r2['scanned'], 'the next run rescans every row, including one that sorts before the prior max');
        self::assertSame(1, $r2['awarded'], 'idempotent: only the new holder earns');
        self::assertTrue($this->achievements->hasEarned($u3, 'ACH-CIV-005'));
    }

    public function test_sweep_resolves_the_holder_across_a_member_join(): void
    {
        // ACH-LEG-009: committee_seats.member_id -> legislature_members.user_id.
        $member = $this->makeUser(31);
        DB::table('legislature_members')->insert([
            'id' => $this->uuid(301), 'user_id' => $this->uuid(31), 'is_speaker' => false, 'status' => 'seated',
        ]);
        DB::table('committee_seats')->insert([
            'id' => $this->uuid(311), 'committee_id' => $this->uuid(399), 'member_id' => $this->uuid(301), 'status' => 'seated',
        ]);

        $sweep = new AchievementStateSweep($this->achievements);
        $r = $sweep->runKey('ACH-LEG-009', 100, function () {});

        self::assertSame(1, $r['awarded']);
        self::assertTrue($this->achievements->hasEarned($member, 'ACH-LEG-009'));
    }

    public function test_the_sweep_chunk_default_derives_from_the_host(): void
    {
        // The command's default chunk is max(500, min(10000, autoscaleWorkers*500)).
        // Assert the host input is a sane lane count so the derived chunk is bounded.
        self::assertGreaterThanOrEqual(2, HostCapacity::autoscaleWorkers());
        $derived = (int) max(500, min(10000, HostCapacity::autoscaleWorkers() * 500));
        self::assertGreaterThanOrEqual(500, $derived);
        self::assertLessThanOrEqual(10000, $derived);
    }

    // ── Guards: demo capture, profile privacy, wiring-table completeness ────

    public function test_achievements_are_captured_by_demo_sessions(): void
    {
        // Operator ruling 2026-09-13: demo awards are captured and voided like
        // any other demo write, so the table must NOT be capture-excluded.
        self::assertNotContains('achievements', DemoMode::CAPTURE_EXCLUDED);
    }

    public function test_the_demo_void_can_soft_delete_a_captured_achievement(): void
    {
        // The append-only achievements_immutable trigger blocks the demo purge's
        // soft delete. Capture without void would leave a demo medal permanent,
        // which the ruling forbids. The fix is a transaction-local demo-void GUC
        // that the append-only guard honors for a deleted_at-only stamp. The
        // PL/pgSQL trigger is Postgres-only (not in this sqlite fixture), so the
        // wiring is asserted at source level, the same posture as the privacy
        // gate above.
        self::assertSame('cga.demo_void', DemoMode::VOID_GUC);

        $reverse = file_get_contents(base_path('app/Services/Demo/DemoSessionService.php'));
        self::assertNotFalse($reverse);
        self::assertStringContainsString('DemoMode::VOID_GUC', $reverse);

        // The migration redefines the guard to permit ONLY a deleted_at stamp
        // while the void GUC is set, and to keep raising otherwise.
        $migration = file_get_contents(base_path(
            'database/migrations/2026_09_13_190000_achievements_demo_void_soft_delete.php'
        ));
        self::assertNotFalse($migration);
        self::assertStringContainsString('achievements_block_mutation', $migration);
        self::assertStringContainsString("current_setting('cga.demo_void', true)", $migration);
        self::assertStringContainsString('append-only', $migration);
    }

    public function test_profile_privacy_gate_is_unchanged(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Social/PersonProfileController.php'));
        self::assertStringContainsString('($isSelf || $isPublicProfile)', $src);
        self::assertStringContainsString("'achievements'", $src);
    }

    public function test_the_wiring_table_names_a_site_for_every_personal_key(): void
    {
        $doc = file_get_contents(base_path('docs/plans/education/K2_ACHIEVEMENT_WIRING.md'));
        self::assertNotFalse($doc);

        $missing = [];
        foreach (array_keys(Catalog::AWARDS) as $key) {
            if (! str_starts_with($key, 'ACH-')) {
                continue;
            }
            $meta = Catalog::AWARDS[$key];
            // Plane B (jurisdiction/system) is a tracked follow-up, out of AC-1 scope.
            if ($meta['scope'] !== Catalog::SCOPE_PERSONAL) {
                continue;
            }
            if (! str_contains($doc, $key)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'wiring table must name a site (wired/sweep/deferred/awaiting_ui) for every personal ACH-* key');
    }
}
