<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * THE ACCEPT GATE (operator plan 2026-08-31): phase 3 is "verify phase 2,
 * then flip". This is the single owner of the completeness questions — the
 * accept endpoint's 422 payload, the wizard's phase-2 progress bars, and
 * the world build's own completion check all read the same report.
 */
final class WorldBuildVerifier
{
    /** @return array<string, mixed> the completeness report */
    public static function report(): array
    {
        $missingHeaders = (int) DB::scalar('
            SELECT COUNT(*) FROM legislatures l
             WHERE l.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM apportionment_ledger al WHERE al.legislature_id = l.id)
        ');
        $unsizedParents = (int) DB::scalar('
            SELECT COUNT(*) FROM jurisdictions j
             WHERE j.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
               AND NOT EXISTS (SELECT 1 FROM legislatures l WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
        ');
        $unsizedLeaves = (int) DB::scalar('
            SELECT COUNT(*) FROM jurisdictions j
             WHERE j.deleted_at IS NULL AND COALESCE(j.population, 0) > 0
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
               AND NOT EXISTS (SELECT 1 FROM legislatures l WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
        ');
        $compute = DB::selectOne("
            SELECT COUNT(*) FILTER (WHERE compute_status IN ('pending','running')) AS open,
                   COUNT(*) FILTER (WHERE compute_status = 'failed') AS failed,
                   COUNT(*) FILTER (WHERE compute_status = 'done')   AS done,
                   COUNT(*) AS total,
                   COUNT(*) FILTER (WHERE gate_reason IS NOT NULL)   AS refusals
              FROM apportionment_ledger
        ");
        $adjacency = AutoscaleClaims::precomputeEnabled()
            ? DB::selectOne("
                SELECT COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                       COUNT(*) AS total
                  FROM jurisdiction_adjacency_parents
              ")
            : (object) ['open' => 0, 'total' => 0];
        $mapless = (int) DB::scalar('SELECT COUNT(*) FROM apportionment_ledger WHERE map_id IS NULL');
        $keyless = (int) DB::scalar('SELECT COUNT(*) FROM apportionment_ledger WHERE block_rank IS NULL');
        $board = (bool) DB::scalar("
            SELECT EXISTS (SELECT 1 FROM election_boards b
                            JOIN jurisdictions j ON j.id = b.jurisdiction_id
                           WHERE j.adm_level = 0 AND b.status = 'active' AND b.deleted_at IS NULL)
        ");

        $complete = $missingHeaders === 0 && $unsizedParents === 0 && $unsizedLeaves === 0
            && (int) $compute->open === 0 && (int) $compute->failed === 0
            && (int) $adjacency->open === 0
            && $mapless === 0 && $keyless === 0 && $board;

        return [
            'complete'     => $complete,
            'legislatures' => [
                'missing_headers' => $missingHeaders,
                'unsized_parents' => $unsizedParents,
                'unsized_leaves'  => $unsizedLeaves,
            ],
            'apportionment' => [
                'open'     => (int) $compute->open,
                'failed'   => (int) $compute->failed,
                'done'     => (int) $compute->done,
                'total'    => (int) $compute->total,
                'refusals' => (int) $compute->refusals,
            ],
            'adjacency' => [
                'open'  => (int) $adjacency->open,
                'total' => (int) $adjacency->total,
            ],
            'maps'  => ['unstamped' => $mapless],
            'block_keys_missing' => $keyless,
            'board' => $board,
        ];
    }

    public static function complete(): bool
    {
        return (bool) self::report()['complete'];
    }
}
