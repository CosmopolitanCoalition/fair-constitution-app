<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 5 / K-3 (K3-C.2) — a single-use, short-lived OIDC authorization code (stored as a hash). Ephemeral
 * infra; no soft-delete.
 */
class OidcAuthorizationCode extends Model
{
    use HasUuids;

    protected $fillable = [
        'code_hash',
        'client_id',
        'user_id',
        'redirect_uri',
        'scope',
        'code_challenge',
        'nonce',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
