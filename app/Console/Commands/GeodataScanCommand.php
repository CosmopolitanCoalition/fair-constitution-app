<?php

namespace App\Console\Commands;

use App\Models\GeodataFlag;
use App\Services\Geodata\GeodataFlagService;
use Illuminate\Console\Command;

/**
 * Run the geodata flag scan inline (CLI twin of POST /api/geodata/scan,
 * which dispatches the same work as a queued GeodataScanJob).
 *
 * Usage:
 *   php artisan geodata:scan                                    # all six detectors, whole tree
 *   php artisan geodata:scan --categories=same_space_chain      # one detector
 *   php artisan geodata:scan --iso=FRA --iso=MTQ                # restrict to iso trees
 */
class GeodataScanCommand extends Command
{
    protected $signature = 'geodata:scan
        {--categories=* : Detector categories to run (default: all six)}
        {--iso=*        : Restrict detectors to these ISO codes}';

    protected $description = 'Scan the jurisdictions tree for geodata defects and store the findings as geodata_flags rows.';

    public function handle(GeodataFlagService $service): int
    {
        $categories = array_values(array_filter((array) $this->option('categories')));
        $isoCodes   = array_values(array_filter((array) $this->option('iso')));

        $unknown = array_diff($categories, GeodataFlag::CATEGORIES);
        if ($unknown !== []) {
            $this->error('Unknown categories: ' . implode(', ', $unknown));
            $this->line('Valid: ' . implode(', ', GeodataFlag::CATEGORIES));
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scanning %s%s…',
            $categories === [] ? 'all categories' : implode(', ', $categories),
            $isoCodes === [] ? '' : ' (iso: ' . implode(', ', $isoCodes) . ')'
        ));

        $started = microtime(true);
        $counts  = $service->scan(
            $categories === [] ? null : $categories,
            $isoCodes === [] ? null : $isoCodes,
        );

        $this->table(
            ['Category', 'Open flags'],
            array_map(fn ($cat, $n) => [$cat, $n], array_keys($counts), $counts)
        );
        $this->info(sprintf(
            'Scan complete: %d open flag(s) in %.1fs.',
            array_sum($counts),
            microtime(true) - $started
        ));

        return self::SUCCESS;
    }
}
