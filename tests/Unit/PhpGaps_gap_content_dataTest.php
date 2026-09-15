<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Gap lane gap-content-data — PHP pin (DB-free, app-free).
 *
 * The media registry ships video titles as data. The lane made the generator
 * emit a stable i18n key per video (title_key = c_media.video.<slug>) so the
 * title renders through vue-i18n with the title as fallback. This pins the
 * generated config so the emitted keys stay in the exact shape the client
 * consumer and the c_media catalog expect.
 *
 * No framework boot, no database: it requires the generated array file
 * directly. `env()` is available through the composer autoloaded helpers.
 */
final class PhpGaps_gap_content_dataTest extends TestCase
{
    /** @return array<string, mixed> */
    private function media(): array
    {
        return require __DIR__.'/../../config/cga/media.php';
    }

    public function test_every_video_has_a_stable_title_key(): void
    {
        $media = $this->media();
        $this->assertArrayHasKey('videos', $media);
        $videos = $media['videos'];
        $this->assertNotEmpty($videos, 'the registry ships videos');

        $bad = [];
        foreach ($videos as $v) {
            $slug = $v['slug'] ?? null;
            $this->assertIsString($slug);
            $expect = 'c_media.video.'.$slug;
            if (($v['title_key'] ?? null) !== $expect) {
                $bad[] = $slug.': '.($v['title_key'] ?? 'null').' != '.$expect;
            }
            if (! is_string($v['title'] ?? null) || ($v['title'] ?? '') === '') {
                $bad[] = $slug.': empty title';
            }
        }

        $this->assertSame([], $bad, 'every video needs title_key = c_media.video.<slug>: '.implode(' | ', $bad));
    }
}
