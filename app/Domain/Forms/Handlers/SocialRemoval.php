<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\SocialPost;
use App\Models\SocialSpace;
use App\Models\SocialSubforum;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\PublicRecordService;

/**
 * F-SOC-003 — a CARVE-OUT removal of a public-square / hall post.
 *
 * The public square cannot be censored (Art. I). The ONLY removals are the four carve-outs;
 * this form carries the office-gated ones (M-1 judicial order / M-2 rights protection). WHO may
 * invoke is the registry role gate — a DERIVED judicial office (R-19/R-20), never a stored
 * "moderator" bit. The validator enforces the carve-out SHAPE + a logged reference.
 *
 * A removal is NOT silent: it FIRST seals an append-only public_records 'violation' entry citing
 * the carve-out + reference (the durable, appealable artifact), THEN soft-deletes the post
 * (best-effort UI removal). The record stands even though the post leaves the timeline.
 */
class SocialRemoval implements FormHandler
{
    public function __construct(private readonly PublicRecordService $records) {}

    public function module(): string
    {
        return 'records';
    }

    public function event(): string
    {
        return 'square.post_removed';
    }

    public function requiredRoles(): array
    {
        return ['R-19', 'R-20'];   // derived judicial office (M-1) — never a stored moderator bit
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $post = SocialPost::query()->find($payload['target_post_id'] ?? null);
        if ($post === null) {
            throw new ConstitutionalViolation('Unknown post.', 'Art. I');
        }

        $thread = SocialThread::query()->findOrFail($post->thread_id);
        $subforum = SocialSubforum::query()->findOrFail($thread->subforum_id);
        $space = SocialSpace::query()->findOrFail($subforum->space_id);

        $carveOut = (string) $payload['carve_out'];
        $reference = (string) $payload['reference'];

        // Log FIRST (append-only, appealable), then remove.
        $record = $this->records->publish(
            kind: 'violation',
            title: sprintf('Public-square removal (%s)', $carveOut),
            body: sprintf('A post was removed under carve-out [%s]; justifying reference: %s.', $carveOut, $reference),
            attrs: [
                'actor_user_id'   => $actor !== null ? (string) $actor->getKey() : null,
                'jurisdiction_id' => (string) $space->jurisdiction_id,
                'via_form'        => 'F-SOC-003',
                'subject_type'    => 'social_post',
                'subject_id'      => (string) $post->id,
            ],
        );

        $post->delete();   // soft-delete — the logged record is the durable artifact, not the bytes' disappearance

        return [
            'removed_post_id' => (string) $post->id,
            'carve_out'       => $carveOut,
            'reference'       => $reference,
            'record_id'       => (string) $record->id,
            'jurisdiction_id' => (string) $space->jurisdiction_id,
        ];
    }
}
