<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\OrgOwnershipStake;
use App\Models\User;
use App\Services\Organizations\OrgOwnershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * F-ORG-008 — Organization Market Participation (R-23).
 *
 * The ACTS half of the org economy (Design Round 2 ②/①; operator ruling
 * 2026-07-29 — POLICY dials ride F-ORG-001, world-changing ACTS ride here).
 * Action-dispatched, like F-ORG-001. First action: issue_shares.
 *
 * SHARES ARE EQUITY, NEVER MONEY. An org unit is a share in a stock
 * enterprise (Art. III §5) — it is NOT a currency (currency is reserved to
 * the most-encompassing jurisdiction, Art. V §5, and this handler cannot
 * reach the currencies table). Issuance writes the cap table
 * (org_ownership_stakes, acquired_via='issue') through the one cap-table
 * writer; ownership is a public fact recorded BY NAME on the ownership plane
 * (Ruling B), distinct from the pseudonymous money plane.
 *
 * Only a STOCK organization has shares: a member-owned, partnership or
 * nonprofit org is refused, citing Art. III §5.
 */
class OrganizationMarketParticipation implements FormHandler
{
    public function __construct(private OrgOwnershipService $ownership) {}

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'organization.market';
    }

    public function requiredRoles(): array
    {
        // R-23 (agent) OR R-31 (a shares-bucket delegate, IO-5). The binding
        // check is the per-act mayPerform below.
        return ['R-23', 'R-31'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        return DB::transaction(fn () => $this->issueForOrganization($actor, $payload));
    }

    private function issueForOrganization(?User $actor, array $payload): array
    {
        $organizationId = $payload['organization_id'] ?? null;
        if (! is_string($organizationId) || ! Str::isUuid($organizationId)) {
            throw new ConstitutionalViolation(__('Choose an existing organization.'), 'CGA Forms Catalog (F-ORG-008)');
        }

        // Keep the authority/structure checks and the ownership write under
        // the same organization lock as other cap-table changes.
        $org = Organization::query()->lockForUpdate()->find($organizationId);

        if ($org === null) {
            throw new ConstitutionalViolation(__('F-ORG-008 targets an unknown organization.'), 'CGA Forms Catalog (F-ORG-008)');
        }

        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

        // Per-act authority (IO-5, the single mayPerform rail). The market acts
        // sit in the 'shares' bucket: the agent OR a shares delegate may act; a
        // system filing passes (engine null-actor rule).
        $bucket = \App\Domain\Organizations\StaffTask::bucketForAction($action) ?? \App\Domain\Organizations\StaffTask::SHARES;

        if (! app(\App\Services\Organizations\OrgDelegationService::class)->mayPerform($org, $actor, $bucket)) {
            throw new ConstitutionalViolation(__('Only this organization\'s agent or a shares delegate may act in the market for it (R-23 / R-31).'),
                'CGA Forms Catalog (R-23)'
            );
        }

        $result = match ($action) {
            'issue_shares' => $this->issueShares($org, $payload),
            default        => throw new ConstitutionalViolation(__('Unknown F-ORG-008 action [:action].', ['action' => $action]),
                'CGA Forms Catalog (F-ORG-008)'
            ),
        };

        return ['action' => $action, 'organization_id' => (string) $org->id] + $result;
    }

    /** @return array<string, mixed> */
    private function issueShares(Organization $org, array $payload): array
    {
        if ($org->status === Organization::STATUS_DISSOLVED) {
            throw new ConstitutionalViolation(__('A dissolved organization cannot issue new shares.'), 'CGA Forms Catalog (F-ORG-008)');
        }

        // Shares are equity in a stock enterprise (Art. III §5). An org owned
        // by its members, partners or no one (nonprofit) has no shares.
        if ((string) $org->structure !== Organization::STRUCTURE_STOCK) {
            throw new ConstitutionalViolation(__('Only a stock organization issues shares — ownership elsewhere is by membership, not equity.'),
                'Art. III §5'
            );
        }

        $holderType = $payload['holder_type'] ?? null;
        if (! in_array($holderType, [OrgOwnershipStake::HOLDER_USERS, OrgOwnershipStake::HOLDER_ORGANIZATIONS], true)) {
            throw new ConstitutionalViolation(__('A share is issued to a person or an organization.'),
                'CGA Forms Catalog (F-ORG-008)'
            );
        }

        $holderId = $payload['holder_id'] ?? null;
        if (! is_string($holderId) || ! Str::isUuid($holderId)) {
            throw new ConstitutionalViolation(__('Choose an existing person or organization to receive the shares.'), 'CGA Forms Catalog (F-ORG-008)');
        }

        $holder = $holderType === OrgOwnershipStake::HOLDER_USERS ? User::query() : Organization::query();
        if (! $holder->whereKey($holderId)->exists()) {
            throw new ConstitutionalViolation(__('The selected share recipient no longer exists.'), 'CGA Forms Catalog (F-ORG-008)');
        }

        try {
            $units = OrgOwnershipService::normalizeUnits($payload['units'] ?? null);
        } catch (InvalidArgumentException $error) {
            throw new ConstitutionalViolation($error->getMessage(), 'CGA Forms Catalog (F-ORG-008)');
        }

        $stake = $this->ownership->openStake($org, $holderType, $holderId, $units, OrgOwnershipStake::VIA_ISSUE);

        return [
            'stake_id'    => (string) $stake->id,
            'holder_type' => $holderType,
            'holder_id'   => $holderId,
            'units'       => (string) $stake->units,
            'pct'         => (string) ($stake->pct ?? '0'),
        ];
    }
}
