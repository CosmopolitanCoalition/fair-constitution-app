<?php

namespace App\Domain\Forms;

use App\Domain\Forms\Contracts\ElectionSchedulingDelegate;
use App\Models\Election;

/**
 * Default (WI-B4) scheduling delegate: F-ELB-001 validation, the election
 * row, explicit-payload races and audit chaining work end to end, but
 * map-derived race generation and phase-timer arming land with WI-B3
 * (ElectionLifecycleService), which the orchestrator rebinds in
 * ConstitutionProvider.
 */
class NoopElectionSchedulingDelegate implements ElectionSchedulingDelegate
{
    public function generateRaces(Election $election, array $payload): array
    {
        return [];
    }

    public function armPhaseTimers(Election $election): void
    {
        // CLK-18 / CLK-01 phase timers land in WI-B3.
    }
}
