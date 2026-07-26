<?php

namespace App\Domain\Engine;

use RuntimeException;

/**
 * Thrown when a filing breaches a constitutional rule (hardened or
 * bounds-checked). Carries the constitutional citation so rejections are
 * recorded — and surfaced to users — with their legal basis, e.g.
 * "legislature_max_seats 9→12 blocked: exceeds hardened ceiling (Art. II §2)".
 *
 * The ConstitutionalEngine catches this, appends a rejected=true entry to
 * the audit chain (rejections are first-class chain entries, WF-SYS-04),
 * and rethrows for the HTTP layer to render as a 422.
 */
class ConstitutionalViolation extends RuntimeException
{
    /**
     * The house marker for "this rule is the engine's implementation of the
     * article, not the article's literal text" — 119 uses across 52 files.
     */
    private const IMPLEMENTATION_MARKER = ' · as implemented';

    /** How that same honest distinction reads to a citizen. */
    private const IMPLEMENTATION_IN_WORDS = ', as this app applies it';

    public function __construct(
        string $message,
        public readonly string $citation,
    ) {
        parent::__construct($message);
    }

    /**
     * The citation as a PERSON should read it.
     *
     * `· as implemented` is code-comment register standing in copy a citizen
     * reads, which is what lane 6 caught on the economy refusals. But it is
     * also doing honest work: it marks the difference between what an article
     * SAYS and how this engine APPLIES it. Deleting it would make the app
     * assert "Art. I says this" where Art. I does not literally say it —
     * a truthfulness change dressed as a copy tidy, on an app whose whole
     * premise is that citations are exact.
     *
     * So the distinction survives and only the register changes. It lives
     * here rather than at the render site because the exception owns its own
     * citation, and because one place means every module reads the same —
     * inconsistency in constitutional copy teaches that citations are
     * approximate.
     *
     * The MACHINE value (`$citation`) is untouched: the audit chain, the
     * logs, the JSON 422 and the pins that assert on it all keep the exact
     * marker.
     */
    public function citationForAPerson(): string
    {
        return str_replace(self::IMPLEMENTATION_MARKER, self::IMPLEMENTATION_IN_WORDS, $this->citation);
    }
}
