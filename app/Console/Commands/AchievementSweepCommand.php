<?php

namespace App\Console\Commands;

use App\Domain\Achievements\AchievementCatalog as Catalog;
use App\Services\Achievements\AchievementStateSweep;
use App\Support\HostCapacity;
use Illuminate\Console\Command;

/**
 * AC-1 — award EARNER_STATE achievements from the fact tables.
 *
 * Idempotent, resumable, host-derived (the ETL paradigm). Reads the seating /
 * membership / confirmation rows that name a holder and awards the matching
 * ACH-* row to that holder. Safe to run repeatedly and on a schedule: every
 * award is idempotent (AchievementService::awardState), each chunk commits its
 * keyset cursor, and a killed run resumes from the cursor.
 *
 *   php artisan achievements:sweep                 all wired state keys
 *   php artisan achievements:sweep --key=ACH-CIV-005
 *   php artisan achievements:sweep --chunk=2000    override the host-derived chunk
 *
 * Chunk size derives from the host (HostCapacity), overridable with
 * CGA_ACH_SWEEP_CHUNK or --chunk. Progress prints elapsed and an ETA per
 * chunk from the measured rate (never a fabricated bar).
 */
class AchievementSweepCommand extends Command
{
    protected $signature = 'achievements:sweep {--key= : One ACH-* state key to sweep (default: all wired keys)} {--chunk= : Rows per keyset chunk (default: host-derived)}';

    protected $description = 'Award EARNER_STATE achievements from the fact tables (idempotent, resumable, keyset-chunked).';

    public function handle(AchievementStateSweep $sweep): int
    {
        $chunk = $this->chunkSize();

        $only = $this->option('key');
        if ($only !== null && $only !== '') {
            if (! AchievementStateSweep::handles($only)) {
                $this->error("Key [{$only}] has no sweep spec. Wired state keys: ".implode(', ', AchievementStateSweep::KEYS));

                return self::FAILURE;
            }
            $keys = [$only];
        } else {
            $keys = AchievementStateSweep::KEYS;
        }

        $this->info('achievements:sweep  chunk='.$chunk.'  keys='.count($keys));

        $grandScanned = 0;
        $grandAwarded = 0;

        foreach ($keys as $key) {
            $title = Catalog::get($key)['title_key'];
            $started = microtime(true);
            $resume = $sweep->cursor($key);
            $total = $sweep->countFor($key); // null => ETA shown as n/a, never faked
            $this->line(sprintf(
                '  %s (%s)  total=%s%s',
                $key,
                $title,
                $total === null ? 'n/a' : (string) $total,
                $resume !== null ? '  resume@'.$resume : '',
            ));

            $progress = function (array $p) use ($started, $total): void {
                $elapsed = max(0.001, microtime(true) - $started);
                $rate = $p['scanned'] / $elapsed;
                // ETA only when a real remaining count exists; never fabricated.
                $eta = 'n/a';
                if ($total !== null && $rate > 0) {
                    $remaining = max(0, $total - $p['scanned']);
                    $eta = '~'.$this->hms($remaining / $rate);
                }
                $this->line(sprintf(
                    '    +%-5d  scanned=%-7d awarded=%-7d  %.0f rows/s  elapsed=%s  eta=%s',
                    $p['chunk'],
                    $p['scanned'],
                    $p['awarded'],
                    $rate,
                    $this->hms($elapsed),
                    $eta,
                ));
            };

            $r = $sweep->runKey($key, $chunk, $progress);
            $grandScanned += $r['scanned'];
            $grandAwarded += $r['awarded'];

            $this->line(sprintf(
                '    done  scanned=%d awarded=%d  elapsed=%s',
                $r['scanned'],
                $r['awarded'],
                $this->hms(microtime(true) - $started),
            ));
        }

        $this->info(sprintf('sweep complete  scanned=%d awarded=%d', $grandScanned, $grandAwarded));

        return self::SUCCESS;
    }

    /**
     * Rows per chunk. Env CGA_ACH_SWEEP_CHUNK or --chunk override; otherwise
     * derived from the host lane width (HostCapacity), clamped so a Pi stays
     * bounded and a big host does not run away.
     */
    private function chunkSize(): int
    {
        $opt = $this->option('chunk');
        if ($opt !== null && (int) $opt > 0) {
            return (int) $opt;
        }

        $env = env('CGA_ACH_SWEEP_CHUNK');
        if ($env !== null && (int) $env > 0) {
            return (int) $env;
        }

        // Lane width scales the chunk; floor keeps a small box moving, ceiling
        // keeps one transaction of awards bounded.
        return (int) max(500, min(10000, HostCapacity::autoscaleWorkers() * 500));
    }

    private function hms(float $seconds): string
    {
        $s = (int) round($seconds);

        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
}
