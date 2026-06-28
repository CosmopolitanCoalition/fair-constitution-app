<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Social\SocialSpaceService;

/**
 * F-SOC-001 — open a thread / post in a public square or hall.
 *
 * The public commons is OPEN (Art. I — free movement + equal treatment): ANY authenticated player
 * may post, resident OR visitor. Residency gates governance POWERS (voting, candidacy, role tools,
 * and the testimony SEAL — F-SOC-002, which stays R-03-gated), NOT access to the square or halls.
 * The role gate is therefore EMPTY (requiredRoles []); but a square post still needs a real author,
 * so a null actor (system filing) is undefined and throws inside handle() (the PetitionCreation
 * precedent). The recorded array carries pseudonymous identifiers only (author_display), never
 * name/email.
 *
 * (Corrected 2026-06-27: the prior rule residency-gated commons ACCESS; the operator's constitutional
 * correction opens BOTH the live Matrix commons (Plane B) AND this recorded plane (Plane A) — only
 * POWERS are residency-gated, enforced elsewhere.)
 */
class SocialThreadPost implements FormHandler
{
    public function __construct(private readonly SocialSpaceService $spaces) {}

    public function module(): string
    {
        return 'social';
    }

    public function event(): string
    {
        return 'post.created';
    }

    public function requiredRoles(): array
    {
        return [];   // the public commons is OPEN — any player may post (Art. I); powers are gated elsewhere
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A square post is authored by a real player — system filing is not defined.',
                'Art. I'
            );
        }

        $result = $this->spaces->openThreadOrPost($actor, $payload);

        return [
            'thread_id' => (string) $result['thread']->id,
            'post_id' => (string) $result['post']->id,
            'subforum_id' => (string) $result['subforum']->id,
            'space_id' => (string) $result['space']->id,
            'space_type' => $result['space']->space_type,
            'jurisdiction_id' => (string) ($payload['jurisdiction_id'] ?? $result['space']->jurisdiction_id),
            'author_display' => $result['post']->author_display,   // pseudonym — never name/email
        ];
    }
}
