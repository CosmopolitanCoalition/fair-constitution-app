<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Department;
use App\Models\EmergencyPower;
use App\Models\Executive;
use App\Models\ExecutiveOrder;
use App\Models\Law;
use Illuminate\Support\Facades\DB;

/**
 * Shared enabling-instrument resolution for executive orders (F-EXE-005)
 * and department rules (F-BOG-001) — "orders execute, rules implement;
 * neither can exceed" is enforced STRUCTURALLY: the cited instrument
 * must exist, be live, and its jurisdiction must cover the acting body's
 * (Art. III §2; Art. II §7 for emergency powers). Semantic excess is
 * Phase E judicial-review territory.
 */
class EnablingInstruments
{
    /**
     * Assert the cited instrument exists, is live, and covers the
     * executive's jurisdiction. Returns a small descriptor for audit
     * payloads.
     *
     * @return array{type: string, id: string, label: string, expires_at: ?string}
     */
    public static function assertLive(
        string $enablingType,
        string $enablingId,
        Executive $executive,
        ?Department $department = null,
    ): array {
        return match ($enablingType) {
            ExecutiveOrder::ENABLING_LAW             => self::assertLaw($enablingId, $executive),
            ExecutiveOrder::ENABLING_CHARTER         => self::assertCharter($enablingId, $executive, $department),
            ExecutiveOrder::ENABLING_EMERGENCY_POWER => self::assertEmergencyPower($enablingId, $executive),
            default => throw new ConstitutionalViolation(
                "Unknown enabling instrument type [{$enablingType}].",
                'Art. III §2'
            ),
        };
    }

    /** @return array{type: string, id: string, label: string, expires_at: ?string} */
    private static function assertLaw(string $lawId, Executive $executive): array
    {
        $law = Law::query()->find($lawId);

        if ($law === null || ! in_array($law->status, [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED], true)) {
            throw new ConstitutionalViolation(
                'The cited enabling law does not exist or is not in force — an order/rule without a '
                . 'live enabling instrument cannot issue.',
                'Art. III §2'
            );
        }

        if (! self::jurisdictionCovers((string) $law->jurisdiction_id, (string) $executive->jurisdiction_id)) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Enabling law %s binds jurisdiction %s, which does not cover the executive\'s '
                    . 'jurisdiction — the instrument cannot enable action outside its own scale.',
                    $law->act_number,
                    (string) $law->jurisdiction_id
                ),
                'Art. III §2'
            );
        }

        return [
            'type'       => ExecutiveOrder::ENABLING_LAW,
            'id'         => (string) $law->id,
            'label'      => (string) $law->act_number,
            'expires_at' => null,
        ];
    }

    /** @return array{type: string, id: string, label: string, expires_at: ?string} */
    private static function assertCharter(string $lawId, Executive $executive, ?Department $department): array
    {
        $law = Law::query()->find($lawId);

        if ($law === null
            || $law->kind !== Law::KIND_CHARTER
            || ! in_array($law->status, [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED], true)) {
            throw new ConstitutionalViolation(
                'The cited charter does not exist or is not in force.',
                'Art. III §2'
            );
        }

        $chartered = Department::query()
            ->where('charter_law_id', $law->id)
            ->where('executive_id', $executive->id)
            ->whereNull('deleted_at')
            ->first();

        if ($chartered === null) {
            throw new ConstitutionalViolation(
                'A charter enables only the executive that oversees the chartered department — the '
                . 'cited charter belongs to no department under this executive.',
                'Art. III §2'
            );
        }

        if ($department !== null && (string) $department->charter_law_id !== (string) $law->id) {
            throw new ConstitutionalViolation(
                'The cited charter is not the named department\'s own charter.',
                'Art. III §2'
            );
        }

        return [
            'type'       => ExecutiveOrder::ENABLING_CHARTER,
            'id'         => (string) $law->id,
            'label'      => (string) $law->act_number,
            'expires_at' => null,
        ];
    }

    /** @return array{type: string, id: string, label: string, expires_at: ?string} */
    private static function assertEmergencyPower(string $powerId, Executive $executive): array
    {
        $power = EmergencyPower::query()->find($powerId);

        $live = $power !== null
            && in_array($power->status, [EmergencyPower::STATUS_ACTIVE, EmergencyPower::STATUS_RENEWED], true)
            && $power->expires_at !== null
            && now()->lt($power->expires_at);

        if (! $live) {
            throw new ConstitutionalViolation(
                'The cited emergency power is not active — emergency-widened scope dies with the '
                . 'power (declared duration, never silent rollover).',
                'Art. II §7'
            );
        }

        // The power widens scope ONLY within its declared area: the
        // executive's jurisdiction must lie within (self or descendant
        // of) the declared area.
        if (! self::jurisdictionCovers((string) $power->area_jurisdiction_id, (string) $executive->jurisdiction_id)) {
            throw new ConstitutionalViolation(
                'The emergency power\'s declared area does not cover the executive\'s jurisdiction — '
                . 'emergency powers widen scope only within their declared area and duration.',
                'Art. II §7'
            );
        }

        return [
            'type'       => ExecutiveOrder::ENABLING_EMERGENCY_POWER,
            'id'         => (string) $power->id,
            'label'      => (string) $power->label,
            'expires_at' => $power->expires_at?->toIso8601String(),
        ];
    }

    /**
     * True when $coveringId is $targetId itself or one of its ancestors
     * (the instrument's scope contains the acting jurisdiction).
     */
    public static function jurisdictionCovers(string $coveringId, string $targetId): bool
    {
        $current = $targetId;

        for ($depth = 0; $depth < 32; $depth++) {
            if ($current === $coveringId) {
                return true;
            }

            $parent = DB::table('jurisdictions')->where('id', $current)->value('parent_id');

            if ($parent === null) {
                return false;
            }

            $current = (string) $parent;
        }

        return false;
    }
}
