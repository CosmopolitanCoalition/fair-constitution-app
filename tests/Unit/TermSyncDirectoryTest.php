<?php

namespace Tests\Unit;

use App\Http\Controllers\System\TermSyncController;
use App\Services\SettingsResolver;
use App\Support\JurisdictionContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** All data lives on an explicitly guarded SQLite memory connection. */
final class TermSyncDirectoryTest extends TestCase
{
    private const CONNECTION = 'term_sync_fixture';
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
            $t->uuid('id')->primary(); $t->uuid('parent_id')->nullable(); $t->string('name'); $t->string('slug');
            $t->integer('adm_level'); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('legislatures', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('jurisdiction_id'); $t->integer('term_number')->default(1);
            $t->string('status')->default('active'); $t->integer('type_b_seats')->default(5);
            $t->date('term_starts_on')->nullable(); $t->date('term_ends_on')->nullable(); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('constitutional_settings', function (Blueprint $t) {
            $t->uuid('jurisdiction_id'); $t->integer('election_interval_months')->nullable();
            $t->integer('civil_appointment_years')->nullable(); $t->integer('judicial_appointment_years')->nullable();
        });
        $schema->create('terms', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('jurisdiction_id'); $t->uuid('legislature_id')->nullable();
            $t->string('office_kind')->default('legislature_seat'); $t->string('term_class')->default('lockstep');
            $t->string('status')->default('active'); $t->date('starts_on'); $t->date('ends_on');
            $t->uuid('source_election_id')->nullable(); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('clock_timers', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('clock_id')->default('CLK-01'); $t->string('subject_type')->default('legislature');
            $t->uuid('subject_id'); $t->string('state')->default('armed'); $t->timestamp('fires_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('elections', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('legislature_id'); $t->string('status')->default('nominating');
            $t->timestamp('created_at'); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('audit_log', function (Blueprint $t) {
            $t->bigInteger('seq')->primary(); $t->uuid('jurisdiction_id')->nullable(); $t->boolean('rejected')->default(true);
            $t->string('ref')->default('CLK-10'); $t->string('event')->default('term.rejected');
            $t->text('blocked_reason')->nullable(); $t->timestamp('occurred_at');
        });
        DB::table('jurisdictions')->insert([
            ['id' => $this->id(1), 'name' => 'World', 'slug' => 'world', 'adm_level' => 0, 'parent_id' => null],
            ['id' => $this->id(2), 'name' => 'Poland', 'slug' => 'poland', 'adm_level' => 1, 'parent_id' => $this->id(1)],
            ['id' => $this->id(3), 'name' => 'Elsewhere', 'slug' => 'elsewhere', 'adm_level' => 1, 'parent_id' => $this->id(1)],
        ]);
        DB::table('constitutional_settings')->insert([
            ['jurisdiction_id' => $this->id(1), 'election_interval_months' => 48, 'civil_appointment_years' => 8, 'judicial_appointment_years' => 9],
            ['jurisdiction_id' => $this->id(2), 'election_interval_months' => 36, 'civil_appointment_years' => null, 'judicial_appointment_years' => 7],
        ]);
        $this->legislature(10, 2);
        $this->legislature(11, 2);
        $this->legislature(12, 3);
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION); DB::purge(self::CONNECTION); DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_unselected_page_reads_no_world_records(): void
    {
        $this->startQueryLog();
        $props = $this->page('/system/term-sync');
        self::assertNull($props['selectedPlace']);
        self::assertNull($props['legislature']);
        foreach (['legislatureChoices', 'lockstepTerms', 'civilTerms', 'refusals'] as $key) self::assertSame([], $props[$key]['rows']);
        self::assertSame([], DB::getQueryLog());
    }

    public function test_place_tools_link_directly_to_the_same_place_and_optional_legislature(): void
    {
        $this->startQueryLog();
        foreach ([null, $this->id(10)] as $legislature) {
            $tools = JurisdictionContext::tools(['slug' => 'poland', 'id' => $this->id(2), 'legislature_id' => $legislature]);
            $term = collect($tools)->firstWhere('key', 'terms');
            self::assertSame('Term schedules', $term['label']);
            self::assertSame('link', $term['state']);
            parse_str(parse_url($term['href'], PHP_URL_QUERY), $query);
            self::assertSame('poland', $query['jurisdiction']);
            self::assertSame($legislature, $query['legislature'] ?? null);
        }
        self::assertSame([], DB::getQueryLog());
    }

    public function test_explicit_legislature_uses_its_place_settings_and_real_clock_date(): void
    {
        DB::table('clock_timers')->insert([
            ['id' => $this->id(40), 'subject_id' => $this->id(10), 'fires_at' => '2028-04-03 11:12:13+00', 'state' => 'armed'],
            ['id' => $this->id(41), 'subject_id' => $this->id(10), 'fires_at' => '2027-01-01 00:00:00+00', 'state' => 'cancelled'],
            ['id' => $this->id(42), 'subject_id' => $this->id(12), 'fires_at' => '2026-01-01 00:00:00+00', 'state' => 'armed'],
        ]);
        DB::table('elections')->insert([
            ['id' => $this->id(50), 'legislature_id' => $this->id(10), 'created_at' => '2026-01-01', 'status' => 'nominating'],
            ['id' => $this->id(51), 'legislature_id' => $this->id(12), 'created_at' => '2026-02-01', 'status' => 'nominating'],
            ['id' => $this->id(52), 'legislature_id' => $this->id(10), 'created_at' => '2026-03-01', 'status' => 'final'],
        ]);
        $props = $this->page('/system/term-sync?legislature='.$this->id(10));
        self::assertSame($this->id(2), $props['selectedPlace']['id']);
        self::assertSame(['world', 'poland'], array_column($props['jurisdictionContext']['chain'], 'slug'));
        self::assertSame(36, $props['legislature']['interval_months']);
        self::assertSame(['civil' => 8, 'judicial' => 7], $props['appointmentYears']);
        self::assertSame(['starts_on' => '2026-01-01', 'ends_on' => '2031-01-01'], $props['legislature']['term']);
        self::assertSame('2028-04-03T11:12:13+00:00', $props['legislature']['next_election']['clock_due_at']);
        self::assertSame($this->id(50), $props['legislature']['next_election']['election_id']);
        self::assertSame('/legislatures/'.$this->id(10).'/districts', $props['legislature']['maps_href']);
        self::assertSame([$this->id(11), $this->id(10)], array_column($props['legislatureChoices']['rows'], 'id'));
    }

    public function test_terms_and_appointments_are_paged_inside_the_selected_scope(): void
    {
        for ($i = 1; $i <= 63; $i++) {
            $this->term(100 + $i);
            $this->term(200 + $i, ['term_class' => 'civil_appointment', 'legislature_id' => null, 'office_kind' => 'civil_officer']);
            $this->term(300 + $i, ['jurisdiction_id' => $this->id(3), 'legislature_id' => $this->id(12)]);
        }
        $this->term(400, ['legislature_id' => $this->id(11)]);
        $this->term(401, ['status' => 'completed']);
        $this->term(402, ['deleted_at' => '2026-01-01']);
        $url = '/system/term-sync?jurisdiction=poland&legislature='.$this->id(10);
        $first = $this->page($url);
        foreach (['lockstepTerms' => 100, 'civilTerms' => 200] as $key => $base) {
            $second = $this->page($first[$key]['next']);
            $third = $this->page($second[$key]['next']);
            self::assertSame([25, 25, 13], [count($first[$key]['rows']), count($second[$key]['rows']), count($third[$key]['rows'])]);
            self::assertNull($third[$key]['next']);
            $rows = [...$first[$key]['rows'], ...$second[$key]['rows'], ...$third[$key]['rows']];
            self::assertSame(array_map($this->id(...), range($base + 63, $base + 1)), array_column($rows, 'id'));
            self::assertSame($second[$key], $this->page($third[$key]['previous'])[$key]);
            self::assertSame($first[$key], $this->page($second[$key]['previous'])[$key]);
            parse_str(parse_url($first[$key]['next'], PHP_URL_QUERY), $query);
            self::assertSame('poland', $query['jurisdiction']);
            self::assertSame($this->id(10), $query['legislature']);
        }
        self::assertSame($this->id(400), $this->page('/system/term-sync?jurisdiction=poland')['lockstepTerms']['rows'][0]['id']);
        self::assertSame($first['selectedPlace'], $this->page('/system/term-sync?jurisdiction='.$this->id(2))['selectedPlace']);
    }

    public function test_refusals_use_exact_recorded_place_before_pagination(): void
    {
        for ($i = 1; $i <= 180; $i++) {
            DB::table('audit_log')->insert(['seq' => $i, 'jurisdiction_id' => $i <= 63 ? $this->id(2) : ($i <= 120 ? $this->id(3) : null), 'occurred_at' => '2026-09-12']);
        }
        DB::table('audit_log')->insert(['seq' => 181, 'jurisdiction_id' => $this->id(2), 'rejected' => false, 'occurred_at' => '2026-09-12']);
        DB::table('audit_log')->insert(['seq' => 182, 'jurisdiction_id' => $this->id(2), 'ref' => 'CLK-02', 'occurred_at' => '2026-09-12']);
        $this->startQueryLog();
        $first = $this->page('/system/term-sync?jurisdiction=poland');
        $second = $this->page($first['refusals']['next']);
        $third = $this->page($second['refusals']['next']);
        self::assertSame(range(63, 1), array_column([...$first['refusals']['rows'], ...$second['refusals']['rows'], ...$third['refusals']['rows']], 'audit_seq'));
        self::assertNull($third['refusals']['next']);
        self::assertSame($first['refusals'], $this->page($second['refusals']['previous'])['refusals']);
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'from "audit_log"') || str_contains($query['query'], 'from "terms"')) {
                self::assertStringContainsString('"jurisdiction_id" = ?', $query['query']);
                self::assertContains($this->id(2), $query['bindings']);
                self::assertStringContainsString('limit 26', $query['query']);
                self::assertStringNotContainsString('offset', $query['query']);
                self::assertStringNotContainsString('group by', $query['query']);
            }
        }
    }

    public function test_legislature_choices_are_paged_without_reading_other_places(): void
    {
        for ($i = 100; $i <= 160; $i++) $this->legislature($i, 2);
        $first = $this->page('/system/term-sync?jurisdiction=poland');
        $second = $this->page($first['legislatureChoices']['next']);
        $third = $this->page($second['legislatureChoices']['next']);
        self::assertSame([25, 25, 13], [count($first['legislatureChoices']['rows']), count($second['legislatureChoices']['rows']), count($third['legislatureChoices']['rows'])]);
        self::assertSame(array_map($this->id(...), [...range(160, 100), 11, 10]), array_column([...$first['legislatureChoices']['rows'], ...$second['legislatureChoices']['rows'], ...$third['legislatureChoices']['rows']], 'id'));
        self::assertSame($first['legislatureChoices'], $this->page($second['legislatureChoices']['previous'])['legislatureChoices']);
    }

    public function test_conflicting_place_and_legislature_are_not_silently_replaced(): void
    {
        $this->startQueryLog();
        try {
            $this->page('/system/term-sync?jurisdiction=elsewhere&legislature='.$this->id(10));
            self::fail('Expected a conflicting scope to be rejected.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('from "terms"', $query['query']);
            self::assertStringNotContainsString('from "audit_log"', $query['query']);
        }
    }

    public function test_invalid_cursors_fail_before_place_or_record_queries(): void
    {
        foreach (['legislatures_cursor', 'terms_cursor', 'appointments_cursor', 'refusals_cursor'] as $name) {
            foreach (['garbage', (new Cursor(['id' => 'not-a-uuid']))->encode(), (new Cursor(['seq' => -1]))->encode(), str_repeat('x', 1025)] as $cursor) {
                $this->startQueryLog();
                try {
                    $this->page('/system/term-sync?'.http_build_query(['jurisdiction' => 'poland', $name => $cursor]));
                    self::fail('Expected an invalid cursor.');
                } catch (ValidationException $e) {
                    self::assertArrayHasKey($name, $e->errors());
                }
                self::assertSame([], DB::getQueryLog());
            }
        }
    }

    private function page(string $url): array
    {
        $response = (new TermSyncController(new SettingsResolver))->show(Request::create($url));

        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function legislature(int $id, int $place): void
    {
        DB::table('legislatures')->insert(['id' => $this->id($id), 'jurisdiction_id' => $this->id($place), 'term_starts_on' => '2026-01-01', 'term_ends_on' => '2031-01-01']);
    }

    private function term(int $id, array $extra = []): void
    {
        DB::table('terms')->insert(array_replace(['id' => $this->id($id), 'jurisdiction_id' => $this->id(2), 'legislature_id' => $this->id(10), 'starts_on' => '2026-01-01', 'ends_on' => '2031-01-01'], $extra));
    }

    private function id(int $id): string { return sprintf('10000000-0000-4000-8000-%012d', $id); }
    private function startQueryLog(): void { DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog(); }
}
