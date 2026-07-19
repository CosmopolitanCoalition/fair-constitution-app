<?php

namespace App\Jobs;

use App\Models\AutoscaleRun;
use App\Services\AuditService;
use App\Services\Autoscale\AdjacencyPrecompute;
use App\Services\ConstitutionalDefaults;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Autoscale Phase A + B under the pull engine (2026-07-19): TRUE ALL SCALE
 * sizing (48k parents per-row cube root + ~903k leaves set-based) and the
 * work-list enumeration. Extracted from the retired orchestrator's
 * runSizing; the sizing math is UNCHANGED.
 *
 * What changed with the pull engine:
 *  - dispatched by the pump (10-min throttle on runs.sizing_lease_at);
 *    duplicates no-op on the per-run pg advisory lock — the true
 *    single-writer guard (SIGKILL releases session locks, so a dead owner
 *    never wedges the run);
 *  - item position is BOTTOM-UP: adm_level DESC, child_count ASC,
 *    population ASC — all leaves and small parents first, the monsters and
 *    Earth last (their in-flight tail is the honest remaining ETA);
 *  - founding maps are minted SET-BASED here with map_id stamped on items
 *    (kills the two-workers-two-maps race — ensureFoundingMap is gone);
 *  - the adopt pre-pass closes items whose legislature already has an
 *    active map with districts (adopt-never-bulldoze, set-based);
 *  - one ROOT SCOPE row per sweep item seeds the incremental giant-cascade
 *    materialization (autoscale_scopes);
 *  - the adjacency precompute worklist is seeded for the claim ladder.
 */
class AutoscaleSizingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries   = 1;

    /** Same lock class as the retired orchestrator ("ASCA") — one owner per run. */
    private const LOCK_CLASS = 0x41534341;

    public function __construct(private readonly string $runId)
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        $run = AutoscaleRun::query()->find($this->runId);
        if ($run === null || ! in_array($run->status, ['queued', 'sizing'], true)) {
            return;
        }
        if ($run->haltRequested()) {
            return;
        }

        $locked = (bool) (DB::selectOne(
            'SELECT pg_try_advisory_lock(?, hashtext(?)) AS ok',
            [self::LOCK_CLASS, (string) $run->id]
        )->ok ?? false);
        if (! $locked) {
            return; // another sizing job owns this run
        }

        try {
            AutoscaleRun::query()->whereKey($run->id)
                ->where('status', 'queued')
                ->update(['status' => 'sizing', 'sizing_started_at' => now(), 'updated_at' => now()]);
            $run->refresh();
            if ($run->status !== 'sizing') {
                return;
            }

            $this->runSizing($run);
        } catch (\Throwable $e) {
            Log::error('Autoscale sizing error: '.$e->getMessage(), ['run_id' => $run->id]);
            AutoscaleRun::query()->whereKey($run->id)->update([
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        } finally {
            try {
                DB::statement('SELECT pg_advisory_unlock(?, hashtext(?))', [self::LOCK_CLASS, (string) $run->id]);
            } catch (\Throwable) {
                // A dropped connection released the session lock already.
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AutoscaleSizingJob failed', [
            'run_id'  => $this->runId,
            'message' => $exception->getMessage(),
        ]);
        // No self-revival: the pump re-dispatches on the stale lease.
    }

    private function runSizing(AutoscaleRun $run): void
    {
        $admMax = (int) $run->adm_max;

        // A1 — parents (48k): the proven per-row cube-root path. Idempotent
        // (upserts). Childless rows are excluded (--parents-only); they get
        // the set-based treatment below — a per-row loop over 903k leaves
        // would alone take days.
        Log::info('Autoscale Phase A: sizing parents', ['run_id' => $run->id]);
        Artisan::call('apportionment:seed', [
            '--parents-only' => true,
            '--adm-max'      => $admMax,
        ]);
        $this->heartbeat($run);

        if ($this->haltRequested($run)) {
            return;
        }

        // A2 — leaves (~903k incl. all adm6 villages): ONE INSERT…SELECT per
        // adm level. CYCLE-2 LEAF LAW (operator ruling 2026-07-19): leaves
        // follow the SAME law as parents — max(floor, round(pop^⅓)), floor
        // clamp ONLY. A too-large legislature gets subdivided (line-split
        // districts), never truncated; the old LEAST(ceiling, …) wrapper was
        // the unlawful truncation. quorum = max(3, ceil(total/2)).
        $floor   = ConstitutionalDefaults::floor();
        $ceiling = ConstitutionalDefaults::ceiling();

        for ($lvl = 0; $lvl <= $admMax; $lvl++) {
            if ($this->haltRequested($run)) {
                return;
            }
            DB::statement("
                INSERT INTO legislatures
                    (id, jurisdiction_id, term_number, status,
                     total_seats, type_a_seats, type_b_seats, quorum_required,
                     created_at, updated_at)
                SELECT gen_random_uuid(), j.id, 1, 'forming',
                       s.seats, s.seats, 0,
                       GREATEST(3, CEIL(s.seats / 2.0))::int,
                       now(), now()
                  FROM jurisdictions j
                 CROSS JOIN LATERAL (
                       SELECT GREATEST(?, ROUND(POWER(GREATEST(COALESCE(j.population, 0), 1)::numeric, 1.0/3.0)))::int AS seats
                 ) s
                 WHERE j.deleted_at IS NULL
                   AND j.adm_level = ?
                   AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                    WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
                   AND NOT EXISTS (SELECT 1 FROM legislatures l
                                    WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
            ", [$floor, $lvl]);
            $this->heartbeat($run); // per level
        }

        // Counters + the canonical apportionment completion stamp (the same
        // record ApportionmentSeedCommand --stamp-instance writes; here it
        // covers parents AND leaves, so the sizing job stamps it itself).
        $sizedParents = (int) DB::scalar("
            SELECT COUNT(*) FROM legislatures l
             WHERE l.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM jurisdictions c
                            WHERE c.parent_id = l.jurisdiction_id AND c.deleted_at IS NULL)
        ");
        $sizedLeaves = (int) DB::scalar("
            SELECT COUNT(*) FROM legislatures l
             WHERE l.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = l.jurisdiction_id AND c.deleted_at IS NULL)
        ");

        DB::table('instance_settings')
            ->whereNull('deleted_at')
            ->update([
                'apportionment_completed_at' => now(),
                'apportionment_log'          => sprintf(
                    'Autoscale apportionment %s — TRUE ALL SCALE: %d parent legislatures (per-row cube root), %d leaf legislatures (set-based), adm ≤ %d.',
                    now()->toIso8601String(),
                    $sizedParents,
                    $sizedLeaves,
                    $admMax,
                ),
                'updated_at' => now(),
            ]);

        // The founding bootstrap board (design §B.3.1) — R-08's substrate.
        // The sweeps' F-ELB-008 line-split filings authorize through the
        // operator's R-08, which RoleService derives from "is_operator AND an
        // active bootstrap board exists" — so the root's board must be
        // constituted BEFORE the first claim, exactly as ActivationService
        // does at CLK-06 activation (board + synthetic system member + audit).
        $this->ensureRootBootstrapBoard($run);

        // ── Phase B — the pull work-list ────────────────────────────────────
        // Enumerate every legislature into autoscale_items. CYCLE-2 kinds:
        // sweep = has children (per-scope mixed autoseed) OR an over-ceiling
        // leaf (its root scope line-splits itself); single = in-band
        // childless leaf (set-based at-large district). The inline position
        // is provisional — the operator's simplest-first key
        // (AutoscaleEnumeration) replaces it below. Idempotent via the
        // per-run NOT EXISTS.
        DB::statement("
            INSERT INTO autoscale_items
                (id, run_id, legislature_id, jurisdiction_id, adm_level, kind,
                 status, position, child_count, seats_expected, created_at, updated_at)
            SELECT gen_random_uuid(), ?, l.id, j.id, j.adm_level,
                   CASE WHEN cc.n > 0 OR l.type_a_seats > ? THEN 'sweep' ELSE 'single' END,
                   'pending',
                   ROW_NUMBER() OVER (ORDER BY j.adm_level DESC, cc.n ASC, j.population ASC NULLS FIRST, j.id),
                   cc.n,
                   l.type_a_seats,
                   now(), now()
              FROM legislatures l
              JOIN jurisdictions j ON j.id = l.jurisdiction_id AND j.deleted_at IS NULL
             CROSS JOIN LATERAL (
                   SELECT COUNT(*)::int AS n FROM jurisdictions c
                    WHERE c.parent_id = j.id AND c.deleted_at IS NULL
             ) cc
             WHERE l.deleted_at IS NULL
               AND j.adm_level <= ?
               AND NOT EXISTS (SELECT 1 FROM autoscale_items ai
                                WHERE ai.run_id = ? AND ai.legislature_id = l.id)
        ", [$run->id, $ceiling, (int) $run->adm_max, $run->id]);
        $this->heartbeat($run);

        // R2 — the simplest-first ordering keys (est_districts, cascade
        // height, position). Shared with the revert command so re-derivation
        // can never drift from enumeration.
        \App\Support\AutoscaleEnumeration::deriveOrderingKeys((string) $run->id, $ceiling);
        $this->heartbeat($run);

        // B2 — ADOPT pre-pass (never bulldoze, set-based): a sweep legislature
        // that already has an ACTIVE map with districts (the operator's
        // accepted work — Earth Draft 12, a hand-fixed review item) is taken
        // as-is. Autoscale exists to give MAPLESS legislatures founding maps.
        DB::statement("
            UPDATE autoscale_items ai
               SET status = 'done',
                   reason = 'adopted: an active map with districts already exists',
                   seats_seated = s.seated,
                   drift = s.seated - COALESCE(ai.seats_expected, 0),
                   started_at = COALESCE(ai.started_at, now()),
                   finished_at = now(),
                   updated_at = now()
              FROM (
                    SELECT ai2.id, SUM(d.seats) AS seated
                      FROM autoscale_items ai2
                      JOIN legislature_district_maps m
                             ON m.legislature_id = ai2.legislature_id
                            AND m.status = 'active' AND m.deleted_at IS NULL
                      JOIN legislature_districts d
                             ON d.map_id = m.id AND d.deleted_at IS NULL
                     WHERE ai2.run_id = ? AND ai2.kind = 'sweep' AND ai2.status = 'pending'
                     GROUP BY ai2.id
              ) s
             WHERE ai.id = s.id
               AND ai.status = 'pending'
        ", [$run->id]);

        // B3 — founding maps, minted SET-BASED (draft; activation is the
        // finalize step's flip). One per sweep legislature lacking one; the
        // map_id stamp on the item is what every scope worker files into —
        // no per-worker ensureFoundingMap, no two-maps race.
        DB::statement("
            INSERT INTO legislature_district_maps
                (id, legislature_id, name, description, status, created_at, updated_at)
            SELECT gen_random_uuid(), ai.legislature_id, 'Founding Map',
                   'Auto-generated by full-scale autoscale (True All Scale, 2026-07-18) — mixed autoseed sweep.',
                   'draft', now(), now()
              FROM autoscale_items ai
             WHERE ai.run_id = ? AND ai.kind = 'sweep' AND ai.status = 'pending'
               AND NOT EXISTS (SELECT 1 FROM legislature_district_maps m
                                WHERE m.legislature_id = ai.legislature_id
                                  AND m.name = 'Founding Map'
                                  AND m.deleted_at IS NULL)
        ", [$run->id]);

        DB::statement("
            UPDATE autoscale_items ai
               SET map_id = fm.id, updated_at = now()
              FROM (
                    SELECT DISTINCT ON (legislature_id) id, legislature_id
                      FROM legislature_district_maps
                     WHERE name = 'Founding Map' AND deleted_at IS NULL
                     ORDER BY legislature_id, created_at DESC
              ) fm
             WHERE ai.run_id = ? AND ai.kind = 'sweep' AND ai.map_id IS NULL
               AND fm.legislature_id = ai.legislature_id
        ", [$run->id]);

        // B4 — one ROOT SCOPE per open sweep item: the seed of the
        // incremental giant-cascade materialization. Children scopes are
        // minted by each completing scope (the one-frame law forbids
        // freezing the tree here).
        DB::statement("
            INSERT INTO autoscale_scopes
                (id, run_id, item_id, legislature_id, scope_jurisdiction_id,
                 depth, status, created_at, updated_at)
            SELECT gen_random_uuid(), ?, ai.id, ai.legislature_id, ai.jurisdiction_id,
                   0, 'pending', now(), now()
              FROM autoscale_items ai
             WHERE ai.run_id = ? AND ai.kind = 'sweep' AND ai.status = 'pending'
                ON CONFLICT ON CONSTRAINT autoscale_scopes_scope_uq DO NOTHING
        ", [$run->id, $run->id]);

        // B5 — the adjacency precompute worklist (global tables; already-done
        // parents from a prior run/iteration are kept — precompute is paid
        // once per GEOMETRY, not once per attempt).
        $pendingParents = app(AdjacencyPrecompute::class)->seedWorklist();
        AutoscaleRun::query()->whereKey($run->id)->update([
            'precompute_started_at' => $run->precompute_started_at ?? now(),
            'updated_at'            => now(),
        ]);

        $singlesTotal = (int) DB::table('autoscale_items')
            ->where('run_id', $run->id)->where('kind', 'single')->count();
        $sweepsTotal = (int) DB::table('autoscale_items')
            ->where('run_id', $run->id)->where('kind', 'sweep')->count();

        $run->forceFill([
            'status'             => 'mapping',
            'mapping_started_at' => $run->mapping_started_at ?? now(),
            'sized_parents'      => $sizedParents,
            'sized_leaves'       => $sizedLeaves,
            'singles_total'      => $singlesTotal,
            'sweeps_total'       => $sweepsTotal,
        ])->save();

        app(AuditService::class)->append(
            module: 'elections',
            event: 'autoscale.sizing_completed',
            payload: [
                'run_id'        => (string) $run->id,
                'sized_parents' => $sizedParents,
                'sized_leaves'  => $sizedLeaves,
                'adm_max'       => (int) $run->adm_max,
                'generator'     => 'AutoscaleSizingJob (pull engine, 2026-07-19)',
            ],
            ref: 'WF-ELE-02',
        );

        Log::info('Autoscale sizing complete', [
            'run_id' => $run->id, 'parents' => $sizedParents, 'leaves' => $sizedLeaves,
            'singles' => $singlesTotal, 'sweeps' => $sweepsTotal,
            'precompute_pending' => $pendingParents,
        ]);
    }

    /**
     * Mirror of ActivationService::ensureBootstrapBoard for the planet root:
     * one active is_bootstrap board + the synthetic system member (user_id
     * NULL, always seated per the B-2 CHECK). An existing ACTIVE board on the
     * root is adopted. Idempotent across resumes.
     */
    private function ensureRootBootstrapBoard(AutoscaleRun $run): void
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

        app(AuditService::class)->append(
            module: 'elections',
            event: 'bootstrap_board_constituted',
            payload: [
                'election_board_id' => $boardId,
                'legislature_id'    => $legislatureId,
                'is_bootstrap'      => true,
                'system_member_id'  => $memberId,
                'banner'            => 'temporary · replacement queued (retired by WF-ELE-10, Phase C)',
                'generator'         => 'AutoscaleSizingJob (pull engine, 2026-07-19)',
            ],
            ref: 'WF-ELE-02',
            jurisdictionId: (string) $root->id,
        );
    }

    private function heartbeat(AutoscaleRun $run): void
    {
        AutoscaleRun::query()->whereKey($run->id)->update([
            'sizing_lease_at' => now(),
            'updated_at'      => now(),
        ]);
    }

    private function haltRequested(AutoscaleRun $run): bool
    {
        return AutoscaleRun::query()->whereKey($run->id)
            ->whereNotNull('halt_requested_at')
            ->exists();
    }
}
