<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's PUBLIC vote on a chamber vote (C-3) — Art. II §2: member
 * votes are published with optional explanation, the exact opposite of
 * the secret `ballots` table. Immutable (no updates, no deletes): a
 * member may NOT change a cast — the record is the record.
 *
 * Exactly one of value/rankings is set (DB CHECK). `lane` snapshots the
 * member's seat kind at cast. Every cast publishes a public_records row
 * kind 'vote' (public_record_id).
 */
class VoteCast extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const VALUE_YES     = 'yes';
    public const VALUE_NO      = 'no';
    public const VALUE_ABSTAIN = 'abstain';

    protected $fillable = [
        'id',
        'vote_id',
        'member_id',
        'lane',
        'value',
        'rankings',
        'is_tiebreak',
        'explanation',
        'cast_via_form',
        'public_record_id',
        'cast_at',
    ];

    protected $casts = [
        'rankings'    => 'array',
        'is_tiebreak' => 'boolean',
        'cast_at'     => 'datetime',
    ];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(ChamberVote::class, 'vote_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'member_id');
    }
}
