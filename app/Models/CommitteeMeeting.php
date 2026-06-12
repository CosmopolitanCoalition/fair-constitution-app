<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-CHR-001/002 — committee meeting (called by the chair, or the alternate
 * when the chair is absent). `minutes_record_id` soft-refs public_records
 * (append-only sibling table — no FK by design).
 */
class CommitteeMeeting extends Model
{
    use HasUuids;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_OPEN      = 'open';
    public const STATUS_ADJOURNED = 'adjourned';

    protected $fillable = [
        'id',
        'committee_id',
        'called_by_member_id',
        'scheduled_for',
        'agenda',
        'opened_at',
        'adjourned_at',
        'status',
        'minutes_record_id',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'agenda'        => 'array',
        'opened_at'     => 'datetime',
        'adjourned_at'  => 'datetime',
    ];

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class, 'committee_id');
    }

    public function calledBy(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'called_by_member_id');
    }
}
