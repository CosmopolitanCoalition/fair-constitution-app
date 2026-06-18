<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * Raised by MultiplexClient when EVERY transport in a peer's failover ladder is
 * either locally undialable or failed to deliver (Phase G, G8b). It means the peer
 * is unreachable over all known channels right now — not that a request was refused
 * (an HTTP refusal is a Response the multiplex returns intact).
 */
class NoSurvivingTransport extends RuntimeException
{
    public function __construct(public readonly string $serverId)
    {
        parent::__construct("No surviving federation transport to reach server [{$serverId}].");
    }
}
