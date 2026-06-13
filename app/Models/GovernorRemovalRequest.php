<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-EXE-003 — a board-member (governor) removal request. Deliberately
 * NOT a removal_proceedings row: governor removal is ORDINARY-MAJORITY
 * hiring-and-firing (owner ruling #14, no Speaker-presides/impeachment
 * trappings) — folding it into the supermajority machinery would invite
 * threshold drift. Grounds are a good-faith finding, published at filing.
 */
class GovernorRemovalRequest extends Model
{
    use HasUuids, SoftDeletes;

    public const OUTCOME_PENDING  = 'pending';
    public const OUTCOME_REMOVED  = 'removed';
    public const OUTCOME_RETAINED = 'retained';

    protected $fillable = [
        'id',
        'board_seat_id',
        'requested_by_member_id',
        'grounds',
        'record_id',
        'vote_id',
        'outcome',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function seat(): BelongsTo
    {
        return $this->belongsTo(BoardSeat::class, 'board_seat_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(ExecutiveMember::class, 'requested_by_member_id');
    }
}
