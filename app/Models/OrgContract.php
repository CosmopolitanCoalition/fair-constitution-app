<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Minimal-viable contract (D-O3): the co-sign gate + the labor-headcount
 * surface. `active` requires BOTH signatures (engine rule + DB CHECK);
 * OrgContractService is the only writer.
 */
class OrgContract extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_LABOR_RECURRING = 'labor_recurring';
    public const KIND_LABOR_SINGLE    = 'labor_single';
    public const KIND_COMMERCIAL      = 'commercial';
    public const KIND_OTHER           = 'other';

    public const STATUS_DRAFT   = 'draft';
    public const STATUS_OFFERED = 'offered';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ENDED   = 'ended';
    public const STATUS_VOIDED  = 'voided';

    public const COUNTERPARTY_USERS         = 'users';
    public const COUNTERPARTY_ORGANIZATIONS = 'organizations';

    protected $fillable = [
        'id',
        'organization_id',
        'counterparty_type',
        'counterparty_id',
        'kind',
        'terms',
        'signed_by_org_user_id',
        'signed_by_org_at',
        'signed_by_counterparty_at',
        'status',
        'effective_at',
        'ended_at',
    ];

    protected $casts = [
        'signed_by_org_at'          => 'datetime',
        'signed_by_counterparty_at' => 'datetime',
        'effective_at'              => 'datetime',
        'ended_at'                  => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
