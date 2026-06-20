<?php

namespace Tests\Constitutional;

use App\Models\MatrixCarveoutLog;
use Illuminate\Database\Connection;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-I.1), the M-5 legal-compliance floor schema. M-5 + the byte-
 * destroying `purge` action join the carve-out vocabulary; the immutable legal_compliance_removals
 * trail exists with a CLOSED legal_basis enum; the two new public_records kinds are countable; and —
 * THE GUARDRAIL — matrix_server_acls stays m1/m4 ONLY (M-5 may NEVER server-ACL a whole jurisdiction).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class LegalComplianceSchemaTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_legal';

    public function test_m5_and_purge_join_the_carveout_vocabulary(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        $carve = (string) $this->checkDef($pg, 'matrix_carveout_log', 'matrix_carveout_log_carve_out_check');
        $this->assertStringContainsString("'".MatrixCarveoutLog::CARVE_M5_LEGAL."'", $carve, 'm5_legal is a logged carve-out');

        $action = (string) $this->checkDef($pg, 'matrix_carveout_log', 'matrix_carveout_log_action_check');
        $this->assertStringContainsString("'".MatrixCarveoutLog::ACTION_PURGE."'", $action, 'purge (byte-delete) is an action');
    }

    public function test_the_legal_compliance_trail_has_a_closed_basis_enum(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        $cols = $this->columns($pg, 'legal_compliance_removals');
        $this->assertSame('uuid', $cols['id'] ?? null);
        $this->assertArrayHasKey('operator_account_id', $cols, 'authorized on the operator plane');
        $this->assertArrayHasKey('referral_record_id', $cols, 'the disclosure referral to seated bodies');
        $this->assertStringStartsWith('timestamp', $cols['created_at'] ?? '');
        // Immutable trail — NOT soft-deletable.
        $this->assertArrayNotHasKey('deleted_at', $cols, 'the legal-compliance trail is append-only / immutable');

        $basis = (string) $this->checkDef($pg, 'legal_compliance_removals', 'legal_compliance_removals_basis_check');
        foreach (['csam_hashmatch', 'court_order_specific', 'true_threat'] as $b) {
            $this->assertStringContainsString("'{$b}'", $basis, "the {$b} basis is allowed");
        }
        // A viewpoint basis is structurally unrepresentable.
        foreach (['hate', 'misinformation', 'offensive', 'values'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $basis, "a '{$forbidden}' legal basis is unrepresentable");
        }
    }

    public function test_m5_is_excluded_from_server_acl_writers(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        // THE GUARDRAIL: a server ACL may only ever be M-1 or M-4 — NEVER M-5. A country's illegal
        // content can never justify silencing all of that jurisdiction's residents (Art. I).
        $aclCarve = (string) $this->checkDef($pg, 'matrix_server_acls', 'matrix_server_acls_carve_out_check');
        $this->assertStringContainsString("'m1_judicial'", $aclCarve);
        $this->assertStringContainsString("'m4_antispam'", $aclCarve);
        $this->assertStringNotContainsString('m5', $aclCarve, 'M-5 may NEVER write a server ACL');
    }

    public function test_the_two_new_public_records_kinds_are_countable(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        $kinds = (string) $this->checkDef($pg, 'public_records', 'public_records_kind_check');
        $this->assertStringContainsString("'moderation_flip'", $kinds, 'the legitimacy-flip log kind');
        $this->assertStringContainsString("'legal_compliance_removal'", $kinds, 'M-5 counted separately from judicial violation');
        $this->assertStringContainsString("'violation'", $kinds, 'the judicial/viewpoint kind stays distinct');
    }

    /** @return array<string,string> */
    private function columns(Connection $pg, string $table): array
    {
        $rows = $pg->select(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?",
            [$table]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row->column_name] = $row->data_type;
        }

        return $out;
    }

    private function checkDef(Connection $pg, string $table, string $constraint): ?string
    {
        $row = $pg->selectOne(
            'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
             WHERE conrelid = ?::regclass AND conname = ?',
            [$table, $constraint]
        );

        return $row?->def;
    }
}
