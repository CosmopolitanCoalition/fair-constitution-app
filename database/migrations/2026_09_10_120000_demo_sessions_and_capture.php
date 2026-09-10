<?php

use App\Services\Demo\DemoSessionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEMO SESSIONS (operator rulings 2026-09-10, DemoMode C + A): the bookkeeping
 * for the compensating purge, and the row-capture trigger on every capturable
 * table. Additive. The trigger's WHEN clause reads one transaction-local
 * setting, so on a production box (where the engine never sets it) the cost
 * per row is the clause alone; the function never runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('session_id', 255)->index();
            $t->uuid('user_id')->nullable()->index();
            $t->timestampTz('started_at');
            $t->timestampTz('last_seen_at');
            $t->timestampTz('voided_at')->nullable()->index();
            $t->string('void_reason', 32)->nullable();
            $t->unsignedInteger('writes')->nullable();
            $t->unsignedInteger('reversed')->nullable();
        });

        Schema::create('demo_session_writes', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->uuid('demo_session_id')->index();
            $t->string('table_name', 128);
            $t->jsonb('pk');
            $t->string('op', 8);
            $t->jsonb('before')->nullable();
            $t->timestampTz('created_at');
        });

        app(DemoSessionService::class)->installCapture();
    }

    public function down(): void
    {
        foreach (DB::select("SELECT event_object_table AS t FROM information_schema.triggers WHERE trigger_name = 'cga_demo_capture' GROUP BY 1") as $row) {
            DB::unprepared("DROP TRIGGER IF EXISTS cga_demo_capture ON \"{$row->t}\"");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS cga_demo_capture()');
        Schema::dropIfExists('demo_session_writes');
        Schema::dropIfExists('demo_sessions');
    }
};
