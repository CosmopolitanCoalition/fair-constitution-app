<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-ELB-006 audit re-run order (B-9) — the "recount" reframing: always a
 * re-tabulation of the stored ballots, never a hand count. Requires a
 * stated cause; engine gate: creatable only when a certification exists.
 * Outcome 'corrected' triggers a superseding certification via the
 * F-ELB-004 path.
 */
class ElectionAudit extends Model
{
    use HasUuids;

    public const OUTCOME_REAFFIRMED = 'reaffirmed';
    public const OUTCOME_CORRECTED  = 'corrected';

    protected $fillable = [
        'id',
        'election_id',
        'race_id',
        'cause',
        'ordered_by',
        'ordered_at',
        'tabulation_id',
        'outcome',
        'resolved_at',
    ];

    protected $casts = [
        'ordered_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'race_id');
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function tabulation(): BelongsTo
    {
        return $this->belongsTo(Tabulation::class, 'tabulation_id');
    }
}
