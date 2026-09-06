<?php

namespace App\Support;

use App\Support\QuorumLaw;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE WORLD BUILD'S OWNERS (single-home law, 2026-08-31): every fact the
 * drawing phase reads is written here, once per dataset — legislatures,
 * the apportionment ledger (headers + stamped scope trees + gate
 * verdicts), ordering + THE BLOCK ORDER keys, sweep-leaf self scopes,
 * founding-map containers, and the root bootstrap board. WorldBuildJob is
 * the orchestrating caller; materializeLedgerTree is the in-draw repair.
 */
final class AutoscaleEnumeration
{

    /**
     * THE ETL RULE (operator ruling 2026-07-19): planet-scale writes run as
     * BOUNDED CHUNKS, each its own committed statement — visible progress,
     * resumable at any boundary, never a single opaque multi-hour
     * transaction.
     */
    public const CHUNK = 25000;




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
                   ".QuorumLaw::sql('s.seats').",
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

        // THE TYPE B PANEL SCOPE (operator order 2026-09-05): every map whose
        // root has constituents ends with ONE more scope — the Type B panel
        // map of the whole chamber, keyed on the root, kind 'type_b', walked
        // LAST (after the Type A root scope). Its budget is the Type B
        // ceiling min(type_a, pop − type_a) over the children's own rows —
        // the divisor the clumping reads (TypeBSeatLadder / TypeBDistrictMapper).
        // A leaf has no Type B: its representation sits in its parent's chamber.
        if ($gate === null) {
            $typeB = self::typeBStep($rootJid, $rootBudget);
            if ($typeB !== null) {
                $steps[] = $typeB;
            }
        }

        return ['steps' => $gate === null ? $steps : [], 'gate_reason' => $gate];
    }

    public const SCOPE_TYPE_A = 'type_a';
    public const SCOPE_TYPE_B = 'type_b';

    /**
     * The Type B step tuple for a root with live children, null for a leaf:
     * [root jid, depth 0, no parent, Type B ceiling, 'type_b'].
     *
     * @return array{0:string,1:int,2:null,3:int,4:string}|null
     */
    public static function typeBStep(string $rootJid, int $typeA): ?array
    {
        $kids = DB::selectOne('
            SELECT COUNT(*)::int AS n, COALESCE(SUM(GREATEST(population, 0)), 0)::bigint AS pop
              FROM jurisdictions WHERE parent_id = ? AND deleted_at IS NULL
        ', [$rootJid]);
        if ((int) ($kids->n ?? 0) === 0) {
            return null;
        }
        $bound = min($typeA, max(0, (int) $kids->pop - $typeA));

        return [$rootJid, 0, null, $bound, self::SCOPE_TYPE_B];
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
            // WORK-PRESERVING FACT UPSERT (single-home law): a recompute
            // rewrites the FACT columns only; a scope row's work state
            // (status, claim, retries, timings) survives. Rows the walk no
            // longer produces are deleted — they are no longer facts.
            $pos = 0;
            $kept = [];   // "jid|kind" keys the walk produced
            foreach ($computed['steps'] as $step) {
                [$jid, $depth, $parentJid, $budget] = $step;
                $kind = $step[4] ?? self::SCOPE_TYPE_A;
                // A Type B scope is light by construction (a graph clumping,
                // no geometry): tier 1 so the heavy cap never holds it.
                DB::statement('
                    INSERT INTO apportionment_ledger_scopes
                        (legislature_id, scope_jurisdiction_id, parent_jurisdiction_id,
                         depth, walk_position, seat_budget, scope_kind, area_tier, is_leaf, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())
                        ON CONFLICT (legislature_id, scope_jurisdiction_id, scope_kind)
                        DO UPDATE SET parent_jurisdiction_id = EXCLUDED.parent_jurisdiction_id,
                                      depth = EXCLUDED.depth,
                                      walk_position = EXCLUDED.walk_position,
                                      seat_budget = EXCLUDED.seat_budget,
                                      updated_at = now()
                ', [
                    $legislatureId, $jid, $parentJid, $depth, $pos++, $budget, $kind,
                    $kind === self::SCOPE_TYPE_B ? 1 : null,
                    $kind === self::SCOPE_TYPE_B ? false : null,
                ]);
                $kept[] = $jid . '|' . $kind;
            }
            $stale = DB::table('apportionment_ledger_scopes')->where('legislature_id', $legislatureId);
            if ($kept !== []) {
                $stale->whereNotIn(DB::raw("scope_jurisdiction_id::text || '|' || scope_kind"), $kept);
            }
            $stale->delete();
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

        // LEAF HEADERS (single-home law): every childless legislature gets a
        // header born computed — its tree is itself, its head is its own
        // type_a, and its gate cannot refuse. Set-based, no walk.
        do {
            $n = DB::affectingStatement("
                INSERT INTO apportionment_ledger
                    (legislature_id, jurisdiction_id, population, head_seats,
                     scope_count, compute_status, computed_at, created_at, updated_at)
                SELECT l.id, l.jurisdiction_id, COALESCE(j.population, 0), l.type_a_seats,
                       0, 'done', now(), now(), now()
                  FROM legislatures l
                  JOIN jurisdictions j ON j.id = l.jurisdiction_id AND j.deleted_at IS NULL
                 WHERE l.deleted_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                    WHERE c.parent_id = l.jurisdiction_id AND c.deleted_at IS NULL)
                   AND NOT EXISTS (SELECT 1 FROM apportionment_ledger al WHERE al.legislature_id = l.id)
                 LIMIT " . self::CHUNK . '
                    ON CONFLICT (legislature_id) DO NOTHING
            ');
            $total += $n;
        } while ($n > 0);

        // Stale rows re-open: population moved under a computed ledger.
        // Leaf heads refresh in place (their compute is the SQL expression).
        $total += DB::update("
            UPDATE apportionment_ledger al
               SET compute_status = 'pending', claim_token = NULL, updated_at = now()
              FROM jurisdictions j
             WHERE j.id = al.jurisdiction_id
               AND al.compute_status = 'done'
               AND al.population IS DISTINCT FROM COALESCE(j.population, 0)
               AND EXISTS (SELECT 1 FROM jurisdictions c
                            WHERE c.parent_id = al.jurisdiction_id AND c.deleted_at IS NULL)
        ");
        $total += DB::update("
            UPDATE apportionment_ledger al
               SET population = COALESCE(j.population, 0), head_seats = l.type_a_seats,
                   computed_at = now(), updated_at = now()
              FROM jurisdictions j, legislatures l
             WHERE j.id = al.jurisdiction_id AND l.id = al.legislature_id
               AND al.compute_status = 'done'
               AND (al.population IS DISTINCT FROM COALESCE(j.population, 0)
                    OR al.head_seats IS DISTINCT FROM l.type_a_seats)
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = al.jurisdiction_id AND c.deleted_at IS NULL)
        ");

        return $total;
    }

    /**
     * THE LEDGER ORDERING STAMP (single-home law, 2026-08-31): every header
     * carries its facts — kind, child_count, adm_level, est_districts,
     * cascade_height, area_tier, position, and THE BLOCK ORDER keys.
     * The ledger edition of deriveOrderingKeys + stampBlockOrder; set-based,
     * idempotent, safe to re-run whenever legislature sizes change.
     */
    public static function deriveOrderingKeysOnLedger(int $ceiling, ?callable $tick = null): void
    {
        $beat = static function () use ($tick) { if ($tick) { $tick(); } };
        DB::statement('SET max_parallel_workers_per_gather = 0');

        DB::statement('
            UPDATE apportionment_ledger al
               SET adm_level = j.adm_level,
                   child_count = cc.n,
                   kind = CASE WHEN cc.n > 0 OR COALESCE(al.head_seats, l.type_a_seats) > ? THEN ? ELSE ? END,
                   est_districts = CEIL(COALESCE(al.head_seats, l.type_a_seats)::numeric / ?)::smallint,
                   updated_at = now()
              FROM legislatures l, jurisdictions j,
                   LATERAL (SELECT COUNT(*)::int AS n FROM jurisdictions c
                             WHERE c.parent_id = j.id AND c.deleted_at IS NULL) cc
             WHERE l.id = al.legislature_id AND j.id = al.jurisdiction_id
        ', [max($ceiling, 1), 'sweep', 'single', max($ceiling, 1)]);
        $beat();

        DB::statement('UPDATE apportionment_ledger SET cascade_height = NULL');
        DB::statement('UPDATE apportionment_ledger SET cascade_height = 0 WHERE child_count = 0');
        for ($pass = 0; $pass < 12; $pass++) {
            $updated = DB::update('
                UPDATE apportionment_ledger p
                   SET cascade_height = x.h
                  FROM (
                        SELECT p2.legislature_id, (1 + MAX(ci.cascade_height))::smallint AS h
                          FROM apportionment_ledger p2
                          JOIN jurisdictions c
                                 ON c.parent_id = p2.jurisdiction_id AND c.deleted_at IS NULL
                          LEFT JOIN apportionment_ledger ci ON ci.jurisdiction_id = c.id
                         WHERE p2.cascade_height IS NULL
                         GROUP BY p2.legislature_id
                        HAVING bool_and(ci.cascade_height IS NOT NULL)
                  ) x
                 WHERE p.legislature_id = x.legislature_id
            ');
            if ($updated === 0) {
                break;
            }
        }
        $beat();
        DB::update('UPDATE apportionment_ledger SET cascade_height = 99 WHERE cascade_height IS NULL');

        DB::statement("
            UPDATE apportionment_ledger al
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
             WHERE j.id = al.jurisdiction_id
        ");
        $beat();

        DB::statement('
            WITH ranked AS (
                SELECT al.legislature_id,
                       ROW_NUMBER() OVER (
                           ORDER BY al.est_districts ASC, al.cascade_height ASC,
                                    COALESCE(al.area_tier, 1) ASC,
                                    al.adm_level DESC, al.population ASC NULLS FIRST, al.legislature_id
                       ) AS rn
                  FROM apportionment_ledger al
            )
            UPDATE apportionment_ledger al
               SET position = r.rn
              FROM ranked r
             WHERE al.legislature_id = r.legislature_id
        ');
        $beat();

        // THE BLOCK ORDER: planet, then each layer composites (biggest
        // first) then leaves (smallest first — trivials lead by definition).
        DB::statement("
            UPDATE apportionment_ledger
               SET block_rank  = adm_level * 2 + CASE WHEN child_count > 0 THEN 0 ELSE 1 END,
                   block_order = CASE WHEN child_count > 0
                                      THEN -GREATEST(COALESCE(population, 0), 0)
                                      ELSE  GREATEST(COALESCE(population, 0), 0) END,
                   updated_at = now()
        ");
        $beat();
        DB::statement('RESET max_parallel_workers_per_gather');
    }

    /**
     * A sweep-leaf (over-ceiling childless legislature) owns exactly ONE
     * scope: itself, budget = its head. Seeded set-based so claimScope has
     * a stamped row from second zero; in-band singles own no scope rows.
     */
    /**
     * THE SCOPE CLASS STAMP (operator order 2026-09-02, the segmented layer
     * bars): is_leaf = the scope jurisdiction has no live children — the
     * same child test deriveOrderingKeysOnLedger applies to headers. A leaf
     * scope is a LINE-SPLIT (box / line templates); a scope with children
     * is a COMPOSITE. Chunked, idempotent (only NULL stamps are written), so
     * a re-materialized scope row is stamped by the next pass. The child
     * test is a LATERAL LIMIT-1 probe on jurisdictions(parent_id): the
     * NOT EXISTS form made the planner hash a full seq scan of the
     * planet's jurisdictions (2 GB of geometry pages) once PER CHUNK.
     * Lane-safe: rows a lane holds are skipped (SKIP LOCKED) and the
     * chunk is small, so a stamp never waits on a draw and a draw never
     * waits long on a stamp — the live box stalled 14 minutes on 2026-09-02
     * when two 25k-row chunks queued behind a lane's transaction.
     */
    public static function stampScopeClass(?callable $tick = null): int
    {
        $total = 0;
        do {
            $n = DB::update('
                UPDATE apportionment_ledger_scopes s
                   SET is_leaf = (h.hit IS NULL)
                  FROM (SELECT id, scope_jurisdiction_id
                          FROM apportionment_ledger_scopes
                         WHERE is_leaf IS NULL
                         LIMIT ' . intdiv(self::CHUNK, 5) . '
                           FOR UPDATE SKIP LOCKED) t
                  LEFT JOIN LATERAL (SELECT 1 AS hit FROM jurisdictions c
                                      WHERE c.parent_id = t.scope_jurisdiction_id
                                        AND c.deleted_at IS NULL LIMIT 1) h ON TRUE
                 WHERE s.id = t.id
            ');
            $total += $n;
            if ($tick) { $tick(); }
        } while ($n > 0);

        return $total;
    }

    public static function seedSweepLeafSelfScopes(): int
    {
        $total = 0;
        do {
            $n = DB::affectingStatement("
                INSERT INTO apportionment_ledger_scopes
                    (legislature_id, scope_jurisdiction_id, parent_jurisdiction_id,
                     depth, walk_position, seat_budget, area_tier, is_leaf, status, created_at, updated_at)
                SELECT al.legislature_id, al.jurisdiction_id, NULL,
                       0, 0, al.head_seats, al.area_tier, TRUE, 'pending', now(), now()
                  FROM apportionment_ledger al
                 WHERE al.kind = 'sweep' AND al.child_count = 0
                   AND NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                    WHERE s.legislature_id = al.legislature_id
                                      AND s.scope_jurisdiction_id = al.jurisdiction_id)
                 LIMIT " . self::CHUNK . '
                    ON CONFLICT (legislature_id, scope_jurisdiction_id, scope_kind) DO NOTHING
            ');
            $total += $n;
        } while ($n > 0);

        return $total;
    }

    /**
     * THE DISPATCH ORDER (operator confirmation 2026-08-31): the pool holds
     * the whole benchmark's sequence as ONE number per row, stamped once at
     * the world build — scopes' walk_position becomes the GLOBAL sequence
     * (block_rank, block_order, within-map walk), headers' position the
     * same over (block_rank, block_order). A claim is then an index pop of
     * the leading edge: no sort, no probe, no join. Idempotent: re-running
     * over already-global numbers reproduces the same sequence. NULL-walk
     * rows (the unstamped repair sentinel) keep their NULL.
     */
    public static function stampDispatchOrder(?callable $beat = null): void
    {
        DB::statement('SET max_parallel_workers_per_gather = 0');

        // Scopes: one chunk per block (ETL law — bounded, committed,
        // resumable at block granularity). Each block's rows rank within
        // the block and add the running offset of every lower block, so
        // the stamps join into the one global sequence. Repair sentinels
        // (walk_position NULL) stay unstamped and pop last at claim time.
        $blocks = DB::select('
            SELECT h.block_rank, count(*) AS n
              FROM apportionment_ledger_scopes s
              JOIN apportionment_ledger h ON h.legislature_id = s.legislature_id
             WHERE s.walk_position IS NOT NULL
             GROUP BY h.block_rank
             ORDER BY h.block_rank ASC NULLS LAST
        ');
        $offset = 0;
        foreach ($blocks as $b) {
            $predicate = $b->block_rank === null ? 'h.block_rank IS NULL' : 'h.block_rank = '.((int) $b->block_rank);
            DB::update("
                WITH ranked AS (
                    SELECT s.id,
                           ROW_NUMBER() OVER (
                               ORDER BY h.block_order ASC NULLS LAST,
                                        s.walk_position ASC, s.id
                           ) + ? AS rn
                      FROM apportionment_ledger_scopes s
                      JOIN apportionment_ledger h ON h.legislature_id = s.legislature_id
                     WHERE s.walk_position IS NOT NULL AND {$predicate}
                )
                UPDATE apportionment_ledger_scopes s
                   SET walk_position = r.rn, updated_at = now()
                  FROM ranked r
                 WHERE s.id = r.id AND s.walk_position IS DISTINCT FROM r.rn
            ", [$offset]);
            $offset += (int) $b->n;
            if ($beat !== null) {
                $beat();
            }
        }

        // Headers: the singles pop order, same per-block chunking.
        $blocks = DB::select('
            SELECT block_rank, count(*) AS n FROM apportionment_ledger
             GROUP BY block_rank ORDER BY block_rank ASC NULLS LAST
        ');
        $offset = 0;
        foreach ($blocks as $b) {
            $predicate = $b->block_rank === null ? 'block_rank IS NULL' : 'block_rank = '.((int) $b->block_rank);
            DB::update("
                WITH ranked AS (
                    SELECT legislature_id,
                           ROW_NUMBER() OVER (
                               ORDER BY block_order ASC NULLS LAST, legislature_id
                           ) + ? AS rn
                      FROM apportionment_ledger
                     WHERE {$predicate}
                )
                UPDATE apportionment_ledger h
                   SET position = r.rn, updated_at = now()
                  FROM ranked r
                 WHERE h.legislature_id = r.legislature_id
                   AND h.position IS DISTINCT FROM r.rn
            ", [$offset]);
            $offset += (int) $b->n;
            if ($beat !== null) {
                $beat();
            }
        }

        DB::statement('RESET max_parallel_workers_per_gather');
    }

    /** The bottom-up claim key (two-ended stays disabled; the fact rides). */
    public static function stampReversePositions(): int
    {
        return DB::update('
            WITH ranked AS (
                SELECT s.id,
                       ROW_NUMBER() OVER (ORDER BY j.adm_level DESC, j.population ASC NULLS FIRST, s.id) AS rn
                  FROM apportionment_ledger_scopes s
                  JOIN jurisdictions j ON j.id = s.scope_jurisdiction_id
            )
            UPDATE apportionment_ledger_scopes s
               SET reverse_position = r.rn, updated_at = now()
              FROM ranked r
             WHERE s.id = r.id AND s.reverse_position IS DISTINCT FROM r.rn
        ');
    }

    /**
     * A refused gate verdict is born on the review list — no claim is ever
     * spent on a map the arithmetic already refused.
     */
    public static function stampGateRefusals(): int
    {
        $total = 0;
        do {
            $n = DB::update("
                UPDATE apportionment_ledger
                   SET map_status = 'review', reason = gate_reason, updated_at = now()
                 WHERE legislature_id IN (
                       SELECT legislature_id FROM apportionment_ledger
                        WHERE gate_reason IS NOT NULL AND map_status = 'pending'
                        LIMIT " . self::CHUNK . '
                 )
            ');
            $total += $n;
        } while ($n > 0);

        return $total;
    }

    /** Founding-map containers for EVERY header lacking one (facts). */
    public static function mintFoundingMapsLedger(?callable $progress = null): int
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
                        SELECT al.legislature_id
                          FROM apportionment_ledger al
                         WHERE al.map_id IS NULL
                           AND NOT EXISTS (SELECT 1 FROM legislature_district_maps m
                                            WHERE m.legislature_id = al.legislature_id
                                              AND m.name = 'Founding Map'
                                              AND m.deleted_at IS NULL)
                         LIMIT " . self::CHUNK . '
                  ) x
            ');
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /** Stamp header map_id from the newest Founding Map per legislature. */
    public static function stampFoundingMapIdsLedger(?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::update("
                UPDATE apportionment_ledger al
                   SET map_id = fm.id, updated_at = now()
                  FROM (
                        SELECT DISTINCT ON (legislature_id) id, legislature_id
                          FROM legislature_district_maps
                         WHERE name = 'Founding Map' AND deleted_at IS NULL
                         ORDER BY legislature_id, created_at DESC
                  ) fm
                 WHERE al.legislature_id IN (
                        SELECT al2.legislature_id FROM apportionment_ledger al2
                         WHERE al2.map_id IS NULL
                           AND EXISTS (SELECT 1 FROM legislature_district_maps m
                                        WHERE m.legislature_id = al2.legislature_id
                                          AND m.name = 'Founding Map'
                                          AND m.deleted_at IS NULL)
                         LIMIT " . self::CHUNK . '
                 )
                   AND fm.legislature_id = al.legislature_id
            ');
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /**
     * The founding bootstrap board at the planet root — R-08's substrate
     * (moved here from the retired sizing job; the world build owns it).
     * One active is_bootstrap board + the synthetic system member. An
     * existing ACTIVE board is adopted. Idempotent.
     */
    public static function ensureRootBootstrapBoard(): void
    {
        $root = DB::table('jurisdictions')
            ->where('adm_level', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first(['id']);
        if ($root === null) {
            return;
        }

        $existing = DB::table('election_boards')
            ->where('jurisdiction_id', $root->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
        if ($existing) {
            return;
        }

        $legislatureId = DB::table('legislatures')
            ->where('jurisdiction_id', $root->id)
            ->whereNull('deleted_at')
            ->value('id');

        $boardId  = (string) \Illuminate\Support\Str::uuid();
        $memberId = (string) \Illuminate\Support\Str::uuid();
        DB::table('election_boards')->insert([
            'id'              => $boardId,
            'jurisdiction_id' => $root->id,
            'legislature_id'  => $legislatureId,
            'is_bootstrap'    => true,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('election_board_members')->insert([
            'id'                => $memberId,
            'election_board_id' => $boardId,
            'user_id'           => null, // THE SYSTEM ITSELF (B-2 schema)
            'status'            => 'seated',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        app(\App\Services\AuditService::class)->append(
            module: 'elections',
            event: 'bootstrap_board_constituted',
            payload: [
                'election_board_id' => $boardId,
                'legislature_id'    => $legislatureId,
                'is_bootstrap'      => true,
                'system_member_id'  => $memberId,
                'banner'            => 'temporary · replacement queued (retired by WF-ELE-10, Phase C)',
                'generator'         => 'WorldBuild (ledger single home, 2026-08-31)',
            ],
            ref: 'WF-ELE-02',
            jurisdictionId: (string) $root->id,
        );
    }

    /**
     * THE REPAIR MATERIALIZATION (single-home law): an unstamped scope met
     * at claim time rebuilds its map's tree straight into the ledger —
     * facts rewritten, work state preserved (writeLedger's contract). A
     * gate refusal throws so the worker routes the map to review.
     */
    public static function materializeLedgerTree(string $legislatureId): int
    {
        $jid = (string) DB::table('apportionment_ledger')
            ->where('legislature_id', $legislatureId)->value('jurisdiction_id');
        if ($jid === '') {
            throw new \RuntimeException('No ledger header for this legislature — the world build has not covered it.');
        }
        $districting = new \App\Services\DistrictingService();
        $head = $districting->resizeRootSeats($legislatureId);
        $computed = self::computeApportionment($legislatureId, $jid, $head, $districting);
        self::writeLedger($legislatureId, $jid, $head, $computed);
        if ($computed['gate_reason'] !== null) {
            throw new \RuntimeException($computed['gate_reason']);
        }

        return count($computed['steps']);
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
