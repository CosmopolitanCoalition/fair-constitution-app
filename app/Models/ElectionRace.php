<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Race-level slice of ESM-03 (B-4). `district_id` NULL = at-large race —
 * the constitutional default for chambers with seats ≤ 9 and no map
 * (Art. II §8). `finalist_count` is X = finalist_multiplier × seats
 * (CLK-21), frozen at creation and pre-published with the scheduling
 * order. `quota` is the Droop snapshot set at tabulation.
 *
 * Race structure rules (5–9 for chamber races, exactly 1 for `single`,
 * no >9-seat at-large) are engine rules in ConstitutionalValidator
 * (`elections.race_structure`, WI-B4), citation Art. II §8.
 */
class ElectionRace extends Model
{
    use HasUuids, SoftDeletes;

    public const SEAT_KIND_TYPE_A = 'type_a';
    public const SEAT_KIND_TYPE_B = 'type_b';
    public const SEAT_KIND_SINGLE = 'single';

    public const ELECTORATE_RESIDENTS = 'residents';
    public const ELECTORATE_OWNERS    = 'owners';
    public const ELECTORATE_WORKERS   = 'workers';

    protected $fillable = [
        'id',
        'election_id',
        'district_id',
        'jurisdiction_id',
        'seat_kind',
        'seats',
        'finalist_count',
        'electorate_type',
        'quota',
        'total_valid_ballots',
        'status',
    ];

    protected $casts = [
        'seats'               => 'integer',
        'finalist_count'      => 'integer',
        'quota'               => 'integer',
        'total_valid_ballots' => 'integer',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(LegislatureDistrict::class, 'district_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function candidacies(): HasMany
    {
        return $this->hasMany(Candidacy::class, 'race_id');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(ApprovalStanding::class, 'race_id');
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(BallotEnvelope::class, 'race_id');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(Ballot::class, 'race_id');
    }

    public function tabulations(): HasMany
    {
        return $this->hasMany(Tabulation::class, 'race_id');
    }

    public function isAtLarge(): bool
    {
        return $this->district_id === null;
    }
}
