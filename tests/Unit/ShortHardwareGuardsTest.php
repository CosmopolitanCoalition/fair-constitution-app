<?php

namespace Tests\Unit;

use App\Jobs\Clocks\EvaluateCriticalPopulationJob;
use App\Jobs\Clocks\EvaluatePetitionThresholdJob;
use App\Jobs\Clocks\EvaluateResidencyThresholdsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SHORT-HARDWARE GUARDS (operator order 2026-11-01). A box with a slow disk and
 * a large user table must not run its heavy automatic work.
 *
 *   · a frozen box exits the three threshold sweeps before any database read,
 *     including payloads queued before the freeze
 *   · the boot prewarm needs 4 GiB of Horizon memory
 *   · the login email lookup tries the exact lowercase address first
 *   · every zoom up to 7 shares one map cache entry, and the prewarm defaults to one zoom
 *   · the schema-update banner and the schema status answer belong to the operator
 *   · the prewarm plans the root scope alone unless the giants are asked for
 *   · a page that shows a resident count reads the cached figure, a decision counts exactly
 *   · the redundant-index drop runs outside a transaction and never names a unique key
 *   · the bill form reads its judiciary choices from a plain jurisdiction IN-list, not an OR over a subselect
 *   · a cold color map answers at once and builds in the background; mutations still color inline
 *   · pages read the maintained resident counter; the nightly recount and the boot warm exist
 */
class ShortHardwareGuardsTest extends TestCase
{
    public function test_the_threshold_sweeps_exit_before_any_query_when_frozen(): void
    {
        config(['cga.clocks_frozen' => true]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach ([EvaluateCriticalPopulationJob::class, EvaluateResidencyThresholdsJob::class, EvaluatePetitionThresholdJob::class] as $job) {
            app()->call([new $job, 'handle']);
        }

        $this->assertSame(0, $queries);
    }

    public function test_the_boot_prewarm_needs_four_gib_of_horizon_memory(): void
    {
        $script = file_get_contents(base_path('docker/php/entrypoint.sh'));

        $this->assertStringContainsString('[ "$gib" -lt 4 ]', $script);
        $this->assertStringNotContainsString('[ "$gib" -lt 2 ]', $script);
    }

    public function test_login_tries_the_exact_lowercase_email_before_the_scan_fallback(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Auth/AuthenticatedSessionController.php'));

        $exact = strpos($src, "->where('email', \$lowered)");
        $fallback = strpos($src, "whereRaw('lower(email) = ?'");

        $this->assertNotFalse($exact);
        $this->assertNotFalse($fallback);
        $this->assertLessThan($fallback, $exact);
    }

    public function test_zooms_up_to_seven_share_one_cache_entry_and_the_prewarm_warms_one_zoom(): void
    {
        $jur = file_get_contents(app_path('Http/Controllers/JurisdictionController.php'));
        $leg = file_get_contents(app_path('Http/Controllers/LegislatureController.php'));

        $this->assertSame(3, substr_count($jur, '$zoom <= 7 ? 7 : $zoom'));
        $this->assertSame(1, substr_count($leg, '$zoom <= 7 ? 7 : $zoom'));
        $this->assertSame([6], \App\Jobs\PrewarmGeojsonCachesJob::parseZooms(null));
    }

    public function test_the_schema_update_banner_and_status_belong_to_the_operator(): void
    {
        $banner = file_get_contents(base_path('resources/js/Components/SchemaUpdateBanner.vue'));
        $this->assertStringContainsString('page.props.auth?.user?.is_operator', $banner);
        $this->assertStringContainsString('!isOperator.value', $banner);

        $setup = file_get_contents(app_path('Http/Controllers/SetupController.php'));
        $this->assertMatchesRegularExpression(
            '/function bootstrapStatusEndpoint\(Request \$request\).*?is_operator.*?pending_count.{1,5}=> 0/s',
            $setup
        );
    }

    public function test_the_prewarm_plans_the_root_scope_alone_unless_giants_are_asked_for(): void
    {
        $planner = file_get_contents(app_path('Services/Maps/GeojsonPrewarmPlanner.php'));
        $this->assertStringContainsString('bool $includeGiants = false', $planner);
        $this->assertStringContainsString('if ($includeGiants) {', $planner);

        $job = new \App\Jobs\PrewarmGeojsonCachesJob;
        $this->assertFalse($job->includeGiants);
    }

    public function test_pages_read_the_cached_resident_count_and_decisions_count_exactly(): void
    {
        $pet = file_get_contents(app_path('Http/Controllers/Civic/PetitionController.php'));
        $life = file_get_contents(app_path('Http/Controllers/Jurisdictions/LifecycleController.php'));
        $this->assertStringContainsString("CivicPopulation::forDisplay(\$association['id'])", $pet);
        $this->assertStringContainsString('CivicPopulation::forDisplay((string) $focus->id)', $life);

        $svc = file_get_contents(app_path('Services/PetitionService.php'));
        $this->assertStringContainsString('CivicPopulation::of(', $svc);
        $this->assertStringNotContainsString('forDisplay', $svc);
    }

    public function test_the_redundant_index_drop_runs_concurrently_and_names_no_unique_key(): void
    {
        $src = file_get_contents(database_path('migrations/2026_11_01_130000_drop_redundant_indexes.php'));

        $this->assertStringContainsString('public $withinTransaction = false;', $src);
        $this->assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS', $src);
        $this->assertStringNotContainsString('_unique', $src);
        $this->assertStringNotContainsString('_pkey', $src);
        $this->assertSame(15, preg_match_all("/^\s+'[a-z0-9_]+',/m", $src));
    }

    public function test_the_bill_form_judiciary_choices_use_a_plain_in_list(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Legislature/BillController.php'));

        $this->assertStringContainsString("->whereIn('jd.jurisdiction_id', \$chainIds === [] ? [\$jid] : \$chainIds)", $src);
        $this->assertStringNotContainsString("orWhereIn('jd.jurisdiction_id'", $src);
    }

    public function test_a_cold_color_map_builds_in_the_background_and_mutations_color_inline(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/LegislatureController.php'));

        $this->assertStringContainsString('BuildLegislatureColorsJob::dispatch($legislatureId, $mapId)', $src);
        $this->assertStringContainsString('bool $blocking = false', $src);
        // the three mutation reads, the scope map and the background build stay exact and inline
        $this->assertSame(5, preg_match_all('/legislatureColorMap\([^)]*, true\)/', $src));
        // the cached revealed payload carries no coloring; colors are overlaid per request
        $this->assertStringContainsString("\$payload['features'][\$i]['properties']['color_index']", $src);

        $job = new \App\Jobs\Legislature\BuildLegislatureColorsJob('L1', null);
        $this->assertSame('L1:null', $job->uniqueId());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
    }

    public function test_pages_read_the_maintained_resident_counter(): void
    {
        Schema::dropIfExists('jurisdiction_resident_counts');
        Schema::create('jurisdiction_resident_counts', function ($t) {
            $t->string('jurisdiction_id')->primary();
            $t->unsignedBigInteger('active_residents')->default(0);
            $t->timestamp('counted_at')->nullable();
        });
        DB::table('jurisdiction_resident_counts')->insert(['jurisdiction_id' => 'J1', 'active_residents' => 4242]);

        $this->assertSame(4242, \App\Support\CivicPopulation::forDisplay('J1'));

        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('RecountResidentsJob', $console);
        $this->assertGreaterThanOrEqual(250, \App\Console\Commands\ResidentsRecountCommand::defaultChunk());
        $this->assertLessThanOrEqual(4000, \App\Console\Commands\ResidentsRecountCommand::defaultChunk());
    }

    public function test_the_boot_warm_is_bounded_and_wired_into_the_horizon_entrypoint(): void
    {
        $this->assertGreaterThanOrEqual(256, \App\Console\Commands\DbWarmHotCommand::defaultBudgetMb());
        $this->assertLessThanOrEqual(2048, \App\Console\Commands\DbWarmHotCommand::defaultBudgetMb());

        $script = file_get_contents(base_path('docker/php/entrypoint.sh'));
        $this->assertStringContainsString('php artisan db:warm-hot --unless-busy', $script);
        $this->assertStringContainsString('CGA_WARM_INDEXES', $script);
    }

    public function test_the_clocks_page_reads_its_armed_timer_aggregate_from_the_cache(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/System/ClocksController.php'));

        $this->assertStringContainsString("Cache::flexible('clocks.armed_by_clock', [60, 900]", $src);
        $this->assertStringContainsString('private function computeArmedByClock', $src);
    }

    public function test_siblings_read_the_parents_children_payload_and_pickers_are_cached(): void
    {
        $jur = file_get_contents(app_path('Http/Controllers/JurisdictionController.php'));
        $this->assertStringContainsString('private function childrenPayload(string $parentId, int $zoom)', $jur);
        $this->assertStringContainsString('$this->childrenPayload((string) $jurisdiction->parent_id, $zoom)', $jur);
        $this->assertStringNotContainsString('geojson.siblings.', $jur);

        $this->assertStringContainsString("rememberForever('places.picker.atlas.200'", file_get_contents(app_path('Http/Controllers/System/AtlasController.php')));
        $this->assertStringContainsString("rememberForever('places.picker.reach.200'", file_get_contents(app_path('Http/Controllers/ReachController.php')));

        $mig = file_get_contents(database_path('migrations/2026_11_01_150000_index_place_picker_and_ubi_latest.php'));
        $this->assertStringContainsString('public $withinTransaction = false;', $mig);
        $this->assertSame(2, substr_count($mig, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS'));
    }
}
