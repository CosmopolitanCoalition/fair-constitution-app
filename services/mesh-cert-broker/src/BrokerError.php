<?php

namespace MeshCertBroker;

/** A refusal with a client-safe message + HTTP status. NEVER carries a token, key, or internal path. */
final class BrokerError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
