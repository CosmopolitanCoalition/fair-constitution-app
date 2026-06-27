<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 5 / K-3 (K3-C) — a game OIDC-provider signing key. The private PEM is stored encrypted (handled by
 * OidcKeyService, never a fillable here) and is NEVER serialized; only public_jwk is ever exposed.
 */
class OidcSigningKey extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'oidc_signing_keys';

    protected $fillable = [
        'kid',
        'algorithm',
        'public_jwk',
        'private_pem_encrypted',
        'is_active',
        'rotated_at',
    ];

    protected $casts = [
        'public_jwk' => 'array',
        'is_active' => 'boolean',
        'rotated_at' => 'datetime',
    ];

    // The private key never rides a serialized model.
    protected $hidden = [
        'private_pem_encrypted',
    ];
}
