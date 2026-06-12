<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ESM-13 Vacancy (B-10):
 *
 *   detected → declared → countback_running → filled
 *                                           → countback_failed →
 *                                             special_election_scheduled
 *
 * Countback = universal full re-run of the race's stored ballots with the
 * vacating candidacy struck; replacement terms inherit the original
 * `ends_on` (CLK-10). If ballots exhaust, the special election is
 * AUTO-scheduled inside [declared_at + min_days, + max_days] (CLK-04
 * backstop — discretion can never produce "no election").
 *
 * `seat_type`/`seat_id` is polymorphic (string enum, app-validated);
 * Phase B writes only 'legislature_members'. F-LEG-036 declaration is
 * Phase C — B writes `declared_via_form = 'dev'` / system detection.
 */
class Vacancy extends Model
{
    use HasUuids;

    public const STATUS_DETECTED           = 'detected';
    public const STATUS_DECLARED           = 'declared';
    public const STATUS_COUNTBACK_RUNNING  = 'countback_running';
    public const STATUS_FILLED             = 'filled';
    public const STATUS_COUNTBACK_FAILED   = 'countback_failed';
    public const STATUS_SPECIAL_SCHEDULED  = 'special_election_scheduled';

    protected $fillable = [
        'id',
        'seat_type',
        'seat_id',
        'legislature_id',
        'jurisdiction_id',
        'declared_by',
        'declared_via_form',
        'status',
        'detected_at',
        'declared_at',
        'countback_tabulation_id',
        'special_election_id',
        'filled_by_user_id',
        'filled_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'declared_at' => 'datetime',
        'filled_at'   => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function countbackTabulation(): BelongsTo
    {
        return $this->belongsTo(Tabulation::class, 'countback_tabulation_id');
    }

    public function specialElection(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'special_election_id');
    }

    public function filledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filled_by_user_id');
    }
}
