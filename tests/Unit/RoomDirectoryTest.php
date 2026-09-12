<?php

namespace Tests\Unit;

use App\Http\Controllers\Rooms\RoomDirectoryController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Tests\TestCase;

/** Explicitly guarded SQLite fixtures; no migrations or live room provisioning. */
final class RoomDirectoryTest extends TestCase
{
    private const CONNECTION = 'room_directory_fixture';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_id')->nullable();
            $t->string('name');
            $t->string('slug');
            $t->integer('adm_level');
            $t->timestamp('deleted_at')->nullable();
        });
        foreach (['legislatures', 'cases', 'committees'] as $table) {
            $schema->create($table, function (Blueprint $t) use ($table) {
                $t->uuid('id')->primary();
                $t->uuid($table === 'committees' ? 'legislature_id' : 'jurisdiction_id');
                $t->string($table === 'cases' ? 'title' : 'name')->default('Fixture');
                $t->string('status')->default('active');
                $t->timestamp('deleted_at')->nullable();
            });
        }
        $schema->create('committee_meetings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('committee_id');
            $t->timestamp('scheduled_for');
            $t->string('status')->default('scheduled');
        });
        $schema->create('board_seats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('board_id');
            $t->uuid('holder_user_id');
            $t->string('seat_class')->default('worker_elected');
            $t->string('status')->default('seated');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('boards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('boardable_id');
            $t->string('boardable_type');
            $t->string('status')->default('active');
            $t->timestamp('deleted_at')->nullable();
        });
        foreach (['organizations', 'departments'] as $table) {
            $schema->create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name');
                $t->timestamp('deleted_at')->nullable();
            });
        }
        DB::table('jurisdictions')->insert([
            ['id' => $this->id(1), 'name' => 'World', 'slug' => 'world', 'adm_level' => 0, 'parent_id' => null],
            ['id' => $this->id(2), 'name' => 'Poland', 'slug' => 'poland', 'adm_level' => 1, 'parent_id' => $this->id(1)],
            ['id' => $this->id(3), 'name' => 'Elsewhere', 'slug' => 'elsewhere', 'adm_level' => 1, 'parent_id' => $this->id(1)],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_unscoped_guest_directory_reads_no_domain_records(): void
    {
        foreach (['chambers', 'committees', 'courts', 'boards'] as $section) {
            $this->logging();
            $props = $this->page('/rooms?section='.$section);
            self::assertNull($props['selectedPlace']);
            self::assertSame([], $props['rooms']);
            self::assertSame([], $props['commons']);
            self::assertSame(['previous' => null, 'next' => null], $props['pagination']);
            self::assertSame([], DB::getQueryLog());
        }
    }

    public function test_scoped_rooms_page_forward_and_back_without_other_places_or_deleted_records(): void
    {
        foreach (['chambers' => 'legislatures', 'courts' => 'cases'] as $section => $table) {
            for ($i = 100; $i < 145; $i++) {
                DB::table($table)->insert(['id' => $this->id($i), 'jurisdiction_id' => $this->id(2)]);
            }
            DB::table($table)->insert(['id' => $this->id(90), 'jurisdiction_id' => $this->id(3)]);
            DB::table($table)->insert(['id' => $this->id(91), 'jurisdiction_id' => $this->id(2), 'deleted_at' => '2026-01-01']);
            $this->logging();
            $first = $this->page('/rooms?jurisdiction=poland&section='.$section);
            $second = $this->page($first['pagination']['next']);
            $third = $this->page($second['pagination']['next']);
            self::assertSame([20, 20, 5], [count($first['rooms']), count($second['rooms']), count($third['rooms'])]);
            self::assertSame(array_map($this->id(...), range(100, 144)), array_column([...$first['rooms'], ...$second['rooms'], ...$third['rooms']], 'id'));
            self::assertSame($first['rooms'], $this->page($second['pagination']['previous'])['rooms']);
            self::assertNull($third['pagination']['next']);
            self::assertSame(['world', 'poland'], array_column($first['jurisdictionContext']['chain'], 'slug'));
            parse_str(parse_url($first['pagination']['next'], PHP_URL_QUERY), $query);
            self::assertSame('poland', $query['jurisdiction']);
            self::assertSame($section, $query['section']);
            foreach (DB::getQueryLog() as $read) {
                if (str_contains($read['query'], 'from "'.$table.'"') && ! str_contains($read['query'], 'exists(')) {
                    self::assertContains($this->id(2), $read['bindings']);
                    self::assertStringContainsString('limit 21', $read['query']);
                    self::assertStringNotContainsString('offset', $read['query']);
                }
            }
        }
    }

    public function test_committees_resolve_only_scoped_latest_meetings_and_keep_record_fallback(): void
    {
        DB::table('legislatures')->insert([
            ['id' => $this->id(10), 'jurisdiction_id' => $this->id(2)],
            ['id' => $this->id(11), 'jurisdiction_id' => $this->id(3)],
        ]);
        DB::table('committees')->insert([
            ['id' => $this->id(20), 'legislature_id' => $this->id(10), 'name' => 'With meeting'],
            ['id' => $this->id(21), 'legislature_id' => $this->id(10), 'name' => 'No meeting'],
            ['id' => $this->id(22), 'legislature_id' => $this->id(11), 'name' => 'Other place'],
        ]);
        DB::table('committee_meetings')->insert([
            ['id' => $this->id(30), 'committee_id' => $this->id(20), 'scheduled_for' => '2026-09-01'],
            ['id' => $this->id(31), 'committee_id' => $this->id(20), 'scheduled_for' => '2026-09-12'],
            ['id' => $this->id(32), 'committee_id' => $this->id(22), 'scheduled_for' => '2026-10-01'],
        ]);
        $this->logging();
        $props = $this->page('/rooms?jurisdiction=poland&section=committees');
        self::assertSame([$this->id(20), $this->id(21)], array_column($props['rooms'], 'id'));
        self::assertSame(['/rooms/committee/'.$this->id(31), '/committees/'.$this->id(21)], array_column($props['rooms'], 'href'));
        self::assertSame(['Open room', 'Committee record'], array_column($props['rooms'], 'action'));
        self::assertSame(['/civic/commons/square?jurisdiction='.$this->id(2), '/civic/commons/halls?jurisdiction='.$this->id(2)], array_column($props['commons'], 'href'));
        foreach (DB::getQueryLog() as $read) {
            if (str_contains($read['query'], 'from "committee_meetings"')) {
                self::assertStringContainsString('"committee_id" = ?', $read['query']);
                self::assertStringContainsString('limit 1', $read['query']);
                self::assertNotContains($this->id(22), $read['bindings']);
            }
        }
    }

    public function test_private_boards_begin_with_viewers_seated_memberships_and_resolve_persisted_owner_types(): void
    {
        for ($i = 100; $i < 123; $i++) {
            $this->board($i);
        }
        $this->board(90, ['holder_user_id' => $this->id(901)]);
        $this->board(91, ['status' => 'removed']);
        $this->board(92, ['deleted_at' => '2026-01-01']);
        $this->board(93, [], ['status' => 'dissolved']);
        $this->board(94, [], ['deleted_at' => '2026-01-01']);
        $this->logging();
        $first = $this->page('/rooms?section=boards', 900);
        $second = $this->page($first['pagination']['next'], 900);
        self::assertSame(array_map($this->id(...), range(100, 122)), array_column([...$first['rooms'], ...$second['rooms']], 'id'));
        self::assertSame('Owner 100 board', $first['rooms'][0]['title']);
        self::assertSame('Owner 101 board', $first['rooms'][1]['title']);
        self::assertSame('/rooms/board/'.$this->id(1100), $first['rooms'][0]['href']);
        self::assertSame($first['rooms'], $this->page($second['pagination']['previous'], 900)['rooms']);
        self::assertNull($second['pagination']['next']);
        $reads = DB::getQueryLog();
        self::assertStringContainsString('from "board_seats"', $reads[0]['query']);
        self::assertContains($this->id(900), $reads[0]['bindings']);
        foreach ($reads as $read) {
            self::assertStringStartsWith('select ', $read['query']);
            if (str_contains($read['query'], 'from "board_seats"')) {
                self::assertStringContainsString('"holder_user_id" = ?', $read['query']);
                self::assertStringContainsString('limit 21', $read['query']);
            } else {
                self::assertStringContainsString('limit 1', $read['query']);
                self::assertStringContainsString('"id" = ?', $read['query']);
            }
        }
    }

    public function test_malformed_cursors_fail_before_any_domain_query(): void
    {
        foreach (['0', 'garbage', (new Cursor(['id' => 'not-a-uuid']))->encode(), (new Cursor(['id' => $this->id(1), 'extra' => 'value']))->encode(), str_repeat('x', 1025)] as $cursor) {
            $this->logging();
            try {
                $this->page('/rooms?'.http_build_query(['jurisdiction' => 'poland', 'cursor' => $cursor]));
                self::fail('Expected an invalid page link to be rejected.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('cursor', $e->errors());
            }
            self::assertSame([], DB::getQueryLog());
        }
    }

    private function board(int $id, array $seat = [], array $board = []): void
    {
        $type = $id % 2 === 0 ? 'organizations' : 'departments';
        DB::table($type)->insert(['id' => $this->id($id + 2000), 'name' => 'Owner '.$id]);
        DB::table('boards')->insert(array_replace(['id' => $this->id($id + 1000), 'boardable_type' => $type, 'boardable_id' => $this->id($id + 2000)], $board));
        DB::table('board_seats')->insert(array_replace(['id' => $this->id($id), 'board_id' => $this->id($id + 1000), 'holder_user_id' => $this->id(900)], $seat));
    }

    private function page(string $url, ?int $user = null): array
    {
        $request = Request::create($url);
        $request->setUserResolver(fn () => $user === null ? null : (new User)->forceFill(['id' => $this->id($user)]));
        $response = (new RoomDirectoryController)->index($request);

        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function id(int $id): string
    {
        return sprintf('60000000-0000-4000-8000-%012d', $id);
    }

    private function logging(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }
}
