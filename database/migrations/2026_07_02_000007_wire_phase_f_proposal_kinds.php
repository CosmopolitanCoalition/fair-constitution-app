<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase F wiring (the D-9 drop-and-re-add technique) — add the three Phase F
 * chamber-act proposal kinds so F-LEG-028/029/030 proposals don't die SQLSTATE
 * 23514 (exactly the Phase D/E exec/judiciary precedent). The MJV-kind CHECK
 * already carries union/disintermediation/cultural_institution; this extends the
 * CHAMBER proposal-kind CHECK to match.
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
        'judiciary_override',
        // Phase F — the four jurisdiction processes (F-LEG-028/029/030)
        'cultural_institution', 'union', 'disintermediation',
    ];

    public function up(): void
    {
        $this->setKindCheck(self::PROPOSAL_KINDS);
    }

    public function down(): void
    {
        $this->setKindCheck(array_values(array_filter(
            self::PROPOSAL_KINDS,
            fn (string $k) => ! in_array($k, ['cultural_institution', 'union', 'disintermediation'], true),
        )));
    }

    private function setKindCheck(array $kinds): void
    {
        $list = collect($kinds)->map(fn ($k) => "'{$k}'")->implode(', ');

        DB::statement('ALTER TABLE chamber_vote_proposals DROP CONSTRAINT IF EXISTS chamber_vote_proposals_kind_check');
        DB::statement(
            'ALTER TABLE chamber_vote_proposals ADD CONSTRAINT chamber_vote_proposals_kind_check '
            ."CHECK (proposal_kind IN ({$list}))"
        );
    }
};
