<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WI-7 — institution stub generation, extracted from
 * SetupController::generateInstitutionStubs so Setup Step 4 and the
 * activation engine (ActivationService) share one implementation.
 *
 * Inserts one executives row + one judiciaries row per jurisdiction that
 * has a legislature, skipping any that already exist (idempotent on
 * re-run). No members or seats are populated — those land via the
 * elections engine (Phase B+). Status stays "forming" until then.
 */
class InstitutionStubService
{
    /**
     * @param  list<string>|null  $jurisdictionIds  Limit to these
     *         jurisdictions (activation path). Null = every jurisdiction
     *         with a legislature (Setup Step 4 path).
     * @return array{executives_created:int, judiciaries_created:int}
     */
    public function generate(?array $jurisdictionIds = null): array
    {
        $now = now();

        $query = DB::table('legislatures')->whereNull('deleted_at');

        if ($jurisdictionIds !== null) {
            if ($jurisdictionIds === []) {
                return ['executives_created' => 0, 'judiciaries_created' => 0];
            }

            $query->whereIn('jurisdiction_id', $jurisdictionIds);
        }

        $targets = $query->pluck('jurisdiction_id')->unique()->values()->all();

        if (empty($targets)) {
            return ['executives_created' => 0, 'judiciaries_created' => 0];
        }

        $existingExec = DB::table('executives')
            ->whereIn('jurisdiction_id', $targets)
            ->whereNull('deleted_at')
            ->pluck('jurisdiction_id')
            ->all();

        $existingJud = DB::table('judiciaries')
            ->whereIn('jurisdiction_id', $targets)
            ->whereNull('deleted_at')
            ->pluck('jurisdiction_id')
            ->all();

        $existingExecSet = array_flip($existingExec);
        $existingJudSet  = array_flip($existingJud);

        $execRows = [];
        $judRows  = [];

        foreach ($targets as $jurId) {
            if (! isset($existingExecSet[$jurId])) {
                $execRows[] = [
                    'id'              => (string) Str::uuid(),
                    'jurisdiction_id' => $jurId,
                    // Art. III: executives start as legislature-delegated
                    // committees (5+ via PR-STV, equal voting power).
                    'type'            => 'committee',
                    'term_number'     => 1,
                    'status'          => 'forming',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            if (! isset($existingJudSet[$jurId])) {
                $judRows[] = [
                    'id'              => (string) Str::uuid(),
                    'jurisdiction_id' => $jurId,
                    'court_name'      => 'Superior Court',
                    // Art. IV §1: appointed by default, 5+ judges, 10-year terms.
                    'type'            => 'appointed',
                    'min_judges'      => 5,
                    'term_years'      => 10,
                    'status'          => 'forming',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        // chunked inserts so we don't blow past pg's parameter limit on a
        // whole-world run (~3500 rows × 7 cols = 24 500 binds — fine, but
        // chunking keeps memory bounded if the count grows).
        foreach (array_chunk($execRows, 500) as $chunk) {
            DB::table('executives')->insert($chunk);
        }
        foreach (array_chunk($judRows, 500) as $chunk) {
            DB::table('judiciaries')->insert($chunk);
        }

        return [
            'executives_created'  => count($execRows),
            'judiciaries_created' => count($judRows),
        ];
    }
}
