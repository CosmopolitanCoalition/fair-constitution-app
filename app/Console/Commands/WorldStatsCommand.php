<?php

namespace App\Console\Commands;

use App\Services\WorldStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `world:stats` — the CLI twin of the Atlas (UI↔CLI parity law).
 *
 * The Atlas reads the nightly `world_stats` rollup; so does this. Same numbers,
 * same rails, two surfaces — a terminal-only metric or a UI-only metric would
 * each be a parity break.
 *
 * ⚑ NO SYNTHETIC GUARD, DELIBERATELY. `GuardsSyntheticData` exists to stop
 * synthetic MINTING and refuses outright on a production instance. This command
 * mints nothing: the read is a public aggregate and `--refresh` recomputes the
 * same real figures the nightly job does. Gating it would deny ops the ability
 * to refresh the gauge on a live node — precisely backwards for a read surface.
 * (Corrects ATLAS_DESIGN §9, which had specified the guard; the rail it was
 * protecting is untouched, because nothing here is synthetic.)
 *
 * ⚑ A gauge, never a lever (CI-1): printing the world changes nothing about it.
 */
class WorldStatsCommand extends Command
{
    protected $signature = 'world:stats
        {--refresh : recompute the rollup now and persist it (what the nightly job does)}
        {--json : emit the raw payload instead of tables}';

    protected $description = 'Show the nightly world rollup that feeds the Atlas (--refresh recomputes it)';

    public function handle(WorldStatsService $stats): int
    {
        $hasTable = Schema::hasTable('world_stats');

        if ($this->option('refresh')) {
            if (! $hasTable) {
                $this->error('REFUSED — there is no `world_stats` table yet, so a rollup cannot be persisted.');
                $this->line('  The Atlas renders honest gaps until the table lands; run this again after the migration.');

                return self::FAILURE;
            }

            $this->info('Recomputing the world rollup…');
            $result = $stats->snapshot(onProgress: function (string $domain, int $seen) {
                $this->line("  {$domain}: {$seen} rows");
            });

            $this->info("Wrote world_stats for {$result['as_of_date']}.");

            return $this->render($result['as_of_date'], $result['domains'], persisted: true);
        }

        // The read path. Prefer the stored row — that is what the Atlas shows.
        if ($hasTable) {
            $row = DB::table('world_stats')->orderByDesc('as_of_date')->first(['as_of_date', 'domains']);

            if ($row !== null) {
                $domains = json_decode((string) $row->domains, true) ?: [];

                return $this->render((string) $row->as_of_date, $domains, persisted: true);
            }
        }

        // Nothing stored: compute live and SAY SO. An unmeasured world is not a
        // world of zeros, and a figure that was never persisted must not be
        // presented as though the Atlas is serving it.
        $this->warn($hasTable
            ? 'No rollup has been written yet — computing live for inspection only.'
            : 'There is no `world_stats` table yet — computing live for inspection only.');
        $this->line('  The Atlas is showing gaps for every figure below until a rollup is persisted.');
        $this->newLine();

        return $this->render(null, $stats->compute(), persisted: false);
    }

    /**
     * @param  array<string,mixed>  $domains
     */
    private function render(?string $date, array $domains, bool $persisted): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'as_of_date' => $date,
                'persisted' => $persisted,
                'domains' => $domains,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('THE WORLD — '.($date ?? 'not persisted').($persisted ? '' : ' (live, not stored)'));

        foreach ($domains as $name => $metrics) {
            if (! is_array($metrics) || $metrics === []) {
                $this->newLine();
                $this->line("  <comment>{$name}</comment> — not measured");

                continue;
            }

            $this->newLine();
            $this->line("  <comment>{$name}</comment>");

            $rows = [];

            foreach ($metrics as $key => $value) {
                $rows[] = [$key, $this->format($value)];
            }

            $this->table(['metric', 'value'], $rows);
        }

        // The rails, restated where an operator reads them.
        $this->newLine();
        $this->line('  <fg=gray>An em-dash is a GAP, never a zero: a figure withheld by k-anonymity or never</>');
        $this->line('  <fg=gray>measured is not the same claim as "none". A gauge, never a lever (CI-1).</>');

        return self::SUCCESS;
    }

    private function format(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $k => $v) {
                $parts[] = "{$k}=".($v === null ? '—' : $v);
            }

            return implode(' · ', $parts);
        }

        return is_int($value) ? number_format($value) : (string) $value;
    }
}
