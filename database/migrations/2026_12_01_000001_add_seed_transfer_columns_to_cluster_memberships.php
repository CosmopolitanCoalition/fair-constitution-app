<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles-onboarding campaign, Phase 0b (geodata-seed sync on join). A joining mirror
 * pulls the host's geodata FOUNDATION (the seed) BEFORE it drains the audit corpus —
 * the seed is bulk-loaded data that never rides the Full-Faith-&-Credit tail, so the
 * audit-replay that rebuilds institutions needs the seed's jurisdiction rows to already
 * exist. These columns are the per-membership progress + integrity bookkeeping for that
 * pull, modelled on the existing backfill_cursor_seq / backfill_target_seq / backfilled_at
 * trio (MirrorBackfillService::drain). The signed values (sha256, total_bytes, version)
 * come from the origin-signed GeodataManifestService manifest; seed_cursor_bytes makes the
 * byte transport resumable; seeded_at stamps a verified, fully-applied seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cluster_memberships', function (Blueprint $table) {
            $table->string('seed_dataset')->nullable();      // e.g. 'seed:earth' — the dataset the manifest names
            $table->string('seed_version')->nullable();      // the pinned seed manifest version (fail-closed pre-check before the audit drain)
            $table->string('seed_sha256')->nullable();       // expected digest from the SIGNED manifest — verified before import
            $table->bigInteger('seed_total_bytes')->nullable();
            $table->bigInteger('seed_cursor_bytes')->default(0); // resumable byte offset for the paged transport
            $table->timestampTz('seeded_at')->nullable();    // set only when the seed is fully pulled, verified, and imported
        });
    }

    public function down(): void
    {
        Schema::table('cluster_memberships', function (Blueprint $table) {
            $table->dropColumn([
                'seed_dataset',
                'seed_version',
                'seed_sha256',
                'seed_total_bytes',
                'seed_cursor_bytes',
                'seeded_at',
            ]);
        });
    }
};
