<?php

namespace App\Services\Identity;

use App\Models\FederationPeer;
use App\Models\MeshOperatorIdentity;
use App\Models\MeshOperatorKey;
use App\Models\MeshOperatorLocalLink;
use App\Models\OperatorAccount;
use App\Models\OperatorDevice;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase G (G-OP-2) — the MESH-SYNC half of operator identity. Mints a mesh-wide
 * operator identity, signs device-key↔identity bindings with the INSTANCE key
 * (reusing InstanceIdentityService::sign), gossips them to trusted peers
 * (Flow A: announce/ingest, mirroring DirectoryService — each binding verified
 * against ITS bound-by server's pinned key, never the relayer's), and links a
 * traveling operator's local account on a new instance by POSSESSION PROOF — a
 * signature from an already-bound device key (Flow B), never a password.
 *
 * Federates: mesh_operator_id + device PUBLIC keys + instance signatures + a
 * non-secret display handle. NEVER federates: the operator password (a local
 * credential), any secret, any citizen/residency fact.
 *
 * NOTE: announce/ingest/link are CROSS-INSTANCE; their real certification is
 * rig-gated like G-V2. The logic here is dev-stack-buildable + unit-testable with
 * a simulated peer.
 */
class MeshOperatorService
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly AuditService $audit,
    ) {}

    /**
     * Flow A (genesis) — mint a NEW mesh identity for a local operator account and
     * bind its enrolled devices, signed by THIS instance. Sets mesh_operator_id.
     */
    public function mintIdentity(OperatorAccount $account, string $displayHandle): MeshOperatorIdentity
    {
        $handle = trim($displayHandle);
        if ($handle === '') {
            throw new InvalidArgumentException('A display handle is required.');
        }

        return DB::transaction(function () use ($account, $handle) {
            $mesh = MeshOperatorIdentity::create([
                'display_handle'    => $handle,
                'genesis_server_id' => $this->identity->serverId(),
            ]);

            $account->devices()->whereNull('revoked_at')->get()
                ->each(fn (OperatorDevice $d) => $this->bindKey((string) $mesh->id, (string) $d->device_public_key));

            MeshOperatorLocalLink::create([
                'operator_account_id' => $account->id,
                'mesh_operator_id'    => $mesh->id,
                'linked_via_peer_id'  => null, // genesis link is local
                'linked_at'           => now(),
            ]);

            $account->mesh_operator_id = $mesh->id;
            $account->save();

            $this->audit->append('operator_identity', 'mesh.minted',
                ['mesh_operator_id' => $mesh->id, 'operator_account_id' => $account->id], 'WF-JUR-06');

            return $mesh;
        });
    }

    /** Bind a device public key to a mesh identity, signed by THIS instance (idempotent on id+key). */
    public function bindKey(string $meshOperatorId, string $devicePublicKey): MeshOperatorKey
    {
        $existing = MeshOperatorKey::query()
            ->where('mesh_operator_id', $meshOperatorId)
            ->where('device_public_key', $devicePublicKey)
            ->whereNull('deleted_at')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $serverId = $this->identity->serverId();
        $boundAt = now();

        $binding = new MeshOperatorKey([
            'mesh_operator_id'   => $meshOperatorId,
            'device_public_key'  => $devicePublicKey,
            'bound_by_server_id' => $serverId,
            'status'             => MeshOperatorKey::STATUS_ACTIVE,
            'bound_at'           => $boundAt,
        ]);
        $binding->binding_signature = $this->identity->sign(
            $this->canonicalBinding($meshOperatorId, $devicePublicKey, $serverId, (int) $boundAt->getTimestamp())
        );
        $binding->save();

        return $binding;
    }

    /** The byte-stable canonical a binding's bound-by instance signs / a verifier reconstructs. */
    public function canonicalBinding(string $meshOperatorId, string $devicePublicKey, string $boundByServerId, int $boundAt): string
    {
        return AuditService::canonicalJson([
            'mesh_operator_id'   => $meshOperatorId,
            'device_public_key'  => $devicePublicKey,
            'bound_by_server_id' => $boundByServerId,
            'bound_at'           => $boundAt,
        ]);
    }

    /**
     * The wire form announcing an identity + its ACTIVE key bindings to peers.
     *
     * @return array<string,mixed>
     */
    public function announceWire(string $meshOperatorId): array
    {
        $mesh = MeshOperatorIdentity::query()->findOrFail($meshOperatorId);
        $keys = MeshOperatorKey::query()
            ->where('mesh_operator_id', $meshOperatorId)
            ->where('status', MeshOperatorKey::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        return [
            'mesh_operator_id'  => (string) $mesh->id,
            'display_handle'    => (string) $mesh->display_handle,
            'genesis_server_id' => (string) $mesh->genesis_server_id,
            'keys'              => $keys->map(fn (MeshOperatorKey $k) => [
                'device_public_key'  => (string) $k->device_public_key,
                'bound_by_server_id' => (string) $k->bound_by_server_id,
                'bound_at'           => (int) ($k->bound_at?->getTimestamp() ?? 0),
                'binding_signature'  => (string) $k->binding_signature,
            ])->values()->all(),
        ];
    }

    /**
     * Flow A (receiver) — ingest a peer-relayed announce. Each binding is verified
     * against ITS bound-by server's pinned key (not the relayer's), so a relay can
     * never forge a binding. Upserts the identity anchor + every VERIFIED binding;
     * silently drops bindings it cannot authenticate.
     *
     * @param  array<string,mixed>  $wire
     */
    public function ingestAnnounce(array $wire, FederationPeer $from): ?MeshOperatorIdentity
    {
        $meshOperatorId = (string) ($wire['mesh_operator_id'] ?? '');
        $genesis = (string) ($wire['genesis_server_id'] ?? '');
        if ($meshOperatorId === '' || $genesis === '') {
            return null;
        }

        return DB::transaction(function () use ($wire, $from, $meshOperatorId, $genesis) {
            $mesh = MeshOperatorIdentity::query()->firstOrNew(['id' => $meshOperatorId]);
            $mesh->display_handle = (string) ($wire['display_handle'] ?? $mesh->display_handle ?? '');
            $mesh->genesis_server_id = $genesis;
            $mesh->save();

            foreach ((array) ($wire['keys'] ?? []) as $k) {
                $devicePublicKey = (string) ($k['device_public_key'] ?? '');
                $boundBy = (string) ($k['bound_by_server_id'] ?? '');
                $boundAt = (int) ($k['bound_at'] ?? 0);
                $sig = (string) ($k['binding_signature'] ?? '');

                if ($devicePublicKey === '' || $boundBy === '') {
                    continue;
                }

                $boundByKey = $this->serverKey($boundBy, $from);
                if ($boundByKey === null) {
                    continue; // we hold no key to authenticate the binder
                }
                if (! InstanceIdentityService::verify(
                    $boundByKey,
                    $this->canonicalBinding($meshOperatorId, $devicePublicKey, $boundBy, $boundAt),
                    $sig
                )) {
                    continue; // tampered, or not actually signed by the named binder
                }

                $binding = MeshOperatorKey::query()->firstOrNew([
                    'mesh_operator_id'  => $meshOperatorId,
                    'device_public_key' => $devicePublicKey,
                ]);
                $binding->fill([
                    'bound_by_server_id' => $boundBy,
                    'binding_signature'  => $sig,
                    'status'             => MeshOperatorKey::STATUS_ACTIVE,
                    'bound_at'           => CarbonImmutable::createFromTimestamp($boundAt ?: time()),
                ]);
                $binding->save();
            }

            return $mesh;
        });
    }

    /**
     * The canonical a traveling operator's existing device signs to PROVE
     * possession when linking on a new instance — bound to THIS instance and the
     * new key so a proof cannot be replayed/relayed elsewhere.
     */
    public function linkProofString(string $meshOperatorId, string $newDevicePublicKey, int $timestamp): string
    {
        return OperatorIdentityService::actionSigningString('POST', '/operator/link', $timestamp,
            AuditService::canonicalJson([
                'mesh_operator_id'      => $meshOperatorId,
                'target_server_id'      => $this->identity->serverId(),
                'new_device_public_key' => $newDevicePublicKey,
            ]));
    }

    /**
     * Flow B — link a local account on THIS instance to an EXISTING mesh identity.
     * The proof is a signature from an already-bound, ACTIVE device key for that
     * identity (possession proof — passwords are never involved cross-instance).
     * On valid proof + freshness, binds the new device key (signed by us) and
     * writes the local link.
     */
    public function linkByProof(
        OperatorAccount $account,
        string $meshOperatorId,
        string $newDevicePublicKey,
        int $timestamp,
        string $proofSignatureB64,
        ?FederationPeer $from = null,
        int $window = 300,
    ): MeshOperatorLocalLink {
        if (abs(time() - $timestamp) > $window) {
            throw new RuntimeException('Link refused — the possession proof is outside the freshness window.');
        }

        $expected = $this->linkProofString($meshOperatorId, $newDevicePublicKey, $timestamp);

        $proven = MeshOperatorKey::query()
            ->where('mesh_operator_id', $meshOperatorId)
            ->where('status', MeshOperatorKey::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->pluck('device_public_key')
            ->contains(fn ($pub) => InstanceIdentityService::verify((string) $pub, $expected, $proofSignatureB64));

        if (! $proven) {
            throw new RuntimeException('Link refused — possession proof did not verify against any active bound device key.');
        }

        return DB::transaction(function () use ($account, $meshOperatorId, $newDevicePublicKey, $from) {
            $this->bindKey($meshOperatorId, $newDevicePublicKey); // signed by us

            $link = MeshOperatorLocalLink::create([
                'operator_account_id' => $account->id,
                'mesh_operator_id'    => $meshOperatorId,
                'linked_via_peer_id'  => $from?->id,
                'linked_at'           => now(),
            ]);

            $account->mesh_operator_id = $meshOperatorId;
            $account->save();

            $this->audit->append('operator_identity', 'mesh.linked',
                ['mesh_operator_id' => $meshOperatorId, 'operator_account_id' => $account->id], 'WF-JUR-06');

            return $link;
        });
    }

    /** Revoke a binding (lost device) — fails closed everywhere; gossiped like a CRL. */
    public function revokeKey(MeshOperatorKey $binding): void
    {
        if ($binding->status === MeshOperatorKey::STATUS_REVOKED) {
            return;
        }

        $binding->status = MeshOperatorKey::STATUS_REVOKED;
        $binding->revoked_at = now();
        $binding->save();

        $this->audit->append('operator_identity', 'mesh.key_revoked',
            ['mesh_operator_id' => $binding->mesh_operator_id, 'device_public_key' => $binding->device_public_key], 'WF-JUR-06');
    }

    /** Resolve the public key for the server that signed a binding (self / relayer / pinned peer). */
    private function serverKey(string $serverId, FederationPeer $from): ?string
    {
        if ($serverId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }
        if ($serverId === (string) $from->server_id && $from->public_key !== null) {
            return (string) $from->public_key;
        }

        $peer = FederationPeer::query()->where('server_id', $serverId)->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }
}
