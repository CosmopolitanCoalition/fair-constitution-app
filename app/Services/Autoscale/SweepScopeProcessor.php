<?php

namespace App\Services\Autoscale;

use App\Http\Controllers\LegislatureController;
use App\Models\AutoscaleRun;
use App\Services\AuditService;
use App\Services\ConstitutionalDefaults;
use App\Support\AutoscaleContext;
use Illuminate\Database\LostConnectionException;
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
 * uncovered territory) as review. A LOST DATABASE SESSION is the one
 * throwable that is rethrown with no write (isLostConnection): the lane's
 * session died, its scope left it (a kill parked it, or the pump reclaims
 * it on backend absence), and the worker ends the lane.
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
        // THE SCOPE KIND (operator order 2026-09-05): 'type_a' draws
        // districts at this scope; 'type_b' draws the chamber's Type B
        // panel map — the LAST scope of every composite map. The claim
        // carries it; a claim shaped before the column reads the row.
        $scopeKind = (string) ($claim['scope_kind']
            ?? DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('scope_kind')
            ?? \App\Support\AutoscaleEnumeration::SCOPE_TYPE_A);
        $isTypeB = $scopeKind === \App\Support\AutoscaleEnumeration::SCOPE_TYPE_B;

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

        // THE CLAIM-TOKEN GUARD (operator order 2026-09-02, the three Tumaco
        // lanes on one scope): a lane acts on a scope only while the scope
        // row still carries this lane's token. A reclaimed or killed scope
        // belongs to nobody or to another lane. This lane logs the loss and
        // returns to its claim loop with no write. Every scope state write
        // below repeats the token in its WHERE and checks the row count.
        if (! $this->ownsScope($scopeId, $workerToken)) {
            Log::warning('Autoscale lane lost its scope before work started', [
                'scope_id' => $scopeId, 'legislature_id' => $legislatureId, 'token' => $workerToken,
            ]);
            return;
        }

        $header = \App\Models\LedgerHeader::query()->find($legislatureId);
        if ($header === null) {
            $this->releaseScope($scopeId, 'ledger header vanished', $workerToken);
            return;
        }

        // Run-level halt: hand the claim back for the resume.
        if ($run->refresh()->haltRequested() || $run->status === 'halted') {
            $this->releaseScope($scopeId, null, $workerToken);
            return;
        }

        // An operator's interactive sweep may hold this legislature (mapper ⚡
        // spot-check sets mass_running through massReseed). Never run two
        // sweeps on one legislature — hand the scope back for later.
        if (Cache::get("legislature.{$legislatureId}.mass_running")) {
            $this->releaseScope($scopeId, 'deferred: an interactive sweep holds this legislature', $workerToken);
            return;
        }

        // ADOPT, never bulldoze — mid-run edition: the enumeration pre-pass
        // adopts maps that existed before the run, and THIS check covers maps
        // that went active while the run was live (an operator's hand-fixed
        // map on a re-queued review item, or our own founding map activated
        // by a prior completed pass). ANY active map with districts is
        // accepted work — a founding sweep only ever fills a void.
        // A Type B scope is never "adopted" by a Type A map: the panel map
        // is its own work, drawn whether or not the districts exist.
        $adopted = $isTypeB ? null : DB::table('legislature_district_maps as m')
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

            // Adoption closes the TYPE A scopes only (2026-09-05): the
            // panel scope is its own work — an accepted district map says
            // nothing about the chamber's Type B panels.
            DB::transaction(function () use ($legislatureId) {
                DB::table('apportionment_ledger_scopes')
                    ->where('legislature_id', $legislatureId)
                    ->whereIn('status', ['pending', 'running'])
                    ->where('scope_kind', \App\Support\AutoscaleEnumeration::SCOPE_TYPE_A)
                    ->update([
                        'status'      => 'done',
                        'reason'      => 'adopted: an active map with districts already exists',
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);
            });
            // A panel scope not yet DONE (open, or parked failed / review):
            // the header stays RUNNING so the lanes draw it — or the
            // finalize rung judges the parked one — and the header closes
            // there, the Type A map adopted as it stands, never re-assessed.
            $panelOpen = DB::table('apportionment_ledger_scopes')
                ->where('legislature_id', $legislatureId)
                ->where('scope_kind', \App\Support\AutoscaleEnumeration::SCOPE_TYPE_B)
                ->where('status', '<>', 'done')
                ->exists();
            if ($panelOpen) {
                $this->handHeaderToFinalize($legislatureId);
                return;
            }
            $this->finishHeader($legislatureId, ['pending', 'running'], 'done', $seated, $expected,
                'adopted: an active map with districts already exists', $bonus);
            return;
            }
        }

        // First scope of the map flips its header running (idempotent).
        \App\Models\LedgerHeader::query()->whereKey($legislatureId)
            ->where('map_status', 'pending')
            // THE START CLOCK IS SET ONCE PER PASS (2026-09-05): a header the
            // halt reaper returned to pending keeps its start, so finalize's
            // "drew this pass" reads the scopes this pass closed. A requeue
            // clears started_at, so the next pass starts its own clock.
            ->update(['map_status' => 'running', 'started_at' => DB::raw('COALESCE(started_at, now())'), 'updated_at' => now()]);

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
                    $this->releaseScope($scopeId, null, $workerToken);
                    return;
                }
                // Leaf: the tree IS this scope — stamped now, draw in the
                // same claim.
            }

            // The token guard fires BEFORE the drawing (the destructive
            // step: the leaf ladder retires and refiles districts). One
            // indexed read; a lost scope draws nothing.
            if (! $this->ownsScope($scopeId, $workerToken)) {
                Log::warning('Autoscale lane lost its scope before the drawing', [
                    'scope_id' => $scopeId, 'legislature_id' => $legislatureId, 'token' => $workerToken,
                ]);
                return;
            }

            // THE TYPE B PANEL SCOPE (operator order 2026-09-05): the last
            // scope of a composite map clumps the chamber's constituents
            // into its Type B panels (TypeBDistrictMapper — a graph
            // grouping, no geometry cut). One active grouping per chamber;
            // a chamber whose ladder fits (no clumping needed) gets the
            // trivial map, one panel per constituent, exactly as an
            // at-large Type A map is still a map. Closes done; the header
            // finalizes when the Type A scopes and this one are all closed.
            if ($isTypeB) {
                $reason = $this->drawTypeBPanels($legislatureId, $header, $leg);
                $this->closeScopeDone($scopeId, $legislatureId, $scopeJid, (int) $claim['depth'], [], $reason, $workerToken);
                return;
            }

            // ZERO POPULATION (operator ruling 2026-09-02): a head of 0 over
            // a root that holds nobody has no seats to fill. No k-loop runs:
            // this lane retires any stale district at the scope on this map
            // (the pre-ruling 1-seat composite) and closes the scope done
            // with the singles path's reason text. finalize closes the
            // header the same way.
            if ((int) $header->head_seats === 0 && (int) $header->population === 0) {
                // Memberships go first, as every sibling retire plane does
                // (review catch 2026-09-02): a soft-deleted district must not
                // leave live membership rows behind it.
                $staleIds = DB::table('legislature_districts')
                    ->where('legislature_id', $legislatureId)
                    ->where('map_id', (string) $header->map_id)
                    ->where('jurisdiction_id', $scopeJid)
                    ->whereNull('deleted_at')
                    ->pluck('id')->all();
                if ($staleIds !== []) {
                    DB::table('legislature_district_jurisdictions')->whereIn('district_id', $staleIds)->delete();
                    DB::table('legislature_districts')->whereIn('id', $staleIds)
                        ->update(['deleted_at' => now(), 'updated_at' => now()]);
                }
                $this->closeScopeDone($scopeId, $legislatureId, $scopeJid, (int) $claim['depth'], [],
                    \App\Services\DistrictingService::ZERO_POPULATION_REASON, $workerToken);
                return;
            }

            /** @var LegislatureController $ctrl */
            $ctrl = app(LegislatureController::class);

            // ONE scope per call (map_view_all: clear + redraw at this scope
            // only). The root cube-root type_a_seats update fires inside when
            // this scope IS the legislature root. leafScopeTx=false: the
            // engine's per-district transaction is the atomic unit, so the
            // global audit advisory lock is held ~ms per filing instead of
            // for the whole scope.
            // GRIND SHUNT (operator order 2026-09-03): a scope the grind
            // watchdog terminated is flagged force_box; it redraws through the
            // box template (which does not grind) instead of shortest.
            $template = $this->scopeForcesBox($scopeId)
                ? \App\Services\Districting\SubdivisionAutoseedService::TEMPLATE_BOX
                : $run->template;
            $result = $ctrl->executeMassReseedSweep(
                $legislatureId,
                'map_view_all',
                $scopeJid,
                (string) $header->map_id,
                $run->initiator_user_id !== null ? (string) $run->initiator_user_id : null,
                $template,
                leafScopeTx: false,
            );

            if ($result['halted']) {
                $this->releaseScope($scopeId, null, $workerToken);
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

            $this->closeScopeDone($scopeId, $legislatureId, $scopeJid, (int) $claim['depth'], $giants, $reason, $workerToken);
        } catch (\Throwable $e) {
            // A LOST SESSION ABANDONS THE CLAIM (2026-09-02, the kill that
            // restarted its work): a terminated backend (kill, idle-in-
            // transaction timeout, postgres restart) means this lane's
            // session died and its scope left it. Nothing is written for
            // the scope; the throwable goes up to the worker, which ends
            // the lane. The pump's backend-absence reclaim carries the
            // retry accounting for a scope that was not parked by a kill.
            if (self::isLostConnection($e)) {
                Log::warning('Autoscale lane lost its database session mid-scope: claim abandoned, no write', [
                    'scope_id'       => $scopeId,
                    'legislature_id' => $legislatureId,
                    'token'          => $workerToken,
                    'message'        => mb_substr($e->getMessage(), 0, 300),
                ]);
                throw $e;
            }
            Log::error('Autoscale scope failed', [
                'scope_id'       => $scopeId,
                'legislature_id' => $legislatureId,
                'message'        => $e->getMessage(),
            ]);
            // TRANSIENT WEATHER SELF-REQUEUES (operator order 2026-08-30,
            // the Canada 30-seat lesson): an infrastructure-class failure
            // on a session that is still alive (Redis mid-reload, a refused
            // or timed-out side connection, a resolver fault) goes back to
            // pending with a bounded retry count, and a lane re-eats it
            // seconds later. Three strikes fail for real. Engine errors
            // (anything else) fail immediately as before.
            $transient = (bool) preg_match(
                '/LOADING Redis|Connection refused|Connection timed out|Connection lost|getaddrinfo|went away|recovery mode|not yet accepting connections/i',
                $e->getMessage()
            );
            $retries = (int) DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('retry_count');
            $write = DB::table('apportionment_ledger_scopes')
                ->where('id', $scopeId)
                ->where('status', 'running')
                ->when($workerToken !== null, fn ($q) => $q->where('claim_token', $workerToken));
            if ($transient && $retries < 3) {
                $n = $write->update([
                    'status'      => 'pending',
                    'retry_count' => $retries + 1,
                    'claim_token' => null,
                    'started_at'  => null,
                    'reason'      => 'transient retry '.($retries + 1).'/3: '.mb_substr($e->getMessage(), 0, 200),
                    'updated_at'  => now(),
                ]);
            } else {
                $n = $write->update([
                    'status'      => 'failed',
                    'reason'      => mb_substr($e->getMessage(), 0, 1000),
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ]);
            }
            if ($n === 0) {
                // The scope left this lane while it worked (reclaim or
                // kill). Nothing further is filed or retired for it.
                Log::warning('Autoscale lane lost its scope before the failure write', [
                    'scope_id' => $scopeId, 'legislature_id' => $legislatureId, 'token' => $workerToken,
                ]);
            }
        } finally {
            AutoscaleContext::clear();
        }
    }

    /**
     * Scope done + giant-child materialization, one transaction, guarded by
     * the lane's token. The unique (legislature, scope) key makes a
     * crash-redo clean. Returns false when the done write affected no row:
     * the scope left this lane (reclaimed or killed) and NOTHING further is
     * written for it — no giant rows, no header flip.
     *
     * @param array<string, int> $giants child jurisdiction id => seat budget
     */
    private function closeScopeDone(string $scopeId, string $legislatureId, string $scopeJid, int $depth, array $giants, ?string $reason, ?string $workerToken): bool
    {
        $owned = true;
        DB::transaction(function () use ($scopeId, $legislatureId, $scopeJid, $depth, $giants, $reason, $workerToken, &$owned) {
            $n = DB::table('apportionment_ledger_scopes')
                ->where('id', $scopeId)
                ->where('status', 'running')
                ->when($workerToken !== null, fn ($q) => $q->where('claim_token', $workerToken))
                ->update([
                    'status'      => 'done',
                    'reason'      => $reason,
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ]);
            if ($n === 0) {
                $owned = false;

                return;
            }

            // THE SCOPE CLOSE THAT EMPTIES A HEADER ARMS ITS FINALIZE
            // (2026-09-05): the finalize rung claims a running header the
            // instant its last scope lands — the same lane's next claim —
            // instead of waiting for the pump's per-minute ready net. Giant
            // children inserted below re-open the header (pending rows), so
            // the arm is re-checked after the inserts.
            $armFinalize = static function () use ($legislatureId): void {
                DB::update("
                    UPDATE apportionment_ledger h
                       SET finalize_ready = NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                                         WHERE s.legislature_id = h.legislature_id
                                                           AND s.status IN ('pending', 'running')),
                           updated_at = now()
                     WHERE h.legislature_id = ? AND h.map_status = 'running'
                ", [$legislatureId]);
            };
            if ($giants === []) {
                $armFinalize();
            }

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
                        ON CONFLICT (legislature_id, scope_jurisdiction_id, scope_kind)
                        DO NOTHING
                ", [
                    $legislatureId, $scopeJid,
                    $depth + 1, 'pending', (int) $budget,
                    (string) $childJid,
                ]);
            }
            if ($giants !== []) {
                $armFinalize();
            }
        });

        if (! $owned) {
            Log::warning('Autoscale lane lost its scope before the done write', [
                'scope_id' => $scopeId, 'legislature_id' => $legislatureId, 'token' => $workerToken,
            ]);
        }

        return $owned;
    }

    /**
     * THE TYPE B ASSESSMENT (operator order 2026-09-05, "B also checked just
     * like A"): the chamber's CURRENT active grouping is judged the way the
     * Type A map is judged at finalize — the three panel-map legalities the
     * mapper surface flags (seat breach over the Type B ceiling, unassigned
     * constituents, uneven clumps) plus the seat identities (Σ panel seats ==
     * grouping.seats_total == chamber type_b_seats) and no empty panel. A
     * machine map passes by construction (every constituent placed, split
     * base / base+1, p × rep_floor ≤ bound); a hand map is judged here, so
     * the review list is the one place an illegal map of either kind lands.
     * A population-capped zero-panel grouping (bound below one panel) is
     * lawful and noted, not failed. Read-only.
     *
     * @return array{reasons: list<string>, notes: list<string>}
     */
    private function assessTypeB(object $leg, string $legislatureId): array
    {
        $grouping = DB::table('legislature_type_b_groupings')
            ->where('legislature_id', $legislatureId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();
        if ($grouping === null) {
            return ['reasons' => ['Type B panel map missing (no active grouping)'], 'notes' => []];
        }

        $kids = DB::table('jurisdictions')
            ->where('parent_id', (string) $leg->jurisdiction_id)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(GREATEST(population, 0)), 0) AS pop')
            ->first();
        $n        = (int) $kids->n;
        $typeA    = (int) $leg->type_a_seats;
        $bound    = min($typeA, max(0, (int) $kids->pop - $typeA));
        $repFloor = max((int) $grouping->rep_floor, \App\Services\Legislature\TypeBSeatLadder::MIN_REP);

        $panels = DB::table('legislature_type_b_panels as p')
            ->leftJoin('legislature_type_b_panel_jurisdictions as pj', 'pj.panel_id', '=', 'p.id')
            ->where('p.grouping_id', $grouping->id)
            ->whereNull('p.deleted_at')
            ->groupBy('p.id', 'p.seats')
            ->selectRaw('p.id, p.seats, COUNT(pj.id) AS members')
            ->get();
        $seatSum = (int) $panels->sum('seats');
        $reasons = [];
        $notes   = [];

        // Seat identities: panels ↔ grouping ↔ chamber.
        if ($seatSum !== (int) $grouping->seats_total) {
            $reasons[] = "Type B seat identity: panels seat {$seatSum}, grouping records {$grouping->seats_total}";
        }
        if ((int) $grouping->seats_total !== (int) $leg->type_b_seats) {
            $reasons[] = "Type B seat identity: grouping {$grouping->seats_total} vs chamber type_b_seats {$leg->type_b_seats}";
        }
        // Seat breach over the Type B ceiling.
        if ($seatSum > $bound) {
            $reasons[] = "Type B seat breach: {$seatSum} seats over the ceiling {$bound}";
        }
        // The lawful zero-panel map: the ceiling holds less than one panel.
        $lawfulZero = $panels->isEmpty() && $bound < $repFloor;
        if ($lawfulZero) {
            $notes[] = "Type B population-capped undercount: ceiling {$bound} below one panel of {$repFloor}, zero panels lawful";
        }
        // Unassigned constituents (every live direct child is a member).
        if (! $lawfulZero) {
            $assigned = (int) DB::table('legislature_type_b_panel_jurisdictions as pj')
                ->join('jurisdictions as c', 'c.id', '=', 'pj.jurisdiction_id')
                ->where('pj.grouping_id', $grouping->id)
                ->where('c.parent_id', (string) $leg->jurisdiction_id)
                ->whereNull('c.deleted_at')
                ->distinct()
                ->count('pj.jurisdiction_id');
            if ($assigned < $n) {
                $reasons[] = 'Type B unassigned constituents: ' . ($n - $assigned) . " of {$n} in no panel";
            }
        }
        // Empty panels elect nobody.
        $empty = $panels->where('members', 0)->count();
        if ($empty > 0) {
            $reasons[] = "Type B empty panels: {$empty}";
        }
        // Uneven clumps: member counts must be as even as the integers allow
        // (THE SPREAD LAW, operator ruling 2026-09-05: Clumping Spread >
        // Contiguity > Compactness — the even split is always reachable, so
        // a spread over 1 is a defect, never a geometry note).
        if ($panels->count() > 1) {
            $mx = (int) $panels->max('members');
            $mn = (int) $panels->min('members');
            if ($mx - $mn > 1) {
                $reasons[] = "Type B uneven clumps: members {$mn}..{$mx} across " . $panels->count()
                    . ' panels (spread ' . ($mx - $mn) . ')';
            }
        }

        return ['reasons' => $reasons, 'notes' => $notes];
    }

    /**
     * Draw the chamber's Type B panel map (operator order 2026-09-05). The
     * mapper mints an ACTIVE grouping (archiving any prior active one),
     * writes the panels, recomputes type_b_seats / total_seats / quorum and
     * clears type_b_needs_districting. Returns the scope's reason text (null
     * on a clean grouping). A chamber with no Type A seats has a zero Type B
     * ceiling: no panels, closed with the zero-population reason.
     */
    private function drawTypeBPanels(string $legislatureId, object $header, object $leg): ?string
    {
        if ((int) $leg->type_a_seats === 0) {
            return \App\Services\DistrictingService::ZERO_POPULATION_REASON;
        }
        $result = app(\App\Services\Legislature\TypeBDistrictMapper::class)->apply($legislatureId, 'active');
        if ($result === null) {
            return 'type_b: no constituents — no panel map (a leaf chamber)';
        }
        $note = 'type_b: ' . $result['panel_count'] . ' panels, ' . $result['seats'] . ' seats';
        if ($result['undercount']) {
            $note .= ' (population-capped undercount: the Type B ceiling holds less than one panel)';
        }

        return $note;
    }

    /**
     * Is this throwable a lost database session? The lane's connection
     * guard throws LostConnectionException; a dead PDO reports 57P01
     * ("terminating connection due to administrator command"), "server
     * closed the connection unexpectedly", "no connection to the server",
     * or an 08xxx connection-class SQLSTATE. The whole previous-chain is
     * read. ONE detector for the processor and the worker.
     */
    public static function isLostConnection(\Throwable $e): bool
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof LostConnectionException) {
                return true;
            }
            if (preg_match(
                '/57P01|terminating connection due to administrator command|Admin shutdown'
                .'|server closed the connection unexpectedly|no connection to the server'
                .'|connection has been closed|SSL SYSCALL error|SQLSTATE\[08/i',
                $cursor->getMessage()
            )) {
                return true;
            }
        }

        return false;
    }

    /** Does the scope row still carry this lane's token? A null token (a direct test call) always owns. */
    private function ownsScope(string $scopeId, ?string $workerToken): bool
    {
        if ($workerToken === null) {
            return true;
        }

        return DB::table('apportionment_ledger_scopes')
            ->where('id', $scopeId)
            ->where('status', 'running')
            ->where('claim_token', $workerToken)
            ->exists();
    }

    /**
     * Does this scope carry the grind-shunt force_box flag (operator order
     * 2026-09-03)? Column-safe: absent before the migration, it reads false.
     * The column presence is cached only once TRUE, so a worker started before
     * the migration picks it up without a restart (the laneControl pattern).
     */
    private static bool $forceBoxColumn = false;

    private function scopeForcesBox(string $scopeId): bool
    {
        if (! self::$forceBoxColumn) {
            self::$forceBoxColumn = \Illuminate\Support\Facades\Schema::hasColumn('apportionment_ledger_scopes', 'force_box');
            if (! self::$forceBoxColumn) {
                return false;
            }
        }

        return (bool) DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('force_box');
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
                $this->finishHeader($legislatureId, ['assessing'], 'failed', null, null, 'legislature vanished before assessment', 0, $workerToken);
                return;
            }

            // ZERO POPULATION (operator ruling 2026-09-02): a head of 0 over
            // a root with no residents closes DONE with nothing seated, the
            // close the singles path writes. The assessor's no-districts
            // rule judges populated maps only.
            $rootOwnPop = (int) DB::table('jurisdictions')
                ->where('id', (string) $leg->jurisdiction_id)->value('population');
            if ((int) $leg->type_a_seats === 0 && $rootOwnPop === 0) {
                $this->finishHeader($legislatureId, ['assessing'], 'done', 0, 0,
                    \App\Services\DistrictingService::ZERO_POPULATION_REASON, 0, $workerToken);
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

            // WHICH HALF DREW THIS PASS (operator order 2026-09-05, Type B as
            // the last scope): a header re-opened ONLY for its Type B panel
            // scope (the upgrade pass over an already-finalized map) drew no
            // Type A scope since it re-opened, so the Type A map is not
            // re-assessed — an accepted active map is never demoted for
            // work it did not do. A Type A scope closed at or after the
            // header's start means the districts changed: assess as ever.
            // An ADOPTED close is not a drawing: adoption accepts whatever
            // active map exists (any id) and touches no district.
            $typeADrewThisPass = $header->started_at === null
                || DB::table('apportionment_ledger_scopes')
                    ->where('legislature_id', $legislatureId)
                    ->where('scope_kind', \App\Support\AutoscaleEnumeration::SCOPE_TYPE_A)
                    ->where('status', 'done')
                    ->where('finished_at', '>=', $header->started_at)
                    ->where(function ($q) {
                        $q->whereNull('reason')->orWhere('reason', 'not like', 'adopted:%');
                    })
                    ->exists();
            // AN ADOPTED PASS: any Type A scope of this header closed
            // 'adopted:' means the active map was accepted as it stands —
            // never re-assessed, whatever else drew before the adoption
            // (every requeue clears scope reasons, so an adopted reason is
            // always this pass's).
            $adoptedThisPass = DB::table('apportionment_ledger_scopes')
                ->where('legislature_id', $legislatureId)
                ->where('scope_kind', \App\Support\AutoscaleEnumeration::SCOPE_TYPE_A)
                ->where('reason', 'like', 'adopted:%')
                ->exists();
            // THE ACCEPTED MAP means what adoption means: ANY active map
            // with districts on this legislature (an operator's accepted
            // map is not always the founding map the header names).
            $activeMapId = DB::table('legislature_district_maps as m')
                ->where('m.legislature_id', $legislatureId)
                ->where('m.status', 'active')
                ->whereNull('m.deleted_at')
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))->from('legislature_districts as d')
                      ->whereColumn('d.map_id', 'm.id')->whereNull('d.deleted_at');
                })
                ->orderByDesc('m.created_at')
                ->value('m.id');
            $assessTypeA = ! $adoptedThisPass && ($typeADrewThisPass || $activeMapId === null);
            // The seat facts read the map that STANDS: the founding
            // container when this pass drew and assessed it, else the
            // accepted active map (what adoption and the revert pre-pass
            // stamp) — never an empty container beside an accepted map.
            $seatMapId = ($assessTypeA || $activeMapId === null) ? $mapId : (string) $activeMapId;

            if ($assessTypeA) {
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
            } else {
                $assessment = ['complete' => true, 'reasons' => [], 'notes' => $scopeReasons];
            }

            // THE TYPE B HALF (operator order 2026-09-05): a chamber with
            // constituents and Type A seats must hold an ACTIVE Type B
            // grouping when its map finalizes — the panel scope wrote it.
            // Missing = the panel scope failed; the header goes to review
            // with the scope's diagnosis, but the Type A map is NOT demoted
            // (it is a valid map; the panels are the missing half).
            $typeBReasons = [];
            if ((int) $header->child_count > 0 && (int) $leg->type_a_seats > 0) {
                $panelScope = DB::table('apportionment_ledger_scopes')
                    ->where('legislature_id', $legislatureId)
                    ->where('scope_kind', \App\Support\AutoscaleEnumeration::SCOPE_TYPE_B)
                    ->first(['status', 'reason']);
                if ($panelScope === null) {
                    // NO PANEL SCOPE YET (a header that reached the rung
                    // ahead of the materialization pass): the header is not
                    // judged for work it was never given — the row is added
                    // here and the header returns to running so the lanes
                    // draw it and this rung runs again. A row that cannot be
                    // added (nothing to draw for) is reported, not looped.
                    $added = app(AutoscaleRunControl::class)->ensureTypeBScopes([$legislatureId]);
                    if ($added > 0) {
                        \App\Models\LedgerHeader::query()->whereKey($legislatureId)
                            ->where('map_status', 'assessing')
                            ->when($workerToken !== null, fn ($q) => $q->where('claim_token', $workerToken))
                            ->update(['map_status' => 'running', 'claim_token' => null, 'finalize_ready' => false, 'updated_at' => now()]);
                        Log::info('Autoscale finalize: panel scope materialized late, header returned to running', [
                            'legislature_id' => $legislatureId,
                        ]);
                        return;
                    }
                    $typeBReasons[] = 'Type B panel map missing (no panel scope could be materialized for this chamber)';
                } else {
                    // THE PANEL HALF IS JUDGED BY ITS OWN SCOPE: the scope
                    // closed done AND the chamber holds a CURRENT active
                    // grouping (not re-flagged) — a stale grouping left by
                    // an earlier manual pass does not stand in for a failed
                    // panel draw.
                    $hasCurrentGrouping = ! (bool) $leg->type_b_needs_districting
                        && DB::table('legislature_type_b_groupings')
                            ->where('legislature_id', $legislatureId)
                            ->where('status', 'active')
                            ->whereNull('deleted_at')
                            ->exists();
                    if ($panelScope->status !== 'done' || ! $hasCurrentGrouping) {
                        $typeBReasons[] = 'Type B panel map missing (panel scope ' . $panelScope->status
                            . ($panelScope->reason !== null ? ': ' . mb_substr((string) $panelScope->reason, 0, 300) : '')
                            . ($hasCurrentGrouping ? '' : '; no current active grouping') . ')';
                    } else {
                        // THE TYPE B ASSESSMENT (operator order 2026-09-05,
                        // "B also checked just like A"): the standing panel
                        // map is judged for legality the way the Type A map
                        // is, and an illegal one parks the header in review.
                        $tb = $this->assessTypeB($leg, $legislatureId);
                        $typeBReasons = array_merge($typeBReasons, $tb['reasons']);
                        $assessment['notes'] = array_merge($assessment['notes'], $tb['notes']);
                    }
                }
            }

            // BONUS NETTING (operator order 2026-08-29): identities compare
            // seats − bonus_seats against the budget, per the 08-28 law.
            $agg = DB::table('legislature_districts')
                ->where('map_id', $seatMapId)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(seats),0) AS s, COALESCE(SUM(bonus_seats),0) AS b')
                ->first();
            $seated = (int) $agg->s;
            $bonus  = (int) $agg->b;
            $expected = (int) $leg->type_a_seats;

            if ($assessment['complete']) {
                // Bare activation flip — the founding context needs no board
                // (same posture as activateMap, which has no guards). Runs
                // whenever the Type A map was assessed this pass (a fresh
                // draw, a redraw of an active map — the districts changed,
                // so the generation audit appends as ever). The Type-B-only
                // pass assessed nothing: no re-flip, no second audit.
                if ($assessTypeA) {
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
                }

                if ($typeBReasons !== []) {
                    // The Type A map stands (active); the header parks in
                    // review for the missing panel half — never demoted.
                    $this->closeHeaderAsReview($legislatureId, ['assessing'],
                        implode(' | ', array_slice(array_merge($typeBReasons, $assessment['notes']), 0, 12)),
                        $workerToken, demoteMap: false);
                } else {
                    $this->finishHeader($legislatureId, ['assessing'], 'done', $seated, $expected,
                        $assessment['notes'] !== []
                            ? 'notes: ' . implode(' | ', array_slice($assessment['notes'], 0, 6))
                            : null, $bonus, $workerToken);
                }
            } else {
                // Map stays draft; the operator reviews from the dashboard.
                // THE ONE REVIEW PATH: closeHeaderAsReview demotes an active
                // map to draft, stamps seated/drift and flips the header.
                // The kill and the third reclaim close through it too.
                $this->closeHeaderAsReview($legislatureId, ['assessing'],
                    implode(' | ', array_slice(array_merge($assessment['reasons'], $typeBReasons), 0, 12)), $workerToken);
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
            $this->finishHeader($legislatureId, ['assessing'], 'failed', null, null, mb_substr($e->getMessage(), 0, 1000), 0, $workerToken);
        } finally {
            AutoscaleContext::clear();
        }
    }

    /**
     * Hand a running scope back to the pile. Guarded by the lane's token: a
     * scope that already left this lane is not touched, and the loss is
     * logged.
     */
    private function releaseScope(string $scopeId, ?string $reason, ?string $workerToken = null): void
    {
        $n = DB::table('apportionment_ledger_scopes')
            ->where('id', $scopeId)
            ->where('status', 'running')
            ->when($workerToken !== null, fn ($q) => $q->where('claim_token', $workerToken))
            ->update([
                'status'      => 'pending',
                'claim_token' => null,
                'reason'      => $reason,
                'updated_at'  => now(),
            ]);
        if ($n === 0 && $workerToken !== null) {
            Log::warning('Autoscale lane lost its scope before the release', [
                'scope_id' => $scopeId, 'token' => $workerToken,
            ]);
        }
    }

    /**
     * THE ONE REVIEW PATH for a header. Demotes an active map to draft (an
     * invalid map never stays live, operator ruling 2026-07-22), stamps the
     * seated / drift facts when a map exists, and flips the header to review
     * from one of $fromStatuses. Caller: finalize's incomplete branch. A
     * parked scope (third reclaim, lane kill) reaches it through the
     * finalize rung (handHeaderToFinalize), never directly.
     *
     * @param list<string> $fromStatuses
     */
    public function closeHeaderAsReview(string $legislatureId, array $fromStatuses, string $reason, ?string $claimToken = null, bool $demoteMap = true): void
    {
        $header = \App\Models\LedgerHeader::query()->find($legislatureId);
        if ($header === null) {
            return;
        }
        $mapId    = $header->map_id !== null ? (string) $header->map_id : null;
        $seated   = null;
        $expected = null;
        $bonus    = 0;
        if ($mapId !== null) {
            // demoteMap=false (2026-09-05): a review for the MISSING TYPE B
            // HALF leaves the valid, active Type A map in place — and reads
            // its seat facts from the map that STANDS (the accepted active
            // map, which need not be the founding container).
            if ($demoteMap) {
                DB::table('legislature_district_maps')
                    ->where('id', $mapId)
                    ->where('status', 'active')
                    ->update(['status' => 'draft', 'updated_at' => now()]);
            } else {
                $standing = DB::table('legislature_district_maps as m')
                    ->where('m.legislature_id', $legislatureId)
                    ->where('m.status', 'active')
                    ->whereNull('m.deleted_at')
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))->from('legislature_districts as d')
                          ->whereColumn('d.map_id', 'm.id')->whereNull('d.deleted_at');
                    })
                    ->orderByDesc('m.created_at')
                    ->value('m.id');
                if ($standing !== null) {
                    $mapId = (string) $standing;
                }
            }
            // BONUS NETTING (operator order 2026-08-29): identities compare
            // seats − bonus_seats against the head.
            $agg = DB::table('legislature_districts')
                ->where('map_id', $mapId)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(seats),0) AS s, COALESCE(SUM(bonus_seats),0) AS b')
                ->first();
            $typeA = DB::table('legislatures')
                ->where('id', $legislatureId)
                ->whereNull('deleted_at')
                ->value('type_a_seats');
            if ($typeA !== null) {
                $seated   = (int) $agg->s;
                $bonus    = (int) $agg->b;
                $expected = (int) $typeA;
            }
        }
        $this->finishHeader($legislatureId, $fromStatuses, 'review', $seated, $expected,
            mb_substr($reason, 0, 1000), $bonus, $claimToken);
    }

    /**
     * After a scope PARKS in review (third reclaim, operator kill), its
     * header takes THE ONE REVIEW PATH: the finalize rung. claimFinalize
     * (AutoscaleClaims) picks a running header once no scope of it is
     * pending or running (a review scope counts as closed), and finalize's
     * assessment closes the header as review with the parked scope's reason
     * riding in as a diagnostic. This method only makes the header visible
     * to that rung: a header still 'pending' (the scope parked before its
     * first flip) becomes 'running', the same idempotent flip the first
     * scope of a map performs. Returns true when the header is running.
     */
    public function handHeaderToFinalize(string $legislatureId): bool
    {
        \App\Models\LedgerHeader::query()->whereKey($legislatureId)
            ->where('map_status', 'pending')
            // THE START CLOCK IS SET ONCE PER PASS (2026-09-05): a header the
            // halt reaper returned to pending keeps its start, so finalize's
            // "drew this pass" reads the scopes this pass closed. A requeue
            // clears started_at, so the next pass starts its own clock.
            ->update(['map_status' => 'running', 'started_at' => DB::raw('COALESCE(started_at, now())'), 'updated_at' => now()]);

        // THE READY FLAG (2026-09-02): true when no scope of this header is
        // pending or running. claimFinalize pops the flag, never the scopes.
        DB::update("
            UPDATE apportionment_ledger h
               SET finalize_ready = NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                                 WHERE s.legislature_id = h.legislature_id
                                                   AND s.status IN ('pending', 'running')),
                   updated_at = now()
             WHERE h.legislature_id = ? AND h.map_status = 'running'
        ", [$legislatureId]);

        return \App\Models\LedgerHeader::query()->whereKey($legislatureId)
            ->where('map_status', 'running')
            ->exists();
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
                   -- zero is zero (ruling 2026-09-05): an uninhabited atom with no
                   -- constituents seats nobody and needs no district
                   AND (COALESCE(j.population, 0) >= 1
                        OR EXISTS (SELECT 1 FROM jurisdictions cc WHERE cc.parent_id = j.id AND cc.deleted_at IS NULL))
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
    private function finishHeader(string $legislatureId, array $fromStatuses, string $status, ?int $seated, ?int $expected, ?string $reason, int $bonus = 0, ?string $claimToken = null): void
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

        // THE CLAIM-TOKEN GUARD (2026-09-02): a finalize lane closes its
        // header only while the header still carries its token. A header
        // the halt reaper or a reclaim took back is left to its new owner.
        $n = \App\Models\LedgerHeader::query()->whereKey($legislatureId)
            ->whereIn('map_status', $fromStatuses)
            ->when($claimToken !== null, fn ($q) => $q->where('claim_token', $claimToken))
            ->update($update);
        if ($n === 0 && $claimToken !== null) {
            Log::warning('Autoscale lane lost its header before the finish write', [
                'legislature_id' => $legislatureId, 'status' => $status, 'token' => $claimToken,
            ]);
        }

        // SUPERSEDED-SCOPE CLOSE (2026-09-04): a map that finalizes DONE
        // supersedes any scope still parked in review from an earlier
        // auto-killed or grind-shunted attempt. Left open, that row reads as
        // not-done in the per-tier progress bar (units_done counts scope
        // status = 'done') and inflates the review scope tally, though the
        // map is complete and fully seated. Close the superseded rows the
        // same instant the header goes done, gated on the header write
        // landing ($n > 0, the claim-token guard) so a lane that lost its
        // header to a reclaim touches nothing. A header closed to review or
        // failed keeps its review scopes — those are the real diagnostics.
        // The original reason rides along as history.
        if ($status === 'done' && $n > 0) {
            DB::table('apportionment_ledger_scopes')
                ->where('legislature_id', $legislatureId)
                ->where('status', 'review')
                ->update([
                    'status'      => 'done',
                    'reason'      => DB::raw("COALESCE(reason, '') || ' | superseded by finalized map'"),
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ]);
        }
    }
}
