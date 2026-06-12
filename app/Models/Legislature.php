<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Legislature instance (substrate model added with WI-B0 — the table dates
 * from 2026_01; controllers previously used query-builder access).
 * `status` flips forming → active at first certification (F-ELB-004).
 * Bicameral per Art. V §3: type_a = constituent reps, type_b = at-large.
 */
class Legislature extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_FORMING   = 'forming';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_DISSOLVED = 'dissolved';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'term_number',
        'term_starts_on',
        'term_ends_on',
        'status',
        'total_seats',
        'type_a_seats',
        'type_b_seats',
        'speaker_id',
        'quorum_required',
        'last_met_on',
        'next_meeting_due_by',
        'parent_legislature_id',
    ];

    protected $casts = [
        'term_number'         => 'integer',
        'term_starts_on'      => 'date',
        'term_ends_on'        => 'date',
        'total_seats'         => 'integer',
        'type_a_seats'        => 'integer',
        'type_b_seats'        => 'integer',
        'quorum_required'     => 'integer',
        'last_met_on'         => 'date',
        'next_meeting_due_by' => 'date',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LegislatureMember::class, 'legislature_id');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(LegislatureDistrict::class, 'legislature_id');
    }

    public function districtMaps(): HasMany
    {
        return $this->hasMany(LegislatureDistrictMap::class, 'legislature_id');
    }

    public function elections(): HasMany
    {
        return $this->hasMany(Election::class, 'legislature_id');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class, 'legislature_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_legislature_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_legislature_id');
    }
}
