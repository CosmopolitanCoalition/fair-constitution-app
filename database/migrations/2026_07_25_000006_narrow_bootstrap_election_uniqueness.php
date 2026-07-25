<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Narrow yesterday's over-broad elections index to the race it was meant to close.
 *
 * `2026_07_25_000002` added `elections_open_general_uq` — one unfinished
 * GENERAL election per jurisdiction. That was wrong, and the constitutional
 * suite caught it: `PeerUpgradeAgreementTest` legitimately creates a second
 * general election for the same jurisdiction while probing the Art. II §7
 * version guard, and the index rejected it.
 *
 * The index was over-reaching. The concurrency hazard it was written for is
 * specific: `ActivationService::scheduleBootstrapElection()` adopts an existing
 * election if it finds one and otherwise files F-ELB-001 — a read-then-write,
 * so two provisioning workers could mint two BOOTSTRAP elections for one
 * jurisdiction. Ordinary general elections across successive cycles are lawful
 * and none of this engine's business; a jurisdiction may well have an old one
 * still tabulating while the next is scheduled.
 *
 * So: scope the guard to `trigger = 'bootstrap'` (ActivationService.php:746),
 * which is exactly the duplicate the provisioning path can create and nothing
 * more.
 *
 * The lesson is worth keeping: a uniqueness constraint asserts a
 * constitutional invariant. Asserting one wider than the law actually requires
 * does not make the system safer — it makes lawful states unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS elections_open_general_uq');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS elections_bootstrap_general_uq
                ON elections (jurisdiction_id)
             WHERE kind = 'general'
               AND trigger = 'bootstrap'
               AND deleted_at IS NULL
               AND status NOT IN ('certified', 'final', 'cancelled')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS elections_bootstrap_general_uq');
    }
};
