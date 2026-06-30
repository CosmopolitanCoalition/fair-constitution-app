<?php

namespace Tests\Feature;

use App\Services\Federation\FoundationServeService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Donor side of the paginated foundation drain (seed redesign), Phase 1. Pins:
 *   • a page is SIGNED over its canonical form (verifies against OUR pinned key);
 *   • KEYSET paging advances strictly forward with no overlap;
 *   • geometry / raster columns are AUTO-DETECTED from the catalog (jurisdictions
 *     carries TWO geometry columns — geom + centroid — and the raster table one);
 *   • geometry round-trips losslessly through EWKB hex, SRID preserved;
 *   • only allowlisted foundation tables serve; a short page is `complete`.
 *
 * Live-pg posture (the foundation tables only exist on the real database).
 */
class FoundationPageServeTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_foundation_serve';

    private function service(): FoundationServeService
    {
        return app(FoundationServeService::class);
    }

    public function test_a_page_is_signed_and_keyset_pages_forward(): void
    {
        $this->onLivePg(function () {
            // cosmic_addresses always carries the 8 seeded Multiverse→Earth rows.
            $page = $this->service()->buildFoundationPage('cosmic_addresses', null, 3);

            $this->assertSame('cosmic_addresses', $page['table']);
            $this->assertSame(['id'], $page['key_columns']);
            $this->assertCount(3, $page['rows']);
            $this->assertFalse($page['complete']);                 // 8 rows > page of 3
            $this->assertIsArray($page['next_from_key']);
            $this->assertCount(1, $page['next_from_key']);

            // The page verifies against our OWN pinned key (a relayer flipping a byte breaks this).
            $pub = app(InstanceIdentityService::class)->publicKey();
            $this->assertTrue(InstanceIdentityService::verify(
                $pub,
                FoundationServeService::pageCanonical($page),
                $page['signature'],
            ));

            // Page 2 from the watermark — strictly forward, no overlap with page 1.
            $page1Ids = array_column($page['rows'], 'id');
            $next = $this->service()->buildFoundationPage('cosmic_addresses', $page['next_from_key'], 3);
            foreach ($next['rows'] as $row) {
                $this->assertGreaterThan($page['next_from_key'][0], $row['id']);
                $this->assertNotContains($row['id'], $page1Ids);
            }
        });
    }

    public function test_a_short_page_is_complete(): void
    {
        $this->onLivePg(function () {
            $page = $this->service()->buildFoundationPage('cosmic_addresses', null, 1000);

            $this->assertGreaterThanOrEqual(8, count($page['rows']));
            $this->assertTrue($page['complete']);                  // fewer rows than asked ⇒ caught up
        });
    }

    public function test_geometry_and_raster_columns_are_auto_detected(): void
    {
        $this->onLivePg(function () {
            $jur = $this->service()->buildFoundationPage('jurisdictions', null, 1);
            // BOTH geometry columns are detected from the catalog — not a hardcoded "geom".
            $this->assertContains('geom', $jur['geometry_columns']);
            $this->assertContains('centroid', $jur['geometry_columns']);

            $rast = $this->service()->buildFoundationPage('worldpop_rasters', null, 1);
            $this->assertContains('rast', $rast['raster_columns']);
        });
    }

    public function test_geometry_round_trips_losslessly_through_ewkb_hex(): void
    {
        $this->onLivePg(function () {
            $id = (string) Str::uuid();
            DB::insert(
                "INSERT INTO jurisdictions (id, name, slug, adm_level, geom, centroid, created_at, updated_at)
                 VALUES (?, ?, ?, 0,
                   ST_Multi(ST_GeomFromText('POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))', 4326)),
                   ST_SetSRID(ST_MakePoint(0.5, 0.5), 4326), now(), now())",
                [$id, 'Round-trip test', 'rt-test-'.Str::random(8)]
            );

            // Encode exactly as the donor projection does, then decode as the joiner will.
            $hex = DB::selectOne("SELECT encode(ST_AsEWKB(geom), 'hex') AS h FROM jurisdictions WHERE id = ?", [$id])->h;
            $decoded = DB::selectOne(
                "SELECT ST_AsText(ST_GeomFromEWKB(decode(?, 'hex'))) AS wkt,
                        ST_SRID(ST_GeomFromEWKB(decode(?, 'hex'))) AS srid",
                [$hex, $hex]
            );

            $this->assertStringContainsString('MULTIPOLYGON', strtoupper((string) $decoded->wkt));
            $this->assertSame(4326, (int) $decoded->srid);
        });
    }

    public function test_only_allowlisted_foundation_tables_serve(): void
    {
        $this->onLivePg(function () {
            $this->expectException(InvalidArgumentException::class);
            $this->service()->buildFoundationPage('users', null, 10);
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
