<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\OrgOwnershipStake;
use App\Support\HostCapacity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * D-O4 (PHASE_D_DESIGN_organizations §A) — the cap-table writer: stake
 * rows open/close (never edit — history preserved via ended_at), pct
 * snapshots recomputed on every write, the user-holder ⇒ matching
 * membership-class invariant maintained.
 */
class OrgOwnershipService
{
    /** Exact numeric(20,6) input. Existing internal float callers remain valid. */
    public static function normalizeUnits(mixed $units): string
    {
        if (is_float($units)) {
            if (! is_finite($units)) {
                throw new InvalidArgumentException('Enter a positive share quantity with no more than six decimal places.');
            }
            // Keep the prior numeric(20,6) rounding for legacy arithmetic
            // callers, including binary floating-point remainders. HTTP sends
            // the original decimal string so large quantities keep every digit.
            $units = number_format($units, 6, '.', '');
        } elseif (is_int($units)) {
            $units = (string) $units;
        }

        if (! is_string($units) || ! preg_match('/\A\d{1,14}(?:\.\d{1,6})?\z/', $units)
            || bccomp($units, '0', 6) <= 0) {
            throw new InvalidArgumentException('Enter a positive share quantity with up to 14 digits before the decimal point and six after it.');
        }

        return bcadd($units, '0', 6);
    }

    /** Open a stake row and recompute the cap table. */
    public function openStake(
        Organization $org,
        string $holderType,
        string $holderId,
        string|int|float $units,
        string $acquiredVia,
        ?string $sourceTransferId = null,
    ): OrgOwnershipStake {
        $units = self::normalizeUnits($units);

        return DB::transaction(function () use ($org, $holderType, $holderId, $units, $acquiredVia, $sourceTransferId) {
            Organization::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
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

            return $stake->refresh();
        });
    }

    /** Close every open stake (conversion/transfer completion). */
    public function closeAllStakes(Organization $org): int
    {
        return DB::transaction(function () use ($org) {
            Organization::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            $closed = 0;
            DB::table('org_ownership_stakes')->where('organization_id', $org->id)->whereNull('ended_at')
                ->select('id')->chunkById($this->stakeBatchSize(), function ($stakes) use (&$closed) {
                    $closed += DB::table('org_ownership_stakes')->whereIn('id', $stakes->pluck('id'))
                        ->update(['ended_at' => now(), 'updated_at' => now()]);
                });

            return $closed;
        });
    }

    /** Denormalized pct snapshot over the OPEN cap table. */
    public function recomputePct(string $organizationId): void
    {
        DB::transaction(function () use ($organizationId) {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $query = DB::table('org_ownership_stakes')->where('organization_id', $organizationId)
                ->whereNull('ended_at')->select('id', 'units');
            $batch = $this->stakeBatchSize();
            $total = '0.000000';
            (clone $query)->chunkById($batch, function ($stakes) use (&$total) {
                foreach ($stakes as $stake) $total = bcadd($total, (string) $stake->units, 6);
            });

            if (bccomp($total, '0', 6) <= 0) return;

            // Two bounded passes avoid hydrating a whole cap table or losing
            // precision in its total. The transaction publishes one coherent
            // ownership change; these snapshots must not commit piecemeal.
            (clone $query)->chunkById($batch, function ($stakes) use ($total) {
                foreach ($stakes as $stake) {
                    $percent = bcdiv(bcmul((string) $stake->units, '100', 6), $total, 5);
                    DB::table('org_ownership_stakes')->where('id', $stake->id)
                        ->update(['pct' => bcadd($percent, '0.00005', 4), 'updated_at' => now()]);
                }
            });
        });
    }

    /** Host-derived buffer for narrow stake rows, overridable for small hosts. */
    protected function stakeBatchSize(): int
    {
        $available = max(1, HostCapacity::workerRecycleLightMb() * 1048576 - memory_get_usage(true));
        if (preg_match('/^(\d+)\s*([KMG]?)$/i', trim((string) ini_get('memory_limit')), $m)) {
            $bytes = (int) $m[1] * (1024 ** array_search(strtoupper($m[2]), ['', 'K', 'M', 'G'], true));
            $available = min($available, max(1, $bytes - memory_get_usage(true)));
        }
        foreach ([['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory.current'], ['/sys/fs/cgroup/memory/memory.limit_in_bytes', '/sys/fs/cgroup/memory/memory.usage_in_bytes']] as [$capPath, $usedPath]) {
            if (! is_readable($capPath) || ! is_readable($usedPath)) continue;
            $cap = trim(file_get_contents($capPath));
            if (ctype_digit($cap)) $available = min($available, max(1, (int) $cap - (int) trim(file_get_contents($usedPath))));
            break;
        }
        $fraction = max(0.0001, min(0.25, (float) env('CGA_OWNERSHIP_MEMORY_FRACTION', 1 / 64)));
        $rowBytes = max(1, (int) env('CGA_OWNERSHIP_ROW_BYTES', 512));
        // Closing stakes binds one UUID per row; respect the PostgreSQL
        // parameter ceiling as well as the available PHP/container memory.
        return max(1, min(65533, (int) floor($available * $fraction / $rowBytes)));
    }
}
