<?php

namespace Tests\Unit;

use App\Domain\Forms\FormRegistry;
use App\Domain\Forms\Handlers\BudgetAct;
use App\Models\ChamberVote;
use App\Models\Executive;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\ClockService;
use App\Services\Economy\BudgetService;
use App\Services\EnactmentService;
use App\Services\Executive\GrantService;
use App\Services\Legislature\ChamberActService;
use App\Services\Legislature\CommitteeService;
use App\Services\Legislature\ElectionBoardTransitionService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W-0299 part 1 — the Budget Act (F-LEG-039).
 *
 * A NAMED sqlite fixture. The audit hash chain and the vote engine use
 * Postgres-only advisory locks, so the audit-touching services are mocked:
 * EnactmentService returns an in-force act, and GrantService (under
 * BudgetService) runs on a mocked AuditService. This pins the NEW code: the
 * form is registered; a draft writes a budget and its lines; and the adoption
 * effect turns those lines into appropriations under the enacting act and
 * marks the budget enacted, which is what the treasury page then reads.
 */
final class BudgetActFormTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.budget_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('budget_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('currencies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id')->nullable();
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->integer('precision')->default(6);
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('budgets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->uuid('legislature_id')->nullable();
            $t->uuid('currency_id');
            $t->string('fiscal_label');
            $t->decimal('total', 24, 6)->default(0);
            $t->string('status')->default('draft');
            $t->uuid('enacting_act_id')->nullable();
            $t->timestamp('enacted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('budget_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('budget_id');
            $t->string('line');
            $t->decimal('amount', 24, 6);
            $t->uuid('department_id')->nullable();
            $t->uuid('appropriation_id')->nullable();
            $t->timestamps();
        });
        $schema->create('appropriations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('law_id');
            $t->uuid('jurisdiction_id');
            $t->uuid('executive_id');
            $t->string('line');
            $t->decimal('amount', 18, 2);
            $t->decimal('remaining', 18, 2);
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('executives', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->string('status')->default('delegated');
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('legislatures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('legislature_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('legislature_id');
            $t->uuid('user_id');
            $t->string('status')->default('seated');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function currencyId(): string
    {
        $id = (string) Str::uuid();
        DB::table('currencies')->insert(['id' => $id, 'name' => 'Root', 'code' => 'RTC', 'precision' => 6, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_the_budget_act_is_a_registered_form_with_a_handler(): void
    {
        self::assertSame('Budget Act', FormRegistry::meta('F-LEG-039')['name']);
        self::assertSame(BudgetAct::class, FormRegistry::handlerFor('F-LEG-039'));
        self::assertContains('R-09', FormRegistry::meta('F-LEG-039')['roles']);
    }

    public function test_a_member_drafts_a_budget_and_its_lines_through_the_form(): void
    {
        $jur   = (string) Str::uuid();
        $legId = (string) Str::uuid();
        $user  = (new User)->forceFill(['id' => (string) Str::uuid()]);
        DB::table('legislatures')->insert(['id' => $legId, 'jurisdiction_id' => $jur, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('legislature_members')->insert(['id' => (string) Str::uuid(), 'legislature_id' => $legId, 'user_id' => (string) $user->id, 'status' => 'seated', 'created_at' => now(), 'updated_at' => now()]);
        $currencyId = $this->currencyId();

        $handler = new BudgetAct(app(BudgetService::class), $this->createMock(ChamberActService::class));
        $result = $handler->handle($user, [
            'action' => 'draft', 'legislature_id' => $legId, 'currency_id' => $currencyId,
            'fiscal_label' => 'FY2027',
            'lines' => [
                ['line' => 'Schools', 'amount' => '1000.000000'],
                ['line' => 'Roads', 'amount' => '500.000000'],
            ],
        ]);

        self::assertSame('budget_drafted', $result['action']);
        $budget = DB::table('budgets')->where('id', $result['budget_id'])->first();
        self::assertNotNull($budget);
        self::assertSame('draft', $budget->status);
        self::assertSame($jur, (string) $budget->jurisdiction_id);
        self::assertSame(2, DB::table('budget_lines')->where('budget_id', $result['budget_id'])->count());
    }

    public function test_the_enact_action_moves_the_budget_to_a_chamber_vote(): void
    {
        $jur   = (string) Str::uuid();
        $legId = (string) Str::uuid();
        $user  = (new User)->forceFill(['id' => (string) Str::uuid()]);
        DB::table('legislatures')->insert(['id' => $legId, 'jurisdiction_id' => $jur, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('legislature_members')->insert(['id' => (string) Str::uuid(), 'legislature_id' => $legId, 'user_id' => (string) $user->id, 'status' => 'seated', 'created_at' => now(), 'updated_at' => now()]);
        $budgetId = (string) Str::uuid();

        $acts = $this->createMock(ChamberActService::class);
        $acts->expects($this->once())
            ->method('proposeBudgetEnactment')
            ->with($this->anything(), $this->anything(), $budgetId)
            ->willReturn(['proposal_id' => 'p-1', 'vote_id' => 'v-1']);

        $handler = new BudgetAct(app(BudgetService::class), $acts);
        $result = $handler->handle($user, ['action' => 'enact', 'legislature_id' => $legId, 'budget_id' => $budgetId]);

        self::assertSame('budget_enactment_proposed', $result['action']);
        self::assertSame('p-1', $result['proposal_id']);
        self::assertSame('v-1', $result['vote_id']);
    }

    public function test_adoption_turns_budget_lines_into_appropriations_under_the_enacting_act(): void
    {
        $jur   = (string) Str::uuid();
        $legId = (string) Str::uuid();
        DB::table('executives')->insert(['id' => (string) Str::uuid(), 'jurisdiction_id' => $jur, 'status' => 'delegated', 'created_at' => now(), 'updated_at' => now()]);
        $currencyId = $this->currencyId();

        // A drafted budget with two lines.
        $budgetId = app(BudgetService::class)->draft($jur, $currencyId, 'FY2027', [
            ['line' => 'Schools', 'amount' => '1000.000000'],
            ['line' => 'Roads', 'amount' => '500.000000'],
        ], $legId);
        self::assertSame('draft', DB::table('budgets')->where('id', $budgetId)->value('status'));

        // Bind a budget service whose appropriation writer runs on a mocked audit.
        $grants = new GrantService($this->createMock(AuditService::class), $this->createMock(PublicRecordService::class));
        $this->app->instance(BudgetService::class, new BudgetService($grants));

        // The enacting act comes from the (mocked) enactment rail on an adopted vote.
        $law = (new Law)->forceFill(['id' => (string) Str::uuid(), 'status' => Law::STATUS_IN_FORCE]);
        $enactments = $this->createMock(EnactmentService::class);
        $enactments->method('enactDirect')->willReturn($law);

        $acts = new ChamberActService(
            $this->createMock(ChamberVoteService::class),
            $enactments,
            $this->createMock(PublicRecordService::class),
            $this->createMock(CommitteeService::class),
            $this->createMock(ElectionBoardTransitionService::class),
            $this->createMock(SettingsResolver::class),
            $this->createMock(ClockService::class),
            $this->createMock(RoleService::class),
        );

        $legislature = (new Legislature)->forceFill(['id' => $legId, 'jurisdiction_id' => $jur]);
        $vote        = (new ChamberVote)->forceFill(['id' => (string) Str::uuid()]);

        $tuple = $acts->enactBudgetFromProposal($legislature, $budgetId, $vote);

        self::assertSame(['budgets', $budgetId], $tuple);

        $budget = DB::table('budgets')->where('id', $budgetId)->first();
        self::assertSame('enacted', $budget->status, 'the budget is marked enacted');
        self::assertSame((string) $law->id, (string) $budget->enacting_act_id, 'the enacting act is recorded');

        // Two appropriations, one per line, attached to the enacting act.
        self::assertSame(2, DB::table('appropriations')->where('law_id', (string) $law->id)->count());
        self::assertSame(0, DB::table('budget_lines')->where('budget_id', $budgetId)->whereNull('appropriation_id')->count(),
            'every line carries its appropriation id');
    }

    public function test_enactment_refuses_when_the_jurisdiction_has_no_executive(): void
    {
        $jur   = (string) Str::uuid();
        $legId = (string) Str::uuid();
        $currencyId = $this->currencyId();
        $budgetId = app(BudgetService::class)->draft($jur, $currencyId, 'FY2027', [
            ['line' => 'Schools', 'amount' => '1000.000000'],
        ], $legId);

        $acts = new ChamberActService(
            $this->createMock(ChamberVoteService::class),
            $this->createMock(EnactmentService::class),
            $this->createMock(PublicRecordService::class),
            $this->createMock(CommitteeService::class),
            $this->createMock(ElectionBoardTransitionService::class),
            $this->createMock(SettingsResolver::class),
            $this->createMock(ClockService::class),
            $this->createMock(RoleService::class),
        );

        $legislature = (new Legislature)->forceFill(['id' => $legId, 'jurisdiction_id' => $jur]);
        $vote        = (new ChamberVote)->forceFill(['id' => (string) Str::uuid()]);

        $this->expectException(\App\Domain\Engine\ConstitutionalViolation::class);
        $acts->enactBudgetFromProposal($legislature, $budgetId, $vote);
    }
}
