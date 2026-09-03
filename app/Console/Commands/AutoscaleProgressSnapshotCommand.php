<?php

namespace App\Console\Commands;

use App\Http\Controllers\SetupController;
use Illuminate\Console\Command;

/**
 * Keeps the Step 3 dashboard snapshot warm off the poll path (2026-09-03).
 *
 * The heavy dashboard aggregates (940k ledger headers, 1M scopes) used to run
 * inside a page poll, under the page's 15 s abort timer, which is the freeze
 * the operator saw: a poll aborted mid-scan and the page showed stale bars
 * until the next one. This command computes that snapshot once a minute so the
 * page only ever reads a ready copy.
 *
 * It is viewer-gated: SetupController::refreshProgressSnapshot skips the scan
 * unless a poll marked a viewer present inside PROGRESS_VIEWER_TTL. So a closed
 * dashboard triggers no scan and adds nothing to the run's load.
 */
class AutoscaleProgressSnapshotCommand extends Command
{
    protected $signature = 'autoscale:progress-snapshot';

    protected $description = 'Warm the Step 3 dashboard snapshot while a viewer is present, so a page poll never runs the heavy ledger scan itself.';

    public function handle(): int
    {
        app(SetupController::class)->refreshProgressSnapshot();

        return self::SUCCESS;
    }
}
