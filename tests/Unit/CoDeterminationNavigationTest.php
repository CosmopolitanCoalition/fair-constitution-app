<?php

namespace Tests\Unit;

use App\Http\Controllers\Organizations\CoDeterminationController;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Services\SettingsResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Tests\TestCase;

/** Read-path tests with explicitly guarded SQLite memory fixtures; no migrations. */
final class CoDeterminationNavigationTest extends TestCase
{
    private CoDeterminationController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.codet_navigation_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('codet_navigation_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('parent_id')->nullable();
            $table->integer('adm_level');
            $table->timestamp('created_at');
            $table->timestamp('deleted_at')->nullable();
        });
        foreach (['organizations', 'departments'] as $name) {
            $schema->create($name, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->string('jurisdiction_id');
                $table->string('type')->default('business');
                $table->string('structure')->default('stock');
                $table->boolean('is_cgc')->default(false);
                $table->timestamp('deleted_at')->nullable();
            });
        }
        $schema->create('boards', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('boardable_type');
            $table->string('boardable_id');
            $table->integer('owner_seats');
            $table->integer('worker_seats');
            $table->integer('worker_headcount');
            $table->boolean('composition_valid');
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('board_seats', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('board_id');
            $table->string('seat_class');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('elections', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('board_id');
            $table->string('kind');
            $table->string('status');
            $table->timestamp('created_at');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('setting_changes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id');
            $table->string('setting_key');
            $table->string('law_id')->nullable();
            $table->timestamp('applied_at');
        });
        DB::table('jurisdictions')->insert([
            ['id' => 'root', 'parent_id' => null, 'adm_level' => 0, 'created_at' => '2026-01-01'],
            ['id' => 'local', 'parent_id' => 'root', 'adm_level' => 1, 'created_at' => '2026-01-02'],
        ]);
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(static fn ($jid, $key, $default) => match ($key) {
            'worker_rep_min_employees' => $jid === 'local' ? 17 : 20,
            'worker_rep_parity_employees' => $jid === 'local' ? 117 : 220,
            default => $default,
        });
        $this->controller = new CoDeterminationController($settings);
    }

    public function test_selected_organization_loads_only_its_live_board_and_retains_local_settings(): void
    {
        $this->entity(1);
        $this->entity(2);
        $this->board(101, 1);
        $this->board(102, 2);
        $this->board(100, 1, overrides: ['status' => Board::STATUS_DISSOLVED]);
        $this->entity(1, 'departments');
        $this->board(103, 1, 'departments');

        $props = $this->show(['org' => $this->id(1)]);

        self::assertSame($this->id(1), $props['organization']['id']);
        self::assertSame($this->id(1), $props['focus']['entity']['id']);
        self::assertSame(['min' => 17, 'parity' => 117], $props['focus']['scale']['thresholds']);
        self::assertSame(3, $props['focus']['scale']['workerSeats']);
        self::assertSame(17, $props['clk13']['value']);
        self::assertSame(117, $props['clk14']['value']);
        self::assertNull($props['pagination']);
        self::assertCount(1, $props['appliesTable']);
        self::assertSame('/organizations/co-determination?org='.$this->id(1), $props['appliesTable'][0]['entity']['representation_href']);
        $this->assertFocusedBoardReads($this->id(1), $this->id(101));
    }

    public function test_boardless_cgc_keeps_its_identity_without_claiming_a_live_scale(): void
    {
        $this->entity(1, overrides: ['is_cgc' => true]);
        $this->entity(2);
        $this->board(102, 2);

        $props = $this->show(['org' => $this->id(1)]);

        self::assertTrue($props['organization']['is_cgc']);
        self::assertSame($this->id(1), $props['focus']['entity']['id']);
        self::assertNull($props['focus']['scale']);
        self::assertSame([], $props['appliesTable']);
        self::assertSame(17, $props['clk13']['value']);
        $this->assertFocusedBoardReads($this->id(1));
    }

    public function test_existing_department_links_remain_scoped_to_the_department(): void
    {
        $this->entity(3, 'departments');
        $this->board(103, 3, 'departments');

        $props = $this->show(['org' => $this->id(3)]);

        self::assertNull($props['organization']);
        self::assertSame('/departments/'.$this->id(3), $props['focus']['entity']['href']);
        self::assertSame('Executive department', $props['focus']['entity']['kind']);
        self::assertSame('appointed governors', $props['appliesTable'][0]['owner_side']['label']);
        $this->assertFocusedBoardReads($this->id(3), $this->id(103));
    }

    public function test_unselected_directory_uses_cursors_without_world_counts_or_offsets(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->entity($i);
            $this->board($i + 100, $i);
        }
        $this->entity(26);
        $this->board(126, 26, overrides: ['status' => Board::STATUS_DISSOLVED]);
        $this->entity(27);
        $this->board(127, 27, overrides: ['deleted_at' => '2026-09-12']);

        $first = $this->show();

        self::assertNull($first['focus']);
        self::assertNull($first['organization']);
        self::assertCount(20, $first['appliesTable']);
        self::assertNull($first['pagination']['previous']);
        self::assertNotNull($first['pagination']['next']);
        $this->assertPagedReads();
        parse_str(parse_url($first['pagination']['next'], PHP_URL_QUERY), $nextQuery);

        $second = $this->show($nextQuery);

        self::assertCount(5, $second['appliesTable']);
        self::assertNull($second['pagination']['next']);
        self::assertNotNull($second['pagination']['previous']);
        self::assertSame([], array_intersect(
            array_column(array_column($first['appliesTable'], 'entity'), 'href'),
            array_column(array_column($second['appliesTable'], 'entity'), 'href'),
        ));
        $this->assertPagedReads();
    }

    public function test_unknown_selection_does_not_fall_back_to_global_boards(): void
    {
        try {
            $this->show(['org' => $this->id(999)]);
            self::fail('An unknown entity must not render the board directory.');
        } catch (ModelNotFoundException) {
            self::assertCount(2, DB::getQueryLog());
            foreach (DB::getQueryLog() as $query) {
                self::assertStringNotContainsString('"boards"', $query['query']);
            }
        }
    }

    public function test_malformed_selection_is_rejected_before_database_reads(): void
    {
        try {
            $this->show(['org' => 'not-a-uuid']);
            self::fail('Malformed UUIDs must fail validation.');
        } catch (ValidationException) {
            self::assertSame([], DB::getQueryLog());
        }
    }

    private function show(array $query = []): array
    {
        $request = Request::create('/organizations/co-determination', 'GET', $query);
        $this->app->instance('request', $request);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->controller->show($request);

        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function assertFocusedBoardReads(string $entity, ?string $board = null): void
    {
        $boards = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "boards"')));
        self::assertCount(1, $boards);
        self::assertContains($entity, $boards[0]['bindings']);
        self::assertStringContainsString('"boardable_id" = ?', $boards[0]['query']);
        self::assertStringContainsString('limit 1', $boards[0]['query']);
        $seats = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "board_seats"')));
        self::assertCount($board ? 1 : 0, $seats);
        if ($board) {
            self::assertSame([$board], $seats[0]['bindings']);
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertMatchesRegularExpression('/^\s*(select|with)\b/i', $query['query']);
        }
    }

    private function assertPagedReads(): void
    {
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/\b(count\s*\(|offset\b)/i', $query['query']);
            if (str_contains($query['query'], 'from "boards"')) {
                self::assertStringContainsString('limit 21', $query['query']);
            }
        }
    }

    private function id(int $number): string
    {
        return '10000000-0000-4000-8000-'.sprintf('%012d', $number);
    }

    private function entity(int $number, string $table = 'organizations', array $overrides = []): void
    {
        DB::table($table)->insert(array_merge([
            'id' => $this->id($number), 'name' => 'Entity '.$number, 'jurisdiction_id' => 'local',
        ], $overrides));
    }

    private function board(int $number, int $entity, string $type = 'organizations', array $overrides = []): void
    {
        DB::table('boards')->insert(array_merge([
            'id' => $this->id($number), 'boardable_type' => $type, 'boardable_id' => $this->id($entity),
            'owner_seats' => 9, 'worker_seats' => 3, 'worker_headcount' => 50,
            'composition_valid' => true, 'status' => Board::STATUS_ACTIVE,
        ], $overrides));
        DB::table('board_seats')->insert([
            'id' => $this->id($number), 'board_id' => $this->id($number),
            'seat_class' => $type === 'departments' ? BoardSeat::CLASS_GOVERNOR : BoardSeat::CLASS_OWNER_ELECTED,
        ]);
    }
}
