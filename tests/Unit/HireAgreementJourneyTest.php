<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Models\{AuditEntry, InstanceSettings, OrgContract, Organization, OrgWorker, User};
use App\Models\Economy\Currency;
use App\Services\{AchievementService, AuditService, ConstitutionalValidator, RoleService};
use App\Services\Economy\{AccountService, LaborBoardService, LedgerService};
use App\Services\Education\TrainingGateService;
use App\Services\Organizations\{CoDeterminationService, OrgMembershipService, OrgSettingsService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * S1 · organizations/economy review — the hire-agreement gap.
 *
 * WorkWorkflowTest mocks the engine at the accept boundary (:63) and
 * WorkerRepresentationTest pins the full chain only against the world
 * PostgreSQL, so no box-safe run exercises the two-consent hire on real
 * services. This journey does, on disposable SQLite: the REAL
 * ConstitutionalEngine files F-IND-019 (apply), the REAL LaborBoardService
 * carries the offer/accept, accept files the REAL F-IND-014 worker
 * registration (the worker's signature), and F-ORG-001 countersign supplies
 * the organization's signature — both signatures activate the org_contract
 * and the worker. Co-determination recompute (WorkerRepresentationTest) is
 * the suppressed queued side-effect; roles and settings are doubles.
 */
final class HireAgreementJourneyTest extends TestCase
{
    private string $original;
    private ConstitutionalEngine $engine;
    private LaborBoardService $work;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // the headcount recompute is queued; do not run the co-determination cascade
        $this->original = DB::getDefaultConnection();
        config(['database.connections.hire_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('hire_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();

        foreach ([Organization::class, User::class, Currency::class, OrgContract::class, OrgWorker::class, InstanceSettings::class] as $class) {
            $model = new $class;
            $schema->create($model->getTable(), function (Blueprint $t) use ($model) {
                foreach (array_unique(array_merge($model->getFillable(), ['id', 'created_at', 'updated_at', 'deleted_at'])) as $column) {
                    if ($column === 'id') $t->string('id')->unique();
                    else $t->text($column)->nullable();
                }
            });
        }
        $schema->create('jurisdictions', function (Blueprint $t) { $t->string('id')->primary(); $t->string('parent_id')->nullable(); $t->softDeletes(); });
        $schema->create('economic_accounts', function (Blueprint $t) { $t->string('id')->primary(); $t->string('currency_id'); $t->string('status')->nullable(); $t->softDeletes(); });
        $schema->create('economic_account_bindings', function (Blueprint $t) { $t->string('account_id'); $t->string('owner_type'); $t->string('owner_id'); });
        $schema->create('work_postings', function (Blueprint $t) {
            $t->string('id')->primary(); foreach (['organization_id', 'title', 'terms', 'status'] as $f) $t->string($f);
            $t->string('rate')->nullable(); $t->string('currency_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        $schema->create('work_applications', function (Blueprint $t) {
            $t->string('id')->primary(); foreach (['posting_id', 'applicant_account_id', 'status'] as $f) $t->string($f);
            $t->string('note')->nullable(); $t->string('org_contract_id')->nullable(); $t->string('offer_terms')->nullable();
            $t->timestamp('offered_at')->nullable(); $t->timestamps(); $t->unique(['posting_id', 'applicant_account_id']);
        });
        $schema->create('org_staff_grants', function (Blueprint $t) {
            $t->string('id')->primary(); foreach (['organization_id', 'grantee_user_id', 'task', 'status'] as $f) $t->string($f); $t->softDeletes();
        });

        InstanceSettings::create(['instance_name' => 'Hire fixture', 'instance_class' => 'production']);
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'parent_id' => null]);
        Currency::create(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1), 'code' => 'FIX']);
        Organization::create(['id' => $this->id(3), 'name' => 'Employer org', 'type' => 'business', 'structure' => 'stock',
            'status' => 'active', 'is_cgc' => false, 'agent_user_id' => $this->id(10), 'jurisdiction_id' => $this->id(1)]);
        foreach ([10, 11, 12, 99] as $u) (new User)->forceFill(['id' => $this->id($u), 'name' => 'User '.$u])->save();
        // Worker U11 and U12 hold wallets; U99 has none.
        foreach ([11 => 111, 12 => 112] as $u => $acct) {
            DB::table('economic_accounts')->insert(['id' => $this->id($acct), 'currency_id' => $this->id(2), 'status' => 'open']);
            DB::table('economic_account_bindings')->insert(['account_id' => $this->id($acct), 'owner_type' => 'users', 'owner_id' => $this->id($u)]);
        }

        // Real domain services; doubled leaf collaborators.
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $coDetermination = $this->createMock(CoDeterminationService::class);
        $memberships = new OrgMembershipService($this->createMock(RoleService::class), $coDetermination);
        $this->app->instance(OrgMembershipService::class, $memberships);
        $this->app->instance(OrgSettingsService::class, $this->createMock(OrgSettingsService::class));
        $this->app->instance(RoleService::class, $this->createMock(RoleService::class));
        $achievements = $this->createMock(AchievementService::class);
        $achievements->method('awardSelf')->willReturn(true);
        $this->app->instance(AchievementService::class, $achievements);

        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-01', 'R-23', 'R-31']);
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
        $this->app->instance(ConstitutionalEngine::class, $this->engine);
        $this->work = new LaborBoardService($this->engine);
        $this->app->instance(LaborBoardService::class, $this->work);
        $this->app->instance(AccountService::class, new AccountService(new LedgerService));
    }

    protected function tearDown(): void
    {
        DB::purge('hire_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
    private function user(int $n): User { return (new User)->forceFill(['id' => $this->id($n)]); }
    private function posting(): string
    {
        $id = $this->id(50);
        DB::table('work_postings')->insert(['id' => $id, 'organization_id' => $this->id(3), 'title' => 'Gardener',
            'terms' => 'Open posting terms', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_worker_and_organization_consents_activate_the_hire(): void
    {
        $posting = $this->posting();
        $terms = 'Standard recurring labor terms.';

        // 1. Worker applies through the REAL engine (F-IND-019).
        $applied = $this->engine->file('F-IND-019', $this->user(11), ['posting_id' => $posting]);
        $applicationId = $applied->recorded['application_id'];
        self::assertSame('applied', DB::table('work_applications')->where('id', $applicationId)->value('status'));

        // 2. Employer offers terms (employer action, not a worker consent).
        $this->work->offer($applicationId, $this->user(10), $terms);
        self::assertSame($terms, DB::table('work_applications')->where('id', $applicationId)->value('offer_terms'));

        // 3. Worker accepts on the exact terms -> files the REAL F-IND-014.
        //    This is the WORKER's signature: a worker-signed offered contract.
        $this->work->accept($applicationId, $this->user(11), $terms);
        $application = DB::table('work_applications')->where('id', $applicationId)->first();
        self::assertSame('accepted', $application->status);
        $contractId = $application->org_contract_id;
        self::assertNotNull($contractId);
        $contract = OrgContract::findOrFail($contractId);
        self::assertSame(OrgContract::STATUS_OFFERED, $contract->status);
        self::assertNotNull($contract->signed_by_counterparty_at);
        self::assertNull($contract->signed_by_org_at);
        self::assertSame(OrgWorker::STATUS_APPLIED, OrgWorker::where('contract_id', $contractId)->value('status'));
        self::assertSame('filled', DB::table('work_postings')->where('id', $posting)->value('status'));

        // 4. Organization countersigns through the REAL engine (F-ORG-001).
        //    This is the ORGANIZATION's signature: both present -> active.
        $out = $this->engine->file('F-ORG-001', $this->user(10),
            ['organization_id' => $this->id(3), 'action' => 'countersign_contract', 'contract_id' => $contractId]);
        self::assertSame(OrgContract::STATUS_ACTIVE, $out->recorded['contract_status']);
        self::assertSame(1, $out->recorded['workers_activated']);

        $contract->refresh();
        self::assertSame(OrgContract::STATUS_ACTIVE, $contract->status);
        self::assertNotNull($contract->signed_by_org_at);
        self::assertNotNull($contract->signed_by_counterparty_at);
        self::assertSame($this->id(10), $contract->signed_by_org_user_id);
        self::assertSame($this->id(11), $contract->counterparty_id);
        // The worker is now active on the correct employer.
        $worker = OrgWorker::where('contract_id', $contractId)->firstOrFail();
        self::assertSame(OrgWorker::STATUS_ACTIVE, $worker->status);
        self::assertSame($this->id(3), $worker->employer_id);
        self::assertSame($this->id(11), $worker->user_id);
        self::assertNotNull($worker->started_at);
    }

    public function test_wrong_actors_stale_and_duplicate_actions_are_refused_without_a_hire(): void
    {
        $posting = $this->posting();

        // Apply without a wallet is refused (no hire surface for a non-resident).
        $this->violation(fn () => $this->engine->file('F-IND-019', $this->user(99), ['posting_id' => $posting]));

        // A real application by the worker.
        $applicationId = $this->engine->file('F-IND-019', $this->user(11), ['posting_id' => $posting])->recorded['application_id'];

        // Duplicate application by the same worker is refused; history stands.
        $this->violation(fn () => $this->engine->file('F-IND-019', $this->user(11), ['posting_id' => $posting]));
        self::assertSame(1, DB::table('work_applications')->where('posting_id', $posting)->count());

        // Accept before any offer is refused.
        $this->runtime(fn () => $this->work->accept($applicationId, $this->user(11), null));

        $this->work->offer($applicationId, $this->user(10), 'Standard recurring labor terms.');

        // A non-applicant cannot accept (wrong actor).
        $this->forbidden(fn () => $this->work->accept($applicationId, $this->user(12), 'Standard recurring labor terms.'));
        // Acceptance on terms other than the offer is refused.
        $this->invalid(fn () => $this->work->accept($applicationId, $this->user(11), 'Different terms.'));
        // No contract was created by any refused accept.
        self::assertSame(0, OrgContract::count());
        self::assertSame('applied', DB::table('work_applications')->where('id', $applicationId)->value('status'));

        // A clean accept, then refusals around the countersign.
        $this->work->accept($applicationId, $this->user(11), 'Standard recurring labor terms.');
        $contractId = DB::table('work_applications')->where('id', $applicationId)->value('org_contract_id');

        // A non-agent cannot countersign (wrong actor).
        $this->violation(fn () => $this->engine->file('F-ORG-001', $this->user(99),
            ['organization_id' => $this->id(3), 'action' => 'countersign_contract', 'contract_id' => $contractId]));
        self::assertSame(OrgContract::STATUS_OFFERED, OrgContract::findOrFail($contractId)->status);

        // The agent countersigns once; a second (stale) countersign is refused.
        $this->engine->file('F-ORG-001', $this->user(10),
            ['organization_id' => $this->id(3), 'action' => 'countersign_contract', 'contract_id' => $contractId]);
        self::assertSame(OrgContract::STATUS_ACTIVE, OrgContract::findOrFail($contractId)->status);
        $this->violation(fn () => $this->engine->file('F-ORG-001', $this->user(10),
            ['organization_id' => $this->id(3), 'action' => 'countersign_contract', 'contract_id' => $contractId]));
        self::assertSame(1, OrgWorker::where('status', OrgWorker::STATUS_ACTIVE)->count());
    }

    private function violation(callable $call): void
    {
        try { $call(); self::fail('Expected a constitutional refusal.'); }
        catch (ConstitutionalViolation $e) { self::assertNotSame('', $e->getMessage()); }
    }
    private function forbidden(callable $call): void
    {
        try { $call(); self::fail('Expected a forbidden refusal.'); }
        catch (HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
    }
    private function invalid(callable $call): void
    {
        try { $call(); self::fail('Expected an invalid-input refusal.'); }
        catch (InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
    }
    private function runtime(callable $call): void
    {
        try { $call(); self::fail('Expected a runtime refusal.'); }
        catch (\RuntimeException $e) { self::assertNotEmpty($e->getMessage()); }
    }
}
