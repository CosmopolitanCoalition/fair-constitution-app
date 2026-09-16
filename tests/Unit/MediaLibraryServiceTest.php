<?php

namespace Tests\Unit;

use App\Services\Media\MediaLibraryService;
use App\Support\MediaMeta;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * W-0448 — the file-side owner of the self-hosted library. These pins run on a
 * temp root (config('cga.media_local.local_root')) and a tiny catalog fixture,
 * so no real media and no DB are needed. The media_videos table is absent here,
 * proving catalog()/present() are Schema-guarded (dbRecords returns []).
 */
class MediaLibraryServiceTest extends TestCase
{
    private string $root;

    private MediaLibraryService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')
            .'/media-lib-test-'.getmypid().'-'.uniqid();
        @mkdir($this->root, 0775, true);

        config([
            'cga.media_local.local_root' => $this->root,
            'cga.media.base_url'         => null,
            'cga.media.languages'        => [
                'nb' => ['name' => 'Norwegian Bokmal', 'native' => 'Norsk bokmal', 'dir' => 'ltr', 'locale' => null],
            ],
            'cga.media.videos' => [
                [
                    'id'       => 'v-test-film',
                    'subject'  => 'Test Film',
                    'slug'     => 'test-film',
                    'master'   => 'Test Film-Silent.mp4',
                    'audio'    => ['nb'],
                    'captions' => ['nb'],
                ],
            ],
        ]);

        Cache::flush();
        $this->svc = new MediaLibraryService;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
        parent::tearDown();
    }

    public function test_root_honours_the_config_override(): void
    {
        $this->assertSame($this->root, $this->svc->root());
    }

    public function test_present_is_false_on_an_empty_root(): void
    {
        $this->assertFalse($this->svc->present());
    }

    public function test_present_is_true_once_a_master_is_on_disk(): void
    {
        $this->writeFile('Test Film/Test Film-Silent.mp4', 'video-bytes');

        Cache::flush(); // present() caches; a fresh probe must see the new file
        $this->assertTrue($this->svc->present());
    }

    public function test_plan_items_marks_present_files_skipped_and_the_rest_pending(): void
    {
        $this->writeFile('Test Film/Test Film-Silent.mp4', 'video-bytes');

        $items = $this->svc->planItems();

        $master = $this->itemOf($items, 'master', null);
        $audio  = $this->itemOf($items, 'audio', 'Norwegian Bokmal');
        $caption = $this->itemOf($items, 'captions', 'Norwegian Bokmal');

        $this->assertNotNull($master, 'a master item is planned');
        $this->assertSame('skipped', $master['status'], 'a present master is skipped');
        $this->assertSame('Test Film/Test Film-Silent.mp4', $master['dest']);
        $this->assertSame('Subjects/Test Film/Test Film-Silent.mp4', $master['source_path']);

        $this->assertNotNull($audio, 'an audio item is planned');
        $this->assertSame('pending', $audio['status'], 'a missing audio track is pending');
        $this->assertSame('Test Film/audio/Test Film-Norwegian Bokmal.m4a', $audio['dest']);

        $this->assertNotNull($caption, 'a caption item is planned');
        $this->assertSame('pending', $caption['status']);
        $this->assertSame('Test Film/captions/Test Film-Norwegian Bokmal.vtt', $caption['dest']);
    }

    public function test_plan_items_respects_the_kinds_and_subjects_filters(): void
    {
        $mastersOnly = $this->svc->planItems(null, ['master']);
        foreach ($mastersOnly as $item) {
            $this->assertSame('master', $item['kind']);
        }

        $none = $this->svc->planItems(['No Such Subject']);
        $this->assertSame([], $none, 'an unknown subject filter yields no items');
    }

    public function test_url_for_percent_encodes_each_segment(): void
    {
        $this->assertSame(
            'https://example.test/uploads/Subjects/Test%20Film/Test%20Film-Silent.mp4',
            $this->svc->urlFor('Subjects/Test Film/Test Film-Silent.mp4', 'https://example.test/uploads')
        );

        // The hyphen and dot are unreserved and must stay literal.
        $this->assertStringContainsString('-Silent.mp4', $this->svc->urlFor('a/b-Silent.mp4', 'https://x.test'));
    }

    public function test_dest_abs_joins_the_root(): void
    {
        $this->assertSame(
            $this->root.'/Test Film/Test Film-Silent.mp4',
            $this->svc->destAbs('Test Film/Test Film-Silent.mp4')
        );
    }

    public function test_master_exists_reflects_the_disk(): void
    {
        $video = MediaMeta::for('v-test-film');
        $this->assertFalse($this->svc->masterExists($video));

        $this->writeFile('Test Film/Test Film-Silent.mp4', 'x');
        $this->assertTrue($this->svc->masterExists($video));
    }

    public function test_inventory_counts_present_and_expected_tracks(): void
    {
        $this->writeFile('Test Film/Test Film-Silent.mp4', 'x');
        $this->writeFile('Test Film/audio/Test Film-Norwegian Bokmal.m4a', 'a');

        $row = $this->svc->inventory()[0];

        $this->assertSame('v-test-film', $row['id']);
        $this->assertTrue($row['master']);
        $this->assertSame(1, $row['audio']);
        $this->assertSame(0, $row['captions']);
        $this->assertSame(1, $row['expected_audio']);
        $this->assertSame(1, $row['expected_captions']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function itemOf(array $items, string $kind, ?string $trackName): ?array
    {
        foreach ($items as $item) {
            if ($item['kind'] === $kind && $item['track_name'] === $trackName) {
                return $item;
            }
        }

        return null;
    }

    private function writeFile(string $rel, string $content): void
    {
        $abs = $this->root.'/'.$rel;
        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $content);
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
