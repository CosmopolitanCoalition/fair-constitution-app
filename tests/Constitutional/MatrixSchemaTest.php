<?php

namespace Tests\Constitutional;

use App\Models\MatrixCarveoutLog;
use App\Models\MatrixRoom;
use Illuminate\Database\Connection;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (the mesh commons) bridge-schema posture. The matrix_* tables
 * are additive, UUID-keyed, soft-deletable, timestamptz; the topology reconciler's idempotency
 * rests on a partial-unique (NULLS NOT DISTINCT so a null space_type still dedupes); the testimony
 * back-pointer is a UUID (not a seq); the carve-out vocabulary is exactly M-1/M-2/M-4 (M-3 is
 * client-side, never logged); and — the pseudonymity rail — matrix_identities carries NO
 * name/email column (Matrix only ever sees the handle).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MatrixSchemaTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_matrix';

    private const TABLES = [
        'matrix_rooms', 'matrix_identities', 'matrix_event_snapshots',
        'matrix_carveout_log', 'matrix_server_acls',
    ];

    public function test_every_matrix_table_is_uuid_pk_softdeletes_timestamptz(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        foreach (self::TABLES as $table) {
            $cols = $this->columns($pg, $table);

            $this->assertNotEmpty($cols, "{$table} exists");
            $this->assertSame('uuid', $cols['id'] ?? null, "{$table}.id is uuid");

            foreach (['created_at', 'updated_at', 'deleted_at'] as $ts) {
                $this->assertArrayHasKey($ts, $cols, "{$table} has {$ts}");
                $this->assertStringStartsWith('timestamp', $cols[$ts], "{$table}.{$ts} is timestamptz");
            }
        }
    }

    public function test_the_room_entity_partial_unique_is_the_topology_idempotency_key(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);
        $def = $this->indexDef($pg, 'matrix_rooms_entity_unique');

        $this->assertNotNull($def, 'the matrix_rooms (entity_type, entity_id, space_type) partial-unique exists');
        $this->assertStringContainsStringIgnoringCase('UNIQUE', $def);
        $this->assertStringContainsString('entity_type', $def);
        $this->assertStringContainsString('entity_id', $def);
        $this->assertStringContainsString('space_type', $def);
        $this->assertStringContainsString('deleted_at IS NULL', $def, 'soft-deleted rows may coexist (re-create is a no-op)');
        // PG17 NULLS NOT DISTINCT so a null space_type (non-jurisdiction rooms) still dedupes by entity.
        $this->assertStringContainsString('NULLS NOT DISTINCT', $def, 'a null space_type must not defeat the idempotency key');
    }

    public function test_matrix_identities_carry_no_name_or_email_column(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);
        $cols = $this->columns($pg, 'matrix_identities');

        $this->assertArrayHasKey('matrix_localpart', $cols, 'the pseudonymous localpart IS present');
        foreach (['name', 'email', 'display_name', 'legal_name', 'residency'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $cols, "matrix_identities must never carry {$forbidden} (pseudonymity rail)");
        }
    }

    public function test_the_testimony_back_pointer_is_a_uuid_not_a_seq(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);
        $cols = $this->columns($pg, 'matrix_event_snapshots');

        $this->assertSame('uuid', $cols['published_record_id'] ?? null,
            'the testimony snapshot back-points at the public_records UUID, never its integer seq');
    }

    public function test_the_carveout_vocabulary_is_exactly_m1_m2_m4_and_m3_is_never_logged(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        $carve = (string) $this->checkDef($pg, 'matrix_carveout_log', 'matrix_carveout_log_carve_out_check');
        foreach ([MatrixCarveoutLog::CARVE_M1_JUDICIAL, MatrixCarveoutLog::CARVE_M2_RIGHTS, MatrixCarveoutLog::CARVE_M4_ANTISPAM] as $kind) {
            $this->assertStringContainsString("'{$kind}'", $carve, "the carve-out CHECK allows {$kind}");
        }
        $this->assertStringNotContainsString('m3', $carve, 'M-3 per-user block is client-side, never an appservice carve-out action');

        // The room-type + space-type CHECKs match the model vocabulary.
        $roomType = (string) $this->checkDef($pg, 'matrix_rooms', 'matrix_rooms_room_type_check');
        $this->assertStringContainsString("'".MatrixRoom::ROOM_SPACE."'", $roomType);
        $this->assertStringContainsString("'".MatrixRoom::ROOM_COMMONS."'", $roomType);

        $spaceType = (string) $this->checkDef($pg, 'matrix_rooms', 'matrix_rooms_space_type_check');
        $this->assertStringContainsString("'".MatrixRoom::SPACE_PUBLIC_SQUARE."'", $spaceType);
        $this->assertStringContainsString("'".MatrixRoom::SPACE_HALLS."'", $spaceType);
    }

    /** @return array<string,string> column_name => data_type */
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

    private function indexDef(Connection $pg, string $index): ?string
    {
        $row = $pg->selectOne(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND indexname = ?',
            ['public', $index]
        );

        return $row?->indexdef;
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
