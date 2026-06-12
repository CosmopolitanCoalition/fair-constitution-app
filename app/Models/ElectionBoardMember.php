<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Election board membership (B-2). `user_id` NULL = THE SYSTEM ITSELF on a
 * bootstrap board — gives every F-ELB filing a board-member provenance
 * without inventing a fake user. The DB CHECK pins that the system row is
 * always seated. R-08 derives from a seated row (RoleService, WI-B4).
 */
class ElectionBoardMember extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_NOMINATED  = 'nominated';
    public const STATUS_SEATED     = 'seated';
    public const STATUS_REMOVED    = 'removed';
    public const STATUS_TERM_ENDED = 'term_ended';

    protected $fillable = [
        'id',
        'election_board_id',
        'user_id',
        'appointment_id',
        'status',
        'term_starts_on',
        'term_ends_on',
    ];

    protected $casts = [
        'term_starts_on' => 'date',
        'term_ends_on'   => 'date',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(ElectionBoard::class, 'election_board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function scopeSeated($query)
    {
        return $query->where('status', self::STATUS_SEATED);
    }

    /** The synthetic system row on a bootstrap board. */
    public function isSystem(): bool
    {
        return $this->user_id === null;
    }
}
