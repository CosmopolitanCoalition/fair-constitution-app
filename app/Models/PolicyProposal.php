<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-EXE-002 — a department policy proposal. The executive proposes; the
 * department BOARD decides (body_type='board' yes/no chamber vote,
 * ordinary majority) — proposals never bypass the board. `amended` is
 * recorded when the board files amended_text before voting.
 */
class PolicyProposal extends Model
{
    use HasUuids, SoftDeletes;

    public const DECISION_PENDING  = 'pending';
    public const DECISION_ADOPTED  = 'adopted';
    public const DECISION_AMENDED  = 'amended';
    public const DECISION_DECLINED = 'declined';

    protected $fillable = [
        'id',
        'executive_id',
        'department_id',
        'proposed_by_member_id',
        'title',
        'text',
        'board_vote_id',
        'decision',
        'amended_text',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(ExecutiveMember::class, 'proposed_by_member_id');
    }
}
