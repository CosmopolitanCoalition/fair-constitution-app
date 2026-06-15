<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A short-lived, instance-signed snapshot of a person's DERIVED standing (Phase G,
 * G-ID), bound to a device key. Carries only public standing — role codes, the
 * device key, issuer, TTL, signature. Never credentials/locations/ballots.
 */
class StandingAttestation extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'subject_user_id',
        'device_public_key',
        'issuer_server_id',
        'roles',
        'issued_at',
        'expires_at',
        'signature',
        'source_server_id',
    ];

    protected $casts = [
        'roles' => 'array',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
