<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Article IV — Judiciary of a jurisdiction.
 *
 * Default type=appointed (Art IV §1). Minimum 5 judges per race,
 * 10-year terms in lockstep with civil appointments. Conversion to
 * elected requires legislative + constituent supermajority.
 */
class Judiciary extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'court_name',
        'type',
        'min_judges',
        'term_years',
        'status',
        'parent_judiciary_id',
    ];

    protected $casts = [
        'min_judges' => 'integer',
        'term_years' => 'integer',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function parentJudiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'parent_judiciary_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(JudicialSeat::class, 'judiciary_id');
    }
}
