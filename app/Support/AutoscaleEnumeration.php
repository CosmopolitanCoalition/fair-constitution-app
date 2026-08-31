<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared enumeration-key derivation (cycle-2 R2, operator ruling 2026-07-19)
 * — called by BOTH AutoscaleSizingJob (fresh enumeration) and
 * AutoscaleRevertCommand (re-derivation), so the two can never drift.
 *
 * THE WORK ORDER — simplest calculation first, so the run front-loads the
 * bulk and the remaining ETA honestly means "everything left is this
 * complex or more":
 *
 *   1. est_districts ASC   — ceil(type_a / ceiling): one-district
 *                            legislatures (floor→ceiling) first, then 2, 3…
 *   2. cascade_height ASC  — subtree height: leaves (0) before
 *                            parents-of-leaves (1) before deeper cascades
 *   3. area_tier ASC       — the pixelGrid ladder's area buckets (operator
 *                            ruling 2026-07-21): the TRUE cost driver is
 *                            geometry size, not admin depth. adm DESC alone
 *                            let every est band end in a consecutive block
 *                            of shallow-admin giants that captured all
 *                            workers at once (the est-2 tail collapse).
 *   4. adm_level DESC      — deepest layers first within a tier
 *   5. population ASC      — smallest first
 */
final class AutoscaleEnumeration
{
    /**
     * Derive est_districts + cascade_height + position for every item of a
     * run. Set-based; idempotent; safe to re-run.
     */
    public static function deriveOrderingKeys(string $runId, int $ceiling, ?callable $tick = null): void
    {
        // A2 (2026-08-29): $tick heartbeats between every set-based pass so
        // the run row never goes silent for minutes mid-derivation.
        $beat = static function () use ($tick) { if ($tick) { $tick(); } };
        // Planet-wide joins (the height loop, the position ROW_NUMBER) must
        // not recruit parallel workers: their DSM segments exceed Docker's
        // default 64 MB /dev/shm. Serial is fine for these set-based
        // passes; reset before returning so the session's later work (the
        // sweeps) keeps its normal planner freedom.
        DB::statement('SET max_parallel_workers_per_gather = 0');

        // est_districts from the CURRENT lawful size.
        DB::statement('
            UPDATE autoscale_items ai
               SET est_districts = CEIL(l.type_a_seats::numeric / ?)::smallint
              FROM legislatures l
             WHERE l.id = ai.legislature_id AND ai.run_id = ?
        ', [max($ceiling, 1), $runId]);

        // cascade_height: leaves 0, then iterative passes — a parent's height
        // resolves once ALL its children's heights are known (bool_and).
        DB::statement("
            UPDATE autoscale_items SET cascade_height = NULL WHERE run_id = ?
        ", [$runId]);
        DB::statement("
            UPDATE autoscale_items SET cascade_height = 0
             WHERE run_id = ? AND child_count = 0
        ", [$runId]);
        $beat();
        for ($pass = 0; $pass < 12; $pass++) {
            $updated = DB::update('
                UPDATE autoscale_items p
                   SET cascade_height = x.h
                  FROM (
                        SELECT p2.id, (1 + MAX(ci.cascade_height))::smallint AS h
                          FROM autoscale_items p2
                          JOIN jurisdictions c
                                 ON c.parent_id = p2.jurisdiction_id AND c.deleted_at IS NULL
                          LEFT JOIN autoscale_items ci
                                 ON ci.run_id = p2.run_id AND ci.jurisdiction_id = c.id
                         WHERE p2.run_id = ? AND p2.cascade_height IS NULL
                         GROUP BY p2.id
                        HAVING bool_and(ci.cascade_height IS NOT NULL)
                  ) x
                 WHERE p.id = x.id
            ', [$runId]);
            if ($updated === 0) {
                break;
            }
        }
        // Safety valve: a child jurisdiction without an item row (out-of-scope
        // adm level, data quirk) leaves its ancestors NULL — backfill high so
        // they sort last, and log the honest count.
        $beat();
        $orphans = DB::update("
            UPDATE autoscale_items SET cascade_height = 99
             WHERE run_id = ? AND cascade_height IS NULL
        ", [$runId]);
        if ($orphans > 0) {
            Log::warning('AutoscaleEnumeration: cascade_height unresolved for some items (sorted last)', [
                'run_id' => $runId, 'count' => $orphans,
            ]);
        }

        // area_tier from the geometry BBOX (header-only — no vertex walk):
        // width × height in km at the bbox's mid-latitude, bucketed on the
        // pixelGrid ladder's own thresholds. The bbox over-estimates for
        // diagonal coastal shapes, which errs HEAVY — safe for both the
        // ordering and the claim cap. Geometry-less items tier 1 (they
        // refuse in milliseconds). Idempotent; cheap enough set-based.
        DB::statement("
            UPDATE autoscale_items ai
               SET area_tier = CASE
                       WHEN j.geom IS NULL THEN 1
                       ELSE CASE
                           WHEN bbox.km2 <= 300      THEN 1
                           WHEN bbox.km2 <= 3000     THEN 2
                           WHEN bbox.km2 <= 30000    THEN 3
                           WHEN bbox.km2 <= 300000   THEN 4
                           ELSE 5
                       END
                   END
              FROM jurisdictions j
              LEFT JOIN LATERAL (
                   SELECT (ST_XMax(j.geom) - ST_XMin(j.geom)) * 111.32
                          * GREATEST(cos(radians((ST_YMin(j.geom) + ST_YMax(j.geom)) / 2)), 0.01)
                          * (ST_YMax(j.geom) - ST_YMin(j.geom)) * 110.57 AS km2
              ) bbox ON true
             WHERE j.id = ai.jurisdiction_id AND ai.run_id = ?
        ", [$runId]);

        // Position: the operator's simplest-first key (cost-aware since the
        // 2026-07-21 ruling — area_tier ahead of the adm proxy).
        DB::statement('
            WITH ranked AS (
                SELECT ai.id,
                       ROW_NUMBER() OVER (
                           ORDER BY ai.est_districts ASC, ai.cascade_height ASC,
                                    COALESCE(ai.area_tier, 1) ASC,
                                    ai.adm_level DESC, j.population ASC NULLS FIRST, ai.id
                       ) AS rn
                  FROM autoscale_items ai
                  JOIN jurisdictions j ON j.id = ai.jurisdiction_id
                 WHERE ai.run_id = ?
            )
            UPDATE autoscale_items ai
               SET position = r.rn
              FROM ranked r
             WHERE ai.id = r.id
        ', [$runId]);

        $beat();
        DB::statement('RESET max_parallel_workers_per_gather');
    }

    /**
     * THE ETL RULE (operator ruling 2026-07-19): planet-scale writes run as
     * BOUNDED CHUNKS, each its own committed statement — visible progress
     * (a psql count moves while it runs), resumable at any boundary (halt,
     * crash, cancel — the NOT-EXISTS guards make redo clean), and never a
     * single opaque multi-hour transaction. The three enumeration-tail
     * writers below are shared by AutoscaleSizingJob and the revert.
     */
    public const CHUNK = 25000;

    /** Mint draft Founding Maps for open sweep items lacking one. */
    public static function mintFoundingMaps(string $runId, ?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::affectingStatement("
                INSERT INTO legislature_district_maps
                    (id, legislature_id, name, description, status, created_at, updated_at)
                SELECT gen_random_uuid(), x.legislature_id, 'Founding Map',
                       'Auto-generated by full-scale autoscale (True All Scale, 2026-07-18) — mixed autoseed sweep.',
                       'draft', now(), now()
                  FROM (
                        SELECT ai.legislature_id
                          FROM autoscale_items ai
                         WHERE ai.run_id = ? AND ai.status = 'pending'
                           AND NOT EXISTS (SELECT 1 FROM legislature_district_maps m
                                            WHERE m.legislature_id = ai.legislature_id
                                              AND m.name = 'Founding Map'
                                              AND m.deleted_at IS NULL)
                         LIMIT " . self::CHUNK . '
                  ) x
            ', [$runId]);
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /** Stamp items.map_id from the newest Founding Map per legislature. */
    public static function stampFoundingMapIds(string $runId, ?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::update("
                UPDATE autoscale_items ai
                   SET map_id = fm.id, updated_at = now()
                  FROM (
                        SELECT DISTINCT ON (legislature_id) id, legislature_id
                          FROM legislature_district_maps
                         WHERE name = 'Founding Map' AND deleted_at IS NULL
                         ORDER BY legislature_id, created_at DESC
                  ) fm
                 WHERE ai.id IN (
                        SELECT ai2.id FROM autoscale_items ai2
                         WHERE ai2.run_id = ? AND ai2.map_id IS NULL
                           AND EXISTS (SELECT 1 FROM legislature_district_maps m
                                        WHERE m.legislature_id = ai2.legislature_id
                                          AND m.name = 'Founding Map'
                                          AND m.deleted_at IS NULL)
                         LIMIT " . self::CHUNK . '
                 )
                   AND fm.legislature_id = ai.legislature_id
            ', [$runId]);
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /**
     * THE BLOCK ORDER STAMP (operator ruling 2026-08-31, spoken table).
     * Stamp every item's block key from its own row — the whole priority
     * becomes data the claim reads, never status staging:
     *
     *   block_rank  = adm_level * 2 + (composite 0 | leaf 1)
     *                 planet → countries composites → countries leaves →
     *                 states composites → … → neighborhoods leaves.
     *   block_order = composites -population (biggest first);
     *                 leaves +population (smallest first — trivials lead).
     *
     * Chunked and resumable (block_rank IS NULL is the cursor).
     */
    public static function stampBlockOrder(string $runId, ?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::update('
                UPDATE autoscale_items ai
                   SET block_rank  = ai.adm_level * 2 + CASE WHEN ai.child_count > 0 THEN 0 ELSE 1 END,
                       block_order = CASE WHEN ai.child_count > 0
                                          THEN -GREATEST(COALESCE(j.population, 0), 0)
                                          ELSE  GREATEST(COALESCE(j.population, 0), 0) END,
                       updated_at  = now()
                  FROM jurisdictions j
                 WHERE j.id = ai.jurisdiction_id
                   AND ai.id IN (
                        SELECT id FROM autoscale_items
                         WHERE run_id = ? AND block_rank IS NULL
                         LIMIT ' . self::CHUNK . '
                 )
            ', [$runId]);
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /**
     * THE ONE LEAF STATEMENT (operator order 2026-08-31): leaf legislatures
     * are seeded by exactly this statement, from every caller — the run's
     * sizing pass and the ingest tail alike. One owner, one answer. The
     * guards ride with it everywhere: seats never exceed residents, a
     * zero-population space seats nobody, quorum never exceeds the seats.
     *
     * @return int legislatures created at this level
     */
    public static function seedLeafLegislatures(int $admLevel, int $floor): int
    {
        return DB::affectingStatement("
            INSERT INTO legislatures
                (id, jurisdiction_id, term_number, status,
                 total_seats, type_a_seats, type_b_seats, quorum_required,
                 created_at, updated_at)
            SELECT gen_random_uuid(), j.id, 1, 'forming',
                   s.seats, s.seats, 0,
                   LEAST(s.seats, GREATEST(3, CEIL(s.seats / 2.0)))::int,
                   now(), now()
              FROM jurisdictions j
             CROSS JOIN LATERAL (
                   SELECT LEAST(
                              GREATEST(?, ROUND(POWER(GREATEST(COALESCE(j.population, 0), 1)::numeric, 1.0/3.0))),
                              GREATEST(COALESCE(j.population, 0), 0)
                          )::int AS seats
             ) s
             WHERE j.deleted_at IS NULL
               AND j.adm_level = ?
               AND COALESCE(j.population, 0) > 0
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
               AND NOT EXISTS (SELECT 1 FROM legislatures l
                                WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
        ", [$floor, $admLevel]);
    }

    /** Mint the root scope row for every open sweep item lacking one. */
    public static function mintRootScopes(string $runId, ?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::affectingStatement("
                INSERT INTO autoscale_scopes
                    (id, run_id, item_id, legislature_id, scope_jurisdiction_id,
                     depth, status, area_tier, created_at, updated_at)
                SELECT gen_random_uuid(), ?, x.id, x.legislature_id, x.jurisdiction_id,
                       0, 'pending', x.area_tier, now(), now()
                  FROM (
                        SELECT ai.id, ai.legislature_id, ai.jurisdiction_id, ai.area_tier
                          FROM autoscale_items ai
                         WHERE ai.run_id = ? AND ai.kind = 'sweep' AND ai.status = 'pending'
                           AND NOT EXISTS (SELECT 1 FROM autoscale_scopes s WHERE s.item_id = ai.id)
                         LIMIT " . self::CHUNK . '
                  ) x
                    ON CONFLICT ON CONSTRAINT autoscale_scopes_scope_uq DO NOTHING
            ', [$runId, $runId]);
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /**
     * UPFRONT SCOPE-TREE MATERIALIZATION (operator order 2026-08-30,
     * benchmark 3). The budgets are pure arithmetic on the population rows
     * (the level law), so the whole giant tree is knowable before a single
     * district draws. This walks giantChildrenForScope exactly like the UI
     * stepper — recurse largest budget first, emit POST-ORDER, root last —
     * and upserts every scope with its walk_position, so lanes claim in the
     * stepper's order from second zero and no lane waits for a parent to
     * finish before its siblings even exist.
     *
     * @return int scopes materialized or restamped
     */
    public static function materializeScopeTree(string $runId, string $itemId): int
    {
        $item = DB::table('autoscale_items')->where('id', $itemId)->first();
        if ($item === null) {
            return 0;
        }

        $districting = new \App\Services\DistrictingService();

        // THE ONE HEAD (operator ruling 2026-08-30, the Guyana 91-on-84):
        // the root is sized HERE, once, before a single scope exists — the
        // single sizing owner, own-row base. Every scope below draws inside
        // this head; the sweep's root block recomputes the same number, so
        // the head can never change between a child's draw and the root's.
        $rootBudget = $districting->resizeRootSeats((string) $item->legislature_id);

        // THE LEDGER FAST PATH (operator order 2026-08-31): materialization
        // lives at the INGEST TAIL — a run COPIES its stamped tree from the
        // world ledger when the ledger's head matches the freshly resized
        // head (the freshness proof: same own-row arithmetic, same answer).
        // A refused ledger verdict throws the stored fact so the item lands
        // in review without recomputing the refusal.
        $ledger = DB::table('apportionment_ledger')
            ->where('legislature_id', (string) $item->legislature_id)
            ->where('compute_status', 'done')
            ->first();
        if ($ledger !== null && (int) $ledger->head_seats === $rootBudget) {
            if ($ledger->gate_reason !== null) {
                throw new \RuntimeException((string) $ledger->gate_reason);
            }

            DB::statement('
                INSERT INTO autoscale_scopes
                    (id, run_id, item_id, legislature_id, scope_jurisdiction_id,
                     depth, status, area_tier, walk_position, seat_budget,
                     created_at, updated_at)
                SELECT gen_random_uuid(), ?, ?, ?, ls.scope_jurisdiction_id,
                       ls.depth, ?, ?, ls.walk_position, ls.seat_budget,
                       now(), now()
                  FROM apportionment_ledger_scopes ls
                 WHERE ls.legislature_id = ?
                    ON CONFLICT ON CONSTRAINT autoscale_scopes_scope_uq
                    DO UPDATE SET walk_position = EXCLUDED.walk_position,
                                  seat_budget = EXCLUDED.seat_budget,
                                  updated_at = now()
            ', [$runId, $itemId, $item->legislature_id, 'pending', $item->area_tier, $item->legislature_id]);
            DB::statement('
                UPDATE autoscale_scopes s
                   SET parent_scope_id = p.id
                  FROM apportionment_ledger_scopes ls
                  JOIN autoscale_scopes p
                    ON p.item_id = s.item_id AND p.scope_jurisdiction_id = ls.parent_jurisdiction_id
                 WHERE s.item_id = ? AND s.scope_jurisdiction_id = ls.scope_jurisdiction_id
                   AND ls.legislature_id = ? AND s.parent_scope_id IS NULL
                   AND ls.parent_jurisdiction_id IS NOT NULL
            ', [$itemId, $item->legislature_id]);

            return (int) $ledger->scope_count;
        }

        // LIVE PATH (ledger missing or stale): compute the walk now, stamp
        // the run's scopes, and refresh the ledger as a side effect so the
        // next run copies.
        $computed = self::computeApportionment(
            (string) $item->legislature_id,
            (string) $item->jurisdiction_id,
            $rootBudget,
            $districting,
        );
        self::writeLedger((string) $item->legislature_id, (string) $item->jurisdiction_id, $rootBudget, $computed);
        if ($computed['gate_reason'] !== null) {
            throw new \RuntimeException($computed['gate_reason']);
        }

        $idByJid = [];
        $pos = 0;
        foreach ($computed['steps'] as [$jid, $depth, $parentJid, $budget]) {
            $idByJid[$jid] = $idByJid[$jid] ?? (string) \Illuminate\Support\Str::uuid();
        }
        foreach ($computed['steps'] as [$jid, $depth, $parentJid, $budget]) {
            // THE STAMP IS THE HEAD (2026-08-30): the budget is frozen on
            // the scope row at materialization. Lanes draw to the stamp —
            // the composite path and the leaf-giant path both read it
            // before any live resolver — so the frame a scope draws in is
            // decided once, here, and cannot move under a running map.
            DB::statement('
                INSERT INTO autoscale_scopes
                    (id, run_id, item_id, legislature_id, scope_jurisdiction_id,
                     parent_scope_id, depth, status, area_tier, walk_position,
                     seat_budget, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())
                    ON CONFLICT ON CONSTRAINT autoscale_scopes_scope_uq
                    DO UPDATE SET walk_position = EXCLUDED.walk_position,
                                  parent_scope_id = COALESCE(autoscale_scopes.parent_scope_id, EXCLUDED.parent_scope_id),
                                  seat_budget = EXCLUDED.seat_budget,
                                  updated_at = now()
            ', [
                $idByJid[$jid], $runId, $itemId, $item->legislature_id, $jid,
                $parentJid !== null ? ($idByJid[$parentJid] ?? null) : null,
                $depth, 'pending', $item->area_tier, $pos, $budget,
            ]);
            $pos++;
        }

        return $pos;
    }

    /**
     * THE WALK, PURE (operator order 2026-08-31): the cascade + pre-draw
     * gate as a computation with no side effects on run tables. Returns the
     * post-order steps and the gate verdict; a refusal is a RESULT here
     * (gate_reason text), so the ledger can record the whole world's
     * verdicts in one pass.
     *
     * @return array{steps: array<int, array{0:string,1:int,2:?string,3:int}>, gate_reason: ?string}
     */
    public static function computeApportionment(
        string $legislatureId,
        string $rootJid,
        int $rootBudget,
        ?\App\Services\DistrictingService $districting = null,
    ): array {
        $districting ??= new \App\Services\DistrictingService();

        $steps = [];   // post-order emit: [scope_jurisdiction_id, depth, parent_jid, budget]
        $gate  = null;
        $walk = function (string $jid, int $depth, ?string $parentJid, int $budget) use (&$walk, &$steps, &$gate, $districting, $legislatureId) {
            if ($gate !== null) {
                return;
            }
            $giants = $districting->giantChildrenForScope($jid, $legislatureId);

            // THE PRE-DRAW GATE (operator order 2026-08-30): apportionment
            // is knowable before any geometry. A giant set that
            // oversubscribes its head is already wrong on a blank map.
            $giantSum = array_sum($giants);
            if ($giantSum > $budget) {
                $names = DB::table('jurisdictions')->whereIn('id', array_keys($giants))->pluck('name', 'id');
                $parts = [];
                foreach ($giants as $gid => $b) {
                    $parts[] = ($names[$gid] ?? $gid) . "={$b}";
                }
                $gate = 'Pre-draw apportionment gate: giant budgets ('.implode(', ', $parts).") sum {$giantSum}"
                    . " over scope budget {$budget} at {$jid} — the head cannot distribute; refusing before any drawing.";

                return;
            }

            arsort($giants);   // largest budget first — the stepper's key
            foreach ($giants as $gid => $gBudget) {
                $walk((string) $gid, $depth + 1, $jid, (int) $gBudget);
            }
            $steps[] = [$jid, $depth, $parentJid, $budget];   // post-order: children emitted, then self
        };
        $walk($rootJid, 0, null, $rootBudget);

        return ['steps' => $gate === null ? $steps : [], 'gate_reason' => $gate];
    }

    /** Upsert one legislature's ledger header + scope tree (transactional). */
    public static function writeLedger(string $legislatureId, string $jurisdictionId, int $head, array $computed): void
    {
        $pop = (int) DB::table('jurisdictions')->where('id', $jurisdictionId)->value('population');
        DB::transaction(function () use ($legislatureId, $jurisdictionId, $head, $computed, $pop) {
            DB::statement("
                INSERT INTO apportionment_ledger
                    (legislature_id, jurisdiction_id, population, head_seats,
                     scope_count, gate_reason, compute_status, claim_token, computed_at,
                     created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'done', NULL, now(), now(), now())
                    ON CONFLICT (legislature_id)
                    DO UPDATE SET population = EXCLUDED.population,
                                  head_seats = EXCLUDED.head_seats,
                                  scope_count = EXCLUDED.scope_count,
                                  gate_reason = EXCLUDED.gate_reason,
                                  compute_status = 'done', claim_token = NULL,
                                  computed_at = now(), updated_at = now()
            ", [
                $legislatureId, $jurisdictionId, $pop, $head,
                count($computed['steps']), $computed['gate_reason'],
            ]);
            DB::table('apportionment_ledger_scopes')->where('legislature_id', $legislatureId)->delete();
            $pos = 0;
            foreach ($computed['steps'] as [$jid, $depth, $parentJid, $budget]) {
                DB::table('apportionment_ledger_scopes')->insert([
                    'legislature_id'         => $legislatureId,
                    'scope_jurisdiction_id'  => $jid,
                    'parent_jurisdiction_id' => $parentJid,
                    'depth'                  => $depth,
                    'walk_position'          => $pos++,
                    'seat_budget'            => $budget,
                ]);
            }
        });
    }

    /**
     * Seed the world apportionment worklist (the adjacency pattern): a
     * pending ledger header for every legislature with no fresh ledger —
     * missing, or whose stored population differs from the current own row.
     * Chunked; idempotent.
     */
    public static function seedApportionmentWorklist(): int
    {
        $total = 0;
        do {
            $n = DB::affectingStatement("
                INSERT INTO apportionment_ledger
                    (legislature_id, jurisdiction_id, population, compute_status, created_at, updated_at)
                SELECT l.id, l.jurisdiction_id, COALESCE(j.population, 0), 'pending', now(), now()
                  FROM legislatures l
                  JOIN jurisdictions j ON j.id = l.jurisdiction_id AND j.deleted_at IS NULL
                 WHERE l.deleted_at IS NULL
                   -- COMPOSITES ONLY (operator ruling 2026-08-31): a childless
                   -- legislature's tree is itself and its gate cannot refuse —
                   -- the ledger walks only jurisdictions with children.
                   AND EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = l.jurisdiction_id AND c.deleted_at IS NULL)
                   AND NOT EXISTS (SELECT 1 FROM apportionment_ledger al WHERE al.legislature_id = l.id)
                 LIMIT " . self::CHUNK . '
                    ON CONFLICT (legislature_id) DO NOTHING
            ');
            $total += $n;
        } while ($n > 0);

        // Stale rows re-open: population moved under a computed ledger.
        $total += DB::update("
            UPDATE apportionment_ledger al
               SET compute_status = 'pending', claim_token = NULL, updated_at = now()
              FROM jurisdictions j
             WHERE j.id = al.jurisdiction_id
               AND al.compute_status = 'done'
               AND al.population IS DISTINCT FROM COALESCE(j.population, 0)
        ");

        return $total;
    }

    /**
     * Claim + compute ONE pending ledger row. Returns false when the
     * worklist is drained. The lane loop around this is the ingest tail's
     * (and the manual command's) drain.
     */
    public static function processApportionmentClaim(string $token): bool
    {
        $row = DB::selectOne("
            UPDATE apportionment_ledger
               SET compute_status = 'running', claim_token = ?, updated_at = now()
             WHERE legislature_id = (
                   SELECT legislature_id FROM apportionment_ledger
                    WHERE compute_status = 'pending'
                    ORDER BY population DESC
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING legislature_id, jurisdiction_id
        ", [$token]);
        if ($row === null) {
            return false;
        }

        try {
            $districting = new \App\Services\DistrictingService();
            $head = $districting->resizeRootSeats((string) $row->legislature_id);
            $computed = self::computeApportionment((string) $row->legislature_id, (string) $row->jurisdiction_id, $head, $districting);
            self::writeLedger((string) $row->legislature_id, (string) $row->jurisdiction_id, $head, $computed);
        } catch (\Throwable $e) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('apportionment_ledger')->where('legislature_id', (string) $row->legislature_id)->update([
                'compute_status' => 'failed', 'gate_reason' => mb_substr('compute failed: ' . $e->getMessage(), 0, 1000),
                'claim_token' => null, 'updated_at' => now(),
            ]);
        }

        return true;
    }
}
