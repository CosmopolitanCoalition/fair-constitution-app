<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase G (G8b) — add YGGDRASIL as a fifth federation transport. Yggdrasil is an
 * end-to-end-encrypted IPv6 overlay (each node gets a stable 200::/7 address derived
 * from its public key) that self-routes over any peer link — the "no DNS, no static
 * IP, double-NAT, censored uplink" survivor, complementary to tailnet (needs a
 * coordinator) and onion (high latency). Routable directly once the local daemon is
 * up, so it dials like tailnet (no SOCKS proxy).
 *
 * Additive + reversible: the established drop-and-re-add of the CHECK constraint
 * (the same technique as the kind-check widenings); no protected migration touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE federation_transports DROP CONSTRAINT IF EXISTS federation_transports_transport_check');
        DB::statement(
            "ALTER TABLE federation_transports ADD CONSTRAINT federation_transports_transport_check "
          ."CHECK (transport IN ('https','tailnet','onion','sneakernet','yggdrasil'))"
        );
    }

    public function down(): void
    {
        // Reversible: any yggdrasil rows must clear before the narrower CHECK re-applies.
        DB::table('federation_transports')->where('transport', 'yggdrasil')->delete();

        DB::statement('ALTER TABLE federation_transports DROP CONSTRAINT IF EXISTS federation_transports_transport_check');
        DB::statement(
            "ALTER TABLE federation_transports ADD CONSTRAINT federation_transports_transport_check "
          ."CHECK (transport IN ('https','tailnet','onion','sneakernet'))"
        );
    }
};
