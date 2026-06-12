<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-7 — `ballot_envelopes` + `ballots`: ESM-05 Ballot (Ranked) — THE
 * SECRECY BOUNDARY (PHASE_B_DESIGN_schema_lifecycle §A B-7 + §B.5).
 *
 * The schema-level unlinkability IS the constitutional guarantee
 * (Art. II — cryptographic separation of voter identity from ballot):
 *
 *   ballot_envelopes — participation record (double-vote prevention; the
 *     ONLY voter-linked row). No content, no hash, no receipt — nothing
 *     here can reach the ballot.
 *
 *   ballots — anonymous content. NO user column, NO envelope column, NO FK
 *     to anything voter-shaped, and NO created_at/updated_at/deleted_at —
 *     wall-clock insert time is itself a linking channel; the hour-truncated
 *     `cast_bucket` (date_trunc('hour', …), computed in the BallotBox unit)
 *     is the only time. Enforced by the constitutional test suite asserting
 *     this table's column list (BallotSecrecyTest); the single writer is
 *     app/Domain/Ballots/BallotBox.php.
 *
 * Crypto posture (design §B.5 — stated plainly, not overclaimed):
 *   - payload_encrypted = sodium secretbox of the canonical ranking JSON
 *     (incl. write-in candidacy ids — tabulated identically), keyed by the
 *     per-election data key wrapped in elections.ballot_key_wrapped. This
 *     is confidentiality against DB exfiltration, NOT against the server
 *     operator.
 *   - ballot_hash = sha256(salt ‖ canonical_json) — the salted commitment;
 *     the voter receipt is {ballot_hash, salt}; the published sorted hash
 *     list enables voter self-audit. The salt prevents brute-forcing the
 *     small ranking space.
 *   - Known residual channel: physical heap insertion order (ctid) — the
 *     post-close CLUSTER re-order in PublishBallotHashesJob is a
 *     mitigation, not a proof. Receipt-freeness/coercion resistance is
 *     explicitly out of Phase B scope (cryptographer-review list).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ballot_envelopes — voter-linked, content-free ───────────────────
        Schema::create('ballot_envelopes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('race_id');
            $table->foreign('race_id')->references('id')->on('election_races')->cascadeOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            $table->string('kind', 12);

            // No FK until Phase C (referendum_questions).
            $table->uuid('referendum_question_id')->nullable();

            $table->timestampTz('committed_at');
            $table->timestampTz('created_at')->useCurrent();

            // Double-vote prevention (extended with question id in Phase C).
            $table->unique(['race_id', 'user_id', 'kind']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE ballot_envelopes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE ballot_envelopes ADD CONSTRAINT ballot_envelopes_kind_check " .
            "CHECK (kind IN ('ranked', 'referendum'))"
        );

        // ── ballots — anonymous content, voter-free ─────────────────────────
        Schema::create('ballots', function (Blueprint $table) {
            // Random uuid — no sequence (a sequence would order-link).
            $table->uuid('id')->primary();

            $table->uuid('race_id');
            $table->foreign('race_id')->references('id')->on('election_races')->cascadeOnDelete();

            $table->string('kind', 12);

            // Encrypted ranking JSON, incl. write-in candidacy ids.
            $table->text('payload_encrypted');

            // Hex commitment salt (returned in the voter receipt; stored so
            // audit re-runs can re-verify commitments).
            $table->char('salt', 64);

            // Published self-audit list.
            $table->char('ballot_hash', 64)->unique();

            // Hour-truncated — the ONLY time on this table.
            $table->timestampTz('cast_bucket');

            $table->boolean('counted')->default(false);

            // Deliberately NO timestamps()/softDeletes() — see docblock.

            $table->index(['race_id', 'counted']);
        });

        DB::statement('ALTER TABLE ballots ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE ballots ADD CONSTRAINT ballots_kind_check " .
            "CHECK (kind IN ('ranked', 'referendum'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ballots');
        Schema::dropIfExists('ballot_envelopes');
    }
};
