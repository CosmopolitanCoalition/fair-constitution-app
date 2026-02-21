<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Organizations are scoped to a jurisdiction
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            // Type — what kind of organization this is
            // political_party  = acts as a faction in elections
            // business         = for-profit private enterprise
            // nonprofit        = non-profit entity
            // common_good_corp = Article III Sec 5 CGC (legislature-created public enterprise)
            // informal         = unincorporated group, caucus, coalition
            // individual       = reserved for future use (person acting as endorser)
            $table->string('type')->default('informal');

            $table->string('name');
            $table->string('slug');
            $table->string('abbreviation')->nullable();
            $table->string('color', 7)->nullable()->comment('Hex color for political parties, e.g. #FF5733');
            $table->text('description')->nullable();
            $table->string('website_url')->nullable();

            // Organizational hierarchy — parent org (e.g. national party → local chapter)
            $table->uuid('parent_organization_id')->nullable();
            // Self-referential FK added after table creation

            // Common Good Corporation fields (Article III Sec 5)
            $table->boolean('is_cgc')->default(false)
                ->comment('True = legislature-created Common Good Corporation');
            $table->uuid('created_by_legislature_id')->nullable()
                ->comment('For CGCs: which legislature created this organization');
            $table->uuid('overseen_by_executive_id')->nullable()
                ->comment('For CGCs: which executive body oversees this');

            // Ownership — for private enterprises and CGCs
            // 'public'  = owned by the jurisdiction (CGC)
            // 'private' = privately owned
            $table->string('ownership_type')->default('private');

            // Worker co-determination tracking (Article III Sec 6)
            $table->unsignedInteger('employee_count')->default(0);

            // IP policy — CGC IP is permanently public domain (constitutional requirement)
            $table->boolean('ip_is_public_domain')->default(false)
                ->comment('Always true for CGCs per Article III Sec 5');

            // Registration and status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_registered')->default(false);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('dissolved_at')->nullable();
            $table->string('dissolution_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['jurisdiction_id', 'slug']);
            $table->index('jurisdiction_id');
            $table->index('type');
            $table->index('is_active');
            $table->index('is_cgc');
            $table->index('ownership_type');
        });

        DB::statement('ALTER TABLE organizations ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Self-referential FK — parent org (national → regional → local chapters)
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('parent_organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
