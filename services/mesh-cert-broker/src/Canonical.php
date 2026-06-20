<?php

namespace MeshCertBroker;

/**
 * Byte-for-byte compatible with the CGA's AuditService::canonicalJson +
 * InstanceIdentityService::sign/verify — so a grant signed by a CGA authority instance, and a request
 * signed by a CGA peer instance, verify HERE identically. Key-sorted recursive JSON, UNESCAPED_SLASHES +
 * UNESCAPED_UNICODE; detached Ed25519; SODIUM_BASE64_VARIANT_ORIGINAL base64.
 */
final class Canonical
{
    /** Byte-identical to AuditService::canonicalJson (recurse-first, ksort SORT_STRING, skip LISTS). */
    public static function json(array $payload): string
    {
        $normalized = json_decode(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            true
        ) ?? [];

        return json_encode(self::ksortRecursive($normalized), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Verbatim copy of AuditService::ksortRecursive — only NON-list arrays are sorted, by SORT_STRING. */
    private static function ksortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::ksortRecursive($item);
        }

        if (! $isList) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** Verify a base64 detached signature over $message against a base64 Ed25519 public key. Fails closed. */
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

    /** Constant-time check that $needle is one of $haystack (authorized authority keys). */
    public static function isOneOf(string $needle, array $haystack): bool
    {
        $ok = false;
        foreach ($haystack as $candidate) {
            if (hash_equals((string) $candidate, $needle)) {
                $ok = true;
            }
        }

        return $ok;
    }
}
