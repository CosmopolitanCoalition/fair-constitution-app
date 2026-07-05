<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Phase P.9 — Export the full map-data state of an instance into a portable
 * tarball that can be restored by `MapDataImportService` on a fresh
 * instance. Skips re-running the ETL.
 *
 * Tables included (the full chain that makes a hierarchy queryable):
 *   - cosmic_addresses          (universe→galaxy→planet hierarchy; FK target
 *                                of instance_settings.cosmic_address_id)
 *   - jurisdictions             (geometry + parent links)
 *   - worldpop_rasters          (population tiles)
 *   - geoboundary_metadata      (display names, region info)
 *   - constitutional_settings   (per-jurisdiction amendments)
 *   - instance_settings         (operator-recorded acceptance + setup state;
 *                                FK → cosmic_addresses, so cosmic_addresses
 *                                must restore first — see TABLES order)
 *
 * pg_dump's `--data-only --table=` flags pull just the rows; schema is
 * applied on the receiving end via `php artisan migrate:fresh` before
 * the import service loads the data.
 *
 * Output: a tar.gz archive with two members:
 *   manifest.json   → { exported_at, schema_version, source_url_hint, table_counts }
 *   data.dump       → pg_dump custom-format (.dump) of the listed tables
 */
class MapDataExportService
{
    /**
     * The CURATED default-export order (the historical, hand-verified,
     * acyclic parent → child chain that makes a jurisdiction hierarchy
     * queryable). This is what a plain export ships and what the import
     * service reverses for its child-first DELETE pass — its order is
     * proven safe and is NOT derived at runtime, so the common path never
     * depends on live introspection.
     *
     * Since Workstream C the CHOOSER (what the picker offers) and the
     * allowlist that gates an operator's explicit `tables[]` selection are
     * fed by {@see deriveExportableTables()} — a schema-derived, self-
     * maintaining list. TABLES stays the *default export order* and the
     * *curated priority prefix* of the derived order, so:
     *   - a plain export (no `tables[]`) is byte-for-byte the historical set,
     *   - the derived list is always a SUPERSET of TABLES, in the same
     *     relative order at the front (the curated chain is pinned), and
     *   - newly-added portable tables appear in the picker automatically.
     *
     * Order is parent → child so pg_restore can replay the rows without
     * deferred-constraint gymnastics; the import service reverses this
     * list for the truncation pass so child rows clear before parents.
     *
     * Skipped on purpose (also enforced by the derive denylist):
     *   - users        — operator-account credentials; not portable across
     *                     instances.
     *   - location_pings — privacy-sensitive GPS history.
     *   - cache / jobs / sessions / migrations — runtime / infra tables.
     *   - postgis system tables (spatial_ref_sys) — managed by the
     *                     PostGIS extension; we don't own their lifecycle.
     *
     * @var list<string>
     */
    public const TABLES = [
        // Settings + cosmic hierarchy
        'cosmic_addresses',
        'instance_settings',
        // Spatial core (the hub — every other app table cascades from here)
        'jurisdictions',
        // Organizations / civil structure
        'organizations',
        // Legislature tree
        'legislatures',
        'legislature_district_maps',
        'legislature_districts',
        'legislature_district_jurisdictions',
        'legislature_members',
        // Executive tree
        'executives',
        'executive_members',
        // Judiciary tree
        'judiciaries',
        'judicial_seats',
        // Elections
        'elections',
        'endorsements',
        // Residency / civic state
        'residency_confirmations',
        // ETL artifacts + amendments
        'data_review_decisions',
        'constitutional_settings',
        'geoboundary_metadata',
        // Heavy raster data (kept last — biggest payload, easiest to
        // identify in the picker by the "heavy" badge, slowest to dump)
        'worldpop_rasters',
    ];

    /**
     * Exact-name denylist for {@see deriveExportableTables()}: framework /
     * infra tables, PostGIS system tables, and privacy / credential /
     * key-material tables that must never leave the instance. Any table
     * whose name matches {@see DENY_PATTERNS} (e.g. `*_private_key`,
     * `*signing_key*`) is also excluded, so key material added later is
     * kept out without touching this list.
     *
     * @var list<string>
     */
    public const DERIVE_DENYLIST = [
        // framework / infra
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens',
        // PostGIS system
        'spatial_ref_sys',
        // privacy / credentials / device + key material — never portable
        'users', 'location_pings', 'operator_accounts', 'operator_devices',
        'mesh_operator_keys', 'oidc_signing_keys',
        // additional dedicated credential / device / key-material tables
        // that fall under the same "never portable" intent as the list above
        'mesh_operator_identities', 'mesh_operator_local_links',
        'oidc_authorization_codes', 'oidc_signing_keys',
        'actor_devices', 'cluster_join_keys', 'election_ballot_key_rewraps',
    ];

    /**
     * Substring/regex denials layered on top of DERIVE_DENYLIST so any
     * table carrying key material (now or added later) is excluded from
     * the schema-derived set automatically.
     *
     * @var list<string>
     */
    public const DERIVE_DENY_PATTERNS = ['/_private_key/', '/signing_key/'];

    /** In-request memo for the derived list (schema introspection is cheap but not free). */
    private ?array $derivedTablesCache = null;

    /**
     * Tables that get filtered out when skip_rasters=true. The receiving
     * import service inspects manifest.included_tables and only truncates
     * those — so a `--skip-rasters` snapshot can be restored over a target
     * that already has its own worldpop_rasters loaded without clobbering.
     *
     * @var list<string>
     */
    public const RASTER_TABLES = ['worldpop_rasters'];

    /**
     * @param  ?string         $tmpDir       Override storage_path('app/exports')
     * @param  bool            $skipRasters  Drop worldpop_rasters from the dump
     *                                       (kept for backward compatibility with
     *                                        the original "Full / Without rasters"
     *                                        button pair; equivalent to passing
     *                                        $tables = TABLES minus RASTER_TABLES)
     * @param  ?string         $stagingId    Override the auto-generated staging id
     *                                       (used by ExportMapDataJob to align
     *                                        status-file ID with archive filename)
     * @param  ?\Closure       $onProgress   See runPgDump() docblock
     * @param  ?\Closure       $haltCheck    See runPgDump() docblock
     * @param  ?list<string>   $tables       Explicit table selection. When non-null
     *                                       takes precedence over $skipRasters.
     *                                       Values outside TABLES are silently
     *                                       dropped — callers can't dump arbitrary
     *                                       tables. Order is rewritten to match
     *                                       TABLES so the restore-side parent-
     *                                       before-child FK order is preserved.
     */
    public function export(
        ?string $tmpDir = null,
        bool $skipRasters = false,
        ?string $stagingId = null,
        ?\Closure $onProgress = null,
        ?\Closure $haltCheck = null,
        ?array $tables = null,
    ): string {
        $tmpDir ??= storage_path('app/exports');
        if (! is_dir($tmpDir) && ! @mkdir($tmpDir, 0775, true)) {
            throw new \RuntimeException("Could not create export directory: {$tmpDir}");
        }

        $stagingId ??= 'map-data-' . now()->format('Ymd-His');
        $stageDir   = "{$tmpDir}/{$stagingId}";
        if (! @mkdir($stageDir, 0775, true)) {
            throw new \RuntimeException("Could not create staging directory: {$stageDir}");
        }

        // Resolve effective table list. Explicit $tables wins; otherwise fall
        // back to the legacy skip_rasters boolean. Result is always a subset
        // of TABLES in TABLES order (parent → child) so the receiving
        // pg_restore can replay them without FK violations.
        $tables = $this->resolveTables($tables, $skipRasters);
        if (empty($tables)) {
            throw new \RuntimeException('No valid tables selected for export.');
        }

        // ── Manifest ───────────────────────────────────────────────────────
        $manifest = [
            'exported_at'    => now()->toIso8601String(),
            'schema_version' => $this->currentSchemaVersion(),
            'source_url_hint'=> config('app.url'),
            'skip_rasters'   => $skipRasters,
            'included_tables'=> $tables,
            'table_counts'   => $this->countRows($tables),
        ];
        file_put_contents("{$stageDir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

        // ── pg_dump (data-only) ─────────────────────────────────────────────
        $dumpPath = "{$stageDir}/data.dump";
        $this->runPgDump($dumpPath, $tables, $onProgress, $haltCheck);

        // ── tar.gz ─────────────────────────────────────────────────────────
        // Compressing the staging dir into the final archive. tar -c on a
        // dump file + manifest is usually fast (the dump is already
        // compressed inside pg_dump --format=custom), but emit one progress
        // update so the UI doesn't look stalled while tar runs.
        if ($onProgress) {
            $onProgress([
                'phase'                 => 'compressing',
                'bytes_written'         => is_file($dumpPath) ? (int) filesize($dumpPath) : 0,
                'estimated_total_bytes' => null,
                'throughput_bps'        => null,
                'eta_seconds'           => null,
            ]);
        }
        $archivePath = "{$tmpDir}/{$stagingId}.tar.gz";
        $proc = new Process([
            'tar', '-czf', $archivePath,
            '-C', $tmpDir,
            $stagingId,
        ]);
        $proc->setTimeout(0);
        $proc->mustRun();

        // Cleanup the staging dir; we keep only the archive.
        $this->rmTree($stageDir);

        Log::info("MapDataExportService: wrote {$archivePath} (".filesize($archivePath)." bytes)");
        return $archivePath;
    }

    /**
     * @return array<string, int>
     */
    private function countRows(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            try {
                $out[$t] = (int) DB::scalar("SELECT COUNT(*) FROM {$t}");
            } catch (\Throwable $e) {
                $out[$t] = -1;   // table missing on this instance
            }
        }
        return $out;
    }

    /**
     * Resolve the effective table selection for an export run.
     *
     * Precedence:
     *   1. If $explicit is non-null, intersect it with the schema-derived
     *      exportable allowlist ({@see deriveExportableTables()}) and
     *      preserve that list's order (parent → child, with the curated
     *      chain pinned at the front) so the restore-side replay stays FK-
     *      aware. Widened from the curated 20 to the full portable suite so
     *      the picker's checkboxes are all actually exportable.
     *   2. Otherwise (no explicit selection) fall back to the legacy
     *      skip_rasters flag over the CURATED default order — full TABLES,
     *      or TABLES minus RASTER_TABLES. The default export is unchanged:
     *      it never depends on live introspection.
     *
     * Intersecting against the derived allowlist (rather than accepting the
     * raw $explicit) still prevents callers from dumping arbitrary tables:
     * only tables the derive step vetted as portable are ever shipped.
     *
     * @param  ?list<string>  $explicit
     * @return list<string>
     */
    public function resolveTables(?array $explicit, bool $skipRasters): array
    {
        if ($explicit !== null) {
            $allow = $this->deriveExportableTables();

            return array_values(array_filter(
                $allow,
                fn ($t) => in_array($t, $explicit, true),
            ));
        }
        return $skipRasters
            ? array_values(array_diff(self::TABLES, self::RASTER_TABLES))
            : self::TABLES;
    }

    /**
     * Schema-derived, self-maintaining list of every portable BASE table in
     * the public schema, ordered parent → child, with the curated {@see
     * TABLES} chain pinned as the leading prefix. This is the source of
     * truth for the export CHOOSER and the allowlist that gates an
     * operator's explicit `tables[]` selection — as tables are added to the
     * schema they appear here automatically (minus the denylist), so the
     * picker never drifts from a hand-maintained constant again.
     *
     * Derivation:
     *   1. Enumerate `information_schema.tables` (public, BASE TABLE).
     *   2. Drop {@see DERIVE_DENYLIST} exact names and any name matching
     *      {@see DERIVE_DENY_PATTERNS} (framework/infra, PostGIS system,
     *      privacy/credential/key-material tables).
     *   3. Read FK edges from `pg_constraint` (child → parent, excluding
     *      self-references and edges to denied tables) and topologically
     *      sort so every table lands after all tables it FK-references
     *      *within the exported set*. The curated TABLES chain is emitted
     *      first (in its proven order) as the topo seed, guaranteeing the
     *      derived list is a strict superset of TABLES with the same
     *      relative order at the front.
     *
     * Cycle handling: the live schema has genuine FK cycles among the
     * institutional tables (e.g. bills ↔ laws, boards ↔ board_seats,
     * elections ↔ vacancies, terms ↔ appointments), and every FK is NOT
     * DEFERRABLE — so NO linear order can satisfy all of them. When the topo
     * pass stalls, {@see remainingCycleNodes()} isolates the tables that
     * actually sit on a cycle in the remaining subgraph, and the break fires
     * only on one of those (deterministically: fewest unmet parents, then
     * most children unblocked, then name). An acyclic table whose sole
     * blocker is a cyclic parent is never front-run — it is emitted normally
     * once the cycle is broken. The result is therefore a valid topological
     * order EXCEPT for the irreducible cycle arcs (and the curated pin, which
     * front-loads the curated chain). This is why this list drives the
     * CHOOSER and an opt-in "full" selection, while the DEFAULT export/restore
     * order stays the curated, cycle-free {@see TABLES}. pg_restore
     * --data-only performs its own dependency ordering for the load direction
     * regardless; only the import service's DELETE pass consumes this order,
     * and only for a full opt-in selection.
     *
     * Falls back to {@see TABLES} on any introspection failure so a broken
     * information_schema query can never leave the picker empty.
     *
     * @return list<string>
     */
    public function deriveExportableTables(): array
    {
        if ($this->derivedTablesCache !== null) {
            return $this->derivedTablesCache;
        }

        try {
            // 1. All public BASE tables, minus the denylist / key-material patterns.
            $all = DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_type', 'BASE TABLE')
                ->pluck('table_name')
                ->all();

            $nodes = [];
            foreach ($all as $t) {
                if ($this->isDeniedFromExport($t)) {
                    continue;
                }
                $nodes[$t] = true;
            }
            if ($nodes === []) {
                return $this->derivedTablesCache = self::TABLES;
            }

            // 2. FK edges (child → parent) restricted to the node set.
            $edges = DB::select(
                "SELECT DISTINCT c.conrelid::regclass::text AS child,
                        c.confrelid::regclass::text AS parent
                   FROM pg_constraint c
                   JOIN pg_namespace n ON n.oid = c.connamespace
                  WHERE c.contype = 'f'
                    AND n.nspname = 'public'
                    AND c.conrelid <> c.confrelid"
            );

            $parents  = [];
            $children = [];
            foreach ($nodes as $t => $_) {
                $parents[$t]  = [];
                $children[$t] = [];
            }
            foreach ($edges as $e) {
                // regclass may schema-qualify (public.foo) or quote reserved
                // names; normalise to a bare table name before matching.
                $child  = $this->bareTableName($e->child);
                $parent = $this->bareTableName($e->parent);
                if ($child === $parent) {
                    continue;
                }
                if (! isset($nodes[$child]) || ! isset($nodes[$parent])) {
                    continue;
                }
                $parents[$child][$parent] = true;
                $children[$parent][$child] = true;
            }

            // 3. Topological sort, curated TABLES pinned as the seed prefix.
            $emitted    = [];
            $emittedSet = [];
            foreach (self::TABLES as $t) {
                if (isset($nodes[$t]) && ! isset($emittedSet[$t])) {
                    $emitted[]        = $t;
                    $emittedSet[$t]   = true;
                }
            }
            $remaining = [];
            foreach ($nodes as $t => $_) {
                if (! isset($emittedSet[$t])) {
                    $remaining[$t] = true;
                }
            }

            $unmetParents = static function (string $t) use (&$parents, &$emittedSet): int {
                $n = 0;
                foreach (array_keys($parents[$t]) as $p) {
                    if (! isset($emittedSet[$p])) {
                        $n++;
                    }
                }
                return $n;
            };

            while ($remaining !== []) {
                // Kahn: emit every currently-ready table (all parents already
                // out), alphabetically, repeating until nothing is ready.
                do {
                    $ready = [];
                    foreach (array_keys($remaining) as $t) {
                        if ($unmetParents($t) === 0) {
                            $ready[] = $t;
                        }
                    }
                    sort($ready);
                    foreach ($ready as $t) {
                        $emitted[]      = $t;
                        $emittedSet[$t] = true;
                        unset($remaining[$t]);
                    }
                } while ($ready !== []);

                if ($remaining === []) {
                    break;
                }

                // Stall = FK cycle. Only ever break a node that actually SITS ON a
                // cycle within the remaining subgraph — never an acyclic dependent
                // whose sole blocker happens to be a cyclic parent (breaking such a
                // node would front-run it ahead of its parent for no reason; the
                // Kahn pass will emit it cleanly once the cycle is broken). Among
                // the on-cycle candidates, break deterministically: fewest unmet
                // parents, then unblocks the most children, then name.
                $cycleNodes = $this->remainingCycleNodes($remaining, $parents, $children, $emittedSet);
                $candidates = $cycleNodes !== [] ? $cycleNodes : array_keys($remaining);

                $best    = null;
                $bestKey = null;
                foreach ($candidates as $t) {
                    $up = $unmetParents($t);
                    $cc = 0;
                    foreach (array_keys($children[$t]) as $c) {
                        if (isset($remaining[$c])) {
                            $cc++;
                        }
                    }
                    $key = [$up, -$cc, $t];
                    if ($bestKey === null || $key < $bestKey) {
                        $bestKey = $key;
                        $best    = $t;
                    }
                }
                $emitted[]         = $best;
                $emittedSet[$best] = true;
                unset($remaining[$best]);
            }

            return $this->derivedTablesCache = array_values($emitted);
        } catch (\Throwable $e) {
            Log::warning('deriveExportableTables introspection failed; falling back to curated TABLES', [
                'error' => $e->getMessage(),
            ]);
            return $this->derivedTablesCache = self::TABLES;
        }
    }

    /**
     * Identify which still-unemitted tables actually sit on an FK cycle within
     * the remaining subgraph, so the topo cycle-break only ever fires on a
     * genuine cycle member (never on an acyclic table that is merely blocked by
     * a cyclic parent). Peels the DAG fringe: repeatedly drop any remaining node
     * with no remaining unmet parent (a source) or no remaining child (a sink)
     * — restricted to remaining nodes — until only cycle members survive.
     *
     * @param  array<string, true>                  $remaining
     * @param  array<string, array<string, true>>   $parents     child → {parent:true}
     * @param  array<string, array<string, true>>   $children    parent → {child:true}
     * @param  array<string, true>                  $emittedSet
     * @return list<string>  remaining tables that lie on a cycle
     */
    private function remainingCycleNodes(array $remaining, array $parents, array $children, array $emittedSet): array
    {
        // Work over the remaining-only subgraph.
        $inDeg  = [];   // # of remaining children pointing at me (I am their parent)
        $outDeg = [];   // # of remaining unmet parents I point at
        foreach (array_keys($remaining) as $t) {
            $out = 0;
            foreach (array_keys($parents[$t]) as $p) {
                if (isset($remaining[$p]) && ! isset($emittedSet[$p])) {
                    $out++;
                }
            }
            $in = 0;
            foreach (array_keys($children[$t] ?? []) as $c) {
                if (isset($remaining[$c])) {
                    $in++;
                }
            }
            $outDeg[$t] = $out;
            $inDeg[$t]  = $in;
        }

        $alive = $remaining;   // copy; we peel from this
        $queue = [];
        foreach (array_keys($alive) as $t) {
            if ($outDeg[$t] === 0 || $inDeg[$t] === 0) {
                $queue[] = $t;
            }
        }

        while ($queue !== []) {
            $t = array_pop($queue);
            if (! isset($alive[$t])) {
                continue;
            }
            unset($alive[$t]);

            // Removing a source (no unmet parents) relieves its remaining children's
            // out-degree; removing a sink (no children) relieves its parents' in-degree.
            foreach (array_keys($children[$t] ?? []) as $c) {
                if (isset($alive[$c]) && $outDeg[$c] > 0) {
                    $outDeg[$c]--;
                    if ($outDeg[$c] === 0 || $inDeg[$c] === 0) {
                        $queue[] = $c;
                    }
                }
            }
            foreach (array_keys($parents[$t]) as $p) {
                if (isset($alive[$p]) && $inDeg[$p] > 0) {
                    $inDeg[$p]--;
                    if ($inDeg[$p] === 0 || $outDeg[$p] === 0) {
                        $queue[] = $p;
                    }
                }
            }
        }

        return array_keys($alive);
    }

    /**
     * True when a table must never appear in the schema-derived export set:
     * it is on {@see DERIVE_DENYLIST} or its name matches a key-material
     * pattern in {@see DERIVE_DENY_PATTERNS}.
     */
    public function isDeniedFromExport(string $table): bool
    {
        if (in_array($table, self::DERIVE_DENYLIST, true)) {
            return true;
        }
        foreach (self::DERIVE_DENY_PATTERNS as $pattern) {
            if (preg_match($pattern, $table) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strip a schema qualifier and quoting from a `regclass::text` value so
     * `public.foo` and `"foo"` both normalise to `foo`.
     */
    private function bareTableName(string $regclass): string
    {
        $name = $regclass;
        if (($dot = strrpos($name, '.')) !== false) {
            $name = substr($name, $dot + 1);
        }
        return trim($name, '"');
    }

    private function currentSchemaVersion(): string
    {
        // Use the latest applied migration name as the schema fingerprint.
        $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
        return (string) ($latest ?? 'unknown');
    }

    /**
     * Run pg_dump in the background while:
     *   - polling the output file size every ~2s and firing $onProgress so
     *     the UI can show a live ETA + bytes-written counter
     *   - polling $haltCheck so the operator can cancel a long-running
     *     dump (worldpop_rasters at world scale takes 20-30 minutes)
     *
     * Uses Symfony\Process::start() instead of mustRun() so we can interleave
     * polling with the running subprocess. On any failure (non-zero exit
     * code, halt, or thrown exception), the partial dump file is unlinked.
     *
     * @param  list<string>  $tables    Tables to include (caller filters
     *                                  self::TABLES vs RASTER_TABLES based
     *                                  on the skip_rasters flag).
     * @param  ?\Closure     $onProgress See export() docblock.
     * @param  ?\Closure     $haltCheck  See export() docblock.
     */
    private function runPgDump(
        string $outPath,
        array $tables,
        ?\Closure $onProgress = null,
        ?\Closure $haltCheck = null,
    ): void {
        $cfg = config('database.connections.'.config('database.default'));
        $argv = ['pg_dump',
            '--host=' . $cfg['host'],
            '--port=' . $cfg['port'],
            '--username=' . $cfg['username'],
            '--dbname=' . $cfg['database'],
            '--format=custom',
            '--data-only',
            '--no-owner',
            '--no-privileges',
            '--file=' . $outPath,
        ];
        foreach ($tables as $t) {
            $argv[] = '--table=' . $t;
        }

        // Pre-estimate the compressed-dump size from postgres' raw table
        // sizes. pg_dump --format=custom applies its own gzip-equivalent
        // compression, so the on-disk output is meaningfully smaller than
        // the raw relation size. The 0.42 factor is a rough empirical
        // average for the worktree's data (~7GB raw rasters compress to
        // ~3GB in the dump). The progress UI clamps to [0, 100] so a slight
        // overshoot or undershoot near the end is invisible to the operator.
        $estimatedTotal = $this->estimateDumpSize($tables);

        $proc = new Process($argv);
        $proc->setTimeout(0);
        $proc->setEnv(['PGPASSWORD' => $cfg['password']]);
        $proc->start();

        $startedAt    = microtime(true);
        $lastEmitAt   = 0.0;
        $emitInterval = 2.0;   // seconds — matches the UI's polling cadence

        try {
            while ($proc->isRunning()) {
                // Halt check first — if the operator requested halt, stop
                // pg_dump promptly and surface a structured exception so
                // the calling job can record status=halted (vs failed).
                if ($haltCheck && $haltCheck()) {
                    $proc->stop(5, SIGTERM);  // 5s grace, then SIGKILL
                    @unlink($outPath);
                    throw new \App\Exceptions\ExportHaltedException(
                        'Export halted by operator request.'
                    );
                }

                $now = microtime(true);
                if ($onProgress && ($now - $lastEmitAt) >= $emitInterval) {
                    $lastEmitAt    = $now;
                    $bytesWritten  = is_file($outPath) ? (int) filesize($outPath) : 0;
                    $elapsed       = $now - $startedAt;
                    $throughput    = $elapsed > 0 ? $bytesWritten / $elapsed : 0.0;
                    // Dynamic recalibration: if the initial estimate undershoots
                    // (pg_table_size × compression factor was too optimistic),
                    // bump it so the progress bar doesn't stick at 100% while
                    // bytes keep rolling in. Assume there's still ~20% to go
                    // when we've already crossed the original estimate.
                    if ($estimatedTotal !== null && $bytesWritten > $estimatedTotal) {
                        $estimatedTotal = (int) round($bytesWritten * 1.20);
                    }
                    $eta = ($estimatedTotal !== null && $throughput > 0)
                        ? max(0, (int) round(($estimatedTotal - $bytesWritten) / max($throughput, 1)))
                        : null;
                    $onProgress([
                        'phase'                 => 'dumping',
                        'bytes_written'         => $bytesWritten,
                        'estimated_total_bytes' => $estimatedTotal,
                        'throughput_bps'        => (int) round($throughput),
                        'eta_seconds'           => $eta,
                        'elapsed_seconds'       => (int) round($elapsed),
                    ]);
                }

                usleep(500_000);  // 0.5s — keeps the halt check responsive
            }

            // Process completed — surface any error from pg_dump
            if ($proc->getExitCode() !== 0) {
                @unlink($outPath);
                throw new \RuntimeException(
                    "pg_dump failed (exit {$proc->getExitCode()}): " . $proc->getErrorOutput()
                );
            }

            // Final progress emit so the UI bar hits 100% before tar.gz step
            if ($onProgress) {
                $bytesWritten = is_file($outPath) ? (int) filesize($outPath) : 0;
                $elapsed      = microtime(true) - $startedAt;
                $onProgress([
                    'phase'                 => 'dumping',
                    'bytes_written'         => $bytesWritten,
                    'estimated_total_bytes' => $bytesWritten,   // collapse to actual now
                    'throughput_bps'        => $elapsed > 0 ? (int) round($bytesWritten / $elapsed) : 0,
                    'eta_seconds'           => 0,
                    'elapsed_seconds'       => (int) round($elapsed),
                ]);
            }
        } catch (\App\Exceptions\ExportHaltedException $e) {
            // Re-throw for the calling layer to handle without converting
            // to a generic RuntimeException.
            throw $e;
        } catch (\Throwable $e) {
            // Any other failure: ensure pg_dump is dead, then bubble up.
            if ($proc->isRunning()) $proc->stop(2, SIGKILL);
            @unlink($outPath);
            throw $e;
        }
    }

    /**
     * Estimate the on-disk size of a pg_dump --format=custom file for the
     * given tables. Used to drive the progress bar's percentage + ETA.
     *
     * Uses `pg_table_size` (main fork + TOAST + FSM/VM) rather than
     * `pg_relation_size` (main only). The worldpop_rasters table stores
     * its raster pixel data as bytea, which postgres puts in the TOAST
     * relation; without TOAST in the estimate, the bar underestimates by
     * ~50% on raster-heavy dumps. pg_table_size excludes indexes, which
     * matches pg_dump --data-only's behavior.
     *
     * Compression factor 0.85: pg_dump custom-format applies gzip-level
     * compression, but already-compressed bytea + integer-heavy raster
     * data don't shrink much. Empirically a full worldpop_rasters dump
     * lands around 70-90% of the on-disk table size. The dynamic
     * recalibration in runPgDump() corrects any remaining drift by
     * bumping the estimate if bytes_written exceeds it.
     *
     * @param  list<string>  $tables
     */
    private function estimateDumpSize(array $tables): ?int
    {
        try {
            $totalRaw = 0;
            foreach ($tables as $t) {
                $bytes = (int) DB::scalar("SELECT pg_table_size(?)", [$t]);
                $totalRaw += $bytes;
            }
            return $totalRaw > 0 ? (int) round($totalRaw * 0.85) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function rmTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->rmTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
