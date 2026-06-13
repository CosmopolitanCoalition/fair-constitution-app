<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CHALLENGE E-5 (PHASE_E_DESIGN_challenge_law §A/§E) — the wiring migration:
 *
 *  1. chamber_vote_proposals_kind_check: add 'judiciary_override' (F-LEG-035,
 *     Path 2) to the existing Phase C + D + E(JUD) union (the D-9
 *     drop-and-re-add technique). Without this every F-LEG-035 override
 *     proposal would die SQLSTATE 23514, exactly as the Phase D exec kinds did.
 *  2. multi_jurisdiction_votes_kind_check: add 'setting_amendment' (Door 2a —
 *     the constituent-consent leg of a dual-door setting amendment).
 *  3. constitutional_settings: two provenance columns recording WHICH of the
 *     two-door routes last amended a setting (audit/display). No new amendment
 *     TABLE — amendments ride the existing setting_changes ledger.
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
        // Phase E — judiciary structure (E-JUD)
        'judiciary_creation', 'judiciary_conversion', 'judiciary_dissolution',
        // Phase E — challenge & law (this stage)
        'judiciary_override',
    ];

    private const MJV_KINDS = [
        'exec_office_create', 'exec_office_alter', 'judiciary_convert',
        'cultural_institution', 'additional_articles', 'union', 'disintermediation',
        // Door 2a — the constituent-consent leg of a dual-door setting amendment.
        'setting_amendment',
    ];

    public function up(): void
    {
        $this->setProposalKindCheck(self::PROPOSAL_KINDS);
        $this->setMjvKindCheck(self::MJV_KINDS);

        Schema::table('constitutional_settings', function (Blueprint $table) {
            // Which two-door route last amended a setting (audit/display).
            $table->string('last_amendment_route', 24)->nullable();
            // The multi_jurisdiction_votes / referendum_questions row for a
            // non-legislative route (NULL for the ordinary F-LEG-031 path).
            $table->uuid('last_amendment_process_id')->nullable();
        });

        DB::statement(
            'ALTER TABLE constitutional_settings ADD CONSTRAINT constitutional_settings_amendment_route_check '.
            'CHECK (last_amendment_route IS NULL OR last_amendment_route IN '.
            "('legislative_supermajority', 'constituent_supermajority', 'population_supermajority'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE constitutional_settings DROP CONSTRAINT IF EXISTS constitutional_settings_amendment_route_check');
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn(['last_amendment_route', 'last_amendment_process_id']);
        });

        $this->setProposalKindCheck(array_values(array_filter(
            self::PROPOSAL_KINDS,
            fn (string $k) => $k !== 'judiciary_override',
        )));
        $this->setMjvKindCheck(array_values(array_filter(
            self::MJV_KINDS,
            fn (string $k) => $k !== 'setting_amendment',
        )));
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

    private function setMjvKindCheck(array $kinds): void
    {
        $list = collect($kinds)->map(fn ($k) => "'{$k}'")->implode(', ');

        DB::statement('ALTER TABLE multi_jurisdiction_votes DROP CONSTRAINT IF EXISTS multi_jurisdiction_votes_kind_check');
        DB::statement(
            'ALTER TABLE multi_jurisdiction_votes ADD CONSTRAINT multi_jurisdiction_votes_kind_check '.
            "CHECK (kind IN ({$list}))"
        );
    }
};
