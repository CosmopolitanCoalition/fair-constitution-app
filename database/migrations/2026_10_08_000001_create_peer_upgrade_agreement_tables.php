<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-VER 3) — the constitutional upgrade-agreement protocol.
 *
 *  1. multi_jurisdiction_votes_kind_check: add 'peer_upgrade' — Meter B, the
 *     seated-institution leg of an upgrade agreement (a hardened-rule change to a
 *     jurisdiction with a real government requires that government's supermajority
 *     consent, the same arithmetic Art. VII demands for amendments).
 *  2. peer_upgrade_proposals: a signed, scoped version-diff proposal — the upgrade
 *     equivalent of partition_exports / local_autonomy_processes. Tamper-evidence
 *     is the Ed25519 signature over the immutable proposal core PLUS the hash-chain
 *     entry every transition appends (the partition_exports/MJV pattern: a mutable
 *     status lifecycle whose every move is chained, not a frozen row).
 *  3. peer_upgrade_consents: one row per consenting authority — an operator (Meter
 *     A, the R-08 bootstrap board), a seated institution via MJV link (Meter B), or
 *     a peer (Meter C, wired in G-VER 4). Mirrors ConstituentConsent.
 *
 * Additive only — instance_settings/federation_peers/elections gained their
 * nullable version columns in 2026_10_07_000001; audit_log/ballots/jurisdictions
 * are untouched.
 */
return new class extends Migration
{
    /** The full current MJV-kind set (from the 2026_09_05 live constraint) + the new one. */
    private const MJV_KINDS = [
        'exec_office_create', 'exec_office_alter', 'judiciary_convert',
        'cultural_institution', 'additional_articles', 'union', 'disintermediation',
        'setting_amendment', 'local_autonomy',
        // Phase G (G-VER) — the seated-institution upgrade-consent leg (Meter B).
        'peer_upgrade',
    ];

    public function up(): void
    {
        $this->setMjvKindCheck(self::MJV_KINDS);

        Schema::create('peer_upgrade_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // constitutional_bump | schema_bump | app_release.
            $table->string('kind', 20);

            // The three version axes — from/to per axis (only the changed axis moves).
            $table->string('from_constitutional_version')->nullable();
            $table->string('to_constitutional_version')->nullable();
            $table->string('from_schema_version')->nullable();
            $table->string('to_schema_version')->nullable();
            $table->string('from_app_release')->nullable();
            $table->string('to_app_release')->nullable();

            // The proposed hardened policy snapshot the admissibility filter checks
            // (voting_method, supermajority_numerator/denominator). NULL for a pure
            // code-version bump with no declared policy change.
            $table->jsonb('hardened_params')->nullable();

            // The subtree this upgrade applies to (scope, like AuthorityFlipService).
            $table->uuid('affected_root_jurisdiction_id');

            // Provenance + tamper-evidence: who signed it, and the detached signature
            // over the canonical proposal (InstanceIdentityService::sign).
            $table->uuid('proposed_by_server_id')->nullable();
            $table->text('signature')->nullable();

            // open | ratified | rejected | superseded.
            $table->string('status', 12)->default('open');

            // The Meter B MultiJurisdictionVote, once opened (the seated leg).
            $table->uuid('seated_process_id')->nullable();

            $table->timestampTz('ratified_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('affected_root_jurisdiction_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE peer_upgrade_proposals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE peer_upgrade_proposals ADD CONSTRAINT peer_upgrade_proposals_kind_check "
          ."CHECK (kind IN ('constitutional_bump','schema_bump','app_release'))"
        );
        DB::statement(
            "ALTER TABLE peer_upgrade_proposals ADD CONSTRAINT peer_upgrade_proposals_status_check "
          ."CHECK (status IN ('open','ratified','rejected','superseded'))"
        );

        Schema::create('peer_upgrade_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('proposal_id');

            // operator (Meter A) | seated (Meter B) | peer (Meter C).
            $table->string('meter', 10);

            // Meter A — the consenting operator (the vetted de-facto board member).
            $table->uuid('operator_account_id')->nullable();
            $table->uuid('mesh_operator_id')->nullable();
            // Meter C — the consenting peer authoritative for a co-affected subtree.
            $table->uuid('peer_server_id')->nullable();
            // Meter B — the MultiJurisdictionVote carrying the seated consent.
            $table->uuid('mjv_process_id')->nullable();

            // pending | yes | no.
            $table->string('result', 8)->default('pending');

            // Optional attestation signature (device/peer key); the chain is the floor.
            $table->text('signature')->nullable();

            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('proposal_id');
        });

        DB::statement('ALTER TABLE peer_upgrade_consents ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE peer_upgrade_consents ADD CONSTRAINT peer_upgrade_consents_meter_check "
          ."CHECK (meter IN ('operator','seated','peer'))"
        );
        DB::statement(
            "ALTER TABLE peer_upgrade_consents ADD CONSTRAINT peer_upgrade_consents_result_check "
          ."CHECK (result IN ('pending','yes','no'))"
        );
        // One operator decides once per proposal (anti-double-count, the vetting rail).
        DB::statement(
            'CREATE UNIQUE INDEX peer_upgrade_consents_operator_unique ON peer_upgrade_consents '
          .'(proposal_id, operator_account_id) WHERE operator_account_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('peer_upgrade_consents');
        Schema::dropIfExists('peer_upgrade_proposals');

        $this->setMjvKindCheck(array_values(array_filter(
            self::MJV_KINDS,
            fn (string $k) => $k !== 'peer_upgrade',
        )));
    }

    private function setMjvKindCheck(array $kinds): void
    {
        $list = collect($kinds)->map(fn ($k) => "'{$k}'")->implode(', ');

        DB::statement('ALTER TABLE multi_jurisdiction_votes DROP CONSTRAINT IF EXISTS multi_jurisdiction_votes_kind_check');
        DB::statement(
            'ALTER TABLE multi_jurisdiction_votes ADD CONSTRAINT multi_jurisdiction_votes_kind_check '.
            "CHECK (kind IN ({$list}))"
        );
    }
};
