<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A signed CRL entry that kills a standing attestation before its TTL (Phase G,
 * G-ID). Federates the same way an attestation does.
 */
class AttestationRevocation extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'attestation_id',
        'issuer_server_id',
        'reason',
        'revoked_at',
        'signature',
        'source_server_id',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];
}
