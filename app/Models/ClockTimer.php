<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One armed instance of a constitutional clock — subject-scoped runtime
 * state for the registry row it references (clocks.id = 'CLK-xx').
 *
 *   armed → fired
 *         → cancelled   (subject resolved before the deadline)
 *         → expired     (window closed without firing)
 *
 * `fires_at` NULL = threshold-watch: no deadline; the EvaluateClocksJob
 * sweep evaluates the watched quantity directly. All transitions flow
 * through ClockService (arm/fire/cancel) — every transition is a chain
 * entry (module 'clocks').
 *
 * `override_value` is the Phase E per-case slot (CLK-11/CLK-12: window set
 * by the judiciary per finding) — present now, written by nothing yet.
 */
class ClockTimer extends Model
{
    use HasUuids, SoftDeletes;

    public const STATE_ARMED     = 'armed';
    public const STATE_FIRED     = 'fired';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_EXPIRED   = 'expired';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'clock_id',
        'jurisdiction_id',
        'subject_type',
        'subject_id',
        'armed_at',
        'fires_at',
        'state',
        'payload',
        'override_value',
    ];

    protected $casts = [
        'armed_at'       => 'datetime',
        'fires_at'       => 'datetime',
        'payload'        => 'array',
        'override_value' => 'array',
    ];

    public function clock(): BelongsTo
    {
        return $this->belongsTo(Clock::class, 'clock_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function scopeArmed($query)
    {
        return $query->where('state', self::STATE_ARMED);
    }

    /** Armed timers whose deadline has passed (threshold-watches excluded). */
    public function scopeDue($query)
    {
        return $query->armed()->whereNotNull('fires_at')->where('fires_at', '<=', now());
    }
}
