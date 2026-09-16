<?php

namespace Tests\Unit;

use App\Models\MediaPull;
use App\Models\MediaPullItem;
use App\Services\Media\MediaLibraryService;
use App\Services\Media\MediaTransfer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * W-0448 — MediaTransfer moves one file resumably.
 *
 * Runs on the phpunit sqlite :memory: connection (the run/item rows the
 * transfer reads and ticks live in media_pulls / media_pull_items). NEVER
 * RefreshDatabase: the media migration's own up() builds exactly those tables.
 * The web pins drive Guzzle with a MockHandler + history, so no network is
 * touched and the Range header can be inspected.
 */
class MediaTransferTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('database/migrations/2026_09_16_180000_media_library_tables.php'))->up();

        $this->root = $this->tmp('media-transfer-root');
        @mkdir($this->root, 0775, true);
        config(['cga.media_local.local_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
        parent::tearDown();
    }

    public function test_folder_source_copies_and_verifies(): void
    {
        $srcBase = $this->tmp('media-transfer-src');
        $rel = 'Subjects/Test Film/Test Film-Silent.mp4';
        $this->putFile($srcBase.'/'.$rel, 'the-master-bytes');

        $item = $this->item('master', $rel, 'Test Film/Test Film-Silent.mp4');

        $transfer = new MediaTransfer(new MediaLibraryService);
        $result = $transfer->pull($item, 'folder', $srcBase);

        $this->assertSame('done', $result['status']);
        $this->assertSame(strlen('the-master-bytes'), $result['bytes']);

        $dest = $this->root.'/Test Film/Test Film-Silent.mp4';
        $this->assertFileExists($dest);
        $this->assertSame('the-master-bytes', file_get_contents($dest));
        $this->assertFileDoesNotExist($dest.'.part', 'the .part is renamed away on success');

        $this->rmrf($srcBase);
    }

    public function test_a_short_web_read_keeps_the_part(): void
    {
        // Content-Length claims 100 bytes; the body is 3. The size check fails,
        // the fetch fails, and the .part is kept for a later resume.
        $mock = new MockHandler([
            new Response(200, ['Content-Length' => '100'], 'abc'),
        ]);
        $transfer = new MediaTransfer(new MediaLibraryService, new Client(['handler' => HandlerStack::create($mock)]));

        $rel = 'Subjects/Test Film/Test Film-Silent.mp4';
        $item = $this->item('master', $rel, 'Test Film/Test Film-Silent.mp4');

        $result = $transfer->pull($item, 'web', 'https://media.test/uploads');

        $this->assertSame('failed', $result['status']);
        $part = $this->root.'/Test Film/Test Film-Silent.mp4.part';
        $this->assertFileExists($part, 'a short read keeps the .part');
        $this->assertSame('abc', file_get_contents($part));
        $this->assertFileDoesNotExist($this->root.'/Test Film/Test Film-Silent.mp4', 'no final file on failure');
    }

    public function test_range_header_is_sent_when_a_part_exists(): void
    {
        // A 5-byte .part already on disk; the server serves the remaining 5 as a
        // 206, which appends to complete the 10-byte file.
        $dest = 'Test Film/Test Film-Silent.mp4';
        $part = $this->root.'/'.$dest.'.part';
        $this->putFile($part, 'abcde');

        $history = [];
        $mock = new MockHandler([
            new Response(206, ['Content-Range' => 'bytes 5-9/10', 'Content-Length' => '5'], 'fghij'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $transfer = new MediaTransfer(new MediaLibraryService, new Client(['handler' => $stack]));

        $rel = 'Subjects/Test Film/Test Film-Silent.mp4';
        $item = $this->item('master', $rel, $dest);

        $result = $transfer->pull($item, 'web', 'https://media.test/uploads');

        $this->assertSame('done', $result['status']);
        $this->assertCount(1, $history);
        $this->assertSame('bytes=5-', $history[0]['request']->getHeaderLine('Range'));

        $final = $this->root.'/'.$dest;
        $this->assertFileExists($final);
        $this->assertSame('abcdefghij', file_get_contents($final));
        $this->assertFileDoesNotExist($part, 'the .part is consumed on success');
    }

    public function test_a_halted_run_leaves_the_item_pending(): void
    {
        $pull = MediaPull::create(['source' => 'folder', 'status' => 'halted', 'options' => []]);
        $item = new MediaPullItem([
            'pull_id' => $pull->id, 'subject' => 'Test Film', 'kind' => 'master',
            'source' => 'Subjects/Test Film/Test Film-Silent.mp4',
            'dest' => 'Test Film/Test Film-Silent.mp4', 'status' => 'pending',
        ]);
        $item->save();

        $transfer = new MediaTransfer(new MediaLibraryService);
        $result = $transfer->pull($item, 'folder', $this->tmp('never-read'));

        $this->assertSame('pending', $result['status'], 'a halted run does not fetch');
        $this->assertFileDoesNotExist($this->root.'/Test Film/Test Film-Silent.mp4');
    }

    private function item(string $kind, string $source, string $dest): MediaPullItem
    {
        $pull = MediaPull::create(['source' => 'web', 'status' => 'running', 'options' => []]);
        $item = new MediaPullItem([
            'pull_id'    => $pull->id,
            'subject'    => 'Test Film',
            'kind'       => $kind,
            'track_name' => null,
            'source'     => $source,
            'dest'       => $dest,
            'status'     => 'running',
        ]);
        $item->save();

        return $item;
    }

    private function tmp(string $label): string
    {
        return rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/'.$label.'-'.getmypid().'-'.uniqid();
    }

    private function putFile(string $abs, string $content): void
    {
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
