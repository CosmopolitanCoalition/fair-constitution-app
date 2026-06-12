<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\Law;
use App\Models\User;
use App\Services\ReferendumService;

/**
 * F-LEG-034 — Referendum Act Modification Vote (R-09; canonical — the
 * impeachment flows' "F-LEG-034" citation is recorded drift for
 * F-LEG-022, never auto-resolved).
 *
 * The CLK-19 shield rule (referendum.shield) runs FIRST, at the engine's
 * validator boundary: an act passed by population supermajority whose
 * shield election has not certified is rejected pre-vote with Art. II §6
 * (+ the rejected=true chain row). An unshielded (majority-passed)
 * referendum act proceeds — at chamber SUPERMAJORITY
 * (referendum_act_modify); adoption appends a `referendum_modification`
 * law version. After the shield election certifies, referendum acts are
 * ordinary laws (WF-LEG-18).
 */
class ReferendumActModification implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly ReferendumService $referendums)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'law.referendum_modified';
    }

    public function requiredRoles(): array
    {
        return ['R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $law = Law::query()->find($payload['law_id'] ?? null);

        if ($law === null) {
            throw new ConstitutionalViolation('F-LEG-034 targets an unknown law.', 'Art. II §6');
        }

        $legislature = $law->legislature()->firstOrFail();

        $proposer = $this->currentMemberOf($actor, (string) $legislature->id);

        $opened = $this->referendums->proposeModification(
            $legislature,
            $proposer,
            $law,
            (string) ($payload['text'] ?? ''),
        );

        return $opened + [
            'law_id'          => (string) $law->id,
            'act_number'      => $law->act_number,
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $law->jurisdiction_id,
        ];
    }
}
