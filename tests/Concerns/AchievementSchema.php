<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Achievements in a named sqlite fixture that is NOT about achievements.
 *
 * AC-1 (2026-09-13) wired AchievementService into 43 engine handlers and two
 * seat-minting services. The real award path seals to the audit chain first
 * (a PostgreSQL advisory lock) and then reads and writes the `achievements`
 * ledger, so a DB-free fixture that drives a handler directly now needs:
 *   - the ledger tables (hasEarned reads before any award), and
 *   - a QUIET award service, because the fixture's AuditService is the real
 *     one and its advisory lock does not exist on sqlite.
 * Awards themselves are pinned by tests/Unit/AchievementWiringTest.php on its
 * own fixture with a mocked audit; nothing here weakens that.
 */
trait AchievementSchema
{
    /** Ledger tables plus a quiet award service. Call right after DB::setDefaultConnection(). */
    protected function createAchievementTables(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        if (! $schema->hasTable('achievements')) {
            $schema->create('achievements', function (Blueprint $t) {
                $t->string('id')->nullable();
                $t->uuid('user_id');
                $t->string('award_key');
                $t->string('title');
                $t->integer('audit_seq')->nullable();
                $t->timestamp('earned_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'award_key']);
            });
        }

        if (! $schema->hasTable('achievement_sweep_cursors')) {
            $schema->create('achievement_sweep_cursors', function (Blueprint $t) {
                $t->string('key')->primary();
                $t->string('cursor')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        app()->instance(AchievementService::class, new class extends AchievementService {
            public function __construct() {}

            public function awardSelf(User $filer, string $awardKey): bool { return false; }

            public function awardSubject(User $subject, string $awardKey): bool { return false; }

            public function awardState(User $holder, string $awardKey): bool { return false; }
        });
    }

    /**
     * A minimal users table for fixtures that never had one: the seat-minting
     * award sites resolve the holder with User::find before they award, and a
     * missing table is an error where a missing row is simply no award.
     * Call at the END of setUp so a fixture's own users table wins.
     */
    protected function createUsersTableIfMissing(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        if (! $schema->hasTable('users')) {
            $schema->create('users', function (Blueprint $t) {
                $t->string('id')->primary();
                $t->string('name')->nullable();
                $t->string('display_name')->nullable();
                $t->timestamps();
                $t->timestamp('deleted_at')->nullable();
            });
        }
    }
}
