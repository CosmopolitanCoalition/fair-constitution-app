<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\OrgTransfer;
use App\Models\User;
use App\Services\Organizations\OrgTransferService;

/**
 * F-ORG-005 — Ownership Transfer Initiation (R-23; WF-ORG-06).
 *
 * Actions: initiate (from-side consent at filing) · consent (to-side —
 * the named transferee user or the transferee org's agent) · complete
 * (stake mutations; MUTUAL consent mandatory — engine + validator +
 * DB CHECK).
 *
 * ROLE-GATE EXTENSION (documented, the F-SPK-007 precedent): R-03 joins
 * R-23 so a plain-user transferee can file `consent` — the handler binds
 * the action to the NAMED transferee, which is the real gate.
 */
class OwnershipTransferInitiation implements FormHandler
{
    public function __construct(
        private readonly OrgTransferService $transfers,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'ownership_transfer.acted';
    }

    public function requiredRoles(): array
    {
        return ['R-23', 'R-03'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $action = (string) ($payload['action'] ?? 'initiate');

        if ($action === 'initiate') {
            $org = Organization::query()->find($payload['organization_id'] ?? null);

            if ($org === null) {
                throw new ConstitutionalViolation('F-ORG-005 targets an unknown organization.', 'CGA Forms Catalog (F-ORG-005)');
            }

            if ($actor === null || (string) $org->agent_user_id !== (string) $actor->getKey()) {
                throw new ConstitutionalViolation(
                    'Only this organization\'s agent may initiate a transfer (R-23).',
                    'CGA Forms Catalog (R-23)'
                );
            }

            $transfer = $this->transfers->initiate(
                $org,
                $actor,
                (string) ($payload['to_party_type'] ?? ''),
                (string) ($payload['to_party_id'] ?? ''),
                isset($payload['terms']) ? (string) $payload['terms'] : null,
            );

            return [
                'action'          => 'initiate',
                'transfer_id'     => (string) $transfer->id,
                'organization_id' => (string) $org->id,
                'status'          => (string) $transfer->status,
            ];
        }

        $transfer = OrgTransfer::query()->find($payload['transfer_id'] ?? null);

        if ($transfer === null) {
            throw new ConstitutionalViolation('F-ORG-005 targets an unknown transfer.', 'CGA Forms Catalog (F-ORG-005)');
        }

        return match ($action) {
            'consent' => (function () use ($transfer, $actor) {
                if ($actor === null) {
                    throw new ConstitutionalViolation('Consent belongs to the named transferee.', 'CGA Forms Catalog (F-ORG-005)');
                }

                $transfer = $this->transfers->consent($transfer, $actor);

                return [
                    'action'      => 'consent',
                    'transfer_id' => (string) $transfer->id,
                    'status'      => (string) $transfer->status,
                ];
            })(),

            'complete' => (function () use ($transfer) {
                $transfer = $this->transfers->complete($transfer);

                return [
                    'action'      => 'complete',
                    'transfer_id' => (string) $transfer->id,
                    'status'      => (string) $transfer->status,
                ];
            })(),

            default => throw new ConstitutionalViolation(
                "Unknown F-ORG-005 action [{$action}].",
                'CGA Forms Catalog (F-ORG-005)'
            ),
        };
    }
}
