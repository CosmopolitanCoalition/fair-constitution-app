<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WF-JUR-01 bootstrap tracker — one row per jurisdiction that has begun
 * activating (absence of a row = dormant boundary). All transitions flow
 * through ActivationService; each step is audit-chained.
 *
 *   boundary_loaded → critical_population → bootstrapping → self_governing
 */
class JurisdictionActivation extends Model
{
    use HasUuids, SoftDeletes;

    public const STATE_BOUNDARY_LOADED     = 'boundary_loaded';
    public const STATE_CRITICAL_POPULATION = 'critical_population';
    public const STATE_BOOTSTRAPPING       = 'bootstrapping';
    public const STATE_SELF_GOVERNING      = 'self_governing';

    /** Forward order of the Phase A activation pipeline. */
    public const STATE_ORDER = [
        self::STATE_BOUNDARY_LOADED,
        self::STATE_CRITICAL_POPULATION,
        self::STATE_BOOTSTRAPPING,
        self::STATE_SELF_GOVERNING,
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'state',
        'critical_population_at',
        'activated_at',
        'legislature_id',
        'notes',
    ];

    protected $casts = [
        'critical_population_at' => 'datetime',
        'activated_at'           => 'datetime',
        'notes'                  => 'array',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    /** True when $state is at or past this row's current state. */
    public function hasReached(string $state): bool
    {
        $current = array_search($this->state, self::STATE_ORDER, true);
        $target  = array_search($state, self::STATE_ORDER, true);

        return $current !== false && $target !== false && $current >= $target;
    }
}
