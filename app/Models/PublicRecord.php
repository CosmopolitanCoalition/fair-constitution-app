<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the WF-SYS-03 public register (C-1) — the curated,
 * citizen-readable publication layer, distinct from the raw audit chain.
 *
 * APPEND-ONLY: no updates, no deletes (database triggers enforce).
 * Corrections append a new row pointing back via supersedes_record_id.
 * All writes flow through PublicRecordService::publish() inside the
 * caller's transaction; `audit_seq` seals each record to the chain.
 *
 * PK is `seq` (publication order); `id` is the cross-instance uuid.
 */
class PublicRecord extends Model
{
    public const UPDATED_AT = null;

    public const KINDS = [
        'registration', 'residency', 'participation', 'statement', 'vote',
        'bill', 'act', 'minutes', 'opinion', 'certification', 'testimony',
        'violation', 'correction', 'other',
        // Phase K-3: the legitimacy-flip log + M-5 legal-compliance removal (counted separately
        // from the judicial/viewpoint 'violation' kind — a spike in legal removals is itself a flag).
        'moderation_flip', 'legal_compliance_removal',
    ];

    protected $table = 'public_records';

    protected $primaryKey = 'seq';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'kind',
        'title',
        'body',
        'actor_user_id',
        'actor_display',
        'jurisdiction_id',
        'legislature_id',
        'via_form',
        'via_workflow',
        'via_clock',
        'subject_type',
        'subject_id',
        'audit_seq',
        'translations',
        'supersedes_record_id',
        'published_at',
        'source_server_id',
    ];

    protected $casts = [
        'translations' => 'array',
        'published_at' => 'datetime',
        'audit_seq' => 'integer',
    ];
}
