<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ownership transfer (F-ORG-005, WF-ORG-06) — MUTUAL consent: `consented`
 * requires BOTH consents; the engine rejects anything less. The only
 * ownership path overriding owner consent is monopoly acquisition, which
 * is a CONVERSION, never a transfer.
 */
class OrgTransfer extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_PROPOSED  = 'proposed';
    public const STATUS_CONSENTED = 'consented';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    public const PARTY_USERS         = 'users';
    public const PARTY_ORGANIZATIONS = 'organizations';

    protected $fillable = [
        'id',
        'organization_id',
        'to_party_type',
        'to_party_id',
        'terms',
        'consent_from_at',
        'consent_from_user_id',
        'consent_to_at',
        'consent_to_user_id',
        'status',
        'completed_at',
        'ffc_synced_at',
    ];

    protected $casts = [
        'consent_from_at' => 'datetime',
        'consent_to_at'   => 'datetime',
        'completed_at'    => 'datetime',
        'ffc_synced_at'   => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
