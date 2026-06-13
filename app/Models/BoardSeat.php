<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One seat on a unified board (exec design D-2 contract).
 *
 * Classes: departments use governor + worker_elected; private orgs
 * owner_elected + worker_elected; CGCs governor + worker_elected (owner
 * ruling #12 — the BoG stands where shareholders would).
 */
class BoardSeat extends Model
{
    use HasUuids, SoftDeletes;

    public const CLASS_GOVERNOR       = 'governor';
    public const CLASS_OWNER_ELECTED  = 'owner_elected';
    public const CLASS_WORKER_ELECTED = 'worker_elected';

    public const STATUS_VACANT            = 'vacant';
    public const STATUS_NOMINATED         = 'nominated';
    public const STATUS_SEATED            = 'seated';
    public const STATUS_REMOVAL_REQUESTED = 'removal_requested';
    public const STATUS_REMOVED           = 'removed';
    public const STATUS_TERM_ENDED        = 'term_ended';

    protected $fillable = [
        'id',
        'board_id',
        'seat_class',
        'seat_no',
        'holder_user_id',
        'appointment_id',
        'elected_in_race_id',
        'term_id',
        'is_chair',
        'status',
    ];

    protected $casts = [
        'seat_no'  => 'integer',
        'is_chair' => 'boolean',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function electedInRace(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'elected_in_race_id');
    }

    public function scopeSeated($query)
    {
        return $query->where('status', self::STATUS_SEATED);
    }
}
