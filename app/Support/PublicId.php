<?php

namespace App\Support;

use Sqids\Sqids;

/**
 * PublicId — the pretty-URL foundation (mockups-v3-wiring Phase 1).
 *
 * Two flavours, one home:
 *   - generate(): a RANDOM URL-safe base62 short id (crypto-grade via
 *     random_int) — for rows whose public reference carries no meaning
 *     (support reports, share handles, …);
 *   - sqids(): the ONE project-configured Sqids instance — for later phases
 *     that ENCODE numeric ids (sequence numbers, composite keys) into short
 *     ids. Centralised here so every caller shares the same alphabet and no
 *     two surfaces mint incompatible encodings.
 */
final class PublicId
{
    /** URL-safe base62 — no lookalike trimming; ids are copy-pasted, not read aloud. */
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * The project alphabet for Sqids ENCODINGS (numeric → short id).
     * Shuffled base62 — a Sqids alphabet must be constant forever once ids
     * are in the wild; never reorder it.
     */
    private const SQIDS_ALPHABET = 'k3G7QAe51FCsPW92uEOyq4Bg6Sp8YzVTmnU0liwDdHXLajZrfxNhobJIRcMvKt';

    private const SQIDS_MIN_LENGTH = 8;

    private static ?Sqids $sqids = null;

    /** A random URL-safe base62 id, e.g. "h8Kq2ZrW1x". */
    public static function generate(int $length = 10): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $id = '';
        for ($i = 0; $i < $length; $i++) {
            $id .= self::ALPHABET[random_int(0, $max)];
        }

        return $id;
    }

    /** The shared, project-configured Sqids instance (one alphabet everywhere). */
    public static function sqids(): Sqids
    {
        return self::$sqids ??= new Sqids(self::SQIDS_ALPHABET, self::SQIDS_MIN_LENGTH);
    }
}
