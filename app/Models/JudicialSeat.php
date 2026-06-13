<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single seat in a judiciary's seat pool (PHASE_E_DESIGN_judiciary §A/§C).
 *
 * Seat classes (mutually exclusive per judiciary in a given era): a court
 * is constituent_nominated OR committee_nominated while appointed; on
 * conversion every appointed seat closes and the elected race writes
 * `elected` seats.
 *
 * `recused` is deliberately ABSENT from the status enum — recusal is a
 * per-case concern owned by the cases agent (tracked on the case/panel row,
 * never here). The seat pool is read-only to anything outside the
 * formation/removal services (the §F structure↔cases contract).
 */
class JudicialSeat extends Model
{
    use HasUuids, SoftDeletes;

    public const CLASS_CONSTITUENT_NOMINATED = 'constituent_nominated';

    public const CLASS_COMMITTEE_NOMINATED = 'committee_nominated';

    public const CLASS_ELECTED = 'elected';

    public const STATUS_VACANT = 'vacant';

    public const STATUS_NOMINATED = 'nominated';

    public const STATUS_SEATED = 'seated';

    public const STATUS_REMOVAL_REQUESTED = 'removal_requested';

    public const STATUS_REMOVED = 'removed';

    public const STATUS_TERM_ENDED = 'term_ended';

    public const STATUS_RETIRED = 'retired';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'judiciary_id',
        'user_id',
        'seat_number',
        'seat_class',
        'nominating_jurisdiction_id',
        'appointment_id',
        'elected_in_race_id',
        'term_id',
        'term_starts_on',
        'term_ends_on',
        'status',
    ];

    protected $casts = [
        'seat_number' => 'integer',
        'term_starts_on' => 'date',
        'term_ends_on' => 'date',
    ];

    public function judiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'judiciary_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function nominatingJurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'nominating_jurisdiction_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function electedInRace(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'elected_in_race_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function scopeSeated($query)
    {
        return $query->where('status', self::STATUS_SEATED);
    }
}
