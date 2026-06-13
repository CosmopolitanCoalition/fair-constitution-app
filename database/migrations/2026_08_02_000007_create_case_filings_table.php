<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-CASES E-7 (PHASE_E_DESIGN_cases_juries §A) — `case_filings`: the docket.
 * Every motion / evidence / brief / order docketed to a case. APPEND-ONLY
 * register (no updated_at, no soft deletes, BEFORE UPDATE/DELETE + TRUNCATE
 * triggers — the public_records / cgc_ip_register posture): the docket is
 * immutable, "nothing argued in open court is ever sealed retroactively".
 *
 * Judge rulings on motions/evidence (granted/denied/admitted/excluded +
 * written reasons) APPEND a follow-up filing row, never edit the original.
 * The attach-window rule (motions before/during Hearing, evidence on the
 * open docket, briefs until Deliberation) is enforced by CaseFilingService
 * against the live cases.status — a brief filed after `deliberation` is
 * rejected with citation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE case_filings (
                seq               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                id                uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                case_id           uuid NOT NULL REFERENCES cases(id) ON DELETE RESTRICT,
                filing_form       varchar(16) NOT NULL,
                filing_kind       varchar(16) NOT NULL,
                filed_by_user_id  uuid NULL REFERENCES users(id) ON DELETE SET NULL,
                filed_by_role     varchar(8) NULL,
                advocate_id       uuid NULL,
                title             text NULL,
                body              text NULL,
                ruling            varchar(12) NULL,
                ruling_reason     text NULL,
                accepted_at_state varchar(20) NULL,
                record_id         uuid NULL,
                audit_seq         bigint NULL,
                created_at        timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT case_filings_filing_form_check CHECK (filing_form IN (
                    \'F-IND-017\', \'F-ADV-001\', \'F-ADV-002\', \'F-ADV-003\', \'F-ADV-004\',
                    \'F-JDG-001\', \'F-JDG-002\', \'F-JDG-003\', \'F-JDG-009\', \'F-JDG-010\'
                )),
                CONSTRAINT case_filings_filing_kind_check CHECK (filing_kind IN (
                    \'case_filing\', \'motion\', \'evidence\', \'brief\', \'order\',
                    \'panel_assignment\', \'jury_order\', \'opinion\', \'sentence\', \'warrant\', \'ruling\'
                )),
                CONSTRAINT case_filings_ruling_check CHECK (ruling IS NULL OR ruling IN (
                    \'granted\', \'denied\', \'admitted\', \'excluded\'
                ))
            )
        ');

        DB::statement('CREATE INDEX case_filings_by_case ON case_filings (case_id, seq)');
        DB::statement('CREATE INDEX case_filings_by_advocate ON case_filings (advocate_id)');
        DB::statement('CREATE INDEX case_filings_by_user ON case_filings (filed_by_user_id)');

        // ── Append-only enforcement (the public_records pattern) ────────────
        DB::statement("
            CREATE OR REPLACE FUNCTION case_filings_block_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'case_filings is an append-only docket: % is not permitted (nothing argued in open court is sealed retroactively)', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('
            CREATE TRIGGER case_filings_immutable
            BEFORE UPDATE OR DELETE ON case_filings
            FOR EACH ROW EXECUTE FUNCTION case_filings_block_mutation();
        ');
        DB::statement('
            CREATE TRIGGER case_filings_no_truncate
            BEFORE TRUNCATE ON case_filings
            FOR EACH STATEMENT EXECUTE FUNCTION case_filings_block_mutation();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS case_filings_immutable ON case_filings');
        DB::statement('DROP TRIGGER IF EXISTS case_filings_no_truncate ON case_filings');
        DB::statement('DROP TABLE IF EXISTS case_filings');
        DB::statement('DROP FUNCTION IF EXISTS case_filings_block_mutation()');
    }
};
