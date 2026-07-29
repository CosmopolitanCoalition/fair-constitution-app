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
 * Bicameral per Art. V §3, and the two chambers answer DIFFERENT questions
 * (settled 2026-07-26 — CLAUDE.md "Bicameral Support"):
 *
 *   type_a — PROPORTIONAL. Sized from population, max(5, round(pop^(1/3))),
 *            with NO ceiling on the total; above 9 it is districted into
 *            races of 5–9 seats. Earth: 1,999 across 282 districts.
 *   type_b — EQUAL REPRESENTATION OF THE CONSTITUENT JURISDICTIONS. Every
 *            direct constituent gets the same number of seats regardless of
 *            its population, and it is elected AT LARGE — the jurisdiction
 *            IS the district, so it is one STV race however many seats.
 *            A leaf has no type_b; its representation appears in its
 *            PARENT's type_b chamber.
 *
 * This docblock previously read "type_a = constituent reps, type_b =
 * at-large", which has them the wrong way round on the allocation axis. The
 * same inversion in CLAUDE.md is what `racePlan()` was written against, and it
 * blocked every bicameral act on a fresh world until 2026-07-26. Two axes, not
 * one: type_b is at-large as a METHOD and constituent-equal as an ALLOCATION.
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
        'type_b_rep_floor'    => 'integer',
        'type_b_needs_districting' => 'boolean',
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
