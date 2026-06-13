<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Jobs\Organizations\RecomputeWorkerHeadcountJob;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Models\OrgConversion;
use App\Models\OrgOwnershipStake;
use App\Models\OrgWorker;
use App\Models\Term;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\EnactmentService;
use App\Services\PublicRecordService;
use App\Services\RoleService;

/**
 * D-O6 (PHASE_D_DESIGN_organizations §D.2) — public↔private conversions:
 *
 *  - F-ORG-006 (R-23/R-09): a conversion REQUEST routed to the
 *    legislature (request ≠ act — both directions are legislature-only).
 *  - F-LEG-026 Monopoly Acquisition Vote (WF-ORG-07): proposal kind
 *    monopoly_acquisition under `procedural_motion` (ordinary majority of
 *    all serving — owner ruling #13) → org_conversions row
 *    (private_to_cgc / monopoly_acquisition / compensation_pending) +
 *    law. Completion: compensation ≥ the recorded fair-market floor
 *    (HARDENED, Art. III §5 — a below-floor filing is rejected with the
 *    verbatim citation, on the chain), compensation public record, stakes
 *    closed (holders paid) + jurisdiction stake opened, org becomes CGC,
 *    bulk IP dedication, founding-governor offers to the prior board,
 *    workforce co-determination recheck.
 *  - F-LEG-027 CGC Reorganization/Sale Vote (WF-ORG-08/09): branches
 *    reorganize / dissolve / sell. The sell branch NEVER touches
 *    cgc_ip_register — existing dedications are irreversible; new works
 *    after privatization follow private rules.
 */
class OrgConversionService
{
    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly OrgOwnershipService $ownership,
        private readonly OrgRegistryService $registry,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // F-ORG-006 — conversion request (routes to the legislature)
    // =========================================================================

    public function request(Organization $org, User $actor, string $direction, ?string $rationale): OrgConversion
    {
        if (! in_array($direction, [OrgConversion::DIRECTION_PRIVATE_TO_CGC, OrgConversion::DIRECTION_CGC_TO_PRIVATE], true)) {
            throw new ConstitutionalViolation("Unknown conversion direction [{$direction}].", 'CGA Forms Catalog (F-ORG-006)');
        }

        if ($direction === OrgConversion::DIRECTION_CGC_TO_PRIVATE && ! $org->is_cgc) {
            throw new ConstitutionalViolation('Only a CGC can be sold to private ownership.', 'Art. III §5');
        }

        if ($direction === OrgConversion::DIRECTION_PRIVATE_TO_CGC && $org->is_cgc) {
            throw new ConstitutionalViolation('This organization is already a CGC.', 'Art. III §5');
        }

        return OrgConversion::create([
            'organization_id'   => (string) $org->id,
            'direction'         => $direction,
            'via'               => $direction === OrgConversion::DIRECTION_PRIVATE_TO_CGC
                ? OrgConversion::VIA_MUTUAL
                : OrgConversion::VIA_CGC_SALE,
            'fair_market_basis' => $rationale,
            'status'            => OrgConversion::STATUS_PROPOSED,
        ]);
    }

    // =========================================================================
    // F-LEG-026 — monopoly acquisition (WF-ORG-07)
    // =========================================================================

    /** @return array{proposal_id: string, vote_id: string} */
    public function proposeMonopolyAcquisition(
        Legislature $legislature,
        LegislatureMember $proposer,
        array $payload,
    ): array {
        $org = Organization::query()->find($payload['organization_id'] ?? null);

        if ($org === null || $org->is_cgc) {
            throw new ConstitutionalViolation(
                'F-LEG-026 targets a private organization (a CGC cannot be acquired).',
                'CGA Forms Catalog (F-LEG-026)'
            );
        }

        $floor = $payload['fair_market_floor'] ?? null;

        if (! is_numeric($floor) || (float) $floor <= 0
            || trim((string) ($payload['fair_market_basis'] ?? '')) === '') {
            // The validator pre-checks this too (pre-vote, rejected on the
            // chain); the service is the backstop.
            throw new ConstitutionalViolation(
                'A monopoly acquisition records the fair-market floor and its published valuation basis '
                . 'BEFORE any vote — fair-market compensation is the constitutional condition.',
                'Art. III §5'
            );
        }

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => (string) $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_MONOPOLY_ACQUISITION,
            'payload'               => [
                'organization_id'   => (string) $org->id,
                'finding'           => (string) ($payload['finding'] ?? 'monopolistic_control'),
                'fair_market_floor' => (float) $floor,
                'fair_market_basis' => (string) $payload['fair_market_basis'],
            ],
            'proposed_by_member_id' => (string) $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'procedural_motion', // ordinary majority of all serving — owner ruling #13
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /** @return array{0: string, 1: string} adoption effect (vote-close dispatch) */
    public function adoptMonopolyAcquisition(ChamberVote $vote, ChamberVoteProposal $proposal): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;
        $org         = Organization::query()->findOrFail($payload['organization_id']);

        $law = $this->enactments->enactDirect(
            $legislature,
            Law::KIND_ORDINARY,
            'Monopoly Acquisition Act — ' . $org->name,
            sprintf(
                "Finding: %s. The legislature acquires %s for the public (Art. III §5). Fair-market floor: %s.\n\nValuation basis: %s",
                (string) $payload['finding'],
                $org->name,
                number_format((float) $payload['fair_market_floor'], 2),
                (string) $payload['fair_market_basis']
            ),
            $vote,
        );

        $conversion = OrgConversion::create([
            'organization_id'     => (string) $org->id,
            'direction'           => OrgConversion::DIRECTION_PRIVATE_TO_CGC,
            'via'                 => OrgConversion::VIA_MONOPOLY_ACQUISITION,
            'proposal_id'         => (string) $proposal->id,
            'authorizing_vote_id' => (string) $vote->id,
            'authorizing_law_id'  => (string) $law->id,
            'fair_market_floor'   => (float) $payload['fair_market_floor'],
            'fair_market_basis'   => (string) $payload['fair_market_basis'],
            'status'              => OrgConversion::STATUS_COMPENSATION_PENDING,
        ]);

        return ['org_conversions', (string) $conversion->id];
    }

    /**
     * F-LEG-026 'record_compensation' — THE fair-market gate (hardened,
     * Art. III §5): below-floor compensation is engine-blocked with the
     * citation; the engine records the rejection on the chain.
     *
     * @return array<string, mixed>
     */
    public function recordCompensationAndComplete(OrgConversion $conversion, float $compensation, ?User $actor): array
    {
        if ($conversion->status !== OrgConversion::STATUS_COMPENSATION_PENDING) {
            throw new ConstitutionalViolation(
                "Conversion [{$conversion->id}] is not awaiting compensation (status: {$conversion->status}).",
                'CGA Forms Catalog (F-LEG-026)'
            );
        }

        if ($conversion->authorizing_law_id === null) {
            throw new ConstitutionalViolation(
                'A conversion proceeds only on an enacted authorizing law — CGCs are never self-converted.',
                'Art. III §5'
            );
        }

        // HARDENED (the verbatim 422 of the exit path).
        ConstitutionalValidator::assertFairMarketCompensation(
            (float) $conversion->fair_market_floor,
            $compensation,
        );

        $org = Organization::query()->findOrFail($conversion->organization_id);

        // Compensation is a recorded constitutional fact (public record +
        // chain), not a funds transfer (deferral §E.4.7).
        $record = $this->records->publish(
            kind: 'act',
            title: "Acquisition compensation recorded — {$org->name}",
            body: sprintf(
                'Compensation of %s recorded for the acquisition of %s (fair-market floor %s — Art. III §5). '
                . 'Prior owners are paid at or above fair market value.',
                number_format($compensation, 2),
                $org->name,
                number_format((float) $conversion->fair_market_floor, 2)
            ),
            attrs: [
                'actor_user_id'   => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                'jurisdiction_id' => (string) $org->jurisdiction_id,
                'via_form'        => 'F-LEG-026',
                'via_workflow'    => 'WF-ORG-07',
                'subject_type'    => 'org_conversions',
                'subject_id'      => (string) $conversion->id,
            ],
        );

        $conversion->forceFill([
            'compensation'           => $compensation,
            'compensation_record_id' => (string) $record->id,
            'status'                 => OrgConversion::STATUS_CONVERTING,
        ])->save();

        // Founding-governor offers to every seated prior-board member
        // (accept → F-EXE-001/F-LEG-020 pipeline [COORD-EXEC]; decline →
        // the ordinary WF-EXE-05 analog).
        $offers     = [];
        $priorBoard = $org->board_id !== null ? Board::query()->find($org->board_id) : null;

        if ($priorBoard !== null) {
            foreach ($priorBoard->seats()->seated()->get() as $seat) {
                if ($seat->holder_user_id !== null) {
                    $offers[] = [
                        'user_id'        => (string) $seat->holder_user_id,
                        'offered_at'     => now()->toIso8601String(),
                        'response'       => 'pending',
                        'appointment_id' => null,
                    ];
                }

                // Prior owner-side seats end with the acquisition; the BoG
                // replaces them. Worker-elected seats persist (Art. III §6
                // applies identically before and after).
                if ($seat->seat_class === BoardSeat::CLASS_OWNER_ELECTED) {
                    $seat->forceFill(['status' => BoardSeat::STATUS_TERM_ENDED, 'is_chair' => false])->save();

                    if ($seat->term_id !== null) {
                        Term::query()->whereKey($seat->term_id)->update(['status' => Term::STATUS_COMPLETED, 'updated_at' => now()]);
                    }

                    // The seat class flips to governor for the BoG era.
                    BoardSeat::create([
                        'board_id'   => (string) $priorBoard->id,
                        'seat_class' => BoardSeat::CLASS_GOVERNOR,
                        'seat_no'    => (int) $priorBoard->seats()->max('seat_no') + 1,
                        'status'     => BoardSeat::STATUS_VACANT,
                    ]);
                }
            }
        }

        // Stakes: holders paid, the jurisdiction takes 100%.
        $this->ownership->closeAllStakes($org);
        $this->ownership->openStake(
            $org,
            OrgOwnershipStake::HOLDER_JURISDICTIONS,
            (string) $org->jurisdiction_id,
            100.0,
            OrgOwnershipStake::VIA_CONVERSION,
        );

        // The org becomes a CGC.
        $org->forceFill([
            'type'                => Organization::TYPE_COMMON_GOOD_CORP,
            'is_cgc'              => true,
            'ownership_type'      => 'public',
            'structure'           => null,
            'ip_is_public_domain' => true,
            'created_by_law_id'   => (string) $conversion->authorizing_law_id,
        ])->save();
        $this->registry->setStatus($org, Organization::STATUS_ACTIVE);

        // Bulk dedication of all acquired IP (Art. III §5).
        app(CgcIpRegisterService::class)->dedicate(
            $org,
            'All intellectual property acquired with ' . $org->name,
            'other',
            'Bulk dedication at monopoly acquisition: every acquired work enters the public domain, irreversibly.',
            'F-LEG-026',
            $actor?->getKey() !== null ? (string) $actor->getKey() : null,
        );

        $conversion->forceFill([
            'board_transition' => $offers,
            'status'           => OrgConversion::STATUS_COMPLETED,
            'completed_at'     => now(),
        ])->save();

        // Workforce co-determination recheck (WF-ORG-07 final step) —
        // queued, never synchronous.
        RecomputeWorkerHeadcountJob::dispatch(OrgWorker::EMPLOYER_ORGANIZATIONS, (string) $org->id)->afterCommit();

        $this->roles->flush();

        return [
            'conversion_id'    => (string) $conversion->id,
            'organization_id'  => (string) $org->id,
            'compensation'     => $compensation,
            'record_id'        => (string) $record->id,
            'governor_offers'  => count($offers),
        ];
    }

    // =========================================================================
    // F-LEG-027 — CGC reorganization / sale (WF-ORG-08/09)
    // =========================================================================

    /** @return array{proposal_id: string, vote_id: string} */
    public function proposeReorgSale(Legislature $legislature, LegislatureMember $proposer, array $payload): array
    {
        $org    = Organization::query()->find($payload['organization_id'] ?? null);
        $branch = (string) ($payload['branch'] ?? '');

        if ($org === null || ! $org->is_cgc) {
            throw new ConstitutionalViolation('F-LEG-027 targets a Common Good Corporation.', 'CGA Forms Catalog (F-LEG-027)');
        }

        if (! in_array($branch, ['reorganize', 'dissolve', 'sell'], true)) {
            throw new ConstitutionalViolation("Unknown F-LEG-027 branch [{$branch}].", 'CGA Forms Catalog (F-LEG-027)');
        }

        // CONSTITUTIONAL PIN (Art. III §5): a sale can never reach the IP
        // register — a payload smuggling an ip_*/reclaim key is rejected
        // pre-vote with the citation (also validator-gated pre-commit).
        foreach (array_keys($payload) as $key) {
            if (str_starts_with(strtolower((string) $key), 'ip_') || str_contains(strtolower((string) $key), 'reclaim')) {
                throw new ConstitutionalViolation(
                    'CGC public-domain dedications are irreversible — no sale or reorganization may reclaim or '
                    . 'privatize dedicated IP.',
                    'Art. III §5'
                );
            }
        }

        if ($branch === 'reorganize' && trim((string) ($payload['charter'] ?? '')) === '') {
            throw new ConstitutionalViolation('Reorganization requires the new charter text.', 'CGA Forms Catalog (F-LEG-027)');
        }

        if ($branch === 'sell') {
            if (! in_array($payload['buyer_type'] ?? null, ['users', 'organizations'], true)
                || ! is_string($payload['buyer_id'] ?? null)
                || ! is_numeric($payload['consideration'] ?? null)) {
                throw new ConstitutionalViolation(
                    'A sale names the buyer (type + id) and the recorded consideration.',
                    'CGA Forms Catalog (F-LEG-027)'
                );
            }
        }

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => (string) $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_CGC_REORG_SALE,
            'payload'               => [
                'organization_id' => (string) $org->id,
                'branch'          => $branch,
                'charter'         => isset($payload['charter']) ? (string) $payload['charter'] : null,
                'buyer_type'      => $payload['buyer_type'] ?? null,
                'buyer_id'        => $payload['buyer_id'] ?? null,
                'consideration'   => isset($payload['consideration']) ? (float) $payload['consideration'] : null,
                'structure'       => $payload['structure'] ?? null,
            ],
            'proposed_by_member_id' => (string) $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'procedural_motion', // unstated threshold → ordinary majority (flagged)
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /** @return array{0: string, 1: string} adoption effect */
    public function adoptReorgSale(ChamberVote $vote, ChamberVoteProposal $proposal): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;
        $org         = Organization::query()->findOrFail($payload['organization_id']);

        return match ((string) $payload['branch']) {
            'reorganize' => (function () use ($legislature, $payload, $org, $vote) {
                $law = $this->enactments->enactDirect(
                    $legislature,
                    Law::KIND_CHARTER,
                    'CGC Reorganization — ' . $org->name,
                    (string) $payload['charter'],
                    $vote,
                );

                $org->forceFill(['created_by_law_id' => (string) $law->id])->save();

                return ['laws', (string) $law->id];
            })(),

            'dissolve' => (function () use ($legislature, $org, $vote) {
                $law = $this->enactments->enactDirect(
                    $legislature,
                    Law::KIND_ORDINARY,
                    'CGC Dissolution Act — ' . $org->name,
                    'Wind-down of ' . $org->name . ' by legislative act (WF-ORG-10, system actor).',
                    $vote,
                );

                // CGC flag drops FIRST so the voluntary-dissolution
                // internals accept the system wind-down; dedications stand.
                $org->forceFill(['is_cgc' => false])->save();
                $this->registry->dissolve($org, null, 'Dissolved by legislative act (F-LEG-027).');
                $org->forceFill(['is_cgc' => true])->save();

                return ['laws', (string) $law->id];
            })(),

            'sell' => (function () use ($legislature, $payload, $org, $vote) {
                $law = $this->enactments->enactDirect(
                    $legislature,
                    Law::KIND_ORDINARY,
                    'CGC Sale Act — ' . $org->name,
                    sprintf(
                        'Sale of %s to %s %s at recorded consideration %s. Existing public-domain dedications are '
                        . 'IRREVERSIBLE and survive the sale (Art. III §5); works produced after privatization follow private rules.',
                        $org->name,
                        rtrim((string) $payload['buyer_type'], 's'),
                        (string) $payload['buyer_id'],
                        number_format((float) $payload['consideration'], 2)
                    ),
                    $vote,
                );

                $conversion = OrgConversion::create([
                    'organization_id'     => (string) $org->id,
                    'direction'           => OrgConversion::DIRECTION_CGC_TO_PRIVATE,
                    'via'                 => OrgConversion::VIA_CGC_SALE,
                    'proposal_id'         => (string) ($payload['proposal_id'] ?? ''),
                    'authorizing_vote_id' => (string) $vote->id,
                    'authorizing_law_id'  => (string) $law->id,
                    'compensation'        => (float) $payload['consideration'],
                    'status'              => OrgConversion::STATUS_CONVERTING,
                ]);

                // Governor seats end; an owner-elected board is provisioned
                // for the buyer era.
                if ($org->board_id !== null) {
                    $board = Board::query()->find($org->board_id);

                    foreach ($board?->seats()?->seated()?->get() ?? [] as $seat) {
                        if ($seat->seat_class === BoardSeat::CLASS_GOVERNOR) {
                            $seat->forceFill(['status' => BoardSeat::STATUS_TERM_ENDED, 'is_chair' => false])->save();

                            if ($seat->term_id !== null) {
                                Term::query()->whereKey($seat->term_id)
                                    ->update(['status' => Term::STATUS_COMPLETED, 'updated_at' => now()]);
                            }
                        }
                    }
                }

                // Stakes: jurisdiction out, buyer in at the consideration.
                $this->ownership->closeAllStakes($org);
                $this->ownership->openStake(
                    $org,
                    (string) $payload['buyer_type'],
                    (string) $payload['buyer_id'],
                    100.0,
                    OrgOwnershipStake::VIA_CONVERSION,
                );

                $structure = in_array($payload['structure'] ?? null, Organization::STRUCTURES, true)
                    ? (string) $payload['structure']
                    : Organization::STRUCTURE_STOCK;

                // cgc_ip_register is NEVER touched here (WF-ORG-09 —
                // existing dedications irreversible; pinned by test).
                // ip_is_public_domain flips only BECAUSE is_cgc flips in
                // the same write (new works follow private rules; the
                // dedicated works stay public domain in the register).
                $org->forceFill([
                    'type'                => Organization::TYPE_BUSINESS,
                    'is_cgc'              => false,
                    'ownership_type'      => 'private',
                    'structure'           => $structure,
                    'ip_is_public_domain' => false,
                ])->save();
                $this->registry->setStatus($org, Organization::STATUS_ACTIVE);

                $conversion->forceFill([
                    'status'       => OrgConversion::STATUS_COMPLETED,
                    'completed_at' => now(),
                ])->save();

                RecomputeWorkerHeadcountJob::dispatch(OrgWorker::EMPLOYER_ORGANIZATIONS, (string) $org->id)->afterCommit();
                $this->roles->flush();

                return ['org_conversions', (string) $conversion->id];
            })(),

            default => throw new ConstitutionalViolation('Unknown F-LEG-027 branch.', 'CGA Forms Catalog (F-LEG-027)'),
        };
    }
}
