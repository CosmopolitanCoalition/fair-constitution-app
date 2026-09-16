<?php

namespace Tests\Feature;

use App\Models\MediaSurfaceVideo;
use App\Models\MediaVideo;
use App\Models\MediaVideoTrack;
use App\Support\MediaMeta;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * W-0449 — the registry + uploaded merge in MediaMeta.
 *
 * Runs on the phpunit sqlite :memory: connection. NEVER RefreshDatabase (the
 * dev Postgres holds ~951k jurisdictions): the media migration's own up() builds
 * exactly the tables these pins read, which also proves the migration is
 * sqlite-safe. audit_log is not touched here.
 *
 * A temp empty library root pins baseUrl() to null (no master on disk), so
 * `available` is deterministically false and the merge is what is under test.
 */
class MediaMetaMergeTest extends TestCase
{
    private const VIDEO_ID = 'v-uploaded-merge-demo';

    protected function setUp(): void
    {
        parent::setUp();

        // Build the media tables by running the real migration (sqlite-safe proof).
        (require base_path('database/migrations/2026_09_16_180000_media_library_tables.php'))->up();

        config([
            'cga.media_local.local_root' => rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')
                .'/media-merge-empty-'.getmypid().'-'.uniqid(),
            'cga.media.base_url' => null,
        ]);
        Cache::flush();

        MediaVideo::create([
            'id'      => self::VIDEO_ID,
            'subject' => 'Uploaded Merge Demo',
            'slug'    => 'uploaded-merge-demo',
            'master'  => 'Uploaded Merge Demo-Silent.mp4',
            'title'   => 'Uploaded Merge Demo',
            'source'  => 'upload',
        ]);
        MediaVideoTrack::create([
            'video_id' => self::VIDEO_ID,
            'kind'     => 'audio',
            'code'     => 'nb',
            'name'     => 'Norwegian Bokmal',
        ]);
        MediaVideoTrack::create([
            'video_id' => self::VIDEO_ID,
            'kind'     => 'captions',
            'code'     => 'nb',
            'name'     => 'Norwegian Bokmal',
        ]);
        MediaSurfaceVideo::create([
            'surface_id' => 'learn/lesson',
            'video_id'   => self::VIDEO_ID,
        ]);
    }

    public function test_ids_include_the_uploaded_film(): void
    {
        $this->assertContains(self::VIDEO_ID, MediaMeta::ids());
        // The generated registry still leads: order is preserved, uploads append.
        $this->assertSame('v-summary', MediaMeta::ids()[0]);
    }

    public function test_all_includes_the_uploaded_film_tagged_upload(): void
    {
        $ids = array_column(MediaMeta::all(), 'id');
        $this->assertContains(self::VIDEO_ID, $ids);

        $record = null;
        foreach (MediaMeta::all() as $video) {
            if ($video['id'] === self::VIDEO_ID) {
                $record = $video;
                break;
            }
        }

        $this->assertNotNull($record);
        $this->assertSame('upload', $record['source']);
        $this->assertFalse($record['available'], 'no master on disk, local base -> unavailable');
    }

    public function test_for_returns_the_uploaded_film_with_its_tracks(): void
    {
        $record = MediaMeta::for(self::VIDEO_ID);

        $this->assertSame('Uploaded Merge Demo', $record['subject']);
        $this->assertSame('upload', $record['source']);
        $this->assertSame('nb', $record['audio'][0]['code']);
        $this->assertSame('Norwegian Bokmal', $record['audio'][0]['name']);
        $this->assertSame('nb', $record['captions'][0]['code']);
    }

    public function test_surface_override_resolves_an_assignment(): void
    {
        $this->assertSame(self::VIDEO_ID, MediaMeta::surfaceOverride('learn/lesson'));
        $this->assertNull(MediaMeta::surfaceOverride('learn/home'), 'an unassigned surface has no override');
    }

    public function test_an_uploaded_row_wins_over_a_registry_id(): void
    {
        // Upload a row with the SAME id as a registry film; the DB row wins.
        MediaVideo::create([
            'id'      => 'v-summary',
            'subject' => 'Affiliate Report',
            'slug'    => 'affiliate-report-upload',
            'master'  => 'Affiliate Report-Silent.mp4',
            'title'   => 'Affiliate Report (operator upload)',
            'source'  => 'upload',
        ]);

        $record = MediaMeta::for('v-summary');
        $this->assertSame('upload', $record['source']);
        $this->assertSame('Affiliate Report (operator upload)', $record['title']);
    }
}
