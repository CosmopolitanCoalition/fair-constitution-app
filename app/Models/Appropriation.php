<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * D-6 — an appropriation line under an enacted act, administered by an
 * executive. Minimal viable per the executive-actions contract: the
 * register + audit-chained mutation through GrantService only (award ≤
 * remaining; FOR UPDATE discipline).
 */
class Appropriation extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_LAPSED    = 'lapsed';

    protected $fillable = [
        'id',
        'law_id',
        'jurisdiction_id',
        'executive_id',
        'line',
        'amount',
        'remaining',
        'status',
    ];

    protected $casts = [
        'amount'    => 'decimal:2',
        'remaining' => 'decimal:2',
    ];

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_id');
    }

    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(GrantApplication::class, 'appropriation_id');
    }
}
