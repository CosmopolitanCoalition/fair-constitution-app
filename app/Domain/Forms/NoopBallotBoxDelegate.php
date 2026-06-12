<?php

namespace App\Domain\Forms;

use App\Domain\Forms\Contracts\BallotBoxDelegate;
use App\Models\ElectionRace;
use App\Models\User;

/**
 * Default (WI-B4) ballot-box delegate: F-IND-007 validation and audit
 * chaining work end to end, but no envelope/ballot rows are written —
 * the crypto unit arrives with WI-B2 (app/Domain/Ballots/BallotBox.php),
 * which the orchestrator rebinds in ConstitutionProvider.
 */
class NoopBallotBoxDelegate implements BallotBoxDelegate
{
    public function commit(?User $actor, ElectionRace $race, array $rankings): array
    {
        return [
            'race_id'     => (string) $race->id,
            'envelope_id' => null,
            'committed'   => false,
            'stub'        => 'BallotBox crypto unit lands in WI-B2',
        ];
    }
}
