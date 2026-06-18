<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reachability bookkeeping for ONE (server, transport, url) endpoint (Phase G, G8b).
 * The multiplex survival mesh reads this to order a peer's transports best-first and
 * to skip a tripped (open) circuit fast; it writes it on every dial outcome. The key
 * matches the ladder's dedupe key so two urls on the same transport own independent
 * circuits. Purely OPERATIONAL — never constitutional, gates nothing.
 *
 * circuit_state is persisted as 'closed' or 'open'; 'half_open' is a DERIVED ladder
 * state (a cooled-open circuit eligible for one probe), never written at rest.
 */
class FederationTransportHealth extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'federation_transport_health';

    public const CIRCUIT_CLOSED = 'closed';

    public const CIRCUIT_OPEN = 'open';

    public const CIRCUIT_HALF_OPEN = 'half_open';

    protected $fillable = [
        'id',
        'server_id',
        'transport',
        'url',
        'last_ok_at',
        'last_fail_at',
        'consecutive_failures',
        'latency_ema_ms',
        'circuit_state',
    ];

    protected $casts = [
        'last_ok_at' => 'datetime',
        'last_fail_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'latency_ema_ms' => 'integer',
    ];
}
