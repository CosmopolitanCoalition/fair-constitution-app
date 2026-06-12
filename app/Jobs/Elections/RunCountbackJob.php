<?php

namespace App\Jobs\Elections;

use App\Models\Vacancy;
use App\Services\VacancyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WI-B6 — queued countback runner (Art. II §5; counting design §D
 * "Countback"). Thin wrapper: VacancyService::runCountback holds the
 * whole sequence (countback_running → universal re-run → seat the first
 * eligible replacement OR countback_failed → CLK-04 + auto-scheduled
 * special election). Queued on `long-running` next to the tabulation
 * jobs — a countback IS a tabulation of the original race's ballots.
 *
 * Idempotent: a vacancy not in a runnable state (already running, filled,
 * or routed to a special election) is left untouched.
 */
class RunCountbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public readonly string $vacancyId,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(VacancyService $vacancies): void
    {
        $vacancy = Vacancy::query()->find($this->vacancyId);

        if ($vacancy === null) {
            return;
        }

        $vacancies->runCountback($vacancy);
    }
}
