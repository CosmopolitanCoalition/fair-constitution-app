<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\MatrixEventSnapshot;
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

        // Phase K-3 — a testimony filed from a live Matrix message (Plane B → Plane A). The own-post +
        // halls gates were checked by TestimonyBridgeService against the REAL event; here we seal the
        // snapshot into the SAME append-only register and record the matrix_event_snapshots back-pointer.
        if (isset($payload['matrix_event_id'])) {
            return $this->handleMatrixOrigin($actor, $payload);
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

    /** Seal a Matrix-origin testimony snapshot into public_records (same chain seal as the social path). */
    private function handleMatrixOrigin(User $actor, array $payload): array
    {
        $record = $this->records->publish(
            kind: 'testimony',
            title: 'Testimony (halls)',
            body: (string) ($payload['body_snapshot'] ?? ''),
            attrs: [
                'actor_user_id'   => (string) $actor->getKey(),
                'actor_display'   => (string) ($payload['actor_display'] ?? ''),   // pseudonym — never name/email
                'jurisdiction_id' => (string) ($payload['jurisdiction_id'] ?? ''),
                'legislature_id'  => null,
                'via_form'        => 'F-SOC-002',
                // The Matrix-event link is the matrix_event_snapshots row below, NOT the record's
                // subject (subject_id is a UUID column; a Matrix event id is not one). General testimony.
                'subject_type'    => null,
                'subject_id'      => null,
            ],
        );

        MatrixEventSnapshot::query()->create([
            'matrix_event_id'     => (string) $payload['matrix_event_id'],
            'matrix_room_id'      => (string) ($payload['matrix_room_id'] ?? ''),
            'published_record_id' => $record->id,                    // THE back-pointer (uuid, not seq)
            'actor_display'       => (string) ($payload['actor_display'] ?? ''),
            'origin_server_ts'    => $payload['origin_server_ts'] ?? null,
            'body_snapshot'       => (string) ($payload['body_snapshot'] ?? ''),
        ]);

        return [
            'record_seq'          => (int) $record->seq,
            'record_id'           => (string) $record->id,
            'published_record_id' => (string) $record->id,
            'matrix_event_id'     => (string) $payload['matrix_event_id'],
            'jurisdiction_id'     => (string) ($payload['jurisdiction_id'] ?? ''),
        ];
    }
}
