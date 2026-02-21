<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An organization registers as a recognized faction for a specific legislature term.
        // This is what drives committee proportionality calculations.
        // Any organization type can register — a business, a party, an informal group.
        // Members with no faction registration are tracked as 'independent'.
        Schema::create('legislature_faction_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->cascadeOnDelete();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            // How many seats this faction holds in this legislature (updated as members change)
            $table->unsignedTinyInteger('seat_count')->default(0);

            // Proportional share of seats — used for committee assignment algorithm
            // Stored as decimal for precision in fractional calculations
            $table->decimal('seat_proportion', 5, 4)->default(0.0000)
                ->comment('seat_count / total legislature seats');

            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('deregistered_at')->nullable();

            $table->timestamps();

            // One registration per org per legislature term
            $table->unique(['legislature_id', 'organization_id']);
            $table->index('legislature_id');
            $table->index('organization_id');
        });

        DB::statement('ALTER TABLE legislature_faction_registrations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('legislature_faction_registrations');
    }
};
