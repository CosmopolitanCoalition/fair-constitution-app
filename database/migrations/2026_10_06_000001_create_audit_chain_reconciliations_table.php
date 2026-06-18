<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The constitutional CHAIN-RECONCILIATION ledger. The hash-chained audit log is
 * tamper-EVIDENT, not tamper-PROOF: a genuine discontinuity (a pre-fix concurrency
 * fork, a hardware fault, a hand-import of a broken record) cannot be silently
 * rewritten — that would itself be undetectable tampering. Instead a legitimate
 * authority ACKNOWLEDGES the break ON the record, with a reason, and the chain is
 * re-grounded forward from that signed acknowledgement.
 *
 * Authority is the constitutional human element: a standing government office
 * (e.g. the R-08 election board) where one exists, or — where none does yet — the
 * de-facto collective of operators (the operator plane; threshold per the G-VER
 * de-facto-board design). Each acknowledgement is ALSO appended to the chain as a
 * `chain.reconciled` audit entry (audit_seq), so the act of grounding a broken
 * record is itself on the tamper-evident record and federates.
 *
 * verifyChain treats an acknowledged break as continuous; an UNacknowledged break
 * still fails — the tamper-evidence guarantee is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->bigInteger('break_seq');                 // the discontinuous row's seq
            $table->char('observed_prev_hash', 64);          // that row's actual prev_hash (the branch we re-ground onto)
            $table->char('expected_prev_hash', 64);          // the preceding row's hash (what naive continuity expected)
            $table->text('reason');                          // the human/constitutional comment — WHY the break exists

            $table->string('authority_kind', 24);            // government_office | operator_collective
            $table->uuid('acknowledged_by_user_id')->nullable();      // the officeholder (government path)
            $table->uuid('acknowledged_by_operator_id')->nullable();  // the operator (de-facto-collective path)
            $table->jsonb('consent')->nullable();            // consenting operators / threshold detail (G-VER board)

            $table->bigInteger('audit_seq')->nullable();     // the chain.reconciled entry recording this acknowledgement
            $table->timestampTz('acknowledged_at');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('break_seq');
        });

        DB::statement('ALTER TABLE audit_chain_reconciliations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'ALTER TABLE audit_chain_reconciliations ADD CONSTRAINT audit_chain_reconciliations_authority_check '
          ."CHECK (authority_kind IN ('government_office','operator_collective'))"
        );
        // One acknowledgement per specific break (seq + the exact branch it grounds onto).
        DB::statement(
            'CREATE UNIQUE INDEX audit_chain_reconciliations_break_unique '
          .'ON audit_chain_reconciliations (break_seq, observed_prev_hash) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_reconciliations');
    }
};
