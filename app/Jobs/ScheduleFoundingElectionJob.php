<?php

namespace App\Jobs;

use App\Models\Jurisdiction;
use App\Services\ActivationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CLOSE THE LOOP between drawing a map and holding an election (operator,
 * 2026-08-08 — "how do we get around this catch 22?").
 *
 * There was never a cycle, but there WAS a dead end: activation schedules
 * the founding election, which needs districts; a chamber over the 5–9
 * per-race ceiling with nothing drawn has none, so the engine refuses
 * ("Race generation is blocked pending subdivision") and activation
 * records the posture and moves on — correctly. Nothing then re-attempted
 * the election once the operator drew the map, so 21,559 places on the
 * live box sat blocked with no path forward but a CLI --replan.
 *
 * This job runs when a district plan goes ACTIVE: it re-enters WF-JUR-01
 * step 3.5 (ActivationService::replan), which now finds districts and
 * schedules the founding election with a race per district.
 *
 * Guards are the service's own: replan refuses a chamber that is not
 * memberless+forming, so a re-districting of a SEATED chamber is a logged
 * no-op — an election is never re-run over sitting members.
 */
class ScheduleFoundingElectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $jurisdictionId)
    {
    }

    public function handle(ActivationService $activation): void
    {
        $jurisdiction = Jurisdiction::query()->find($this->jurisdictionId);
        if ($jurisdiction === null) {
            return;
        }

        // Drawing a map must not start a CLOCK on an empty planet (operator
        // ruling 2026-08-08). Only POPULATION mode closes the loop
        // automatically — there a place activates because residents arrived,
        // so its blocked election is genuinely waiting on the map.
        if (\App\Models\InstanceSettings::query()->whereNull('deleted_at')
                ->value('institution_scale_mode') !== 'population') {
            return;
        }

        // Already holding an open cycle? Nothing to schedule.
        $open = DB::table('elections as e')
            ->join('legislatures as l', 'l.id', '=', 'e.legislature_id')
            ->where('l.jurisdiction_id', $this->jurisdictionId)
            ->whereNull('l.deleted_at')
            ->whereIn('e.status', ['scheduled', 'approval_open'])
            ->exists();
        if ($open) {
            return;
        }

        try {
            $activation->replan($jurisdiction);
            Log::info(sprintf(
                'ScheduleFoundingElectionJob: re-planned %s after its district map went active.',
                $jurisdiction->slug,
            ));
        } catch (\Throwable $e) {
            // A seated chamber (replan's own guard), or a place that never
            // activated — both are legitimate no-ops, not failures.
            Log::info(sprintf(
                'ScheduleFoundingElectionJob: %s not re-planned — %s',
                $jurisdiction->slug, $e->getMessage(),
            ));
        }
    }
}
