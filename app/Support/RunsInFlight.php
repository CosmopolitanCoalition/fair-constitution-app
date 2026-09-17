<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RunsInFlight — is any engine run active on this box right now?
 *
 * Operator ruling 2026-09-17 (serving-boot-prewarm = A): on the serving
 * profile the boot prewarm is skipped while any run is active, because the
 * prewarm worker and the run's pool share one Horizon memory cap (the WoS
 * beta OOM-killed a 1 GB raster worker at every boot). This is the ONE
 * detector the prewarm commands consult (`--unless-busy`), so the four run
 * kinds are named in one place. Every probe is table-guarded: a fresh box
 * mid-migration answers "idle", never an exception.
 */
final class RunsInFlight
{
    /**
     * The kinds and the statuses that mean "active". Halted runs count: their
     * pool may resume at any tick, and the ruling reads "while any run is
     * active", not "while any worker is busy".
     *
     * @var array<string, array{table: string, statuses: list<string>}>
     */
    public const KINDS = [
        'geodata'   => ['table' => 'geodata_runs',   'statuses' => ['running', 'halted']],
        'autoscale' => ['table' => 'autoscale_runs', 'statuses' => ['queued', 'sizing', 'mapping', 'halted']],
        'provision' => ['table' => 'provision_runs', 'statuses' => ['running', 'halted']],
        'sim'       => ['table' => 'sim_runs',       'statuses' => ['queued', 'running', 'halted']],
        'media'     => ['table' => 'media_pulls',    'statuses' => ['running', 'halted']],
    ];

    /** The kind of the first active run found, or null when the box is idle. */
    public static function any(): ?string
    {
        foreach (self::KINDS as $kind => $spec) {
            try {
                if (! Schema::hasTable($spec['table'])) {
                    continue;
                }
                if (DB::table($spec['table'])->whereIn('status', $spec['statuses'])->exists()) {
                    return $kind;
                }
            } catch (\Throwable) {
                // A table that exists but cannot be read mid-migration reads as idle.
            }
        }

        return null;
    }
}
