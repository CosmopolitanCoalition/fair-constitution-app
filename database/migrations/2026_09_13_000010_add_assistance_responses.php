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
        if (! Schema::hasTable('assistance_responses')) {
            Schema::create('assistance_responses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('request_id');
                $table->uuid('responder_account_id');
                $table->text('message');
                $table->string('status', 16)->default('offered');
                $table->timestampsTz();
                $table->foreign('request_id')->references('id')->on('assistance_requests');
                $table->unique(['request_id', 'responder_account_id'], 'assistance_response_once');
                $table->index(['request_id', 'id'], 'assistance_response_request_page');
                $table->index(['responder_account_id', 'request_id'], 'assistance_response_owner_page');
            });
        }
        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        foreach ([
            'assistance_public_page' => "ON assistance_requests (id DESC) WHERE privacy = 'public' AND status = 'open' AND deleted_at IS NULL",
            'assistance_request_owner_page' => 'ON assistance_requests (requester_account_id, id DESC) WHERE deleted_at IS NULL',
            'assistance_assigned_owner_page' => 'ON assistance_requests (responder_account_id, id DESC) WHERE deleted_at IS NULL',
        ] as $name => $definition) {
            if ($concurrently !== '') {
                $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
                if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX '.$concurrently.'IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_responses');
        DB::statement('DROP INDEX IF EXISTS assistance_public_page');
        DB::statement('DROP INDEX IF EXISTS assistance_request_owner_page');
        DB::statement('DROP INDEX IF EXISTS assistance_assigned_owner_page');
    }
};
