<?php

namespace App\Exceptions\Federation;

use RuntimeException;

/**
 * Thrown when the class-scoped federation rail refuses a peering because the two
 * instances are in different classes (operator ruling 2026-07-25: a scale_demo
 * instance federates only with other demos; a production instance only with
 * production). See PeerService::assertSameClass and ClassScopedFederationTest.
 *
 * It extends RuntimeException so every existing `catch (RuntimeException)` /
 * `catch (Throwable)` on the discover()/handshake paths keeps catching it —
 * the type only lets the HTTP layer distinguish a lawful cross-class REFUSAL
 * (a 409 Conflict between two well-formed instances) from an internal fault
 * (a 500). The declared classes are carried so the response can name both
 * sides; both are already public (each rides GET /identity), so this leaks
 * nothing that is not already advertised.
 */
class CrossClassPeeringException extends RuntimeException
{
    public function __construct(
        public readonly string $ourClass,
        public readonly string $peerClass,
        public readonly string $where,
        string $message,
    ) {
        parent::__construct($message);
    }
}
