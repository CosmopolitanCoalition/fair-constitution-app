<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 (Flag 2) — widen the CRL uniqueness from (attestation_id) to (attestation_id, issuer_server_id).
 *
 * Cross-node revocation propagation (Flag 2) materializes a FOREIGN revocation carrying its issuer's claim.
 * The consume side (verifyAttestation → isRevoked) now matches a revocation ONLY when its issuer equals the
 * attestation's own issuer, so a row claiming a different issuer can never suppress an attestation. But with
 * a (attestation_id)-only unique slot, a hostile trusted peer could PRE-PLANT a row for an attestation_id it
 * does not own and thereby BLOCK the genuine issuer's later revocation from materializing. Keying uniqueness
 * on (attestation_id, issuer_server_id) lets the genuine issuer's revocation coexist with (and override, at
 * verify time) any other-issuer noise — closing that second-order denial-of-revocation. One live revocation
 * per (attestation, issuer); for our own attestations there is exactly one issuer (us), so legit behaviour is
 * unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS attestation_revocations_one_per_attestation');
        DB::statement(
            'CREATE UNIQUE INDEX attestation_revocations_one_per_attestation_issuer '
          .'ON attestation_revocations (attestation_id, issuer_server_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attestation_revocations_one_per_attestation_issuer');
        DB::statement(
            'CREATE UNIQUE INDEX attestation_revocations_one_per_attestation '
          .'ON attestation_revocations (attestation_id) WHERE deleted_at IS NULL'
        );
    }
};
