<?php

namespace App\Models\Economy;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Public money — a jurisdiction's or a department's account (Phase L, L-2).
 *
 * PUBLIC by construction. A government's money is public business (Art. III
 * §4 — Boards of Governors execute their departments' charters and report on
 * them). The `public` flag exists for completeness, not as a privacy dial:
 * these are the accounts whose every movement belongs on the open ledger.
 *
 * Individual and organisational accounts are a DIFFERENT table
 * (economic_accounts, slice M-1) precisely because they carry the opposite
 * posture — pseudonymous, with the account↔person binding restricted.
 *
 * `balance` is a cache of the ledger, not a source of truth. LedgerService
 * maintains it inside the same transaction as the posting; the ledger itself
 * is what can be audited.
 */
class TreasuryAccount extends Model
{
    use HasUuids, SoftDeletes;

    public const OWNER_JURISDICTIONS = 'jurisdictions';
    public const OWNER_DEPARTMENTS   = 'departments';

    protected $fillable = [
        'id',
        'owner_type',
        'owner_id',
        'currency_id',
        'balance',
        'public',
        'label',
    ];

    protected $casts = [
        'balance' => 'decimal:6',
        'public'  => 'boolean',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
