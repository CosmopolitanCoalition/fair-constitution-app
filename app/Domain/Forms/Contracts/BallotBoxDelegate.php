<?php

namespace App\Domain\Forms\Contracts;

use App\Models\ElectionRace;
use App\Models\User;

/**
 * Seam between the F-IND-007 BallotSubmission handler and the real ballot
 * crypto unit `app/Domain/Ballots/BallotBox.php` (WI-B2) — same pattern as
 * Phase A's ResidencyHandlerDelegate: WI-B4 binds NoopBallotBoxDelegate;
 * the orchestrator rebinds BallotBox in ConstitutionProvider after WI-B2
 * merges, without touching the handler or the engine.
 *
 * commit() runs inside the engine's DB transaction and performs the
 * secrecy-boundary write pair in one go: the voter-linked, content-free
 * `ballot_envelopes` row and the anonymous, voter-free `ballots` row
 * (design §B.5.1 — no linking column, no FK path, no shared id).
 *
 * CHAIN-SAFETY CONTRACT (design §B.5.6): the array commit() returns is the
 * audit payload recorded to the chain — it must contain PARTICIPATION ONLY
 * (race_id, envelope_id). Never the ballot hash, never the salt, never
 * rankings: hash + chain-seq adjacency would re-link voter to ballot.
 *
 * RECEIPT INTEGRATION POINT (WI-B2 / WI-B8 / orchestrator): the voter's
 * receipt {ballot_hash, salt} cannot ride the handler's return value —
 * ConstitutionalEngine records the same array to the chain and to
 * EngineResult->recorded, so anything returned here would be sealed into
 * the audit log. BallotBox must deliver the receipt out-of-band (e.g. a
 * request-scoped receipt holder the WI-B8 BallotController reads after
 * file() returns). This contract deliberately pins only that commit()'s
 * return is chain-safe.
 */
interface BallotBoxDelegate
{
    /**
     * Commit one ranked ballot for $actor in $race.
     *
     * @param  list<string>  $rankings  ordered candidacy UUIDs (already
     *                                  validated by the handler: distinct,
     *                                  finalist-or-validated, same race)
     * @return array chain-safe audit payload — participation only
     *               (e.g. ['race_id' => ..., 'envelope_id' => ...])
     */
    public function commit(?User $actor, ElectionRace $race, array $rankings): array;
}
