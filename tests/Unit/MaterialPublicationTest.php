<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Engine\EngineResult;
use App\Http\Controllers\Education\MaterialController;
use App\Models\AuditEntry;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\Education\EducationCatalogService;
use App\Services\Education\TrainingGateService;
use App\Services\RoleService;
use App\Support\InstanceClass;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LE-2 — F-EDU-002 writes the education_modules row through the single owner
 * EducationCatalogService, on the real engine, over a private in-memory
 * SQLite fixture. The append-only triggers and demo capture do not exist on
 * SQLite, so rollback-on-reject is proven by asserting NO row after a thrown
 * handler, not by a trigger.
 */
final class MaterialPublicationTest extends TestCase
{
    private string $original;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config([
            'database.connections.material_publication_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false,
        ]);
        DB::setDefaultConnection('material_publication_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        InstanceClass::flush();

        $schema = DB::connection()->getSchemaBuilder();

        $schema->create('education_tracks', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('key');
            $t->string('title');
            $t->string('status')->default('live');
            $t->integer('ordering')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('education_modules', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('track_id');
            $t->string('key');
            $t->string('title');
            $t->string('surface_id')->nullable();
            $t->integer('minutes')->nullable();
            $t->string('status')->default('live');
            $t->integer('ordering')->default(0);
            $t->integer('revision_number')->default(1);
            $t->string('published_by')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('users', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name')->nullable();
            $t->string('display_name')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        // The instance_settings singleton the engine reads (mirror + class).
        foreach ([InstanceSettings::class] as $class) {
            $model = new $class;
            $schema->create($model->getTable(), function (Blueprint $t) use ($model) {
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    $column === 'id' ? $t->string('id')->primary() : $t->text($column)->nullable();
                }
            });
        }
        InstanceSettings::create(['instance_name' => 'Private material fixture', 'instance_class' => 'production']);

        DB::table('education_tracks')->insert([
            'id' => $this->id(1), 'key' => 'legislator', 'title' => 'Legislator basics', 'status' => 'live',
            'ordering' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actorId = $this->id(2);
        (new User)->forceFill(['id' => $this->actorId, 'name' => 'Agent', 'display_name' => 'Coalition Agent'])->save();
    }

    protected function tearDown(): void
    {
        InstanceClass::flush();
        DB::purge('material_publication_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string
    {
        return sprintf('82000000-0000-4000-8000-%012d', $n);
    }

    /** The real engine with a mocked audit sink and a role resolver returning $roles. */
    private function engine(array $roles): ConstitutionalEngine
    {
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn($roles);

        return new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
    }

    private function file(array $roles, array $payload): void
    {
        $this->engine($roles)->file('F-EDU-002', User::findOrFail($this->actorId), $payload);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'module_key' => 'floor', 'title' => 'Taking the floor', 'action' => 'publish',
            'track_key' => 'legislator', 'surface_id' => 'learn/lesson', 'minutes' => 5, 'status' => 'live',
        ];
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            self::fail('Expected constitutional refusal.');
        } catch (ConstitutionalViolation $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function test_r23_publish_creates_the_module_row_with_revision_one_and_stamps(): void
    {
        $this->file(['R-23'], $this->payload());
        $row = DB::table('education_modules')->where('key', 'floor')->first();
        self::assertNotNull($row);
        self::assertSame('Taking the floor', $row->title);
        self::assertSame('learn/lesson', $row->surface_id);
        self::assertSame('live', $row->status);
        self::assertSame(5, (int) $row->minutes);
        self::assertSame(1, (int) $row->revision_number);
        self::assertSame($this->actorId, $row->published_by);
        self::assertNotNull($row->published_at);
    }

    public function test_revise_increments_revision_and_keeps_the_key(): void
    {
        $this->file(['R-23'], $this->payload());
        $this->file(['R-23'], $this->payload(['action' => 'revise', 'title' => 'Taking the floor v2']));
        $rows = DB::table('education_modules')->where('key', 'floor')->get();
        self::assertCount(1, $rows);
        self::assertSame(2, (int) $rows->first()->revision_number);
        self::assertSame('Taking the floor v2', $rows->first()->title);
    }

    public function test_revise_of_an_unknown_module_is_refused(): void
    {
        $this->refused(fn () => $this->file(['R-23'], $this->payload(['module_key' => 'ghost', 'action' => 'revise'])));
        self::assertSame(0, DB::table('education_modules')->count());
    }

    public function test_a_non_r23_actor_is_refused_and_writes_nothing(): void
    {
        $this->refused(fn () => $this->file(['R-01'], $this->payload()));
        self::assertSame(0, DB::table('education_modules')->count());
    }

    public function test_answer_key_content_is_refused_and_writes_nothing(): void
    {
        $this->refused(fn () => $this->file(['R-23'], $this->payload(['correct_keys' => ['a', 'b']])));
        self::assertSame(0, DB::table('education_modules')->count());
    }

    public function test_an_unregistered_surface_is_refused(): void
    {
        $this->refused(fn () => $this->file(['R-23'], $this->payload(['surface_id' => 'not/a-surface'])));
        self::assertSame(0, DB::table('education_modules')->count());
    }

    public function test_live_arms_the_gate_and_draft_does_not(): void
    {
        $gate = new TrainingGateService;
        self::assertFalse($gate->hasLiveTraining('legislator'));

        $this->file(['R-23'], $this->payload(['module_key' => 'draft-mod', 'status' => 'draft']));
        self::assertFalse($gate->hasLiveTraining('legislator'));

        $this->file(['R-23'], $this->payload(['module_key' => 'live-mod', 'status' => 'live']));
        self::assertTrue($gate->hasLiveTraining('legislator'));
    }

    public function test_publication_awards_nothing_and_pays_nothing(): void
    {
        // No achievements or wallets table exists in this fixture; a handler
        // that awarded or paid would fail on the missing table. It filing
        // cleanly proves the publication touches neither ledger.
        $this->file(['R-23'], $this->payload());
        self::assertSame(1, DB::table('education_modules')->count());
    }

    public function test_controller_store_files_exactly_f_edu_002_through_the_engine(): void
    {
        $engine = $this->createMock(ConstitutionalEngine::class);
        $user = User::findOrFail($this->actorId);
        $payload = ['module_key' => 'floor', 'title' => 'Taking the floor', 'action' => 'publish',
            'track_key' => 'legislator', 'surface_id' => 'learn/lesson', 'minutes' => 5, 'status' => 'live', 'ip_register_entry_id' => null];
        $engine->expects(self::once())->method('file')
            ->with('F-EDU-002', $user, $payload)
            ->willReturn(new EngineResult('F-EDU-002', (new AuditEntry)->forceFill(['seq' => 1]), []));

        $request = Request::create('/learn/manage', 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        $controller = new MaterialController($engine, $this->createMock(RoleService::class));
        $redirect = $controller->store($request);
        self::assertStringEndsWith('/learn/manage', $redirect->getTargetUrl());
    }

    public function test_index_props_are_bounded_to_a_page_with_a_next_cursor(): void
    {
        $catalog = new EducationCatalogService;
        for ($i = 0; $i < 51; $i++) {
            $catalog->publishModule(sprintf('m%03d', $i), "Module {$i}", 'publish', 'legislator', 'learn/lesson', 5, 'live', $this->actorId);
        }

        $roles = $this->createMock(RoleService::class);
        $roles->method('rolesFor')->willReturn([]); // non-editor viewer

        $request = Request::create('/learn/manage');
        $request->setUserResolver(fn () => User::findOrFail($this->actorId));
        $request->headers->set('X-Inertia', 'true');

        $controller = new MaterialController($this->createMock(ConstitutionalEngine::class), $roles);
        $props = $controller->index($request)->toResponse($request)->getData(true)['props'];

        self::assertFalse($props['can']['publish']);
        self::assertCount(50, $props['modules']['rows']);
        self::assertNotNull($props['modules']['pages']['next']);
        self::assertStringContainsString('cursor=', $props['modules']['pages']['next']);
    }
}
