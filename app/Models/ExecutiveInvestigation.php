<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-EXE-004 — a department investigation ordered by the executive.
 * `records_access` is DECLARATIVE jsonb in Phase D (no record-ACL layer
 * until E/F — flagged deferral); findings publication is the operative
 * constitutional duty. The outcome branch files F-EXE-002 / F-EXE-003 or
 * refers to I-ADM intake, or closes with no finding.
 */
class ExecutiveInvestigation extends Model
{
    use HasUuids, SoftDeletes;

    public const OUTCOME_OPEN                 = 'open';
    public const OUTCOME_POLICY_PROPOSAL      = 'policy_proposal';
    public const OUTCOME_REMOVAL_REQUEST      = 'removal_request';
    public const OUTCOME_LEGISLATIVE_REFERRAL = 'legislative_referral';
    public const OUTCOME_CLOSED_NO_FINDING    = 'closed_no_finding';

    protected $fillable = [
        'id',
        'executive_id',
        'department_id',
        'ordered_by_member_id',
        'scope',
        'records_access',
        'findings_record_id',
        'outcome',
        'outcome_ref_type',
        'outcome_ref_id',
    ];

    protected $casts = [
        'records_access' => 'array',
    ];

    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(ExecutiveMember::class, 'ordered_by_member_id');
    }
}
