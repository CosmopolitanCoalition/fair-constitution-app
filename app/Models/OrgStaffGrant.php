<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * R-31 substrate (F-ORG-011, IO-5): a scoped staff delegation. One active row
 * lets `grantee_user_id` act on the coarse `task` bucket for `organization_id`.
 * It confers no constitutional office (Art. I economic freedom, agent-delegated)
 * and derives R-31 only. Grant and revoke are audited agent acts; a revoke sets
 * status → revoked and stamps who/when, keeping the row as history.
 */
class OrgStaffGrant extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'id',
        'organization_id',
        'grantee_user_id',
        'task',
        'status',
        'granted_by_user_id',
        'granted_at',
        'revoked_at',
        'revoked_by_user_id',
        'end_reason',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function grantee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
