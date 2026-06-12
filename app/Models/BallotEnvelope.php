<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participation record — the ONLY voter-linked row of the ballot pair
 * (ESM-05, B-7). Exists solely for double-vote prevention and the voter's
 * own participation panel: NO content, NO hash, NO receipt — nothing on
 * this row can reach a `ballots` row, and there is deliberately no
 * relation to Ballot. Written only by BallotBox::commit() (WI-B2).
 */
class BallotEnvelope extends Model
{
    use HasUuids;

    public const KIND_RANKED     = 'ranked';
    public const KIND_REFERENDUM = 'referendum';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'race_id',
        'user_id',
        'kind',
        'referendum_question_id',
        'committed_at',
        'created_at',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'race_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
