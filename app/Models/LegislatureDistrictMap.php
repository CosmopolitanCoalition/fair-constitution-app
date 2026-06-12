<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Versioned district plan per legislature (substrate model added with
 * WI-B0 — the table dates from 2026_04): draft → active → archived; one
 * active map per legislature (app-layer enforced). Elections snapshot the
 * map they were generated from (elections.district_map_id, restrict on
 * delete — published races pin their plan).
 */
class LegislatureDistrictMap extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'id',
        'legislature_id',
        'name',
        'description',
        'status',
        'effective_start',
        'effective_end',
    ];

    protected $casts = [
        'effective_start' => 'date',
        'effective_end'   => 'date',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(LegislatureDistrict::class, 'map_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
