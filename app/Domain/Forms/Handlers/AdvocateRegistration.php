<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Judiciary\AdvocateService;

/**
 * F-IND-015 — Advocate Registration (R-21; WF-JUD-04, the advocate-console).
 *
 * Actor R-03 (associated resident). Creates an `advocates` row at the named
 * judiciary; the handler rejects only on association + duplicate, never on a
 * merits/identity test (Art. I — the bar exists to satisfy the client's right
 * to representation, it is not a gate on who may be a party). R-21 derives on
 * success.
 */
class AdvocateRegistration implements FormHandler
{
    public function __construct(
        private readonly AdvocateService $advocates,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'advocate.registered';
    }

    public function requiredRoles(): array
    {
        return ['R-03'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation('F-IND-015 is filed by the registering resident.', 'Art. I');
        }

        $judiciaryId = (string) ($payload['judiciary_id'] ?? '');

        if ($judiciaryId === '') {
            throw new ConstitutionalViolation('F-IND-015 names the court (judiciary_id) to register with.', 'CGA Forms Catalog');
        }

        $advocate = $this->advocates->register(
            (string) $actor->getKey(),
            $judiciaryId,
            isset($payload['qualifications_note']) ? (string) $payload['qualifications_note'] : null,
        );

        return [
            'advocate_id' => (string) $advocate->id,
            'judiciary_id' => (string) $advocate->judiciary_id,
            'user_id' => (string) $advocate->user_id,
        ];
    }
}
