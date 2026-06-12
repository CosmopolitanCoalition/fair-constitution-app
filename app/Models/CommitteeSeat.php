<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ESM-09 — one committee placement. No soft deletes (documented exception):
 * `vacated_at`/`vacated_reason` are the lifecycle; seat rows are history.
 * Partial unique `committee_seats_one_live` guarantees a member never holds
 * two live seats on one committee (assignment algorithm backstop, §C.4).
 */
class CommitteeSeat extends Model
{
    use HasUuids;

    public const STATUS_ALLOCATED  = 'allocated';
    public const STATUS_ASSIGNED   = 'assigned';
    public const STATUS_TIE_BROKEN = 'tie_broken';
    public const STATUS_SEATED     = 'seated';
    public const STATUS_VACATED    = 'vacated';

    public const VIA_ALGORITHM       = 'algorithm';
    public const VIA_TIE_BREAK       = 'tie_break';
    public const VIA_WHOLE_HOUSE_RCV = 'whole_house_rcv';

    protected $fillable = [
        'id',
        'committee_id',
        'member_id',
        'seat_kind',
        'status',
        'assigned_via',
        'preference_rank_honored',
        'seated_at',
        'vacated_at',
        'vacated_reason',
    ];

    protected $casts = [
        'preference_rank_honored' => 'integer',
        'seated_at'               => 'datetime',
        'vacated_at'              => 'datetime',
    ];

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class, 'committee_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'member_id');
    }

    public function scopeLive($query)
    {
        return $query->whereNull('vacated_at');
    }
}
