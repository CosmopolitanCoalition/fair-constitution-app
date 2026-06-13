<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\OrgOwnershipStake;
use Illuminate\Support\Facades\DB;

/**
 * D-O4 (PHASE_D_DESIGN_organizations §A) — the cap-table writer: stake
 * rows open/close (never edit — history preserved via ended_at), pct
 * snapshots recomputed on every write, the user-holder ⇒ matching
 * membership-class invariant maintained.
 */
class OrgOwnershipService
{
    /** Open a stake row and recompute the cap table. */
    public function openStake(
        Organization $org,
        string $holderType,
        string $holderId,
        float $units,
        string $acquiredVia,
        ?string $sourceTransferId = null,
    ): OrgOwnershipStake {
        $stake = OrgOwnershipStake::create([
            'organization_id'    => (string) $org->id,
            'holder_type'        => $holderType,
            'holder_id'          => $holderId,
            'units'              => $units,
            'acquired_via'       => $acquiredVia,
            'source_transfer_id' => $sourceTransferId,
            'as_of'              => now(),
        ]);

        // Invariant: a user-holder's stake implies a membership row of
        // the org's ownership class (service-maintained).
        if ($holderType === OrgOwnershipStake::HOLDER_USERS) {
            $class = $org->membershipKind();

            if ($class !== null) {
                $open = OrgMembership::query()
                    ->where('organization_id', $org->id)
                    ->where('user_id', $holderId)
                    ->where('kind', $class)
                    ->whereIn('status', [OrgMembership::STATUS_APPLIED, OrgMembership::STATUS_ACTIVE])
                    ->exists();

                if (! $open) {
                    OrgMembership::create([
                        'organization_id' => (string) $org->id,
                        'user_id'         => $holderId,
                        'kind'            => $class,
                        'status'          => OrgMembership::STATUS_ACTIVE,
                        'applied_at'      => now(),
                        'accepted_at'     => now(),
                    ]);
                }
            }
        }

        $this->recomputePct((string) $org->id);

        return $stake;
    }

    /** Close every open stake (conversion/transfer completion). */
    public function closeAllStakes(Organization $org): int
    {
        $closed = OrgOwnershipStake::query()
            ->where('organization_id', $org->id)
            ->open()
            ->update(['ended_at' => now(), 'updated_at' => now()]);

        $this->recomputePct((string) $org->id);

        return (int) $closed;
    }

    /** Denormalized pct snapshot over the OPEN cap table. */
    public function recomputePct(string $organizationId): void
    {
        $total = (float) OrgOwnershipStake::query()
            ->where('organization_id', $organizationId)
            ->open()
            ->sum('units');

        if ($total <= 0) {
            return;
        }

        foreach (OrgOwnershipStake::query()->where('organization_id', $organizationId)->open()->get() as $stake) {
            DB::table('org_ownership_stakes')
                ->where('id', $stake->id)
                ->update(['pct' => round((float) $stake->units / $total * 100, 4), 'updated_at' => now()]);
        }
    }
}
