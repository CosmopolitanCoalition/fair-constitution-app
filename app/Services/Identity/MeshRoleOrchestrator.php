<?php

namespace App\Services\Identity;

use App\Models\InstanceCapability;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use InvalidArgumentException;

/**
 * Role adopt/drop orchestration (Operator Roles & Console ★3) — a thin batch over the EXISTING per-channel
 * flow. Adopting a named role = registerSelf each self-asserted channel + open a MeshRoleGrantService
 * request for each governed channel (ratified through the IDENTICAL dual-meter consent). Dropping = revoke
 * each channel (unilateral). No new vote math, no new grant kind, no new consent path — the catalog
 * (config/mesh_roles.php) just names which channels move together.
 */
class MeshRoleOrchestrator
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly MeshRoleGrantService $grants,
        private readonly InstanceIdentityService $identity,
    ) {}

    /**
     * Adopt a role: establish its self-asserted channels immediately; open a governed-grant REQUEST for
     * each governed channel (which then awaits dual-meter consent). Governed channels need a jurisdiction
     * scope — without one they are reported `needs_scope` rather than silently skipped. Idempotent: an
     * already-held channel reports `already_established`.
     *
     * @return array{role:string,actions:list<array{capability:string,kind:string,result:string,detail:?string}>}
     */
    public function adopt(string $role, ?string $scopeJurisdictionId = null): array
    {
        $serverId = $this->identity->serverId();
        $actions = [];

        foreach ($this->channelsFor($role) as $cap) {
            $governed = InstanceCapability::isGoverned($cap);
            $kind = $governed ? 'governed' : 'self-asserted';

            if ($this->capabilities->holds($serverId, $cap)) {
                $actions[] = $this->action($cap, $kind, 'already_established', null);
                continue;
            }

            if (! $governed) {
                $this->capabilities->registerSelf($cap);
                $actions[] = $this->action($cap, $kind, 'established', null);
                continue;
            }

            if ($scopeJurisdictionId === null || $scopeJurisdictionId === '') {
                $actions[] = $this->action($cap, $kind, 'needs_scope', 'a jurisdiction scope is required to request this governed channel');
                continue;
            }

            // Per-channel: one channel that can't be requested (e.g. not yet qualifiable, or already
            // requested) must not abort adoption of the rest of the role.
            try {
                $proposal = $this->grants->request($cap, $scopeJurisdictionId);
                $actions[] = $this->action($cap, $kind, 'requested', (string) $proposal->id);
            } catch (\Throwable $e) {
                $actions[] = $this->action($cap, $kind, 'error', $e->getMessage());
            }
        }

        return ['role' => $role, 'actions' => $actions];
    }

    /**
     * Drop a role: revoke its channels — EXCEPT any channel still required by another role that is currently
     * fully established (e.g. client.serve is shared by Archivist / Social Moderator / Identity Broker, so
     * dropping one must not yank it from another). Revoke is unilateral + per-channel.
     *
     * @return array{role:string,dropped:list<string>,kept_shared:list<string>}
     */
    public function drop(string $role): array
    {
        $keep = $this->channelsHeldByOtherEstablishedRoles($role);
        $dropped = [];
        $keptShared = [];

        foreach ($this->channelsFor($role) as $cap) {
            if (in_array($cap, $keep, true)) {
                $keptShared[] = $cap; // another established role still needs it
                continue;
            }
            if ($this->grants->revoke($cap, 'role.dropped')) {
                $dropped[] = $cap;
            }
        }

        return ['role' => $role, 'dropped' => $dropped, 'kept_shared' => $keptShared];
    }

    /**
     * Channels belonging to OTHER roles that are currently fully established (every channel held). Dropping
     * a role must leave these in place so a still-complete sibling role keeps working.
     *
     * @return list<string>
     */
    private function channelsHeldByOtherEstablishedRoles(string $excludeRole): array
    {
        $serverId = $this->identity->serverId();
        $keep = [];

        foreach ((array) config('mesh_roles', []) as $key => $role) {
            if ($key === $excludeRole) {
                continue;
            }
            $channels = array_values((array) ($role['channels'] ?? []));
            if ($channels === []) {
                continue;
            }
            $allHeld = true;
            foreach ($channels as $cap) {
                if (! $this->capabilities->holds($serverId, $cap)) {
                    $allHeld = false;
                    break;
                }
            }
            if ($allHeld) {
                $keep = array_merge($keep, $channels);
            }
        }

        return array_values(array_unique($keep));
    }

    /** @return list<string> */
    private function channelsFor(string $role): array
    {
        $catalog = (array) config('mesh_roles', []);
        if (! isset($catalog[$role])) {
            throw new InvalidArgumentException("Unknown operator-role [{$role}].");
        }

        return array_values((array) ($catalog[$role]['channels'] ?? []));
    }

    /** @return array{capability:string,kind:string,result:string,detail:?string} */
    private function action(string $capability, string $kind, string $result, ?string $detail): array
    {
        return ['capability' => $capability, 'kind' => $kind, 'result' => $result, 'detail' => $detail];
    }
}
