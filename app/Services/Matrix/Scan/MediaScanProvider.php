<?php

namespace App\Services\Matrix\Scan;

/**
 * Phase K-3 (K3-I.4) — the M-S media-scan provider SEAM. A scan provider answers exactly one
 * content-neutral question: does this media's HASH match a configured known-illegal list? The ONLY
 * inputs are the hash list + the media hash — there is NO semantic / ML classifier behind this
 * interface (a viewpoint can never be inferred here). Implementations are fully OFFLINE by default
 * (the privacy rail); cloud-API scanners are an opt-in provider the operator supplies.
 */
interface MediaScanProvider
{
    /** Content-neutral hash-list membership — never an inference about meaning. */
    public function matchesKnownIllegal(string $mediaHash): bool;

    /** WHICH list matched (recorded as matched_list_source) — NEVER the hash/locator itself. */
    public function source(): string;
}
