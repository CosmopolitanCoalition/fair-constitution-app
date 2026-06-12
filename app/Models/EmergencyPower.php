<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-12 — emergency power (C-9, Art. II §7). The row exists only after
 * the supermajority adoption ('invoked' lives in the vote, never here).
 * Closed cause enum (natural_disaster | actual_invasion), declared
 * duration ≤ min(90, resolved emergency_powers_max_days), area ≤ the
 * declaring legislature's authority, CLK-03 auto-expiry — nothing rolls
 * over silently. Renewals are fresh supermajorities, each ≤ max,
 * extending from CURRENT expiry.
 *
 * `judicial_review_case_id` / `review_outcome` are the Phase E F-JDG-007
 * hook (status already supports under_review | struck | narrowed).
 */
class EmergencyPower extends Model
{
    use HasUuids, SoftDeletes;

    public const CAUSE_NATURAL_DISASTER = 'natural_disaster';
    public const CAUSE_ACTUAL_INVASION  = 'actual_invasion';

    /** Art. II §7 — the CLOSED cause enum; anything else is rejected pre-vote. */
    public const CAUSES = [self::CAUSE_NATURAL_DISASTER, self::CAUSE_ACTUAL_INVASION];

    public const STATUS_ACTIVE       = 'active';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RENEWED      = 'renewed';
    public const STATUS_EXPIRED      = 'expired';
    public const STATUS_STRUCK       = 'struck';
    public const STATUS_NARROWED     = 'narrowed';

    /** Statuses in which the power is LIVE (renewable; agenda slot-1 feed). */
    public const LIVE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_RENEWED, self::STATUS_UNDER_REVIEW, self::STATUS_NARROWED];

    /** Art. II §7 hardened ceiling — declarations and each renewal alike. */
    public const HARD_MAX_DAYS = 90;

    protected $fillable = [
        'id',
        'legislature_id',
        'jurisdiction_id',
        'cause',
        'label',
        'declared_duration_days',
        'area_jurisdiction_id',
        'methods',
        'invoke_vote_id',
        'status',
        'starts_at',
        'expires_at',
        'judicial_review_case_id',
        'review_outcome',
    ];

    protected $casts = [
        'declared_duration_days' => 'integer',
        'starts_at'              => 'datetime',
        'expires_at'             => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function areaJurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'area_jurisdiction_id');
    }

    public function invokeVote(): BelongsTo
    {
        return $this->belongsTo(ChamberVote::class, 'invoke_vote_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(EmergencyPowerRenewal::class, 'emergency_power_id');
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }
}
