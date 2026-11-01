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
 *   · district results survive a reboot and clear by legislature or by scope,
 *     exactly as the Redis tags do
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
            $t->string('legislature_id', 64)->nullable();
            $t->string('scope_tag', 200)->nullable();
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

    public function test_district_results_survive_a_reboot(): void
    {
        $tags = ['revealed.L1.null.S1', 'revealed.L1'];
        GeojsonDurableCache::rememberTagged('geojson.revealed.L1.S1.null.z6', $tags, 'L1', 'revealed.L1.null.S1', fn () => $this->payload());

        Cache::flush();

        $runs = 0;
        $data = GeojsonDurableCache::rememberTagged('geojson.revealed.L1.S1.null.z6', $tags, 'L1', 'revealed.L1.null.S1', function () use (&$runs) {
            $runs++;

            return [];
        });

        $this->assertSame(0, $runs);
        $this->assertSame($this->payload(), $data);
    }

    public function test_flush_legislature_clears_only_that_legislature(): void
    {
        GeojsonDurableCache::rememberTagged('a', ['revealed.L1'], 'L1', 'revealed.L1.null.S1', fn () => $this->payload());
        GeojsonDurableCache::rememberTagged('b', ['revealed.L2'], 'L2', 'revealed.L2.null.S1', fn () => $this->payload());

        GeojsonDurableCache::flushLegislature('L1');

        $this->assertSame(['b'], DB::table('geojson_cache')->pluck('cache_key')->all());
    }

    public function test_flush_scope_clears_only_that_scope(): void
    {
        GeojsonDurableCache::rememberTagged('a', ['revealed.L1.null.S1'], 'L1', 'revealed.L1.null.S1', fn () => $this->payload());
        GeojsonDurableCache::rememberTagged('b', ['revealed.L1.null.S2'], 'L1', 'revealed.L1.null.S2', fn () => $this->payload());
        GeojsonDurableCache::putTagged('colors.L1.null', ['revealed.L1'], 'L1', null, ['d1' => 2]);

        GeojsonDurableCache::flushScope('L1', 'null', 'S1');

        $keys = DB::table('geojson_cache')->orderBy('cache_key')->pluck('cache_key')->all();
        $this->assertSame(['b', 'colors.L1.null'], $keys);
    }
}
