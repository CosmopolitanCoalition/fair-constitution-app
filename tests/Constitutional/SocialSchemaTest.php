<?php

namespace Tests\Constitutional;

use Illuminate\Database\Connection;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (the civic record plane) schema posture. The social_*
 * tables are additive, UUID-keyed, soft-deletable, timestamptz; the auto-bind reconciler's
 * idempotency rests on a partial-unique index; and — Art. I — a public-square thread/post has
 * NO 'removed' status (the square is uncensorable; the only removals are the four office-gated
 * carve-outs, each logged to public_records, never a status flip).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SocialSchemaTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k1_schema';

    private const TABLES = [
        'social_profiles', 'social_spaces', 'social_subforums', 'social_threads',
        'social_posts', 'social_reactions', 'social_follows', 'social_memberships',
    ];

    public function test_every_social_table_is_uuid_pk_softdeletes_timestamptz(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        foreach (self::TABLES as $table) {
            $cols = $this->columns($pg, $table);

            $this->assertNotEmpty($cols, "{$table} exists");
            $this->assertArrayHasKey('id', $cols, "{$table} has an id column");
            $this->assertSame('uuid', $cols['id'], "{$table}.id is uuid");

            foreach (['created_at', 'updated_at', 'deleted_at'] as $ts) {
                $this->assertArrayHasKey($ts, $cols, "{$table} has {$ts}");
                $this->assertStringStartsWith('timestamp', $cols[$ts], "{$table}.{$ts} is timestamptz");
            }
        }
    }

    public function test_the_subforum_object_partial_unique_is_the_reconciler_idempotency_key(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);
        $def = $this->indexDef($pg, 'social_subforums_object_unique');

        $this->assertNotNull($def, 'the subforum (object_type, object_id) partial-unique exists');
        $this->assertStringContainsStringIgnoringCase('UNIQUE', $def);
        $this->assertStringContainsString('governing_object_type', $def);
        $this->assertStringContainsString('governing_object_id', $def);
        $this->assertStringContainsString('deleted_at IS NULL', $def, 'soft-deleted rows may coexist (re-create is a no-op)');
    }

    public function test_a_public_square_post_has_no_removed_or_locked_status(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        $threadCheck = (string) $this->checkDef($pg, 'social_threads', 'social_threads_status_check');
        $this->assertStringContainsString("'open'", $threadCheck);
        $this->assertStringContainsString("'archived'", $threadCheck);
        $this->assertStringNotContainsString('removed', $threadCheck, 'Art. I — a public-square thread is uncensorable; no removed status');

        $spaceStatus = (string) $this->checkDef($pg, 'social_spaces', 'social_spaces_status_check');
        $this->assertStringNotContainsString('locked', $spaceStatus, 'a public space is never lockable');

        $spaceType = (string) $this->checkDef($pg, 'social_spaces', 'social_spaces_type_check');
        $this->assertStringContainsString('public_square', $spaceType);
        $this->assertStringContainsString('halls', $spaceType);
    }

    public function test_local_only_and_public_uniqueness_rails_exist(): void
    {
        $pg = $this->livePg(self::LIVE_CONNECTION);

        // The local-only graph dedupes per user; the public spaces are one-square-one-halls.
        foreach ([
            'social_reactions_unique'    => 'deleted_at IS NULL',
            'social_follows_unique'      => 'deleted_at IS NULL',
            'social_profiles_user_unique' => 'deleted_at IS NULL',
            'social_spaces_jur_type_unique' => 'is_private = false',
        ] as $index => $needle) {
            $def = $this->indexDef($pg, $index);
            $this->assertNotNull($def, "{$index} exists");
            $this->assertStringContainsStringIgnoringCase('UNIQUE', $def);
            $this->assertStringContainsString($needle, $def, "{$index} carries its partial predicate");
        }
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
