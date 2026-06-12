<?php

namespace App\Domain\Ballots;

/**
 * The voter receipt (design §B.5.3): {ballot_hash, salt}, returned exactly
 * once from BallotBox::commit() and never reconstructable afterwards —
 * nothing voter-linked stores either value.
 *
 * Self-audit flow: the published per-race hash list (PublishBallotHashesJob)
 * contains `ballot_hash` for every counted ballot; the voter checks
 * inclusion, and — holding the salt — can re-verify that the hash commits
 * to the rankings they remember casting.
 *
 * KNOWN LIMITATION (cryptographer-review list): this receipt *proves* a
 * vote, which is a vote-selling/coercion channel. Phase B claims
 * separation + tamper-evidence + inclusion-audit, not receipt-freeness.
 */
final readonly class BallotReceipt
{
    public function __construct(
        public string $ballotHash,
        public string $salt,
    ) {
    }

    /** Does this receipt commit to these rankings? (voter-side re-check) */
    public function verifies(array $rankings): bool
    {
        return BallotCrypto::verifyCommitment($this->ballotHash, $this->salt, $rankings);
    }

    /** @return array{ballot_hash: string, salt: string} */
    public function toArray(): array
    {
        return [
            'ballot_hash' => $this->ballotHash,
            'salt'        => $this->salt,
        ];
    }
}
