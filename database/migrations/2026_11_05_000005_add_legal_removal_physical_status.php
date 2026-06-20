<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K-3 (K3-N P1) — the HONESTY column for the M-5 legal-compliance trail. A redaction does NOT
 * destroy media bytes and the admin media-DELETE is operator-config / rig-gated, so the trail must say
 * exactly whether the PHYSICAL removal actually completed — never report "purged" while the bytes remain.
 *
 *   deferred — the redaction landed but the byte-DELETE is not yet done (no admin token, or a text event)
 *   done     — the homeserver physical action completed (a redaction, or a confirmed media byte-DELETE)
 *   failed   — the homeserver was unreachable / the admin DELETE errored
 *
 * This is OPERATIONAL state on the evidence trail (it advances as the real-world action completes); the
 * SEALED legal record (the audit_log entry + the public_records row) is unchanged and immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_compliance_removals', function (Blueprint $table) {
            $table->string('physical_removal_status', 16)->default('deferred')->after('action');
        });

        DB::statement(
            "ALTER TABLE legal_compliance_removals ADD CONSTRAINT legal_compliance_removals_physical_status_check "
          ."CHECK (physical_removal_status IN ('deferred','done','failed'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE legal_compliance_removals DROP CONSTRAINT IF EXISTS legal_compliance_removals_physical_status_check');
        Schema::table('legal_compliance_removals', function (Blueprint $table) {
            $table->dropColumn('physical_removal_status');
        });
    }
};
