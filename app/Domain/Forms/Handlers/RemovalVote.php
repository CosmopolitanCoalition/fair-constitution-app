<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\ChamberVote;
use App\Models\RemovalProceeding;
use App\Models\User;
use App\Services\Legislature\OversightService;

/**
 * F-LEG-022 — Removal/Impeachment/Censure/Expulsion Vote (chamber ops
 * §D.3).
 *
 * One member's cast on a proceeding's SUPERMAJORITY vote (of ALL serving
 * — Art. VII; the Speaker presides and votes only on ties, which are
 * arithmetically impossible at supermajority). On the closing cast the
 * vote engine dispatches the outcome: removed/expelled → the member row
 * flips and F-LEG-036 system-files into the Phase B vacancy machinery
 * (countback → certify-or-special); censured → record only; failed →
 * retained.
 */
class RemovalVote implements FormHandler
{
    public function __construct(
        private readonly OversightService $oversight,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'oversight.removal_cast';
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
        $proceeding = RemovalProceeding::query()->find($payload['proceeding_id'] ?? null);

        if ($proceeding === null) {
            throw new ConstitutionalViolation('F-LEG-022 requires a valid proceeding_id.', 'CGA Forms Catalog');
        }

        if ($proceeding->vote_id === null) {
            throw new ConstitutionalViolation(
                'This proceeding has no open vote — the presider opens it (F-SPK-007).',
                'Art. II §3'
            );
        }

        $vote   = ChamberVote::query()->findOrFail($proceeding->vote_id);
        $member = ChamberActor::member($actor, (string) $proceeding->legislature_id, 'F-LEG-022');

        $result = $this->oversight->recordRemovalCast(
            $vote,
            $proceeding,
            $member,
            (string) ($payload['value'] ?? ''),
            isset($payload['explanation']) ? (string) $payload['explanation'] : null,
        );

        return [
            'proceeding_id' => (string) $proceeding->id,
            'kind'          => $proceeding->kind,
            'member_id'     => (string) $member->id,
            'value'         => (string) $payload['value'],
        ] + $result;
    }
}
