<?php

namespace App\Jobs\Organizations;

use App\Models\ClockTimer;
use App\Models\Election;
use App\Services\Organizations\OrgBoardSeatingService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * D-O4 (PHASE_D_DESIGN_organizations §B.2/§B.4) — the nightly
 * co-determination SWEEP (the CLK-05/06 pattern: event-driven cheap path
 * + scheduled safety net):
 *
 *  1. re-runs the headcount recompute for every employer with an armed
 *     CLK-13/CLK-14 watcher — covers threshold LOWERING by act
 *     (setting_changes on either key re-derive entitlements per the
 *     Phase A clock rule) and any missed event;
 *  2. the 48h auto-certify backstop: an org-board election whose voting
 *     closed ≥ 48h ago certifies with the system actor — a stalling R-23
 *     can never block constitutionally-mandated worker seats (§C.2).
 *
 * Doubles as the CLK-13/14 fire handler (ClockService::HANDLERS): when
 * constructed with a fired timer id, only that timer's subject recomputes.
 */
class EvaluateCoDeterminationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
    ) {
    }

    public function handle(): void
    {
        if ($this->timerId !== null) {
            $timer = ClockTimer::query()->find($this->timerId);

            if ($timer?->subject_type !== null && $timer->subject_id !== null) {
                RecomputeWorkerHeadcountJob::dispatch((string) $timer->subject_type, (string) $timer->subject_id);
            }

            return;
        }

        // ── 1. sweep every watched employer ─────────────────────────────
        $subjects = DB::table('clock_timers')
            ->whereIn('clock_id', ['CLK-13', 'CLK-14'])
            ->where('state', ClockTimer::STATE_ARMED)
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->distinct()
            ->get(['subject_type', 'subject_id']);

        foreach ($subjects as $subject) {
            RecomputeWorkerHeadcountJob::dispatch((string) $subject->subject_type, (string) $subject->subject_id);
        }

        // ── 2. auto-certify backstop (48h after voting closed) ──────────
        // The grace threshold follows the SAME clock the elections use: under
        // a compressed demo (config cga.election_demo_compression = N minutes,
        // read by ElectionLifecycleService::defaultDates) the 48h wall grace
        // compresses to N minutes, so worker boards seat inside a short demo
        // instead of never appearing. False (production) keeps the 48h wall
        // clock exactly. See backstopThreshold().
        $stalled = Election::query()
            ->whereIn('kind', [Election::KIND_ORG_BOARD_OWNER, Election::KIND_ORG_BOARD_WORKER])
            ->whereIn('status', [Election::STATUS_VOTING_CLOSED, Election::STATUS_TABULATING])
            ->where('ranked_closes_at', '<=', self::backstopThreshold())
            ->get();

        foreach ($stalled as $election) {
            try {
                app(OrgBoardSeatingService::class)->certify($election);
            } catch (\Throwable) {
                // Not yet tabulated / nothing to seat — the next sweep
                // retries; tabulation has its own pipeline.
            }
        }
    }

    /**
     * The auto-certify grace threshold. An election whose voting closed at or
     * before this instant has stalled long enough for the backstop to seat it.
     *
     * Reads the same dial the elections consult (cga.election_demo_compression):
     *   - false / 0  → 48h wall-clock grace (production, unchanged).
     *   - N minutes  → N-minute grace, so a compressed demo whose phase windows
     *                  are N minutes apart also seats stalled worker boards
     *                  after one such window, not after two real days.
     */
    public static function backstopThreshold(): CarbonInterface
    {
        $compression = (int) config('cga.election_demo_compression', 0);

        return $compression > 0
            ? now()->subMinutes($compression)
            : now()->subHours(48);
    }
}
