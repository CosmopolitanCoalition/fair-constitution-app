<?php

namespace Tests\Integration;

use App\Services\AuditService;
use App\Services\Demo\DemoSessionService;
use App\Support\DemoMode;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in integration tests on a new, EMPTY disposable PostgreSQL database.
 * No Laravel boot, live-test trait, application schema, or world data is used.
 *
 * DEMO_CLEANUP_TEST_DATABASE=wos_demo_cleanup_test_<suffix>
 * php vendor/bin/phpunit tests/Integration/DemoSessionConflictTest.php
 */
final class DemoSessionConflictTest extends TestCase
{
    private DemoSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('DEMO_CLEANUP_TEST_DATABASE') ?: '';
        if ($database === '') {
            $this->markTestSkipped('Requires an explicitly named disposable PostgreSQL database.');
        }
        self::assertMatchesRegularExpression('/^wos_demo_cleanup_test_[a-z0-9_]+$/', $database);

        // Load credentials without booting application providers or connecting
        // to the configured application database. The database is always overridden.
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $config = [
            'driver' => 'pgsql', 'host' => env('DB_HOST', 'postgres'), 'port' => env('DB_PORT', '5432'),
            'database' => $database, 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'),
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ];

        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $capsule = new Capsule($container);
        $capsule->addConnection($config);
        $capsule->addConnection($config, 'competing');
        $capsule->setAsGlobal();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());

        self::assertSame($database, DB::scalar('SELECT current_database()'));
        $allowed = ['audit_log', 'demo_sessions', 'demo_session_writes', 'fixture_items', 'fixture_children', 'fixture_plain'];
        $existing = DB::table('information_schema.tables')->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')->pluck('table_name')->all();
        self::assertSame([], array_values(array_diff($existing, $allowed)), 'Refuse any database containing non-fixture tables.');
        // These are the only tables this harness owns, in the verified test DB.
        foreach (['fixture_children', 'fixture_items', 'fixture_plain', 'demo_session_writes', 'demo_sessions', 'audit_log'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS cga_demo_capture()');
        DB::unprepared(<<<'SQL'
CREATE TABLE fixture_items (id uuid PRIMARY KEY, code text UNIQUE NOT NULL, label text NOT NULL,
    amount numeric(30,0) DEFAULT 1, properties jsonb DEFAULT '{}'::jsonb, deleted_at timestamptz);
CREATE TABLE fixture_children (id uuid PRIMARY KEY, item_id uuid REFERENCES fixture_items(id) ON DELETE CASCADE,
    item_code text REFERENCES fixture_items(code) ON UPDATE CASCADE, label text, deleted_at timestamptz);
CREATE INDEX fixture_children_item_id ON fixture_children(item_id);
CREATE INDEX fixture_children_item_code ON fixture_children(item_code);
CREATE TABLE fixture_plain (id uuid PRIMARY KEY, label text NOT NULL);
CREATE TABLE audit_log (seq bigserial PRIMARY KEY, occurred_at timestamptz, actor_user_id uuid,
    module text, event text, ref text, jurisdiction_id uuid, payload jsonb, prev_hash text,
    hash text, rejected boolean, blocked_reason text, created_at timestamptz);
SQL);
        $genesis = AuditService::canonicalJson(['genesis' => true]);
        DB::table('audit_log')->insert([
            'event' => 'genesis', 'payload' => $genesis, 'prev_hash' => AuditService::GENESIS_PREV_HASH,
            'hash' => AuditService::chainHash(AuditService::GENESIS_PREV_HASH, $genesis),
        ]);
        $this->service = new DemoSessionService(new AuditService);
        $container->instance(DemoSessionService::class, $this->service);

        // Exercise the real migration order, including the legacy installer.
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_10_120000_demo_sessions_and_capture.php')->up();
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_12_120000_demo_reversal_evidence.php')->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->service)) {
            DB::disconnect('competing');
            DB::disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
        }
        parent::tearDown();
    }

    public function test_unchanged_insert_update_delete_and_exact_json_round_trip(): void
    {
        $existing = $this->item('original');
        $deleted = $this->item('restore-me');
        $session = $this->session();
        $inserted = null;
        $this->capture($session, function () use ($existing, $deleted, &$inserted): void {
            $inserted = $this->item('new');
            DB::table('fixture_items')->where('id', $existing)->update([
                'label' => 'changed', 'amount' => '123456789012345678901234567890',
                'properties' => '{"b":true,"a":[1,"1",null]}',
            ]);
            DB::table('fixture_items')->where('id', $deleted)->delete();
        });
        $report = $this->service->void($session);
        self::assertSame(3, $report['reversed'], json_encode($report));
        self::assertSame([], $report['skipped']);
        self::assertSame('original', DB::table('fixture_items')->where('id', $existing)->value('label'));
        self::assertSame('1', DB::table('fixture_items')->where('id', $existing)->value('amount'));
        self::assertNotNull(DB::table('fixture_items')->where('id', $inserted)->value('deleted_at'));
        self::assertSame('restore-me', DB::table('fixture_items')->where('id', $deleted)->value('label'));
        self::assertSame(3, DB::table('demo_session_writes')->where('demo_session_id', $session)->count());
        $this->assertAudit($session, 3, 0);
    }

    public function test_later_session_and_uncaptured_updates_survive_cleanup(): void
    {
        foreach ([true, false] as $capturedLater) {
            $id = $this->item('original');
            $first = $this->session();
            $later = $this->session();
            $this->capture($first, fn () => DB::table('fixture_items')->where('id', $id)->update(['label' => 'first']));
            $write = fn () => DB::table('fixture_items')->where('id', $id)->update(['label' => 'later']);
            $capturedLater ? $this->capture($later, $write) : $write();
            $report = $this->service->void($first);
            self::assertSame(0, $report['reversed']);
            self::assertStringStartsWith('row_changed:', $report['skipped'][0]['error']);
            self::assertSame('later', DB::table('fixture_items')->where('id', $id)->value('label'));
            $this->assertAudit($first, 0, 1);
        }
    }

    public function test_later_edit_to_inserted_row_is_preserved(): void
    {
        $session = $this->session();
        $id = $this->capture($session, fn () => $this->item('first'));
        DB::table('fixture_items')->where('id', $id)->update(['label' => 'shared-edit']);
        $report = $this->service->void($session);
        self::assertSame(0, $report['reversed']);
        self::assertNull(DB::table('fixture_items')->where('id', $id)->value('deleted_at'));
        self::assertSame('shared-edit', DB::table('fixture_items')->where('id', $id)->value('label'));
    }

    public function test_foreign_key_dependency_prevents_soft_delete_and_cascade(): void
    {
        $session = $this->session();
        $id = $this->capture($session, fn () => $this->item('parent'));
        $child = (string) Str::uuid();
        DB::table('fixture_children')->insert(['id' => $child, 'item_id' => $id, 'label' => 'later work']);
        $report = $this->service->void($session);
        self::assertSame(0, $report['reversed']);
        self::assertStringStartsWith('row_referenced:', $report['skipped'][0]['error']);
        self::assertNull(DB::table('fixture_items')->where('id', $id)->value('deleted_at'));
        self::assertSame('later work', DB::table('fixture_children')->where('id', $child)->value('label'));
    }

    public function test_reverting_referenced_unique_key_does_not_cascade_into_later_work(): void
    {
        $id = $this->item('parent');
        $session = $this->session();
        $this->capture($session, fn () => DB::table('fixture_items')->where('id', $id)->update(['code' => 'changed-key']));
        $child = (string) Str::uuid();
        DB::table('fixture_children')->insert(['id' => $child, 'item_code' => 'changed-key']);
        $report = $this->service->void($session);
        self::assertSame(0, $report['reversed']);
        self::assertStringStartsWith('row_referenced:', $report['skipped'][0]['error']);
        self::assertSame('changed-key', DB::table('fixture_children')->where('id', $child)->value('item_code'));
    }

    public function test_replacement_after_delete_is_a_skip_and_not_a_false_success(): void
    {
        $id = $this->item('original');
        $session = $this->session();
        $this->capture($session, fn () => DB::table('fixture_items')->where('id', $id)->delete());
        DB::table('fixture_items')->insert(['id' => $id, 'code' => $id, 'label' => 'replacement']);
        $report = $this->service->void($session);
        self::assertSame(0, $report['reversed']);
        self::assertStringStartsWith('row_replaced:', $report['skipped'][0]['error']);
        self::assertSame('replacement', DB::table('fixture_items')->where('id', $id)->value('label'));
    }

    public function test_legacy_after_images_are_not_guessed(): void
    {
        $session = $this->session();
        $id = $this->capture($session, fn () => $this->item('legacy'));
        DB::table('demo_session_writes')->where('demo_session_id', $session)->update(['after' => null]);
        $report = $this->service->void($session);
        self::assertSame(0, $report['reversed']);
        self::assertStringStartsWith('missing_after_image:', $report['skipped'][0]['error']);
        self::assertNull(DB::table('fixture_items')->where('id', $id)->value('deleted_at'));
    }

    public function test_newest_first_does_not_overwrite_an_interleaved_session(): void
    {
        $id = $this->item('original');
        $a = $this->session();
        $b = $this->session();
        $this->capture($a, fn () => DB::table('fixture_items')->where('id', $id)->update(['label' => 'A1']));
        $this->capture($b, fn () => DB::table('fixture_items')->where('id', $id)->update(['label' => 'B']));
        $this->capture($a, fn () => DB::table('fixture_items')->where('id', $id)->update(['label' => 'A2']));
        $report = $this->service->void($a);
        self::assertSame(1, $report['reversed']);
        self::assertCount(1, $report['skipped']);
        self::assertSame('B', DB::table('fixture_items')->where('id', $id)->value('label'));
    }

    public function test_final_audit_failure_resumes_without_repeating_committed_reversals(): void
    {
        $session = $this->session();
        $id = $this->capture($session, function (): string {
            $id = (string) Str::uuid();
            DB::table('fixture_plain')->insert(['id' => $id, 'label' => 'temporary']);

            return $id;
        });
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willThrowException(new \RuntimeException('simulated interruption'));
        try {
            (new DemoSessionService($audit))->void($session);
            self::fail('The final audit must fail in this fixture.');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated interruption', $e->getMessage());
        }
        self::assertFalse(DB::table('fixture_plain')->where('id', $id)->exists());
        self::assertSame('reversed', DB::table('demo_session_writes')->where('demo_session_id', $session)->value('resolution'));
        self::assertNull(DB::table('demo_sessions')->where('id', $session)->value('voided_at'));
        $report = $this->service->void($session);
        self::assertSame(1, $report['reversed']);
        self::assertSame([], $report['skipped']);
        $this->assertAudit($session, 1, 0);
        self::assertTrue($this->service->void($session)['already_voided']);
        $this->assertAudit($session, 1, 0);
    }

    public function test_closing_session_rejects_new_capture_and_rolls_back_the_mutation(): void
    {
        $session = $this->session();
        DB::table('demo_sessions')->where('id', $session)->update(['void_started_at' => now()]);
        try {
            $this->capture($session, fn () => $this->item('too late'));
            self::fail('Capture into a closing session must fail.');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame('55000', $e->errorInfo[0]);
        }
        self::assertSame(0, DB::table('fixture_items')->count());
        self::assertSame(0, DB::table('demo_session_writes')->count());
    }

    public function test_inflight_capture_holds_closing_until_its_transaction_commits(): void
    {
        $session = $this->session();
        $other = DB::connection('competing');
        $other->statement("SET lock_timeout = '100ms'");
        DB::beginTransaction();
        try {
            DB::statement('SELECT set_config(?, ?, true)', [DemoMode::GUC, $session]);
            $this->item('in-flight');
            try {
                $other->table('demo_sessions')->where('id', $session)->update(['void_started_at' => now()]);
                self::fail('Closing must wait for the active capture transaction.');
            } catch (\Illuminate\Database\QueryException $e) {
                self::assertSame('55P03', $e->errorInfo[0]);
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        self::assertSame(1, $this->service->void($session)['reversed']);
    }

    public function test_capture_waiting_on_closing_cannot_land_after_the_close_commits(): void
    {
        $session = $this->session();
        $other = DB::connection('competing');
        $other->statement("SET lock_timeout = '100ms'");
        $other->statement('SELECT set_config(?, ?, false)', [DemoMode::GUC, $session]);
        $id = (string) Str::uuid();
        DB::beginTransaction();
        try {
            DB::table('demo_sessions')->where('id', $session)->lockForUpdate()->first();
            try {
                $other->table('fixture_items')->insert(['id' => $id, 'code' => $id, 'label' => 'waiting']);
                self::fail('The capture trigger must lock the demo session before accepting the row.');
            } catch (\Illuminate\Database\QueryException $e) {
                self::assertSame('55P03', $e->errorInfo[0]);
            }
            DB::table('demo_sessions')->where('id', $session)->update(['void_started_at' => now()]);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        try {
            $other->table('fixture_items')->insert(['id' => $id, 'code' => $id, 'label' => 'too late']);
            self::fail('After the closing lock commits, new capture must fail closed.');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame('55000', $e->errorInfo[0]);
        }
        self::assertSame(0, DB::table('fixture_items')->count());
        self::assertSame(0, DB::table('demo_session_writes')->count());
    }

    private function session(): string
    {
        $id = (string) Str::uuid();
        DB::table('demo_sessions')->insert([
            'id' => $id, 'session_id' => $id, 'started_at' => now(), 'last_seen_at' => now(),
        ]);

        return $id;
    }

    private function item(string $label): string
    {
        $id = (string) Str::uuid();
        DB::table('fixture_items')->insert(['id' => $id, 'code' => $id, 'label' => $label]);

        return $id;
    }

    private function capture(string $session, callable $write): mixed
    {
        return DB::transaction(function () use ($session, $write): mixed {
            DB::statement('SELECT set_config(?, ?, true)', [DemoMode::GUC, $session]);

            return $write();
        });
    }

    private function assertAudit(string $session, int $reversed, int $skipped): void
    {
        $entries = DB::table('audit_log')->where('event', 'demo.session.voided')
            ->whereRaw("payload->>'demo_session_id' = ?", [$session])->get();
        self::assertCount(1, $entries);
        $payload = json_decode($entries[0]->payload, true);
        self::assertSame($reversed, $payload['reversed']);
        self::assertCount($skipped, $payload['skipped']);
        $previous = AuditService::GENESIS_PREV_HASH;
        foreach (DB::table('audit_log')->orderBy('seq')->get() as $entry) {
            self::assertSame($previous, $entry->prev_hash);
            self::assertSame(AuditService::chainHash($previous, AuditService::canonicalJson(json_decode($entry->payload, true))), $entry->hash);
            $previous = $entry->hash;
        }
    }
}
