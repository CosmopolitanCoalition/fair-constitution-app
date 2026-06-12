<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The universal in-body decision record (C-3) — unicameral floor,
 * committee, each bicameral kind, and (Phase D) org boards all ride this
 * one shape. Per-lane thresholds live in chamber_vote_tallies; member
 * votes in vote_casts (PUBLIC — the exact opposite of ballots).
 *
 * IMMUTABLE once closed (no soft deletes — documented exception);
 * corrections are a new vote. All transitions flow through
 * ChamberVoteService (open/cast/close/tiebreak).
 */
class ChamberVote extends Model
{
    use HasUuids;

    public const BODY_LEGISLATURE = 'legislature';
    public const BODY_COMMITTEE   = 'committee';
    public const BODY_BOARD       = 'board';

    public const METHOD_YES_NO = 'yes_no';
    public const METHOD_RCV    = 'rcv';

    public const BASIS_MAJORITY      = 'majority';
    public const BASIS_SUPERMAJORITY = 'supermajority';

    public const STAGE_COMMITTEE = 'committee';
    public const STAGE_FLOOR     = 'floor';

    public const OUTCOME_ADOPTED = 'adopted';
    public const OUTCOME_FAILED  = 'failed';
    public const OUTCOME_TIED    = 'tied';

    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_VOID   = 'void';

    protected $fillable = [
        'id',
        'body_type',
        'body_id',
        'legislature_id',
        'jurisdiction_id',
        'votable_type',
        'votable_id',
        'vote_type',
        'vote_method',
        'threshold_basis',
        'stage',
        'bicameral',
        'serving_snapshot',
        'held_in_session_id',
        'opened_by_member_id',
        'opened_at',
        'closes_at',
        'decided_at',
        'outcome',
        'speaker_tiebreak',
        'rcv_record',
        'status',
    ];

    protected $casts = [
        'bicameral'        => 'boolean',
        'serving_snapshot' => 'integer',
        'opened_at'        => 'datetime',
        'closes_at'        => 'datetime',
        'decided_at'       => 'datetime',
        'speaker_tiebreak' => 'boolean',
        'rcv_record'       => 'array',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LegislatureSession::class, 'held_in_session_id');
    }

    public function tallies(): HasMany
    {
        return $this->hasMany(ChamberVoteTally::class, 'vote_id');
    }

    /**
     * Named voteCasts (not casts) deliberately: Model::casts() is the
     * Laravel 11 attribute-cast definition hook — a `casts()` relation
     * would be merged into getCasts() and explode.
     */
    public function voteCasts(): HasMany
    {
        return $this->hasMany(VoteCast::class, 'vote_id');
    }
}
