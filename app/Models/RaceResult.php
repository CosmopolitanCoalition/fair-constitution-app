<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-candidacy outcome of a tabulation (B-8). `vote_share_norm` is the
 * normalized-quota share — the committee tie-break input (q-ledger #2),
 * computed once here and copied to the legislature_members row at seating.
 * `runner_up_rank` (1–4 sequential-exclusion advisors) is written by
 * Phase D individual-executive races.
 */
class RaceResult extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'tabulation_id',
        'candidacy_id',
        'round_elected',
        'seat_no',
        'vote_share_norm',
        'is_runner_up',
        'runner_up_rank',
        'created_at',
    ];

    protected $casts = [
        'round_elected'   => 'integer',
        'seat_no'         => 'integer',
        'vote_share_norm' => 'decimal:4',
        'is_runner_up'    => 'boolean',
        'runner_up_rank'  => 'integer',
        'created_at'      => 'datetime',
    ];

    public function tabulation(): BelongsTo
    {
        return $this->belongsTo(Tabulation::class, 'tabulation_id');
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class, 'candidacy_id');
    }

    public function scopeElected($query)
    {
        return $query->whereNotNull('seat_no');
    }
}
