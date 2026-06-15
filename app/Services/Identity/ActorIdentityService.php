<?php

namespace App\Services\Identity;

use App\Models\ActorDevice;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use InvalidArgumentException;

/**
 * Device identity (Phase G, G-ID — the person-level signing layer). A person
 * enrols a device's Ed25519 PUBLIC key (the secret never leaves the device — no
 * escrow); the device then signs ACTIONS, the person-level analogue of the
 * instance signing a peer message. A leader verifies a forwarded write by checking
 * the device's action signature against the enrolled key AND the attestation that
 * binds that device key to the person's standing.
 */
class ActorIdentityService
{
    public function __construct(private readonly AuditService $audit) {}

    /** Enrol (idempotently) a device signing key for a user. */
    public function enrollDevice(User $user, string $devicePublicKey, ?string $label = null): ActorDevice
    {
        if (trim($devicePublicKey) === '') {
            throw new InvalidArgumentException('A device public key is required.');
        }

        $device = ActorDevice::query()->firstOrCreate(
            ['user_id' => (string) $user->getKey(), 'device_public_key' => $devicePublicKey],
            ['label' => $label, 'enrolled_at' => now()],
        );

        if ($device->wasRecentlyCreated) {
            $this->audit->append('actor_identity', 'device.enrolled',
                ['user_id' => (string) $user->getKey(), 'device_id' => $device->id], 'WF-JUR-06');
        }

        return $device;
    }

    /** Revoke a device (lost/replaced) — its action signatures stop verifying. */
    public function revokeDevice(ActorDevice $device): void
    {
        if ($device->revoked_at !== null) {
            return;
        }

        $device->revoked_at = now();
        $device->save();

        $this->audit->append('actor_identity', 'device.revoked', ['device_id' => $device->id], 'WF-JUR-06');
    }

    /**
     * The canonical string a device signs to authorize an action — the
     * person-level analogue of FederationClient::signingString:
     *   METHOD \n TARGET \n TIMESTAMP \n sha256(body)
     */
    public static function actionSigningString(string $method, string $target, int $timestamp, string $body): string
    {
        return strtoupper($method)."\n".$target."\n".$timestamp."\n".hash('sha256', $body);
    }

    /** Verify a device's detached action signature against its enrolled key. */
    public function verifyActionSignature(ActorDevice $device, string $signingString, string $signatureB64): bool
    {
        if ($device->revoked_at !== null) {
            return false;
        }

        return InstanceIdentityService::verify((string) $device->device_public_key, $signingString, $signatureB64);
    }
}
