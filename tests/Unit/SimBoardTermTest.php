<?php

namespace Tests\Unit;

use App\Models\BoardSeat;
use App\Models\ClockTimer;
use App\Models\Term;
use App\Services\ClockService;
use App\Services\Demo\SimBoardService;
use App\Services\Organizations\CoDeterminationService;
use App\Services\Organizations\OrgBoardService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** Private SQLite fixtures only. No simulation command, migration, or live database. */
final class SimBoardTermTest extends TestCase
{
    private const JURISDICTION = '11111111-1111-4111-8111-111111111111';
    private const ORGANIZATION = '22222222-2222-4222-8222-222222222222';
    private const BOARD = '33333333-3333-4333-8333-333333333333';
    private const SEAT = '44444444-4444-4444-8444-444444444444';
    private const USER = '55555555-5555-4555-8555-555555555555';

    private SettingsResolver $settings;
    private ClockService $clocks;
    private RoleService $roles;
    private SimBoardService $service;
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.sim_board_term_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('sim_board_term_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();

        $schema->create('organizations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id');
            $table->string('type');
            $table->softDeletes();
        });
        $schema->create('boards', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('boardable_type');
            $table->string('boardable_id');
            $table->string('status');
            $table->boolean('composition_valid')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('board_seats', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('board_id');
            $table->string('seat_class');
            $table->integer('seat_no');
            $table->string('holder_user_id')->nullable();
            $table->string('term_id')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('terms', function (Blueprint $table) {
            $table->string('id')->primary();
            foreach (['office_kind', 'office_type', 'office_id', 'holder_user_id', 'jurisdiction_id', 'term_class', 'status'] as $column) {
                $table->string($column);
            }
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('executives', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id');
            $table->softDeletes();
        });
        $schema->create('executive_members', function (Blueprint $table) {
            $table->string('executive_id');
            $table->string('user_id');
            $table->string('status');
            $table->softDeletes();
        });
        $schema->create('users', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('email');
        });
        $schema->create('residency_confirmations', function (Blueprint $table) {
            $table->string('user_id');
            $table->string('jurisdiction_id');
            $table->boolean('is_active');
        });

        DB::table('organizations')->insert(['id' => self::ORGANIZATION, 'jurisdiction_id' => self::JURISDICTION, 'type' => 'common_good_corp']);
        DB::table('boards')->insert(['id' => self::BOARD, 'boardable_type' => 'organizations', 'boardable_id' => self::ORGANIZATION, 'status' => 'forming']);
        DB::table('board_seats')->insert(['id' => self::SEAT, 'board_id' => self::BOARD, 'seat_class' => 'governor', 'seat_no' => 1, 'status' => 'vacant']);
        DB::table('users')->insert(['id' => self::USER, 'email' => 'sim-term-fixture@demo.invalid']);
        DB::table('residency_confirmations')->insert(['user_id' => self::USER, 'jurisdiction_id' => self::JURISDICTION, 'is_active' => true]);

        $this->settings = $this->createMock(SettingsResolver::class);
        $this->clocks = $this->createMock(ClockService::class);
        $this->roles = $this->createMock(RoleService::class);
        $this->service = new SimBoardService(
            $this->createMock(OrgBoardService::class),
            $this->createMock(CoDeterminationService::class),
            $this->roles,
            $this->settings,
            $this->clocks,
        );
        $this->travelTo(now()->setDate(2026, 9, 13)->setTime(12, 30));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('sim_board_term_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_cgc_governor_terms_resolve_years_and_arm_exact_expiry_only_once(): void
    {
        $this->settings->expects(self::exactly(2))->method('resolveInt')
            ->with(self::JURISDICTION, 'civil_appointment_years', 10)->willReturn(7);
        $this->roles->expects(self::once())->method('flushUser')->with(self::USER);
        $this->clocks->expects(self::once())->method('arm')->willReturnCallback(function ($clock, $jurisdiction, $subjectType, $subjectId, $firesAt, $payload) {
            self::assertSame('CLK-09', $clock);
            self::assertSame(self::JURISDICTION, $jurisdiction);
            self::assertSame('term', $subjectType);
            self::assertSame('2033-09-13 00:00:00', $firesAt->format('Y-m-d H:i:s'));
            self::assertSame(['step' => 'civil_term_expiry', 'ends_on' => '2033-09-13'], $payload);
            self::assertSame($subjectId, BoardSeat::findOrFail(self::SEAT)->term_id);
            self::assertSame('2033-09-13', Term::findOrFail($subjectId)->ends_on->toDateString());
            return new ClockTimer;
        });

        self::assertSame(1, $this->service->seatCgcGovernors(self::JURISDICTION));
        self::assertSame(0, $this->service->seatCgcGovernors(self::JURISDICTION));
        self::assertSame(1, Term::count());
        self::assertSame(Term::CLASS_CIVIL_APPOINTMENT, Term::firstOrFail()->term_class);
    }

    public function test_org_cycle_keeps_its_own_month_duration_without_a_civil_clock(): void
    {
        $this->settings->expects(self::never())->method('resolveInt');
        $this->clocks->expects(self::never())->method('arm');
        $this->roles->expects(self::once())->method('flushUser')->with(self::USER);

        (new ReflectionMethod(SimBoardService::class, 'seatSeat'))->invoke(
            $this->service, BoardSeat::findOrFail(self::SEAT), self::USER, self::JURISDICTION, 'org_cycle', null, 18,
        );

        $term = Term::firstOrFail();
        self::assertSame('org_cycle', $term->term_class);
        self::assertSame('2028-03-13', $term->ends_on->toDateString());
    }

    public function test_failed_clock_arm_rolls_back_the_new_term_and_seat(): void
    {
        $this->settings->method('resolveInt')->willReturn(7);
        $this->clocks->expects(self::once())->method('arm')->willThrowException(new RuntimeException('Fixture clock unavailable'));
        $this->roles->expects(self::never())->method('flushUser');

        try {
            $this->service->seatCgcGovernors(self::JURISDICTION);
            self::fail('A missing expiry clock must not leave a seated civil appointment.');
        } catch (RuntimeException $error) {
            self::assertSame('Fixture clock unavailable', $error->getMessage());
        }

        self::assertSame(0, Term::count());
        $seat = BoardSeat::findOrFail(self::SEAT);
        self::assertSame('vacant', $seat->status);
        self::assertNull($seat->term_id);
        self::assertNull($seat->holder_user_id);
    }
}
