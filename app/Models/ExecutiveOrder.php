<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * F-EXE-005 — an executive order/decision with PRE-ISSUANCE scope
 * validation (Art. III §2 · Art. II §7).
 *
 * A rejected order is a first-class domain outcome, not just an
 * exception: the row PERSISTS (`rejected_pre_issuance` + the verbatim
 * citation), publishes to public_records, and the engine appends the
 * rejected=true chain entry — all before the 422 surfaces (the Phase D
 * exit-criterion mechanism, OrderScopeValidationTest).
 */
class ExecutiveOrder extends Model
{
    use HasUuids, SoftDeletes;

    public const ENABLING_LAW             = 'law';
    public const ENABLING_EMERGENCY_POWER = 'emergency_power';
    public const ENABLING_CHARTER         = 'charter';

    public const DOMAIN_DEPARTMENT_OPERATIONS = 'department_operations';
    public const DOMAIN_PUBLIC_WORKS          = 'public_works';
    public const DOMAIN_EMERGENCY_RESPONSE    = 'emergency_response';
    public const DOMAIN_ADMINISTRATION        = 'administration';
    public const DOMAIN_OTHER                 = 'other';
    // Auto-reject values (Art. II §7) — kept in the enum so the ATTEMPT
    // is typed honestly.
    public const DOMAIN_ELECTORAL_PROCESS     = 'electoral_process';
    public const DOMAIN_JUDICIAL_PROCESS      = 'judicial_process';
    public const DOMAIN_LEGISLATIVE_PROCESS   = 'legislative_process';

    public const STATUS_DRAFTED               = 'drafted';
    public const STATUS_SCOPE_VALIDATED       = 'scope_validated';
    public const STATUS_ISSUED                = 'issued';
    public const STATUS_REJECTED_PRE_ISSUANCE = 'rejected_pre_issuance';
    public const STATUS_UNDER_REVIEW          = 'under_review';
    public const STATUS_STRUCK                = 'struck';
    public const STATUS_REVOKED               = 'revoked';

    protected $fillable = [
        'id',
        'executive_id',
        'issued_by_member_id',
        'department_id',
        'order_no',
        'title',
        'body',
        'enabling_type',
        'enabling_id',
        'target_domain',
        'status',
        'rejection_citation',
        'rejection_reason',
        'record_id',
        'judicial_review_case_id',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(ExecutiveMember::class, 'issued_by_member_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
