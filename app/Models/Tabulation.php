<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One count run per race (B-8): initial, audit_rerun (the "recount" —
 * always a re-run of the stored ballots, never a hand count), or countback
 * (universal full re-run at original seat count with `excluded_candidacy_id`
 * struck — no other filter of any kind, q-ledger #q6; the DB CHECK pins
 * the exclusion to countbacks only).
 *
 * `engine_version` snapshots VoteCountingService::VERSION; `record_hash`
 * (sha256 of the canonical full round record) is sealed into the audit
 * chain on completion.
 */
class Tabulation extends Model
{
    use HasUuids;

    public const KIND_INITIAL     = 'initial';
    public const KIND_AUDIT_RERUN = 'audit_rerun';
    public const KIND_COUNTBACK   = 'countback';

    public const STATUS_RUNNING    = 'running';
    public const STATUS_COMPLETE   = 'complete';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'id',
        'race_id',
        'kind',
        'excluded_candidacy_id',
        'engine_version',
        'total_valid',
        'quota',
        'seats',
        'status',
        'started_at',
        'completed_at',
        'record_hash',
    ];

    protected $casts = [
        'total_valid'  => 'integer',
        'quota'        => 'integer',
        'seats'        => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'race_id');
    }

    public function excludedCandidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class, 'excluded_candidacy_id');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(TabulationRound::class, 'tabulation_id')->orderBy('round_no');
    }

    public function results(): HasMany
    {
        return $this->hasMany(RaceResult::class, 'tabulation_id');
    }

    public function scopeComplete($query)
    {
        return $query->where('status', self::STATUS_COMPLETE);
    }
}
