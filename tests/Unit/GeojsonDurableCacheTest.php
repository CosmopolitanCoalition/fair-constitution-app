<?php

namespace Tests\Unit;

use App\Services\GeojsonDurableCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE DURABLE MAP CACHE (operator order 2026-11-01). The map result is computed
 * once. A restart empties the cache store, and the stored copy is read back
 * without running the PostGIS query again. DB-free: a tiny sqlite table.
 *
 *   · a cache miss computes once and stores the result
 *   · after the cache store is emptied (a reboot), the stored copy is returned
 *     and the builder does not run
 *   · flush() clears the stored copy, so the builder runs again
 *   · a missing table never blocks a request
 */
class GeojsonDurableCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('geojson_cache');
        Schema::create('geojson_cache', function ($t) {
            $t->string('cache_key', 200)->primary();
            $t->text('payload');
            $t->timestamp('created_at')->nullable();
        });
        Cache::flush();
    }

    private function payload(): array
    {
        return ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'id' => 'a']]];
    }

    public function test_a_miss_computes_once_and_stores_the_result(): void
    {
        $runs = 0;
        $build = function () use (&$runs) {
            $runs++;

            return $this->payload();
        };

        $this->assertSame($this->payload(), GeojsonDurableCache::remember('geojson.children.x.z6', $build));
        $this->assertSame($this->payload(), GeojsonDurableCache::remember('geojson.children.x.z6', $build));
        $this->assertSame(1, $runs);
        $this->assertSame(1, DB::table('geojson_cache')->count());
    }

    public function test_a_reboot_reads_the_stored_copy_without_computing(): void
    {
        GeojsonDurableCache::remember('geojson.children.x.z6', fn () => $this->payload());

        Cache::flush(); // the cache store starts empty after a restart

        $runs = 0;
        $data = GeojsonDurableCache::remember('geojson.children.x.z6', function () use (&$runs) {
            $runs++;

            return [];
        });

        $this->assertSame(0, $runs);
        $this->assertSame($this->payload(), $data);
    }

    public function test_flush_clears_the_stored_copy(): void
    {
        GeojsonDurableCache::remember('geojson.children.x.z6', fn () => $this->payload());

        Cache::flush();
        GeojsonDurableCache::flush();

        $runs = 0;
        GeojsonDurableCache::remember('geojson.children.x.z6', function () use (&$runs) {
            $runs++;

            return $this->payload();
        });

        $this->assertSame(1, $runs);
    }

    public function test_a_missing_table_does_not_block_a_request(): void
    {
        Schema::dropIfExists('geojson_cache');
        Cache::flush();

        $data = GeojsonDurableCache::remember('geojson.self.y.z6', fn () => $this->payload());

        $this->assertSame($this->payload(), $data);
        GeojsonDurableCache::flush(); // must not throw
    }
}
