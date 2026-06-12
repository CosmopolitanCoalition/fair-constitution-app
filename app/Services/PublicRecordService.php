<?php

namespace App\Services;

use App\Models\PublicRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * C-1 (WF-SYS-03) — the ONLY write path into `public_records`, the
 * curated public register (distinct from the raw audit chain).
 *
 * publish() runs INSIDE the caller's transaction (callers are engine
 * handlers and services already inside ConstitutionalEngine::file()'s
 * transaction): it appends a 'records/published' chain entry first, then
 * inserts the record row carrying that entry's seq in `audit_seq` —
 * sealing the record into the chain. Insert-after-append because the
 * table is append-only (a back-filling UPDATE would be blocked by the
 * immutability trigger).
 *
 * HARD RULE enforced HERE, not in callers (WF-SYS-03 content discipline):
 * never ballot content, never raw coordinates. Chamber vote casts are
 * PUBLIC by constitutional mandate (Art. II §2) and pass freely; the
 * guard blocks the subject types whose content is constitutionally
 * secret/private.
 */
class PublicRecordService
{
    /**
     * Subject types whose content may never reach the public register
     * (ballot secrecy, Art. II §2; location privacy, Art. I posture).
     */
    public const FORBIDDEN_SUBJECT_TYPES = [
        'ballot',
        'ballot_envelope',
        'location_ping',
        'residency_claim_pings',
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Publish one record. Returns the inserted PublicRecord (with seq).
     *
     * @param  string       $kind     one of PublicRecord::KINDS
     * @param  string       $title    headline (public)
     * @param  string|null  $body     full text (public)
     * @param  array{
     *     actor_user_id?: ?string, actor_display?: ?string,
     *     jurisdiction_id?: ?string, legislature_id?: ?string,
     *     via_form?: ?string, via_workflow?: ?string, via_clock?: ?string,
     *     subject_type?: ?string, subject_id?: ?string,
     *     supersedes_record_id?: ?string, translations?: array
     * } $attrs
     */
    public function publish(string $kind, string $title, ?string $body = null, array $attrs = []): PublicRecord
    {
        if (! in_array($kind, PublicRecord::KINDS, true)) {
            throw new InvalidArgumentException("Unknown public-record kind [{$kind}].");
        }

        $subjectType = $attrs['subject_type'] ?? null;

        if ($subjectType !== null
            && in_array(strtolower((string) $subjectType), self::FORBIDDEN_SUBJECT_TYPES, true)) {
            throw new InvalidArgumentException(
                "public_records may never carry [{$subjectType}] content — ballot secrecy / location privacy (WF-SYS-03)."
            );
        }

        $insert = function () use ($kind, $title, $body, $attrs, $subjectType): PublicRecord {
            $id = (string) Str::uuid();

            // Seal first: the chain entry names the record id; the record
            // row carries the entry's seq. Same transaction — no record
            // without its entry, no entry without its record.
            $entry = $this->audit->append(
                module: 'records',
                event: 'published',
                payload: [
                    'record_id'    => $id,
                    'kind'         => $kind,
                    'title'        => $title,
                    'subject_type' => $subjectType,
                    'subject_id'   => $attrs['subject_id'] ?? null,
                    'via_form'     => $attrs['via_form'] ?? null,
                    'via_workflow' => $attrs['via_workflow'] ?? null,
                    'via_clock'    => $attrs['via_clock'] ?? null,
                ],
                ref: $attrs['via_form'] ?? $attrs['via_clock'] ?? $attrs['via_workflow'] ?? null,
                actorId: $attrs['actor_user_id'] ?? null,
                jurisdictionId: $attrs['jurisdiction_id'] ?? null,
            );

            return PublicRecord::create([
                'id'                   => $id,
                'kind'                 => $kind,
                'title'                => $title,
                'body'                 => $body,
                'actor_user_id'        => $attrs['actor_user_id'] ?? null,
                'actor_display'        => $attrs['actor_display'] ?? null,
                'jurisdiction_id'      => $attrs['jurisdiction_id'] ?? null,
                'legislature_id'       => $attrs['legislature_id'] ?? null,
                'via_form'             => $attrs['via_form'] ?? null,
                'via_workflow'         => $attrs['via_workflow'] ?? null,
                'via_clock'            => $attrs['via_clock'] ?? null,
                'subject_type'         => $subjectType,
                'subject_id'           => $attrs['subject_id'] ?? null,
                'audit_seq'            => (int) $entry->seq,
                'translations'         => $attrs['translations'] ?? [],
                'supersedes_record_id' => $attrs['supersedes_record_id'] ?? null,
                'published_at'         => now(),
            ]);
        };

        return DB::transactionLevel() > 0 ? $insert() : DB::transaction($insert);
    }
}
