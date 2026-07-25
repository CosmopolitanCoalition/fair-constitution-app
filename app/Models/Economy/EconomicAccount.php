<?php

namespace App\Models\Economy;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A wallet — for a person or an organisation (Phase M, slice M-1).
 *
 * PSEUDONYMOUS BY CONSTRUCTION. There is deliberately no user_id and no
 * organization_id on this model: the link lives in economic_account_bindings
 * and is the only restricted object in the economy. Adding an owner column
 * here would collapse the whole privacy model, so EconomyPrivacyTest pins its
 * absence.
 *
 * `balance` is a cache of the ledger maintained inside the same transaction
 * as the posting. The ledger is the source of truth; this is the fast read.
 */
class EconomicAccount extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_USER         = 'user';
    public const KIND_ORGANIZATION = 'organization';

    public const STATUS_OPEN   = 'open';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'id',
        'kind',
        'currency_id',
        'balance',
        'status',
    ];

    protected $casts = [
        'balance' => 'decimal:6',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
