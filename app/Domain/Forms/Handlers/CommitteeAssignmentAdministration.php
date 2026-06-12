<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\CommitteeAssignmentService;
use App\Services\PublicRecordService;

/**
 * F-SPK-005 — Committee Assignment Administration (chamber ops §C.4):
 * runs THE deterministic assignment algorithm over the chamber's
 * `created` committees. The complete input/output snapshot — budgets,
 * every contested vote_share_norm comparison (q-ledger #q2), every
 * honored preference rank, exhaustion placements — IS this filing's
 * audit payload, and a `certification` public record publishes the
 * assignment. R-10 files; the system may administer (first-organization
 * support before tooling exists).
 */
class CommitteeAssignmentAdministration implements FormHandler
{
    public function __construct(
        private readonly CommitteeAssignmentService $assignments,
        private readonly PublicRecordService $records,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.assignment_run';
    }

    public function requiredRoles(): array
    {
        return ['R-10'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-SPK-005');

        if ($actor !== null) {
            ChamberActor::speaker($actor, $legislature, 'F-SPK-005');
        }

        $committeeIds = isset($payload['committee_ids'])
            ? array_values(array_map('strval', (array) $payload['committee_ids']))
            : null;

        $snapshot = $this->assignments->run($legislature, $committeeIds);

        $this->records->publish(
            kind: 'certification',
            title: sprintf(
                'Committee assignments certified — %d placement(s) across %d committee(s)',
                count($snapshot['placements']),
                count($snapshot['committees'])
            ),
            body: sprintf(
                '%d contested seat(s) resolved by normalized vote share (Art. II §4 · as implemented); '
                . '%d exhaustion placement(s).',
                count($snapshot['contests']),
                count($snapshot['exhaustion'])
            ),
            attrs: [
                'actor_user_id'   => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-SPK-005',
                'subject_type'    => 'legislatures',
                'subject_id'      => (string) $legislature->id,
            ],
        );

        return $snapshot;
    }
}
