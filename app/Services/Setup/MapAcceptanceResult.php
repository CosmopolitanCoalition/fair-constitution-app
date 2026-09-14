<?php

namespace App\Services\Setup;

use App\Models\AutoscaleRun;

/**
 * The outcome of one acceptance attempt. Each door renders it in its own
 * shape (a JSON response, console lines, or an import summary), but the
 * decision and every state change already happened in the service.
 */
final class MapAcceptanceResult
{
    public const MISSING_INSTANCE = 'missing_instance';
    public const WORLD_BUILD_INCOMPLETE = 'world_build_incomplete';
    public const REQUIRES_ACKNOWLEDGMENT = 'requires_acknowledgment';
    public const ALREADY_LIVE = 'already_live';
    public const RESUMED = 'resumed';
    public const ALREADY_ACCEPTED = 'already_accepted';
    public const ACCEPTED = 'accepted';

    /**
     * @param  string                $outcome  one of the class constants
     * @param  array<string,mixed>   $payload  outcome-specific detail (open_flags, progress, timestamps, mode)
     * @param  ?AutoscaleRun         $run      the run created or resumed, when one was
     * @param  bool                  $kickPump true when the caller must kick the autoscale pump
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $payload = [],
        public readonly ?AutoscaleRun $run = null,
        public readonly bool $kickPump = false,
    ) {}

    /** True when acceptance was recorded (a fresh stamp or a re-hook run start). */
    public function accepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }
}
