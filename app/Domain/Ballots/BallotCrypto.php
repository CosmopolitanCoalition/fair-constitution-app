<?php

namespace App\Domain\Ballots;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pure ballot-crypto primitives (design §B.5) — no database, no Laravel
 * container, no I/O. Everything stateful (the single insert path, the
 * wrapped-key column) lives in BallotBox; everything here is a
 * deterministic function of its arguments so the constitutional suite can
 * pin the crypto without a live stack.
 *
 * Posture (stated plainly, never overclaimed — §B.5 + cryptographer list):
 *
 *  - At-rest encryption: XSalsa20-Poly1305 secretbox under a per-election
 *    data key k_e, itself wrapped by a KEK derived from the Laravel app
 *    key. Confidentiality against DB exfiltration, NOT against the server
 *    operator (who holds the app key).
 *  - Commitment: ballot_hash = sha256(salt_hex ‖ canonical_rankings_json).
 *    The 32-byte random salt prevents brute-forcing the small ranking
 *    space from the published hash list. The salt is stored on the ballot
 *    row AND returned in the voter receipt, so both the voter and audit
 *    re-runs can re-verify the commitment.
 *  - Receipt-freeness is explicitly OUT of scope: a {ballot_hash, salt}
 *    receipt *proves* a vote (vote-selling channel). Flagged for a real
 *    cryptographer before production; UI copy must not overclaim.
 *
 * Wire formats:
 *  - wrapped key / encrypted payload: base64( nonce(24) ‖ secretbox )
 *  - salt: 64 lowercase hex chars (the hex string itself — not its raw
 *    bytes — is the hash input, matching what the row and receipt carry)
 *  - canonical rankings: JSON list of candidacy UUID strings, order
 *    preserved (order IS the ballot), no escaping noise.
 */
final class BallotCrypto
{
    /** Domain separation for the app-key → KEK derivation. */
    private const WRAP_CONTEXT = 'cga.ballot-key-wrap.v1';

    private function __construct()
    {
    }

    // -------------------------------------------------------------------------
    // Keys
    // -------------------------------------------------------------------------

    /** Fresh per-election data key k_e (32 random bytes). */
    public static function generateDataKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Derive the key-encryption key from the Laravel app key
     * (`config('app.key')`, with or without the `base64:` prefix).
     * Domain-separated so the KEK is never byte-identical to the app key
     * Laravel uses elsewhere.
     */
    public static function kekFromAppKey(string $appKey): string
    {
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('App key is not valid base64 — cannot derive ballot KEK.');
            }

            $appKey = $decoded;
        }

        if ($appKey === '') {
            throw new RuntimeException('App key is empty — cannot derive ballot KEK.');
        }

        return sodium_crypto_generichash(self::WRAP_CONTEXT . $appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /** Wrap k_e under the KEK → base64(nonce ‖ secretbox) for elections.ballot_key_wrapped. */
    public static function wrapDataKey(string $dataKey, string $kek): string
    {
        if (strlen($dataKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException('Ballot data key must be exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.');
        }

        return self::seal($dataKey, $kek);
    }

    /** Unwrap elections.ballot_key_wrapped → raw k_e. Throws on wrong KEK or tampering. */
    public static function unwrapDataKey(string $wrapped, string $kek): string
    {
        return self::open($wrapped, $kek, 'ballot key unwrap failed — wrong app key or corrupted ballot_key_wrapped');
    }

    // -------------------------------------------------------------------------
    // Canonical rankings + commitment
    // -------------------------------------------------------------------------

    /**
     * Canonical byte representation of a ranking: JSON list of candidacy
     * UUIDs, order preserved. This exact byte string is what gets
     * encrypted AND what the commitment hash binds — encrypt-side and
     * hash-side can never diverge.
     *
     * @param  list<string>  $rankings  ordered candidacy UUIDs, most-preferred first
     */
    public static function canonicalRankings(array $rankings): string
    {
        if ($rankings === [] || ! array_is_list($rankings)) {
            throw new InvalidArgumentException('Rankings must be a non-empty ordered list of candidacy UUIDs.');
        }

        foreach ($rankings as $candidacyId) {
            if (! is_string($candidacyId) || ! Str::isUuid($candidacyId)) {
                throw new InvalidArgumentException('Every ranking entry must be a candidacy UUID string.');
            }
        }

        $normalized = array_map(strtolower(...), $rankings);

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException('Rankings must not repeat a candidacy.');
        }

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Fresh commitment salt: 32 random bytes as 64 hex chars (the stored/receipt form). */
    public static function newSaltHex(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** ballot_hash = sha256(salt_hex ‖ canonical_rankings_json). */
    public static function commitmentHash(string $saltHex, string $canonicalRankings): string
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $saltHex)) {
            throw new InvalidArgumentException('Commitment salt must be 64 lowercase hex characters.');
        }

        return hash('sha256', $saltHex . $canonicalRankings);
    }

    /** Voter self-audit: does {ballot_hash, salt} commit to these rankings? */
    public static function verifyCommitment(string $ballotHash, string $saltHex, array $rankings): bool
    {
        return hash_equals($ballotHash, self::commitmentHash($saltHex, self::canonicalRankings($rankings)));
    }

    // -------------------------------------------------------------------------
    // Payload encryption
    // -------------------------------------------------------------------------

    /** Encrypt a canonical ranking string under k_e → base64(nonce ‖ secretbox). */
    public static function encryptCanonical(string $canonicalRankings, string $dataKey): string
    {
        return self::seal($canonicalRankings, $dataKey);
    }

    /** Decrypt ballots.payload_encrypted back to the canonical ranking string. */
    public static function decryptToCanonical(string $payloadEncrypted, string $dataKey): string
    {
        return self::open($payloadEncrypted, $dataKey, 'ballot payload decryption failed — wrong election key or corrupted payload');
    }

    /**
     * Decrypt straight to the ranking list.
     *
     * @return list<string> ordered candidacy UUIDs
     */
    public static function decryptRankings(string $payloadEncrypted, string $dataKey): array
    {
        $rankings = json_decode(self::decryptToCanonical($payloadEncrypted, $dataKey), true);

        if (! is_array($rankings) || ! array_is_list($rankings)) {
            throw new RuntimeException('Decrypted ballot payload is not a ranking list.');
        }

        return $rankings;
    }

    // -------------------------------------------------------------------------
    // Shared secretbox plumbing
    // -------------------------------------------------------------------------

    private static function seal(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private static function open(string $sealed, string $key, string $errorMessage): string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException($errorMessage);
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);

        if ($plain === false) {
            throw new RuntimeException($errorMessage);
        }

        return $plain;
    }
}
