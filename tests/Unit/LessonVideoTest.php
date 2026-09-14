<?php

namespace Tests\Unit;

use App\Http\Controllers\Education\LearnController;
use App\Http\Controllers\Media\VideoLibraryController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LE-3 lesson-video association, server half. All education state is an
 * explicit SQLite memory fixture; the media catalog is the real config, so the
 * resolution (per-surface film, legacy-alias fallback, demo default) and the
 * poster/real base-url switch are exercised end to end. No live world.
 */
final class LessonVideoTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.lesson_video_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('lesson_video_fixture');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('education_tracks', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('key'); $t->string('title');
            $t->string('status')->default('live'); $t->unsignedSmallInteger('ordering')->default(0); $t->softDeletes();
        });
        $schema->create('education_modules', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('track_id'); $t->string('key'); $t->string('title');
            $t->string('surface_id')->nullable(); $t->unsignedSmallInteger('minutes')->nullable();
            $t->string('status')->default('live'); $t->unsignedSmallInteger('ordering')->default(0); $t->softDeletes();
        });
        $schema->create('education_questions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('module_id'); $t->string('key'); $t->text('prompt');
            $t->text('choices')->default('[]'); $t->unsignedSmallInteger('ordering')->default(0); $t->softDeletes();
        });

        DB::table('education_tracks')->insert(['id' => $this->id(1), 'key' => 'civics', 'title' => 'Civics', 'ordering' => 1]);
        // judiciary/court is a pre-rename surface id -> alias judiciary/judiciary-home,
        // an authored per-surface film (v-judiciaries).
        DB::table('education_modules')->insert(['id' => $this->id(10), 'track_id' => $this->id(1), 'key' => 'courts', 'title' => 'Courts', 'surface_id' => 'judiciary/court', 'minutes' => 6, 'ordering' => 1]);
        // legislature/floor -> alias legislature/session-console, no own film -> demo default.
        DB::table('education_modules')->insert(['id' => $this->id(11), 'track_id' => $this->id(1), 'key' => 'floor', 'title' => 'Floor', 'surface_id' => 'legislature/floor', 'minutes' => 6, 'ordering' => 2]);
        // A surface with no registry entry and no alias -> no film.
        DB::table('education_modules')->insert(['id' => $this->id(12), 'track_id' => $this->id(1), 'key' => 'nofilm', 'title' => 'No film', 'surface_id' => 'zzz/none', 'minutes' => 6, 'ordering' => 3]);
    }

    protected function tearDown(): void
    {
        DB::purge('lesson_video_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('40000000-0000-4000-8000-%012d', $n); }

    private function props($response): array
    {
        return (new \ReflectionClass($response))->getProperty('props')->getValue($response);
    }

    private function lesson(string $module): array
    {
        $request = Request::create('/learn/civics/'.$module); // guest — no user resolver
        return $this->props((new LearnController())->lesson($request, 'civics', $module));
    }

    public function test_lesson_passes_the_per_surface_film_record(): void
    {
        config(['cga.media.base_url' => null]);
        $props = $this->lesson('courts');

        self::assertIsArray($props['video']);
        self::assertSame('v-judiciaries', $props['video']['id']);
        self::assertArrayHasKey('audio', $props['video']);
        self::assertArrayHasKey('captions', $props['video']);
        // No media host configured -> null base url -> the player's poster.
        self::assertNull($props['videoBaseUrl']);
    }

    public function test_lesson_falls_back_to_the_demo_default_film(): void
    {
        $props = $this->lesson('floor');

        self::assertIsArray($props['video']);
        self::assertSame('v-introduction-to-the-coalition1', $props['video']['id']);
    }

    public function test_lesson_passes_null_video_when_the_surface_has_no_film(): void
    {
        $props = $this->lesson('nofilm');

        self::assertNull($props['video']);
        self::assertNull($props['videoBaseUrl']);
    }

    public function test_lesson_hands_the_player_the_configured_media_host(): void
    {
        config(['cga.media.base_url' => 'https://media.example.test/']);
        $props = $this->lesson('courts');

        self::assertSame('v-judiciaries', $props['video']['id']);
        // MediaMeta::baseUrl() trims the trailing slash.
        self::assertSame('https://media.example.test', $props['videoBaseUrl']);
    }

    public function test_video_library_preselects_a_valid_v_query(): void
    {
        $props = $this->props((new VideoLibraryController())->index(Request::create('/videos?v=v-judiciaries')));
        self::assertSame('v-judiciaries', $props['preselect']);
    }

    public function test_video_library_ignores_an_unknown_v_query(): void
    {
        $unknown = $this->props((new VideoLibraryController())->index(Request::create('/videos?v=not-a-real-id')));
        self::assertNull($unknown['preselect']);

        $none = $this->props((new VideoLibraryController())->index(Request::create('/videos')));
        self::assertNull($none['preselect']);
    }
}
