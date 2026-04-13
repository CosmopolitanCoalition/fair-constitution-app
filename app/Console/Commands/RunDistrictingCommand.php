<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * districts:run — Trigger the Python districting engine (Phases 2–4)
 *
 * This command is a thin wrapper around the ETL Python script.
 * It finds pending legislatures (status = 'phase1_complete') and
 * launches district_skater.py to complete compositing, Webster rounding,
 * and giant splitting.
 *
 * Python runs in the fc_etl container; this command attempts to find
 * a Python 3 interpreter. If Python is not available in the current
 * container, it prints the equivalent ETL command to run manually.
 *
 * Usage:
 *   php artisan districts:run --all
 *   php artisan districts:run --legislature-id=<uuid>
 *   php artisan districts:run --all --dry-run
 */
class RunDistrictingCommand extends Command
{
    protected $signature = 'districts:run
                            {--all : Process all legislatures with pending Phase 1 districts}
                            {--legislature-id= : Process a single legislature by UUID}
                            {--dry-run : Show which legislatures would be processed without running Python}';

    protected $description = 'Phases 2–4: SKATER compositing, Webster rounding, and giant splitting';

    public function handle(): int
    {
        $all           = (bool)   $this->option('all');
        $legislatureId = (string) $this->option('legislature-id');
        $dryRun        = (bool)   $this->option('dry-run');

        if (! $all && $legislatureId === '') {
            $this->error('Provide --all or --legislature-id=<uuid>');
            return self::FAILURE;
        }

        // Build list of pending legislature IDs
        $pending = $this->getPendingLegislatures($all ? null : $legislatureId);

        if ($pending->isEmpty()) {
            $this->info('No pending legislatures found (all districts already processed).');
            return self::SUCCESS;
        }

        $this->info("Pending legislatures: {$pending->count()}");

        if ($dryRun) {
            foreach ($pending as $row) {
                $this->line("  {$row->id}  {$row->jurisdiction_name}  ({$row->district_count} districts)");
            }
            return self::SUCCESS;
        }

        // Locate the Python script
        $scriptPath = $this->resolveScriptPath();

        // Try to find Python 3 in the current environment
        $python = $this->findPython();

        if ($python === null) {
            $this->warn('Python 3 not found in this container.');
            $this->line('Run the districting engine from the ETL container:');
            $this->newLine();

            foreach ($pending as $row) {
                $this->line("  docker compose exec etl python3 district_skater.py --legislature-id {$row->id}");
            }
            $this->newLine();
            $this->line('  OR to process all at once:');
            $this->line('  docker compose exec etl python3 district_skater.py --all');
            return self::SUCCESS;
        }

        // Run Python for each pending legislature
        $failed = 0;
        foreach ($pending as $row) {
            $this->line("Processing: {$row->jurisdiction_name} ({$row->id})…");

            $exitCode = $this->runPython($python, $scriptPath, $row->id);

            if ($exitCode !== 0) {
                $this->error("  Failed (exit {$exitCode}) for legislature {$row->id}");
                $failed++;
            } else {
                $this->info("  Done.");
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} legislature(s) failed.");
            return self::FAILURE;
        }

        $this->info('All legislatures processed successfully.');
        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    private function getPendingLegislatures(?string $legislatureId)
    {
        $query = DB::table('legislature_districts AS ld')
            ->join('legislatures AS l', 'l.id', '=', 'ld.legislature_id')
            ->join('jurisdictions AS j', 'j.id', '=', 'l.jurisdiction_id')
            ->select(
                'l.id',
                'j.name AS jurisdiction_name',
                DB::raw('COUNT(ld.id) AS district_count'),
            )
            ->where('ld.status', 'phase1_complete')
            ->whereNull('ld.deleted_at')
            ->whereNull('l.deleted_at')
            ->groupBy('l.id', 'j.name');

        if ($legislatureId !== null) {
            $query->where('l.id', $legislatureId);
        }

        return $query->get();
    }

    // -------------------------------------------------------------------------
    // Python detection and execution
    // -------------------------------------------------------------------------

    private function findPython(): ?string
    {
        foreach (['python3', 'python'] as $cmd) {
            exec("which {$cmd} 2>/dev/null", $output, $code);
            if ($code === 0 && ! empty($output[0])) {
                return trim($output[0]);
            }
        }
        return null;
    }

    private function resolveScriptPath(): string
    {
        // When running from fc_app container: scripts live at /var/www/html/scripts/etl/
        // When running from fc_etl container: scripts live at /etl/
        $candidates = [
            '/etl/district_skater.py',
            base_path('scripts/etl/district_skater.py'),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Fall back to Laravel base path even if file doesn't exist yet
        return base_path('scripts/etl/district_skater.py');
    }

    private function runPython(string $python, string $scriptPath, string $legislatureId): int
    {
        $cmd = sprintf('%s %s --legislature-id %s 2>&1',
            escapeshellcmd($python),
            escapeshellarg($scriptPath),
            escapeshellarg($legislatureId),
        );

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($proc)) {
            $this->error('Failed to start Python process.');
            return 1;
        }

        fclose($pipes[0]);

        // Stream stdout/stderr to the console
        while (! feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false && trim($line) !== '') {
                $this->line('  ' . rtrim($line));
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc);
    }
}
