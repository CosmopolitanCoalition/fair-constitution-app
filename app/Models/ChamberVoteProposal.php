<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proposal store for the chamber-ops act-creating votes (F-LEG-009/012/
 * 013/032/033): carries the act payload (name/seats/nominees/law text)
 * between vote OPEN and vote ADOPTION across many member cast filings —
 * chamber_votes (sibling scope) has no payload column, and the institution
 * row itself is created only on adoption (a failed vote leaves a
 * `rejected` proposal, never a half-born institution).
 *
 * `vote_id` soft-refs chamber_votes; `result_type`/`result_id` point at
 * whatever adoption created (committees / election_boards / admin_offices
 * / laws row).
 */
class ChamberVoteProposal extends Model
{
    use HasUuids;

    public const KIND_COMMITTEE_CREATION      = 'committee_creation';
    public const KIND_ELECTION_BOARD_CREATION = 'election_board_creation';
    public const KIND_ADMIN_OFFICE_CREATION   = 'admin_office_creation';
    public const KIND_RULES_OF_ORDER          = 'rules_of_order';
    public const KIND_ETHICS_CODE             = 'ethics_code';

    public const STATUS_OPEN     = 'open';
    public const STATUS_ADOPTED  = 'adopted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'id',
        'legislature_id',
        'proposal_kind',
        'vote_id',
        'payload',
        'proposed_by_member_id',
        'status',
        'decided_at',
        'result_type',
        'result_id',
    ];

    protected $casts = [
        'payload'    => 'array',
        'decided_at' => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'proposed_by_member_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
