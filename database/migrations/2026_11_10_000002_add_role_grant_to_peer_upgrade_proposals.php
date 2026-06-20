<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesh Roles & Channels of Trust (★7) — teach the G-VER upgrade-agreement protocol a fourth proposal
 * kind: `role_grant`. A capability grant flows through the SAME dual-meter consent (Meter A operator
 * board / Meter B seated government / Meter C co-affected peers) that gates a constitutional bump — so
 * elevating a box to a governed channel (broker.tls, authority.grant, matrix.homeserver, …) follows
 * legitimacy through proven machinery, no new vote math.
 *
 * Reuses peer_upgrade_proposals verbatim (affected_root_jurisdiction_id = the grant scope,
 * proposed_by_server_id = the requesting/grantee box) + two additive columns: the channel requested and,
 * once ratified, the minted + signed grant envelope (the cryptographic receipt written onto the grantee's
 * instance_capabilities row). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peer_upgrade_proposals', function (Blueprint $table) {
            $table->string('capability', 32)->nullable();   // the governed channel requested (role_grant only)
            $table->jsonb('grant_payload')->nullable();      // the minted, signed capability-grant envelope
        });

        DB::statement('ALTER TABLE peer_upgrade_proposals DROP CONSTRAINT IF EXISTS peer_upgrade_proposals_kind_check');
        DB::statement(
            "ALTER TABLE peer_upgrade_proposals ADD CONSTRAINT peer_upgrade_proposals_kind_check "
          ."CHECK (kind IN ('constitutional_bump','schema_bump','app_release','role_grant'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE peer_upgrade_proposals DROP CONSTRAINT IF EXISTS peer_upgrade_proposals_kind_check');
        DB::statement(
            "ALTER TABLE peer_upgrade_proposals ADD CONSTRAINT peer_upgrade_proposals_kind_check "
          ."CHECK (kind IN ('constitutional_bump','schema_bump','app_release'))"
        );

        Schema::table('peer_upgrade_proposals', function (Blueprint $table) {
            $table->dropColumn(['capability', 'grant_payload']);
        });
    }
};
