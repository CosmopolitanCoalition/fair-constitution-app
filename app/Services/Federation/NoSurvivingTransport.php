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
    /**
     * @param  bool  $undialable  true when the ladder had ZERO locally-dialable rungs (a
     *   permanent misconfiguration — e.g. an onion-only peer with no SOCKS proxy — NOT a
     *   transient WAN blip, so a retrying caller should fail fast rather than back off).
     */
    public function __construct(public readonly string $serverId, public readonly bool $undialable = false)
    {
        parent::__construct(
            $undialable
                ? "No locally-dialable federation transport to reach server [{$serverId}] (check SOCKS proxy / registered transports)."
                : "No surviving federation transport to reach server [{$serverId}]."
        );
    }
}
