<?php

namespace App\Console\Commands;

use App\Support\HostCapacity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * W-0443 — fill drawn_seats and seat_gap on every district map that has none.
 *
 * ETL paradigm: bounded chunks (size derived from host memory through
 * HostCapacity::enumerationChunk, env CGA_ENUM_CHUNK overrides), one commit
 * per chunk, resumable (NULL means not yet computed, so a kill costs one
 * chunk and a rerun continues), visible (done, remaining, elapsed, ETA per
 * chunk). Never one planet-wide statement: each chunk names its map ids.
 *
 * After this pass the database triggers keep the columns current; this
 * command is the one-time fill for maps drawn before the columns existed.
 */
class MapsDrawnSeatsBackfillCommand extends Command
{
    protected $signature = 'maps:drawn-seats-backfill {--chunk= : override the chunk size}';

    protected $description = 'Fill legislature_district_maps.drawn_seats and seat_gap in bounded, resumable chunks (W-0443)';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('PostgreSQL only.');

            return self::FAILURE;
        }

        $chunk = (int) ($this->option('chunk') ?: HostCapacity::enumerationChunk());
        $remaining = (int) DB::table('legislature_district_maps')->whereNull('drawn_seats')->count();
        $total = $remaining;
        $this->info(sprintf('maps without drawn_seats: %s; chunk %s', number_format($total), number_format($chunk)));

        $t0 = microtime(true);
        $done = 0;

        while ($remaining > 0) {
            $ids = DB::table('legislature_district_maps')
                ->whereNull('drawn_seats')
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(function () use ($ids) {
                DB::statement('SELECT public.cga_map_drawn_seats_recompute(?::uuid[])', ['{' . implode(',', $ids) . '}']);
            });

            $done += count($ids);
            $remaining = max(0, $remaining - count($ids));
            $elapsed = microtime(true) - $t0;
            $rate = $done / max($elapsed, 0.001);
            $eta = $rate > 0 ? $remaining / $rate : 0;
            $this->line(sprintf(
                'chunk done: %s of %s (%.1f%%) · elapsed %ds · eta %ds · %.0f maps/s',
                number_format($done), number_format($total), $total > 0 ? 100 * $done / $total : 100, (int) $elapsed, (int) $eta, $rate
            ));
        }

        $left = (int) DB::table('legislature_district_maps')->whereNull('drawn_seats')->count();
        $this->info(sprintf('done: %s maps filled in %ds; %s still NULL', number_format($done), (int) (microtime(true) - $t0), number_format($left)));

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }
}
