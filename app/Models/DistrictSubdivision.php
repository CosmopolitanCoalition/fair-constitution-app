<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase H — a drawn/split ELECTORAL sub-unit of a childless leaf giant
 * (table 2026_10_01). NOT an administrative jurisdiction: it lives outside
 * `jurisdictions` precisely so residency / civic-population / authority queries
 * never see it (design §3.1, §6 C5). The districting pipeline reads these as the
 * virtual leaf-children of the giant; leaves (seats within the resolved band)
 * become districts via the polymorphic junction (legislature_district_jurisdictions
 * .subdivision_id).
 *
 * `geom` / `centroid` are PostGIS columns — read via raw ST_AsGeoJSON when the
 * map needs them (as revealedGeoJson does for jurisdictions); they are not cast
 * attributes.
 */
class DistrictSubdivision extends Model
{
    use HasUuids, SoftDeletes;

    public const METHOD_SPLITLINE = 'splitline';
    public const METHOD_MANUAL = 'manual';
    public const METHOD_COMPOSITE_SYNTHETIC = 'composite_synthetic';

    public const SOURCE_WORLDPOP = 'worldpop_raster';
    public const SOURCE_CIVIC = 'civic';
    public const SOURCE_MANUAL_OVERRIDE = 'manual_override';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'id',
        'map_id',
        'parent_jurisdiction_id',
        'parent_subdivision_id',
        'method',
        'label',
        'population',
        'population_source',
        'population_year',
        'fractional_seats',
        'seats',
        'status',
    ];

    protected $casts = [
        'population'       => 'integer',
        'population_year'  => 'integer',
        'fractional_seats' => 'decimal:6',
        'seats'            => 'integer',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(LegislatureDistrictMap::class, 'map_id');
    }

    public function parentJurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'parent_jurisdiction_id');
    }

    public function parentSubdivision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_subdivision_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_subdivision_id');
    }
}
