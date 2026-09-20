<?php

namespace App\Services\Demo\Stages;

use App\Services\Demo\SimEconomyService;
use App\Support\SimTimer;

/**
 * The STIPEND stage (W7 item 8) — the civic stipend for one jurisdiction's
 * residents, through the real StipendService (F-TRE-004).
 *
 * The demo command runs the whole root in one transaction; Step 5 prepares
 * per-jurisdiction work and commits at most four scopes together, so a
 * kill costs a bounded scope group, not the pass. Eligibility is the hardened
 * gate — active residency and nothing else (Art. I). SimWorkerJob now uses
 * SimStipendBatch to commit money and fenced DONE records atomically. Direct
 * calls to this legacy single-scope entry retain fresh-disbursement behavior.
 */
final class StipendStage
{
    private function __construct() {}

    /**
     * @return array{ran:bool, recipients:int, total:string, short_paid:bool, skipped:?string}
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $mDisburse = hrtime(true);
        try {
            $result = app(SimEconomyService::class)->runStipendFor($jurisdictionId, $beat);
        } finally {
            SimTimer::record('stipend.disburse', (int) ((hrtime(true) - $mDisburse) / 1000));
        }

        if ($result === null) {
            return ['ran' => false, 'recipients' => 0, 'total' => '0', 'short_paid' => false, 'skipped' => 'no residents with wallets'];
        }

        return [
            'ran' => true,
            'recipients' => $result['recipients'],
            'total' => $result['total'],
            'short_paid' => $result['short_paid'],
            'skipped' => null,
        ];
    }
}
