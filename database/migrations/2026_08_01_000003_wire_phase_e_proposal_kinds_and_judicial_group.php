<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-3 (PHASE_E_DESIGN_judiciary §A) — the D-9 analogue: registry/enum
 * widening so the engine-filed judiciary lane never dies on a stale CHECK.
 *
 *  1. chamber_vote_proposals_kind_check: add the three Phase E proposal
 *     kinds — judiciary_creation, judiciary_conversion,
 *     judiciary_dissolution — to the existing Phase C + Phase D union
 *     (the D-9 drop-and-re-add technique). Without this every
 *     F-LEG-017/018 proposal would die SQLSTATE 23514, exactly as the
 *     Phase D exec kinds did.
 *  2. election_races_seat_kind_check: add 'judicial_group' (14 chars —
 *     fits the varchar(16) widened by D-9).
 *  3. election_races_seats_check: add the judicial arm —
 *     judicial_group floors at 5 (judiciary_min_judges_per_race;
 *     Art. IV §1) with NO ceiling, the exec_committee shape.
 */
return new class extends Migration
{
    private const PROPOSAL_KINDS = [
        // Phase C
        'committee_creation', 'election_board_creation', 'admin_office_creation',
        'rules_of_order', 'ethics_code', 'referendum_delegation',
        'referendum_act_modification', 'emergency_invocation', 'emergency_renewal',
        // Phase D
        'exec_delegation', 'exec_conversion', 'department_creation',
        'cgc_creation', 'monopoly_acquisition', 'cgc_reorg_sale',
        // Phase E
        'judiciary_creation', 'judiciary_conversion', 'judiciary_dissolution',
    ];

    private const SEAT_KINDS = ['type_a', 'type_b', 'single', 'exec_committee', 'judicial_group'];

    public function up(): void
    {
        $this->setProposalKindCheck(self::PROPOSAL_KINDS);
        $this->setSeatKindChecks(self::SEAT_KINDS, withJudicial: true);
    }

    public function down(): void
    {
        // Re-state without the three Phase E kinds / judicial_group.
        $this->setProposalKindCheck(array_values(array_filter(
            self::PROPOSAL_KINDS,
            fn (string $k) => ! str_starts_with($k, 'judiciary_'),
        )));
        $this->setSeatKindChecks(
            array_values(array_filter(self::SEAT_KINDS, fn (string $k) => $k !== 'judicial_group')),
            withJudicial: false,
        );
    }

    private function setProposalKindCheck(array $kinds): void
    {
        $list = collect($kinds)->map(fn ($k) => "'{$k}'")->implode(', ');

        DB::statement('ALTER TABLE chamber_vote_proposals DROP CONSTRAINT IF EXISTS chamber_vote_proposals_kind_check');
        DB::statement(
            'ALTER TABLE chamber_vote_proposals ADD CONSTRAINT chamber_vote_proposals_kind_check '.
            "CHECK (proposal_kind IN ({$list}))"
        );
    }

    private function setSeatKindChecks(array $kinds, bool $withJudicial): void
    {
        $list = collect($kinds)->map(fn ($k) => "'{$k}'")->implode(', ');

        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seat_kind_check');
        DB::statement(
            'ALTER TABLE election_races ADD CONSTRAINT election_races_seat_kind_check '.
            "CHECK (seat_kind IN ({$list}))"
        );

        $judicialArm = $withJudicial ? " OR (seat_kind = 'judicial_group' AND seats >= 5)" : '';

        DB::statement('ALTER TABLE election_races DROP CONSTRAINT IF EXISTS election_races_seats_check');
        DB::statement(
            'ALTER TABLE election_races ADD CONSTRAINT election_races_seats_check CHECK ( '.
            "(seat_kind IN ('type_a', 'type_b') AND seats BETWEEN 1 AND 9) ".
            "OR (seat_kind = 'single' AND seats = 1) ".
            "OR (seat_kind = 'exec_committee' AND seats >= 5)".
            $judicialArm.' )'
        );
    }
};
