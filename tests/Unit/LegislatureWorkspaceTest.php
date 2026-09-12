<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Legislature\SpeakerController;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Support\LegislatureWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/** Only isolated SQLite fixtures; no engine calls or simulated-world access. */
final class LegislatureWorkspaceTest extends TestCase
{
    private const LEGISLATURE = '11111111-1111-4111-8111-111111111111';
    private const PLACE = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.legislature_workspace_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('legislature_workspace_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('legislature_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('legislature_id')->index();
            $table->integer('session_no');
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('audit_log', function (Blueprint $table) {
            $table->integer('seq')->primary();
            $table->string('module');
            $table->string('event');
            $table->boolean('rejected');
            $table->text('payload');
            $table->timestamp('occurred_at');
        });
    }

    public function test_navigation_keeps_the_selected_legislature_and_existing_speaker_read_boundary(): void
    {
        $legislature = $this->legislature();
        $workspace = LegislatureWorkspace::for($legislature, $legislature->jurisdiction, true);
        foreach (['overview', 'chamber', 'session', 'speaker', 'maps', 'bills', 'committees', 'oversight', 'referendums', 'settings', 'rooms'] as $key) {
            $route = Route::getRoutes()->match(Request::create($workspace[$key]));
            self::assertContains('GET', $route->methods(), $key);
            if (! in_array($key, ['overview', 'rooms'], true)) {
                self::assertStringContainsString(self::LEGISLATURE, $workspace[$key]);
            }
        }
        self::assertSame('/legislatures/viewed-place', $workspace['overview']);
        self::assertSame('/civic/commons/halls?jurisdiction='.self::PLACE, $workspace['rooms']);
        self::assertNull(LegislatureWorkspace::for($legislature, $legislature->jurisdiction, false)['speaker']);

        DB::enableQueryLog();
        $request = Request::create('/legislatures/'.self::LEGISLATURE.'/speaker');
        $request->setUserResolver(fn () => null);
        $response = $this->controller()->show($request, $legislature);
        self::assertSame(url('/legislatures/'.self::LEGISLATURE.'/chamber'), $response->getTargetUrl());
        self::assertSame([], DB::getQueryLog(), 'Guest speaker redirects must not read private office records.');
    }

    public function test_priority_history_is_scoped_before_paging_and_keeps_older_records_reachable(): void
    {
        DB::table('legislature_sessions')->insert([
            ['id' => 'selected-session', 'legislature_id' => self::LEGISLATURE, 'session_no' => 7, 'status' => 'open'],
            ['id' => 'foreign-session', 'legislature_id' => 'another-legislature', 'session_no' => 9, 'status' => 'open'],
        ]);
        for ($i = 1; $i <= 200; $i++) {
            DB::table('audit_log')->insert([
                'seq' => $i, 'module' => 'legislature', 'event' => 'session.member_priority', 'rejected' => false,
                'payload' => json_encode(['session_id' => $i <= 80 ? 'selected-session' : 'foreign-session', 'text' => 'Priority '.$i]),
                'occurred_at' => '2026-09-12 12:00:00',
            ]);
        }
        $seen = [];
        foreach ([1 => 50, 2 => 30] as $page => $count) {
            Paginator::currentPageResolver(fn () => $page);
            Paginator::currentPathResolver(fn () => '/legislatures/'.self::LEGISLATURE.'/speaker');
            $record = (new ReflectionMethod(SpeakerController::class, 'priorities'))
                ->invoke($this->controller(), $this->legislature(), collect());
            self::assertCount($count, $record['rows']);
            self::assertSame($page === 1, $record['pages']['newer'] === null);
            self::assertSame($page === 2, $record['pages']['older'] === null);
            foreach ($record['rows'] as $row) self::assertSame(7, $row['session_no']);
            $seen = array_merge($seen, array_column($record['rows'], 'id'));
        }
        self::assertSame(range(80, 1), $seen);
    }

    public function test_a_legislature_without_sessions_cannot_borrow_other_priority_filings(): void
    {
        $record = (new ReflectionMethod(SpeakerController::class, 'priorities'))
            ->invoke($this->controller(), $this->legislature(), collect());
        self::assertSame([], $record['rows']);
        self::assertSame(['newer' => null, 'older' => null], $record['pages']);
    }

    private function controller(): SpeakerController
    {
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->never())->method('file');

        return new SpeakerController($engine);
    }

    private function legislature(): Legislature
    {
        return (new Legislature)->forceFill(['id' => self::LEGISLATURE, 'speaker_id' => null])
            ->setRelation('jurisdiction', (new Jurisdiction)->forceFill([
                'id' => self::PLACE, 'slug' => 'viewed-place', 'name' => 'Viewed place',
            ]));
    }
}
