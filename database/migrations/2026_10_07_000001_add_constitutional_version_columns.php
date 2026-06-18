<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-VER — the three version axes (additive only; no protected migration touched).
 * `schema_version` (wire shape) already rides config + peer metadata; this adds:
 *   - `constitutional_version` — the DERIVED hardened-compute version (does the
 *     instance count the same way?). The one that, if it changes mid-game,
 *     re-rules a live contest. Tracked on this instance, on every peer, and PINNED
 *     onto each election when it opens (so a redeploy cannot change the rules under
 *     a contest already counting — Art. II §7 non-disruption).
 *   - `app_release` — human-readable deploy/provenance tag (which code is running).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('constitutional_version')->nullable();   // the agreed hardened-compute version
            $table->string('app_release')->nullable();              // deploy provenance tag
            $table->timestampTz('version_pinned_at')->nullable();   // when constitutional_version was pinned
        });

        Schema::table('federation_peers', function (Blueprint $table) {
            $table->string('constitutional_version')->nullable();   // promoted out of metadata['schema_version']
            $table->string('app_release')->nullable();
        });

        Schema::table('elections', function (Blueprint $table) {
            // Pinned at open; the count + certification run under THIS version, not
            // the deployed one — a mid-count redeploy cannot re-rule the contest.
            $table->string('constitutional_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['constitutional_version', 'app_release', 'version_pinned_at']);
        });
        Schema::table('federation_peers', function (Blueprint $table) {
            $table->dropColumn(['constitutional_version', 'app_release']);
        });
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn('constitutional_version');
        });
    }
};
