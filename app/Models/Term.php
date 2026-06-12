<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A held term of office — the CLK-10 lockstep substrate (B-1).
 *
 * HARDENED (CLK-10, the no-API guarantee): no service exposes an update
 * path for `ends_on` on `lockstep` terms; countback / special-election
 * replacement terms INHERIT the original `ends_on`, never a fresh term.
 * Pinned by TermLockstepTest.
 *
 * `office_type`/`office_id` is the polymorphic seat row (string enum,
 * app-layer validated — same pattern as clock_timers.subject_*); filled
 * after seating. Phase B writes only office_kind `legislature_seat` /
 * `election_board_member`.
 */
class Term extends Model
{
    use HasUuids, SoftDeletes;

    public const CLASS_LOCKSTEP          = 'lockstep';
    public const CLASS_CIVIL_APPOINTMENT = 'civil_appointment';

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VACATED   = 'vacated';
    public const STATUS_REMOVED   = 'removed';

    protected $fillable = [
        'id',
        'office_kind',
        'office_type',
        'office_id',
        'holder_user_id',
        'jurisdiction_id',
        'legislature_id',
        'term_class',
        'starts_on',
        'ends_on',
        'source_election_id',
        'source_appointment_id',
        'status',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on'   => 'date',
    ];

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function sourceElection(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'source_election_id');
    }

    public function sourceAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'source_appointment_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeLockstep($query)
    {
        return $query->where('term_class', self::CLASS_LOCKSTEP);
    }

    public function isLockstep(): bool
    {
        return $this->term_class === self::CLASS_LOCKSTEP;
    }
}
