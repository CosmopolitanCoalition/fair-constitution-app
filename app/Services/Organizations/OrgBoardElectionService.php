<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Services\AuditService;
use App\Services\ElectionLifecycleService;
use App\Services\SettingsResolver;

/**
 * D-O5 (PHASE_D_DESIGN_organizations §C.2) — board election lifecycle for
 * kinds org_board_owner / org_board_worker, REUSING the Phase B election
 * machinery end to end (ESM-03 lifecycle, two-phase open ballot, CLK-18/
 * CLK-21 structure, TabulateElectionJob → the PROTECTED
 * VoteCountingService::countStv — never forked).
 *
 * Org elections are administered by the org agent under engine
 * supervision, NOT the jurisdiction's I-ELB (flagged decision: the
 * catalog gives F-ORG-003/004 to R-23; I-ELB's constitutional remit is
 * public office) — `election_board_id` stays NULL, `legislature_id`
 * NULL, `board_id` set (D-O8).
 *
 * Races carry electorate_type owners/workers; seats 1–9 per race (a
 * class needing >9 splits into grouped races of 5–9, the Art. II §8
 * grouping discipline — mockup max is 9).
 */
class OrgBoardElectionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ElectionLifecycleService $lifecycle,
        private readonly SettingsResolver $settings,
    ) {
    }

    /**
     * Open the owner-track election (F-ORG-003 'open_owner_election',
     * R-23) for the vacant owner_elected seats.
     */
    public function openOwnerElection(Board $board): Election
    {
        $vacant = $board->seats()
            ->where('seat_class', BoardSeat::CLASS_OWNER_ELECTED)
            ->where('status', BoardSeat::STATUS_VACANT)
            ->count();

        if ($vacant < 1) {
            throw new ConstitutionalViolation(
                'No vacant owner-elected seats — nothing to elect.',
                'CGA Forms Catalog (F-ORG-003)'
            );
        }

        return $this->open($board, Election::KIND_ORG_BOARD_OWNER, ElectionRace::ELECTORATE_OWNERS, $vacant);
    }

    /**
     * Open the worker-track election (F-ORG-004 'open_worker_election' —
     * R-23 OR the WF-ORG-04 system auto-trigger; R-23 absence can never
     * stall a constitutionally-required seat).
     */
    public function openWorkerElection(Board $board, ?int $seats = null): Election
    {
        $vacant = $board->seats()
            ->where('seat_class', BoardSeat::CLASS_WORKER_ELECTED)
            ->where('status', BoardSeat::STATUS_VACANT)
            ->count();

        $seats ??= $vacant;

        if ($vacant < 1 || $seats < 1) {
            throw new ConstitutionalViolation(
                'No vacant worker-elected seats — nothing to elect.',
                'CGA Forms Catalog (F-ORG-004)'
            );
        }

        return $this->open($board, Election::KIND_ORG_BOARD_WORKER, ElectionRace::ELECTORATE_WORKERS, min($seats, $vacant));
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function open(Board $board, string $kind, string $electorate, int $seats): Election
    {
        $existing = Election::query()
            ->where('board_id', $board->id)
            ->where('kind', $kind)
            ->whereNotIn('status', [Election::STATUS_FINAL, Election::STATUS_CANCELLED])
            ->first();

        if ($existing !== null) {
            throw new ConstitutionalViolation(
                "An open {$kind} election already exists for this board.",
                'CGA Forms Catalog (F-ORG-003/004)'
            );
        }

        $jurisdictionId = $board->jurisdictionId();

        if ($jurisdictionId === null) {
            throw new ConstitutionalViolation('The board resolves to no jurisdiction.', 'WF-ORG-04');
        }

        $dates      = $this->lifecycle->defaultDates($jurisdictionId);
        $multiplier = max(1, $this->settings->resolveInt($jurisdictionId, 'finalist_multiplier', 3));

        $election = Election::create([
            'jurisdiction_id'    => $jurisdictionId,
            'legislature_id'     => null,
            'kind'               => $kind,
            'status'             => Election::STATUS_SCHEDULED,
            'trigger'            => 'scheduled',
            'voting_method'      => $this->votingMethod($jurisdictionId),
            'election_board_id'  => null, // org-administered under engine supervision
            'board_id'           => (string) $board->id,
            'approval_opens_at'  => $dates['approval_opens_at'],
            'finalist_cutoff_at' => $dates['finalist_cutoff_at'],
            'ranked_opens_at'    => $dates['ranked_opens_at'],
            'ranked_closes_at'   => $dates['ranked_closes_at'],
        ]);

        // Grouped races of ≤ 9 (Art. II §8 grouping discipline — judicial
        // precedent; mockup max is 9).
        $races     = [];
        $remaining = $seats;

        while ($remaining > 0) {
            $raceSeats = min(9, $remaining);
            $remaining -= $raceSeats;

            $races[] = ElectionRace::create([
                'election_id'     => (string) $election->id,
                'district_id'     => null,
                'jurisdiction_id' => $jurisdictionId,
                'seat_kind'       => ElectionRace::SEAT_KIND_TYPE_A, // generic at-large posture; election.kind disambiguates (§C.2 "add nothing")
                'seats'           => $raceSeats,
                'finalist_count'  => $multiplier * $raceSeats,
                'electorate_type' => $electorate,
                'status'          => Election::STATUS_SCHEDULED,
            ]);
        }

        $this->audit->append(
            module: 'organizations',
            event: 'board_election.opened',
            payload: [
                'election_id' => (string) $election->id,
                'kind'        => $kind,
                'board_id'    => (string) $board->id,
                'electorate'  => $electorate,
                'seats'       => $seats,
                'races'       => array_map(fn (ElectionRace $r) => [
                    'race_id'        => (string) $r->id,
                    'seats'          => (int) $r->seats,
                    'finalist_count' => (int) $r->finalist_count,
                ], $races),
            ],
            ref: 'WF-ORG-04',
            jurisdictionId: $jurisdictionId,
        );

        // Same ESM-03 authority + phase timers as every election.
        $this->lifecycle->openApproval($election);
        $this->lifecycle->armPhaseTimers($election);

        return $election->refresh();
    }

    private function votingMethod(string $jurisdictionId): string
    {
        $method = $this->settings->resolve($jurisdictionId, 'voting_method');

        return is_string($method) && $method !== '' ? $method : 'stv_droop';
    }
}
