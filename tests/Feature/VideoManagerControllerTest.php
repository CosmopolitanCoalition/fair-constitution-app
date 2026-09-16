<?php

namespace Tests\Feature;

use App\Http\Controllers\Media\VideoManagerController;
use App\Models\MediaSurfaceVideo;
use App\Models\MediaVideo;
use App\Models\MediaVideoTrack;
use App\Support\MediaMeta;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * W-0449 — the video manager controller. Video publication is an OPERATOR tool,
 * temporary during development (operator ruling 2026-09-16): store and assign
 * gate on is_operator ONLY. Runs on the phpunit sqlite :memory: connection
 * (phpunit.xml). The media tables are built in setUp by calling the migration's
 * own up() (which also proves it is sqlite-safe); the library root is a temp
 * dir, so real file writes never touch the operator's E:\Subjects. audit_log is
 * Postgres-only and absent here, so the controller's guarded audit is skipped.
 * No RefreshDatabase (forbidden: the dev Postgres holds ~951k jurisdictions).
 */
class VideoManagerControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Build only the media tables this test reads. require re-executes the
        // migration file and returns a fresh anonymous-class instance.
        (require base_path('database/migrations/2026_09_16_180000_media_library_tables.php'))->up();

        $this->root = sys_get_temp_dir().'/video-manager-'.bin2hex(random_bytes(4));
        @mkdir($this->root, 0775, true);
        config(['cga.media_local.local_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    public function test_a_non_operator_is_403_on_store_and_assign(): void
    {
        $c = app(VideoManagerController::class);

        $this->assert403(fn () => $c->store($this->req(false)));
        $this->assert403(fn () => $c->assign($this->req(false)));
    }

    public function test_a_valid_upload_lands_the_three_files_and_creates_the_rows(): void
    {
        $c = app(VideoManagerController::class);

        $request = $this->req(true, [
            'title'   => 'Test Film',
            'subject' => 'Test Film',
            'summary' => 'A short test film.',
            'surfaces' => ['learn/lesson'],
        ]);
        $request->files->set('master', UploadedFile::fake()->create('anything.mp4', 20, 'video/mp4'));
        // No explicit language: it is parsed from the filename suffix.
        $request->files->set('audio', [UploadedFile::fake()->create('src-French.m4a', 5, 'audio/mp4')]);
        $request->files->set('captions', [UploadedFile::fake()->create('src-Polish.vtt', 1, 'text/vtt')]);

        $response = $c->store($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);

        // Canonical on-disk names under the library root.
        $this->assertFileExists($this->root.'/Test Film/Test Film-Silent.mp4');
        $this->assertFileExists($this->root.'/Test Film/audio/Test Film-French.m4a');
        $this->assertFileExists($this->root.'/Test Film/captions/Test Film-Polish.vtt');

        // The catalog rows.
        $video = MediaVideo::query()->find('v-test-film');
        $this->assertNotNull($video, 'the media_videos row is created');
        $this->assertSame('Test Film', $video->subject);
        $this->assertSame('test-film', $video->slug);
        $this->assertSame('Test Film-Silent.mp4', $video->master);
        $this->assertSame('Test Film', $video->title);

        // Filename-suffix language resolution: French -> fr, Polish -> pl.
        $audio = MediaVideoTrack::query()->where('video_id', 'v-test-film')->where('kind', 'audio')->first();
        $this->assertNotNull($audio);
        $this->assertSame('fr', $audio->code);
        $this->assertSame('French', $audio->name);

        $captions = MediaVideoTrack::query()->where('video_id', 'v-test-film')->where('kind', 'captions')->first();
        $this->assertNotNull($captions);
        $this->assertSame('pl', $captions->code);
        $this->assertSame('Polish', $captions->name);

        // The surface assignment.
        $this->assertSame('v-test-film', MediaSurfaceVideo::query()->find('learn/lesson')?->video_id);

        // The uploaded film appears in the merged catalog (a DB row wins).
        $this->assertContains('v-test-film', MediaMeta::ids());
    }

    public function test_an_explicit_language_code_overrides_the_filename(): void
    {
        $c = app(VideoManagerController::class);

        $request = $this->req(true, [
            'title'      => 'Coded Film',
            'audio_lang' => ['de'], // German, even though the filename says French
        ]);
        $request->files->set('master', UploadedFile::fake()->create('m.mp4', 10, 'video/mp4'));
        $request->files->set('audio', [UploadedFile::fake()->create('src-French.m4a', 5, 'audio/mp4')]);

        $c->store($request);

        $this->assertFileExists($this->root.'/Coded Film/audio/Coded Film-German.m4a');
        $track = MediaVideoTrack::query()->where('video_id', 'v-coded-film')->where('kind', 'audio')->first();
        $this->assertSame('de', $track?->code);
        $this->assertSame('German', $track?->name);
    }

    public function test_the_master_is_required_when_the_subject_has_no_master_yet(): void
    {
        $c = app(VideoManagerController::class);

        $request = $this->req(true, ['title' => 'No Master']);

        try {
            $c->store($request);
            $this->fail('expected a validation failure with no master');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('master', $e->errors());
        }

        $this->assertNull(MediaVideo::query()->find('v-no-master'));
    }

    public function test_an_unresolvable_track_language_rejects_the_upload(): void
    {
        $c = app(VideoManagerController::class);

        $request = $this->req(true, ['title' => 'Bad Lang']);
        $request->files->set('master', UploadedFile::fake()->create('m.mp4', 10, 'video/mp4'));
        // No language suffix and no explicit code: unresolvable.
        $request->files->set('audio', [UploadedFile::fake()->create('mystery.m4a', 5, 'audio/mp4')]);

        try {
            $c->store($request);
            $this->fail('expected a validation failure on the unresolvable track');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('audio.0', $e->errors());
        }

        // The rejection is total: no rows, no files written for the subject.
        $this->assertNull(MediaVideo::query()->find('v-bad-lang'));
        $this->assertFileDoesNotExist($this->root.'/Bad Lang/Bad Lang-Silent.mp4');
    }

    public function test_assign_sets_and_clears_a_films_surface_override(): void
    {
        $c = app(VideoManagerController::class);

        // A registry film id (present in config/cga/media.php).
        $videoId = MediaMeta::ids()[0];

        $c->assign($this->req(true, ['video_id' => $videoId, 'surfaces' => ['civic/home']]));
        $this->assertSame($videoId, MediaMeta::surfaceOverride('civic/home'));

        // Reassigning to a different set removes the old surface for this film.
        $c->assign($this->req(true, ['video_id' => $videoId, 'surfaces' => ['learn/home']]));
        $this->assertNull(MediaMeta::surfaceOverride('civic/home'));
        $this->assertSame($videoId, MediaMeta::surfaceOverride('learn/home'));

        // clear removes every assignment for the film.
        $c->assign($this->req(true, ['video_id' => $videoId, 'surfaces' => [], 'clear' => 1]));
        $this->assertNull(MediaMeta::surfaceOverride('learn/home'));
    }

    // ----------------------------------------------------------------------

    private function req(bool $operator, array $body = []): Request
    {
        $user = (new User)->forceFill(['id' => 'u-'.bin2hex(random_bytes(3)), 'is_operator' => $operator]);
        $r = Request::create('/videos/manage', 'POST', $body);
        $r->setUserResolver(fn () => $user);
        // back() reads the previous url from the request; a referer keeps the
        // redirect deterministic without a session.
        $r->headers->set('referer', '/videos/manage');
        $this->app->instance('request', $r);

        return $r;
    }

    private function assert403(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a 403 from a non-operator');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $f) {
            is_file($f) ? @unlink($f) : $this->rrmdir($f);
        }
        @rmdir($dir);
    }
}
