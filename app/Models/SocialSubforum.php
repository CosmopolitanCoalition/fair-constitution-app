<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — a subforum within a space, auto-bound (one per live governance object) by the
 * SubforumReconciler. The partial-unique on (governing_object_type, governing_object_id) is
 * the reconciler's idempotency key; a closed object's subforum flips to 'archived', never deleted.
 */
class SocialSubforum extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_OPEN     = 'open';
    public const STATUS_ARCHIVED = 'archived';

    // The governance-object vocabulary — matches the public_records subject_type convention.
    public const OBJECT_BILL               = 'bill';
    public const OBJECT_REFERENDUM_QUESTION = 'referendum_question';
    public const OBJECT_PETITION           = 'petition';
    public const OBJECT_COMMITTEE_MEETING  = 'committee_meeting';
    public const OBJECT_CANDIDACY          = 'candidacy';

    protected $fillable = [
        'id',
        'space_id',
        'governing_object_type',
        'governing_object_id',
        'title',
        'status',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(SocialSpace::class, 'space_id');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(SocialThread::class, 'subforum_id');
    }
}
