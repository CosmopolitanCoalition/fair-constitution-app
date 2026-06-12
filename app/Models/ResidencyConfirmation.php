<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confirmed residency association — ONE ROW PER ENCLOSING JURISDICTION
 * (the declared boundary at depth 0, then every ancestor toward root, plus
 * dual-footprint twin chains). Created in bulk by ResidencyService::verify
 * (F-IND-006); the active row IS the right: voting and candidacy unlock
 * atomically with the association and nothing else may gate them (Art. I —
 * the rights booleans default true and exist for historical record, not as
 * toggles).
 *
 * History lives in `is_active` + `deactivated_at` (+ partial unique on
 * active rows) — deliberately NO soft deletes; this table reuses the
 * original residency_confirmations rather than the schema-design's
 * jurisdiction_associations (see the 2026_06_12_000003 migration docblock).
 */
class ResidencyConfirmation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'jurisdiction_id',
        'claim_id',
        'depth',
        'days_confirmed',
        'confirmed_at',
        'voting_right_active',
        'candidacy_right_active',
        'is_active',
        'deactivated_at',
        'deactivation_reason',
    ];

    protected $casts = [
        'depth'                  => 'integer',
        'days_confirmed'         => 'integer',
        'confirmed_at'           => 'datetime',
        'voting_right_active'    => 'boolean',
        'candidacy_right_active' => 'boolean',
        'is_active'              => 'boolean',
        'deactivated_at'         => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResidencyClaim::class, 'claim_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
