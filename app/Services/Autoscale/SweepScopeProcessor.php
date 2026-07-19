<?php

namespace App\Services\Autoscale;

use App\Http\Controllers\LegislatureController;
use App\Models\AutoscaleItem;
use App\Models\AutoscaleRun;
use App\Services\AuditService;
use App\Services\ConstitutionalDefaults;
use App\Support\AutoscaleContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sweep execution under the pull engine (2026-07-19): one claimed SCOPE at a
 * time, plus the per-item finalize (assessment + activation) once every
 * scope of an item has closed.
 *
 * The item stays the per-legislature unit (review list, adoption, drift);
 * the scope is the work unit. When a scope completes, the SAME transaction
 * marks it done and materializes its giant-child scopes from
 * DistrictingService::giantChildrenForScope — incremental materialization,
 * because the root sweep lawfully re-derives type_a_seats at start and the
 * one-frame law judges each scope with current data; a giant tree frozen at
 * enumeration could disagree with the tree the sweep actually walks.
 *
 * Failures never sink the run: a throwable becomes scope status `failed`
 * with the message; the item finalizes honestly (the assessor finds any
 * uncovered territory) as review.
 */
class SweepScopeProcessor
{
    /**
     * Process one claimed scope. Returns void; all outcomes are row state.
     *
     * @param array{scope_id: string, item_id: string, legislature_id: string,
     *              scope_jurisdiction_id: string, depth: int} $claim
     */
    public function process(AutoscaleRun $run, array $claim): void
    {
        $scopeId       = $claim['scope_id'];
        $itemId        = $claim['item_id'];
        $legislatureId = $claim['legislature_id'];
        $scopeJid      = $claim['scope_jurisdiction_id'];

        $item = AutoscaleItem::query()->find($itemId);
        if ($item === null) {
            $this->releaseScope($scopeId, 'item vanished');
            return;
        }

        // Run-level halt: hand the claim back for the resume.
        if ($run->refresh()->haltRequested() || $run->status === 'halted') {
            $this->releaseScope($scopeId, null);
            return;
        }

        // An operator's interactive sweep may hold this legislature (mapper ⚡
        // spot-check sets mass_running through massReseed). Never run two
        // sweeps on one legislature — hand the scope back for later.
        if (Cache::get("legislature.{$legislatureId}.mass_running")) {
            $this->releaseScope($scopeId, 'deferred: an interactive sweep holds this legislature');
            return;
        }

        // ADOPT, never bulldoze — mid-run edition: the enumeration pre-pass
        // adopts maps that existed before the run, and THIS check covers maps
        // that went active while the run was live (an operator's hand-fixed
        // map on a re-queued review item, or our own founding map activated
        // by a prior completed pass). ANY active map with districts is
        // accepted work — a founding sweep only ever fills a void.
        $adopted = DB::table('legislature_district_maps as m')
            ->where('m.legislature_id', $legislatureId)
            ->where('m.status', 'active')
            ->whereNull('m.deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('legislature_districts as d')
                    ->whereColumn('d.map_id', 'm.id')
                    ->whereNull('d.deleted_at');
            })
            ->orderByDesc('m.created_at')
            ->first(['m.id']);
        if ($adopted !== null) {
            $seated = (int) DB::table('legislature_districts')
                ->where('map_id', $adopted->id)
                ->whereNull('deleted_at')
                ->sum('seats');
            $expected = (int) DB::table('legislatures')
                ->where('id', $legislatureId)->value('type_a_seats');

            DB::transaction(function () use ($run, $itemId, $scopeId) {
                DB::table('autoscale_scopes')
                    ->where('item_id', $itemId)
                    ->whereIn('status', ['pending', 'running'])
                    ->update([
                        'status'      => 'done',
                        'reason'      => 'adopted: an active map with districts already exists',
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);
            });
            $this->finishItem($itemId, ['pending', 'running'], 'done', $seated, $expected,
                'adopted: an active map with districts already exists');
            return;
        }

        // First scope of the item flips it running (idempotent).
        AutoscaleItem::query()->whereKey($itemId)
            ->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);

        // A resumed run's stale halt flag must not instantly halt the sweep.
        Cache::forget("legislature.{$legislatureId}.mass_halt");

        AutoscaleContext::enter((string) $run->id, $itemId, $scopeId);

        try {
            $leg = DB::table('legislatures')
                ->where('id', $legislatureId)
                ->whereNull('deleted_at')
                ->first();
            if ($leg === null) {
                throw new \RuntimeException('Legislature vanished before its sweep.');
            }
            if ($item->map_id === null) {
                throw new \RuntimeException('Item has no founding map (enumeration incomplete) — pump repair will re-mint.');
            }

            /** @var LegislatureController $ctrl */
            $ctrl = app(LegislatureController::class);

            // ONE scope per call (map_view_all: clear + redraw at this scope
            // only). The root cube-root type_a_seats update fires inside when
            // this scope IS the legislature root. leafScopeTx=false: the
            // engine's per-district transaction is the atomic unit, so the
            // global audit advisory lock is held ~ms per filing instead of
            // for the whole scope.
            $result = $ctrl->executeMassReseedSweep(
                $legislatureId,
                'map_view_all',
                $scopeJid,
                (string) $item->map_id,
                $run->initiator_user_id !== null ? (string) $run->initiator_user_id : null,
                $run->template,
                leafScopeTx: false,
            );

            if ($result['halted']) {
                $this->releaseScope($scopeId, null);
                return;
            }

            $errors = $result['errors'] ?? [];
            $reason = $errors !== []
                ? mb_substr('sweep: ' . implode(' | ', array_slice($errors, 0, 4)), 0, 1000)
                : null;

            // Scope done + giant-child materialization, one transaction. The
            // unique (run, legislature, scope) key makes a crash-redo clean.
            $districting = app(\App\Services\DistrictingService::class);
            $giants = $districting->giantChildrenForScope($scopeJid, $legislatureId);

            DB::transaction(function () use ($run, $scopeId, $itemId, $legislatureId, $claim, $giants, $reason) {
                DB::table('autoscale_scopes')
                    ->where('id', $scopeId)
                    ->where('status', 'running')
                    ->update([
                        'status'      => 'done',
                        'reason'      => $reason,
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);

                foreach ($giants as $childJid => $budget) {
                    DB::statement('
                        INSERT INTO autoscale_scopes
                            (id, run_id, item_id, legislature_id, scope_jurisdiction_id,
                             parent_scope_id, depth, status, created_at, updated_at)
                        VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, now(), now())
                            ON CONFLICT ON CONSTRAINT autoscale_scopes_scope_uq DO NOTHING
                    ', [
                        $run->id, $itemId, $legislatureId, (string) $childJid,
                        $scopeId, $claim['depth'] + 1, 'pending',
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Autoscale scope failed', [
                'scope_id'       => $scopeId,
                'legislature_id' => $legislatureId,
                'message'        => $e->getMessage(),
            ]);
            DB::table('autoscale_scopes')
                ->where('id', $scopeId)
                ->where('status', 'running')
                ->update([
                    'status'      => 'failed',
                    'reason'      => mb_substr($e->getMessage(), 0, 1000),
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ]);
        } finally {
            AutoscaleContext::clear();
        }
    }

    /**
     * Finalize one item whose scopes have ALL closed (claimed atomically by
     * the ladder's running→assessing flip): completeness assessment against
     * the real giant tree, bare activation on a complete map, ONE summary
     * audit append, item → done|review|failed.
     */
    public function finalize(AutoscaleRun $run, string $itemId): void
    {
        $item = AutoscaleItem::query()->find($itemId);
        if ($item === null) {
            return;
        }
        $legislatureId = (string) $item->legislature_id;
        $mapId         = (string) $item->map_id;

        AutoscaleContext::enter((string) $run->id, $itemId, null);

        try {
            $leg = DB::table('legislatures')
                ->where('id', $legislatureId)
                ->whereNull('deleted_at')
                ->first();
            if ($leg === null) {
                $this->finishItem($itemId, ['assessing'], 'failed', null, null, 'legislature vanished before assessment');
                return;
            }

            // Scope-level errors ride into the assessment as diagnostics.
            $scopeReasons = DB::table('autoscale_scopes')
                ->where('item_id', $itemId)
                ->whereNotNull('reason')
                ->orderBy('depth')
                ->limit(12)
                ->pluck('reason')
                ->all();

            $assessment = $this->assessCompleteness($leg, $mapId, ['errors' => $scopeReasons]);

            $seated = (int) DB::table('legislature_districts')
                ->where('map_id', $mapId)
                ->whereNull('deleted_at')
                ->sum('seats');
            $expected = (int) $leg->type_a_seats;

            if ($assessment['complete']) {
                // Bare activation flip — the founding context needs no board
                // (same posture as activateMap, which has no guards).
                DB::transaction(function () use ($legislatureId, $mapId) {
                    DB::table('legislature_district_maps')
                        ->where('legislature_id', $legislatureId)
                        ->where('status', 'active')
                        ->where('id', '!=', $mapId)
                        ->whereNull('deleted_at')
                        ->update([
                            'status'        => 'archived',
                            'effective_end' => now()->subDay()->toDateString(),
                            'updated_at'    => now(),
                        ]);
                    DB::table('legislature_district_maps')
                        ->where('id', $mapId)
                        ->update([
                            'status'          => 'active',
                            'effective_start' => now()->toDateString(),
                            'updated_at'      => now(),
                        ]);
                });

                // ONE summary append per legislature, outside any sweep tx.
                app(AuditService::class)->append(
                    module: 'elections',
                    event: 'district_map.generated',
                    payload: [
                        'map_id'          => $mapId,
                        'legislature_id'  => $legislatureId,
                        'type_a_seats'    => $expected,
                        'district_count'  => (int) DB::table('legislature_districts')
                            ->where('map_id', $mapId)->whereNull('deleted_at')->count(),
                        'seats_seated'    => $seated,
                        'seat_drift'      => $seated - $expected, // informational — never forced
                        'generator'       => 'SweepScopeProcessor mixed autoseed (pull engine, 2026-07-19)',
                    ],
                    ref: 'WF-ELE-02',
                    jurisdictionId: (string) $leg->jurisdiction_id,
                );

                $this->finishItem($itemId, ['assessing'], 'done', $seated, $expected,
                    $assessment['notes'] !== []
                        ? 'notes: ' . implode(' | ', array_slice($assessment['notes'], 0, 6))
                        : null);
            } else {
                // Map stays draft; the operator reviews from the dashboard.
                $this->finishItem($itemId, ['assessing'], 'review', $seated, $expected,
                    implode(' | ', array_slice($assessment['reasons'], 0, 12)));
            }

            try {
                app(LegislatureController::class)
                    ->flushRevealedCache($legislatureId, $mapId, (string) $leg->jurisdiction_id);
            } catch (\Throwable $e) {
                Log::warning('Autoscale flushRevealedCache failed (non-fatal): '.$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('Autoscale finalize error', [
                'item_id' => $itemId, 'message' => $e->getMessage(),
            ]);
            $this->finishItem($itemId, ['assessing'], 'failed', null, null, mb_substr($e->getMessage(), 0, 1000));
        } finally {
            AutoscaleContext::clear();
        }
    }

    private function releaseScope(string $scopeId, ?string $reason): void
    {
        DB::table('autoscale_scopes')
            ->where('id', $scopeId)
            ->where('status', 'running')
            ->update([
                'status'      => 'pending',
                'claim_token' => null,
                'reason'      => $reason,
                'updated_at'  => now(),
            ]);
    }

    /**
     * The completeness assessment (moved verbatim from AutoscaleLegislatureJob,
     * 2026-07-18/19). Reuses the mapper's own giant-tree frame: composite
     * scopes = root + giants WITH children; leaf giants = giants WITHOUT
     * children.
     *
     *  1. every composite scope: no direct non-giant child with geometry left
     *     unassigned on this map;
     *  2. every leaf giant: has drawn (line-split) districts on this map;
     *  3. every district in band — seats > ceiling is a violation; seats <
     *     floor only when floor_override is false (override rows are recorded
     *     Art. II §2 postures).
     *
     * Sweep errors join the reasons only when the checks above already
     * failed (they explain WHY); on a passing map they are informational
     * notes — the checks are the oracle, the errors are diagnostics.
     *
     * @return array{complete: bool, reasons: list<string>, notes: list<string>}
     */
    private function assessCompleteness(object $leg, string $mapId, array $sweepResult): array
    {
        $rootId  = (string) $leg->jurisdiction_id;
        $floor   = ConstitutionalDefaults::floor($rootId);
        $ceiling = ConstitutionalDefaults::ceiling($rootId);

        $reasons = [];

        // ONE-FRAME LAW (2026-07-19): the giant tree is the budget cascade's
        // giant tree (DistrictingService::giantChildrenForScope, local
        // children-sum frame at every scope) — identical to the sweep's
        // scope walk and the wizard stepper, so the assessor can never mark
        // review what the stepper shows complete, or vice versa.
        $districting = app(\App\Services\DistrictingService::class);
        $giantSetByScope = [];
        $compositeIds = [$rootId];
        $leafGiantIds = [];
        $queue = [$rootId];
        $seen  = [$rootId => true];
        while (! empty($queue)) {
            $pid = array_shift($queue);
            $giants = $districting->giantChildrenForScope($pid, (string) $leg->id);
            $giantSetByScope[$pid] = $giants;
            foreach ($giants as $cid => $budget) {
                if (isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                $hasKids = DB::table('jurisdictions')
                    ->where('parent_id', $cid)->whereNull('deleted_at')->exists();
                if ($hasKids) {
                    $compositeIds[] = $cid;
                    $queue[] = $cid;
                } else {
                    $leafGiantIds[] = $cid;
                }
            }
        }
        $names = DB::table('jurisdictions')
            ->whereIn('id', array_merge($compositeIds, $leafGiantIds))
            ->pluck('name', 'id')->all();
        $compositeScopes = [];
        foreach ($compositeIds as $id) {
            $compositeScopes[$id] = $id === $rootId ? 'root' : (string) ($names[$id] ?? $id);
        }
        $leafGiants = [];
        foreach ($leafGiantIds as $id) {
            $leafGiants[$id] = (string) ($names[$id] ?? $id);
        }

        // 1 — unassigned compositable children (with geometry, non-giant in
        // THIS scope's local frame) at each composite scope. A geometry-less
        // child big enough to be a local giant is flagged honestly — it can
        // neither composite nor be a scope.
        foreach ($compositeScopes as $scopeId => $scopeName) {
            $giantIds = array_keys($giantSetByScope[$scopeId] ?? []);
            $notIn  = '';
            $params = [$scopeId];
            if ($giantIds !== []) {
                $notIn  = ' AND j.id NOT IN ('.implode(',', array_fill(0, count($giantIds), '?')).')';
                $params = array_merge($params, $giantIds);
            }
            $params[] = $mapId;

            $unassigned = (int) DB::scalar("
                SELECT COUNT(*)
                  FROM jurisdictions j
                 WHERE j.parent_id = ?
                   AND j.deleted_at IS NULL
                   AND j.geom IS NOT NULL
                   {$notIn}
                   AND NOT EXISTS (
                       SELECT 1
                         FROM legislature_district_jurisdictions ldj
                         JOIN legislature_districts d ON d.id = ldj.district_id
                        WHERE ldj.jurisdiction_id = j.id
                          AND d.map_id = ?
                          AND d.deleted_at IS NULL
                   )
            ", $params);

            if ($unassigned > 0) {
                $reasons[] = "{$unassigned} unassigned constituents at "
                    . ($scopeName === 'root' ? 'the root scope' : $scopeName);
            }

            $geomlessGiant = (int) DB::scalar('
                WITH s AS (SELECT COALESCE(SUM(population),0) AS cs FROM jurisdictions
                            WHERE parent_id = ? AND deleted_at IS NULL)
                SELECT COUNT(*) FROM jurisdictions j, s
                 WHERE j.parent_id = ? AND j.deleted_at IS NULL AND j.geom IS NULL
                   AND s.cs > 0
                   AND (COALESCE(j.population,0)::float8 * ?) / s.cs >= ?
            ', [$scopeId, $scopeId,
                (int) ($districting->computeSeatBudget($scopeId, (string) $leg->id) ?? 0),
                ConstitutionalDefaults::giantThreshold($rootId)]);
            if ($geomlessGiant > 0) {
                $reasons[] = "{$geomlessGiant} geometry-less giant constituents at "
                    . ($scopeName === 'root' ? 'the root scope' : $scopeName);
            }
        }

        // 2 — undrawn leaf giants.
        foreach ($leafGiants as $giantId => $giantName) {
            $drawn = (int) DB::table('district_subdivisions')
                ->where('map_id', $mapId)
                ->where('parent_jurisdiction_id', $giantId)
                ->whereNull('deleted_at')
                ->count();
            if ($drawn === 0) {
                $reasons[] = "leaf giant {$giantName} has no line-split districts";
            }
        }

        // 3 — band check (floor_override rows are recorded postures).
        $outOfBand = (int) DB::table('legislature_districts')
            ->where('map_id', $mapId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($floor, $ceiling) {
                $q->where('seats', '>', $ceiling)
                    ->orWhere(function ($q2) use ($floor) {
                        $q2->where('seats', '<', $floor)->where('floor_override', false);
                    });
            })
            ->count();
        if ($outOfBand > 0) {
            $reasons[] = "{$outOfBand} districts out of the [{$floor},{$ceiling}] band";
        }

        // A map with zero districts is never complete (a sweep that produced
        // nothing at all — e.g. every scope errored).
        $districtCount = (int) DB::table('legislature_districts')
            ->where('map_id', $mapId)
            ->whereNull('deleted_at')
            ->count();
        if ($districtCount === 0) {
            $reasons[] = 'sweep produced no districts';
        }

        // Sweep errors are DIAGNOSTICS, not the oracle — checks 1–3 above ARE
        // the law's completeness definition. A scope whose children are all
        // giants makes the composite report "No compositable (non-giant)
        // children found": a benign no-op (each giant child is its own
        // scope), NOT incompleteness — Bangladesh landed 550/551 fully drawn
        // with exactly that noise on the first live run. Any error that
        // actually left territory uncovered surfaces through check 1/2, so
        // errors only join the review reasons when the checks already failed;
        // on a passing map they ride along as notes.
        $notes = [];
        foreach (array_slice($sweepResult['errors'], 0, 8) as $err) {
            $notes[] = 'sweep: ' . mb_substr((string) $err, 0, 200);
        }
        if ($reasons !== []) {
            $reasons = array_merge($reasons, $notes);
        }

        return ['complete' => $reasons === [], 'reasons' => $reasons, 'notes' => $notes];
    }

    /**
     * @param list<string> $fromStatuses only the item's live owner finalizes —
     *        a late worker after a (rare) false reclaim must not clobber the
     *        new owner's state.
     */
    private function finishItem(string $itemId, array $fromStatuses, string $status, ?int $seated, ?int $expected, ?string $reason): void
    {
        $update = [
            'status'      => $status,
            'reason'      => $reason,
            'finished_at' => now(),
            'updated_at'  => now(),
        ];
        // Keep the enumeration-time expectation when a failure never measured
        // anything — a null overwrite would blank the dashboard's seat column.
        if ($seated !== null && $expected !== null) {
            $update['seats_seated']   = $seated;
            $update['seats_expected'] = $expected;
            $update['drift']          = $seated - $expected;
        }

        AutoscaleItem::query()->whereKey($itemId)
            ->whereIn('status', $fromStatuses)
            ->update($update);
    }
}
