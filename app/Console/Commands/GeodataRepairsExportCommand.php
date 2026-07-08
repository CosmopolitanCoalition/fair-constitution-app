<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Geodata repair manifest — EXPORT.
 *
 * Dumps the applied (non-reverted) geodata_repairs ledger as a replayable
 * JSON manifest, oldest first. Rules are keyed by slug (not uuid), so the
 * manifest applies cleanly to another box that ran the same ETL seed —
 * geodata:repairs-apply replays it idempotently there.
 *
 * Usage:
 *   php artisan geodata:repairs-export
 *   php artisan geodata:repairs-export --file=/archive/repairs.json
 */
class GeodataRepairsExportCommand extends Command
{
    protected $signature = 'geodata:repairs-export
                            {--file= : Write the manifest here (default: storage/app/geodata/repairs-<timestamp>.json)}';

    protected $description = 'Export the applied geodata repairs as a replayable JSON manifest';

    public function handle(): int
    {
        $rows = DB::table('geodata_repairs')
            ->whereNull('deleted_at')
            ->whereNull('reverted_at')
            ->orderBy('applied_at')
            ->get();

        $manifest = [
            'version'      => 1,
            'generated_at' => now()->toIso8601String(),
            'count'        => $rows->count(),
            'repairs'      => $rows->map(fn ($r) => [
                'action'                  => $r->action,
                'target_slug'             => $r->target_slug,
                'target_geoboundaries_id' => $r->target_geoboundaries_id,
                'params'                  => json_decode($r->params, true) ?? [],
                'result'                  => $r->result !== null ? json_decode($r->result, true) : null,
                'applied_at'              => $r->applied_at,
            ])->values()->all(),
        ];

        $path = (string) ($this->option('file') ?: '');
        if ($path === '') {
            $path = storage_path('app/geodata') . '/repairs-' . now()->format('Ymd-His') . '.json';
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info(sprintf('Exported %d repair(s) → %s', $manifest['count'], $path));

        return self::SUCCESS;
    }
}
