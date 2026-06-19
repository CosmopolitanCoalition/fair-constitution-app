<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K-3 (The Mesh Commons) — the bridge schema linking CGA entities to Matrix (Plane B).
 *
 *  - matrix_rooms          : every appservice-created room/Space; the (entity_type, entity_id,
 *                            space_type) partial-unique is the topology reconciler's idempotency key.
 *  - matrix_identities     : users.id ↔ @localpart:domain (= social_profiles.handle). NEVER
 *                            name/email — Matrix only ever sees the pseudonym (de-anon is judicial).
 *  - matrix_event_snapshots: the ONE-WAY testimony valve — a filed Matrix event frozen into
 *                            public_records (published_record_id = the record UUID, not the seq).
 *  - matrix_carveout_log   : append-only audit of every M-1/M-2/M-4 appservice action — the durable
 *                            artifact + the cross-mesh "censorship-without-an-order" discontinuity detector.
 *  - matrix_server_acls    : per-room m.room.server_acl mirror; the allow:[] self-brick guard lives in
 *                            the writing service, this is the audit trail (mesh application is rig-gated).
 *
 * Additive only — no protected migration is touched. Enums = app-layer strings + raw CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── matrix_rooms ────────────────────────────────────────────────────
        Schema::create('matrix_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('matrix_room_id', 255)->nullable();   // !opaque:domain (set on creation)
            $table->string('matrix_alias', 255)->nullable();     // #square-<jur>:domain
            $table->string('room_type', 20);                     // m.space | commons | org_public | org_private | institution
            $table->string('room_version', 8)->nullable();       // stored from the LIVE capability, never hardcoded
            $table->string('entity_type', 24);                   // jurisdiction | organization | legislature | … | bill | petition | …
            $table->uuid('entity_id');                           // soft ref to the bound CGA entity
            $table->string('space_type', 16)->nullable();        // public_square | halls (jurisdiction rooms only)
            $table->boolean('is_public')->default(true);
            $table->boolean('is_seated')->default(false);        // flip snapshot (Legislature::STATUS_ACTIVE)
            $table->boolean('is_activated')->default(true);      // Phase-I activation-tier seam
            $table->timestampTz('tombstoned_at')->nullable();    // closed-object rooms are tombstoned, never deleted
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('entity_id');
            $table->index('matrix_room_id');
        });

        DB::statement('ALTER TABLE matrix_rooms ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE matrix_rooms ADD CONSTRAINT matrix_rooms_room_type_check "
          ."CHECK (room_type IN ('m.space','commons','org_public','org_private','institution'))"
        );
        DB::statement(
            "ALTER TABLE matrix_rooms ADD CONSTRAINT matrix_rooms_entity_type_check "
          ."CHECK (entity_type IN ('jurisdiction','organization','legislature','executive','judiciary',"
          ."'board','bill','referendum_question','petition','committee_meeting','candidacy'))"
        );
        DB::statement(
            "ALTER TABLE matrix_rooms ADD CONSTRAINT matrix_rooms_space_type_check "
          ."CHECK (space_type IS NULL OR space_type IN ('public_square','halls'))"
        );
        // THE topology-reconciler idempotency key: one live room per (entity, space_type). PG17
        // NULLS NOT DISTINCT so a null space_type (non-jurisdiction rooms) still dedupes by entity.
        DB::statement(
            'CREATE UNIQUE INDEX matrix_rooms_entity_unique ON matrix_rooms '
          .'(entity_type, entity_id, space_type) NULLS NOT DISTINCT WHERE deleted_at IS NULL'
        );
        // The Matrix room id is globally unique when set.
        DB::statement(
            'CREATE UNIQUE INDEX matrix_rooms_room_id_unique ON matrix_rooms '
          .'(matrix_room_id) WHERE matrix_room_id IS NOT NULL AND deleted_at IS NULL'
        );

        // ─── matrix_identities ───────────────────────────────────────────────
        Schema::create('matrix_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');                             // soft ref to users.id
            $table->string('matrix_localpart', 64);              // = social_profiles.handle (pseudonym)
            $table->string('matrix_user_id', 255)->nullable();   // @localpart:domain (full)
            $table->string('device_master_key', 255)->nullable();// cross-signing master key id
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id');
            // NOTE: no name / email / residency column EVER (pseudonymity rail).
        });

        DB::statement('ALTER TABLE matrix_identities ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'CREATE UNIQUE INDEX matrix_identities_user_unique ON matrix_identities '
          .'(user_id) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX matrix_identities_localpart_unique ON matrix_identities '
          .'(lower(matrix_localpart)) WHERE deleted_at IS NULL'
        );

        // ─── matrix_event_snapshots (the testimony valve, Plane B → Plane A) ──
        Schema::create('matrix_event_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('matrix_event_id', 255);
            $table->string('matrix_room_id', 255);
            $table->uuid('published_record_id');                 // soft ref to public_records.id (UUID, NOT seq)
            $table->string('actor_display', 120);                // frozen pseudonym at file time
            $table->bigInteger('origin_server_ts')->nullable();
            $table->text('body_snapshot');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('published_record_id');
            $table->index('matrix_event_id');
        });

        DB::statement('ALTER TABLE matrix_event_snapshots ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        // One testimony snapshot per Matrix event.
        DB::statement(
            'CREATE UNIQUE INDEX matrix_event_snapshots_event_unique ON matrix_event_snapshots '
          .'(matrix_event_id) WHERE deleted_at IS NULL'
        );

        // ─── matrix_carveout_log (append-only M-1/M-2/M-4 audit) ─────────────
        Schema::create('matrix_carveout_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('matrix_room_id', 255);
            $table->string('matrix_event_id', 255)->nullable();
            $table->string('carve_out', 16);                     // m1_judicial | m2_rights | m4_antispam (NEVER m3 — client-side)
            $table->string('action', 16);                        // soft_fail | hard_redact | server_acl
            $table->uuid('attestation_id')->nullable();          // the AttestationService snapshot honored
            $table->string('issuer_server_id', 255)->nullable(); // the peer that issued the attestation (rig)
            $table->uuid('public_records_id')->nullable();       // the F-SOC-003 / violation row
            $table->uuid('jurisdiction_id')->nullable();
            $table->boolean('is_seated_at_time');                // bootstrap operator-board vs seated bodies
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('matrix_room_id');
            $table->index('jurisdiction_id');
        });

        DB::statement('ALTER TABLE matrix_carveout_log ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE matrix_carveout_log ADD CONSTRAINT matrix_carveout_log_carve_out_check "
          ."CHECK (carve_out IN ('m1_judicial','m2_rights','m4_antispam'))"
        );
        DB::statement(
            "ALTER TABLE matrix_carveout_log ADD CONSTRAINT matrix_carveout_log_action_check "
          ."CHECK (action IN ('soft_fail','hard_redact','server_acl'))"
        );

        // ─── matrix_server_acls (m.room.server_acl mirror) ───────────────────
        Schema::create('matrix_server_acls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('matrix_room_id', 255);
            $table->jsonb('allow')->default('[]');               // ALWAYS retains local + every federation_peer (brick-guard)
            $table->jsonb('deny')->default('[]');                // M-1 / M-4 only, never viewpoint
            $table->string('written_by_carve_out', 16);          // m1_judicial | m4_antispam
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('matrix_room_id');
        });

        DB::statement('ALTER TABLE matrix_server_acls ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE matrix_server_acls ADD CONSTRAINT matrix_server_acls_carve_out_check "
          ."CHECK (written_by_carve_out IN ('m1_judicial','m4_antispam'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('matrix_server_acls');
        Schema::dropIfExists('matrix_carveout_log');
        Schema::dropIfExists('matrix_event_snapshots');
        Schema::dropIfExists('matrix_identities');
        Schema::dropIfExists('matrix_rooms');
    }
};
