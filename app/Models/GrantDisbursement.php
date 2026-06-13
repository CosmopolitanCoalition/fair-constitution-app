<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-6 — an APPEND-ONLY grant disbursement: no updates, no soft deletes
 * (a disbursement happened or it did not). Written only by GrantService
 * under the appropriation's FOR UPDATE lock (Σ disbursements ≤ awarded).
 */
class GrantDisbursement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'application_id',
        'amount',
        'disbursed_by_member_id',
        'disbursed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'disbursed_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(GrantApplication::class, 'application_id');
    }
}
