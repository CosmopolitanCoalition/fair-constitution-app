<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\EngineResult;
use App\Http\Controllers\Organizations\BoardElectionController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Http\Presenters\StvRoundPresenter;
use App\Models\AuditEntry;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Election;
use App\Models\Organization;
use App\Models\Tabulation;
use App\Models\User;
use App\Services\Organizations\OrgSettingsService;
use App\Services\Rooms\BoardRoomAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

/** Private SQLite fixtures. No migrations or live organization actions. */
final class BoardElectionSurfaceTest extends TestCase
{
    private string $originalConnection;

    private ConstitutionalEngine $engine;

    private BoardElectionController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.board_surface_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('board_surface_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('boards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('boardable_type');
            $t->uuid('boardable_id');
            $t->string('status')->default('active');
            $t->integer('owner_seats')->default(1);
            $t->integer('worker_seats')->default(1);
            $t->boolean('composition_valid')->default(false);
            $t->uuid('chair_seat_id')->nullable();
            $t->softDeletes();
        });
        $schema->create('board_seats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('board_id');
            $t->string('seat_class');
            $t->string('status')->default('vacant');
            $t->integer('seat_no');
            $t->uuid('holder_user_id')->nullable();
            $t->uuid('term_id')->nullable();
            $t->boolean('is_chair')->default(false);
            $t->softDeletes();
        });
        $schema->create('org_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->string('kind');
            $t->string('status');
            $t->softDeletes();
        });
        $schema->create('org_workers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('employer_id');
            $t->string('employer_type');
            $t->string('status');
            $t->softDeletes();
        });
        $schema->create('elections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('board_id');
            $t->string('kind');
            $t->string('status')->default('voting_closed');
            foreach (['approval_opens_at', 'finalist_cutoff_at', 'ranked_opens_at', 'ranked_closes_at', 'certified_at'] as $column) {
                $t->timestamp($column)->nullable();
            }
            $t->timestamp('created_at')->nullable();
            $t->softDeletes();
        });
        $schema->create('election_races', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('election_id');
            $t->timestamp('created_at')->nullable();
            $t->softDeletes();
        });
        $schema->create('tabulations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('race_id');
            $t->string('kind')->default('initial');
            $t->string('status');
            $t->string('record_hash')->nullable();
            $t->timestamp('completed_at')->nullable();
        });
        $schema->create('chamber_votes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('body_id');
            $t->string('body_type');
            $t->string('vote_type');
            $t->timestamp('opened_at')->nullable();
            $t->softDeletes();
        });
        foreach (['executives', 'legislatures'] as $table) {
            $schema->create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->softDeletes();
            });
        }
        $settings = $this->createMock(OrgSettingsService::class);
        $settings->method('get')->willReturn(null);
        $this->app->instance(OrgSettingsService::class, $settings);
        $rooms = $this->createMock(BoardRoomAccess::class);
        $rooms->method('allows')->willReturn(false);
        $this->app->instance(BoardRoomAccess::class, $rooms);
        $this->engine = $this->createMock(ConstitutionalEngine::class);
        $this->controller = new BoardElectionController(
            $this->engine, $this->createMock(StvRoundPresenter::class), $this->createMock(ChamberVotePresenter::class),
        );
    }

    protected function tearDown(): void
    {
        DB::purge('board_surface_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_first_board_is_available_only_to_the_active_ordinary_organizations_agent(): void
    {
        $this->engine->expects(self::never())->method('file');
        $org = $this->organization();
        self::assertSame(['provisionBoard' => true, 'administerOwner' => false, 'administerWorker' => false], $this->page($org)['can']);
        self::assertFalse($this->page($org, $this->user(102))['can']['provisionBoard']);
        self::assertFalse($this->page($this->organization(['is_cgc' => true]))['can']['provisionBoard']);
        self::assertFalse($this->page($this->organization(['status' => Organization::STATUS_DISSOLVED]))['can']['provisionBoard']);
        self::assertFalse($this->page($this->organization(['board_id' => $this->id(999)]))['can']['provisionBoard']);
    }

    public function test_cgc_governors_have_context_links_and_worker_elections_but_no_owner_election_control(): void
    {
        $org = $this->organization([
            'is_cgc' => true, 'board_id' => $this->id(11),
            'overseen_by_executive_id' => $this->id(21), 'created_by_legislature_id' => $this->id(31),
        ]);
        $this->board(BoardSeat::CLASS_GOVERNOR);
        DB::table('executives')->insert(['id' => $this->id(21)]);
        DB::table('legislatures')->insert(['id' => $this->id(31)]);
        $props = $this->page($org);
        self::assertSame(['provisionBoard' => false, 'administerOwner' => false, 'administerWorker' => true], $props['can']);
        self::assertSame('/executives/'.$this->id(21), $props['appointmentContext']['executive_href']);
        self::assertSame('/legislatures/'.$this->id(31).'/chamber', $props['appointmentContext']['legislature_href']);
        self::assertFalse($props['appointmentContext']['canNominate']);
        self::assertSame(BoardSeat::CLASS_GOVERNOR, $props['seated']['seats'][0]['seat_class']);
        self::assertSame($props['appointmentContext'], $this->page($org, $this->user(102))['appointmentContext']);
        self::assertFalse($this->page($org, $this->user(102))['can']['administerWorker']);
    }

    public function test_missing_or_removed_cgc_oversight_is_not_rendered_as_a_working_link(): void
    {
        $org = $this->organization([
            'is_cgc' => true, 'overseen_by_executive_id' => $this->id(21),
            'created_by_legislature_id' => $this->id(31),
        ]);
        DB::table('executives')->insert(['id' => $this->id(21), 'deleted_at' => now()]);
        self::assertNull($this->page($org)['appointmentContext']['executive_href']);
        self::assertNull($this->page($org)['appointmentContext']['legislature_href']);
        self::assertNull($this->page($this->organization())['appointmentContext']);
    }

    public function test_election_controls_require_actual_vacancies_on_the_current_board(): void
    {
        $this->board(BoardSeat::CLASS_OWNER_ELECTED);
        $org = $this->organization(['board_id' => $this->id(11)]);
        self::assertSame(['provisionBoard' => false, 'administerOwner' => true, 'administerWorker' => true], $this->page($org)['can']);
        DB::table('board_seats')->update(['status' => BoardSeat::STATUS_REMOVED]);
        self::assertSame(['provisionBoard' => false, 'administerOwner' => false, 'administerWorker' => false], $this->page($org)['can']);
        DB::table('board_seats')->update(['status' => BoardSeat::STATUS_VACANT]);
        DB::table('boards')->update(['boardable_id' => $this->id(2)]);
        self::assertFalse($this->page($org)['can']['administerOwner']);
        self::assertFalse($this->page($org)['can']['administerWorker']);
    }

    public function test_provisioning_uses_the_existing_engine_with_selected_organization_and_optional_cycle(): void
    {
        $org = $this->organization();
        $actor = $this->user();
        $request = Request::create('/organizations/'.$org->id.'/board-elections', 'POST', [
            'track' => 'owner', 'action' => 'provision_board', 'owner_seats' => 7, 'cycle_months' => null,
        ]);
        $request->setUserResolver(fn () => $actor);
        $this->engine->expects(self::once())->method('file')->with('F-ORG-003', $actor, [
            'organization_id' => (string) $org->id, 'action' => 'provision_board', 'owner_seats' => 7,
        ])->willReturn(new EngineResult('F-ORG-003', new AuditEntry, ['board_id' => $this->id(11)]));
        $response = $this->controller->store($request, $org);
        self::assertTrue($response->isRedirect());
        self::assertStringContainsString('Board established', session('status'));
    }

    public function test_invalid_provisioning_input_does_not_reach_the_engine(): void
    {
        $this->engine->expects(self::never())->method('file');
        $request = Request::create('/organizations/'.$this->id(1).'/board-elections', 'POST', [
            'track' => 'owner', 'action' => 'provision_board', 'owner_seats' => -1, 'cycle_months' => 0,
        ]);
        $this->expectException(ValidationException::class);
        $this->controller->store($request, $this->organization());
    }

    public static function certificationTracks(): array
    {
        return [['owner', Election::KIND_ORG_BOARD_OWNER, 'F-ORG-003'], ['worker', Election::KIND_ORG_BOARD_WORKER, 'F-ORG-004']];
    }

    #[DataProvider('certificationTracks')]
    public function test_certification_requires_every_race_counted_and_is_independent_of_vacancy_controls(string $track, string $kind): void
    {
        $this->board(BoardSeat::CLASS_OWNER_ELECTED);
        $org = $this->organization(['board_id' => $this->id(11)]);
        DB::table('elections')->insert(['id' => $this->id(200), 'board_id' => $this->id(11), 'kind' => $kind]);
        self::assertFalse($this->page($org)[$track.'Track']['canCertify'], 'An empty election is not ready.');
        foreach ([201, 202] as $race) {
            DB::table('election_races')->insert(['id' => $this->id($race), 'election_id' => $this->id(200)]);
        }
        DB::table('tabulations')->insert([
            'id' => $this->id(301), 'race_id' => $this->id(201), 'status' => Tabulation::STATUS_COMPLETE, 'record_hash' => 'sealed',
        ]);
        self::assertFalse($this->page($org)[$track.'Track']['canCertify'], 'The first completed race is insufficient.');
        DB::table('tabulations')->insert([
            'id' => $this->id(302), 'race_id' => $this->id(202), 'status' => Tabulation::STATUS_COMPLETE, 'record_hash' => null,
        ]);
        self::assertFalse($this->page($org)[$track.'Track']['canCertify'], 'Unsealed counts are insufficient.');
        DB::table('tabulations')->where('id', $this->id(302))->update(['record_hash' => 'sealed', 'status' => Tabulation::STATUS_RUNNING]);
        self::assertFalse($this->page($org)[$track.'Track']['canCertify'], 'Running counts are insufficient.');
        DB::table('tabulations')->where('id', $this->id(302))->update(['status' => Tabulation::STATUS_COMPLETE, 'kind' => Tabulation::KIND_AUDIT_RERUN]);
        DB::table('board_seats')->update(['status' => BoardSeat::STATUS_REMOVED]);
        foreach ([Election::STATUS_VOTING_CLOSED, Election::STATUS_TABULATING] as $status) {
            DB::table('elections')->where('id', $this->id(200))->update(['status' => $status]);
            $props = $this->page($org);
            self::assertTrue($props[$track.'Track']['canCertify']);
            self::assertTrue($props[$track.'Track']['certificationReady']);
            self::assertFalse($props['can']['administer'.ucfirst($track)]);
        }
        self::assertFalse($this->page($org, $this->user(102))[$track.'Track']['canCertify']);
        self::assertFalse($this->page($this->organization(['board_id' => $this->id(11), 'status' => Organization::STATUS_DISSOLVED]))[$track.'Track']['canCertify']);
        foreach ([Election::STATUS_RANKED_OPEN, Election::STATUS_CERTIFIED, Election::STATUS_FINAL, Election::STATUS_CANCELLED] as $status) {
            DB::table('elections')->where('id', $this->id(200))->update(['status' => $status]);
            self::assertFalse($this->page($org)[$track.'Track']['canCertify']);
        }
        DB::table('elections')->where('id', $this->id(200))->update(['status' => Election::STATUS_VOTING_CLOSED, 'board_id' => $this->id(12)]);
        self::assertFalse($this->page($org)[$track.'Track']['canCertify'], 'Other boards never supply the selected track.');
    }

    #[DataProvider('certificationTracks')]
    public function test_certification_submits_the_exact_displayed_track_to_the_existing_engine(string $track, string $kind, string $form): void
    {
        $this->board(BoardSeat::CLASS_OWNER_ELECTED);
        $org = $this->organization(['board_id' => $this->id(11)]);
        $actor = $this->user();
        $request = Request::create('/organizations/'.$org->id.'/board-elections', 'POST', [
            'track' => $track, 'action' => 'certify', 'election_id' => $this->id(200),
        ]);
        $request->setUserResolver(fn () => $actor);
        $scope = $track === 'owner' ? ['organization_id' => $this->id(1)] : ['board_id' => $this->id(11)];
        $this->engine->expects(self::once())->method('file')->with($form, $actor, $scope + [
            'action' => 'certify', 'election_id' => $this->id(200),
        ])->willReturn(new EngineResult($form, new AuditEntry, ['election_id' => $this->id(200)]));
        self::assertTrue($this->controller->store($request, $org)->isRedirect());
        self::assertSame(ucfirst($track).'-seat election result certified.', session('status'));
    }

    public function test_certification_requires_a_selected_election_before_calling_the_engine(): void
    {
        $this->engine->expects(self::never())->method('file');
        $request = Request::create('/organizations/'.$this->id(1).'/board-elections', 'POST', [
            'track' => 'owner', 'action' => 'certify',
        ]);
        try {
            $this->controller->store($request, $this->organization());
            self::fail('Missing election selection must be refused.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('election_id', $e->errors());
        }
    }

    private function page(Organization $org, ?User $user = null): array
    {
        $request = Request::create('/organizations/'.$org->id.'/board-elections');
        $request->setUserResolver(fn () => $user ?? $this->user());
        $response = $this->controller->show($request, $org);

        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function organization(array $overrides = []): Organization
    {
        $org = new Organization;
        $org->setRawAttributes($overrides + [
            'id' => $this->id(1), 'name' => 'Selected organization', 'type' => 'business',
            'structure' => 'stock', 'status' => Organization::STATUS_ACTIVE, 'is_cgc' => false,
            'agent_user_id' => $this->id(101), 'board_id' => null,
            'overseen_by_executive_id' => null, 'created_by_legislature_id' => null,
        ]);

        return $org;
    }

    private function board(string $ownerClass): void
    {
        DB::table('boards')->insert([
            'id' => $this->id(11), 'boardable_type' => Board::BOARDABLE_ORGANIZATIONS, 'boardable_id' => $this->id(1),
        ]);
        foreach ([1 => $ownerClass, 2 => BoardSeat::CLASS_WORKER_ELECTED] as $n => $class) {
            DB::table('board_seats')->insert([
                'id' => $this->id(40 + $n), 'board_id' => $this->id(11), 'seat_class' => $class, 'seat_no' => $n,
            ]);
        }
    }

    private function user(int $id = 101): User
    {
        $user = new User;
        $user->setRawAttributes(['id' => $this->id($id)]);

        return $user;
    }

    private function id(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }
}
