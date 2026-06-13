<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-O7 (PHASE_D_DESIGN_organizations §A) — `cgc_ip_register`:
 * IRREVERSIBLE public-domain dedications (Art. III §5 — CGC intellectual
 * property is ALWAYS public domain, never privatized; CLAUDE.md hard
 * constraint).
 *
 * Append-only posture (the audit_log/public_records pattern), four
 * independent layers (pinned by tests/Constitutional/
 * CgcIpPublicDomainTest):
 *   1. DB trigger raises on UPDATE/DELETE/TRUNCATE;
 *   2. UPDATE/DELETE privileges revoked from the app role;
 *   3. single-value CHECK on status — "privatize" is UNREPRESENTABLE;
 *   4. the only writer is CgcIpRegisterService::dedicate() (model
 *      forbids update/delete; source-scanned).
 *
 * Rows outlive dissolution (organization FK restrictOnDelete — orgs only
 * soft-delete; the dedication is eternal). cgc_to_private conversion code
 * never touches this table — existing dedications stand (WF-ORG-09).
 *
 * CONVENTION EXCEPTION: no updated_at, no deleted_at — by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE cgc_ip_register (
                seq                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                id                   uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                organization_id      uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                asset                varchar(255) NOT NULL,
                kind                 varchar(24) NOT NULL,
                description          text NULL,
                status               varchar(13) NOT NULL DEFAULT \'public_domain\',
                dedicated_via_form   varchar(12) NOT NULL,
                dedicated_by_user_id uuid NULL,
                published_record_id  uuid NULL,
                audit_seq            bigint NULL,
                published_at         timestamptz NULL,
                created_at           timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT cgc_ip_register_kind_check CHECK (kind IN (
                    \'software\', \'patentable_invention\', \'copyrightable_work\',
                    \'design\', \'data\', \'process\', \'other\'
                )),
                -- The schema-level statement of irreversibility: no other
                -- value can EVER be stored (Art. III §5).
                CONSTRAINT cgc_ip_register_status_public_domain CHECK (status = \'public_domain\')
            )
        ');

        DB::statement('CREATE INDEX cgc_ip_register_by_org ON cgc_ip_register (organization_id)');

        DB::statement("
            CREATE OR REPLACE FUNCTION cgc_ip_register_block_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'cgc_ip_register is append-only and irreversible (Art. III §5): % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('
            CREATE TRIGGER cgc_ip_register_immutable
            BEFORE UPDATE OR DELETE ON cgc_ip_register
            FOR EACH ROW EXECUTE FUNCTION cgc_ip_register_block_mutation();
        ');
        DB::statement('
            CREATE TRIGGER cgc_ip_register_no_truncate
            BEFORE TRUNCATE ON cgc_ip_register
            FOR EACH STATEMENT EXECUTE FUNCTION cgc_ip_register_block_mutation();
        ');

        // Privilege layer: the app role keeps INSERT/SELECT only.
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON cgc_ip_register FROM PUBLIC');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON cgc_ip_register FROM CURRENT_USER');
    }

    public function down(): void
    {
        // Dev-only escape hatch: rolling back destroys dedications.
        DB::statement('DROP TRIGGER IF EXISTS cgc_ip_register_immutable ON cgc_ip_register');
        DB::statement('DROP TRIGGER IF EXISTS cgc_ip_register_no_truncate ON cgc_ip_register');
        DB::statement('DROP TABLE IF EXISTS cgc_ip_register');
        DB::statement('DROP FUNCTION IF EXISTS cgc_ip_register_block_mutation()');
    }
};
