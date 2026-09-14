<?php

namespace App\Console\Commands;

use App\Console\Concerns\GuardsSyntheticData;
use App\Models\SimRun;
use App\Services\AuditService;
use App\Services\Demo\Stages\CohortStage;
use App\Support\HostCapacity;
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
                            {--sample-pct=1 : Percent of a leaf population to materialize as people (parents inherit via bind-up)}
                            {--adm-max=6 : Deepest adm level to populate}
                            {--limit= : Only enumerate the N largest jurisdictions (a smoke run)}
                            {--jurisdiction= : Scope the world to this jurisdiction and its subtree (slug or UUID) — the narrow co-test posture}
                            {--aspects= : Comma-separated aspects to simulate (elections,governance,civic_life,training,money); prerequisites auto-included; default all}
                            {--no-floor : Disable the election roster floor top-up (measure the pre-floor-fix behaviour — short scopes file review instead of minting)}
                            {--resume : Adopt the newest unfinished run instead of starting one}';

    protected $description = 'Start a simulated-world populate run and enumerate its worklist';

    /**
     * THE ETL RULE chunk size, DERIVED FROM THE HOST (operator ruling
     * 2026-09-13, the derive-from-host law generalized to every sibling): one
     * source, HostCapacity::enumerationChunk(), shared with ProvisionRunControl
     * and AutoscaleEnumeration. The fallback host resolves to 25000. Env
     * override CGA_ENUM_CHUNK.
     */
    public static function chunk(): int
    {
        return HostCapacity::enumerationChunk();
    }

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
                    'sample_pct' => max(0.0, (float) $this->option('sample-pct')),
                    'adm_max' => $admMax,
                    'limit' => $limit,
                    'no_floor' => (bool) $this->option('no-floor'),
                    'scope_jurisdiction_id' => $scopeRootId,
                    'scope_aspects' => $this->parseAspects(),
                ],
                'phase_timings' => [],
            ]);

            $this->info("run {$run->id} created (version {$version}, turnout {$turnout}%)");
        } else {
            $this->info("resuming run {$run->id}");

            // A RESUME REPRODUCES THE ORIGINAL SET (operator ruling
            // sim-resume-cursor = A). adm_max and limit come from the stored
            // run options, never the command line, so a resume that omits the
            // original flags enumerates the same set the run started with.
            $resolved = self::resolveResumeParams($run->options ?? [], $admMax, $limit);
            if ($resolved['overrode']) {
                $this->warn('resume: using the stored run options (adm_max='.$resolved['adm_max']
                    .', limit='.($resolved['limit'] === null ? 'none' : $resolved['limit'])
                    .'). Command-line --adm-max/--limit are ignored on resume.');
            }
            $admMax = $resolved['adm_max'];
            $limit  = $resolved['limit'];
            $scopeRootId = $run->options['scope_jurisdiction_id'] ?? null;
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

        // RELEASE THE RUN TO THE PUMP — the LAST step, after the worklist is
        // fully minted and committed. Until now the run is 'queued', which the
        // pump SKIPS, so a half-enumerated run can never be advanced (the
        // create-then-enumerate race, 2026-09-07). Both fresh and --resume land
        // here: a resumed halted run is un-halted, a live one is a no-op.
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();

        $this->newLine();
        $this->info("enumerated {$minted} cohort items — sim:pump will start workers within the minute");

        return self::SUCCESS;
    }

    /**
     * The chosen scope aspects, validated against the known set. Null (the
     * default) means run everything; base is always implied by the run.
     *
     * @return list<string>|null
     */
    private function parseAspects(): ?array
    {
        $raw = trim((string) $this->option('aspects'));
        if ($raw === '') {
            return null;
        }

        $known = array_values(array_diff(SimRun::ALL_ASPECTS, ['base']));
        $chosen = array_values(array_intersect(
            array_map('trim', explode(',', $raw)),
            $known
        ));

        return $chosen === [] ? null : $chosen;
    }

    /**
     * The stored options win over the command line on a resume (operator
     * ruling sim-resume-cursor = A). Pure: no DB, no output — the unit-tested
     * seam. A stored value present (adm_max non-null, or the limit key set)
     * replaces the command-line value; missing stored keys fall back to the
     * command line (the create path, where options == the command line).
     *
     * @param  array<string,mixed>|null  $options
     * @return array{adm_max:int, limit:?int, overrode:bool}
     */
    public static function resolveResumeParams(?array $options, int $cliAdmMax, ?int $cliLimit): array
    {
        $options ??= [];

        $admMax = (array_key_exists('adm_max', $options) && $options['adm_max'] !== null)
            ? (int) $options['adm_max']
            : $cliAdmMax;

        $limit = array_key_exists('limit', $options)
            ? ($options['limit'] === null ? null : (int) $options['limit'])
            : $cliLimit;

        return [
            'adm_max' => $admMax,
            'limit' => $limit,
            'overrode' => ($admMax !== $cliAdmMax) || ($limit !== $cliLimit),
        ];
    }

    /**
     * Mint one `cohort_scope` item per eligible jurisdiction, in bounded,
     * individually committed KEYSET chunks (G2, operator ruling
     * sim-resume-cursor = A).
     *
     * THE RESUME DEFECT THIS REPAIRS: the old walk paged by OFFSET and looped
     * on rows INSERTED. A resume whose first page was already enrolled saw the
     * NOT EXISTS guard insert zero rows and exited after one page, so every
     * later, unenrolled cohort was never minted. The walk now:
     *   - orders LARGEST-FIRST by (COALESCE(population,0) DESC, id) and pages
     *     by a KEYSET cursor over that order (bounds the INPUT, never a
     *     planet-wide OFFSET rescan);
     *   - loops on rows SCANNED, not inserted, so a fully pre-enrolled chunk
     *     still advances the cursor to the next chunk;
     *   - persists a durable {key, id, position_max, scanned_total} into
     *     sim_runs.enum_cursor after each committed chunk, so a resume
     *     continues from the stored maximum and positions never restart at 0;
     *   - keeps NOT EXISTS + the sim_items_unit_uq key, so a re-scan is a
     *     no-op and repeat resumes never duplicate or skip.
     * This mirrors the Step 4 sibling ProvisionRunControl::materializeLedger.
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

        // SUBTREE SCOPE — pre-compute the descendant roster ONCE (the Step 3/4
        // "index the list once" pattern). The recursive CTE materialises the
        // WHOLE subtree before any LIMIT bites, so running it per chunk re-walked
        // India's 661k descendants every chunk and ran Postgres out of lock
        // memory (2026-09-07). Walk it once into a temp roster and paginate that
        // flat, indexed table; --resume re-materialises it (a fresh session) and
        // the KEYSET cursor rides over it identically. The ROOT scope needs no
        // roster — it is already a flat paginated index scan.
        if ($scopeRootId !== null) {
            DB::statement('DROP TABLE IF EXISTS sim_scope_roster');
            DB::statement(
                "CREATE TEMP TABLE sim_scope_roster AS $subtreeCte
                 SELECT j.id, j.adm_level, COALESCE(j.population, 0) AS population
                   FROM jurisdictions j JOIN sub ON sub.id = j.id
                  WHERE j.deleted_at IS NULL AND j.adm_level <= ?",
                [$scopeRootId, $admMax],
            );
            DB::statement('CREATE INDEX ON sim_scope_roster (population DESC, id)');
        }

        $eligible = $scopeRootId === null
            ? DB::table('jurisdictions')
                ->whereNull('deleted_at')
                ->where('adm_level', '<=', $admMax)
                ->count()
            : (int) DB::selectOne('SELECT count(*) AS n FROM sim_scope_roster')->n;

        $this->line($scopeRootId === null
            ? "eligible jurisdictions (adm ≤ {$admMax}): {$eligible}"
            : "eligible jurisdictions (adm ≤ {$admMax}, subtree of {$scopeRootId}): {$eligible}");

        // Portable-SQL tokens: the id generator and the cursor-id cast are the
        // only Postgres/sqlite differences; the walk structure is identical
        // (one owner, no divergent code path), so the DB-free fixture exercises
        // the real keyset walk.
        $driver = DB::connection()->getDriverName();
        $isPg = $driver === 'pgsql';
        $newId = $isPg ? 'gen_random_uuid()' : 'lower(hex(randomblob(16)))';
        $idCast = $isPg ? '::uuid' : '';

        $chunkSize = self::chunk();
        $ts = now();

        // THE STARTING CURSOR: the stored enum_cursor on a resume, else a
        // recovery boundary derived from any already-enrolled prefix (an
        // in-flight run seeded by the old restart-from-zero enumerator), else
        // a fresh start. Positions continue from the stored maximum.
        [$curPop, $curId, $base, $scannedTotal] = $this->resumeCursor($run);
        $hasCursor = $curId !== null;

        $target = $limit !== null ? min($limit, $eligible) : $eligible;
        $bar = $this->output->createProgressBar(max(0, $target));
        $bar->start();
        $bar->setProgress(min($scannedTotal, max(0, $target)));

        $startAt = microtime(true);
        $scannedThisRun = 0;
        $insertedTotal = 0;

        while (true) {
            $take = $limit !== null ? min($chunkSize, $limit - $scannedTotal) : $chunkSize;
            if ($take <= 0) {
                break;
            }

            [$pageSql, $pageBinds] = $this->pageSql($scopeRootId, $admMax, $hasCursor, $curPop, $curId, $take, $idCast);

            // SCAN the page — scanned count and the ordering-last row (the next
            // cursor). Loop terminates on scanned, never on inserted.
            $scan = DB::selectOne(
                "WITH page AS ($pageSql)
                 SELECT (SELECT count(*) FROM page) AS scanned,
                        (SELECT id FROM page ORDER BY population ASC, id DESC LIMIT 1) AS last_id,
                        (SELECT population FROM page ORDER BY population ASC, id DESC LIMIT 1) AS last_pop",
                $pageBinds
            );
            $scanned = (int) ($scan->scanned ?? 0);
            if ($scanned === 0) {
                break;
            }

            // ENROL the page. NOT EXISTS + sim_items_unit_uq make a re-scan a
            // no-op, so a resume over an already-enrolled chunk inserts nothing
            // yet still advances the cursor below. position = base +
            // row_number(), so numbering stays dense and largest-first across
            // the resume boundary.
            $inserted = DB::affectingStatement(
                "INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT {$newId}, ?, 'cohort_scope', 'pending', j.id, j.adm_level, CAST(j.id AS text),
                        ? + row_number() OVER (ORDER BY j.population DESC, j.id),
                        j.population, '{}', ?, ?
                   FROM ({$pageSql}) j
                  WHERE NOT EXISTS (
                        SELECT 1 FROM sim_items s
                         WHERE s.run_id = ? AND s.kind = 'cohort_scope' AND s.unit_key = CAST(j.id AS text)
                  )",
                array_merge([$run->id, $base, $ts, $ts], $pageBinds, [$run->id])
            );

            $base += $inserted;
            $insertedTotal += $inserted;
            $scannedTotal += $scanned;
            $scannedThisRun += $scanned;
            $curPop = (int) $scan->last_pop;
            $curId = (string) $scan->last_id;
            $hasCursor = true;

            // PERSIST THE CURSOR each committed chunk — resumable at chunk
            // granularity. A crash after the insert but before this save leaves
            // the cursor behind actual inserts; the resume re-scans that chunk
            // and NOT EXISTS makes it a no-op, then advances. Never a gap.
            $run->forceFill(['enum_cursor' => [
                'key' => $curPop,
                'id' => $curId,
                'position_max' => $base,
                'scanned_total' => $scannedTotal,
            ]])->save();

            $bar->setProgress(min($scannedTotal, max(0, $target)));

            // VISIBLE: per-chunk scanned / inserted / elapsed / ETA (ETA from
            // this invocation's rate over the remaining eligible count).
            $elapsed = microtime(true) - $startAt;
            $remaining = max(0, $target - $scannedTotal);
            $eta = ($scannedThisRun > 0 && $elapsed > 0)
                ? $this->fmtDuration($remaining / ($scannedThisRun / $elapsed))
                : '—';
            $this->line(sprintf(
                '  chunk: scanned=%d inserted=%d total=%d/%d elapsed=%s eta=%s',
                $scanned, $inserted, $scannedTotal, $target, $this->fmtDuration($elapsed), $eta
            ));

            if ($scanned < $take) {
                break;
            }
        }

        $bar->finish();
        $this->newLine();

        return $insertedTotal;
    }

    /**
     * The starting cursor for enumeration.
     *
     * @return array{0:int, 1:?string, 2:int, 3:int} [population, id, position_max, scanned_total]
     */
    private function resumeCursor(SimRun $run): array
    {
        $cursor = $run->enum_cursor;
        if (is_array($cursor) && isset($cursor['id'])) {
            return [
                (int) ($cursor['key'] ?? 0),
                (string) $cursor['id'],
                (int) ($cursor['position_max'] ?? 0),
                (int) ($cursor['scanned_total'] ?? 0),
            ];
        }

        // RECOVERY (mirror ProvisionRunControl::resume): no stored cursor, but
        // an already-enrolled prefix from the old enumerator. The buggy walk
        // always left a contiguous largest-first prefix, so the max-position
        // enrolled row is the exact resume boundary — continue past it, never
        // re-scan it. est_cost stores COALESCE(population,0); unit_key stores
        // the text id — the two ordering keys.
        $enrolled = (int) DB::table('sim_items')
            ->where('run_id', $run->id)
            ->where('kind', 'cohort_scope')
            ->count();
        if ($enrolled > 0) {
            $last = DB::selectOne(
                "SELECT position, est_cost, unit_key FROM sim_items
                  WHERE run_id = ? AND kind = 'cohort_scope'
                  ORDER BY position DESC LIMIT 1",
                [$run->id]
            );

            return [
                (int) ($last->est_cost ?? 0),
                (string) $last->unit_key,
                (int) ($last->position ?? $enrolled),
                $enrolled,
            ];
        }

        return [0, null, 0, 0];
    }

    /**
     * The keyset page SELECT and its bindings, for both the scan and the
     * insert (one fragment, so the two statements can never diverge). Rows
     * strictly AFTER the cursor in (population DESC, id ASC) order:
     *   population < curPop OR (population = curPop AND id > curId).
     *
     * @return array{0:string, 1:array<int,mixed>}
     */
    private function pageSql(?string $scopeRootId, int $admMax, bool $hasCursor, int $curPop, ?string $curId, int $take, string $idCast): array
    {
        if ($scopeRootId === null) {
            $sql = 'SELECT id, adm_level, COALESCE(population, 0) AS population
                      FROM jurisdictions
                     WHERE deleted_at IS NULL AND adm_level <= ?'
                . ($hasCursor
                    ? " AND (COALESCE(population, 0) < ? OR (COALESCE(population, 0) = ? AND id > ?{$idCast}))"
                    : '')
                . ' ORDER BY COALESCE(population, 0) DESC, id
                     LIMIT ?';
            $binds = $hasCursor ? [$admMax, $curPop, $curPop, $curId, $take] : [$admMax, $take];

            return [$sql, $binds];
        }

        $sql = 'SELECT id, adm_level, population
                  FROM sim_scope_roster'
            . ($hasCursor
                ? " WHERE (population < ? OR (population = ? AND id > ?{$idCast}))"
                : '')
            . ' ORDER BY population DESC, id
                 LIMIT ?';
        $binds = $hasCursor ? [$curPop, $curPop, $curId, $take] : [$take];

        return [$sql, $binds];
    }

    /** Compact H:MM:SS / M:SS / Ns duration for the per-chunk progress line. */
    private function fmtDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
