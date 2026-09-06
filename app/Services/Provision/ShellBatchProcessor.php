<?php

namespace App\Services\Provision;

use App\Services\AuditService;
use App\Services\InstitutionProvisionService;
use Illuminate\Support\Facades\DB;

/**
 * One shell batch (Wave 6): the six set-based, idempotent, live-unique shell
 * statements over the provision_ledger rows held under one claim token —
 * executives, judiciaries (the bench law), election boards, board members,
 * civic spaces, treasuries. Each statement commits on its own, so a kill
 * mid-batch loses at most one statement and the rerun is a no-op for the
 * rows it already wrote. ONE audit entry per batch carries the manifest.
 */
class ShellBatchProcessor
{
    public function __construct(
        private readonly InstitutionProvisionService $provision,
        private readonly AuditService $audit,
    ) {}

    /** The tables the shell predicates probe; their plans need statistics. */
    public const SHELL_TABLES = [
        'executives', 'judiciaries', 'election_boards', 'election_board_members', 'social_spaces', 'treasury_accounts',
    ];

    /**
     * @param  callable|null  $beat  fn(string $step): void — the lane's heartbeat between statements
     * @return array<string,int>  rows created per step
     */
    public function process(string $token, ?callable $beat = null): array
    {
        self::analyzeIfUnplanned();

        $created = [];
        foreach (InstitutionProvisionService::STEPS as $step) {
            $created[$step] = $this->provision->provisionClaim($step, $token);
            if ($beat !== null) {
                $beat($step);
            }
        }

        // The rows advance to stage 1 (shells done) and return to the pile
        // for the unit lane. Zero-seat chambers keep their shells (a place is
        // a polity even while its chamber is inactive) and are skipped by the
        // seat step, not here.
        $advanced = DB::update("
            UPDATE provision_ledger
               SET stage = 1, status = 'pending', claim_token = NULL, updated_at = now()
             WHERE claim_token = ?::uuid AND status = 'running' AND stage = 0
        ", [$token]);

        if (array_sum($created) > 0) {
            $this->audit->append(
                module: 'jurisdictions',
                event: 'institutions_provisioned',
                payload: [
                    'step'      => 'shell_batch',
                    'created'   => $created,
                    'rows'      => $advanced,
                    'set_based' => true,
                    'generator' => 'Step 4 engine (Wave 6)',
                ],
                ref: 'WF-JUR-01',
            );
        }

        return $created + ['rows' => $advanced];
    }

    /**
     * THE PLANNER NEEDS STATISTICS (dry run 2026-09-06): election_boards,
     * election_board_members, social_spaces and treasury_accounts start near
     * empty and unanalyzed on a fresh world, so the NOT EXISTS probes plan as
     * sequential scans and each batch grows slower than the last (2.5 s, then
     * 54 s, 107 s, 160 s, 213 s). A table that was never analyzed is analyzed
     * once here (sub-second on a small table); the pump re-analyzes every
     * minute while shells are pending.
     */
    public static function analyzeIfUnplanned(): void
    {
        $stale = DB::table('pg_stat_user_tables')
            ->whereIn('relname', self::SHELL_TABLES)
            ->whereNull('last_analyze')
            ->whereNull('last_autoanalyze')
            ->pluck('relname')
            ->all();
        foreach ($stale as $table) {
            DB::statement('ANALYZE '.$table);
        }
    }

    /** The pump's per-minute refresh while shells are landing. */
    public static function analyzeAll(): void
    {
        foreach (array_merge(self::SHELL_TABLES, ['provision_ledger']) as $table) {
            DB::statement('ANALYZE '.$table);
        }
    }
}
