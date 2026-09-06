<?php

namespace App\Services\Demo\Stages;

use App\Services\Demo\SimEconomyService;

/**
 * The STIPEND stage (W7 item 8) — the civic stipend for one jurisdiction's
 * residents, through the real StipendService (F-TRE-004).
 *
 * PER-JURISDICTION IS THE CHUNK (THE ETL RULE). The demo command runs the whole
 * root in one transaction; the sim runs one bounded jurisdiction at a time, so a
 * kill costs one scope, not the pass. Eligibility is the hardened gate — active
 * residency and nothing else (Art. I). Idempotent enough for the pull engine: a
 * re-handed item writes a fresh disbursement, which the demo tolerates (the
 * ledger is append-only and every credit is real); the run is the operator's
 * trigger, and sim:revert clears the layer for a clean re-run.
 */
final class StipendStage
{
    private function __construct() {}

    /**
     * @return array{ran:bool, recipients:int, total:string, short_paid:bool, skipped:?string}
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $result = app(SimEconomyService::class)->runStipendFor($jurisdictionId, $beat);

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
