<?php

namespace App\Jobs;

use App\Http\Controllers\LegislatureController;
use App\Models\AutoscaleItem;
use App\Services\AuditService;
use App\Services\ConstitutionalDefaults;
use App\Services\Districting\LeafGiantResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One legislature inside a full-scale autoscale run (kind `sweep`): draw its
 * complete founding district map with the proven mixed autoseed
 * (`map_plus_children_all` from the root — composite scopes + line-split
 * childless leaf giants), assess completeness, and activate.
 *
 * COMPLETENESS GATE (seating-law-correct, ruling 2026-07-13): a map is
 * complete ⟺ no unassigned compositable children with geometry at any swept
 * scope AND no undrawn leaf giants AND every district in band (floor_override
 * rows are recorded postures, not violations). Σ-seat drift vs type_a_seats
 * is INFORMATIONAL — recorded on the item, never a failure, never "repaired"
 * by total-forcing.
 *
 * Complete → bare activation flip (the founding context activates without a
 * board, exactly like the operator's v1 maps) + ONE summary audit append
 * outside the sweep transactions. Incomplete → the map stays draft and the
 * item lands on the review list with reasons.
 *
 * Failures never sink the run: every throwable becomes item status `failed`
 * with the message; the orchestrator's next wave continues past it.
 */
class AutoscaleLegislatureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * timeout 0: giant-country sweeps run for hours (supervisor-autoscale is
     * also timeout 0). tries 0 (unlimited): the width-governor gate below
     * RELEASES jobs back to the queue when the width is full, and each
     * release counts an attempt — a finite tries would MaxAttempts a job
     * that was merely waiting its turn. Handler errors never rethrow (every
     * throwable becomes item state), so unlimited tries cannot loop a
     * genuinely broken job; a >4 h redelivered copy still exits at the
     * atomic queued→running flip.
     */
    public int $timeout = 0;
    public int $tries   = 0;

    public function __construct(private readonly string $itemId)
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        $item = AutoscaleItem::query()->with('run')->find($this->itemId);
        if ($item === null || $item->run === null) {
            return;
        }
        $run = $item->run;

        // Run-level halt: put the item back for the resume's next wave.
        if (Cache::get(\App\Models\AutoscaleRun::HALT_CACHE_KEY) || $run->status === 'halted') {
            AutoscaleItem::query()->whereKey($item->id)
                ->where('status', 'queued')
                ->update(['status' => 'pending', 'updated_at' => now()]);
            return;
        }

        // WIDTH GATE (the governor's decided width, operator ruling: the
        // system itself decides): when as many sweeps are already busy as
        // the width allows, hand this payload back to the queue untouched —
        // backpressure, not failure. Horizon may hold ceiling-many
        // processes; only width-many work. (Inline callers — the pin suite —
        // have no queue context and skip the gate.)
        if ($this->job !== null) {
            $busy = (int) DB::table('autoscale_items')
                ->where('run_id', $run->id)
                ->where('kind', 'sweep')
                ->where('status', 'running')
                ->count();
            if ($busy >= \App\Support\AutoscaleGovernor::width()) {
                $this->release(15);

                return;
            }
        }

        // Idempotence: only a queued item may start (a redelivered copy of a
        // long-running job, or a double-dispatch, exits here).
        $started = AutoscaleItem::query()->whereKey($item->id)
            ->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if ($started === 0) {
            return;
        }

        $legislatureId = (string) $item->legislature_id;

        // An operator's interactive sweep may already hold this legislature
        // (mapper ⚡ mid-spot-check sets the same flag through massReseed).
        // Never run two sweeps on one legislature — hand the item back for a
        // later wave instead.
        if (Cache::get("legislature.{$legislatureId}.mass_running")) {
            AutoscaleItem::query()->whereKey($item->id)
                ->where('status', 'running')
                ->update([
                    'status'     => 'pending',
                    'reason'     => 'deferred: an interactive sweep holds this legislature',
                    'updated_at' => now(),
                ]);
            return;
        }

        // mass_running blocks the mapper's manual ⚡ mid-sweep and makes
        // massStatus truthful during operator spot-checks. Set here (not at
        // dispatch — 48k queued jobs would all flag at once), cleared in
        // finally like MassReseedJob. publishMassProgress refreshes the TTL
        // while the sweep is alive; the orchestrator reads flag absence on a
        // stale `running` item as worker death.
        Cache::put("legislature.{$legislatureId}.mass_running", true, 14400);
        Cache::forget("legislature.{$legislatureId}.mass_halt");

        try {
            $leg = DB::table('legislatures')
                ->where('id', $legislatureId)
                ->whereNull('deleted_at')
                ->first();
            if ($leg === null) {
                throw new \RuntimeException('Legislature vanished before its sweep.');
            }

            // ADOPT, never bulldoze: a legislature that already has an ACTIVE
            // map with districts (the operator's accepted work — Earth
            // Draft 12, a hand-fixed review item) is taken as-is, exactly
            // like the singles path. The autoscale exists to give mapless
            // legislatures founding maps, not to archive accepted ones.
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
                $this->finishItem($item->id, 'done', $seated, (int) $leg->type_a_seats,
                    'adopted: an active map with districts already exists');
                return;
            }

            $mapId = $this->ensureFoundingMap($leg);

            /** @var LegislatureController $ctrl */
            $ctrl = app(LegislatureController::class);

            // The proven mixed sweep, filing F-ELB-008 as the accepting
            // operator. leafScopeTx=false: without it every line-split scope
            // holds the global audit advisory lock for its whole per-scope
            // transaction, collapsing the 3 autoscale workers to 1.
            $result = $ctrl->executeMassReseedSweep(
                $legislatureId,
                'map_plus_children_all',
                (string) $leg->jurisdiction_id,
                $mapId,
                $run->initiator_user_id !== null ? (string) $run->initiator_user_id : null,
                $run->template,
                leafScopeTx: false,
            );

            // Re-read: the sweep may lawfully update type_a_seats at root
            // (cube root of children-sum).
            $leg = DB::table('legislatures')->where('id', $legislatureId)->first();

            $assessment = $this->assessCompleteness($leg, $mapId, $result);

            $seated = (int) DB::table('legislature_districts')
                ->where('map_id', $mapId)
                ->whereNull('deleted_at')
                ->sum('seats');
            $expected = (int) $leg->type_a_seats;

            if ($result['halted']) {
                $this->finishItem($item->id, 'halted', $seated, $expected,
                    'halted mid-sweep (' . count($result['errors']) . ' errors so far)');
                return;
            }

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
                        'generator'       => 'AutoscaleLegislatureJob mixed autoseed (True All Scale, 2026-07-18)',
                    ],
                    ref: 'WF-ELE-02',
                    jurisdictionId: (string) $leg->jurisdiction_id,
                );

                $this->finishItem($item->id, 'done', $seated, $expected,
                    $assessment['notes'] !== []
                        ? 'notes: ' . implode(' | ', array_slice($assessment['notes'], 0, 6))
                        : null);
            } else {
                // Map stays draft; the operator reviews from the dashboard.
                $this->finishItem($item->id, 'review', $seated, $expected,
                    implode(' | ', array_slice($assessment['reasons'], 0, 12)));
            }

            try {
                $ctrl->flushRevealedCache($legislatureId, $mapId, (string) $leg->jurisdiction_id);
            } catch (\Throwable $e) {
                Log::warning('Autoscale flushRevealedCache failed (non-fatal): '.$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('AutoscaleLegislatureJob error', [
                'item_id'        => $this->itemId,
                'legislature_id' => $legislatureId,
                'message'        => $e->getMessage(),
            ]);
            $this->finishItem($item->id, 'failed', null, null, mb_substr($e->getMessage(), 0, 1000));
        } finally {
            Cache::forget("legislature.{$legislatureId}.mass_running");
            Cache::forget("legislature.{$legislatureId}.mass_halt");
            Cache::forget("legislature.{$legislatureId}.mass_db_pid");
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Only flip an item that never started (a spurious MaxAttempts on a
        // redelivered copy of a still-running job must not clobber the live
        // worker's state — the orchestrator's reclaim rule owns true deaths).
        AutoscaleItem::query()->whereKey($this->itemId)
            ->where('status', 'queued')
            ->update([
                'status'     => 'failed',
                'reason'     => mb_substr($exception->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
    }

    // -------------------------------------------------------------------------

    /**
     * The sweep needs a draft map to file into. Reuse this legislature's
     * existing Founding Map if a prior attempt created one (the _all sweep
     * clears + redraws, so resuming onto it is clean); otherwise create it.
     */
    private function ensureFoundingMap(object $leg): string
    {
        $existing = DB::table('legislature_district_maps')
            ->where('legislature_id', $leg->id)
            ->where('name', 'Founding Map')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id'             => $mapId,
            'legislature_id' => $leg->id,
            'name'           => 'Founding Map',
            'description'    => 'Auto-generated by full-scale autoscale (True All Scale, 2026-07-18) — mixed autoseed sweep.',
            'status'         => 'draft',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return $mapId;
    }

    /**
     * The completeness assessment. Reuses the mapper's own giant-tree frame
     * (wizardSteps' recursive CTE): composite scopes = root + giants WITH
     * children; leaf giants = giants WITHOUT children.
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

    private function finishItem(string $itemId, string $status, ?int $seated, ?int $expected, ?string $reason): void
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

        // Only the item's live owner finalizes: after a (rare) false reclaim
        // spawns a second worker, a late first worker must not clobber the
        // second's state — its item is 'running' under the NEW owner only
        // from the new flip forward, and this guard plus the deterministic
        // _all redraw bound the damage.
        AutoscaleItem::query()->whereKey($itemId)
            ->where('status', 'running')
            ->update($update);
    }
}
