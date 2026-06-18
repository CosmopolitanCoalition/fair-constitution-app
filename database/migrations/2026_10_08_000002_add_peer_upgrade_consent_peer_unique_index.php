<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase G (G-VER 4) — close the Meter C double-consent gap. The agreement-tables
 * migration (2026_10_08_000001) gave Meter A a partial unique index
 * (peer_upgrade_consents_operator_unique) but Meter C had none, so two concurrent
 * recordPeerConsent() calls for the same (proposal, peer) could each firstOrNew →
 * insert a duplicate. meterCPassed() de-dupes at read time, but the invariant —
 * one consent per peer per proposal (Art. VII unanimity) — belongs at the DB layer,
 * fail-closed, exactly as Meter A enforces it. Additive: a new partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX peer_upgrade_consents_peer_unique ON peer_upgrade_consents '
          .'(proposal_id, peer_server_id) WHERE peer_server_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS peer_upgrade_consents_peer_unique');
    }
};
