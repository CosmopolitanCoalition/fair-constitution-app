<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Social\SocialSpaceService;

/**
 * F-SOC-001 — open a thread / post in a public square or hall.
 *
 * Residency is the ONLY gate (R-03 — Art. I; never karma/account-age/reputation). A null
 * actor is bypassed by the engine's role gate, so a square post — which must always have a
 * resident author — throws inside handle() (the PetitionCreation precedent). The recorded
 * array carries pseudonymous identifiers only (author_display), never name/email.
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
        return ['R-03'];   // residency is the only gate (Art. I)
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A square post is authored by a resident — system filing is not defined.',
                'Art. I'
            );
        }

        $result = $this->spaces->openThreadOrPost($actor, $payload);

        return [
            'thread_id'       => (string) $result['thread']->id,
            'post_id'         => (string) $result['post']->id,
            'subforum_id'     => (string) $result['subforum']->id,
            'space_id'        => (string) $result['space']->id,
            'space_type'      => $result['space']->space_type,
            'jurisdiction_id' => (string) ($payload['jurisdiction_id'] ?? $result['space']->jurisdiction_id),
            'author_display'  => $result['post']->author_display,   // pseudonym — never name/email
        ];
    }
}
