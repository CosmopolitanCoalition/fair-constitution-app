<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G6 the autonomy vote) — wires the GOVERNED flip to authoritative R/W.
 *
 *  1. multi_jurisdiction_votes_kind_check: add 'local_autonomy' — the parent
 *     (current authoritative government) consent leg of an autonomy promotion.
 *  2. chamber_vote_proposals_kind_check: add 'local_autonomy_promotion' — the
 *     promoting legislature's proposal that opens the process (the D-9
 *     drop-and-re-add technique; the full current kind sets are taken from the
 *     live constraints so nothing is dropped).
 *  3. local_autonomy_processes: the dual-ratification process row (promoting
 *     supermajority + parent MJV) — authority is EARNED by population and GRANTED
 *     by the seated authoritative government, never claimed unilaterally.
 */
return new class extends Migration
{
    /** The full current proposal-kind set (from the live constraint) + the new one. */
    private const PROPOSAL_KINDS = [
        'committee_creation', 'election_board_creation', 'admin_office_creation',
        'rules_of_order', 'ethics_code', 'referendum_delegation',
        'referendum_act_modification', 'emergency_invocation', 'emergency_renewal',
        'exec_delegation', 'exec_conversion', 'department_creation',
        'cgc_creation', 'monopoly_acquisition', 'cgc_reorg_sale',
        'judiciary_creation', 'judiciary_conversion', 'judiciary_dissolution',
        'judiciary_override', 'cultural_institution', 'union', 'disintermediation',
        // Phase G
        'local_autonomy_promotion',
    ];

    /** The full current MJV-kind set (from the live constraint) + the new one. */
    private const MJV_KINDS = [
        'exec_office_create', 'exec_office_alter', 'judiciary_convert',
        'cultural_institution', 'additional_articles', 'union', 'disintermediation',
        'setting_amendment',
        // Phase G
        'local_autonomy',
    ];

    public function up(): void
    {
        $this->setProposalKindCheck(self::PROPOSAL_KINDS);
        $this->setMjvKindCheck(self::MJV_KINDS);

        Schema::create('local_autonomy_processes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('promoting_jurisdiction_id');
            $table->uuid('promoting_legislature_id');
            $table->uuid('parent_jurisdiction_id');

            // The cluster that gains authoritative R/W for the subtree.
            $table->uuid('gaining_server_id');
            $table->uuid('gaining_cluster_id')->nullable();

            // The parent-consent MultiJurisdictionVote (the granting leg).
            $table->uuid('parent_process_id');

            // The promoting jurisdiction's own population supermajority (the seeking leg).
            $table->boolean('promoting_supermajority_met')->default(false);

            $table->string('status', 12)->default('open'); // open | passed | failed

            $table->uuid('resulting_authoritative_server_id')->nullable();
            $table->integer('subtree_size')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('promoting_jurisdiction_id');
            $table->index('parent_process_id');
        });

        DB::statement('ALTER TABLE local_autonomy_processes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE local_autonomy_processes ADD CONSTRAINT local_autonomy_processes_status_check "
          ."CHECK (status IN ('open','passed','failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('local_autonomy_processes');

        $this->setProposalKindCheck(array_values(array_filter(
            self::PROPOSAL_KINDS,
            fn (string $k) => $k !== 'local_autonomy_promotion',
        )));
        $this->setMjvKindCheck(array_values(array_filter(
            self::MJV_KINDS,
            fn (string $k) => $k !== 'local_autonomy',
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
