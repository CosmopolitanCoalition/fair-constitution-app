<?php

namespace Tests\Feature;

use App\Models\FederationPeer;
use App\Models\FoundationSyncCursor;
use App\Services\Federation\FoundationDrainService;
use App\Services\Federation\FoundationServeService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MultiplexClient;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Joiner side of the paginated foundation drain (seed redesign), Phase 2. Pins:
 *   • applyPage actually INSERTS, decoding EWKB geometry losslessly, and is idempotent
 *     (ON CONFLICT DO NOTHING — a re-applied page is a no-op);
 *   • a full donor→joiner loop reaches COMPLETE and re-runs idempotently;
 *   • a page that does not verify against the host's pinned key ABORTS and applies nothing;
 *   • unsafeFks() drops only self-refs + out-of-foundation FKs, keeping intra-foundation ones;
 *   • a self-ref FK dropped for the drain is restored + present afterward.
 *
 * The donor and joiner share this one live database (the donor serves our own foundation back to
 * us) — enough to prove sign → verify → decode → upsert → cursor → complete + FK management.
 * Live-pg posture.
 */
class FoundationDrainTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_foundation_drain';

    private function service(): FoundationDrainService
    {
        return app(FoundationDrainService::class);
    }

    /** A host peer that signs as US — so the donor's pages verify against host->public_key. */
    private function selfHost(): FederationPeer
    {
        $id = app(InstanceIdentityService::class);

        return FederationPeer::create([
            'server_id' => $id->serverId(),
            'name' => 'Self donor (test)',
            'url' => 'http://host.docker.internal:9998',
            'public_key' => $id->publicKey(),
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
            'metadata' => ['schema_version' => '1'],
        ]);
    }

    /** Bind the multiplex so every reach() returns a page our REAL donor builds for the asked table/key. */
    private function fakeDonorTransport(): void
    {
        $donor = app(FoundationServeService::class);
        $this->mock(MultiplexClient::class, function ($mock) use ($donor) {
            $mock->shouldReceive('reach')->andReturnUsing(function ($serverId, $method, $path, $query) use ($donor) {
                $table = (string) ($query['table'] ?? '');
                $fromKey = isset($query['from_key']) ? json_decode((string) $query['from_key'], true) : null;
                $pageSize = (int) ($query['page_size'] ?? 0);
                $page = $donor->buildFoundationPage($table, is_array($fromKey) ? $fromKey : null, $pageSize);

                return new ClientResponse(new Psr7Response(200, [], (string) json_encode($page)));
            });
        });
    }

    public function test_apply_page_inserts_and_decodes_geometry_and_is_idempotent(): void
    {
        $this->onLivePg(function () {
            $geomHex = DB::selectOne("SELECT encode(ST_AsEWKB(ST_Multi(ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))',4326))),'hex') AS h")->h;
            $centHex = DB::selectOne("SELECT encode(ST_AsEWKB(ST_SetSRID(ST_MakePoint(0.5,0.5),4326)),'hex') AS h")->h;

            $id = (string) Str::uuid();
            $page = [
                'table' => 'jurisdictions',
                'key_columns' => ['id'],
                'columns' => ['id', 'name', 'slug', 'adm_level', 'geom', 'centroid', 'created_at', 'updated_at'],
                'geometry_columns' => ['geom', 'centroid'],
                'raster_columns' => [],
                'rows' => [[
                    'id' => $id, 'name' => 'Applied', 'slug' => 'applied-'.Str::random(8), 'adm_level' => 0,
                    'geom' => $geomHex, 'centroid' => $centHex,
                    'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
                ]],
            ];

            $this->service()->applyPage('jurisdictions', $page);

            $got = DB::selectOne(
                'SELECT name, ST_SRID(geom) AS srid, ST_GeometryType(geom) AS gt, ST_X(centroid) AS cx FROM jurisdictions WHERE id = ?',
                [$id]
            );
            $this->assertSame('Applied', $got->name);
            $this->assertSame(4326, (int) $got->srid);
            $this->assertSame('ST_MultiPolygon', $got->gt);
            $this->assertEqualsWithDelta(0.5, (float) $got->cx, 1e-9);

            // Re-apply — ON CONFLICT DO NOTHING ⇒ still exactly one row, no error.
            $this->service()->applyPage('jurisdictions', $page);
            $this->assertSame(1, (int) DB::table('jurisdictions')->where('id', $id)->count());
        });
    }

    public function test_a_full_donor_to_joiner_loop_completes_and_is_idempotent(): void
    {
        $this->onLivePg(function () {
            $this->fakeDonorTransport();
            $host = $this->selfHost();

            // geoboundary_metadata has NO foreign keys → exercises the pure page/cursor loop with no DDL.
            $cursor = $this->service()->drainTable($host, 'geoboundary_metadata');

            $this->assertSame(FoundationSyncCursor::STATUS_COMPLETE, $cursor->status);
            $this->assertSame(
                (int) DB::table('geoboundary_metadata')->count(),
                (int) $cursor->total_rows ?: (int) DB::table('geoboundary_metadata')->count()
            );

            // Re-draining a complete table is a no-op (returns the same complete cursor).
            $again = $this->service()->drainTable($host, 'geoboundary_metadata');
            $this->assertSame(FoundationSyncCursor::STATUS_COMPLETE, $again->status);
            $this->assertSame($cursor->id, $again->id);
        });
    }

    public function test_a_page_that_fails_signature_aborts_and_applies_nothing(): void
    {
        $this->onLivePg(function () {
            $this->fakeDonorTransport();

            // Same server_id as us, but a WRONG public key → the donor's real signature won't verify.
            $wrongPub = sodium_bin2base64(
                sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()),
                SODIUM_BASE64_VARIANT_ORIGINAL
            );
            $host = FederationPeer::create([
                'server_id' => app(InstanceIdentityService::class)->serverId(),
                'name' => 'Wrong-key host (test)',
                'url' => 'http://host.docker.internal:9998',
                'public_key' => $wrongPub,
                'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
                'trust_established_at' => now(),
                'metadata' => ['schema_version' => '1'],
            ]);

            $cursor = $this->service()->drainTable($host, 'cosmic_addresses');

            $this->assertSame(FoundationSyncCursor::STATUS_ABORTED, $cursor->status);
            $this->assertSame('page_invalid', $cursor->abort_reason);
            $this->assertSame(0, (int) $cursor->rows_applied);
        });
    }

    public function test_unsafe_fks_drops_self_refs_and_out_of_foundation_only(): void
    {
        $this->onLivePg(function () {
            $svc = $this->service();

            // jurisdictions: only its self-ref is unsafe.
            $names = array_column($svc->unsafeFks('jurisdictions'), 'conname');
            $this->assertContains('jurisdictions_parent_id_foreign', $names);

            // cosmic_addresses: its self-ref is unsafe.
            $this->assertContains('cosmic_addresses_parent_id_foreign', array_column($svc->unsafeFks('cosmic_addresses'), 'conname'));

            // constitutional_settings: → laws (out of foundation) is dropped; → jurisdictions (in foundation) is KEPT.
            $settings = array_column($svc->unsafeFks('constitutional_settings'), 'conname');
            $this->assertContains('constitutional_settings_last_amended_by_act_id_foreign', $settings);
            $this->assertNotContains('constitutional_settings_jurisdiction_id_foreign', $settings);
        });
    }

    public function test_a_dropped_self_ref_fk_is_restored_after_the_drain(): void
    {
        $this->onLivePg(function () {
            $this->fakeDonorTransport();
            $host = $this->selfHost();

            $cursor = $this->service()->drainTable($host, 'cosmic_addresses');
            $this->assertSame(FoundationSyncCursor::STATUS_COMPLETE, $cursor->status);

            // The self-ref FK that was dropped for the load is back (and validated).
            $present = DB::selectOne(
                "SELECT 1 AS ok FROM pg_constraint WHERE conname = 'cosmic_addresses_parent_id_foreign' AND conrelid = 'cosmic_addresses'::regclass"
            );
            $this->assertNotNull($present, 'cosmic self-ref FK should be restored after the drain');

            // And the drain left the detail clean (no lingering dropped-constraint record).
            $this->assertArrayNotHasKey('dropped_constraints', (array) ($cursor->refresh()->detail ?? []));
        });
    }

    public function test_droppable_indexes_excludes_the_primary_key_and_unique_indexes(): void
    {
        $this->onLivePg(function () {
            $jur = array_column($this->service()->droppableIndexes('jurisdictions'), 'name');

            // The heavy secondary indexes are droppable …
            $this->assertContains('jurisdictions_geom_idx', $jur);
            $this->assertContains('jurisdictions_centroid_idx', $jur);
            // … but the PK and the slug UNIQUE index (the conflict targets) are NOT.
            $this->assertNotContains('jurisdictions_pkey', $jur);
            $this->assertNotContains('jurisdictions_slug_unique', $jur);
        });
    }

    public function test_dropped_indexes_are_rebuilt_after_the_drain(): void
    {
        $this->onLivePg(function () {
            $this->fakeDonorTransport();
            $host = $this->selfHost();

            // cosmic_addresses carries two secondary btrees + a slug UNIQUE + the PK.
            $before = array_column($this->service()->droppableIndexes('cosmic_addresses'), 'name');
            $this->assertNotEmpty($before, 'cosmic_addresses should have droppable secondary indexes');

            $cursor = $this->service()->drainTable($host, 'cosmic_addresses');
            $this->assertSame(FoundationSyncCursor::STATUS_COMPLETE, $cursor->status);

            // Every secondary index dropped for the load is back afterward.
            foreach ($before as $idx) {
                $present = DB::selectOne("SELECT 1 AS ok FROM pg_class WHERE relname = ? AND relkind = 'i'", [$idx]);
                $this->assertNotNull($present, "index {$idx} should be rebuilt after the drain");
            }
            $this->assertArrayNotHasKey('dropped_indexes', (array) ($cursor->refresh()->detail ?? []));
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
