<?php

namespace Tests\Integration;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Http\Controllers\Education\LearnController;
use App\Models\{InstanceSettings, Jurisdiction, User};
use App\Models\Economy\Currency;
use App\Services\{AchievementService, AuditService, ConstitutionalValidator, SettingsResolver};
use App\Services\Economy\{AccountService, IssuanceService, LedgerService};
use App\Services\Education\{GradingService, TrainingGateService, TrainingStipendService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Opt-in PostgreSQL fixture: no migrations, public-world tables, queues or network actors.
 * Real grading/controller/engine/audit/award/stipend/ledger; only roles and settings are doubles.
 * Run with WOS_LESSON_FIXTURE_DB=codex_lessons_<12 hex> pointing to a newly created empty DB.
 */
final class IsolatedLessonWorkflowTest extends TestCase
{
    private ?string $original = null;
    private User $learner;
    private LedgerService $ledger;
    private ConstitutionalEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('WOS_LESSON_FIXTURE_DB');
        if (! is_string($database) || ! preg_match('/\Acodex_lessons_[a-f0-9]{12}\z/', $database)) {
            $this->markTestSkipped('Requires an explicitly created disposable codex_lessons_<12 hex> database.');
        }
        $this->original = DB::getDefaultConnection();
        $connection = config('database.connections.pgsql');
        unset($connection['url']);
        config(['database.connections.lesson_workflow_fixture' => array_replace($connection,
            ['driver' => 'pgsql', 'database' => $database, 'search_path' => 'public']),
            'cga.education.pass_threshold_pct' => 80, 'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('lesson_workflow_fixture');
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame($database, DB::selectOne('select current_database() as name')->name);
        self::assertSame(0, (int) DB::selectOne("select count(*) as n from information_schema.tables where table_schema='public'")->n,
            'Fixture database must be empty; never reuse a world database.');
        DB::beginTransaction();
        DB::statement("SET LOCAL statement_timeout = '10s'");
        DB::statement("SET LOCAL lock_timeout = '3s'");
        $s = DB::connection()->getSchemaBuilder();
        foreach ([InstanceSettings::class, Jurisdiction::class, Currency::class, User::class] as $class) {
            $model = new $class;
            $s->create($model->getTable(), function (Blueprint $t) use ($model) {
                foreach (array_unique(array_merge($model->getFillable(), ['id', 'created_at', 'updated_at', 'deleted_at'])) as $field) {
                    if ($field === 'id') $t->uuid($field)->primary();
                    else $t->text($field)->nullable();
                }
            });
        }
        $s->create('education_tracks', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('key'); $t->string('status'); $t->softDeletes(); });
        $s->create('education_modules', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('track_id'); $t->string('key'); $t->string('status'); $t->softDeletes(); });
        $s->create('education_questions', function (Blueprint $t) { $t->uuid('module_id'); $t->string('key'); $t->text('prompt'); $t->jsonb('correct_keys'); $t->integer('weight'); $t->integer('ordering'); $t->softDeletes(); });
        $s->create('education_progress', function (Blueprint $t) { $t->uuid('user_id'); $t->uuid('module_id'); $t->string('state'); $t->integer('score_pct'); $t->timestamp('completed_at'); $t->timestamps(); $t->unique(['user_id', 'module_id']); });
        $s->create('achievements', function (Blueprint $t) { $t->uuid('user_id'); $t->string('award_key'); $t->text('title'); $t->bigInteger('audit_seq'); $t->timestamp('earned_at'); $t->timestamps(); $t->unique(['user_id', 'award_key']); });
        $s->create('audit_log', function (Blueprint $t) { $t->bigIncrements('seq'); $t->timestamp('occurred_at')->nullable(); $t->uuid('actor_user_id')->nullable(); $t->uuid('jurisdiction_id')->nullable(); $t->string('module'); $t->string('event'); $t->string('ref')->nullable(); $t->jsonb('payload'); $t->text('prev_hash'); $t->text('hash'); $t->boolean('rejected')->default(false); $t->text('blocked_reason')->nullable(); $t->timestamp('created_at')->nullable(); });
        $s->create('residency_confirmations', function (Blueprint $t) { $t->uuid('user_id'); $t->uuid('jurisdiction_id'); $t->boolean('is_active'); $t->timestamp('confirmed_at'); });
        $s->create('treasury_accounts', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('owner_id'); $t->string('owner_type'); $t->uuid('currency_id'); $t->decimal('balance', 24, 6); $t->timestamps(); $t->softDeletes(); });
        $s->create('economic_accounts', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('currency_id'); $t->decimal('balance', 24, 6); $t->timestamps(); $t->softDeletes(); });
        $s->create('economic_account_bindings', function (Blueprint $t) { $t->uuid('account_id'); $t->uuid('owner_id'); $t->string('owner_type'); });
        $s->create('ledger_entries', function (Blueprint $t) { $t->bigIncrements('seq'); $t->uuid('id'); $t->uuid('entry_group'); $t->string('account_type'); $t->uuid('account_id'); $t->uuid('currency_id'); $t->string('direction'); $t->decimal('amount', 24, 6); $t->string('kind'); $t->string('ref_type')->nullable(); $t->uuid('ref_id')->nullable(); $t->text('prev_hash'); $t->text('hash'); $t->timestamp('created_at'); });
        $s->create('issuance_events', function (Blueprint $t) { $t->uuid('id'); $t->uuid('currency_id'); $t->string('direction'); $t->decimal('amount', 24, 6); $t->text('reason'); $t->uuid('act_id')->nullable(); $t->uuid('entry_group'); $t->timestamp('created_at'); });
        $genesis = AuditService::canonicalJson(['fixture' => 'isolated lesson workflow']);
        DB::table('audit_log')->insert(['module' => 'system', 'event' => 'genesis', 'payload' => $genesis,
            'prev_hash' => AuditService::GENESIS_PREV_HASH, 'hash' => AuditService::chainHash(AuditService::GENESIS_PREV_HASH, $genesis)]);
        InstanceSettings::create(['instance_name' => 'Lesson fixture', 'instance_class' => 'production']);
        Jurisdiction::create(['id' => $this->id(1), 'name' => 'Fixture root', 'parent_id' => null]);
        Currency::create(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1), 'code' => 'FIX']);
        $this->learner = (new User)->forceFill(['id' => $this->id(3), 'name' => 'Synthetic learner']);
        DB::table('education_tracks')->insert(['id' => $this->id(4), 'key' => 'legislature', 'status' => 'live']);
        foreach ([5, 6] as $n) {
            DB::table('education_modules')->insert(['id' => $this->id($n), 'track_id' => $this->id(4), 'key' => 'lesson-'.$n, 'status' => 'live']);
            foreach (['a' => 4, 'b' => 1] as $key => $weight) DB::table('education_questions')->insert([
                'module_id' => $this->id($n), 'key' => $key, 'prompt' => 'Synthetic question',
                'correct_keys' => '["yes"]', 'weight' => $weight, 'ordering' => $weight]);
        }
        DB::table('residency_confirmations')->insert(['user_id' => $this->learner->id, 'jurisdiction_id' => $this->id(1), 'is_active' => true, 'confirmed_at' => now()]);
        DB::table('treasury_accounts')->insert(['id' => $this->id(7), 'owner_id' => $this->id(1), 'owner_type' => 'jurisdictions', 'currency_id' => $this->id(2), 'balance' => '0']);
        DB::table('economic_accounts')->insert(['id' => $this->id(8), 'currency_id' => $this->id(2), 'balance' => '0']);
        DB::table('economic_account_bindings')->insert(['account_id' => $this->id(8), 'owner_id' => $this->learner->id, 'owner_type' => 'users']);
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturn(10);
        $settings->method('resolve')->willReturn('minted');
        $roles = $this->createMock(ResolvesRoles::class);
        $roles->method('rolesFor')->willReturn(['R-01']);
        $audit = new AuditService;
        $this->ledger = new LedgerService;
        $this->app->instance(AchievementService::class, new AchievementService($audit));
        $this->app->instance(TrainingStipendService::class, new TrainingStipendService(new AccountService($this->ledger), new IssuanceService($this->ledger), $settings));
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roles, new TrainingGateService);
        $this->app->instance(ConstitutionalEngine::class, $this->engine);
        TrainingStipendService::resetBatch();
    }

    protected function tearDown(): void
    {
        if ($this->original !== null) {
            while (DB::connection('lesson_workflow_fixture')->transactionLevel() > 0) DB::connection('lesson_workflow_fixture')->rollBack();
            DB::purge('lesson_workflow_fixture');
            DB::setDefaultConnection($this->original);
        }
        TrainingStipendService::resetBatch();
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('80000000-0000-4000-8000-%012d', $n); }
    private function check(array $answers, string $module = 'lesson-5'): array
    {
        $request = Request::create('/learn/legislature/'.$module.'/check', 'POST', ['answers' => $answers]);
        $request->setUserResolver(fn () => $this->learner);
        return (new LearnController)->check($request, 'legislature', $module)->getSession()->get('quiz');
    }
    private function balance(): string { return (string) DB::table('economic_accounts')->where('id', $this->id(8))->value('balance'); }

    public function test_failed_attempt_then_pass_retake_and_second_lesson_use_real_award_and_money_rails(): void
    {
        $failed = $this->check(['a' => 'no', 'b' => 'yes']);
        self::assertFalse($failed['passed']);
        self::assertSame(20, $failed['score_pct']);
        self::assertSame(['a'], array_keys($failed['explain']));
        self::assertSame(0, DB::table('education_progress')->count());
        self::assertSame(0, DB::table('achievements')->count());
        self::assertSame(1, DB::table('audit_log')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertFalse((new TrainingGateService)->hasCompleted($this->learner, 'legislature'));

        $passed = $this->check(['a' => 'yes', 'b' => 'no']);
        self::assertTrue($passed['passed']);
        self::assertSame(80, $passed['score_pct']);
        $completion = DB::table('education_progress')->first();
        self::assertSame('completed', $completion->state);
        self::assertSame(80, $completion->score_pct);
        self::assertSame('10.000000', $this->balance());
        self::assertSame('0.000000', DB::table('treasury_accounts')->value('balance'));
        self::assertSame(1, DB::table('issuance_events')->count());
        self::assertSame(3, DB::table('ledger_entries')->count());
        self::assertSame(1, DB::table('achievements')->where('user_id', $this->learner->id)->where('award_key', 'ACH-EDU-001')->count());
        self::assertTrue((new TrainingGateService)->hasCompleted($this->learner, 'legislature'));

        $this->travel(2)->minutes();
        self::assertTrue($this->check(['a' => 'yes', 'b' => 'yes'])['passed']);
        self::assertSame($completion->completed_at, DB::table('education_progress')->value('completed_at'));
        self::assertSame(100, DB::table('education_progress')->value('score_pct'));
        self::assertTrue($this->check(['a' => 'yes', 'b' => 'yes'], 'lesson-6')['passed']);
        self::assertSame(2, DB::table('education_progress')->count());
        self::assertSame(1, DB::table('achievements')->count());
        self::assertSame('10.000000', $this->balance());
        self::assertSame(1, DB::table('issuance_events')->count());
        self::assertSame(3, DB::table('ledger_entries')->count());
        self::assertSame(3, DB::table('audit_log')->where('ref', 'F-EDU-001')->count());
        foreach (DB::table('audit_log')->where('ref', 'F-EDU-001')->get() as $entry) {
            $payload = json_decode($entry->payload, true);
            $keys = array_keys($payload);
            sort($keys);
            self::assertSame(['module_key', 'passed', 'score_pct', 'track_key'], $keys);
            self::assertSame($this->learner->id, $entry->actor_user_id);
        }
        self::assertTrue($this->ledger->verifyChain());
        $previous = AuditService::GENESIS_PREV_HASH;
        foreach (DB::table('audit_log')->orderBy('seq')->get() as $entry) {
            self::assertSame($previous, $entry->prev_hash);
            self::assertSame(AuditService::chainHash($previous, AuditService::canonicalJson(json_decode($entry->payload, true))), $entry->hash);
            $previous = $entry->hash;
        }
    }

    public function test_drafts_deleted_modules_and_empty_checks_never_pass_grading(): void
    {
        $grader = new GradingService;
        self::assertFalse($grader->grade('legislature', 'lesson-5', [])['passed']);
        self::assertFalse($grader->grade('missing', 'lesson-5', ['a' => 'yes', 'b' => 'yes'])['passed']);
        DB::table('education_modules')->where('id', $this->id(5))->update(['status' => 'draft']);
        self::assertFalse($grader->grade('legislature', 'lesson-5', ['a' => 'yes', 'b' => 'yes'])['passed']);
        DB::table('education_tracks')->update(['status' => 'draft']);
        self::assertFalse($grader->grade('legislature', 'lesson-6', ['a' => 'yes', 'b' => 'yes'])['passed']);
        DB::table('education_tracks')->update(['status' => 'live']);
        DB::table('education_modules')->where('id', $this->id(6))->update(['deleted_at' => now()]);
        self::assertFalse($grader->grade('legislature', 'lesson-6', ['a' => 'yes', 'b' => 'yes'])['passed']);
    }

    public function test_payment_failure_rolls_back_progress_award_audit_and_money_before_a_clean_retry(): void
    {
        DB::statement("ALTER TABLE economic_accounts ADD CONSTRAINT fixture_reject_credit CHECK (balance = 0)");
        try {
            $this->check(['a' => 'yes', 'b' => 'yes']);
            self::fail('Fixture payment failure must escape and roll the whole filing back.');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertStringContainsString('fixture_reject_credit', $e->getMessage());
        }
        foreach (['education_progress', 'achievements', 'issuance_events', 'ledger_entries'] as $table) self::assertSame(0, DB::table($table)->count());
        self::assertSame(1, DB::table('audit_log')->count());
        self::assertSame('0.000000', $this->balance());
        self::assertSame('0.000000', DB::table('treasury_accounts')->value('balance'));
        DB::statement('ALTER TABLE economic_accounts DROP CONSTRAINT fixture_reject_credit');
        self::assertTrue($this->check(['a' => 'yes', 'b' => 'yes'])['passed']);
        self::assertSame('10.000000', $this->balance());
        self::assertSame(1, DB::table('achievements')->count());
    }
}
