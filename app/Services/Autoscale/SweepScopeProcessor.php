<?php

namespace App\Services\Autoscale;

use App\Http\Controllers\LegislatureController;
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
     * @param string|null $workerToken  the claiming lease id, so the engine's
     *        heartbeat can keep this worker's lease alive through a long scope
     */
    public function process(AutoscaleRun $run, array $claim, ?string $workerToken = null): void
    {
        $scopeId       = $claim['scope_id'];
        $legislatureId = $claim['legislature_id'];
        $scopeJid      = $claim['scope_jurisdiction_id'];

        // CONNECTION HYGIENE AT THE CLAIM BOUNDARY (operator recycle order
        // 2026-08-31, the Bosnia four-canton 25P02): a scope must start at
        // transaction level ZERO. A prior scope's error can leave an
        // aborted transaction open on this lane's connection, and then
        // every statement of every following scope fails with 25P02 while
        // the real culprit's error is swallowed — Sarajevo Canton drew 18
        // seats clean by hand while failing on the poisoned lane. Roll the
        // residue back and log it; the log names the poisoning boundary.
        if (DB::transactionLevel() > 0) {
            Log::warning('Autoscale lane arrived at a claim with an open transaction — rolling back the residue', [
                'scope_id' => $scopeId, 'legislature_id' => $legislatureId,
                'tx_level' => DB::transactionLevel(),
            ]);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $header = \App\Models\LedgerHeader::query()->find($legislatureId);
        if ($header === null) {
            $this->releaseScope($scopeId, 'ledger header vanished');
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
            // BONUS NETTING (operator order 2026-08-29): every exactness
            // identity compares seats − bonus_seats against the budget (the
            // 08-28 ceiling-exception law). Raw sums flagged lawful bonus
            // lifts as drift — Uttar Pradesh wore a +4 for four lawful lifts.
            $agg = DB::table('legislature_districts')
                ->where('map_id', $adopted->id)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(seats),0) AS s, COALESCE(SUM(bonus_seats),0) AS b')
                ->first();
            $seated = (int) $agg->s;
            $bonus  = (int) $agg->b;
            $expected = (int) DB::table('legislatures')
                ->where('id', $legislatureId)->value('type_a_seats');

            // DRIFT-REPAIR REQUEUE (operator ruling 2026-07-22, "there
            // should be no drift in seat counts"): an EXPLICITLY flagged
            // header (redraw_requested_at, set by the repair
            // requeue) skips adoption so the sweep below retires and
            // refiles through the audited replace path. Everything else
            // keeps ADOPT-NEVER-BULLDOZE byte-identical — an operator's
            // accepted work is never archived by a plain requeue.
            if ($header->redraw_requested_at === null) {

            DB::transaction(function () use ($legislatureId) {
                DB::table('apportionment_ledger_scopes')
                    ->where('legislature_id', $legislatureId)
                    ->whereIn('status', ['pending', 'running'])
                    ->update([
                        'status'      => 'done',
                        'reason'      => 'adopted: an active map with districts already exists',
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);
            });
            $this->finishHeader($legislatureId, ['pending', 'running'], 'done', $seated, $expected,
                'adopted: an active map with districts already exists', $bonus);
            return;
            }
        }

        // First scope of the map flips its header running (idempotent).
        \App\Models\LedgerHeader::query()->whereKey($legislatureId)
            ->where('map_status', 'pending')
            ->update(['map_status' => 'running', 'started_at' => now(), 'updated_at' => now()]);

        // A resumed run's stale halt flag must not instantly halt the sweep.
        Cache::forget("legislature.{$legislatureId}.mass_halt");

        AutoscaleContext::enter((string) $run->id, $legislatureId, $scopeId, $workerToken);

        try {
            $leg = DB::table('legislatures')
                ->where('id', $legislatureId)
                ->whereNull('deleted_at')
                ->first();
            if ($leg === null) {
                throw new \RuntimeException('Legislature vanished before its sweep.');
            }
            if ($header->map_id === null) {
                throw new \RuntimeException('Header has no founding map (world build incomplete) — re-run the world build.');
            }

            // MATERIALIZE BEFORE ANY DRAWING (operator order 2026-08-31,
            // benchmark 14 — THE ONE HEAD made structural): a map's first
            // claim materializes its FULL stamped scope tree — root sized
            // once, every scope's seat budget stamped, the pre-draw gate
            // run, walk_position emitted post-order — and then hands the
            // claim back so the next claim follows the stamped walk
            // (deepest first, root last). No run needs a manual
            // materialize pass; every box gets this by claiming.
            $stamped = DB::table('apportionment_ledger_scopes')
                ->where('id', $scopeId)
                ->whereNotNull('walk_position')
                ->whereNotNull('seat_budget')
                ->exists();
            if (! $stamped) {
                \App\Support\AutoscaleEnumeration::materializeLedgerTree($legislatureId);
                if ((int) $header->child_count > 0) {
                    // Composite: the tree just grew — hand the claim back so
                    // the stamped walk decides what draws first.
                    $this->releaseScope($scopeId, null);
                    return;
                }
                // Leaf: the tree IS this scope — stamped now, draw in the
                // same claim.
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
                (string) $header->map_id,
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

            DB::transaction(function () use ($scopeId, $legislatureId, $scopeJid, $claim, $giants, $reason) {
                DB::table('apportionment_ledger_scopes')
                    ->where('id', $scopeId)
                    ->where('status', 'running')
                    ->update([
                        'status'      => 'done',
                        'reason'      => $reason,
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);

                foreach ($giants as $childJid => $budget) {
                    // Sub-scope tier from the CHILD's own bbox (2026-07-22,
                    // the Earth-swarm crash): a geometry-less tier-1 item can
                    // cascade into continental sub-scopes — the heavy cap
                    // must see the scope's real weight, not the item's.
                    // THE BUDGET STAMP (operator order 2026-08-30, one
                    // owner): the cascade's answer for this child is in
                    // hand RIGHT HERE — it is stamped onto the scope row,
                    // and the sweep draws to the stamp instead of asking
                    // again later under different live state. The 70/71
                    // class dies at this line.
                    // In a ledger-built world this is a stamp CONFIRMATION
                    // of the pre-walked row; a genuinely new row (data moved
                    // under the ledger) is late-born and marked by its NULL
                    // walk_position.
                    DB::statement("
                        INSERT INTO apportionment_ledger_scopes
                            (legislature_id, scope_jurisdiction_id, parent_jurisdiction_id,
                             depth, status, seat_budget, area_tier, created_at, updated_at)
                        SELECT ?, j.id, ?, ?, ?, ?,
                               CASE WHEN j.geom IS NULL THEN 1 ELSE CASE
                                   WHEN bbox.km2 <= 300      THEN 1
                                   WHEN bbox.km2 <= 3000     THEN 2
                                   WHEN bbox.km2 <= 30000    THEN 3
                                   WHEN bbox.km2 <= 300000   THEN 4
                                   ELSE 5 END END,
                               now(), now()
                          FROM jurisdictions j
                          LEFT JOIN LATERAL (
                               SELECT (ST_XMax(j.geom) - ST_XMin(j.geom)) * 111.32
                                      * GREATEST(cos(radians((ST_YMin(j.geom) + ST_YMax(j.geom)) / 2)), 0.01)
                                      * (ST_YMax(j.geom) - ST_YMin(j.geom)) * 110.57 AS km2
                          ) bbox ON true
                         WHERE j.id = ?
                            ON CONFLICT (legislature_id, scope_jurisdiction_id)
                            DO NOTHING
                    ", [
                        $legislatureId, $scopeJid,
                        $claim['depth'] + 1, 'pending', (int) $budget,
                        (string) $childJid,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Autoscale scope failed', [
                'scope_id'       => $scopeId,
                'legislature_id' => $legislatureId,
                'message'        => $e->getMessage(),
            ]);
            // TRANSIENT WEATHER SELF-REQUEUES (operator order 2026-08-30,
            // the Canada 30-seat lesson): an infrastructure-class failure —
            // Redis mid-reload, a refused or dropped connection — goes back
            // to pending with a bounded retry count, and a lane re-eats it
            // seconds later. Three strikes fail for real. Engine errors
            // (anything else) fail immediately as before.
            $transient = (bool) preg_match(
                '/LOADING Redis|Connection refused|server closed the connection|no connection to the server|SQLSTATE\[08|Connection timed out/i',
                $e->getMessage()
            );
            $retries = (int) DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('retry_count');
            if ($transient && $retries < 3) {
                DB::table('apportionment_ledger_scopes')
                    ->where('id', $scopeId)
                    ->where('status', 'running')
                    ->update([
                        'status'      => 'pending',
                        'retry_count' => $retries + 1,
                        'claim_token' => null,
                        'started_at'  => null,
                        'reason'      => 'transient retry '.($retries + 1).'/3: '.mb_substr($e->getMessage(), 0, 200),
                        'updated_at'  => now(),
                    ]);
            } else {
                DB::table('apportionment_ledger_scopes')
                    ->where('id', $scopeId)
                    ->where('status', 'running')
                    ->update([
                        'status'      => 'failed',
                        'reason'      => mb_substr($e->getMessage(), 0, 1000),
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);
            }
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
    public function finalize(AutoscaleRun $run, string $legislatureId, ?string $workerToken = null): void
    {
        $header = \App\Models\LedgerHeader::query()->find($legislatureId);
        if ($header === null) {
            return;
        }
        $mapId = (string) $header->map_id;

        AutoscaleContext::enter((string) $run->id, $legislatureId, null, $workerToken);

        try {
            $leg = DB::table('legislatures')
                ->where('id', $legislatureId)
                ->whereNull('deleted_at')
                ->first();
            if ($leg === null) {
                $this->finishHeader($legislatureId, ['assessing'], 'failed', null, null, 'legislature vanished before assessment');
                return;
            }

            // Scope-level errors ride into the assessment as diagnostics.
            $scopeReasons = DB::table('apportionment_ledger_scopes')
                ->where('legislature_id', $legislatureId)
                ->whereNotNull('reason')
                ->orderBy('depth')
                ->limit(12)
                ->pluck('reason')
                ->all();

            // MAP-LEVEL ZERO-POP ABSORPTION (2026-07-23): before assessment,
            // any seated district measuring zero people merges into the
            // nearest live district on the map — a cross-scope remainder
            // (all-giant sibling frames) can only heal here, once every
            // scope has filed. A map absorption cannot help (no live
            // district, zero-pop root) falls through to the honest review.
            try {
                $absorbedCount = app(\App\Services\DistrictingService::class)
                    ->absorbZeroPopDistricts($legislatureId, $leg, $mapId);
                if ($absorbedCount > 0) {
                    $scopeReasons[] = "{$absorbedCount} zero-pop districts absorbed at finalize";
                }
            } catch (\Throwable $e) {
                Log::warning('Zero-pop absorption failed (non-fatal): '.$e->getMessage());
            }

            $assessment = $this->assessCompleteness($leg, $mapId, ['errors' => $scopeReasons]);

            // BONUS NETTING (operator order 2026-08-29): identities compare
            // seats − bonus_seats against the budget, per the 08-28 law.
            $agg = DB::table('legislature_districts')
                ->where('map_id', $mapId)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(seats),0) AS s, COALESCE(SUM(bonus_seats),0) AS b')
                ->first();
            $seated = (int) $agg->s;
            $bonus  = (int) $agg->b;
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
                        'bonus_seats'     => $bonus,
                        'seat_drift'      => ($seated - $bonus) - $expected, // net of lawful bonus lifts
                        'generator'       => 'SweepScopeProcessor mixed autoseed (pull engine, 2026-07-19)',
                    ],
                    ref: 'WF-ELE-02',
                    jurisdictionId: (string) $leg->jurisdiction_id,
                );

                $this->finishHeader($legislatureId, ['assessing'], 'done', $seated, $expected,
                    $assessment['notes'] !== []
                        ? 'notes: ' . implode(' | ', array_slice($assessment['notes'], 0, 6))
                        : null, $bonus);
            } else {
                // Map stays draft; the operator reviews from the dashboard.
                // A flagged repair redraw runs on an ACTIVE map (adoption
                // skipped) — if it still assesses incomplete, the map demotes
                // to draft: an invalid map never remains the live map
                // (operator ruling 2026-07-22, no drift). No-op for the
                // normal first-pass path, whose maps are already draft.
                DB::table('legislature_district_maps')
                    ->where('id', $mapId)
                    ->where('status', 'active')
                    ->update(['status' => 'draft', 'updated_at' => now()]);
                $this->finishHeader($legislatureId, ['assessing'], 'review', $seated, $expected,
                    implode(' | ', array_slice($assessment['reasons'], 0, 12)), $bonus);
            }

            try {
                app(LegislatureController::class)
                    ->flushRevealedCache($legislatureId, $mapId, (string) $leg->jurisdiction_id);
            } catch (\Throwable $e) {
                Log::warning('Autoscale flushRevealedCache failed (non-fatal): '.$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('Autoscale finalize error', [
                'legislature_id' => $legislatureId, 'message' => $e->getMessage(),
            ]);
            $this->finishHeader($legislatureId, ['assessing'], 'failed', null, null, mb_substr($e->getMessage(), 0, 1000));
        } finally {
            AutoscaleContext::clear();
        }
    }

    private function releaseScope(string $scopeId, ?string $reason): void
    {
        DB::table('apportionment_ledger_scopes')
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
        $leafGiantBudget = [];
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
                // INERT CHILD LAYER (ruling 2026-07-23): zero-stored children
                // under a populated scope — effectively childless, the scope
                // line-splits itself; classified LEAF so check 2 counts it.
                $hasKids = DB::table('jurisdictions')
                        ->where('parent_id', $cid)->whereNull('deleted_at')->exists()
                    && ! $districting->childLayerIsInert($cid);
                if ($hasKids) {
                    $compositeIds[] = $cid;
                    $queue[] = $cid;
                } else {
                    $leafGiantIds[] = $cid;
                    $leafGiantBudget[$cid] = (int) $budget;
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

        // 0 — CHILDLESS ROOT (cycle-2 leaf law): an over-ceiling leaf
        // legislature line-splits ITSELF. Completeness = the drawn set
        // exists AND reaches the plan's district count (ceil(type_a /
        // ceiling)) — a partial commit (leafScopeTx=false means no wrapping
        // transaction) must never activate. In-band childless roots never
        // reach the sweep path (they ride the at-large singles shape).
        $rootIsLeaf = ! DB::table('jurisdictions')
                ->where('parent_id', $rootId)->whereNull('deleted_at')->exists()
            || $districting->childLayerIsInert($rootId);
        if ($rootIsLeaf) {
            $needed = (int) ceil(((int) $leg->type_a_seats) / max($ceiling, 1));
            $drawn  = (int) DB::table('district_subdivisions')
                ->where('map_id', $mapId)
                ->where('parent_jurisdiction_id', $rootId)
                ->whereNull('deleted_at')
                ->count();
            if ($drawn === 0) {
                $reasons[] = 'root leaf has no line-split districts';
            } elseif ($drawn < $needed) {
                $reasons[] = "root leaf drawn {$drawn} of {$needed} districts";
            }
        }

        // 1 — unassigned compositable children (with geometry, non-giant in
        // THIS scope's local frame) at each composite scope. A geometry-less
        // child big enough to be a local giant is flagged honestly — it can
        // neither composite nor be a scope.
        foreach ($compositeScopes as $scopeId => $scopeName) {
            if ($districting->childLayerIsInert($scopeId)) {
                continue;   // inert layer: the scope draws ITSELF — checks 0/2 cover it
            }
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

        // 2 — undrawn or PARTIALLY drawn leaf giants (partial-fill gate,
        // 2026-07-23). Any complete drawing of budget B needs at least
        // ceil(B / ceiling) districts (each seats <= ceiling), so a filing
        // that died partway always falls short of that count — while a
        // lawful closest-ships atom files its full district set and never
        // trips this (drift stays informational, ruling 2026-07-13).
        foreach ($leafGiants as $giantId => $giantName) {
            $drawn = (int) DB::table('district_subdivisions')
                ->where('map_id', $mapId)
                ->where('parent_jurisdiction_id', $giantId)
                ->whereNull('deleted_at')
                ->count();
            if ($drawn === 0) {
                $reasons[] = "leaf giant {$giantName} has no line-split districts";
                continue;
            }
            $needed = (int) ceil(($leafGiantBudget[$giantId] ?? 0) / max($ceiling, 1));
            if ($drawn < $needed) {
                $reasons[] = "leaf giant {$giantName} drawn {$drawn} of >={$needed} districts";
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

        // 4 — zero-population seated districts (Gnaviyani class, 2026-07-23):
        // seats over zero measured people cannot satisfy one-person-one-vote;
        // such a row files only when a constituent's geometry captures no
        // raster mass (micro-island raster/geometry mismatch) — honest review,
        // never activation. Guarded on the root actually having people so a
        // genuinely empty jurisdiction cannot flag forever.
        $rootPop = (int) DB::table('jurisdictions')->where('id', $rootId)->value('population');
        if ($rootPop > 0) {
            $zeroPop = (int) DB::table('legislature_districts')
                ->where('map_id', $mapId)
                ->whereNull('deleted_at')
                ->where('seats', '>', 0)
                ->where('actual_population', 0)
                ->count();
            if ($zeroPop > 0) {
                $reasons[] = "{$zeroPop} zero-population seated districts";
            }
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
    private function finishHeader(string $legislatureId, array $fromStatuses, string $status, ?int $seated, ?int $expected, ?string $reason, int $bonus = 0): void
    {
        $update = [
            'map_status'  => $status,
            'reason'      => $reason,
            'finished_at' => now(),
            'updated_at'  => now(),
            // A drift-repair flag is consumed by the attempt it triggered —
            // the NEXT plain requeue adopts again (never a standing bypass).
            'redraw_requested_at' => null,
        ];
        if ($seated !== null && $expected !== null) {
            $update['seats_seated'] = $seated;
            // Net of lawful bonus lifts (operator order 2026-08-29): every
            // exactness identity compares seats - bonus_seats against the
            // head. head_seats IS the expected — no second copy exists.
            $update['drift']        = ($seated - $bonus) - $expected;
        }

        \App\Models\LedgerHeader::query()->whereKey($legislatureId)
            ->whereIn('map_status', $fromStatuses)
            ->update($update);
    }
}
