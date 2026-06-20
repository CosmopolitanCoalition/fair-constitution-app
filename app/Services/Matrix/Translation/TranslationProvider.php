<?php

namespace App\Services\Matrix\Translation;

/**
 * Phase K-3 (K3-K) — the in-conversation translation provider SEAM. A provider answers one thing: given
 * source text + a target language, return a translation (or null if it cannot). It also DECLARES whether
 * it is a CLOUD provider — the single bit the privacy rail keys on (a cloud provider may never see a
 * private room's content). The full NLLB-local-tail + Haiku-tier-1 hybrid ROUTER is Phase N; K3-K ships
 * only the seam + the rail + an offline stub, so an operator can swap in a cloud provider WITHOUT
 * weakening the rail (the gate, not the provider, decides admissibility).
 */
interface TranslationProvider
{
    public function translate(string $text, string $targetLanguage): ?string;

    /** TRUE if this provider sends text off-box to a third party — the bit the privacy rail forbids on a private room. */
    public function isCloud(): bool;

    public function name(): string;
}
