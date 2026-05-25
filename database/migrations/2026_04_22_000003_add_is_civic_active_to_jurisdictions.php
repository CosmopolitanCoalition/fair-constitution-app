<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->boolean('is_civic_active')->default(true)->after('is_bootstrapping')
                ->comment('Instance-maintainer flag: whether this jurisdiction participates in civic cycles (elections, legislatures). Toggled by the setup-wizard tree picker.');

            $table->index('is_civic_active');
        });
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->dropIndex(['is_civic_active']);
            $table->dropColumn('is_civic_active');
        });
    }
};
