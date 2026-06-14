<?php

namespace App\Services\Federation;

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * This instance's federation identity (Phase F).
 *
 * Mints + holds a stable `server_id` and an Ed25519 signing keypair on the
 * `instance_settings` singleton. The private key is encrypted at rest with
 * Laravel Crypt (APP_KEY-derived) and is used only to SIGN our outbound peer
 * messages; peers verify against the public half they pinned at handshake.
 *
 * Identity generation is idempotent — `ensureIdentity()` is a no-op once minted,
 * so it is safe to call on every `migrate` / boot. Re-keying is an explicit
 * `rotate()` (a fresh clone of an instance should rotate so two peers never
 * share an identity).
 */
class InstanceIdentityService
{
    /**
     * Mint server_id + keypair if absent. Idempotent. Returns the singleton.
     */
    public function ensureIdentity(): InstanceSettings
    {
        $settings = InstanceSettings::current();

        if ($settings->server_id !== null
            && $settings->public_key !== null
            && $settings->private_key_encrypted !== null) {
            return $settings;
        }

        return $this->mint($settings);
    }

    /** Force a fresh identity (new server_id + keypair). */
    public function rotate(): InstanceSettings
    {
        $settings = InstanceSettings::current();
        $settings->server_id = null; // mint() generates a fresh one

        return $this->mint($settings);
    }

    public function serverId(): string
    {
        return (string) $this->ensureIdentity()->server_id;
    }

    /** Base64 Ed25519 public key (the half shared with peers). */
    public function publicKey(): string
    {
        return (string) $this->ensureIdentity()->public_key;
    }

    public function isEnabled(): bool
    {
        return (bool) InstanceSettings::current()->federation_enabled;
    }

    public function setEnabled(bool $enabled): InstanceSettings
    {
        $settings = InstanceSettings::current();
        $settings->federation_enabled = $enabled;
        $settings->save();

        return $settings;
    }

    /**
     * Detached Ed25519 signature over $message, base64-encoded.
     */
    public function sign(string $message): string
    {
        $settings = $this->ensureIdentity();

        $secret = sodium_base642bin(
            Crypt::decryptString((string) $settings->private_key_encrypted),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );

        $signature = sodium_crypto_sign_detached($message, $secret);

        return sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /**
     * Verify a base64 detached signature against a base64 public key.
     * Returns false on any malformed input rather than throwing.
     */
    public static function verify(string $publicKeyB64, string $message, string $signatureB64): bool
    {
        try {
            $publicKey = sodium_base642bin($publicKeyB64, SODIUM_BASE64_VARIANT_ORIGINAL);
            $signature = sodium_base642bin($signatureB64, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            return false;
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    /**
     * The identity payload shared at handshake (server_id + public_key +
     * instance metadata). Signed by the caller before transmission.
     *
     * @return array{server_id: string, public_key: string, name: string, schema_version: string}
     */
    public function handshakePayload(): array
    {
        $settings = $this->ensureIdentity();

        return [
            'server_id' => (string) $settings->server_id,
            'public_key' => (string) $settings->public_key,
            'name' => (string) $settings->instance_name,
            'schema_version' => config('cga.schema_version', '1'),
        ];
    }

    private function mint(InstanceSettings $settings): InstanceSettings
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('ext-sodium is required to mint a federation identity.');
        }

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $settings->server_id = $settings->server_id ?? (string) \Illuminate\Support\Str::uuid();
        $settings->public_key = sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_ORIGINAL);
        $settings->private_key_encrypted = Crypt::encryptString(
            sodium_bin2base64($secretKey, SODIUM_BASE64_VARIANT_ORIGINAL)
        );
        $settings->signing_key_generated_at = now();
        $settings->save();

        // Best-effort wipe of the raw secret from memory.
        sodium_memzero($secretKey);

        return $settings;
    }
}
