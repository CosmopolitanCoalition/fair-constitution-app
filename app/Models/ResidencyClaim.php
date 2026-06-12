<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-02 Residency Claim — the state machine between "I live here"
 * (F-IND-003) and verified residency with its full association sweep
 * (F-IND-006):
 *
 *   declared → ping_monitoring → threshold_met → verified → active
 *                                                  └→ superseded / lapsed
 *
 * One OPEN claim per user (partial unique index `residency_claims_one_open_
 * per_user`). All transitions flow through ConstitutionalEngine::file via
 * ResidencyService — never mutate status directly.
 */
class ResidencyClaim extends Model
{
    // HasUuids: client-side uuid so the id is known at create() time — the
    // F-IND-003 audit payload records claim_id inside the same transaction.
    use HasUuids, SoftDeletes;

    public const STATUS_DECLARED        = 'declared';
    public const STATUS_PING_MONITORING = 'ping_monitoring';
    public const STATUS_THRESHOLD_MET   = 'threshold_met';
    public const STATUS_VERIFIED        = 'verified';
    public const STATUS_ACTIVE          = 'active';
    public const STATUS_SUPERSEDED      = 'superseded';
    public const STATUS_LAPSED          = 'lapsed';

    /** States in which the claim is OPEN (blocks a second declaration). */
    public const OPEN_STATUSES = [
        self::STATUS_DECLARED,
        self::STATUS_PING_MONITORING,
        self::STATUS_THRESHOLD_MET,
        self::STATUS_VERIFIED,
        self::STATUS_ACTIVE,
    ];

    /** States in which pings accrue toward the qualifying-day threshold. */
    public const MONITORING_STATUSES = [
        self::STATUS_PING_MONITORING,
        self::STATUS_THRESHOLD_MET,
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'jurisdiction_id',
        'status',
        'declared_at',
        'ping_consent_at',
        'qualifying_days',
        'threshold_days_at_verification',
        'threshold_met_at',
        'verified_at',
        'superseded_at',
        'lapsed_at',
    ];

    protected $casts = [
        'declared_at'                    => 'datetime',
        'ping_consent_at'                => 'datetime',
        'qualifying_days'                => 'integer',
        'threshold_days_at_verification' => 'integer',
        'threshold_met_at'               => 'datetime',
        'verified_at'                    => 'datetime',
        'superseded_at'                  => 'datetime',
        'lapsed_at'                      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function pings(): HasMany
    {
        return $this->hasMany(LocationPing::class, 'claim_id');
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(ResidencyConfirmation::class, 'claim_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isMonitoring(): bool
    {
        return in_array($this->status, self::MONITORING_STATUSES, true);
    }
}
