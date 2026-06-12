<?php

namespace App\Services\Legislature;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Committee;
use App\Models\CommitteeSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\VoteCast;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\VoteCountingService;
use Illuminate\Support\Facades\DB;

/**
 * Committee lifecycle (chamber ops §C): creation kind-split (Art. V §3
 * mirror), creation-on-adoption, chair/alternate whole-house RCV
 * (F-LEG-011), vacancy refill eligibility (WF-LEG-13).
 *
 * Bicameral committees mirror the chamber-kind ratio by largest-remainder
 * apportionment of `seats` over serving type_a : type_b, with each kind
 * ≥ 1 whenever seats ≥ 2 (per-kind dual agreement must never be vacuous
 * at committee stage — q-ledger #q7). San Marino (32a:9b), 5 seats →
 * 4a + 1b.
 *
 * Chair ballots are whole-house RCV chamber votes (votable = the
 * committee row); the engine auto-closes at full participation and its
 * votable-effect dispatch routes back to resolveChairVote() — chair at
 * majority-of-continuing (PROTECTED countRcv), alternate = top runner-up
 * by sequential exclusion (the Phase B deriveAdvisors doctrine).
 */
class CommitteeService
{
    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly PublicRecordService $records,
        private readonly VoteCountingService $counter,
        private readonly AuditService $audit,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // Kind split (pure — pinned DB-free)
    // =========================================================================

    /**
     * Largest-remainder apportionment of committee seats over the serving
     * kind ratio, floored at 1 per kind whenever seats ≥ 2.
     *
     * @return array{type_a: int, type_b: int}
     */
    public static function kindSplit(int $seats, int $servingA, int $servingB): array
    {
        $total = $servingA + $servingB;

        if ($seats < 1 || $servingA < 1 || $servingB < 1) {
            throw new ConstitutionalViolation(
                'Committee kind split requires seats ≥ 1 and serving members of both kinds.',
                'Art. V §3'
            );
        }

        $quotaA = $seats * $servingA / $total;
        $quotaB = $seats * $servingB / $total;

        $a = (int) floor($quotaA);
        $b = (int) floor($quotaB);

        if ($a + $b < $seats) {
            // Exactly one seat remains (two bins): largest remainder takes
            // it; ties resolve to the larger kind, then type_a (deterministic).
            $remainderA = $quotaA - $a;
            $remainderB = $quotaB - $b;

            if ($remainderA > $remainderB || ($remainderA === $remainderB && $servingA >= $servingB)) {
                $a++;
            } else {
                $b++;
            }
        }

        // Each kind ≥ 1 whenever the committee has 2+ seats (Art. V §3).
        if ($seats >= 2) {
            if ($a === 0) {
                $a = 1;
                $b = $seats - 1;
            } elseif ($b === 0) {
                $b = 1;
                $a = $seats - 1;
            }
        }

        return ['type_a' => $a, 'type_b' => $b];
    }

    // =========================================================================
    // Creation (F-LEG-009 → supermajority vote → adoption effect)
    // =========================================================================

    /**
     * F-LEG-009 filing: validate, store the proposal, open the
     * supermajority chamber vote (vote_type committee_create). The
     * committee row is created only on adoption — a failed vote leaves a
     * rejected proposal, never a half-born institution.
     */
    public function proposeCreation(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $name,
        ?string $purpose,
        int $seats,
    ): array {
        if (trim($name) === '') {
            throw new ConstitutionalViolation('A committee needs a name.', 'CGA Forms Catalog (F-LEG-009)');
        }

        if ($seats < 1) {
            throw new ConstitutionalViolation('A committee carries at least one seat.', 'CGA Forms Catalog (F-LEG-009)');
        }

        $bicameral = (int) $legislature->type_b_seats > 0;
        $split     = null;

        if ($bicameral) {
            [$servingA, $servingB] = $this->servingByKind($legislature);
            $split = self::kindSplit($seats, $servingA, $servingB);

            ConstitutionalValidator::assertCommitteeKindSplit($seats, $split['type_a'], $split['type_b'], true);
        }

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_COMMITTEE_CREATION,
            'payload'               => [
                'name'         => $name,
                'purpose'      => $purpose,
                'seats'        => $seats,
                'type_a_seats' => $split['type_a'] ?? null,
                'type_b_seats' => $split['type_b'] ?? null,
            ],
            'proposed_by_member_id' => $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'committee_create',
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return [
            'proposal_id' => (string) $proposal->id,
            'vote_id'     => (string) $vote->id,
            'kind_split'  => $split,
        ];
    }

    /**
     * Adoption effect (routed by ChamberActService::resolveProposalVote
     * inside the closing transaction).
     */
    public function createFromProposal(ChamberVoteProposal $proposal): Committee
    {
        $payload = (array) $proposal->payload;

        $committee = Committee::create([
            'legislature_id'     => $proposal->legislature_id,
            'name'               => (string) $payload['name'],
            'purpose'            => $payload['purpose'] ?? null,
            'seats'              => (int) $payload['seats'],
            'type_a_seats'       => $payload['type_a_seats'] ?? null,
            'type_b_seats'       => $payload['type_b_seats'] ?? null,
            'created_by_vote_id' => $proposal->vote_id,
            'status'             => Committee::STATUS_CREATED,
        ]);

        $legislature = $proposal->legislature()->firstOrFail();

        $this->records->publish(
            kind: 'act',
            title: sprintf('Committee created: %s (%d seats)', $committee->name, $committee->seats),
            body: $payload['purpose'] ?? null,
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-009',
                'subject_type'    => 'committees',
                'subject_id'      => (string) $committee->id,
            ],
        );

        return $committee;
    }

    // =========================================================================
    // Chairs + alternates (F-LEG-011)
    // =========================================================================

    /**
     * Open the chair balloting for one seated committee: whole-house RCV
     * (all serving cast, Speaker included — constitutive election),
     * candidates = the committee's seated members (R-12 requires R-11).
     */
    public function openChairBallot(Committee $committee, ?LegislatureMember $opener = null): ChamberVote
    {
        if ($committee->status !== Committee::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'Chair elections run on SEATED committees — run the F-SPK-005 assignment first.',
                'CGA Forms Catalog (F-LEG-011)'
            );
        }

        if ($this->openChairBallotFor($committee) !== null) {
            throw new ConstitutionalViolation(
                'A chair balloting is already open for this committee.',
                'CGA Forms Catalog (F-LEG-011)'
            );
        }

        return $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $committee->legislature_id,
            voteType: 'committee_chair',
            votable: $committee,
            opener: $opener,
        );
    }

    public function openChairBallotFor(Committee $committee): ?ChamberVote
    {
        return ChamberVote::query()
            ->where('votable_type', 'committee')
            ->where('votable_id', (string) $committee->id)
            ->where('vote_type', 'committee_chair')
            ->where('status', ChamberVote::STATUS_OPEN)
            ->first();
    }

    /**
     * Record one member's F-LEG-011 cast (rankings ⊆ the committee's
     * seated members). The engine auto-closes at full participation; its
     * votable dispatch routes to resolveChairVote().
     *
     * @return array{vote_id: string, closed: bool,
     *               chair_member_id: string|null, alternate_member_id: string|null}
     */
    public function recordChairCast(
        ChamberVote $vote,
        Committee $committee,
        LegislatureMember $member,
        array $rankings,
        ?string $explanation = null,
    ): array {
        $candidates = $this->seatedMemberIds($committee);

        $unknown = array_diff(array_map('strval', $rankings), $candidates);

        if ($rankings === [] || $unknown !== []) {
            throw new ConstitutionalViolation(
                'Chair ballot rankings must name seated members of this committee (R-12 requires R-11).',
                'CGA Roles & Forms Chart (R-12)'
            );
        }

        $this->votes->cast(
            vote: $vote,
            member: $member,
            value: null,
            rankings: $rankings,
            explanation: $explanation,
            viaForm: 'F-LEG-011',
        );

        $vote->refresh();
        $committee->refresh();

        return [
            'vote_id'             => (string) $vote->id,
            'closed'              => $vote->status === ChamberVote::STATUS_CLOSED,
            'chair_member_id'     => $committee->chair_member_id !== null ? (string) $committee->chair_member_id : null,
            'alternate_member_id' => $committee->alternate_member_id !== null ? (string) $committee->alternate_member_id : null,
        ];
    }

    /**
     * Vote-close side-effect (ChamberVoteService dispatch, same
     * transaction): the chair is the engine's RCV winner (majority of
     * continuing); the alternate is derived by sequential exclusion over
     * the SAME public casts — deterministic, recomputable by any auditor.
     */
    public function resolveChairVote(ChamberVote $vote, string $outcome): void
    {
        if ($vote->vote_type !== 'committee_chair') {
            return; // other committee-votable votes (e.g. seat fills) resolve elsewhere
        }

        $committee = Committee::query()->find($vote->votable_id);

        if ($committee === null || $outcome !== ChamberVote::OUTCOME_ADOPTED) {
            return; // failed balloting = re-ballot posture (WF-LEG-02 analog)
        }

        $chairId = $vote->rcv_record['winner_member_id'] ?? null;

        if ($chairId === null) {
            return;
        }

        $candidates = $this->seatedMemberIds($committee);

        $rankings = VoteCast::query()
            ->where('vote_id', $vote->id)
            ->get()
            ->map(fn (VoteCast $cast) => array_values(array_map('strval', $cast->rankings ?? [])))
            ->filter(fn (array $r) => $r !== [])
            ->values()
            ->all();

        $alternateId = null;

        if (count($candidates) > 1) {
            $alternateRun = $this->counter->countRcv(new CountInput(
                $candidates,
                1,
                BallotSet::fromRankings($rankings),
                [$chairId],
                hash('sha256', (string) $vote->id),
            ));

            $alternateId = $alternateRun->elected[0]['candidacy_id'] ?? null;
        }

        $legislature = $committee->legislature()->firstOrFail();

        $committee->forceFill([
            'chair_member_id'     => $chairId,
            'alternate_member_id' => $alternateId,
        ])->save();

        $this->records->publish(
            kind: 'certification',
            title: sprintf('Committee chair elected: %s', $committee->name),
            body: sprintf(
                'Chair member %s; alternate %s (whole-house ranked-choice ballot, F-LEG-011).',
                $chairId,
                $alternateId ?? '— none derivable'
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-011',
                'subject_type'    => 'committees',
                'subject_id'      => (string) $committee->id,
            ],
        );

        $this->audit->append(
            module: 'legislature',
            event: 'committee.chair_elected',
            payload: [
                'committee_id'        => (string) $committee->id,
                'vote_id'             => (string) $vote->id,
                'chair_member_id'     => $chairId,
                'alternate_member_id' => $alternateId,
            ],
            ref: 'F-LEG-011',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );

        // R-12 / R-13 derive from the chair/alternate pointers.
        foreach (array_filter([$chairId, $alternateId]) as $memberId) {
            $userId = LegislatureMember::query()->whereKey($memberId)->value('user_id');

            if ($userId !== null) {
                $this->roles->flushUser((string) $userId);
            }
        }
    }

    // =========================================================================
    // Refill eligibility (WF-LEG-13) — proportion-safe
    // =========================================================================

    /**
     * Candidates for a vacated committee seat: members of the SAME seat
     * kind whose current live placement count is at the chamber minimum —
     * prevents placement concentration, which is what "preserving
     * proportion" honestly means with no faction layer (Art. II §4 · as
     * implemented).
     *
     * @return list<string> member ids
     */
    public function refillCandidates(Committee $committee, ?string $seatKind): array
    {
        $members = LegislatureMember::query()
            ->where('legislature_id', $committee->legislature_id)
            ->current()
            ->when($seatKind !== null, fn ($q) => $q->where('seat_type', $seatKind === 'type_b' ? 'b' : 'a'))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($members === []) {
            return [];
        }

        $counts = DB::table('committee_seats')
            ->join('committees', 'committees.id', '=', 'committee_seats.committee_id')
            ->whereIn('committee_seats.member_id', $members)
            ->whereNull('committee_seats.vacated_at')
            ->where('committees.status', '!=', Committee::STATUS_DISSOLVED)
            ->groupBy('committee_seats.member_id')
            ->selectRaw('committee_seats.member_id, count(*) as n')
            ->pluck('n', 'member_id');

        $byCount = [];
        foreach ($members as $memberId) {
            $byCount[$memberId] = (int) ($counts[$memberId] ?? 0);
        }

        $min = min($byCount);

        // Already-sitting members of THIS committee are not candidates.
        $sitting = $this->seatedMemberIds($committee);

        return array_values(array_filter(
            array_keys(array_filter($byCount, fn ($n) => $n === $min)),
            fn ($memberId) => ! in_array($memberId, $sitting, true)
        ));
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return list<string> live-seat member ids of the committee */
    public function seatedMemberIds(Committee $committee): array
    {
        return CommitteeSeat::query()
            ->where('committee_id', $committee->id)
            ->live()
            ->pluck('member_id')
            ->map(fn ($id) => (string) $id)
            ->sort()
            ->values()
            ->all();
    }

    /** @return array{0: int, 1: int} serving counts by kind (a, b) */
    private function servingByKind(Legislature $legislature): array
    {
        $rows = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->current()
            ->selectRaw('seat_type, count(*) as n')
            ->groupBy('seat_type')
            ->pluck('n', 'seat_type');

        return [(int) ($rows['a'] ?? 0), (int) ($rows['b'] ?? 0)];
    }
}
