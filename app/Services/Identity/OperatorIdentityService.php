<?php

namespace App\Services\Identity;

use App\Models\OperatorAccount;
use App\Models\OperatorDevice;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Phase G (G-OP) — the LOCAL operator plane: register/authenticate an operator
 * account on this instance and enrol its Ed25519 signing devices. Deliberately
 * separate from the citizen identity (ActorIdentityService / AttestationService):
 * an operator is INFRASTRUCTURE, never a citizen privilege, so this service
 * touches NO `users` row and NO `RoleService` — the plane wall.
 *
 * The password authenticates LOCALLY only and NEVER federates; cross-mesh
 * recognition is by device-key possession (see MeshOperatorService), never by
 * replaying this credential.
 */
class OperatorIdentityService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly InstanceIdentityService $instance,
    ) {}

    /** Register a local operator account on THIS instance (username unique per server). */
    public function register(
        string $username,
        string $plaintextPassword,
        string $status = OperatorAccount::STATUS_ACTIVE,
    ): OperatorAccount {
        $username = trim($username);

        if ($username === '' || strlen($plaintextPassword) < 8) {
            throw new InvalidArgumentException('An operator username and an 8+ character password are required.');
        }

        $account = OperatorAccount::create([
            'server_id' => $this->instance->serverId(),
            'username'  => $username,
            'password'  => $plaintextPassword, // hashed by the model cast
            'status'    => $status,
        ]);

        // Only the handle is audited — never the credential.
        $this->audit->append('operator_identity', 'account.registered',
            ['operator_account_id' => $account->id, 'username' => $username], 'WF-JUR-06');

        return $account;
    }

    /**
     * Authenticate a local operator by username + password — LOCAL only, never a
     * mesh call. Returns the account on success (stamping last_login_at), null
     * otherwise (fails closed on a missing/suspended/closed account).
     */
    public function authenticate(string $username, string $plaintextPassword): ?OperatorAccount
    {
        $account = OperatorAccount::query()
            ->where('server_id', $this->instance->serverId())
            ->where('username', trim($username))
            ->whereNull('deleted_at')
            ->first();

        if ($account === null
            || ! $account->isActive()
            || ! Hash::check($plaintextPassword, (string) $account->password)) {
            return null;
        }

        $account->last_login_at = now();
        $account->save();

        return $account;
    }

    /** Enrol (idempotently) an Ed25519 signing device for an operator — PUBLIC key only. */
    public function enrollDevice(OperatorAccount $account, string $devicePublicKey, ?string $label = null): OperatorDevice
    {
        if (trim($devicePublicKey) === '') {
            throw new InvalidArgumentException('A device public key is required.');
        }

        $device = OperatorDevice::query()->firstOrCreate(
            ['operator_account_id' => $account->id, 'device_public_key' => $devicePublicKey],
            ['label' => $label, 'enrolled_at' => now()],
        );

        if ($device->wasRecentlyCreated) {
            $this->audit->append('operator_identity', 'device.enrolled',
                ['operator_account_id' => $account->id, 'device_id' => $device->id], 'WF-JUR-06');
        }

        return $device;
    }

    /** Revoke a device (lost/replaced) — its action signatures stop verifying (fail closed). */
    public function revokeDevice(OperatorDevice $device): void
    {
        if ($device->revoked_at !== null) {
            return;
        }

        $device->revoked_at = now();
        $device->save();

        $this->audit->append('operator_identity', 'device.revoked', ['device_id' => $device->id], 'WF-JUR-06');
    }

    /**
     * Verify an operator device's detached action signature against its enrolled
     * key (key-possession, NEVER the password). Same signing-string format as the
     * citizen device layer — reuse ActorIdentityService::actionSigningString.
     */
    public function verifyActionSignature(OperatorDevice $device, string $signingString, string $signatureB64): bool
    {
        if ($device->revoked_at !== null) {
            return false;
        }

        return InstanceIdentityService::verify((string) $device->device_public_key, $signingString, $signatureB64);
    }

    /**
     * The canonical string an operator device signs to authorize an action — same
     * shape as the instance/citizen signing seam (METHOD \n TARGET \n TS \n
     * sha256(body)), kept self-contained so the operator plane never reaches into
     * the citizen identity service even for a helper.
     */
    public static function actionSigningString(string $method, string $target, int $timestamp, string $body): string
    {
        return strtoupper($method)."\n".$target."\n".$timestamp."\n".hash('sha256', $body);
    }
}
