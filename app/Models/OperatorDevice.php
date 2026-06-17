<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G-OP) — an operator's Ed25519 signing device (the operator-plane
 * analogue of ActorDevice). Stores the PUBLIC key only; the secret never leaves
 * the device (no escrow). This key — not the password — is what federates: it
 * signs operator actions and is bound to a mesh identity via MeshOperatorKey.
 */
class OperatorDevice extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'operator_account_id',
        'device_public_key',
        'label',
        'enrolled_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    public function operatorAccount(): BelongsTo
    {
        return $this->belongsTo(OperatorAccount::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
