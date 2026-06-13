<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Models\OrgOwnershipStake;
use App\Services\ChamberVoteService;
use App\Services\EnactmentService;
use App\Services\PublicRecordService;
use Illuminate\Support\Str;

/**
 * D-O6 (PHASE_D_DESIGN_organizations §D.2) — F-LEG-019 Common Good
 * Corporation Creation Act (Art. III §5: CGCs are LEGISLATURE-created;
 * self-registration of type common_good_corp is validator-rejected).
 *
 * ChamberActService pattern: proposal (kind cgc_creation) → chamber vote
 * under `procedural_motion` (REGISTRY GAP, flagged: the 33-row registry
 * has no CGC-creation key; catalog threshold unstated → ordinary
 * majority of all serving, MANIFEST §8 owner ruling) → adoption creates
 * the law (creation_act), the org row, the jurisdiction's 100% stake,
 * the governor-side board, the GENESIS IP dedication ("all existing and
 * future IP — Art. III §5"), and arms the co-determination watchers.
 *
 * Regulated identically to private peers thereafter (hardened: org-module
 * services branch on is_cgc only at oversight/IP/conversion/dissolution —
 * pinned by CgcIpPublicDomainTest).
 *
 * [COORD-EXEC] Governor seats are filled by the F-EXE-001 → F-LEG-020
 * (`bog_consent`) appointment pipeline — the exec builder's scope.
 */
class CgcService
{
    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly OrgOwnershipService $ownership,
        private readonly CgcIpRegisterService $ipRegister,
        private readonly CoDeterminationService $coDetermination,
    ) {
    }

    /**
     * F-LEG-019 — file the creation act (proposal + ordinary-majority
     * chamber vote).
     *
     * @return array{proposal_id: string, vote_id: string}
     */
    public function proposeCreation(Legislature $legislature, LegislatureMember $proposer, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '' || trim((string) ($payload['charter'] ?? '')) === '') {
            throw new ConstitutionalViolation(
                'A CGC creation act requires a name and a charter text.',
                'CGA Forms Catalog (F-LEG-019)'
            );
        }

        $ownerSeats = (int) ($payload['owner_seats'] ?? 0);

        if ($ownerSeats < 1 || $ownerSeats > 99) {
            throw new ConstitutionalViolation(
                "Governor seats must lie in [1, 99] (got {$ownerSeats}).",
                'Art. III §6 · as implemented'
            );
        }

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => (string) $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_CGC_CREATION,
            'payload'               => [
                'name'                   => $name,
                'charter'                => (string) $payload['charter'],
                'goods_services'         => isset($payload['goods_services']) ? (string) $payload['goods_services'] : null,
                'oversight_executive_id' => isset($payload['oversight_executive_id']) ? (string) $payload['oversight_executive_id'] : null,
                'owner_seats'            => $ownerSeats,
            ],
            'proposed_by_member_id' => (string) $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'procedural_motion', // registry gap — ordinary majority of all serving (owner ruling)
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /**
     * Adoption effect (ChamberActService dispatch, same transaction as
     * the closing cast).
     *
     * @return array{0: string, 1: string} [result_type, result_id]
     */
    public function adoptCreation(ChamberVote $vote, ChamberVoteProposal $proposal): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;

        $law = $this->enactments->enactDirect(
            $legislature,
            \App\Models\Law::KIND_CREATION_ACT,
            'CGC Creation Act — ' . (string) $payload['name'],
            (string) $payload['charter'],
            $vote,
        );

        $org = Organization::create([
            'jurisdiction_id'           => (string) $legislature->jurisdiction_id,
            'type'                      => Organization::TYPE_COMMON_GOOD_CORP,
            'structure'                 => null, // public charter — never a private structure
            'name'                      => (string) $payload['name'],
            'slug'                      => $this->uniqueSlug((string) $legislature->jurisdiction_id, (string) $payload['name']),
            'purpose'                   => $payload['goods_services'] ?? null,
            'is_cgc'                    => true,
            'ownership_type'            => 'public',
            'ip_is_public_domain'       => true,
            'status'                    => Organization::STATUS_ACTIVE,
            'is_active'                 => true,
            'is_registered'             => true,
            'registered_at'             => now(),
            'registered_via_form'       => 'F-LEG-019',
            'created_by_legislature_id' => (string) $legislature->id,
            'created_by_law_id'         => (string) $law->id,
            'overseen_by_executive_id'  => $payload['oversight_executive_id'] ?? null,
            'worker_count'              => 0,
        ]);

        // The jurisdiction stands where shareholders would: one stake,
        // 100% (owner ruling #12).
        $this->ownership->openStake(
            $org,
            OrgOwnershipStake::HOLDER_JURISDICTIONS,
            (string) $legislature->jurisdiction_id,
            100.0,
            OrgOwnershipStake::VIA_FOUNDING,
        );

        // Governor-side board; seats fill via F-EXE-001 → F-LEG-020.
        $board = Board::create([
            'boardable_type'    => Board::BOARDABLE_ORGANIZATIONS,
            'boardable_id'      => (string) $org->id,
            'owner_seats'       => (int) $payload['owner_seats'],
            'worker_seats'      => 0,
            'worker_headcount'  => 0,
            'composition_valid' => true,
            'status'            => Board::STATUS_FORMING,
        ]);

        for ($no = 1; $no <= (int) $payload['owner_seats']; $no++) {
            BoardSeat::create([
                'board_id'   => (string) $board->id,
                'seat_class' => BoardSeat::CLASS_GOVERNOR,
                'seat_no'    => $no,
                'status'     => BoardSeat::STATUS_VACANT,
            ]);
        }

        $org->forceFill(['board_id' => (string) $board->id])->save();

        // GENESIS dedication — all existing and future IP (Art. III §5).
        $this->ipRegister->dedicate(
            $org,
            'All existing and future intellectual property of ' . $org->name,
            'other',
            'Genesis dedication at chartering: every work this CGC produces is public domain, irreversibly (Art. III §5).',
            'F-LEG-019',
        );

        // Co-determination watchers armed at stand-up (CLK-13/14).
        $this->coDetermination->armWatchers(
            Board::BOARDABLE_ORGANIZATIONS,
            (string) $org->id,
            (string) $legislature->jurisdiction_id,
        );

        $record = $this->records->publish(
            kind: 'act',
            title: "Common Good Corporation chartered — {$org->name}",
            body: sprintf(
                '%s chartered by act of legislature %s (law %s). Public ownership (jurisdiction stake 100%%), '
                . '%d governor seat(s) awaiting nomination and consent, IP permanently public domain (Art. III §5).',
                $org->name,
                (string) $legislature->id,
                (string) $law->id,
                (int) $payload['owner_seats']
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-019',
                'subject_type'    => 'organizations',
                'subject_id'      => (string) $org->id,
            ],
        );

        $org->forceFill(['registration_record_id' => (string) $record->id])->save();

        return ['organizations', (string) $org->id];
    }

    private function uniqueSlug(string $jurisdictionId, string $name): string
    {
        $base = Str::slug($name) ?: 'cgc';
        $slug = $base;
        $n    = 1;

        while (Organization::withTrashed()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }
}
