<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase D wiring fix (surfaced by the Phase D constitutional verification
 * suite — OrderScopeValidationTest / ExecDelegationProportionalityTest /
 * ExecConversionDualSupermajorityTest). Two committed schema gaps blocked
 * the engine-filed executive/legislative-act lane:
 *
 *  1. chamber_vote_proposals_kind_check (set in
 *     2026_06_22_000001_create_petitions_and_referendums_tables.php) listed
 *     only the Phase C proposal kinds. App\Models\ChamberVoteProposal
 *     declares six Phase D kinds and ExecutiveActService::propose() inserts
 *     them, so every Phase D chamber-act proposal died with SQLSTATE 23514.
 *     Re-state the CHECK with the Phase C kinds + the six Phase D kinds.
 *
 *  2. election_races.seat_kind stayed varchar(8)
 *     (2026_06_13_000004_create_election_races.php) while
 *     2026_06_23_000001_evolve_executives_tables.php widened its CHECK to
 *     allow 'exec_committee' (14 chars). Scheduling a committee
 *     conversion election 22001-truncated. Widen the column to varchar(16).
 */
return new class extends Migration
{
    private const PHASE_C_KINDS = [
        'committee_creation', 'election_board_creation', 'admin_office_creation',
        'rules_of_order', 'ethics_code', 'referendum_delegation',
        'referendum_act_modification', 'emergency_invocation', 'emergency_renewal',
    ];

    private const PHASE_D_KINDS = [
        'exec_delegation', 'exec_conversion', 'department_creation',
        'cgc_creation', 'monopoly_acquisition', 'cgc_reorg_sale',
    ];

    public function up(): void
    {
        $this->setProposalKindCheck(array_merge(self::PHASE_C_KINDS, self::PHASE_D_KINDS));

        // varchar(8) → varchar(16): 'exec_committee' is 14 chars. Widening
        // never truncates; the existing seat_kind CHECK is untouched.
        DB::statement('ALTER TABLE election_races ALTER COLUMN seat_kind TYPE varchar(16)');
    }

    public function down(): void
    {
        $this->setProposalKindCheck(self::PHASE_C_KINDS);

        // seat_kind is left widened: narrowing back to varchar(8) would
        // truncate any 'exec_committee' race rows. The wider type is inert.
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
};
