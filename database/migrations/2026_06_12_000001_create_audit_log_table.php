<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WF-SYS-04 — Append-only, hash-chained constitutional audit log.
 *
 * Every state-changing civic action filed through the ConstitutionalEngine
 * appends exactly one row here (rejections included, rejected = true).
 * Each row's hash = sha256(prev_hash || canonical_json(payload)) — the
 * chain makes the record tamper-evident end to end; `php artisan
 * audit:verify` re-walks every link.
 *
 * CONVENTION EXCEPTIONS (deliberate — append-only table):
 *  - NO soft deletes (`deleted_at`). Rows are immutable by construction;
 *    a deletable audit row is not an audit row. CLAUDE.md's soft-delete
 *    convention applies to mutable entities only.
 *  - NO `updated_at`. Rows are never updated; a BEFORE UPDATE OR DELETE
 *    trigger raises an exception at the database layer, and a statement
 *    trigger blocks TRUNCATE.
 *  - `actor_user_id` carries NO foreign key: (a) `users.id` is still a
 *    bigint until the WI-3 UUID rebuild lands, and (b) the chain favors
 *    immutability over referential cascade — an actor's row being purged
 *    must never be able to touch the historical record. Same reasoning
 *    for `jurisdiction_id` (jurisdictions are soft-deleted in practice).
 *
 * A genesis row (seq 1, prev_hash = 64 zeros) is seeded by this migration
 * so the chain always has an anchor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `seq` (chain order) is added below as BIGSERIAL — Blueprint has
            // no non-primary-key serial column type.

            $table->timestampTz('occurred_at')->useCurrent();

            // No FK — see migration docblock (users still bigint until WI-3).
            $table->uuid('actor_user_id')->nullable();

            // identity | residency | jurisdictions | elections | legislature |
            // executive | judiciary | organizations | settings | records |
            // federation | clocks | system   (string enum, app-layer validated)
            $table->string('module', 32);

            $table->string('event', 64);

            // Canonical registry ID this entry was filed under: F-xxx form,
            // WF-xxx workflow, or CLK-xx clock. Aliases are resolved to
            // canonical IDs before writing (FormRegistry).
            $table->string('ref', 24)->nullable();

            // No FK — immutability over cascade (see docblock).
            $table->uuid('jurisdiction_id')->nullable();

            // Canonical (recursively key-sorted) JSON. NEVER ballot content,
            // NEVER raw locations — commitments/counts only.
            $table->jsonb('payload')->default('{}');

            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            // Engine denials are first-class chain entries (WF-SYS-04).
            $table->boolean('rejected')->default(false);
            $table->text('blocked_reason')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index('module');
            $table->index('actor_user_id');
            $table->index('jurisdiction_id');
            $table->index('ref');
        });

        DB::statement('ALTER TABLE audit_log ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Chain order. BIGSERIAL (not the uuid PK) so verification can walk
        // rows in insertion order. Gaps are possible (rolled-back inserts
        // burn sequence values) and harmless — linkage is by prev_hash.
        DB::statement('ALTER TABLE audit_log ADD COLUMN seq BIGSERIAL');
        DB::statement('ALTER TABLE audit_log ADD CONSTRAINT audit_log_seq_unique UNIQUE (seq)');

        // ── Genesis row ─────────────────────────────────────────────────────
        // Hash computed with the exact algorithm AuditService uses, inlined
        // here so the migration stays self-contained:
        //   hash = sha256(prev_hash || canonical_json(payload))
        // where canonical_json recursively sorts object keys.
        $payload = [
            'algorithm' => 'sha256(prev_hash || canonical_json(payload))',
            'genesis'   => true,
            'note'      => 'Cosmopolitan Governance App audit chain genesis',
        ];
        $canonical = $this->canonicalJson($payload);
        $prevHash  = str_repeat('0', 64);

        DB::table('audit_log')->insert([
            'occurred_at' => now(),
            'module'      => 'system',
            'event'       => 'genesis',
            'ref'         => 'WF-SYS-04',
            'payload'     => $canonical,
            'prev_hash'   => $prevHash,
            'hash'        => hash('sha256', $prevHash . $canonical),
            'rejected'    => false,
            'created_at'  => now(),
        ]);

        // ── Append-only enforcement at the database layer ───────────────────
        DB::statement("
            CREATE OR REPLACE FUNCTION audit_log_block_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only: % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('
            CREATE TRIGGER audit_log_immutable
            BEFORE UPDATE OR DELETE ON audit_log
            FOR EACH ROW EXECUTE FUNCTION audit_log_block_mutation();
        ');
        DB::statement('
            CREATE TRIGGER audit_log_no_truncate
            BEFORE TRUNCATE ON audit_log
            FOR EACH STATEMENT EXECUTE FUNCTION audit_log_block_mutation();
        ');
    }

    public function down(): void
    {
        // Dev-only escape hatch: rolling back destroys the chain.
        DB::statement('DROP TRIGGER IF EXISTS audit_log_immutable ON audit_log');
        DB::statement('DROP TRIGGER IF EXISTS audit_log_no_truncate ON audit_log');
        Schema::dropIfExists('audit_log');
        DB::statement('DROP FUNCTION IF EXISTS audit_log_block_mutation');
    }

    /**
     * Recursively key-sorted JSON. MUST stay byte-identical with
     * App\Services\AuditService::canonicalJson().
     */
    private function canonicalJson(array $payload): string
    {
        $normalized = json_decode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true) ?? [];

        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $isList = array_is_list($value);
            foreach ($value as $key => $item) {
                $value[$key] = $sort($item);
            }
            if (! $isList) {
                ksort($value, SORT_STRING);
            }
            return $value;
        };

        return json_encode($sort($normalized), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
};
