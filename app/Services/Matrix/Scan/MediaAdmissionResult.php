<?php

namespace App\Services\Matrix\Scan;

/** Phase K-3 (K3-I.4) — the outcome of an M-S admission scan. On a block, the LIST SOURCE is recorded
 *  (for matched_list_source) — NEVER the hash/locator (republishable harm). */
final class MediaAdmissionResult
{
    public function __construct(
        public readonly bool $admitted,
        public readonly ?string $matchedListSource = null,
    ) {}
}
