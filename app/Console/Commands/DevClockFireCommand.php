<?php

namespace App\Console\Commands;

use App\Http\Middleware\DevTimeControlsEnabled;
use App\Models\ClockTimer;
use App\Services\Dev\DevClockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P1 — fire one named timer (DEV_TIME_AND_ROLE_CONTROLS.md §3).
 *
 *   php artisan dev:clock-fire                 # list what is armed
 *   php artisan dev:clock-fire <timer-uuid>    # fire that one
 *
 * Goes through the real `ClockService::fire()`. No shortcut, no bypass of the
 * constitutional engine — whatever the clock is wired to do, it does, and if a
 * handler refuses, that refusal is the honest outcome.
 */
class DevClockFireCommand extends Command
{
    protected $signature = 'dev:clock-fire {timer? : The armed timer to fire; omit to list what is armed}';

    protected $description = 'Playtest control: fire one armed clock timer now';

    public function handle(DevClockService $clock): int
    {
        if ($reason = DevTimeControlsEnabled::refusalReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $timerId = $this->argument('timer');

        if ($timerId === null) {
            return $this->listArmed();
        }

        try {
            $result = $clock->fireOne($timerId);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $result['fired']
            ? $this->info("  {$result['clock_id']} fired.")
            : $this->warn("  {$result['clock_id']} did not fire — it was no longer armed.");

        return $result['fired'] ? self::SUCCESS : self::FAILURE;
    }

    /** What is waiting, soonest first — so the operator can pick one. */
    private function listArmed(): int
    {
        $rows = DB::table('clock_timers as ct')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 'ct.jurisdiction_id')
            ->where('ct.state', ClockTimer::STATE_ARMED)
            ->whereNull('ct.deleted_at')
            ->orderBy('ct.fires_at')
            ->limit(50)
            ->get(['ct.id', 'ct.clock_id', 'ct.fires_at', 'ct.subject_type', 'j.name as jurisdiction']);

        if ($rows->isEmpty()) {
            $this->line('  <fg=gray>Nothing is armed. Nothing is waiting to happen.</>');

            return self::SUCCESS;
        }

        $this->table(
            ['timer', 'clock', 'place', 'about', 'fires at'],
            $rows->map(static fn ($r): array => [
                $r->id,
                $r->clock_id,
                $r->jurisdiction ?? '<all>',
                $r->subject_type ?? '—',
                $r->fires_at,
            ])->all(),
        );

        $this->line('  Fire one with <options=bold>php artisan dev:clock-fire {timer}</>');

        return self::SUCCESS;
    }
}
