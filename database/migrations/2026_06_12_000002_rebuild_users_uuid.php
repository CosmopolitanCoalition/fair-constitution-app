<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WI-3 — Rebuild `users` on UUID primary keys (DESIGN_schema_engine.md §A.1).
 *
 * The stock bigint `users` table is dropped and recreated per the ESM-01
 * Individual column spec: uuid PK, account status machine, identity
 * verification fields (never document data), terms acceptance, languages /
 * timezone / locale / comm_prefs, and federation-first `home_server_id`
 * (NULL = this instance is authoritative for the individual).
 *
 * FOUNDER CARRY-OVER: the live dev DB holds the setup-wizard founder
 * account (created by SetupController::createFounder before this rebuild).
 * Existing rows are captured at runtime BEFORE the drop and re-inserted
 * after the recreate with a fresh uuid, `terms_accepted_at = now()` and
 * `is_operator = true` (the founder predates the terms checkbox and is by
 * definition the operator). Password hashes, names, emails and
 * `email_verified_at` survive; bigint ids do not (nothing referenced them
 * — all dependent tables are verified empty below).
 *
 * Dependent objects handled here:
 *  - sessions / password_reset_tokens — dropped + recreated stock
 *    (sessions.user_id becomes uuid; live sessions ride Redis, not this table).
 *  - location_pings.user_id, residency_confirmations.user_id,
 *    legislature_members.user_id — bigint FKs to users. All three tables
 *    are empty (guarded at runtime); columns are retyped to uuid and the
 *    FKs re-added against the new users table.
 *  - executive_members.user_id / judicial_seats.user_id — already uuid;
 *    their deferred FKs (see 2026_04_25_000002 line ~75) are added now,
 *    nullOnDelete (federation-imported members stay permissive).
 *  - audit_log.actor_user_id — deliberately stays FK-free (append-only
 *    chain favors immutability over referential cascade; see its docblock).
 */
return new class extends Migration
{
    /** Tables whose user_id columns are retyped bigint → uuid. */
    private const RETYPED = ['location_pings', 'residency_confirmations', 'legislature_members'];

    public function up(): void
    {
        // ── 0. Safety guards + founder capture ──────────────────────────────
        foreach (self::RETYPED as $tableName) {
            $count = (int) DB::table($tableName)->count();
            if ($count > 0) {
                throw new RuntimeException(
                    "{$tableName} holds {$count} row(s) referencing bigint users — " .
                    'this rebuild assumes it is empty. Migrate its rows manually first.'
                );
            }
        }

        $carried = DB::table('users')->get();

        // ── 1. Drop FKs into users, then the identity tables ────────────────
        foreach (self::RETYPED as $tableName) {
            DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_user_id_foreign");
        }

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        // ── 2. Recreate users per the ESM-01 spec ───────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');

            // Account-side states only — residency states derive from claims (WI-5).
            $table->string('status', 24)->default('registered');
            $table->timestampTz('identity_verified_at')->nullable();
            // bridge | attestation — document data is NEVER stored.
            $table->string('identity_verified_via', 16)->nullable();

            // F-IND-001 requires terms acceptance; no default — every row
            // records when its person accepted.
            $table->timestampTz('terms_accepted_at');

            $table->jsonb('languages')->default('["en"]');
            $table->string('timezone')->default('UTC');
            $table->string('locale', 12)->default('en');
            $table->jsonb('comm_prefs')->default('{}');

            // NULL = this instance is authoritative for this individual.
            $table->uuid('home_server_id')->nullable();

            $table->boolean('is_operator')->default(false);

            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('home_server_id');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_status_check " .
            "CHECK (status IN ('registered', 'identity_verified', 'deceased', 'closed'))"
        );
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_identity_verified_via_check " .
            "CHECK (identity_verified_via IS NULL OR identity_verified_via IN ('bridge', 'attestation'))"
        );

        // ── 3. Founder carry-over ────────────────────────────────────────────
        foreach ($carried as $old) {
            DB::table('users')->insert([
                // id: fresh uuid via column default
                'name'              => $old->name,
                'email'             => $old->email,
                'email_verified_at' => $old->email_verified_at,
                'password'          => $old->password, // hash carried verbatim
                'status'            => 'registered',
                'terms_accepted_at' => now(),
                'is_operator'       => true,
                'remember_token'    => null, // bigint-era tokens invalidated
                'created_at'        => $old->created_at ?? now(),
                'updated_at'        => now(),
            ]);
        }

        // ── 4. Recreate sessions (uuid user_id) + password_reset_tokens ─────
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // ── 5. Retype dependent user_id columns + re-add FKs ────────────────
        // USING (NULL::uuid) is safe: the tables are guaranteed empty above,
        // so the cast expression never evaluates against a real row.
        foreach (self::RETYPED as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN user_id TYPE uuid USING (NULL::uuid)");
            DB::statement(
                "ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_user_id_foreign " .
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
            );
        }

        // ── 6. Deferred FKs on the already-uuid columns ──────────────────────
        DB::statement(
            'ALTER TABLE executive_members ADD CONSTRAINT executive_members_user_id_foreign ' .
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
        );
        DB::statement(
            'ALTER TABLE judicial_seats ADD CONSTRAINT judicial_seats_user_id_foreign ' .
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
        );
    }

    /**
     * Dev-only escape hatch — DESTRUCTIVE: uuid user rows (including the
     * carried founder) cannot be mapped back to bigint ids and are lost.
     * Restores the stock 0001_01_01_000000 shape so the migration chain
     * stays reversible in scratch environments.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE executive_members DROP CONSTRAINT IF EXISTS executive_members_user_id_foreign');
        DB::statement('ALTER TABLE judicial_seats DROP CONSTRAINT IF EXISTS judicial_seats_user_id_foreign');

        foreach (self::RETYPED as $tableName) {
            DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_user_id_foreign");
            DB::statement("DELETE FROM {$tableName}");
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN user_id TYPE bigint USING (NULL::bigint)");
        }

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        foreach (self::RETYPED as $tableName) {
            DB::statement(
                "ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_user_id_foreign " .
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
            );
        }
    }
};
