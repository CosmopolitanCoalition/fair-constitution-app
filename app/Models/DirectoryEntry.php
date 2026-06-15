<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An ADVISORY directory entry (Phase G, G9): a signed "server S serves jurisdiction
 * J at these endpoints" hint. Holds no authority — purely routing. Signed by the
 * named server, so it is self-authenticating wherever it is relayed.
 */
class DirectoryEntry extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'server_id',
        'endpoints',
        'priority',
        'signature',
        'source_server_id',
        'published_at',
        'expires_at',
    ];

    protected $casts = [
        'endpoints' => 'array',
        'priority' => 'integer',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
