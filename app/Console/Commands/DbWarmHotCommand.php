<?php

namespace App\Console\Commands;

use App\Support\RunsInFlight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Load the hot indexes into the operating system's file cache with one sequential read each
 * (pg_prewarm, mode 'read'). On a spinning disk a cold lookup is a random seek, about 100 a
 * second; a sequential read runs a hundred times faster per byte. After a boot the first page
 * load per table otherwise pays those seeks one by one.
 *
 * The set is the indexes behind the pages every visit touches (jurisdictions, legislatures,
 * districts, judiciaries, executives, elections, organizations, board seats) plus the user
 * lookup keys. It is taken smallest first and stops at a budget derived from host memory
 * (a quarter of RAM, at most 2 GB), so a small host never evicts what it needs.
 *
 *   php artisan db:warm-hot                    # derived budget
 *   php artisan db:warm-hot --budget-mb=1024
 *   php artisan db:warm-hot --unless-busy      # do nothing while an engine run is active
 */
class DbWarmHotCommand extends Command
{
    protected $signature = 'db:warm-hot {--budget-mb= : Most index megabytes to load (default: a quarter of host RAM, at most 2048)} {--unless-busy : Do nothing while any engine run is active}';

    protected $description = 'Load the hot indexes into the file cache with sequential reads.';

    /** Tables whose indexes serve nearly every page. */
    private const TABLES = [
        'jurisdictions', 'legislatures', 'legislature_districts', 'legislature_district_maps',
        'legislature_district_jurisdictions', 'judiciaries', 'executives', 'elections',
        'organizations', 'board_seats',
    ];

    /** User lookup keys: the id join and the login lookups. */
    private const USER_INDEXES = ['users_pkey', 'users_email_unique', 'users_lower_email_idx'];

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->info('Not a PostgreSQL connection: nothing to warm.');

            return self::SUCCESS;
        }

        if ($this->option('unless-busy') && ($busy = RunsInFlight::any()) !== null) {
            $this->info("Warm skipped: a {$busy} run is active.");

            return self::SUCCESS;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_prewarm');
        } catch (Throwable $e) {
            $this->warn('pg_prewarm is not available: '.$e->getMessage());

            return self::SUCCESS;
        }

        $budget = (int) ($this->option('budget-mb') ?: self::defaultBudgetMb()) * 1048576;
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));

        $candidates = DB::select(
            "SELECT i.indexrelid::regclass::text AS name, pg_relation_size(i.indexrelid) AS bytes
               FROM pg_index i
              WHERE i.indisvalid
                AND (i.indrelid::regclass::text IN ($placeholders)
                     OR i.indexrelid::regclass::text IN ('".implode("','", self::USER_INDEXES)."'))
              ORDER BY pg_relation_size(i.indexrelid) ASC",
            self::TABLES
        );

        $loaded = 0;
        $count = 0;
        $started = microtime(true);

        foreach ($candidates as $index) {
            if ($loaded + (int) $index->bytes > $budget) {
                break; // smallest first: every later index is larger, so none fits
            }

            DB::select('SELECT pg_prewarm(?::regclass, ?)', [$index->name, 'read']);
            $loaded += (int) $index->bytes;
            $count++;
            $this->line(sprintf('%s (%d MB) — %d MB of %d MB, %ds', $index->name, $index->bytes / 1048576, $loaded / 1048576, $budget / 1048576, (int) (microtime(true) - $started)));
        }

        $this->info(sprintf('Warmed %d indexes, %d MB, in %ds.', $count, $loaded / 1048576, (int) (microtime(true) - $started)));

        return self::SUCCESS;
    }

    /** A quarter of host RAM, at most 2 GB, at least 256 MB. */
    public static function defaultBudgetMb(): int
    {
        $mb = 0;
        if (is_readable('/proc/meminfo') && preg_match('/^MemTotal:\s+(\d+) kB/m', (string) file_get_contents('/proc/meminfo'), $m)) {
            $mb = (int) ($m[1] / 1024);
        }

        return max(256, min(2048, (int) ($mb / 4)));
    }
}
