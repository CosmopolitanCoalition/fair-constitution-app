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
 * F-SOC-002 — file testimony in a hall: seal one of YOUR own hall posts into the append-only
 * public register (Art. II §2). The post stays live in the commons; the *civic act* of filing
 * lands immutably in public_records via PublicRecordService::publish() (which joins the engine
 * transaction, so the seal + the back-pointer + the audit append are one atomic act), and the
 * thread's published_record_id back-pointer is stamped with the record's UUID (never its seq).
 *
 * Residency-only (R-03). You may only file your OWN statement as testimony. Testimony belongs to
 * the halls (the mandated deliberation record), not the open square.
 */
class SocialTestimonyFiling implements FormHandler
{
    public function __construct(private readonly PublicRecordService $records) {}

    public function module(): string
    {
        return 'records';   // it publishes to the register
    }

    public function event(): string
    {
        return 'testimony.filed';
    }

    public function requiredRoles(): array
    {
        return ['R-03'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation('Testimony is filed by a resident.', 'Art. I');
        }

        $post = SocialPost::query()->find($payload['post_id'] ?? null);
        if ($post === null) {
            throw new ConstitutionalViolation('Unknown post.', 'Art. II §2 · as implemented');
        }

        if ((string) $post->author_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Testimony enters YOUR own statement into the record — a resident cannot file another resident\'s post as testimony.',
                'Art. I'
            );
        }

        $thread = SocialThread::query()->findOrFail($post->thread_id);
        $subforum = SocialSubforum::query()->findOrFail($thread->subforum_id);
        $space = SocialSpace::query()->findOrFail($subforum->space_id);

        if ($space->space_type !== SocialSpace::TYPE_HALLS) {
            throw new ConstitutionalViolation(
                'Testimony is filed in the halls of governance (the Art. II §2 deliberation record), not the open square.',
                'Art. II §2'
            );
        }

        $record = $this->records->publish(
            kind: 'testimony',
            title: sprintf('Testimony — %s', $thread->title),
            body: $post->body,
            attrs: [
                'actor_user_id'   => (string) $actor->getKey(),
                'actor_display'   => $post->author_display,        // pseudonym snapshot — never name/email
                'jurisdiction_id' => (string) $space->jurisdiction_id,
                'legislature_id'  => null,                          // resolved when bound to a seated body (follow-up)
                'via_form'        => 'F-SOC-002',
                'subject_type'    => $subforum->governing_object_type,  // e.g. 'bill' — null for a general subforum
                'subject_id'      => $subforum->governing_object_id,
            ],
        );

        $thread->forceFill(['published_record_id' => $record->id])->save();   // THE back-pointer (uuid, not seq)

        return [
            'record_seq'          => (int) $record->seq,
            'record_id'           => (string) $record->id,
            'thread_id'           => (string) $thread->id,
            'post_id'             => (string) $post->id,
            'published_record_id' => (string) $record->id,
            'jurisdiction_id'     => (string) $space->jurisdiction_id,
        ];
    }
}
