<?php

namespace App\Services\Matrix\Translation;

/** Phase K-3 (K3-K) — the outcome of a gated translation. On a rail refusal, `translated` is NULL and no
 *  source text was ever sent to the provider (the reason names the rail). */
final class TranslationResult
{
    public function __construct(
        public readonly bool $admitted,
        public readonly ?string $translated = null,
        public readonly ?string $provider = null,
        public readonly ?string $reason = null,
    ) {}
}
