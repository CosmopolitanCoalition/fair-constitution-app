<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PUBLIC daily approval aggregate per (candidacy, day) — ESM-04 (B-6).
 * Written ONLY by the daily ApprovalStandingsRollupJob (never per-request —
 * Earth-scale rule); `is_frozen = true` marks the finalist-cutoff snapshot
 * archived to the audit chain. This table is the ONLY surface through
 * which approval data leaves the system.
 */
class ApprovalStanding extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'race_id',
        'candidacy_id',
        'as_of_date',
        'approvals_count',
        'rank',
        'delta',
        'is_frozen',
        'created_at',
    ];

    protected $casts = [
        'as_of_date'      => 'date',
        'approvals_count' => 'integer',
        'rank'            => 'integer',
        'delta'           => 'integer',
        'is_frozen'       => 'boolean',
        'created_at'      => 'datetime',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'race_id');
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class, 'candidacy_id');
    }

    public function scopeFrozen($query)
    {
        return $query->where('is_frozen', true);
    }
}
