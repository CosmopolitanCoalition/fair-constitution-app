<?php

namespace App\Services\Demo\Stages;

use App\Services\Education\SeatedMemberTrainingService;
use App\Support\SimTimer;

/**
 * The TRAINING stage (W7 item 7, ruling edu-arming A — "the walk shows a
 * trained fleet").
 *
 * Runs BEFORE the content stages (governance / judiciary / civics), right after
 * seating, so each jurisdiction's seated chamber completes its tutorial before
 * it exercises role authority — the tutorial-before-you-act model (operator
 * 2026-09-07). The training gate refuses a role-authority act by an untrained
 * holder, and the gated acts those stages file (the F-LEG committee / delegation
 * / department / court-creation forms) are cast by chamber members; training the
 * chamber here is what lets them pass. The catalog is published at this phase
 * transition (SimPumpCommand), arming the gate. This stage then trains ONE
 * jurisdiction's seated role-holders that exist at seating — its chamber — so a
 * walker sees trained members rather than a wall of Learn redirects.
 *
 * PER-JURISDICTION IS THE CHUNK (THE ETL RULE). The global pre-train pass loads
 * every seated holder on the planet into memory; scoping to one jurisdiction
 * bounds each item to its own institutions and makes the phase resumable. The
 * pass is idempotent: a holder with an accepted F-EDU-001 for the track is
 * skipped, so a re-handed item mints no second achievement.
 */
final class TrainingStage
{
    private function __construct() {}

    /**
     * @return array{holders:int, trained:int, already:int, unarmed:int, failed:int}
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $mArm = hrtime(true);
        $tally = app(SeatedMemberTrainingService::class)
            ->armForJurisdiction($jurisdictionId, null, $beat);
        SimTimer::record('training.arm', (int) ((hrtime(true) - $mArm) / 1000));

        return [
            'holders' => $tally['holders'],
            'trained' => $tally['filed'],
            'already' => $tally['already'],
            'unarmed' => $tally['unarmed'],
            'failed' => $tally['failed'],
        ];
    }
}
