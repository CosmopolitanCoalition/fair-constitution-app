<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Organizations\OrgConversionService;

/**
 * F-LEG-027 — CGC Reorganization/Sale Vote (R-09; WF-ORG-08/09).
 *
 * Branches: reorganize (new charter law) · dissolve (wind-down via the
 * F-ORG-007 internals, system actor) · sell (cgc_to_private via
 * cgc_sale). A payload carrying any ip_-prefixed or reclaim key is
 * rejected PRE-VOTE with the Art. III §5 citation (validator + service)
 * — dedications are irreversible; the cgc_ip_register is never touched.
 * Threshold: procedural_motion (unstated → ordinary majority; flagged).
 */
class CgcReorganizationSaleVote implements FormHandler
{
    public function __construct(
        private readonly OrgConversionService $conversions,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'cgc_reorg_sale.proposed';
    }

    public function requiredRoles(): array
    {
        return ['R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-LEG-027');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-027');

        $result = $this->conversions->proposeReorgSale($legislature, $member, $payload);

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'branch'         => (string) ($payload['branch'] ?? ''),
        ] + $result;
    }
}
