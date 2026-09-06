<?php

namespace App\Services\Provision;

use App\Models\ProvisionRun;
use App\Services\AuditService;
use App\Services\InstitutionProvisionService;
use App\Services\InstitutionScaleService;
use App\Support\ProvisionClaims;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ONE OWNER for the Step 4 run's operator controls (Wave 6) — start, halt,
 * resume, rollback, the ledger materialization and the counters — shared by
 * the wizard endpoints and the provision:* CLI so the two doors cannot drift.
 *
 * THE ESCAPE-HATCH LAW: halt, resume and rollback are never blocked by the
 * state they exist to recover from. Halt parks the run and the pump reaps
 * the lanes; resume clears the flag; rollback refuses only while a run is
 * live (halt it first), then deletes the run's product in bounded chunks.
 */
class ProvisionRunControl
{
    public const LEDGER_CHUNK = 25000;

    public function __construct(
        private readonly InstitutionProvisionService $provision,
        private readonly FoundingTreasuryService $treasury,
        private readonly AuditService $audit,
    ) {}

    public function liveRun(): ?ProvisionRun
    {
        return ProvisionRun::query()
            ->whereIn('status', ProvisionRun::LIVE_STATUSES)
            ->orderBy('created_at')
            ->first();
    }

    public function latestRun(): ?ProvisionRun
    {
        return ProvisionRun::query()->orderByDesc('created_at')->first();
    }

    /**
     * Start (or adopt) the run. A live run is returned as is. A done run is
     * left alone: a re-run needs a rollback or a fresh ledger sweep, both
     * explicit. Returns [ok, run_id, created].
     *
     * @return array{ok:bool, run_id:?string, created:bool, error:?string}
     */
    public function start(): array
    {
        if ($live = $this->liveRun()) {
            return ['ok' => true, 'run_id' => (string) $live->id, 'created' => false, 'error' => null];
        }

        if (DB::table('autoscale_runs')->whereIn('status', ['queued', 'sizing', 'mapping'])->exists()) {
            return ['ok' => false, 'run_id' => null, 'created' => false,
                'error' => 'The district maps are still being drawn. Step 4 starts when the map run is done.'];
        }

        $run = ProvisionRun::create(['status' => ProvisionRun::STATUS_QUEUED]);

        $this->audit->append(
            module: 'jurisdictions',
            event: 'provision.started',
            payload: ['run_id' => (string) $run->id, 'generator' => 'Step 4 engine (Wave 6)'],
            ref: 'WF-JUR-01',
        );

        return ['ok' => true, 'run_id' => (string) $run->id, 'created' => true, 'error' => null];
    }

    /**
     * Materialize the ledger: one row per live legislature, in keyset chunks
     * (resumable — ON CONFLICT DO NOTHING). est_cost = seats × 10 + D(P), the
     * unit's cost proxy. Uninhabited childless places (the zero rule under
     * real binding) file as skipped. Returns rows inserted this call.
     */
    public function materializeLedger(ProvisionRun $run, int $maxChunks = PHP_INT_MAX): int
    {
        $zeroRule = $this->provision->binding() !== InstitutionScaleService::BINDING_FREE;
        $skipPredicate = $zeroRule
            ? "(COALESCE(j.population, 0) < 1 AND NOT EXISTS (SELECT 1 FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL))"
            : 'false';

        $afterId  = '00000000-0000-0000-0000-000000000000';
        $inserted = 0;
        for ($chunk = 0; $chunk < $maxChunks; $chunk++) {
            $rows = DB::select("
                WITH page AS (
                    SELECT l.id, l.jurisdiction_id
                      FROM legislatures l
                     WHERE l.deleted_at IS NULL AND l.id > ?::uuid
                     ORDER BY l.id
                     LIMIT ?
                ),
                ins AS (
                    INSERT INTO provision_ledger (legislature_id, jurisdiction_id, est_cost, stage, status, reason, updated_at)
                    SELECT p.id, p.jurisdiction_id,
                           COALESCE(l.total_seats, 0) * 10
                             + CASE WHEN COALESCE(j.population, 0) < 1 THEN 0
                                    ELSE GREATEST(3, LEAST(30, ROUND(-7.8 + 1.67 * LN(j.population::numeric))::int)) END,
                           0,
                           CASE WHEN {$skipPredicate} THEN 'skipped' ELSE 'pending' END,
                           CASE WHEN {$skipPredicate} THEN 'zero rule: uninhabited, no constituents' ELSE NULL END,
                           now()
                      FROM page p
                      JOIN legislatures l ON l.id = p.id
                      JOIN jurisdictions j ON j.id = p.jurisdiction_id
                    ON CONFLICT (legislature_id) DO NOTHING
                    RETURNING 1
                )
                SELECT (SELECT count(*) FROM page) AS scanned,
                       (SELECT count(*) FROM ins) AS inserted,
                       (SELECT id FROM page ORDER BY id DESC LIMIT 1) AS last_id
            ", [$afterId, self::LEDGER_CHUNK]);
            $r = $rows[0] ?? null;
            $scanned = (int) ($r->scanned ?? 0);
            if ($scanned === 0) {
                break;
            }
            $inserted += (int) ($r->inserted ?? 0);
            $afterId = (string) $r->last_id;
            if ($scanned < self::LEDGER_CHUNK) {
                break;
            }
        }

        return $inserted;
    }

    /** @return array{ok:bool, error:?string} */
    public function halt(): array
    {
        $run = $this->liveRun();
        if ($run === null) {
            return ['ok' => false, 'error' => 'No live Step 4 run.'];
        }
        if ($run->halt_requested_at === null) {
            $run->forceFill(['halt_requested_at' => now(), 'updated_at' => now()])->save();
        }
        Cache::put('provision.halt_requested', true, 86400);

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:bool, run_id:?string, error:?string} */
    public function resume(bool $requeueReview = false): array
    {
        $run = $this->liveRun() ?? $this->latestRun();
        if ($run === null) {
            return ['ok' => false, 'run_id' => null, 'error' => 'No Step 4 run to resume.'];
        }

        Cache::forget('provision.halt_requested');

        if ($requeueReview) {
            DB::update("
                UPDATE provision_ledger
                   SET status = 'pending', claim_token = NULL, finished_at = NULL,
                       reason = 'requeued: ' || LEFT(COALESCE(reason, ''), 800), updated_at = now()
                 WHERE status = 'review'
            ");
        }

        $patch = ['halt_requested_at' => null, 'updated_at' => now()];
        if ($run->status === ProvisionRun::STATUS_HALTED) {
            $patch['status'] = ProvisionRun::STATUS_RUNNING;
        } elseif ($run->status === ProvisionRun::STATUS_DONE && $requeueReview && ProvisionClaims::openWork()) {
            $patch['status'] = ProvisionRun::STATUS_RUNNING;
            $patch['finished_at'] = null;
        }
        $run->forceFill($patch)->save();

        return ['ok' => true, 'run_id' => (string) $run->id, 'error' => null];
    }

    /**
     * THE ROLLBACK (item 2). Deletes the run's product and resets the ledger,
     * in bounded chunks over the ledger rows:
     *   default   the unit stage — elections + races + their timers, the
     *             system-act committees, the system-act departments with
     *             their boards, seats, reports and founding charter laws, the
     *             zero-balance treasuries; rows return to stage 1;
     *   --shells  also the forming shells (executives, courts, bootstrap
     *             boards + system members, unused civic spaces); rows return
     *             to stage 0.
     * Refuses while a run is live with fresh lanes (halt it first).
     *
     * @return array{ok:bool, error:?string, deleted:array<string,int>}
     */
    public function revert(bool $shells = false, bool $force = false): array
    {
        $run = $this->latestRun();
        if ($run === null) {
            return ['ok' => false, 'error' => 'No Step 4 run to roll back.', 'deleted' => []];
        }
        if (in_array($run->status, [ProvisionRun::STATUS_QUEUED, ProvisionRun::STATUS_RUNNING], true) && ! $force) {
            return ['ok' => false, 'error' => "Run {$run->id} is {$run->status} — halt it first.", 'deleted' => []];
        }
        $liveLanes = (int) DB::table('provision_worker_leases')
            ->where('last_seen_at', '>', now()->subMinutes(2))->count();
        if ($liveLanes > 0 && ! $force) {
            return ['ok' => false, 'error' => "{$liveLanes} lane(s) still live — wait for them to park (≤2 min).", 'deleted' => []];
        }

        $deleted = [
            'clock_timers' => 0, 'election_races' => 0, 'elections' => 0, 'committees' => 0,
            'departments' => 0, 'laws' => 0, 'treasury_accounts' => 0,
            'executives' => 0, 'judiciaries' => 0, 'election_boards' => 0, 'social_spaces' => 0,
        ];

        // Chunked over the ledger by legislature id (keyset), each chunk one
        // committed transaction — THE ETL RULE.
        $afterId = '00000000-0000-0000-0000-000000000000';
        while (true) {
            $ids = DB::table('provision_ledger')
                ->where('legislature_id', '>', $afterId)
                ->where('stage', '>=', $shells ? 0 : 1)
                ->orderBy('legislature_id')
                ->limit(self::LEDGER_CHUNK)
                ->pluck('legislature_id')
                ->map(fn ($id) => (string) $id)
                ->all();
            if ($ids === []) {
                break;
            }
            $afterId = end($ids);

            DB::transaction(function () use ($ids, $shells, &$deleted) {
                DB::statement('CREATE TEMP TABLE IF NOT EXISTS pr_chunk (legislature_id uuid PRIMARY KEY, jurisdiction_id uuid) ON COMMIT DROP');
                DB::statement('DELETE FROM pr_chunk');
                DB::statement("
                    INSERT INTO pr_chunk SELECT legislature_id, jurisdiction_id FROM provision_ledger
                     WHERE legislature_id = ANY(?::uuid[])
                ", ['{'.implode(',', $ids).'}']);

                // Elections minted by the unit (manifest), with their races and timers.
                $deleted['clock_timers'] += DB::delete("
                    DELETE FROM clock_timers t USING provision_ledger pl JOIN pr_chunk c ON c.legislature_id = pl.legislature_id
                     WHERE t.subject_type = 'election' AND t.subject_id = (pl.manifest->'seat'->>'election_id')::uuid
                ");
                $deleted['election_races'] += DB::delete("
                    DELETE FROM election_races r USING provision_ledger pl JOIN pr_chunk c ON c.legislature_id = pl.legislature_id
                     WHERE r.election_id = (pl.manifest->'seat'->>'election_id')::uuid
                ");
                $deleted['elections'] += DB::delete("
                    DELETE FROM elections e USING provision_ledger pl JOIN pr_chunk c ON c.legislature_id = pl.legislature_id
                     WHERE e.id = (pl.manifest->'seat'->>'election_id')::uuid
                ");
                // System-act committees (no vote, no law).
                $deleted['committees'] += DB::delete("
                    DELETE FROM committees k USING pr_chunk c
                     WHERE k.legislature_id = c.legislature_id
                       AND k.created_by_vote_id IS NULL AND k.created_by_law_id IS NULL
                ");
                // System-act departments: reports, seats, boards, the department, then the founding charter law.
                DB::delete("
                    DELETE FROM department_reports dr USING departments d JOIN laws w ON w.id = d.charter_law_id, pr_chunk c
                     WHERE dr.department_id = d.id AND d.jurisdiction_id = c.jurisdiction_id AND w.origin = 'founding'
                ");
                DB::delete("
                    DELETE FROM board_seats bs USING boards b JOIN departments d ON d.board_id = b.id JOIN laws w ON w.id = d.charter_law_id, pr_chunk c
                     WHERE bs.board_id = b.id AND d.jurisdiction_id = c.jurisdiction_id AND w.origin = 'founding'
                ");
                DB::delete("
                    DELETE FROM boards b USING departments d JOIN laws w ON w.id = d.charter_law_id, pr_chunk c
                     WHERE b.id = d.board_id AND d.jurisdiction_id = c.jurisdiction_id AND w.origin = 'founding'
                ");
                $deleted['departments'] += DB::delete("
                    DELETE FROM departments d USING laws w, pr_chunk c
                     WHERE w.id = d.charter_law_id AND d.jurisdiction_id = c.jurisdiction_id AND w.origin = 'founding'
                ");
                DB::delete("
                    DELETE FROM law_versions v USING laws w, pr_chunk c
                     WHERE v.law_id = w.id AND w.legislature_id = c.legislature_id AND w.origin = 'founding' AND w.kind = 'charter'
                ");
                $deleted['laws'] += DB::delete("
                    DELETE FROM laws w USING pr_chunk c
                     WHERE w.legislature_id = c.legislature_id AND w.origin = 'founding' AND w.kind = 'charter'
                ");
                $deleted['treasury_accounts'] += DB::delete("
                    DELETE FROM treasury_accounts t USING pr_chunk c
                     WHERE t.owner_type = 'jurisdictions' AND t.owner_id = c.jurisdiction_id AND t.balance = 0
                ");

                if ($shells) {
                    $deleted['executives'] += DB::delete("
                        DELETE FROM executives e USING pr_chunk c
                         WHERE e.jurisdiction_id = c.jurisdiction_id AND e.status = 'forming'
                           AND NOT EXISTS (SELECT 1 FROM departments d WHERE d.executive_id = e.id)
                    ");
                    $deleted['judiciaries'] += DB::delete("
                        DELETE FROM judiciaries j USING pr_chunk c
                         WHERE j.jurisdiction_id = c.jurisdiction_id AND j.status = 'forming'
                    ");
                    DB::delete("
                        DELETE FROM election_board_members m USING election_boards b, pr_chunk c
                         WHERE m.election_board_id = b.id AND b.jurisdiction_id = c.jurisdiction_id
                           AND b.is_bootstrap = true AND m.user_id IS NULL
                    ");
                    $deleted['election_boards'] += DB::delete("
                        DELETE FROM election_boards b USING pr_chunk c
                         WHERE b.jurisdiction_id = c.jurisdiction_id AND b.is_bootstrap = true
                           AND NOT EXISTS (SELECT 1 FROM election_board_members m WHERE m.election_board_id = b.id)
                           AND NOT EXISTS (SELECT 1 FROM elections e WHERE e.election_board_id = b.id)
                    ");
                    $deleted['social_spaces'] += DB::delete("
                        DELETE FROM social_spaces s USING pr_chunk c
                         WHERE s.jurisdiction_id = c.jurisdiction_id AND s.is_private = false
                           AND s.space_type IN ('public_square', 'halls')
                           AND NOT EXISTS (SELECT 1 FROM social_subforums f WHERE f.space_id = s.id)
                    ");
                }

                DB::update("
                    UPDATE provision_ledger pl
                       SET stage = ?, status = CASE WHEN pl.status = 'skipped' THEN 'skipped' ELSE 'pending' END,
                           claim_token = NULL, started_at = NULL, finished_at = NULL, retry_count = 0,
                           reason = CASE WHEN pl.status = 'skipped' THEN pl.reason ELSE NULL END,
                           manifest = NULL, updated_at = now()
                      FROM pr_chunk c
                     WHERE pl.legislature_id = c.legislature_id
                ", [$shells ? 0 : 1]);
            });
        }

        $run->forceFill([
            'status'         => ProvisionRun::STATUS_HALTED,
            'rolled_back_at' => now(),
            'finished_at'    => null,
            'units_done'     => 0,
            'shells_done'    => $shells ? 0 : $run->shells_done,
            'review_count'   => 0,
            'updated_at'     => now(),
        ])->save();

        $this->audit->append(
            module: 'jurisdictions',
            event: 'provision.rolled_back',
            payload: ['run_id' => (string) $run->id, 'shells' => $shells, 'deleted' => $deleted],
            ref: 'WF-JUR-01',
        );
        Log::warning('Step 4 rollback', ['run_id' => (string) $run->id, 'shells' => $shells, 'deleted' => $deleted]);

        return ['ok' => true, 'error' => null, 'deleted' => $deleted];
    }

    /** Refresh the run's counters from the ledger. */
    public function refreshCounters(ProvisionRun $run): object
    {
        $c = DB::table('provision_ledger')->selectRaw("
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'skipped') AS skipped,
            COUNT(*) FILTER (WHERE stage >= 1) AS shells_done,
            COUNT(*) FILTER (WHERE status = 'done') AS units_done,
            COUNT(*) FILTER (WHERE status = 'review') AS review_count,
            COUNT(*) FILTER (WHERE status = 'running') AS running,
            COUNT(*) FILTER (WHERE status = 'pending' AND stage = 0) AS shells_pending,
            COUNT(*) FILTER (WHERE status = 'pending' AND stage = 1) AS units_pending
        ")->first();

        $run->forceFill([
            'ledger_total'   => (int) $c->total,
            'ledger_skipped' => (int) $c->skipped,
            'shells_done'    => (int) $c->shells_done,
            'units_done'     => (int) $c->units_done,
            'review_count'   => (int) $c->review_count,
            'updated_at'     => now(),
        ])->save();

        return $c;
    }

    /** Found the money plane once per run (idempotent). */
    public function foundMoneyPlane(): ?string
    {
        return $this->treasury->found();
    }
}
