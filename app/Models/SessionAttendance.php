<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-member attendance for one session (C-2). Unique (session, member);
 * corrections re-record through the upsert — history lives in the audit
 * chain (no soft deletes by design).
 *
 * HARDENED FRAMING (pinned): attendance feeds the quorum CALL and the
 * public record only — it is never a vote denominator. An absent member
 * counts arithmetically as a no against every threshold.
 */
class SessionAttendance extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const STATUS_PRESENT   = 'present';
    public const STATUS_ABSENT    = 'absent';
    public const STATUS_COMPELLED = 'compelled';
    public const STATUS_EXCUSED   = 'excused';

    /** Statuses that count toward the quorum call (WF-LEG-20). */
    public const COUNTED_PRESENT = [self::STATUS_PRESENT, self::STATUS_COMPELLED];

    protected $table = 'session_attendance';

    protected $fillable = [
        'id',
        'session_id',
        'member_id',
        'status',
        'recorded_via_form',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LegislatureSession::class, 'session_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'member_id');
    }
}
