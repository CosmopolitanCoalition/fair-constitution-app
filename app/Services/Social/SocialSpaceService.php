<?php

namespace App\Services\Social;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\SocialPost;
use App\Models\SocialSpace;
use App\Models\SocialSubforum;
use App\Models\SocialThread;
use App\Models\User;

/**
 * Phase K-1 — opens threads and appends posts in a jurisdiction's public square or halls.
 *
 * The space is created on demand (one public square + one halls per jurisdiction). A post
 * either opens a new thread (title required) or replies to an existing one. The civic act
 * is delegated here from the F-SOC-001 handler, which runs inside the engine transaction —
 * so the space/thread/post creation commits atomically with the audit entry.
 *
 * NOTE on official speech: is_official / acting_seat are NOT trusted from input in K-1 (an
 * unvalidated authority claim would be a forgery surface). They default false/null; validated
 * officeholder speech (checked against LIVE derived roles) is a follow-up. The square is
 * residency-only and uncensorable regardless.
 */
class SocialSpaceService
{
    /**
     * @return array{space: SocialSpace, subforum: SocialSubforum, thread: SocialThread, post: SocialPost}
     */
    public function openThreadOrPost(User $actor, array $payload): array
    {
        $jurisdictionId = (string) ($payload['jurisdiction_id'] ?? '');
        if ($jurisdictionId === '') {
            throw new ConstitutionalViolation('A square post must name its jurisdiction.', 'Art. I');
        }

        $spaceType = (string) ($payload['space_type'] ?? SocialSpace::TYPE_PUBLIC_SQUARE);
        if (! in_array($spaceType, [SocialSpace::TYPE_PUBLIC_SQUARE, SocialSpace::TYPE_HALLS], true)) {
            throw new ConstitutionalViolation('Unknown space type.', 'Art. I');
        }

        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            throw new ConstitutionalViolation('A post needs a body.', 'Art. I');
        }

        $display = (string) ($actor->display_name ?? $actor->name);

        $space = SocialSpace::query()->firstOrCreate(
            ['jurisdiction_id' => $jurisdictionId, 'space_type' => $spaceType, 'is_private' => false],
            [
                'title'  => $spaceType === SocialSpace::TYPE_HALLS ? 'Halls of Governance' : 'Public Square',
                'status' => SocialSpace::STATUS_OPEN,
            ],
        );

        $subforum = $this->resolveSubforum($space, $payload);
        $thread = $this->resolveThread($subforum, $actor, $display, $payload);

        $post = SocialPost::query()->create([
            'thread_id'      => $thread->id,
            'author_user_id' => (string) $actor->getKey(),
            'author_display' => $display,
            'body'           => $body,
            'is_official'    => false,   // not trusted from input in K-1 (forgery surface)
            'acting_seat'    => null,
        ]);

        return ['space' => $space, 'subforum' => $subforum, 'thread' => $thread, 'post' => $post];
    }

    private function resolveSubforum(SocialSpace $space, array $payload): SocialSubforum
    {
        if (! empty($payload['subforum_id'])) {
            return SocialSubforum::query()
                ->where('space_id', $space->id)
                ->findOrFail($payload['subforum_id']);
        }

        // The space's default general subforum (null governing object — not under the
        // auto-bind partial-unique, which only constrains object-bound subforums).
        return SocialSubforum::query()->firstOrCreate(
            ['space_id' => $space->id, 'governing_object_type' => null, 'governing_object_id' => null],
            ['title' => 'General Discussion', 'status' => SocialSubforum::STATUS_OPEN],
        );
    }

    private function resolveThread(SocialSubforum $subforum, User $actor, string $display, array $payload): SocialThread
    {
        if (! empty($payload['thread_id'])) {
            return SocialThread::query()
                ->where('subforum_id', $subforum->id)
                ->findOrFail($payload['thread_id']);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new ConstitutionalViolation('A new thread needs a title.', 'Art. I');
        }

        return SocialThread::query()->create([
            'subforum_id'    => $subforum->id,
            'author_user_id' => (string) $actor->getKey(),
            'author_display' => $display,
            'title'          => $title,
            'status'         => SocialThread::STATUS_OPEN,
        ]);
    }
}
