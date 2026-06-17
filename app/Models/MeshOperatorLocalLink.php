<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G-OP) — the local↔mesh sync ledger: local operator account A on this
 * instance is the same operator as mesh identity M. The home_server_id analogue
 * made many-to-many — one mesh operator may hold a local account on several
 * instances (a traveling operator recognized everywhere).
 */
class MeshOperatorLocalLink extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'operator_account_id',
        'mesh_operator_id',
        'linked_via_peer_id',
        'linked_at',
        'unlinked_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_at'   => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    public function operatorAccount(): BelongsTo
    {
        return $this->belongsTo(OperatorAccount::class);
    }
}
