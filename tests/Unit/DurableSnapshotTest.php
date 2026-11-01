<?php

namespace Tests\Unit;

use App\Support\DurableSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE DURABLE SNAPSHOT (operator order 2026-11-01). A display figure that is expensive to
 * compute is computed once, kept in the database, and survives a restart. DB-free: a tiny
 * sqlite table.
 *
 *   · an absent figure is built once and kept
 *   · a fresh figure is returned without building again
 *   · objects survive the round trip
 *   · a missing table never blocks the page
 */
class DurableSnapshotTest extends TestCase
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

    public function test_an_absent_figure_is_built_once_and_a_fresh_one_is_not_rebuilt(): void
    {
        $builds = 0;
        $build = function () use (&$builds) {
            $builds++;

            return 42;
        };

        $this->assertSame(42, DurableSnapshot::remember('t.count', 3600, $build));
        $this->assertSame(42, DurableSnapshot::remember('t.count', 3600, $build));
        $this->assertSame(1, $builds);
    }

    public function test_the_figure_survives_a_restart_of_the_cache_store(): void
    {
        DurableSnapshot::remember('t.count', 3600, fn () => 7);
        Cache::flush();

        $builds = 0;
        $value = DurableSnapshot::remember('t.count', 3600, function () use (&$builds) {
            $builds++;

            return 99;
        });

        $this->assertSame(7, $value);
        $this->assertSame(0, $builds);
    }

    public function test_objects_survive_the_round_trip(): void
    {
        $rows = collect([(object) ['id' => 'a', 'n' => 3]]);
        DurableSnapshot::remember('t.rows', 3600, fn () => $rows);

        $back = DurableSnapshot::remember('t.rows', 3600, fn () => collect());

        $this->assertSame('a', $back->first()->id);
        $this->assertSame(3, $back->first()->n);
    }

    public function test_forget_drops_the_figure_and_a_missing_table_does_not_block(): void
    {
        DurableSnapshot::remember('t.count', 3600, fn () => 1);
        DurableSnapshot::forget('t.count');
        $this->assertSame(2, DurableSnapshot::remember('t.count', 3600, fn () => 2));

        Schema::dropIfExists('geojson_cache');
        $this->assertSame(5, DurableSnapshot::remember('t.other', 3600, fn () => 5));
        DurableSnapshot::forget('t.other'); // must not throw
    }
}
