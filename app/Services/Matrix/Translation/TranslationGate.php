<?php

namespace App\Services\Matrix\Translation;

use App\Models\MatrixRoom;

/**
 * Phase K-3 (K3-K) — THE TRANSLATION PRIVACY RAIL (CI invariant). Public-room messages are
 * server-translatable; a PRIVATE / E2EE room's content may NEVER be sent to a CLOUD translator. The rail
 * is enforced HERE, server-side, before the provider is ever touched — so a refused translation leaks no
 * source text at all (not even an attempt). It is content-NEUTRAL and structural: privacy is derived from
 * the MatrixRoom row, and an unknown / tombstoned room FAILS CLOSED (treated as private). A local /
 * on-device provider (isCloud()=false) is admissible everywhere; only the cloud × private combination is
 * forbidden. The full hybrid router is Phase N; the rail is permanent.
 */
class TranslationGate
{
    public function __construct(private readonly TranslationProvider $provider) {}

    /** A room is PRIVATE if it is not public OR an org-private room; unknown/tombstoned → private (fail-closed). */
    public function isPrivate(?MatrixRoom $room): bool
    {
        if ($room === null || $room->tombstoned_at !== null) {
            return true;
        }

        return ! (bool) $room->is_public || $room->room_type === MatrixRoom::ROOM_ORG_PRIVATE;
    }

    public function translate(?MatrixRoom $room, string $text, string $targetLanguage): TranslationResult
    {
        // THE RAIL: a private room + a cloud provider is REFUSED before the provider sees the text.
        if ($this->isPrivate($room) && $this->provider->isCloud()) {
            return new TranslationResult(
                admitted: false,
                reason: "A private room's content can never be sent to a cloud translator (the K3-K privacy rail) "
                    .'— use a local / on-device provider for private conversations.',
            );
        }

        return new TranslationResult(
            admitted: true,
            translated: $this->provider->translate($text, $targetLanguage),
            provider: $this->provider->name(),
        );
    }
}
