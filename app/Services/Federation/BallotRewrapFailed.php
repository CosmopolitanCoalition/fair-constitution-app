<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * A ballot-key re-wrap FAILED its fail-closed verification (Phase G, G5a): the
 * gaining cluster could not reproduce the election's certified count from the
 * re-wrapped key (a corrupted/foreign key, tampered ballots, or no certified
 * count to prove against). The re-wrap is aborted and the election's
 * `ballot_key_wrapped` is left exactly as it was — a bad re-wrap never corrupts
 * a historical election or its re-countability.
 */
class BallotRewrapFailed extends RuntimeException
{
    public function __construct(
        public readonly string $electionId,
        public readonly ?string $raceId,
        public readonly string $reason,
    ) {
        parent::__construct("Ballot-key re-wrap refused for election {$electionId}: {$reason}");
    }
}
