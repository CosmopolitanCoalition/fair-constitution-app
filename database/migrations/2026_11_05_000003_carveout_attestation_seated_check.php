<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase K-3 (K3-I adversarial-review hardening) — make the anti-forgery discriminator STRUCTURAL. A
 * matrix_carveout_log row carries an attestation_id ONLY for a real R-19/R-20 judicial order, which can
 * exist ONLY once a legislature is seated (the flip). So an attestation-bearing row in a bootstrap
 * (un-seated) context — a forged "judicial order before there is a judge" — must be impossible at the
 * DB layer, not merely by service convention. Operator relays, anti-spam, and every M-5 row carry a NULL
 * attestation_id and are unaffected (the OR-branch passes regardless of seatedness).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE matrix_carveout_log ADD CONSTRAINT matrix_carveout_log_attestation_seated_check '
          .'CHECK (attestation_id IS NULL OR is_seated_at_time = true)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE matrix_carveout_log DROP CONSTRAINT IF EXISTS matrix_carveout_log_attestation_seated_check');
    }
};
