<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ExecutiveActor;
use App\Models\User;
use App\Services\Executive\ExecutiveOrderService;

/**
 * F-EXE-005 — Executive Order/Decision (WF-EXE-10).
 *
 * PRE-ISSUANCE scope validation runs at the VALIDATOR stage
 * (ConstitutionalValidator::check → ExecutiveOrderService::preflight),
 * outside the engine transaction: an out-of-scope order persists its
 * `rejected_pre_issuance` row + public record BEFORE the rejected=true
 * chain entry and the 422 — the Phase D exit-criterion mechanism. This
 * handler runs only for filings that already passed the rules; issue()
 * re-runs them (TOCTOU guard) and publishes the issued order.
 *
 * Action `revoke` revokes an issued order (scope-checked like issuance).
 */
class ExecutiveOrder implements FormHandler
{
    public function __construct(
        private readonly ExecutiveOrderService $orders,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'order.filed';
    }

    public function requiredRoles(): array
    {
        return ['R-14', 'R-15', 'R-16'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $action = (string) ($payload['action'] ?? 'issue');

        if ($action === 'revoke') {
            return $this->revoke($actor, $payload);
        }

        if ($action !== 'issue') {
            throw new ConstitutionalViolation(
                "Unknown F-EXE-005 action [{$action}].",
                'CGA Forms Catalog (F-EXE-005)'
            );
        }

        $executive = ExecutiveActor::executive($payload, 'F-EXE-005');
        $member    = ExecutiveActor::member($actor, (string) $executive->id, 'F-EXE-005');

        // The filing acts through the ACTOR's seat — issued_by_member_id
        // is derived, never trusted from input.
        $order = $this->orders->issue(array_merge($payload, [
            'issued_by_member_id' => (string) $member->id,
        ]));

        return [
            'action'        => 'issue',
            'order_id'      => (string) $order->id,
            'order_no'      => (string) $order->order_no,
            'executive_id'  => (string) $executive->id,
            'issued_by'     => (string) $member->id,
            'target_domain' => (string) $order->target_domain,
            'record_id'     => (string) $order->record_id,
        ];
    }

    private function revoke(?User $actor, array $payload): array
    {
        $order = \App\Models\ExecutiveOrder::query()->find((string) ($payload['order_id'] ?? ''));

        if ($order === null) {
            throw new ConstitutionalViolation('F-EXE-005 revocation names an issued order.', 'Art. III §2');
        }

        $member = ExecutiveActor::member($actor, (string) $order->executive_id, 'F-EXE-005');

        $this->orders->revoke($order, $member, isset($payload['reason']) ? (string) $payload['reason'] : null);

        return [
            'action'   => 'revoke',
            'order_id' => (string) $order->id,
            'order_no' => (string) $order->order_no,
        ];
    }
}
