<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One signed broker-routing FACT (Mesh Roles & Channels of Trust ★8): "authority A attests that broker B
 * may broker under domain D." The mesh-replicated generalization of the cert-broker's static per-domain
 * authority_keys whitelist. Signed by the AUTHORITY, gossiped, verified against the authority's own pinned
 * key. Carries only public keys + names + a signature — never the Cloudflare token.
 */
class BrokerAuthorization extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'broker_authorizations';

    protected $fillable = [
        'id',
        'domain',
        'broker_server_id',
        'authority_server_id',
        'authority_pubkey',
        'signature',
        'issued_at',
        'revoked_at',
    ];

    protected $casts = [
        'issued_at'  => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->deleted_at === null;
    }
}
