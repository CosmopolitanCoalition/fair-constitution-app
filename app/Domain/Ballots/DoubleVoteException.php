<?php

namespace App\Domain\Ballots;

use App\Domain\Engine\ConstitutionalViolation;

/**
 * One ballot per voter per race per kind — surfaced when the
 * ballot_envelopes unique (race_id, user_id, kind) rejects a second
 * envelope insert (the database, not application logic, is the
 * double-vote authority).
 *
 * Extends ConstitutionalViolation so the engine's F-IND-007 path records
 * the rejection as a first-class chain entry with its citation and the
 * HTTP layer renders 422. NOTE for the handler (WI-B4): the engine's
 * rejection recorder echoes the sanitized filing payload — F-IND-007 must
 * strip `rankings` before letting this bubble, or ballot content reaches
 * the chain on a rejected double vote.
 */
class DoubleVoteException extends ConstitutionalViolation
{
    public function __construct(string $kind = 'ranked')
    {
        parent::__construct(
            "A {$kind} ballot has already been committed by this voter in this race — one ballot per voter per race.",
            'Art. II §2'
        );
    }
}
