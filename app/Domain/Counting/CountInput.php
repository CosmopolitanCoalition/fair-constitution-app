<?php

namespace App\Domain\Counting;

/**
 * Immutable input to one count.
 *
 * These five fields are the ENTIRE universe the counting core can see.
 * There is deliberately no candidacy metadata here (write-in/finalist
 * status, party, organization, anything): the constitutional guarantee
 * that such attributes cannot influence a count is enforced by this
 * type's shape, not by a rule (design §A.5 "Write-ins").
 *
 *  - $candidacyIds  validated candidate set for the race
 *  - $seats         seats to fill (1–9, Art. II §2/§8 hard bounds)
 *  - $ballots       grouped ranked ballots
 *  - $excluded      candidacy ids whose preferences are passed over
 *                   entirely (pre-lock withdrawals, countback strikes)
 *  - $tieSeedBase   sha256(canonical-ballots-hash ∥ race_id), computed
 *                   by the calling job from public data, so the seeded
 *                   lot of §A.5-T is reproducible by any auditor
 */
final readonly class CountInput
{
    /**
     * @param  list<string>  $candidacyIds
     * @param  list<string>  $excluded
     */
    public function __construct(
        public array $candidacyIds,
        public int $seats,
        public BallotSet $ballots,
        public array $excluded = [],
        public string $tieSeedBase = '',
    ) {
    }
}
