<?php

namespace App\Services\Dev;

use App\Models\ClockTimer;
use App\Services\AuditService;
use App\Services\ClockService;
use App\Support\DeadlineRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PLAYTEST TIME CONTROLS — make a deadline arrive without waiting for it.
 *
 * Walking a real journey means reaching things that are days or years away: an
 * approval window opening, a ranked ballot closing, an emergency power hitting
 * its 90-day ceiling. Today the only way is to wait, so most journeys cannot
 * be walked at all.
 *
 * ── The design decision (DEV_TIME_AND_ROLE_CONTROLS.md §2) ────────────────
 * This SHIFTS THE DEADLINES, it does not offset the clock. Nothing in the app
 * centralises `now()`, so an offset would mean auditing every call site
 * including inside PROTECTED files, and one missed site produces a world that
 * silently disagrees with itself about what time it is. Pulling a bounded set
 * of registered columns backward fails loudly instead: an unregistered
 * deadline does not move and the journey visibly stalls.
 *
 * Three consequences worth stating plainly:
 *   · The wall clock stays real. Audit entries keep true timestamps, so a
 *     journey walked on 26 July reads as 26 July forever.
 *   · Only PENDING rows move. Fired, cancelled, expired and certified rows are
 *     history and are never touched — see the guards in DeadlineRegistry.
 *   · Every advance writes its own audit entry, so a playtested timeline stays
 *     distinguishable from a lived one. A fabricated history that read as
 *     genuine would be the worst possible outcome here.
 */
class DevClockService
{
    /** Rows per committed chunk — THE ETL RULE: bounded writes, visible progress. */
    public const CHUNK = 5000;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ClockService $clocks,
    ) {}

    /**
     * P2 step 1 — THE DRY RUN, which always runs first.
     *
     * What would become due if the world advanced N days, grouped so the
     * operator can see the shape of it before anything moves. A ten-year
     * advance on a founded world fires a great deal at once, and he must be
     * able to look at that list first.
     *
     * @return array{days:int, timers:list<array<string,mixed>>, columns:list<array<string,mixed>>, total_timers:int}
     */
    public function dryRun(int $days): array
    {
        $this->assertSaneDays($days);

        // Which armed timers would come due — grouped by clock and jurisdiction,
        // because "CLK-01 × 240 across 3 places" is readable and 240 rows is not.
        $timers = DB::select('
            SELECT ct.clock_id,
                   ct.jurisdiction_id,
                   j.name AS jurisdiction_name,
                   count(*) AS due,
                   min(ct.fires_at) AS earliest,
                   max(ct.fires_at) AS latest
              FROM clock_timers ct
              LEFT JOIN jurisdictions j ON j.id = ct.jurisdiction_id
             WHERE ct.state = ?
               AND ct.deleted_at IS NULL
               AND ct.fires_at IS NOT NULL
               AND ct.fires_at > now()
               AND ct.fires_at <= now() + make_interval(days => ?)
             GROUP BY ct.clock_id, ct.jurisdiction_id, j.name
             ORDER BY count(*) DESC, ct.clock_id
        ', [ClockTimer::STATE_ARMED, $days]);

        // And how many rows each registered column would shift. A column
        // reporting zero is worth seeing too: it is either genuinely empty or
        // a guard that is wrong, and both are things to notice.
        $columns = [];

        foreach (DeadlineRegistry::movable() as $entry) {
            $columns[] = [
                'table'  => $entry['table'],
                'column' => $entry['column'],
                'why'    => $entry['why'],
                'rows'   => $this->countShiftable($entry),
            ];
        }

        return [
            'days'         => $days,
            'timers'       => array_map(static fn ($r): array => [
                'clock_id'          => $r->clock_id,
                'jurisdiction_id'   => $r->jurisdiction_id,
                'jurisdiction_name' => $r->jurisdiction_name,
                'due'               => (int) $r->due,
                'earliest'          => $r->earliest,
                'latest'            => $r->latest,
            ], $timers),
            'columns'      => $columns,
            'total_timers' => array_sum(array_map(static fn ($r): int => (int) $r->due, $timers)),
        ];
    }

    /**
     * P2 steps 2–4 — shift every registered deadline back N days, then let the
     * engine fire what is now due.
     *
     * @param  callable|null  $onProgress  fn(string $label, int $done, int $total): void
     * @return array{days:int, shifted:array<string,int>, fired:int, failed:int}
     */
    public function advance(int $days, ?callable $onProgress = null): array
    {
        $this->assertSaneDays($days);

        $shifted = [];

        foreach (DeadlineRegistry::movable() as $entry) {
            $key = $entry['table'].'.'.$entry['column'];
            $shifted[$key] = $this->shiftColumn($entry, $days, $onProgress);
        }

        // The engine fires what is now due through its ordinary path — no
        // shortcut, no bypass of the constitutional handlers.
        $fired = $this->fireDue($onProgress);

        // MARKED AS DEV, ALWAYS. The audit log is the app's record of truth and
        // is hash-chained; a playtested timeline must stay distinguishable from
        // a lived one forever after.
        $this->audit->append(
            module: 'system',
            event: 'dev.clock_advanced',
            payload: [
                'days'        => $days,
                'shifted'     => $shifted,
                'fired'       => $fired['fired'],
                'failed'      => $fired['failed'],
                'dev_control' => true,
                'note'        => 'PLAYTEST CONTROL — deadlines were pulled backward. Real timestamps unchanged.',
            ],
            ref: 'DEV-TIME',
        );

        return ['days' => $days, 'shifted' => $shifted, 'fired' => $fired['fired'], 'failed' => $fired['failed']];
    }

    /**
     * P1 — fire ONE named timer through the real ClockService.
     *
     * No shortcut path: whatever the clock is wired to do, it does.
     *
     * @return array{timer_id:string, clock_id:string, fired:bool}
     */
    public function fireOne(string $timerId): array
    {
        $timer = ClockTimer::query()->find($timerId);

        if ($timer === null) {
            throw new RuntimeException("No timer {$timerId}.");
        }

        if ($timer->state !== ClockTimer::STATE_ARMED) {
            throw new RuntimeException("Timer {$timerId} is {$timer->state}, not armed — only an armed timer can fire.");
        }

        $fired = $this->clocks->fire($timer, ['dev_control' => true]);

        $this->audit->append(
            module: 'system',
            event: 'dev.clock_fired',
            payload: [
                'timer_id'    => $timerId,
                'clock_id'    => $timer->clock_id,
                'fired'       => $fired,
                'dev_control' => true,
                'note'        => 'PLAYTEST CONTROL — a human fired this timer early.',
            ],
            ref: 'DEV-TIME',
            jurisdictionId: $timer->jurisdiction_id,
        );

        return ['timer_id' => $timerId, 'clock_id' => $timer->clock_id, 'fired' => $fired];
    }

    /** How many rows a registered column would shift. */
    private function countShiftable(array $entry): int
    {
        return (int) DB::scalar(sprintf(
            'SELECT count(*) FROM %s WHERE %s AND %s IS NOT NULL',
            $entry['table'],
            $entry['guard'],
            $entry['column'],
        ));
    }

    /**
     * Shift one column in bounded committed chunks.
     *
     * THE ETL RULE: never one planet-wide statement. Each chunk commits on its
     * own and reports progress, so the operator sees movement and a failure
     * halfway through leaves a resumable world rather than an unknown one.
     */
    private function shiftColumn(array $entry, int $days, ?callable $onProgress): int
    {
        $label = $entry['table'].'.'.$entry['column'];
        $total = $this->countShiftable($entry);

        if ($total === 0) {
            $onProgress && $onProgress($label, 0, 0);

            return 0;
        }

        $moved = 0;

        do {
            $n = DB::update(sprintf(
                'UPDATE %1$s SET %2$s = %2$s - make_interval(days => %3$d)
                  WHERE id IN (
                        SELECT id FROM %1$s
                         WHERE %4$s
                           AND %2$s IS NOT NULL
                           AND %2$s > now() - make_interval(days => %3$d)
                         LIMIT %5$d
                  )',
                $entry['table'],
                $entry['column'],
                $days,          // interval literal, not a bound param — a bound
                                // parameter into make_interval() is a known
                                // runtime trap on this stack (PDO binds it as
                                // text and it fails under load, not at parse).
                $entry['guard'],
                self::CHUNK,
            ));

            $moved += $n;
            $onProgress && $onProgress($label, $moved, $total);
        } while ($n > 0 && $moved < $total);

        return $moved;
    }

    /**
     * Fire every timer that is now due, in bounded batches, through the real
     * engine. A timer whose handler refuses is counted and left alone rather
     * than retried — its refusal is a real constitutional outcome, not an error
     * to paper over.
     *
     * @return array{fired:int, failed:int}
     */
    private function fireDue(?callable $onProgress): array
    {
        $fired = 0;
        $failed = 0;

        while (true) {
            $batch = ClockTimer::query()
                ->where('state', ClockTimer::STATE_ARMED)
                ->whereNotNull('fires_at')
                ->where('fires_at', '<=', now())
                ->orderBy('fires_at')
                ->limit(500)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $timer) {
                try {
                    $this->clocks->fire($timer, ['dev_control' => true]) ? $fired++ : $failed++;
                } catch (\Throwable) {
                    // The handler refused or blew up. Leave the timer as the
                    // engine left it and keep going — one stuck clock must not
                    // strand the rest of the sweep.
                    $failed++;
                }
            }

            $onProgress && $onProgress('firing due timers', $fired + $failed, $fired + $failed);

            // Everything in this batch has been attempted; if none of them
            // changed state we would loop forever, so stop.
            if ($batch->count() < 500) {
                break;
            }
        }

        return ['fired' => $fired, 'failed' => $failed];
    }

    private function assertSaneDays(int $days): void
    {
        if ($days < 1) {
            throw new RuntimeException('Advance by at least one day.');
        }

        // ~200 years. Not a constitutional limit — a guard against a typo
        // silently firing every timer in the world.
        if ($days > 73_000) {
            throw new RuntimeException("{$days} days is almost certainly a typo — cap is 73,000 (about 200 years).");
        }
    }
}
