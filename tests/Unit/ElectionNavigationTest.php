<?php

namespace Tests\Unit;

use App\Http\Controllers\Elections\ElectionController;
use App\Models\Election;
use App\Models\User;
use App\Services\ElectionLifecycleService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/** Minimal SQLite fixtures only: no migrations, engine calls, or live database traits. */
final class ElectionNavigationTest extends TestCase
{
    private const WORLD = '11111111-1111-4111-8111-111111111111';

    private const HOME = '22222222-2222-4222-8222-222222222222';

    private const VIEWED = '33333333-3333-4333-8333-333333333333';

    private ElectionController $controller;

    private SettingsResolver $settings;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.election_navigation_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('election_navigation_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('adm_level');
            $table->string('parent_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('residency_confirmations', function (Blueprint $table) {
            $table->string('user_id');
            $table->string('jurisdiction_id');
            $table->boolean('is_active');
            $table->integer('depth')->nullable();
        });
        $schema->create('elections', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id')->index();
            $table->string('kind');
            $table->string('status');
            $table->timestamp('created_at');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('election_races', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('election_id')->index();
            $table->integer('seats');
            $table->integer('finalist_count');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('clock_timers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id')->index();
            $table->string('clock_id');
            $table->string('state');
            $table->text('payload');
            $table->timestamp('fires_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        $this->place(self::WORLD, 'world', 0);
        $this->place(self::HOME, 'home', 4, self::WORLD);
        $this->place(self::VIEWED, 'viewed', 1, self::WORLD);
        $this->settings = $this->createMock(SettingsResolver::class);
        $this->controller = new ElectionController(
            $this->createMock(ElectionLifecycleService::class),
            $this->settings,
            $this->createMock(RoleService::class),
        );
    }

    public function test_guest_without_a_place_does_not_query_elections_or_clocks(): void
    {
        $this->election('unrelated-open', self::WORLD);
        $this->timer('unrelated-timer', self::WORLD);
        $this->settings->expects($this->never())->method('resolveInt');

        $response = $this->index();

        self::assertInstanceOf(Response::class, $response);
        $props = $this->props($response);
        self::assertNull($props['election']);
        self::assertNull($props['jurisdictionContext']);
        self::assertSame([], $props['others']);
        self::assertSame(['interval' => null, 'place' => null, 'clk01DueAt' => null], $props['empty']);
        self::assertSame([], DB::getQueryLog());
    }

    public function test_person_without_active_residency_does_not_fall_back_to_another_election(): void
    {
        $this->residence(self::HOME, false);
        $this->election('unrelated-open', self::WORLD);
        $this->election('inactive-home', self::HOME);
        $this->timer('unrelated-timer', self::WORLD);
        $this->settings->expects($this->never())->method('resolveInt');

        $response = $this->index('viewer');

        self::assertInstanceOf(Response::class, $response);
        self::assertNull($this->props($response)['empty']['place']);
        self::assertSame([], $this->props($response)['others']);
        self::assertCount(2, DB::getQueryLog());
        foreach (DB::getQueryLog() as $query) {
            self::assertContains('viewer', $query['bindings']);
            self::assertStringNotContainsString('clock_timers', $query['query']);
            self::assertStringContainsString('limit 1', $query['query']);
        }
    }

    public function test_explicit_place_slug_or_uuid_wins_over_residence_for_index_and_entry(): void
    {
        $this->residence(self::HOME);
        $this->election('home-open', self::HOME, ['created_at' => '2026-09-12 12:00:00']);
        $this->election('viewed-open', self::VIEWED);
        $this->settings->expects($this->never())->method('resolveInt');

        foreach (['viewed', self::VIEWED] as $place) {
            foreach ([null, 'viewer'] as $viewer) {
                $response = $this->index($viewer, $place);
                self::assertInstanceOf(RedirectResponse::class, $response);
                self::assertSame(route('elections.show', 'viewed-open'), $response->getTargetUrl());
                self::assertCount(2, DB::getQueryLog());
                $this->assertNoResidencyQuery();

                $this->startQueryLog();
                $response = $this->controller->entry($this->request($viewer, $place), 'ranked-ballot');
                self::assertInstanceOf(RedirectResponse::class, $response);
                self::assertSame(route('elections.ranked-ballot', 'viewed-open'), $response->getTargetUrl());
                self::assertCount(2, DB::getQueryLog());
                $this->assertNoResidencyQuery();
            }
        }
    }

    public function test_empty_viewed_place_retains_context_and_setting_without_borrowing_a_clock(): void
    {
        $this->residence(self::HOME);
        $this->election('home-open', self::HOME);
        $this->election('viewed-history', self::VIEWED, ['status' => Election::STATUS_FINAL]);
        $this->timer('home-timer', self::HOME);
        $this->settings->expects($this->once())->method('resolveInt')
            ->with(self::VIEWED, 'election_interval_months', 60)->willReturn(17);

        $response = $this->index('viewer', 'viewed');

        self::assertInstanceOf(Response::class, $response);
        $props = $this->props($response);
        self::assertNull($props['election']);
        self::assertSame(self::VIEWED, $props['jurisdictionContext']['current']['id']);
        self::assertSame([self::WORLD, self::VIEWED], array_column($props['jurisdictionContext']['chain'], 'id'));
        self::assertSame(['interval' => 17, 'place' => ['name' => 'Viewed', 'slug' => 'viewed'], 'clk01DueAt' => null], $props['empty']);
        self::assertSame(['viewed-history'], array_column($props['others'], 'election_id'));
        $this->assertNoResidencyQuery();
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'clock_timers')) {
                self::assertContains(self::VIEWED, $query['bindings']);
                self::assertStringContainsString('limit 1', $query['query']);
            }
        }
    }

    public function test_empty_place_uses_only_its_earliest_armed_general_schedule_timer(): void
    {
        $this->timer('foreign-earlier', self::WORLD, ['fires_at' => '2026-09-01 00:00:00']);
        $this->timer('wrong-step', self::VIEWED, ['payload' => json_encode(['step' => 'ranked_open']), 'fires_at' => '2026-09-02 00:00:00']);
        $this->timer('wrong-clock', self::VIEWED, ['clock_id' => 'CLK-18', 'fires_at' => '2026-09-03 00:00:00']);
        $this->timer('already-fired', self::VIEWED, ['state' => 'fired', 'fires_at' => '2026-09-04 00:00:00']);
        $this->timer('deleted', self::VIEWED, ['deleted_at' => '2026-09-05 00:00:00', 'fires_at' => '2026-09-05 00:00:00']);
        $this->timer('ours-first', self::VIEWED, ['fires_at' => '2026-10-01 00:00:00']);
        $this->timer('ours-later', self::VIEWED, ['fires_at' => '2026-11-01 00:00:00']);
        $this->settings->expects($this->once())->method('resolveInt')
            ->with(self::VIEWED, 'election_interval_months', 60)->willReturn(17);

        $response = $this->index(null, self::VIEWED);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('2026-10-01T00:00:00+00:00', $this->props($response)['empty']['clk01DueAt']);
        self::assertSame(17, $this->props($response)['empty']['interval']);
    }

    public function test_home_resolver_chooses_deepest_active_public_election_in_one_bounded_read(): void
    {
        $this->residence(self::WORLD, true, 4);
        $this->residence(self::HOME, true, 0);
        $this->residence(self::VIEWED, false, 0);
        $this->place('removed-place', 'removed', 9, self::WORLD, '2026-09-01 00:00:00');
        $this->residence('removed-place', true, 0);
        $this->election('world-newer', self::WORLD, ['created_at' => '2026-09-12 12:00:00']);
        $this->election('home-earlier', self::HOME, ['created_at' => '2026-09-09 00:00:00']);
        $this->election('home-public', self::HOME, ['kind' => Election::KIND_EXECUTIVE]);
        $this->election('inactive-newer', self::VIEWED, ['created_at' => '2026-09-12 12:00:00']);
        $this->election('removed-place-newer', 'removed-place');
        foreach ([Election::KIND_ORG_BOARD_OWNER, Election::KIND_ORG_BOARD_WORKER] as $kind) {
            $this->election($kind, self::HOME, ['kind' => $kind, 'created_at' => '2026-09-12 12:00:00']);
        }
        foreach ([Election::STATUS_FINAL, Election::STATUS_CANCELLED] as $status) {
            $this->election($status, self::HOME, ['status' => $status, 'created_at' => '2026-09-12 12:00:00']);
        }
        $this->election('deleted-election', self::HOME, ['deleted_at' => '2026-09-12 00:00:00', 'created_at' => '2026-09-12 12:00:00']);
        $this->settings->expects($this->never())->method('resolveInt');

        $response = $this->index('viewer');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(route('elections.show', 'home-public'), $response->getTargetUrl());
        self::assertCount(1, DB::getQueryLog());
        $query = DB::getQueryLog()[0];
        self::assertContains('viewer', $query['bindings']);
        self::assertStringContainsString('residency_confirmations', $query['query']);
        self::assertStringContainsString('limit 1', $query['query']);
    }

    public function test_home_empty_state_retains_deepest_active_residence_and_its_clock(): void
    {
        $this->residence(self::WORLD, true, 4);
        $this->residence(self::VIEWED, false, 0);
        $this->residence(self::HOME, true, 0);
        $this->timer('world-earlier', self::WORLD, ['fires_at' => '2026-09-01 00:00:00']);
        $this->timer('home-next', self::HOME);
        $this->settings->expects($this->once())->method('resolveInt')
            ->with(self::HOME, 'election_interval_months', 60)->willReturn(17);

        $response = $this->index('viewer');

        self::assertInstanceOf(Response::class, $response);
        $props = $this->props($response);
        self::assertSame(self::HOME, $props['jurisdictionContext']['current']['id']);
        self::assertSame(['name' => 'Home', 'slug' => 'home'], $props['empty']['place']);
        self::assertSame(17, $props['empty']['interval']);
        self::assertSame('2026-10-01T00:00:00+00:00', $props['empty']['clk01DueAt']);
    }

    public function test_other_elections_are_local_public_rows_with_only_their_own_active_race_totals(): void
    {
        $this->election('current', self::HOME);
        $this->election('history', self::HOME, ['status' => Election::STATUS_FINAL, 'created_at' => '2026-09-09 00:00:00']);
        $this->election('without-races', self::HOME, ['created_at' => '2026-09-08 00:00:00']);
        $this->election('foreign', self::VIEWED);
        $this->election('cancelled', self::HOME, ['status' => Election::STATUS_CANCELLED]);
        $this->election('deleted', self::HOME, ['deleted_at' => '2026-09-12 00:00:00']);
        foreach ([Election::KIND_ORG_BOARD_OWNER, Election::KIND_ORG_BOARD_WORKER] as $kind) {
            $this->election($kind, self::HOME, ['kind' => $kind]);
        }
        $this->race('a', 'history', 5, 13);
        $this->race('b', 'history', 3, 10);
        $this->race('deleted-race', 'history', 100, 1000, '2026-09-12 00:00:00');
        $this->race('foreign-race', 'foreign', 1000, 10000);
        $this->race('current-race', 'current', 500, 1500);
        $current = (new Election)->forceFill(['id' => 'current', 'jurisdiction_id' => self::HOME]);
        $this->startQueryLog();

        $others = $this->otherElections($current);

        self::assertSame(['history', 'without-races'], array_column($others, 'election_id'));
        self::assertSame(['Home', 'Home'], array_column($others, 'jurisdiction_name'));
        self::assertSame([8, 0], array_column($others, 'seats'));
        self::assertSame([23, 0], array_column($others, 'finalist_count'));
        self::assertCount(2, DB::getQueryLog());
        $query = DB::getQueryLog()[0];
        self::assertContains(self::HOME, $query['bindings']);
        self::assertStringContainsString('limit 10', $query['query']);
        self::assertStringContainsString('sum(', strtolower($query['query']));
        self::assertStringContainsString('"elections"."id" = "election_races"."election_id"', $query['query']);
    }

    public function test_other_elections_require_a_place_and_return_at_most_ten_records(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->election('history-'.$i, self::HOME, ['created_at' => sprintf('2026-09-%02d 00:00:00', $i)]);
        }
        $this->startQueryLog();
        self::assertSame([], $this->otherElections(null));
        self::assertSame([], DB::getQueryLog());

        $others = $this->otherElections(null, self::HOME);

        self::assertCount(10, $others);
        self::assertSame('history-12', $others[0]['election_id']);
        self::assertSame('history-3', $others[9]['election_id']);
    }

    private function index(?string $viewer = null, ?string $place = null): Response|RedirectResponse
    {
        $this->startQueryLog();

        return $this->controller->index($this->request($viewer, $place));
    }

    private function request(?string $viewer, ?string $place): Request
    {
        $request = Request::create('/elections', 'GET', $place === null ? [] : ['jurisdiction' => $place]);
        $request->setUserResolver(fn () => $viewer === null ? null : (new User)->forceFill(['id' => $viewer]));

        return $request;
    }

    private function props(Response $response): array
    {
        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function otherElections(?Election $current, ?string $jurisdiction = null): array
    {
        return (new ReflectionMethod($this->controller, 'otherElections'))->invoke($this->controller, $current, $jurisdiction);
    }

    private function startQueryLog(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }

    private function assertNoResidencyQuery(): void
    {
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('residency_confirmations', $query['query']);
        }
    }

    private function place(string $id, string $slug, int $level, ?string $parent = null, ?string $deletedAt = null): void
    {
        DB::table('jurisdictions')->insert([
            'id' => $id, 'name' => ucfirst($slug), 'slug' => $slug,
            'adm_level' => $level, 'parent_id' => $parent, 'deleted_at' => $deletedAt,
        ]);
    }

    private function residence(string $jurisdiction, bool $active = true, ?int $depth = 0): void
    {
        DB::table('residency_confirmations')->insert([
            'user_id' => 'viewer', 'jurisdiction_id' => $jurisdiction, 'is_active' => $active, 'depth' => $depth,
        ]);
    }

    private function election(string $id, string $jurisdiction, array $overrides = []): void
    {
        DB::table('elections')->insert(array_merge([
            'id' => $id, 'jurisdiction_id' => $jurisdiction, 'kind' => Election::KIND_GENERAL,
            'status' => Election::STATUS_APPROVAL_OPEN, 'created_at' => '2026-09-10 00:00:00',
        ], $overrides));
    }

    private function timer(string $id, string $jurisdiction, array $overrides = []): void
    {
        DB::table('clock_timers')->insert(array_merge([
            'id' => $id, 'jurisdiction_id' => $jurisdiction, 'clock_id' => 'CLK-01',
            'state' => 'armed', 'payload' => json_encode(['step' => 'schedule_general']), 'fires_at' => '2026-10-01 00:00:00',
        ], $overrides));
    }

    private function race(string $id, string $election, int $seats, int $finalists, ?string $deletedAt = null): void
    {
        DB::table('election_races')->insert([
            'id' => $id, 'election_id' => $election, 'seats' => $seats,
            'finalist_count' => $finalists, 'deleted_at' => $deletedAt,
        ]);
    }
}
