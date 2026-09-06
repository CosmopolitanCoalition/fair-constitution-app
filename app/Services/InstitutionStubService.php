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

        // THE BENCH LAW (ruling bench-scaling-law B, 2026-09-05): the bench is
        // max(floor, next odd >= type_a_seats / 10), a minimum multiple where
        // constituents nominate. Inputs gathered in bounded chunks: the
        // chamber's Type A seats, the jurisdiction's own floor setting (the
        // root's, then 5, when it has none), and its live constituents.
        $rootFloor = (int) (DB::table('constitutional_settings as cs')
            ->join('jurisdictions as r', 'r.id', '=', 'cs.jurisdiction_id')
            ->whereNull('r.parent_id')->whereNull('r.deleted_at')
            ->orderBy('r.created_at')
            ->value('cs.judiciary_min_judges_per_race') ?? 5);
        $seatsByJur = [];
        $floorByJur = [];
        $kidsByJur  = [];
        foreach (array_chunk($targets, 5000) as $chunk) {
            foreach (DB::table('legislatures')->whereIn('jurisdiction_id', $chunk)->whereNull('deleted_at')
                         ->get(['jurisdiction_id', 'type_a_seats']) as $row) {
                $seatsByJur[(string) $row->jurisdiction_id] = (int) $row->type_a_seats;
            }
            foreach (DB::table('constitutional_settings')->whereIn('jurisdiction_id', $chunk)
                         ->whereNotNull('judiciary_min_judges_per_race')
                         ->get(['jurisdiction_id', 'judiciary_min_judges_per_race']) as $row) {
                $floorByJur[(string) $row->jurisdiction_id] = (int) $row->judiciary_min_judges_per_race;
            }
            foreach (DB::table('jurisdictions as c')
                         ->join('legislatures as cl', 'cl.jurisdiction_id', '=', 'c.id')
                         ->whereIn('c.parent_id', $chunk)->whereNull('c.deleted_at')
                         ->whereNull('cl.deleted_at')->where('cl.status', '<>', 'dissolved')
                         ->groupBy('c.parent_id')
                         ->get([DB::raw('c.parent_id'), DB::raw('count(*) as n')]) as $row) {
                $kidsByJur[(string) $row->parent_id] = (int) $row->n;
            }
        }

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
                    'min_judges'      => \App\Support\BenchLaw::bench(
                        $seatsByJur[$jurId] ?? 0,
                        $floorByJur[$jurId] ?? $rootFloor,
                        $kidsByJur[$jurId] ?? 0,
                    ),
                    'term_years'      => 10,
                    'status'          => 'forming',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        // Chunked so a whole-world run stays bounded in memory and well under
        // pg's bind limit.
        //
        // insertOrIgnore (ON CONFLICT DO NOTHING), not insert: the existence
        // checks above are a read-then-write, so under concurrent provisioning
        // workers two of them can both see "no judiciary here" and both insert.
        // The partial unique indexes added in
        // 2026_07_25_000002_institution_live_uniqueness make the loser a silent
        // no-op instead of a duplicate institution. The audit chain's advisory
        // lock does NOT help here — it serializes chain appends, not the reads
        // that precede them.
        //
        // Counts are the rows the database actually accepted, so a caller can
        // tell "I provisioned this" from "someone beat me to it".
        $execCreated = 0;
        $judCreated  = 0;

        foreach (array_chunk($execRows, 500) as $chunk) {
            $execCreated += DB::table('executives')->insertOrIgnore($chunk);
        }
        foreach (array_chunk($judRows, 500) as $chunk) {
            $judCreated += DB::table('judiciaries')->insertOrIgnore($chunk);
        }

        return [
            'executives_created'  => $execCreated,
            'judiciaries_created' => $judCreated,
        ];
    }
}
