<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\OrgOwnershipStake;
use App\Models\User;
use App\Services\Organizations\OrgOwnershipService;

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
        return ['R-23'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $org = Organization::query()->find($payload['organization_id'] ?? null);

        if ($org === null) {
            throw new ConstitutionalViolation('F-ORG-008 targets an unknown organization.', 'CGA Forms Catalog (F-ORG-008)');
        }

        // The role gate proves agency over SOME org; the act must come from
        // THIS org's agent (a system filing passes — engine rule).
        if ($actor !== null && (string) $org->agent_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Only this organization\'s agent may act in the market for it (R-23).',
                'CGA Forms Catalog (R-23)'
            );
        }

        $action = (string) ($payload['action'] ?? '');

        $result = match ($action) {
            'issue_shares' => $this->issueShares($org, $payload),
            default        => throw new ConstitutionalViolation(
                "Unknown F-ORG-008 action [{$action}].",
                'CGA Forms Catalog (F-ORG-008)'
            ),
        };

        return ['action' => $action, 'organization_id' => (string) $org->id] + $result;
    }

    /** @return array<string, mixed> */
    private function issueShares(Organization $org, array $payload): array
    {
        // Shares are equity in a stock enterprise (Art. III §5). An org owned
        // by its members, partners or no one (nonprofit) has no shares.
        if ((string) $org->structure !== Organization::STRUCTURE_STOCK) {
            throw new ConstitutionalViolation(
                'Only a stock organization issues shares — ownership elsewhere is by membership, not equity.',
                'Art. III §5'
            );
        }

        $holderType = (string) ($payload['holder_type'] ?? '');
        if (! in_array($holderType, [OrgOwnershipStake::HOLDER_USERS, OrgOwnershipStake::HOLDER_ORGANIZATIONS], true)) {
            throw new ConstitutionalViolation(
                'A share is issued to a person or an organization.',
                'CGA Forms Catalog (F-ORG-008)'
            );
        }

        $holderId = (string) ($payload['holder_id'] ?? '');
        if ($holderId === '') {
            throw new ConstitutionalViolation('issue_shares names no holder.', 'CGA Forms Catalog (F-ORG-008)');
        }

        $units = (float) ($payload['units'] ?? 0);
        if ($units <= 0) {
            throw new ConstitutionalViolation('Shares are issued in a positive number of units.', 'CGA Forms Catalog (F-ORG-008)');
        }

        $stake = $this->ownership->openStake($org, $holderType, $holderId, $units, OrgOwnershipStake::VIA_ISSUE);

        return [
            'stake_id'    => (string) $stake->id,
            'holder_type' => $holderType,
            'holder_id'   => $holderId,
            'units'       => (string) $stake->units,
            'pct'         => (string) ($stake->refresh()->pct ?? '0'),
        ];
    }
}
