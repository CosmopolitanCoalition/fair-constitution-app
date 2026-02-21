<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legislatures', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            $table->unsignedSmallInteger('term_number')->default(1);
            $table->date('term_starts_on')->nullable();
            $table->date('term_ends_on')->nullable();

            // forming | active | dissolved | bootstrapping
            $table->string('status')->default('forming');

            $table->unsignedTinyInteger('total_seats')->default(5)
                ->comment('Between 5 and 9 per constitutional settings');
            $table->unsignedTinyInteger('type_a_seats')->default(5);
            $table->unsignedTinyInteger('type_b_seats')->default(0);

            // Speaker elected at first meeting by supermajority
            $table->uuid('speaker_id')->nullable()->comment('FK to legislature_members added later');

            // Quorum = floor(total_seats/2) + 1
            $table->unsignedTinyInteger('quorum_required')->default(3);

            $table->date('last_met_on')->nullable();
            $table->date('next_meeting_due_by')->nullable();

            $table->uuid('parent_legislature_id')->nullable();
            // Self-referential FK added after table creation

            $table->timestamps();
            $table->softDeletes();

            $table->index('jurisdiction_id');
            $table->index('status');
            $table->index(['jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE legislatures ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::table('legislatures', function (Blueprint $table) {
            $table->foreign('parent_legislature_id')->references('id')->on('legislatures')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legislatures');
    }
};
