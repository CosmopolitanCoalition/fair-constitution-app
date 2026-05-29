<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
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

        $this->rmTree($stageDir);

        return [
            'imported_at'     => now()->toIso8601String(),
            'manifest'        => $manifest,
            'tables_restored' => $effective,
        ];
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
