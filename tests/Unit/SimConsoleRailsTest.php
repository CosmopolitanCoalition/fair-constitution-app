<?php

namespace Tests\Unit;

use App\Http\Controllers\Demo\SimConsoleController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PIN (W-0443) — the sim console's honesty rails are lazy and bounded.
 *
 * Measured 2026-09-14 on box E: GET /simworld took 94 s for a guest because
 * world() summed drawn districts for 512,895 chambers on every request and the
 * page polls every 2 s. Now: the page and its poll carry no rails; the page
 * asks /api/simworld/rails once after mount; that endpoint reads two partial
 * indexes (drifted active maps, chambers over the Type B bound) and one
 * indexed count. The drawn-seat total and the gap live on the map row,
 * maintained by database triggers (migration 2026_09_14_223000) and filled
 * once by maps:drawn-seats-backfill in bounded chunks.
 */
class SimConsoleRailsTest extends TestCase
{
    private function body(string $method): string
    {
        $m = new ReflectionMethod(SimConsoleController::class, $method);
        $lines = file($m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    public function test_the_page_and_the_poll_carry_no_rails(): void
    {
        $world = $this->body('world');
        $this->assertStringNotContainsString('DB::', $world, 'world() issues no query of its own');
        $this->assertStringNotContainsString('over_bound', $world);
        $this->assertStringNotContainsString('seat_gap', $world);
        $this->assertStringContainsString('return $this->snap->world();', $world);
    }

    public function test_the_rails_endpoint_is_bounded(): void
    {
        $rails = $this->body('rails');
        $this->assertStringNotContainsString('SUM(', $rails, 'no correlated sum over districts');
        $this->assertStringContainsString("->where('m.seat_gap', '<>', 0)", $rails, 'seat gap reads the stored gap through its partial index');
        $this->assertStringContainsString("->whereColumn('l.type_b_seats', '>', 'l.type_a_seats')", $rails, 'over-bound reads its partial index');
        $this->assertSame(2, substr_count($rails, '->limit(25)'), 'both lists page at 25');

        $route = Route::getRoutes()->getByName('api.simworld.rails');
        $this->assertNotNull($route, 'the rails endpoint is registered');
        $this->assertSame('api/simworld/rails', $route->uri());
        $this->assertContains('auth', $route->excludedMiddleware(), 'public read like the poll');
    }

    public function test_migration_adds_the_two_columns_and_the_backfill_is_chunked(): void
    {
        $migration = base_path('database/migrations/2026_09_14_223000_drawn_seats_on_district_maps.php');
        $this->assertFileExists($migration);
        $src = file_get_contents($migration);
        $this->assertStringContainsString('REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT', $src, 'statement-level triggers, one update per statement');
        $this->assertStringContainsString('legislature_district_maps_drift_idx', $src);
        $this->assertStringContainsString('legislatures_over_bound_idx', $src);
        $this->assertStringContainsString('d.seats - COALESCE(d.bonus_seats, 0)', $src, 'the apportionment identity nets bonus seats');

        // The sqlite fixture holds no tables; give it the map table so the
        // column half of the migration can run, then run it twice.
        if (! Schema::hasTable('legislature_district_maps')) {
            Schema::create('legislature_district_maps', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('legislature_id');
                $table->string('status');
            });
        }
        $m = require $migration;
        $m->up();
        $m->up(); // rerunnable on the sqlite fixture
        $this->assertTrue(Schema::hasColumn('legislature_district_maps', 'drawn_seats'));
        $this->assertTrue(Schema::hasColumn('legislature_district_maps', 'seat_gap'));

        $cmd = file_get_contents(app_path('Console/Commands/MapsDrawnSeatsBackfillCommand.php'));
        $this->assertStringContainsString('HostCapacity::enumerationChunk()', $cmd, 'chunk size derived from the host');
        $this->assertStringContainsString("->whereNull('drawn_seats')", $cmd, 'resumable: NULL means not yet computed');
        $this->assertStringContainsString('eta', $cmd, 'visible progress');
    }
}
