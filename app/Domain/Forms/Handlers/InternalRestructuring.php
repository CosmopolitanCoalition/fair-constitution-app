<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgRestructureService;

/**
 * F-ORG-009 — Internal Restructuring (R-01). Ownership-structure
 * conversions WITHIN private hands — the organization stays private
 * throughout; no compensation event, no legislature.
 *
 * TWO ACTIONS, BECAUSE CONSENT IS PER OWNER: `propose` (which consents),
 * and `consent`. The R-01 gate proves personhood; the REAL gate lives in
 * the service — only a holder of an ownership stake acts, and the consent
 * threshold comes from the CURRENT structure's own rules (equal
 * partnership: unanimity; stock: a majority of units; member/worker-owned:
 * a majority of holders). The consent that meets the rule adopts the new
 * structure in the same act.
 *
 * This is deliberately NOT an F-ORG-001 action: F-ORG-001 is the AGENT's
 * form, and restructuring is the OWNERS' act — an agent who holds no stake
 * has no say in it.
 */
class InternalRestructuring implements FormHandler
{
    public function __construct(
        private readonly OrgRestructureService $restructures,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'org.restructuring';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'Restructuring is the owners\' act — system filing is not defined.',
                'CGA Forms Catalog (F-ORG-009)'
            );
        }

        $action = (string) ($payload['action'] ?? 'propose');

        try {
            return match ($action) {
                'propose' => $this->propose($actor, $payload),
                'consent' => ['action' => 'consented'] + $this->restructures->consent(
                    (string) ($payload['restructure_id'] ?? ''),
                    $actor,
                ),
                default => throw new ConstitutionalViolation(
                    "Unknown F-ORG-009 action [{$action}] — propose or consent.",
                    'CGA Forms Catalog (F-ORG-009)'
                ),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The service's refusals ARE the answer — a non-holder acting,
            // a duplicate consent, a structure with nobody to consent.
            throw new ConstitutionalViolation($e->getMessage(), 'Art. I · as implemented');
        }
    }

    /** @return array<string, mixed> */
    private function propose(User $actor, array $payload): array
    {
        $org = Organization::query()->find($payload['organization_id'] ?? null);

        if ($org === null) {
            throw new ConstitutionalViolation('F-ORG-009 targets an unknown organization.', 'CGA Forms Catalog (F-ORG-009)');
        }

        return ['action' => 'proposed', 'organization_id' => (string) $org->id]
            + $this->restructures->propose($org, (string) ($payload['to_structure'] ?? ''), $actor);
    }
}
