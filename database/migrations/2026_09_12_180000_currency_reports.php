<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('currency_reports')) {
            Schema::create('currency_reports', function (Blueprint $t) {
                $t->uuid('currency_id')->primary();
                $t->uuid('run_id');
                $t->string('status');
                $t->string('phase');
                $t->json('checkpoint');
                $t->json('totals');
                $t->json('result')->nullable();
                $t->timestampTz('started_at');
                $t->timestampTz('updated_at');
                $t->timestampTz('result_started_at')->nullable();
                $t->timestampTz('result_completed_at')->nullable();
                $t->index(['status', 'updated_at', 'currency_id']);
            });
        }
        if (! Schema::hasTable('currency_report_balances')) {
            Schema::create('currency_report_balances', function (Blueprint $t) {
                $t->uuid('run_id');
                $t->decimal('balance', 24, 6);
                $t->unsignedBigInteger('wallets');
                $t->primary(['run_id', 'balance']);
            });
        }
        if (DB::getDriverName() !== 'pgsql') return;
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS currency_reports_status_updated_at_currency_id_index ON currency_reports (status, updated_at, currency_id)');
        foreach (['issuance_events', 'economic_accounts', 'treasury_accounts', 'market_transactions'] as $table) {
            $index = $table.'_report_cursor_idx';
            $state = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$index]);
            if ($state !== null && ! $state->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$index);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$index.' ON '.$table.' (currency_id, id)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['issuance_events', 'economic_accounts', 'treasury_accounts', 'market_transactions'] as $table) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$table.'_report_cursor_idx');
            }
        }
        Schema::dropIfExists('currency_report_balances');
        Schema::dropIfExists('currency_reports');
    }
};
