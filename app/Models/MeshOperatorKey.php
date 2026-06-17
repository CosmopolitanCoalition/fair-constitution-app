<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G-OP) — a signed device-public-key ↔ mesh-identity binding (the
 * federated trust material). Each binding is signed by the instance that asserts
 * it (`bound_by_server_id`), verified against that server's pinned key — the
 * directory's "each entry signed by the server it names" pattern. No secret;
 * revocation rides a signed CRL like the attestation layer.
 */
class MeshOperatorKey extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'mesh_operator_id',
        'device_public_key',
        'bound_by_server_id',
        'binding_signature',
        'status',
        'bound_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'bound_at'   => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(MeshOperatorIdentity::class, 'mesh_operator_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->deleted_at === null;
    }
}
