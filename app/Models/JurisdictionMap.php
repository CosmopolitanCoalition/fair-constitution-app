<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A versioned jurisdiction boundary plan (Phase F). Mirrors
 * legislature_district_maps: draft → active → archived; one active per root.
 */
class JurisdictionMap extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'id', 'root_jurisdiction_id', 'name', 'description', 'status',
        'version_no', 'origin', 'origin_process_id', 'effective_start', 'effective_end',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'effective_start' => 'date',
        'effective_end' => 'date',
    ];
}
