<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A cluster join key (Phase G, G2). The host's minted secret a would-be mirror
 * presents to be admitted. Only the Argon2id `key_hash` is stored (hidden); the
 * plaintext `handle.secret` is shown to the operator once and never persisted.
 */
class ClusterJoinKey extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'handle',
        'key_hash',
        'max_uses',
        'uses',
        'scope_jurisdiction_id',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'max_uses' => 'integer',
        'uses' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** The Argon2id hash must never serialize into a prop, export, or log line. */
    protected $hidden = [
        'key_hash',
    ];

    /** Live = not revoked, not expired, and not yet exhausted. */
    public function isLive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && (int) $this->uses < (int) $this->max_uses;
    }
}
