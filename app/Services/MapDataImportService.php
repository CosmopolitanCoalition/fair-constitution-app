<?php

namespace App\Services;

use App\Jobs\PrewarmGeojsonCachesJob;
use App\Jobs\PrewarmRasterTilesJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Phase P.9 — Restore an instance's map-data state from a tarball produced
 * by `MapDataExportService`. Lets the operator skip the ETL on a new
 * instance.
 *
 * Sequence:
 *   1. Extract the tarball to a temp staging dir.
 *   2. Validate manifest.json — schema_version compatibility check.
 *   3. Truncate target tables (atomic via a single transaction).
 *   4. pg_restore --data-only to re-load.
 *   5. Stamp instance_settings.map_accepted_at to mark "imported, ready
 *      for apportionment" (the receiving operator can re-affirm via the
 *      Jurisdiction Viewer's accept button).
 */
class MapDataImportService
{
    /**
     * @param  UploadedFile     $upload  The .tar.gz produced by the export service.
     * @param  ?list<string>    $tables  Optional subset of tables to restore. Null
     *                                   means "everything in the bundle"; otherwise
     *                                   the effective set is the intersection of
     *                                   $tables, MapDataExportService::TABLES, and
     *                                   the manifest's included_tables.
     *
     *                                   The intersection rule prevents partial-
     *                                   bundle errors: if the operator picks
     *                                   worldpop_rasters but their bundle was
     *                                   exported with skip_rasters, that one is
     *                                   silently dropped instead of crashing
     *                                   pg_restore on a missing table.
     *
     *                                   Truncation only touches the selected
     *                                   tables — non-selected tables on the
     *                                   receiving instance are left intact. This
     *                                   makes partial sync workflows safe (e.g.
     *                                   restore just instance_settings +
     *                                   cosmic_addresses from a colleague's
     *                                   working setup without overwriting your
     *                                   own already-loaded jurisdictions).
     */
    public function importFromUpload(UploadedFile $upload, ?array $tables = null): array
    {
        $tmpRoot = storage_path('app/imports');
        if (! is_dir($tmpRoot) && ! @mkdir($tmpRoot, 0775, true)) {
            throw new \RuntimeException("Could not create import directory: {$tmpRoot}");
        }

        $stagingId = 'restore-'.uniqid('', true);
        $stageDir  = "{$tmpRoot}/{$stagingId}";
        if (! @mkdir($stageDir, 0775, true)) {
            throw new \RuntimeException("Could not create staging directory: {$stageDir}");
        }

        // Move the upload into staging.
        $tarPath = "{$stageDir}/upload.tar.gz";
        $upload->move($stageDir, 'upload.tar.gz');

        // Extract.
        $proc = new Process(['tar', '-xzf', $tarPath, '-C', $stageDir]);
        $proc->setTimeout(0);
        $proc->mustRun();

        // Find the inner directory created by the export service.
        $inner = null;
        foreach (scandir($stageDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'upload.tar.gz') continue;
            $candidate = $stageDir.'/'.$entry;
            if (is_dir($candidate)) { $inner = $candidate; break; }
        }
        if ($inner === null) {
            $this->rmTree($stageDir);
            throw new \RuntimeException('Tarball did not contain the expected staging folder.');
        }

        $manifestPath = "{$inner}/manifest.json";
        if (! is_file($manifestPath)) {
            $this->rmTree($stageDir);
            throw new \RuntimeException('manifest.json missing from tarball.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            $this->rmTree($stageDir);
            throw new \RuntimeException('manifest.json is not valid JSON.');
        }

        // ── Compatibility check: refuse imports older than the local
        // schema's most recent migration. Forward-compat (manifest from a
        // newer schema) is allowed — the data tables haven't changed shape
        // between recent migrations, so newer-into-older usually works.
        $localLatest    = DB::table('migrations')->orderByDesc('id')->value('migration');
        $manifestSchema = (string) ($manifest['schema_version'] ?? '');
        if ($manifestSchema && $localLatest && strcmp($manifestSchema, $localLatest) > 0) {
            Log::info("MapDataImport: importing from a newer schema ({$manifestSchema}) than local ({$localLatest}) — proceeding.");
        }

        $dumpPath = "{$inner}/data.dump";
        if (! is_file($dumpPath)) {
            $this->rmTree($stageDir);
            throw new \RuntimeException('data.dump missing from tarball.');
        }

        // ── Resolve effective table set ────────────────────────────────────
        // Intersect (a) the curated export bundle list, (b) what's actually
        // in this archive's manifest, and (c) the operator's selection if
        // they passed one. The result is preserved in TABLES order so the
        // FK-aware truncate-children-first / restore-parents-first replay
        // still works on the partial set.
        $included = is_array($manifest['included_tables'] ?? null)
            ? $manifest['included_tables']
            : MapDataExportService::TABLES;
        $effective = array_values(array_filter(MapDataExportService::TABLES, function ($t) use ($tables, $included) {
            if (! in_array($t, $included, true)) return false;
            if ($tables !== null && ! in_array($t, $tables, true)) return false;
            return true;
        }));
        if (empty($effective)) {
            $this->rmTree($stageDir);
            throw new \RuntimeException('No tables selected for restore (selection has no overlap with bundle).');
        }

        // ── Truncate the selected tables (FK-aware order, all in one tx). ─
        DB::transaction(function () use ($effective) {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            foreach (array_reverse($effective) as $t) {
                DB::statement("TRUNCATE TABLE {$t} CASCADE");
            }
        });

        // ── pg_restore --data-only ─────────────────────────────────────────
        // --table=X flags filter the restore to just the selected tables,
        // matching the truncation set above. Without these, pg_restore tries
        // to load every table in the dump, which would replay rows into
        // tables we intentionally didn't truncate — clobbering non-selected
        // local state.
        $cfg = config('database.connections.'.config('database.default'));
        $argv = [
            'pg_restore',
            '--host='     . $cfg['host'],
            '--port='     . $cfg['port'],
            '--username=' . $cfg['username'],
            '--dbname='   . $cfg['database'],
            '--data-only',
            '--no-owner',
            '--no-privileges',
        ];
        foreach ($effective as $t) {
            $argv[] = '--table=' . $t;
        }
        $argv[] = $dumpPath;
        $proc = new Process($argv);
        $proc->setTimeout(0);
        $proc->setEnv(['PGPASSWORD' => $cfg['password']]);
        $proc->mustRun();

        // Stamp acceptance — the receiving operator can re-affirm via the
        // viewer if they want, but the import is itself an acceptance signal.
        DB::table('instance_settings')->update([
            'map_accepted_at' => now(),
        ]);

        // A restore replaces jurisdictions / districts / settings wholesale, so
        // every derived cache (boundary GeoJSON, revealed GeoJSON, mass-op flags)
        // is now stale. Flush the whole cache store — cheaper and safer than
        // tracking exactly which keys the donor's data invalidates — then
        // re-dispatch the background prewarm so the new state is hot before the
        // operator opens the mapper.
        //
        // Raster TILE files on disk are keyed by z/x/y (not jurisdiction): a
        // same-data round-trip leaves them valid and the prewarm just fills
        // gaps. A restore bringing *different* WorldPop data should be followed
        // by a manual tile-cache clear — rare, so not automated here.
        Cache::flush();
        try {
            PrewarmRasterTilesJob::dispatch();   // z0-12 land-only (defaults)
            PrewarmGeojsonCachesJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('post-restore prewarm dispatch failed', ['error' => $e->getMessage()]);
        }

        $this->rmTree($stageDir);

        return [
            'imported_at'     => now()->toIso8601String(),
            'manifest'        => $manifest,
            'tables_restored' => $effective,
        ];
    }

    /**
     * Roles-campaign Phase 0b — import a geodata SEED tarball (the donor's foundation subset,
     * produced by MapDataExportService) from a FILE PATH, identity-safely.
     *
     * Unlike importFromUpload, this NEVER `TRUNCATE … CASCADE`s `cosmic_addresses` — that would
     * cascade onto `instance_settings` and wipe THIS box's server_id + keypair (its identity).
     * Instead (D1): detach `instance_settings.cosmic_address_id`, hard-DELETE `cosmic_addresses`
     * (now unreferenced — `instance_settings` is its only external referent), load the donor's
     * foundation, then RE-POINT `instance_settings` to the synced cosmic node sharing this box's
     * prior `slug`. The box mirrors the host's cosmos while keeping its own identity.
     *
     * @return array{imported_at:string,tables_restored:list<string>}
     */
    public function importSeedFromFile(string $tarPath): array
    {
        if (! is_file($tarPath)) {
            throw new \RuntimeException("Seed tarball not found at {$tarPath}.");
        }

        $tmpRoot = storage_path('app/imports');
        if (! is_dir($tmpRoot) && ! @mkdir($tmpRoot, 0775, true)) {
            throw new \RuntimeException("Could not create import directory: {$tmpRoot}");
        }
        $stageDir = "{$tmpRoot}/seed-".uniqid('', true);
        if (! @mkdir($stageDir, 0775, true)) {
            throw new \RuntimeException("Could not create staging directory: {$stageDir}");
        }

        try {
            $proc = new Process(['tar', '-xzf', $tarPath, '-C', $stageDir]);
            $proc->setTimeout(0);
            $proc->mustRun();

            $inner = null;
            foreach (scandir($stageDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir("{$stageDir}/{$entry}")) {
                    $inner = "{$stageDir}/{$entry}";
                    break;
                }
            }
            if ($inner === null || ! is_file("{$inner}/manifest.json") || ! is_file("{$inner}/data.dump")) {
                throw new \RuntimeException('Seed tarball did not contain the expected manifest.json + data.dump.');
            }
            $manifest = json_decode((string) file_get_contents("{$inner}/manifest.json"), true);
            if (! is_array($manifest)) {
                throw new \RuntimeException('Seed manifest.json is not valid JSON.');
            }

            // Surface a schema-version delta BEFORE the destructive clear (parity with importFromUpload).
            // A foundation table that changed shape across versions would fault pg_restore AFTER the
            // truncate commits; this note gives an operator the signal. Recovery: seeded_at is only
            // stamped on success, so a re-join re-pulls + re-imports once the schema is brought forward.
            $localLatest = DB::table('migrations')->orderByDesc('id')->value('migration');
            $manifestSchema = (string) ($manifest['schema_version'] ?? '');
            if ($manifestSchema !== '' && $localLatest && strcmp($manifestSchema, (string) $localLatest) > 0) {
                Log::info("importSeedFromFile: seed schema ({$manifestSchema}) is newer than local ({$localLatest}) — proceeding.");
            }

            // Effective set = the curated foundation tables actually present in this seed, in
            // TABLES (parent→child) order. instance_settings can never appear (it is not in TABLES'
            // exportable-by-seed set here — the donor excludes it).
            $included = is_array($manifest['included_tables'] ?? null) ? $manifest['included_tables'] : MapDataExportService::TABLES;
            $effective = array_values(array_filter(
                MapDataExportService::TABLES,
                fn ($t) => in_array($t, $included, true) && $t !== 'instance_settings',
            ));
            if ($effective === []) {
                throw new \RuntimeException('Seed bundle carries no recognised foundation tables.');
            }

            // Remember this box's CURRENT cosmic placement so we can re-point to the matching
            // synced node by its stable slug after the import (keeps our place in the cosmos).
            $priorCosmicId = DB::table('instance_settings')->value('cosmic_address_id');
            $prior = $priorCosmicId !== null
                ? DB::table('cosmic_addresses')->where('id', $priorCosmicId)->first(['slug', 'type'])
                : null;

            $hasCosmic = in_array('cosmic_addresses', $effective, true);
            $nonCosmic = array_values(array_filter($effective, fn ($t) => $t !== 'cosmic_addresses'));

            // ── Identity-safe clear (D1). Detach instance_settings from cosmic, TRUNCATE the
            // non-cosmic foundation CASCADE, then HARD-DELETE cosmic (now unreferenced). We never
            // TRUNCATE cosmic_addresses CASCADE — that constraint-based cascade would take the
            // instance_settings identity row with it regardless of the detach.
            DB::transaction(function () use ($nonCosmic, $hasCosmic) {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
                if ($hasCosmic) {
                    DB::table('instance_settings')->update(['cosmic_address_id' => null]);
                }
                foreach (array_reverse($nonCosmic) as $t) {
                    DB::statement("TRUNCATE TABLE {$t} CASCADE");
                }
                if ($hasCosmic) {
                    DB::statement('DELETE FROM cosmic_addresses');
                }
            });

            // ── pg_restore --data-only the foundation tables (filtered to the effective set).
            $cfg = config('database.connections.'.config('database.default'));
            $argv = ['pg_restore', '--host='.$cfg['host'], '--port='.$cfg['port'], '--username='.$cfg['username'],
                '--dbname='.$cfg['database'], '--data-only', '--no-owner', '--no-privileges'];
            foreach ($effective as $t) {
                $argv[] = '--table='.$t;
            }
            $argv[] = "{$inner}/data.dump";
            $restore = new Process($argv);
            $restore->setTimeout(0);
            $restore->setEnv(['PGPASSWORD' => $cfg['password']]);
            try {
                $restore->mustRun();
            } catch (\Throwable $e) {
                // The destructive clear already committed; the foundation is left empty but IDENTITY-SAFE
                // (instance_settings/keypair untouched). seedFromHost never stamps seeded_at after a throw,
                // so re-running the join re-pulls + re-imports. Log loudly so an operator sees the mid-seed box.
                Log::error('seed pg_restore failed — foundation left cleared (identity preserved); re-run the join to retry.', [
                    'prior_cosmic_slug' => $prior?->slug,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            // ── Re-point instance_settings to the synced cosmic node sharing our prior slug
            // (fail-closed: a foundation with no resolvable node must not leave a dangling FK).
            if ($hasCosmic) {
                $node = null;
                if ($prior?->slug) {
                    $node = DB::table('cosmic_addresses')->where('slug', $prior->slug)->whereNull('deleted_at')->first(['id']);
                }
                if ($node === null && $prior?->type) {
                    $node = DB::table('cosmic_addresses')->where('type', $prior->type)->whereNull('deleted_at')->orderBy('sort_order')->first(['id']);
                }
                if ($node === null) {
                    throw new \RuntimeException('Seed import: could not resolve a synced cosmic node to re-point instance_settings to.');
                }
                DB::table('instance_settings')->update(['cosmic_address_id' => $node->id]);
            }

            DB::table('instance_settings')->update(['map_accepted_at' => now()]);

            Cache::flush();
            try {
                PrewarmRasterTilesJob::dispatch();
                PrewarmGeojsonCachesJob::dispatch();
            } catch (\Throwable $e) {
                Log::warning('post-seed-import prewarm dispatch failed', ['error' => $e->getMessage()]);
            }

            return ['imported_at' => now()->toIso8601String(), 'tables_restored' => $effective];
        } finally {
            $this->rmTree($stageDir);
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
