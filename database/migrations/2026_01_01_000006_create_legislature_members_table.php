<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legislature_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->cascadeOnDelete();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Nullable for independent/factionless members
            $table->uuid('primary_faction_id')->nullable();
            $table->foreign('primary_faction_id')->references('id')->on('organizations')->nullOnDelete();

            // Multi-faction support for committee proportionality
            $table->json('additional_faction_ids')->default('[]');

            // 'a' = constituent rep, 'b' = at-large (Article V Sec 3 bicameral)
            $table->char('seat_type', 1)->default('a');

            $table->uuid('district_id')->nullable()->comment('FK to districts table (future)');

            $table->date('seated_on')->nullable();
            $table->date('term_ends_on')->nullable();

            // active | vacant | expelled | resigned | deceased
            $table->string('status')->default('active');

            $table->timestamp('vacated_at')->nullable();
            $table->string('vacancy_reason')->nullable();

            $table->uuid('election_id')->nullable()->comment('FK to elections table');
            $table->unsignedSmallInteger('vote_count')->nullable();

            $table->boolean('is_speaker')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('legislature_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('primary_faction_id');
            $table->unique(['legislature_id', 'user_id']);
        });

        DB::statement('ALTER TABLE legislature_members ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('legislature_members');
    }
};
