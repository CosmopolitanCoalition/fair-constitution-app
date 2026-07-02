<?php

namespace App\Services;

use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\ResidencyConfirmation;
use App\Models\User;

/**
 * Who represents this person? (mockups-v3-wiring Phase 2 — the unified
 * profile's Representatives tab.)
 *
 * The chain is pure residency facts, never a stored role: active
 * residency_confirmations → those jurisdictions' ACTIVE legislatures →
 * their CURRENT members (elected|seated). Three queries total regardless
 * of how many associations the user holds (no N+1).
 *
 * Rows are ordered most-local first (adm_level DESCENDING) — the seat for
 * the place you actually live leads; the planetary chamber comes last —
 * then by seat_no within a legislature.
 */
class RepresentativesResolver
{
    /**
     * @return list<array{
     *     member_id: string,
     *     name: string|null,
     *     seat_no: int|null,
     *     seat_type: string|null,
     *     is_speaker: bool,
     *     term_ends_on: string|null,
     *     legislature_id: string,
     *     jurisdiction: array{name: string|null, slug: string|null, adm_level: int|null},
     * }>
     */
    public function forUser(User $user): array
    {
        $jurisdictionIds = ResidencyConfirmation::query()
            ->active()
            ->where('user_id', (string) $user->id)
            ->pluck('jurisdiction_id')
            ->unique()
            ->values();

        if ($jurisdictionIds->isEmpty()) {
            return [];
        }

        $legislatures = Legislature::query()
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->where('status', Legislature::STATUS_ACTIVE)
            ->with('jurisdiction:id,name,slug,adm_level')
            ->get(['id', 'jurisdiction_id'])
            ->keyBy(fn (Legislature $l) => (string) $l->id);

        if ($legislatures->isEmpty()) {
            return [];
        }

        $members = LegislatureMember::query()
            ->current()
            ->whereIn('legislature_id', $legislatures->keys())
            ->with('user:id,name,display_name')
            ->orderBy('seat_no')
            ->get();

        return $members
            ->map(function (LegislatureMember $member) use ($legislatures) {
                $jurisdiction = $legislatures->get((string) $member->legislature_id)?->jurisdiction;

                return [
                    'member_id'      => (string) $member->id,
                    'name'           => $member->user?->display_name ?? $member->user?->name,
                    'seat_no'        => $member->seat_no,
                    'seat_type'      => $member->seat_type,
                    'is_speaker'     => (bool) $member->is_speaker,
                    'term_ends_on'   => $member->term_ends_on?->toDateString(),
                    'legislature_id' => (string) $member->legislature_id,
                    'jurisdiction'   => [
                        'name'      => $jurisdiction?->name,
                        'slug'      => $jurisdiction?->slug,
                        'adm_level' => $jurisdiction?->adm_level,
                    ],
                ];
            })
            ->sortBy([
                ['jurisdiction.adm_level', 'desc'],
                ['seat_no', 'asc'],
            ])
            ->values()
            ->all();
    }
}
