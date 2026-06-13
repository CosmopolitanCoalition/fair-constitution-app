<?php

namespace App\Jobs\Organizations;

use App\Services\Organizations\CoDeterminationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * D-O4 (PHASE_D_DESIGN_organizations §B.2) — THE headcount-event
 * recompute. QUEUED, NEVER SYNCHRONOUS: every org_workers status flip
 * dispatches this after-commit (a 2,000-signup import must not run 2,000
 * board reconciliations in the request path); ShouldBeUnique debounces
 * per employer.
 *
 * The body delegates entirely to CoDeterminationService::recompute —
 * counter caches, the boards.worker_seats/worker_headcount snapshot,
 * seat reconciliation (WF-ORG-04 auto-trigger), CLK-13/14 fires, the
 * chain entry. Also the CLK-13/14 fire-handler target (the clock is the
 * registry-visible trigger; the job is the engine — idempotent).
 */
class RecomputeWorkerHeadcountJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $employerType,
        public readonly string $employerId,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->employerType . ':' . $this->employerId;
    }

    public function handle(CoDeterminationService $coDetermination): void
    {
        $coDetermination->recompute($this->employerType, $this->employerId);
    }
}
