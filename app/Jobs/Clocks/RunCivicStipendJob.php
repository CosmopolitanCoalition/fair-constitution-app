<?php

namespace App\Jobs\Clocks;

use App\Services\ClockService;
use App\Services\Demo\SimEconomyService;
use App\Services\Economy\StipendClockService;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CLK-22 — the STANDALONE civic stipend pass (W-0201, F-TRE-004).
 *
 * Fired by the clock engine when the stipend period lapses. It runs the same
 * per-account credit the simulation runs, through the SAME service
 * (SimEconomyService::runStipendFor → StipendService::run) — never a parallel
 * write path — then re-arms CLK-22 for the next period.
 *
 * KEYSET-CHUNKED, RESUMABLE, HOST-SIZED (THE ETL RULE). The roster is the
 * jurisdictions where active residents actually exist (bound the INPUT, not a
 * planet-wide table), walked in host-sized id chunks. Each jurisdiction's run
 * is idempotent within a period: a jurisdiction already paid inside the
 * current period window is skipped, so a re-handed item (a kill mid-pass)
 * credits every eligible account exactly once per period. A kill costs one
 * chunk; the re-arm at the end rolls the next period.
 */
class RunCivicStipendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** The fired timer's id (Phase B handler convention); unused but carried. */
    public function __construct(public readonly ?string $timerId = null)
    {
    }

    public function handle(StipendClockService $clock): void
    {
        $rootId     = $clock->rootId();
        $periodDays = app(ClockService::class)->resolvedInt('CLK-22', $rootId, 30);

        $limit   = HostCapacity::sweepChunk();
        $afterId = null;
        $seen    = 0;
        $paid    = 0;

        while (true) {
            $ids = $this->jurisdictionRoster($afterId, $limit);

            if ($ids === []) {
                break;
            }

            foreach ($ids as $jurisdictionId) {
                $afterId = $jurisdictionId;
                $seen++;
                if ($this->payJurisdiction($jurisdictionId, $periodDays)) {
                    $paid++;
                }
            }

            Log::info('RunCivicStipendJob: chunk done', ['seen' => $seen, 'paid' => $paid, 'after' => $afterId]);
        }

        // Roll the next period regardless of how many places paid.
        $clock->reArm();
    }

    /**
     * The resident-bearing jurisdictions after $afterId (bound the INPUT).
     *
     * @return array<int,string>
     */
    protected function jurisdictionRoster(?string $afterId, int $limit): array
    {
        return DB::table('residency_confirmations')
            ->where('is_active', true)
            ->when($afterId !== null, fn ($q) => $q->where('jurisdiction_id', '>', $afterId))
            ->distinct()
            ->orderBy('jurisdiction_id')
            ->limit($limit)
            ->pluck('jurisdiction_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Pay one jurisdiction through the shared stipend service, idempotent
     * within the current period. Returns true when it ran. The seam a
     * subclass overrides in tests.
     */
    protected function payJurisdiction(string $jurisdictionId, int $periodDays): bool
    {
        $since = now()->subDays(max(1, $periodDays));

        $alreadyPaid = DB::table('ubi_disbursements')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('ran_at', '>=', $since)
            ->exists();

        if ($alreadyPaid) {
            return false; // one run per period — never double-pay
        }

        $result = app(SimEconomyService::class)->runStipendFor($jurisdictionId);

        return $result !== null;
    }
}
