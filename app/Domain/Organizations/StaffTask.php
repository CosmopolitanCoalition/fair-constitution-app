<?php

namespace App\Domain\Organizations;

/**
 * IO-5 — the delegable task allowlist (operator ruling 2026-09-13 ·
 * org-staff-delegation-model = A). Delegation is scoped to COARSE task buckets,
 * never to a single action and never to a constitutional office. A grant names
 * one bucket; the person may then perform the actions that bucket covers for
 * that one organization, and nothing else.
 *
 * NEVER DELEGABLE (absent from BUCKETS by design, so a grant naming any of them
 * is refused): reassign_agent (F-ORG-001), grant_task / revoke_task themselves
 * (F-ORG-011 — a delegate can never widen or pass on delegation), dedicate_ip
 * (F-ORG-001, the CGC public-domain dedication), F-ORG-003/004 board-election
 * administration, F-ORG-005 ownership transfer, F-ORG-006 conversion,
 * F-ORG-007 dissolution. Those stay the agent's alone.
 */
final class StaffTask
{
    public const PROFILE    = 'profile';
    public const MEMBERSHIP = 'membership';
    public const CONTRACTS  = 'contracts';
    public const DOCUMENTS  = 'documents';
    public const HIRING     = 'hiring';
    public const SHARES     = 'shares';

    /** @var list<string> the fixed, allowlisted buckets a grant may name. */
    public const BUCKETS = [
        self::PROFILE,
        self::MEMBERSHIP,
        self::CONTRACTS,
        self::DOCUMENTS,
        self::HIRING,
        self::SHARES,
    ];

    /**
     * The bucket an F-ORG-001 / F-ORG-008 action falls under, or null when the
     * action is NOT delegable (reassign_agent, dedicate_ip) or is unknown. A
     * null result routes the caller to the agent-only path.
     */
    public static function bucketForAction(string $action): ?string
    {
        return match ($action) {
            'update_profile', 'update_settings'    => self::PROFILE,
            'accept_member', 'decline_member'      => self::MEMBERSHIP,
            'countersign_contract', 'void_contract' => self::CONTRACTS,
            'manage_document_package'              => self::DOCUMENTS,
            'issue_shares'                         => self::SHARES,
            default                                => null,
        };
    }

    public static function isBucket(string $task): bool
    {
        return in_array($task, self::BUCKETS, true);
    }
}
