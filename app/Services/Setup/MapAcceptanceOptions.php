<?php

namespace App\Services\Setup;

/**
 * The inputs one map-data acceptance needs, independent of the door it came
 * through (the wizard endpoint, `maps:accept`, or a backup restore). Every
 * caller resolves these the same way, so the three doors leave identical
 * state.
 */
final class MapAcceptanceOptions
{
    /**
     * @param  string   $mode              'eager' | 'population' | 'manual'
     * @param  bool     $simulateAtScale   request flag; effective only under eager on a sandbox world
     * @param  bool     $acknowledgeOpenFlags  the confirm-dialog acknowledgment for open geodata flags
     * @param  bool     $startAutoscale    the re-hook flag: start the planet build on an already-accepted instance
     * @param  bool     $gateOnVerifier    run the world-build verifier before stamping (eager or re-hook)
     * @param  ?string  $initiatorUserId   the operator who started the run, or null for a CLI/restore door
     */
    public function __construct(
        public readonly string $mode = 'eager',
        public readonly bool $simulateAtScale = false,
        public readonly bool $acknowledgeOpenFlags = false,
        public readonly bool $startAutoscale = false,
        public readonly bool $gateOnVerifier = true,
        public readonly ?string $initiatorUserId = null,
    ) {}

    /**
     * Resolve the acceptance mode from a request-style input the way the wizard
     * endpoint always has: an explicit scale_mode wins; otherwise the legacy
     * defer_autoscale flag maps to manual, and its absence to eager.
     */
    public static function resolveMode(?string $scaleMode, bool $deferAutoscale): string
    {
        $mode = (string) $scaleMode;
        if (! in_array($mode, ['eager', 'population', 'manual'], true)) {
            $mode = $deferAutoscale ? 'manual' : 'eager';
        }

        return $mode;
    }
}
