<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Refresh jurisdiction_resident_counts: the figure pages show instead of counting the
 * residency table on every view. THE ETL RULE: bounded chunks, each committed, with
 * progress, and resumable. A chunk is a slice of jurisdictions by primary key. The chunk size
 * derives from the host (a Pi takes smaller slices) and can be set with --chunk.
 *
 *   php artisan residents:recount              # every jurisdiction that has residents
 *   php artisan residents:recount --chunk=500  # smaller slices
 */
class ResidentsRecountCommand extends Command
{
    protected $signature = 'residents:recount {--chunk= : Jurisdictions per slice (default derived from host memory)}';

    protected $description = 'Refresh the per-place active resident counts that pages show.';

    public function handle(): int
    {
        $chunk = (int) ($this->option('chunk') ?: self::defaultChunk());
        $after = '00000000-0000-0000-0000-000000000000';
        $total = 0;
        $places = 0;
        $withResidents = 0;
        $runStart = now();
        $started = microtime(true);

        while (true) {
            // The next slice of jurisdictions: an index-only walk of the primary key. No other
            // column is read, so no heap page is fetched (a deleted_at filter here cost about
            // 2,000 random heap reads per slice, 30 seconds on a spinning disk). A deleted place
            // simply counts zero residents.
            $ids = DB::table('jurisdictions')
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $rows = DB::table('residency_confirmations')
                ->whereIn('jurisdiction_id', $ids)
                ->where('is_active', true)
                ->groupBy('jurisdiction_id')
                ->select('jurisdiction_id', DB::raw('count(*) as n'))
                ->get();

            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    DB::table('jurisdiction_resident_counts')->upsert(
                        [['jurisdiction_id' => $row->jurisdiction_id, 'active_residents' => (int) $row->n, 'counted_at' => now()]],
                        ['jurisdiction_id'],
                        ['active_residents', 'counted_at'],
                    );
                }
            });

            $after = (string) end($ids);
            $places += count($ids);
            $withResidents += $rows->count();
            $total += (int) $rows->sum('n');
            $this->line(sprintf('%d places walked, %d with residents, %d residents counted, %ds elapsed', $places, $withResidents, $total, (int) (microtime(true) - $started)));
        }

        // A place that no longer has residents keeps no stale figure: anything not
        // written during this run is removed.
        DB::table('jurisdiction_resident_counts')->where('counted_at', '<', $runStart)->delete();

        $this->info(sprintf('Done: %d places walked, %d with residents, %d active residents.', $places, $withResidents, $total));

        return self::SUCCESS;
    }

    /** Slice size from the host: 250 on a small host, up to 4000 on a large one. */
    public static function defaultChunk(): int
    {
        $mb = 0;
        if (is_readable('/proc/meminfo') && preg_match('/^MemTotal:\s+(\d+) kB/m', (string) file_get_contents('/proc/meminfo'), $m)) {
            $mb = (int) ($m[1] / 1024);
        }

        return max(250, min(4000, (int) ($mb / 4)));
    }
}
