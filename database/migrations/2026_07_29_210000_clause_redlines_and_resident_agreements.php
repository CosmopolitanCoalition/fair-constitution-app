<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design Round 2 build pieces 5 + 6 (operator ruling 2026-07-29; desk slot
 * granted). Four additive tables, one migration:
 *
 *   PIECE 5 — the shared clause/redline overlay. Clauses layer ALONGSIDE the
 *   whole-text authorities (org_contracts.terms · bill_versions.law_text stay
 *   authoritative — the WIRING_PLAN pin "BillVersion stays whole-text"). The
 *   overlay is polymorphic over subject_type ∈ {org_contract, bill}. A redline
 *   is a proposed edit/add/strike with a lifecycle; rights_flag carries the
 *   Art. I structured tag (a clause that WAIVES a listed right is refused
 *   pre-commit, and void-in-part regardless).
 *
 *   PIECE 6 — person-to-person / N-party agreements (the #9 reversal). An
 *   agreement between residents, with no organization (org_contracts requires
 *   an org). N signers; the agreement is active only when ALL have signed
 *   (Art. I: a one-sided contract never takes effect).
 *
 * Additive only. down() drops in reverse dependency order.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Piece 5 — the clause/redline overlay ─────────────────────────
        Schema::create('clauses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type', 16);            // org_contract | bill
            $table->uuid('subject_id');
            $table->integer('ordinal')->default(0);
            $table->string('heading', 200)->nullable();
            $table->text('body');
            // Art. I structured tag: a listed right this clause touches
            // (voting|candidacy|residency|petition|due_process). NULL = none.
            $table->string('rights_flag', 32)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('redlines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type', 16);            // org_contract | bill
            $table->uuid('subject_id');
            $table->uuid('clause_id')->nullable();         // NULL = a proposed NEW clause
            $table->uuid('proposer_user_id');              // a person always acts
            $table->string('kind', 8);                     // edit | add | strike
            $table->text('body');
            $table->text('rationale')->nullable();
            $table->string('rights_flag', 32)->nullable();
            $table->string('status', 12)->default('pending'); // pending|accepted|rejected|withdrawn
            $table->timestampsTz();
            $table->index(['subject_type', 'subject_id']);
            $table->foreign('clause_id')->references('id')->on('clauses')->nullOnDelete();
        });

        // ── Piece 6 — person-to-person / N-party agreements ──────────────
        Schema::create('resident_agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 200);
            $table->text('terms');
            $table->uuid('initiator_user_id');
            $table->string('status', 8)->default('draft'); // draft|offered|active|ended|voided
            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('initiator_user_id');
        });

        Schema::create('resident_agreement_signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agreement_id');
            $table->uuid('signer_user_id');
            $table->timestampTz('signed_at')->nullable();  // NULL until this party signs
            $table->timestampsTz();
            $table->unique(['agreement_id', 'signer_user_id']);
            $table->foreign('agreement_id')->references('id')->on('resident_agreements')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_agreement_signers');
        Schema::dropIfExists('resident_agreements');
        Schema::dropIfExists('redlines');
        Schema::dropIfExists('clauses');
    }
};
