<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * District within a versioned district plan (substrate model added with
 * WI-B0 — the table dates from 2026_02; controllers previously used
 * query-builder access). Seats are Webster-rounded, constitutionally 5–9.
 * Member jurisdictions live in legislature_district_jurisdictions (join);
 * there is no unioned polygon on the row (geom dropped 2026-04-23).
 */
class LegislatureDistrict extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'legislature_id',
        'jurisdiction_id',
        'district_number',
        'seats',
        'target_population',
        'actual_population',
        'status',
        'fractional_seats',
        'floor_override',
        'map_id',
        'num_geom_parts',
        'is_contiguous',
        'convex_hull_ratio',
    ];

    protected $casts = [
        'district_number'   => 'integer',
        'seats'             => 'integer',
        'target_population' => 'integer',
        'actual_population' => 'integer',
        'fractional_seats'  => 'decimal:6',
        'floor_override'    => 'boolean',
        'num_geom_parts'    => 'integer',
        'is_contiguous'     => 'boolean',
        'convex_hull_ratio' => 'decimal:6',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function map(): BelongsTo
    {
        return $this->belongsTo(LegislatureDistrictMap::class, 'map_id');
    }

    public function races(): HasMany
    {
        return $this->hasMany(ElectionRace::class, 'district_id');
    }
}
