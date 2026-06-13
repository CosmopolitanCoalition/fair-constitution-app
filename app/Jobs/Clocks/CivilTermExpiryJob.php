<?php

namespace App\Jobs\Clocks;

use App\Models\ClockTimer;
use App\Models\Term;
use App\Services\Executive\BoardGovernorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-09 fire handler (Phase D — PHASE_D_DESIGN_executive §C.2.5): a
 * civil-appointment term reached its expiry.
 *
 * Phase D wires the BOARD-GOVERNOR consequence: term completed, seat →
 * term_ended, a fresh vacant seat opens, and the "renomination open"
 * record publishes (the F-EXE-001 → F-LEG-020 loop restarts). Other
 * civil office kinds (election_board_member, admin_staff, civil_officer)
 * keep their Phase C posture — the fire itself is chained by
 * ClockService and their renewal flows land with their phases.
 */
class CivilTermExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $timerId,
    ) {
    }

    public function handle(BoardGovernorService $governors): void
    {
        $timer = ClockTimer::query()->find($this->timerId);

        if ($timer === null || $timer->subject_type !== 'term' || $timer->subject_id === null) {
            return;
        }

        $term = Term::query()->find($timer->subject_id);

        if ($term === null || $term->office_kind !== 'board_governor') {
            return; // other office kinds: fire recorded; flows land later
        }

        $governors->expireGovernorTerm($term);
    }
}
