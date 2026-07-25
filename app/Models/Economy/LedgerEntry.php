<?php

namespace App\Models\Economy;

use Illuminate\Database\Eloquent\Model;

/**
 * One leg of a balanced posting on the public ledger (Phase L, slice L-2).
 *
 * READ-ONLY from Eloquent's side. The table is append-only at the database
 * level (ledger_entries_immutable trigger) and LedgerService is its only
 * writer — so there is deliberately no create/update path on this model. A
 * correction is a new balanced posting, never an edit.
 */
class LedgerEntry extends Model
{
    public const DIRECTION_DEBIT  = 'debit';
    public const DIRECTION_CREDIT = 'credit';

    protected $table = 'ledger_entries';

    /** Append-only: created_at is written by LedgerService, never touched again. */
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'amount'     => 'decimal:6',
        'created_at' => 'datetime',
    ];
}
