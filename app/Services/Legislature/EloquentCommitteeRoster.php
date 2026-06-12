<?php

namespace App\Services\Legislature;

use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Models\Committee;
use App\Models\CommitteeSeat;
use App\Models\Legislature;

/**
 * The REAL CommitteeRoster (replaces NoopCommitteeRoster — the chamber-ops
 * committees substrate is wired). ChamberVoteService consults this for
 * committee-body votes: lanes mirror the chamber (per-kind iff the parent
 * chamber is bicameral — q-ledger #q7 applies at committee stage), seats
 * are LIVE committee_seats rows (vacated_at IS NULL) on non-dissolved
 * committees.
 */
class EloquentCommitteeRoster implements CommitteeRoster
{
    public function laneCounts(string $committeeId): array
    {
        $committee = Committee::query()
            ->whereKey($committeeId)
            ->where('status', '!=', Committee::STATUS_DISSOLVED)
            ->first();

        if ($committee === null) {
            return ['legislature_id' => null, 'jurisdiction_id' => null, 'lanes' => []];
        }

        $legislature = Legislature::query()->find($committee->legislature_id);
        $bicameral   = $legislature !== null && (int) $legislature->type_b_seats > 0;

        $seats = CommitteeSeat::query()
            ->where('committee_id', $committee->id)
            ->live()
            ->get(['seat_kind']);

        $lanes = $bicameral
            ? array_filter([
                'type_a' => $seats->where('seat_kind', 'type_a')->count(),
                'type_b' => $seats->where('seat_kind', 'type_b')->count(),
            ])
            : ($seats->count() > 0 ? ['all' => $seats->count()] : []);

        return [
            'legislature_id'  => (string) $committee->legislature_id,
            'jurisdiction_id' => $legislature !== null ? (string) $legislature->jurisdiction_id : null,
            'lanes'           => $lanes,
        ];
    }

    public function isMember(string $committeeId, string $memberId): bool
    {
        return CommitteeSeat::query()
            ->where('committee_id', $committeeId)
            ->where('member_id', $memberId)
            ->live()
            ->exists();
    }

    public function laneOf(string $committeeId, string $memberId): ?string
    {
        $seat = CommitteeSeat::query()
            ->where('committee_id', $committeeId)
            ->where('member_id', $memberId)
            ->live()
            ->first(['seat_kind']);

        if ($seat === null) {
            return null;
        }

        return $seat->seat_kind ?? 'all';
    }
}
