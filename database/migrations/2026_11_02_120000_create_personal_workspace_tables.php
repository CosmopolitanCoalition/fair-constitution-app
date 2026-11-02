<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal workspace: the native calendar, tasks, CRM and mail that an outside
 * automation (n8n on Voyager) reads and writes through per-user API keys.
 * Every row belongs to one user. Keys are stored as hashes only. External
 * credentials are stored encrypted with a per-user key (see SecretVault).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->char('key_hash', 64)->unique();
            $t->string('key_prefix', 12);
            $t->jsonb('scopes');
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->index('user_id');
        });

        Schema::create('user_secrets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('provider', 40);
            $t->text('ciphertext');
            $t->string('hint', 8)->nullable();
            $t->timestampTz('last_revealed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['user_id', 'name']);
        });

        Schema::create('personal_calendars', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('color', 9)->nullable();
            $t->boolean('counts_as_busy')->default(true);
            $t->string('source', 24)->default('native'); // native|microsoft|google
            $t->timestampsTz();
            $t->index('user_id');
        });

        Schema::create('personal_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('calendar_id')->constrained('personal_calendars')->cascadeOnDelete();
            $t->string('title', 200);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->boolean('all_day')->default(false);
            $t->string('location', 200)->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 12)->default('confirmed'); // confirmed|tentative|cancelled
            $t->jsonb('attendees')->nullable();
            $t->string('external_source', 24)->nullable();
            $t->string('external_id', 200)->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['user_id', 'starts_at']);
            $t->unique(['user_id', 'external_source', 'external_id']);
        });

        Schema::create('availability_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('weekday'); // 0 Sunday .. 6 Saturday
            $t->time('starts_at');
            $t->time('ends_at');
            $t->string('appointment_type', 40)->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'weekday']);
        });

        Schema::create('task_projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('status', 12)->default('active'); // active|archived
            $t->timestampsTz();
            $t->index('user_id');
        });

        Schema::create('personal_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->nullable()->constrained('task_projects')->nullOnDelete();
            $t->string('title', 240);
            $t->text('notes')->nullable();
            $t->string('status', 12)->default('open'); // open|done|cancelled
            $t->unsignedSmallInteger('priority')->default(3); // 1 high .. 5 low
            $t->timestampTz('due_at')->nullable();
            $t->timestampTz('remind_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->string('repeat_rule', 60)->nullable();
            $t->string('external_source', 24)->nullable();
            $t->string('external_id', 200)->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['user_id', 'status', 'due_at']);
            $t->unique(['user_id', 'external_source', 'external_id']);
        });

        Schema::create('crm_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 160);
            $t->string('organization', 160)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('phone', 40)->nullable(); // digits with a leading +
            $t->string('contact_class', 24)->default('standard'); // standard|vip|blocked
            $t->jsonb('tags')->nullable();
            $t->string('external_source', 24)->nullable();
            $t->string('external_id', 200)->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['user_id', 'phone']);
            $t->index(['user_id', 'email']);
            $t->unique(['user_id', 'external_source', 'external_id']);
        });

        Schema::create('crm_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $t->text('body');
            $t->string('channel', 16)->default('note'); // note|call|text|email|meeting
            $t->timestampsTz();
            $t->index(['contact_id', 'created_at']);
        });

        Schema::create('crm_followups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $t->string('what', 240);
            $t->timestampTz('due_at')->nullable();
            $t->timestampTz('done_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'done_at', 'due_at']);
        });

        Schema::create('mail_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('account', 80); // which mailbox: "microsoft", "gmail", a label
            $t->string('external_id', 200);
            $t->string('from_name', 160)->nullable();
            $t->string('from_email', 255)->nullable();
            $t->string('subject', 400)->nullable();
            $t->text('preview')->nullable();
            $t->text('body')->nullable();
            $t->timestampTz('received_at');
            $t->boolean('is_read')->default(false);
            $t->boolean('is_flagged')->default(false);
            $t->timestampTz('due_at')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['user_id', 'account', 'external_id']);
            $t->index(['user_id', 'is_read', 'received_at']);
        });

        Schema::create('mail_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('account', 80)->nullable();
            $t->jsonb('to_addresses');
            $t->string('subject', 400);
            $t->text('body');
            $t->string('in_reply_to', 200)->nullable();
            // draft -> pending (asked to send) -> approved (the owner said yes in a session) -> sent | rejected
            $t->string('status', 12)->default('draft');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['mail_drafts', 'mail_messages', 'crm_followups', 'crm_notes', 'crm_contacts', 'personal_tasks',
            'task_projects', 'availability_rules', 'personal_events', 'personal_calendars', 'user_secrets', 'api_keys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
