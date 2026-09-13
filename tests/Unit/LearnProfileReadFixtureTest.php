<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Education\LearnController;
use App\Http\Controllers\Social\PersonProfileController;
use App\Http\Presenters\CandidacyPanel;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\AuditService;
use App\Services\JourneyService;
use App\Services\OfficesHeldResolver;
use App\Services\Social\PrivateRoomService;
use App\Support\SurfaceMeta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Read-path acceptance only. No live-world helpers, migrations, queues or actor actions. */
final class LearnProfileReadFixtureTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.learn_read_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('learn_read_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('education_tracks', function (Blueprint $t) { $t->string('id')->primary(); $t->string('key'); $t->string('title'); $t->string('status'); $t->integer('ordering'); $t->softDeletes(); });
        $schema->create('education_modules', function (Blueprint $t) { $t->string('id')->primary(); $t->string('track_id'); $t->string('key'); $t->string('title'); $t->string('surface_id'); $t->string('status'); $t->integer('ordering'); $t->integer('minutes'); $t->softDeletes(); });
        $schema->create('education_questions', function (Blueprint $t) { $t->string('module_id'); $t->string('key'); $t->string('prompt'); $t->text('choices'); $t->text('correct_keys'); $t->integer('ordering'); $t->softDeletes(); });
        $schema->create('education_progress', function (Blueprint $t) { $t->string('module_id'); $t->string('user_id'); $t->string('state'); });
        DB::table('education_tracks')->insert([
            ['id' => 'track-live', 'key' => 'chamber', 'title' => 'Chamber lessons', 'status' => 'live', 'ordering' => 1],
            ['id' => 'track-draft', 'key' => 'unpublished', 'title' => 'Not published', 'status' => 'draft', 'ordering' => 2],
        ]);
        DB::table('education_modules')->insert([
            ['id' => 'lesson-live', 'track_id' => 'track-live', 'key' => 'basics', 'title' => 'Chamber basics', 'surface_id' => 'legislature/chamber', 'status' => 'live', 'ordering' => 1, 'minutes' => 4],
            ['id' => 'lesson-draft', 'track_id' => 'track-live', 'key' => 'draft', 'title' => 'Draft module', 'surface_id' => 'legislature/chamber', 'status' => 'draft', 'ordering' => 2, 'minutes' => 5],
        ]);
        DB::table('education_questions')->insert(['module_id' => 'lesson-live', 'key' => 'q1', 'prompt' => 'What happens next?', 'choices' => '{"a":"First choice","b":"Second choice"}', 'correct_keys' => '["secret-server-answer"]', 'ordering' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('learn_read_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('40000000-0000-4000-8000-%012d', $n); }
    private function props($response): array { return (new \ReflectionClass($response))->getProperty('props')->getValue($response); }
    private function request(string $path, ?User $viewer = null): Request
    {
        $request = Request::create($path);
        $request->setUserResolver(fn () => $viewer);
        return $request;
    }

    public function test_guests_can_read_published_lessons_without_answer_keys_or_role_checks(): void
    {
        $controller = new LearnController();
        $home = $this->props($controller->home($this->request('/learn')));
        self::assertSame(['chamber'], array_column($home['tracks'], 'key'));
        self::assertSame(['basics'], array_column($home['tracks'][0]['modules'], 'key'));
        self::assertSame([], $home['recommended']);
        $lesson = $this->props($controller->lesson($this->request('/learn/chamber/basics'), 'chamber', 'basics'));
        self::assertSame('learn/lesson', $lesson['surface']['id']);
        self::assertSame('legislature/chamber', $lesson['module']['surface_id']);
        self::assertFalse($lesson['module']['completed']);
        self::assertSame(['key', 'prompt', 'choices'], array_keys($lesson['questions'][0]));
        self::assertSame('First choice', $lesson['questions'][0]['choices']['a']);
        self::assertStringNotContainsString('secret-server-answer', json_encode($lesson));
        self::assertStringEndsWith('/learn', $controller->lesson($this->request('/learn/unpublished'), 'unpublished')->getTargetUrl());
        self::assertStringEndsWith('/learn', $controller->lesson($this->request('/learn/chamber/draft'), 'chamber', 'draft')->getTargetUrl());
    }

    public function test_lesson_completion_is_read_for_the_current_learner_only(): void
    {
        $learner = (new User())->forceFill(['id' => $this->id(1)]);
        $other = (new User())->forceFill(['id' => $this->id(2)]);
        DB::table('education_progress')->insert(['module_id' => 'lesson-live', 'user_id' => $learner->id, 'state' => 'completed']);
        $controller = new LearnController();
        $mine = $this->props($controller->lesson($this->request('/learn/chamber/basics', $learner), 'chamber', 'basics'));
        $theirs = $this->props($controller->lesson($this->request('/learn/chamber/basics', $other), 'chamber', 'basics'));
        self::assertTrue($mine['module']['completed']);
        self::assertTrue($mine['modules'][0]['completed']);
        self::assertFalse($theirs['module']['completed']);
        self::assertFalse($theirs['modules'][0]['completed']);
    }

    public function test_profile_reads_real_earned_awards_and_respects_the_subjects_visibility(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('display_name'); $t->string('name'); $t->string('email'); $t->softDeletes(); });
        $schema->create('social_profiles', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('user_id'); $t->string('visibility'); $t->string('handle'); $t->text('bio'); $t->softDeletes(); });
        $schema->create('candidacies', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('user_id'); $t->timestamps(); $t->softDeletes(); });
        $schema->create('social_follows', function (Blueprint $t) { $t->uuid('id'); $t->uuid('follower_user_id'); $t->string('target_type'); $t->uuid('target_id'); $t->softDeletes(); });
        $schema->create('jurisdictions', function (Blueprint $t) { $t->uuid('id'); $t->string('name'); $t->integer('adm_level'); $t->softDeletes(); });
        $schema->create('residency_confirmations', function (Blueprint $t) { $t->uuid('user_id'); $t->uuid('jurisdiction_id'); $t->boolean('is_active'); });
        $schema->create('audit_log', function (Blueprint $t) { $t->integer('seq'); $t->uuid('actor_user_id'); $t->boolean('rejected'); $t->string('module'); $t->string('event'); });
        $schema->create('endorsements', function (Blueprint $t) { $t->uuid('candidate_id'); $t->boolean('is_active'); $t->boolean('is_public'); $t->string('endorser_type'); $t->uuid('endorser_id'); $t->timestamp('withdrawn_at')->nullable(); $t->timestamp('endorsed_at'); $t->softDeletes(); });
        $schema->create('achievements', function (Blueprint $t) { $t->uuid('id'); $t->uuid('user_id'); $t->string('award_key'); $t->string('title'); $t->timestamp('earned_at'); });
        DB::table('users')->insert(['id' => $this->id(1), 'display_name' => 'River', 'name' => 'Private legal name', 'email' => 'private@example.invalid']);
        DB::table('social_profiles')->insert(['id' => $this->id(10), 'user_id' => $this->id(1), 'visibility' => 'public', 'handle' => 'river', 'bio' => 'Learning together']);
        DB::table('achievements')->insert([
            ['id' => $this->id(11), 'user_id' => $this->id(1), 'award_key' => 'journey:first-steps', 'title' => 'First steps', 'earned_at' => '2026-09-01 12:00:00'],
            ['id' => $this->id(12), 'user_id' => $this->id(1), 'award_key' => 'training:chamber', 'title' => 'Chamber training', 'earned_at' => '2026-09-02 12:00:00'],
            ['id' => $this->id(13), 'user_id' => $this->id(2), 'award_key' => 'other-person-award', 'title' => 'Someone else', 'earned_at' => '2026-09-03 12:00:00'],
        ]);
        // Offices/candidacy actions are outside this read fixture. The real
        // profile controller and real achievement-ledger read still execute.
        $offices = $this->createMock(OfficesHeldResolver::class);
        $offices->method('forUser')->willReturn([]);
        app()->instance(OfficesHeldResolver::class, $offices);
        $controller = new PersonProfileController(
            $this->createMock(CandidacyPanel::class),
            new JourneyService($this->createMock(AuditService::class), $this->createMock(AchievementService::class)),
            $this->createMock(ConstitutionalEngine::class), $this->createMock(PrivateRoomService::class),
        );
        $path = '/people?who='.$this->id(1).'&tab=achievements';
        $public = $this->props($controller->show($this->request($path)));
        self::assertSame('achievements', $public['tab']);
        self::assertSame(['training:chamber', 'journey:first-steps'], array_column($public['achievements'], 'award_key'));
        self::assertSame('River', $public['person']['display']);
        self::assertStringNotContainsString('private@example.invalid', json_encode($public));
        self::assertStringNotContainsString('Private legal name', json_encode($public));
        self::assertStringNotContainsString('other-person-award', json_encode($public));
        DB::table('social_profiles')->where('user_id', $this->id(1))->update(['visibility' => 'private']);
        $private = $this->props($controller->show($this->request($path)));
        self::assertNull($private['achievements']);
        self::assertNotContains('achievements', $private['tabs']);
        self::assertSame('overview', $private['tab']);
        $self = $this->props($controller->show($this->request($path, User::findOrFail($this->id(1)))));
        self::assertCount(2, $self['achievements']);
        self::assertSame('achievements', $self['tab']);
    }

    public function test_new_surface_metadata_matches_the_generated_learn_registry(): void
    {
        $copy = json_decode(file_get_contents(resource_path('js/i18n/locales/en/c_education.json')), true);
        foreach (['economy/work', 'economy/help', 'economy/help-detail', 'rooms/directory', 'learn/video-library'] as $id) {
            $meta = SurfaceMeta::for($id);
            self::assertSame($id, $meta['id']);
            self::assertNotEmpty($meta['title']);
            self::assertNotEmpty($copy['education_'.str_replace(['/', '-'], '_', $id).'.learn']);
        }
    }
}
