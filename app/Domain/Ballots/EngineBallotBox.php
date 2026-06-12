<?php

namespace App\Domain\Ballots;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\BallotBoxDelegate;
use App\Models\ElectionRace;
use App\Models\User;

/**
 * The real BallotBoxDelegate — adapts BallotBox to the F-IND-007
 * BallotSubmission handler seam (WI-B4's contract; the orchestrator
 * rebinds this over NoopBallotBoxDelegate in ConstitutionProvider, with
 * BallotReceiptHolder bound `scoped()` — see its docblock).
 *
 * Honors the contract's CHAIN-SAFETY clause exactly: the returned array is
 * the audit payload the engine records — participation only
 * ({race_id, envelope_id}); the {ballot_hash, salt} receipt travels
 * out-of-band through BallotReceiptHolder. The underlying write does NOT
 * self-append (BallotBox::commitForEngine), so the engine's entry is the
 * single 'ballot.committed' record per ballot (§B.5.6).
 */
class EngineBallotBox implements BallotBoxDelegate
{
    public function __construct(
        private readonly BallotBox $box,
        private readonly BallotReceiptHolder $receipts,
    ) {
    }

    public function commit(?User $actor, ElectionRace $race, array $rankings): array
    {
        // F-IND-007 is never system-filed: a ballot without a voter has no
        // envelope, and an envelope is the double-vote guarantee.
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A ranked ballot requires a voter — F-IND-007 cannot be system-filed.',
                'Art. II §2'
            );
        }

        [$receipt, $payload] = $this->box->commitForEngine($actor, $race, $rankings);

        $this->receipts->put($receipt);

        return $payload;
    }
}
