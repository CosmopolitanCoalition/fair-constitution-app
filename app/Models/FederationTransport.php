<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One channel an instance is reachable over (Phase G, G8/G8b): https | tailnet |
 * onion | sneakernet | yggdrasil, with its address. The same signed bytes travel
 * over any of them; the multiplex ladder tries them best-first until one survives.
 */
class FederationTransport extends Model
{
    use HasUuids, SoftDeletes;

    public const TRANSPORTS = ['https', 'tailnet', 'onion', 'sneakernet', 'yggdrasil'];

    protected $fillable = [
        'id',
        'server_id',
        'transport',
        'address',
        'is_self',
        'priority',
        'enabled',
    ];

    protected $casts = [
        'is_self' => 'boolean',
        'priority' => 'integer',
        'enabled' => 'boolean',
    ];
}
