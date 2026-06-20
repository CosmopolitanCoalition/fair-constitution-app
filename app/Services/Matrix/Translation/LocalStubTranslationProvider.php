<?php

namespace App\Services\Matrix\Translation;

/**
 * Phase K-3 (K3-K) — the DEFAULT, fully-OFFLINE translation provider (the privacy rail's safe default).
 * isCloud() is FALSE, so it is admissible for private AND public rooms — text never leaves the box. The
 * translation itself is a STUB (a marked passthrough); the real on-device NLLB tail + the Haiku tier-1
 * router land in Phase N (the i18n full-scale build). Shipping a non-cloud default means a fresh instance
 * can translate without any rail exception and without any third party.
 */
class LocalStubTranslationProvider implements TranslationProvider
{
    public function translate(string $text, string $targetLanguage): ?string
    {
        // Phase N replaces this with the real local NLLB tail. The marker keeps the seam observable.
        return '['.$targetLanguage.'] '.$text;
    }

    public function isCloud(): bool
    {
        return false; // on-box → safe for private rooms
    }

    public function name(): string
    {
        return 'local-stub';
    }
}
