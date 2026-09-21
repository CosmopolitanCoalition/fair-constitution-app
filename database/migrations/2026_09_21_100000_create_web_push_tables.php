<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_keys', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->text('public_key');
            $t->text('private_key'); // encrypted with the application's key
        });
        Schema::create('web_push_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->index('user_id');
            $t->char('endpoint_hash', 64)->unique();
            $t->text('subscription'); // encrypted endpoint and browser keys
            $t->char('session_hash', 64)->index();
            $t->boolean('invitations')->default(true);
            $t->boolean('messages')->default(true);
            $t->boolean('clocks')->default(false);
            $t->foreignUuid('clock_jurisdiction_id')->nullable()->constrained('jurisdictions')->nullOnDelete();
            $t->unsignedInteger('remind_minutes')->default(60);
            $t->timestampTz('clocks_checked_at')->nullable();
            $t->timestampsTz();
            $t->index(['clocks', 'clocks_checked_at']);
        });
        Schema::create('web_push_deliveries', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('subscription_id')->constrained('web_push_subscriptions')->cascadeOnDelete();
            $t->char('event_key', 64);
            $t->string('category', 16);
            $t->jsonb('context'); // identifiers only; never private message contents
            $t->timestampTz('available_at')->index();
            $t->timestampTz('expires_at');
            $t->timestampTz('finished_at')->nullable()->index();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('outcome', 32)->nullable();
            $t->timestampsTz();
            $t->unique(['subscription_id', 'event_key']);
            $t->index(['finished_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_deliveries');
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('web_push_keys');
    }
};
