<?php

namespace App\Http\Controllers\Legislature\Concerns;

use App\Models\Election;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;

/**
 * Shared chamber context for the Phase C legislature controllers
 * (PHASE_C_DESIGN_frontend.md §B.1 `legislature` prop block, reused
 * verbatim by B.2–B.11).
 *
 * Threshold posture: the page-level quorum/supermajority stats are
 * DISPLAY anchors resolved through the PROTECTED functions over the live
 * serving count — every VOTE meter on these pages renders the
 * chamber_vote_tallies SNAPSHOT instead (ChamberVotePresenter); the two
 * agree except across seat changes, which is exactly the honest story.
 */
trait ResolvesChamber
{
    /**
     * @return array<string, mixed> the §B.1 `legislature` prop block
     */
    protected function legislatureProps(Legislature $legislature): array
    {
        $members = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->get(['id', 'seat_type']);

        $serving   = $members->count();
        $bicameral = (int) $legislature->type_b_seats > 0;

        $settings    = app(SettingsResolver::class);
        $jid         = (string) $legislature->jurisdiction_id;
        $numerator   = $settings->resolveInt($jid, 'supermajority_numerator', 2);
        $denominator = $settings->resolveInt($jid, 'supermajority_denominator', 3);

        $byKind = null;

        if ($bicameral) {
            $servingA = $members->where('seat_type', 'a')->count();
            $servingB = $members->where('seat_type', 'b')->count();

            $byKind = [
                'type_a' => [
                    'seats'         => (int) $legislature->type_a_seats,
                    'serving'       => $servingA,
                    'quorum'        => $servingA > 0 ? ConstitutionalValidator::quorum($servingA) : null,
                    'supermajority' => $servingA > 0 ? ConstitutionalValidator::supermajority($servingA, $numerator, $denominator) : null,
                ],
                'type_b' => [
                    'seats'         => (int) $legislature->type_b_seats,
                    'serving'       => $servingB,
                    'quorum'        => $servingB > 0 ? ConstitutionalValidator::quorum($servingB) : null,
                    'supermajority' => $servingB > 0 ? ConstitutionalValidator::supermajority($servingB, $numerator, $denominator) : null,
                ],
            ];
        }

        $termEndsOn = $legislature->term_ends_on;

        // The open successor election (CLK-01 structural: the next election
        // exists from the moment the prior certifies).
        $successor = Election::query()
            ->where('legislature_id', $legislature->id)
            ->whereNotIn('status', [Election::STATUS_FINAL, Election::STATUS_CANCELLED])
            ->orderByDesc('created_at')
            ->first(['id']);

        return [
            'id'               => (string) $legislature->id,
            'name'             => ($legislature->jurisdiction?->name ?? 'Unknown') . ' legislature',
            'jurisdiction'     => [
                'id'   => $jid,
                'name' => $legislature->jurisdiction?->name,
                'slug' => $legislature->jurisdiction?->slug,
            ],
            'status'           => $legislature->status,
            'mode'             => $bicameral ? 'bicameral' : 'unicameral',
            'seats'            => (int) $legislature->total_seats,
            'serving'          => $serving,
            'quorum'           => $serving > 0 ? ConstitutionalValidator::quorum($serving) : null,
            'supermajority'    => $serving > 0 ? ConstitutionalValidator::supermajority($serving, $numerator, $denominator) : null,
            'by_kind'          => $byKind,
            'term'             => [
                'ends_on'        => $termEndsOn?->toDateString(),
                'days_remaining' => $termEndsOn !== null
                    ? max(0, (int) CarbonImmutable::now()->startOfDay()->diffInDays($termEndsOn, false))
                    : null,
                'election_id'    => $successor?->id !== null ? (string) $successor->id : null,
            ],
            'next_session_due' => $legislature->next_meeting_due_by?->toDateString(),
        ];
    }

    /** The viewer's CURRENT member row in this chamber, if any. */
    protected function viewerMember(Legislature $legislature, ?User $user): ?LegislatureMember
    {
        if ($user === null) {
            return null;
        }

        return LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->where('user_id', (string) $user->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->first();
    }

    protected function memberDisplayName(?LegislatureMember $member): string
    {
        return $member?->user?->display_name ?: ($member?->user?->name ?? 'Unknown member');
    }
}
