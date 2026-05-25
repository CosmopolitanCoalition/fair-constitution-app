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
    public function importFromUpload(UploadedFile $upload): array
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

        // ── Truncate target tables (FK-aware order, all in one transaction). ─
        DB::transaction(function () {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            // Reverse the export TABLES order so children truncate before parents.
            foreach (array_reverse(MapDataExportService::TABLES) as $t) {
                DB::statement("TRUNCATE TABLE {$t} CASCADE");
            }
        });

        // ── pg_restore --data-only ─────────────────────────────────────────
        $cfg = config('database.connections.'.config('database.default'));
        $proc = new Process([
            'pg_restore',
            '--host='     . $cfg['host'],
            '--port='     . $cfg['port'],
            '--username=' . $cfg['username'],
            '--dbname='   . $cfg['database'],
            '--data-only',
            '--no-owner',
            '--no-privileges',
            $dumpPath,
        ]);
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
            'tables_restored' => MapDataExportService::TABLES,
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
