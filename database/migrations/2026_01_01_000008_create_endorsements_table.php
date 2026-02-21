<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Endorsements are fully generalized:
        // - ANY organization (party, business, nonprofit, CGC, informal group) can endorse
        // - ANY individual user can endorse
        // - Endorsees are candidates in elections
        Schema::create('endorsements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_id');
            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();

            // The candidate being endorsed (FK to candidates table, created with ballots)
            $table->uuid('candidate_id');

            // Polymorphic endorser — either an organization or an individual user
            // endorser_type: 'organization' | 'user'
            $table->string('endorser_type');
            $table->uuid('endorser_id')->comment('UUID of organization or user');

            // Endorsement details
            $table->text('statement')->nullable()
                ->comment('Public endorsement statement');
            $table->timestamp('endorsed_at');
            $table->timestamp('withdrawn_at')->nullable()
                ->comment('Endorsements can be withdrawn before voting opens');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // An endorser can only endorse a candidate once per election
            $table->unique(['election_id', 'candidate_id', 'endorser_type', 'endorser_id']);
            $table->index('election_id');
            $table->index('candidate_id');
            $table->index(['endorser_type', 'endorser_id']);
        });

        DB::statement('ALTER TABLE endorsements ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('endorsements');
    }
};
