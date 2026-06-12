<?php

namespace App\Domain\Engine;

use App\Models\AuditEntry;

/**
 * Outcome of a successful ConstitutionalEngine::file() call: the canonical
 * form that was filed, the audit chain entry sealing it, and the payload
 * the handler recorded.
 */
final class EngineResult
{
    public function __construct(
        public readonly string $formId,
        public readonly AuditEntry $entry,
        public readonly array $recorded,
    ) {
    }
}
