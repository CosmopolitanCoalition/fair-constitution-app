<?php

namespace App\Console\Commands;

use App\Console\Concerns\GuardsSyntheticData;
use App\Models\SimRun;
use App\Services\AuditService;
use App\Services\Demo\Stages\CohortStage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Start a simulated-world populate run and enumerate its worklist.
 *
 * THE ETL RULE, at the one place it matters most: enumeration is the single
 * biggest write in the whole engine (~907k rows planet-wide) and it runs as
 * BOUNDED, INDIVIDUALLY COMMITTED CHUNKS with a progress line per chunk —
 * never one opaque multi-hour INSERT…SELECT. A `psql` count moves while it
 * runs, it resumes cleanly at any boundary because the NOT-EXISTS guard makes
 * redo a no-op, and a halt lands in seconds.
 *
 * The item set is deliberately built from LANE 1's clean geometry: only
 * jurisdictions that actually carry a population and are not flagged for
 * districting review. The demo does not need perfect maps (operator, 07-25) —
 * it needs an honest set and a visible count of what it skipped.
 */
class SimStartCommand extends Command
{
    use GuardsSyntheticData;

    protected $signature = 'sim:start
                            {--world-version=1 : Determinism version — bump to regenerate the world}
                            {--turnout=62 : Percent of population that casts a ballot}
                            {--adm-max=6 : Deepest adm level to populate}
                            {--limit= : Only enumerate the N largest jurisdictions (a smoke run)}
                            {--jurisdiction= : Scope the world to this jurisdiction and its subtree (slug or UUID) — the narrow co-test posture}
                            {--resume : Adopt the newest unfinished run instead of starting one}';

    protected $description = 'Start a simulated-world populate run and enumerate its worklist';

    /** THE ETL RULE chunk size, matching AutoscaleEnumeration::CHUNK. */
    private const CHUNK = 25000;

    public function __construct(private readonly AuditService $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->guardSyntheticData()) {
            return self::FAILURE;
        }

        $version = (int) $this->option('world-version');
        $turnout = max(0, min(100, (int) $this->option('turnout')));
        $admMax = (int) $this->option('adm-max');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // SUBTREE SCOPE (operator, 2026-08-08 — the manual-map co-test: he
        // activates a jurisdiction, draws its map, then simulates THAT
        // jurisdiction; the sim engine runs identically, just over a narrower
        // roster). Slug or UUID; unknown → refuse loudly.
        $scopeRootId = null;
        $scope = trim((string) $this->option('jurisdiction'));
        if ($scope !== '') {
            $scopeRootId = DB::table('jurisdictions')
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('slug', $scope)
                    ->when(Str::isUuid($scope), fn ($qq) => $qq->orWhere('id', $scope)))
                ->value('id');
            if ($scopeRootId === null) {
                $this->error("No jurisdiction matches '{$scope}'.");

                return self::FAILURE;
            }
            $scopeRootId = (string) $scopeRootId;
        }

        $run = $this->option('resume')
            ? SimRun::query()->whereIn('status', ['queued', 'running', 'halted'])->orderByDesc('created_at')->first()
            : null;

        if ($run === null) {
            $existing = SimRun::query()->whereIn('status', ['queued', 'running', 'halted'])->count();

            if ($existing > 0) {
                $this->error('An unfinished run already holds the engine. Use --resume, or halt it first.');

                return self::FAILURE;
            }

            $run = SimRun::create([
                'status' => 'queued',
                'phase' => 'cohorts', // no research layer yet; cohorts is the first real stage
                'options' => [
                    'version' => $version,
                    'turnout_pct' => $turnout,
                    'adm_max' => $admMax,
                    'limit' => $limit,
                    'scope_jurisdiction_id' => $scopeRootId,
                ],
                'phase_timings' => [],
            ]);

            $this->info("run {$run->id} created (version {$version}, turnout {$turnout}%)");
        } else {
            $this->info("resuming run {$run->id}");
        }

        $minted = $this->enumerateCohorts($run, $admMax, $limit,
            $scopeRootId ?? ($run->options['scope_jurisdiction_id'] ?? null));

        // ONE summary audit entry, appended LAST — after the bulk writes have
        // committed. The append takes a GLOBAL advisory lock held for the whole
        // enclosing transaction, so appending it early would stall every other
        // writer on the instance for the duration of the enumeration.
        $this->audit->append(
            module: 'simworld',
            event: 'sim.enumerated',
            payload: [
                'run_id' => (string) $run->id,
                'items_minted' => $minted,
                'version' => $version,
                'turnout_pct' => $turnout,
                'adm_max' => $admMax,
            ],
            ref: 'WF-SYS-04',
        );

        $this->newLine();
        $this->info("enumerated {$minted} cohort items — sim:pump will start workers within the minute");

        return self::SUCCESS;
    }

    /**
     * Mint one `cohort_scope` item per eligible jurisdiction, in bounded chunks.
     *
     * Ordering is LARGEST-FIRST (`position` from population DESC) so the
     * biggest populations start immediately rather than defining the tail —
     * the geodata plan's inversion of autoscale's simplest-first.
     */
    private function enumerateCohorts(SimRun $run, int $admMax, ?int $limit, ?string $scopeRootId = null): int
    {
        // Subtree scope: the SAME enumeration over a narrower roster. The
        // recursive CTE rides inside each chunk's statement — bounded, and
        // NOT EXISTS keeps redo clean exactly as before.
        $subtreeCte = "WITH RECURSIVE sub AS (
                        SELECT id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                        UNION ALL
                        SELECT c.id FROM jurisdictions c JOIN sub ON c.parent_id = sub.id
                         WHERE c.deleted_at IS NULL
                       )";

        $eligible = $scopeRootId === null
            ? DB::table('jurisdictions')
                ->whereNull('deleted_at')
                ->where('adm_level', '<=', $admMax)
                ->count()
            : (int) DB::selectOne(
                "$subtreeCte SELECT count(*) AS n FROM jurisdictions j
                   JOIN sub ON sub.id = j.id
                  WHERE j.deleted_at IS NULL AND j.adm_level <= ?",
                [$scopeRootId, $admMax],
            )->n;

        $this->line($scopeRootId === null
            ? "eligible jurisdictions (adm ≤ {$admMax}): {$eligible}"
            : "eligible jurisdictions (adm ≤ {$admMax}, subtree of {$scopeRootId}): {$eligible}");

        $bar = $this->output->createProgressBar($limit ?? $eligible);
        $bar->start();

        $total = 0;
        $offset = 0;

        do {
            $take = $limit !== null ? min(self::CHUNK, $limit - $total) : self::CHUNK;

            if ($take <= 0) {
                break;
            }

            // Each chunk is its own committed statement. NOT EXISTS makes redo
            // clean, so a crash mid-enumeration resumes without duplicates.
            if ($scopeRootId === null) {
                $n = DB::affectingStatement(
                    "INSERT INTO sim_items
                        (id, run_id, kind, status, jurisdiction_id, adm_level, unit_key,
                         position, est_cost, metrics, created_at, updated_at)
                     SELECT gen_random_uuid(), ?, 'cohort_scope', 'pending', j.id, j.adm_level, j.id::text,
                            ? + row_number() OVER (ORDER BY COALESCE(j.population, 0) DESC, j.id),
                            COALESCE(j.population, 0), '{}', now(), now()
                       FROM (
                            SELECT id, adm_level, population
                              FROM jurisdictions
                             WHERE deleted_at IS NULL AND adm_level <= ?
                             ORDER BY COALESCE(population, 0) DESC, id
                             LIMIT ? OFFSET ?
                       ) j
                      WHERE NOT EXISTS (
                            SELECT 1 FROM sim_items s
                             WHERE s.run_id = ? AND s.kind = 'cohort_scope' AND s.unit_key = j.id::text
                      )",
                    [$run->id, $total, $admMax, $take, $offset, $run->id]
                );
            } else {
                $n = DB::affectingStatement(
                    "$subtreeCte
                     INSERT INTO sim_items
                        (id, run_id, kind, status, jurisdiction_id, adm_level, unit_key,
                         position, est_cost, metrics, created_at, updated_at)
                     SELECT gen_random_uuid(), ?, 'cohort_scope', 'pending', j.id, j.adm_level, j.id::text,
                            ? + row_number() OVER (ORDER BY COALESCE(j.population, 0) DESC, j.id),
                            COALESCE(j.population, 0), '{}', now(), now()
                       FROM (
                            SELECT j2.id, j2.adm_level, j2.population
                              FROM jurisdictions j2
                              JOIN sub ON sub.id = j2.id
                             WHERE j2.deleted_at IS NULL AND j2.adm_level <= ?
                             ORDER BY COALESCE(j2.population, 0) DESC, j2.id
                             LIMIT ? OFFSET ?
                       ) j
                      WHERE NOT EXISTS (
                            SELECT 1 FROM sim_items s
                             WHERE s.run_id = ? AND s.kind = 'cohort_scope' AND s.unit_key = j.id::text
                      )",
                    [$scopeRootId, $run->id, $total, $admMax, $take, $offset, $run->id]
                );
            }

            $total += $n;
            $offset += $take;
            $bar->advance($n);

            if ($n > 0) {
                $this->line("  chunk committed: {$total} items");
            }
        } while ($n > 0 && $offset < ($limit ?? $eligible));

        $bar->finish();
        $this->newLine();

        return $total;
    }
}
