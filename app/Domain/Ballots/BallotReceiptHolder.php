<?php

namespace App\Domain\Ballots;

/**
 * Request-scoped out-of-band channel for the voter receipt (the
 * BallotBoxDelegate "RECEIPT INTEGRATION POINT").
 *
 * Why it exists: through the engine path, the handler's return value is
 * recorded VERBATIM to the audit chain — a receipt riding that return
 * would seal {ballot_hash, salt} into the chain next to the voter's
 * participation entry, re-linking voter↔ballot. So EngineBallotBox stashes
 * the receipt here, and the ballot controller (WI-B8) reads it AFTER
 * ConstitutionalEngine::file() returns, renders it once, and never
 * persists it.
 *
 * BINDING (orchestrator, ConstitutionProvider): MUST be `scoped()` (fresh
 * per request/job), never a plain singleton — a cross-request instance
 * could hand one voter's receipt to another. take() clears on read as a
 * second guard.
 */
final class BallotReceiptHolder
{
    private ?BallotReceipt $receipt = null;

    public function put(BallotReceipt $receipt): void
    {
        $this->receipt = $receipt;
    }

    /** Read-once: returns the held receipt and clears it. */
    public function take(): ?BallotReceipt
    {
        $receipt = $this->receipt;

        $this->receipt = null;

        return $receipt;
    }
}
