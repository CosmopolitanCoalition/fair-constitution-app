<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            // general | special | speaker | executive | judicial | committee_chair | referendum | initiative
            $table->string('type')->default('general');

            $table->string('office_type')->nullable();
            $table->uuid('office_id')->nullable();

            $table->unsignedTinyInteger('seats_to_fill')->default(5);

            // Snapshot of voting config at time of election
            $table->string('voting_method')->default('stv_droop');
            $table->unsignedSmallInteger('droop_quota')->nullable();

            $table->date('nomination_opens_on')->nullable();
            $table->date('nomination_closes_on')->nullable();
            $table->date('voting_opens_on')->nullable();
            $table->date('voting_closes_on')->nullable();
            $table->timestamp('results_certified_at')->nullable();

            // scheduled | nominations | voting | counting | certified | cancelled
            $table->string('status')->default('scheduled');

            // scheduled | vacancy | dissolution | bootstrap | manual
            $table->string('trigger')->default('scheduled');

            $table->unsignedInteger('total_valid_votes')->nullable();

            $table->uuid('election_board_id')->nullable();

            // Referendum/initiative fields
            $table->uuid('legislative_act_id')->nullable();
            $table->text('referendum_question')->nullable();
            $table->boolean('referendum_requires_supermajority')->default(false);
            $table->boolean('referendum_passed')->nullable();

            $table->uuid('district_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('jurisdiction_id');
            $table->index('type');
            $table->index('status');
            $table->index('voting_opens_on');
            $table->index('voting_closes_on');
            $table->index(['jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE elections ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('elections');
    }
};
