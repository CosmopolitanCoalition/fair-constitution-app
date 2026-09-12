<?php

use App\Services\Demo\DemoSessionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Preserve later users' work and resume interrupted demo cleanup per row. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->timestampTz('void_started_at')->nullable();
        });
        Schema::table('demo_session_writes', function (Blueprint $table) {
            // Historical records have no after image. Cleanup preserves them
            // when it cannot prove that an UPDATE/INSERT is still unchanged.
            $table->jsonb('after')->nullable();
            $table->string('resolution', 16)->nullable();
            $table->string('reversal_error', 255)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->index(['demo_session_id', 'resolution', 'seq'], 'demo_writes_pending_index');
        });

        // Existing table triggers call this function already. No world table
        // rewrite, data reset, or trigger reinstall is needed.
        app(DemoSessionService::class)->installCapture(installTriggers: false);
    }

    public function down(): void
    {
        Schema::table('demo_session_writes', function (Blueprint $table) {
            $table->dropIndex('demo_writes_pending_index');
            $table->dropColumn(['after', 'resolution', 'reversal_error', 'resolved_at']);
        });
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->dropColumn('void_started_at');
        });
        app(DemoSessionService::class)->installCapture(installTriggers: false);
    }
};
