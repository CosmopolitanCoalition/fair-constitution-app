<?php

namespace App\Domain\Counting;

/**
 * One tabulation round — exactly one transfer event (or one
 * shortcut-fill election). Serializes to the `tabulation_rounds`
 * JSON shapes pinned in PHASE_B_DESIGN_counting_engine.md §B.3.
 *
 *  - $tallies  standings at round START (fixture convention):
 *      candidates  µv map over every not-yet-eliminated candidate,
 *                  ksorted by candidacy id; surplus-elected rest at
 *                  exactly quota × SCALE
 *      exhausted_micro        cumulative at round start
 *      elected_so_far         election order at round END — includes this
 *                             round's own winner on elect rounds (fixture
 *                             round-27 convention: electedSoFar lists Aisha)
 *      elected_without_quota  true on shortcut-fill rounds
 *      tie_break              only on shortcut-fill rounds decided by §A.5-T
 *
 *  - $transfer  null only for shortcut-fill (and RCV elect) rounds:
 *      kind                     'surplus' | 'elimination'
 *      value_micro              surplus only: floor(surplus × SCALE / total);
 *                               elimination: null ("current value")
 *      to                       [[candidacy_id, µv], ...] id-ascending,
 *                               zero amounts omitted
 *      exhausted_micro          this round's exhaustion
 *      truncation_residue_micro this round's per-ballot truncation loss
 *      tie_break                null | {stage: 'prior_rounds', decided_at_round}
 *                                    | {stage: 'lot', seed, order}
 */
final readonly class RoundResult
{
    public function __construct(
        public int $roundNo,
        public string $action,          // 'elect' | 'eliminate'
        public ?string $candidacyId,
        public ?array $transfer,
        public array $tallies,
    ) {
    }

    public function toArray(): array
    {
        return [
            'round_no' => $this->roundNo,
            'action' => $this->action,
            'candidacy_id' => $this->candidacyId,
            'transfer' => $this->transfer,
            'tallies' => $this->tallies,
        ];
    }
}
