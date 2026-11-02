<?php

namespace App\Support;

/**
 * Per-user encryption for external credentials. Each user gets their own AES-256-GCM key,
 * derived from the application key and the user id (HKDF), so a ciphertext copied to another
 * user's row does not decrypt. The user id is also bound in as authenticated data.
 */
class SecretVault
{
    public static function seal(string $userId, string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($userId), OPENSSL_RAW_DATA, $iv, $tag, $userId, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Secret could not be sealed.');
        }

        return 'v1.'.base64_encode($iv.$tag.$cipher);
    }

    public static function open(string $userId, string $sealed): ?string
    {
        if (! str_starts_with($sealed, 'v1.')) {
            return null;
        }
        $raw = base64_decode(substr($sealed, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($userId), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $userId);

        return $plain === false ? null : $plain;
    }

    /** Last characters shown in lists so the owner can tell secrets apart without revealing them. */
    public static function hint(string $plain): string
    {
        return strlen($plain) >= 12 ? '…'.substr($plain, -4) : '';
    }

    private static function key(string $userId): string
    {
        $app = (string) config('app.key');
        if (str_starts_with($app, 'base64:')) {
            $app = base64_decode(substr($app, 7), true) ?: $app;
        }

        return hash_hkdf('sha256', $app, 32, 'cga-user-secret-v1', $userId);
    }
}
