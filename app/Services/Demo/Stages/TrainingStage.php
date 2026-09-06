<?php

namespace App\Services\Demo\Stages;

use App\Services\Education\SeatedMemberTrainingService;

/**
 * The TRAINING stage (W7 item 7, ruling edu-arming A — "the walk shows a
 * trained fleet").
 *
 * Runs AFTER the content stages (governance / judiciary / civics have filed
 * their gated forms) so arming the training gate never blocks them. The catalog
 * is published once at the phase transition (SimPumpCommand); this stage then
 * pre-trains ONE jurisdiction's seated role-holders — a chamber, a board, an
 * executive, a bench, its advocates — so a walker sees trained members rather
 * than a wall of Learn redirects.
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
        $tally = app(SeatedMemberTrainingService::class)
            ->armForJurisdiction($jurisdictionId, null, $beat);

        return [
            'holders' => $tally['holders'],
            'trained' => $tally['filed'],
            'already' => $tally['already'],
            'unarmed' => $tally['unarmed'],
            'failed' => $tally['failed'],
        ];
    }
}
