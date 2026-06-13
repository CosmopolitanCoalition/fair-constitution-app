<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\OrgConversion;
use App\Models\User;
use App\Services\Organizations\OrgConversionService;

/**
 * F-LEG-026 — Monopoly Acquisition Vote (R-09; WF-ORG-07, Art. III §5).
 *
 * Actions:
 *  - propose: finding + fair-market floor + published basis → proposal
 *    kind monopoly_acquisition → procedural_motion vote (ordinary
 *    majority of all serving — owner ruling #13);
 *  - record_compensation: the completion filing. Compensation BELOW the
 *    recorded floor is validator-rejected pre-commit with the Art. III §5
 *    citation (rejected=true on the chain — the verbatim 422); at or
 *    above the floor the conversion completes (stakes → jurisdiction,
 *    org → CGC, bulk IP dedication, founding-governor offers,
 *    co-determination recheck).
 */
class MonopolyAcquisitionVote implements FormHandler
{
    public function __construct(
        private readonly OrgConversionService $conversions,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'monopoly_acquisition.acted';
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
        $action = (string) ($payload['action'] ?? 'propose');

        if ($action === 'propose') {
            $legislature = ChamberActor::legislature($payload, 'F-LEG-026');
            $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-026');

            $result = $this->conversions->proposeMonopolyAcquisition($legislature, $member, $payload);

            return [
                'action'         => 'propose',
                'legislature_id' => (string) $legislature->id,
                'proposed_by'    => (string) $member->id,
            ] + $result;
        }

        if ($action === 'record_compensation') {
            $conversion = OrgConversion::query()->find($payload['conversion_id'] ?? null);

            if ($conversion === null) {
                throw new ConstitutionalViolation(
                    'record_compensation names an unknown conversion.',
                    'CGA Forms Catalog (F-LEG-026)'
                );
            }

            $compensation = $payload['compensation'] ?? null;

            if (! is_numeric($compensation)) {
                throw new ConstitutionalViolation(
                    'record_compensation requires the numeric compensation amount.',
                    'Art. III §5'
                );
            }

            return ['action' => 'record_compensation']
                + $this->conversions->recordCompensationAndComplete($conversion, (float) $compensation, $actor);
        }

        throw new ConstitutionalViolation(
            "Unknown F-LEG-026 action [{$action}].",
            'CGA Forms Catalog (F-LEG-026)'
        );
    }
}
