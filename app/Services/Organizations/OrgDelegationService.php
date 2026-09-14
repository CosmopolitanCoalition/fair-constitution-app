<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Organizations\StaffTask;
use App\Models\Organization;
use App\Models\OrgStaffGrant;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * IO-5 — the ONE authority rail every organization task gate consults (operator
 * ruling 2026-09-13 · org-staff-delegation-model = A · single mayPerform check).
 *
 * mayPerform() answers, at ACT TIME, whether a person may perform a coarse task
 * bucket for one organization: they are its current agent, OR they hold an
 * active grant for that (organization, person, bucket) and the organization is
 * not dissolved. Rendering-time capability flags are conveniences; the binding
 * decision is made here, inside the handler/service, so a grant revoked between
 * page load and submit refuses the act.
 *
 * grant()/revoke() are the F-ORG-011 writers. They are idempotent, refuse a
 * bucket outside the allowlist (never-delegable acts can never be granted), and
 * flush the grantee's derived-role cache so R-31 appears/disappears at once.
 */
class OrgDelegationService
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * May $actor perform $task (a StaffTask bucket) for $organization right now?
     * $organization may be a loaded model (the caller already has it) or an id
     * string (LaborBoardService has only the id). A null actor is a system
     * filing and passes, matching the engine's null-actor role-gate bypass.
     */
    public function mayPerform(Organization|string $organization, ?User $actor, string $task): bool
    {
        if ($actor === null) {
            return true;
        }

        $org = $organization instanceof Organization
            ? $organization
            : Organization::query()->find($organization);

        if ($org === null) {
            return false;
        }

        $actorId = (string) $actor->getKey();

        // The agent may perform every task on its own organization, at any
        // lifecycle status (the per-action state guards, e.g. the dissolved
        // check in issue_shares, stay where they are).
        if ($org->agent_user_id !== null && (string) $org->agent_user_id === $actorId) {
            return true;
        }

        // A delegate may act only on an allowlisted bucket and only while the
        // organization is not dissolved.
        if (! StaffTask::isBucket($task)) {
            return false;
        }

        if ((string) $org->status === Organization::STATUS_DISSOLVED) {
            return false;
        }

        return DB::table('org_staff_grants')
            ->where('organization_id', (string) $org->id)
            ->where('grantee_user_id', $actorId)
            ->where('task', $task)
            ->where('status', OrgStaffGrant::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Grant $granteeId the $bucket for $org. Idempotent: an existing active
     * grant is returned unchanged. A bucket outside the allowlist (a
     * never-delegable act) is refused.
     */
    public function grant(Organization $org, User $agent, string $granteeId, string $bucket): OrgStaffGrant
    {
        if (! StaffTask::isBucket($bucket)) {
            throw new ConstitutionalViolation(
                "Task [{$bucket}] is not delegable — a delegate never receives agency, ownership, board, conversion, dissolution or IP-dedication powers.",
                'CGA Forms Catalog (F-ORG-011)'
            );
        }

        $existing = OrgStaffGrant::query()
            ->where('organization_id', (string) $org->id)
            ->where('grantee_user_id', $granteeId)
            ->where('task', $bucket)
            ->where('status', OrgStaffGrant::STATUS_ACTIVE)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $grant = OrgStaffGrant::create([
            'organization_id'    => (string) $org->id,
            'grantee_user_id'    => $granteeId,
            'task'               => $bucket,
            'status'             => OrgStaffGrant::STATUS_ACTIVE,
            'granted_by_user_id' => (string) $agent->getKey(),
            'granted_at'         => now(),
        ]);

        $this->roles->flushUser($granteeId);

        return $grant;
    }

    /**
     * Revoke $granteeId's $bucket grant on $org. Idempotent: revoking an absent
     * or already-revoked grant is a no-op returning null.
     */
    public function revoke(Organization $org, string $granteeId, string $bucket, ?User $revoker = null, ?string $reason = null): ?OrgStaffGrant
    {
        $grant = OrgStaffGrant::query()
            ->where('organization_id', (string) $org->id)
            ->where('grantee_user_id', $granteeId)
            ->where('task', $bucket)
            ->where('status', OrgStaffGrant::STATUS_ACTIVE)
            ->first();

        if ($grant === null) {
            return null;
        }

        $grant->forceFill([
            'status'             => OrgStaffGrant::STATUS_REVOKED,
            'revoked_at'         => now(),
            'revoked_by_user_id' => $revoker !== null ? (string) $revoker->getKey() : null,
            'end_reason'         => $reason,
        ])->save();

        $this->roles->flushUser($granteeId);

        return $grant;
    }
}
