<?php

namespace App\Console\Commands;

use App\Services\Legislature\TypeBDistrictMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * THE TYPE B DISTRICT MAPPER — mass pass (Wave 3, lane 1).
 *
 * Reads the worklist (legislatures.type_b_needs_districting, served by the
 * partial index legislatures_type_b_districting_idx) and groups each flagged
 * chamber's constituents into shared panels, clearing the flag so its at-large
 * Type B race can schedule. The UI↔CLI parity twin of the mapper surface.
 *
 * ETL RULE: the worklist is walked in bounded, per-chamber-committed chunks
 * (each apply() is its own transaction) with per-chunk progress; never one
 * planet-wide statement. A VACUUM ANALYZE closes the churn before dependent
 * passes.
 *
 *   php artisan type-b:district --dry-run                 # census, no writes
 *   php artisan type-b:district --jurisdiction=niue       # one chamber
 *   php artisan type-b:district --limit=100               # first 100 flagged
 *   php artisan type-b:district --status=draft            # B7: next-term plan
 */
class TypeBDistrictCommand extends Command
{
    protected $signature = 'type-b:district
                            {--dry-run : Compute groupings without writing}
                            {--jurisdiction= : One chamber by jurisdiction slug or UUID}
                            {--status=active : Grouping status to write (active|draft)}
                            {--limit= : Max chambers to process}
                            {--chunk=500 : Worklist chunk size (ETL rule)}';

    protected $description = 'Group flagged Type B chambers into shared panels and clear the districting flag';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $status = $this->option('status');
        if (! in_array($status, ['active', 'draft'], true)) {
            $this->error("Invalid --status={$status}; use active or draft.");
            return self::FAILURE;
        }
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $base = DB::table('legislatures')
            ->where('type_b_needs_districting', true)
            ->whereNull('deleted_at');

        if ($j = $this->option('jurisdiction')) {
            $jid = DB::table('jurisdictions')
                ->where(function ($q) use ($j) {
                    $q->where('slug', $j);
                    if (\Illuminate\Support\Str::isUuid($j)) {
                        $q->orWhere('id', $j);
                    }
                })
                ->value('id');
            if (! $jid) {
                $this->error("No jurisdiction matched '{$j}'.");
                return self::FAILURE;
            }
            $base->where('jurisdiction_id', $jid);
        }

        $total = (clone $base)->count();
        if ($total === 0) {
            $this->info('No chambers are flagged type_b_needs_districting — nothing to group.');
            return self::SUCCESS;
        }
        $this->info(($dryRun ? '[dry-run] ' : '') . "Type B districting worklist: {$total} flagged chamber(s).");

        $mapper = new TypeBDistrictMapper();
        $processed = 0;
        $grouped = 0;
        $undercounts = 0;
        $seatsTotal = 0;
        $failures = 0;
        $verbose = $total <= 40 || $this->option('jurisdiction');

        // ETL rule: walk the worklist in bounded chunks, committed per chamber.
        $base->orderBy('id')->chunkById($chunk, function ($rows) use (
            &$processed, &$grouped, &$undercounts, &$seatsTotal, &$failures,
            $mapper, $dryRun, $status, $limit, $verbose
        ) {
            foreach ($rows as $leg) {
                if ($limit !== null && $processed >= $limit) {
                    return false; // stop chunking
                }
                $processed++;
                try {
                    $r = $mapper->apply((string) $leg->id, $status, $dryRun);
                } catch (\Throwable $e) {
                    $failures++;
                    $this->warn("  ! {$leg->id}: {$e->getMessage()}");
                    continue;
                }
                if ($r === null) {
                    continue; // not groupable (leaf / vanished)
                }
                $grouped++;
                $seatsTotal += $r['seats'];
                if ($r['undercount']) {
                    $undercounts++;
                }
                if ($verbose) {
                    $this->line(sprintf(
                        '  %s %s → %d panels, %d seats%s',
                        $dryRun ? '[dry]' : 'grouped',
                        $leg->jurisdiction_id,
                        $r['panel_count'],
                        $r['seats'],
                        $r['undercount'] ? ' (undercount)' : '',
                    ));
                }
            }

            $this->line("  … {$processed} processed, {$grouped} grouped");

            return $limit === null || $processed < $limit;
        }, 'id');

        // VACUUM the churn before dependent passes (ETL rule) — never in a tx.
        if (! $dryRun && $grouped > 0 && DB::transactionLevel() === 0) {
            DB::statement('VACUUM ANALYZE legislatures');
        }

        $this->newLine();
        $this->info(sprintf(
            '%sDone: %d processed, %d grouped (%d seats), %d undercount, %d failures.',
            $dryRun ? '[dry-run] ' : '',
            $processed, $grouped, $seatsTotal, $undercounts, $failures,
        ));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
