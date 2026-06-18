<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G-VER) — one consenting authority's decision on a PeerUpgradeProposal.
 * Mirrors ConstituentConsent. Three legs share the table:
 *   • meter='operator' — a vetted operator (the R-08 bootstrap board, Meter A)
 *   • meter='seated'   — links the seated-institution MJV (Meter B)
 *   • meter='peer'     — a co-affected peer's mesh consent (Meter C, G-VER 4)
 */
class PeerUpgradeConsent extends Model
{
    use HasUuids, SoftDeletes;

    public const METER_OPERATOR = 'operator';
    public const METER_SEATED   = 'seated';
    public const METER_PEER     = 'peer';

    public const RESULT_PENDING = 'pending';
    public const RESULT_YES     = 'yes';
    public const RESULT_NO      = 'no';

    protected $fillable = [
        'id',
        'proposal_id',
        'meter',
        'operator_account_id',
        'mesh_operator_id',
        'peer_server_id',
        'mjv_process_id',
        'result',
        'signature',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PeerUpgradeProposal::class, 'proposal_id');
    }
}
