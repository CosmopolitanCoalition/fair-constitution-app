<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Panel;
use App\Models\PanelJudge;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use Illuminate\Support\Facades\DB;

/**
 * The panel draw + conflict screening + recusal re-draw (PHASE_E_DESIGN_cases_juries
 * §C). Calls PanelSizing::sizeFor (the pure core), seeds the draw, publishes
 * the seed to the audit chain (the same seal as the jury draw), screens each
 * drawn judicial_seats row, recuses + re-runs the draw on a confirmed conflict,
 * and sets the presiding judge.
 *
 * En-banc path: when severity is `constitutional_major`, is_en_banc=true and
 * the panel = every non-recused seated seat (CLK-16 hardened).
 *
 * The seed is published so the draw is REPRODUCIBLE — anyone can verify it.
 */
class PanelService
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly AuditService $audit,
    ) {}

    /**
     * Seat the panel for an ACCEPTED case and advance it to `paneled`. Size is
     * computed from the court's classification (not the filer's), via the pure
     * PanelSizing::sizeFor over the seated-judge pool.
     *
     * @param  array<string,string>  $recusals  judicial_seat_id => reason (screening conflicts)
     */
    public function assignPanel(CourtCase $case, ?string $seed = null, array $recusals = []): Panel
    {
        if ($case->status !== CourtCase::STATUS_ACCEPTED) {
            throw new ConstitutionalViolation(
                'A panel is seated for an ACCEPTED case (the court has fixed its classification).',
                'Art. IV §4'
            );
        }

        $severity = (string) $case->court_severity;
        $judiciary = Judiciary::query()->findOrFail((string) $case->judiciary_id);

        // The seated pool the draw runs over (the seat pool is read-only to
        // cases — the §F structure↔cases contract).
        $seatedPool = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->orderBy('seat_number')
            ->get();

        $seatedCount = $seatedPool->count();

        if ($seatedCount < 3) {
            throw new ConstitutionalViolation(
                'A court hears a case with at least three (3) seated judges (Art. IV §4).',
                'Art. IV §4'
            );
        }

        ['size' => $size, 'en_banc' => $enBanc] = PanelSizing::sizeFor($severity, $seatedCount);

        // Re-assert the hardened invariants at seating (the DB belt is behind).
        ConstitutionalValidator::assertPanelSize($size, $enBanc, $severity, $seatedCount);

        $seed ??= bin2hex(random_bytes(16));

        return DB::transaction(function () use ($case, $judiciary, $seatedPool, $size, $enBanc, $severity, $seed, $recusals): Panel {
            $panel = Panel::create([
                'case_id' => (string) $case->id,
                'judiciary_id' => (string) $judiciary->id,
                'size' => $size,
                'is_en_banc' => $enBanc,
                'severity_basis' => $severity,
                'draw_seed' => $seed,
                'status' => Panel::STATUS_SEATED,
            ]);

            // Deterministic draw over the cleared pool — the seed orders the
            // seats; recused seats drop and the draw advances over the rest.
            $cleared = $this->deterministicOrder($seatedPool->all(), $seed);

            $seatedThisPanel = [];
            $presidingSet = false;

            foreach ($cleared as $seat) {
                if (count($seatedThisPanel) >= $size) {
                    break;
                }

                $reason = $recusals[(string) $seat->id] ?? null;

                if ($reason !== null) {
                    // A confirmed conflict — recuse and SKIP (the draw advances
                    // over the remaining cleared seats; the seed is unchanged,
                    // the screening result attaches to the case record).
                    PanelJudge::create([
                        'panel_id' => (string) $panel->id,
                        'judicial_seat_id' => (string) $seat->id,
                        'user_id' => $seat->user_id !== null ? (string) $seat->user_id : null,
                        'screening_result' => PanelJudge::SCREENING_RECUSED,
                        'recusal_reason' => $reason,
                        'status' => PanelJudge::STATUS_RECUSED,
                    ]);

                    continue;
                }

                $isPresiding = ! $presidingSet;
                $presidingSet = true;

                PanelJudge::create([
                    'panel_id' => (string) $panel->id,
                    'judicial_seat_id' => (string) $seat->id,
                    'user_id' => $seat->user_id !== null ? (string) $seat->user_id : null,
                    'is_presiding' => $isPresiding,
                    'screening_result' => PanelJudge::SCREENING_CLEARED,
                    'status' => PanelJudge::STATUS_SEATED,
                ]);

                $seatedThisPanel[] = $seat;
            }

            if (count($seatedThisPanel) < $size) {
                throw new ConstitutionalViolation(
                    sprintf(
                        'After recusals only %d cleared judges remain — a panel of %d cannot be seated (Art. IV §4).',
                        count($seatedThisPanel),
                        $size
                    ),
                    'Art. IV §4'
                );
            }

            $panel->forceFill(['presiding_judge_seat_id' => (string) $seatedThisPanel[0]->id])->save();

            // Seal the draw seed to the chain (the audit-verifiable draw).
            $this->audit->append(
                module: 'judiciary',
                event: 'panel.drawn',
                payload: [
                    'panel_id' => (string) $panel->id,
                    'case_id' => (string) $case->id,
                    'size' => $size,
                    'is_en_banc' => $enBanc,
                    'severity' => $severity,
                    'draw_seed' => $seed,
                    'recused_seats' => array_keys($recusals),
                ],
                ref: 'F-JDG-001',
                jurisdictionId: (string) $case->jurisdiction_id,
            );

            $this->cases->markPaneled($case->refresh(), (string) $panel->id);

            return $panel;
        });
    }

    /**
     * The deterministic draw order over the seated pool: the published seed
     * keys a stable sort, so anyone re-running it with the same seed reproduces
     * the exact panel.
     *
     * @param  list<JudicialSeat>  $seats
     * @return list<JudicialSeat>
     */
    private function deterministicOrder(array $seats, string $seed): array
    {
        usort($seats, function (JudicialSeat $a, JudicialSeat $b) use ($seed): int {
            $ha = hash('sha256', $seed.'|'.$a->id);
            $hb = hash('sha256', $seed.'|'.$b->id);

            return $ha <=> $hb;
        });

        return array_values($seats);
    }
}
