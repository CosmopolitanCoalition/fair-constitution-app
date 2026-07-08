<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stored geodata-quality flag produced by GeodataFlagService::scan().
 *
 * Open flags are derived artifacts — a rescan of a category deletes and
 * re-detects them. Accepted/resolved flags persist across rescans: the scan
 * skips inserting any finding whose `fingerprint` already exists in a
 * non-open status, so an operator's "this is fine" sticks.
 *
 * HasUuids is load-bearing here (as everywhere): the id column has a
 * gen_random_uuid() DB default, and without the trait a create() leaves the
 * in-memory model with id=NULL — see the InstanceSettings class note.
 */
class GeodataFlag extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const CATEGORIES = [
        'dual_coverage',
        'mis_anchored_cluster',
        'same_space_chain',
        'raster_coverage',
        'displaced_geometry',
        'orphaned_rows',
    ];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    public const STATUSES = ['open', 'accepted', 'resolved'];

    protected $table = 'geodata_flags';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'category',
        'severity',
        'jurisdiction_id',
        'related_jurisdiction_id',
        'title',
        'payload',
        'fingerprint',
        'suggested_action',
        'status',
        'resolution',
        'detected_at',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'payload'     => 'array',
        'resolution'  => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function relatedJurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'related_jurisdiction_id');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(GeodataRepair::class, 'flag_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
