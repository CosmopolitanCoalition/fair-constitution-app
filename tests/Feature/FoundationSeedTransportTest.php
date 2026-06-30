<?php

namespace Tests\Feature;

use App\Models\ClusterMembership;
use App\Models\FoundationSyncCursor;
use App\Services\Federation\FoundationDrainService;
use App\Services\Federation\SyncProgressService;
use App\Services\Mirror\MirrorService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * The seed transport branch (seed redesign), Phases 3 + 4. Pins:
 *   • in 'paginated' mode the progress payload shows ONE bar PER foundation table, each with a real
 *     row total (the visible/ETA replacement for the opaque download+import bars);
 *   • seedFromHost is gated PER-TABLE-COMPLETE — if any foundation table's drain does not complete,
 *     it throws and never stamps seeded_at (fail-closed).
 *
 * Live-pg posture. The fail-closed test MOCKS the drain so no 951k-row authority stamp runs.
 */
class FoundationSeedTransportTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_seed_transport';

    public function test_progress_emits_one_bar_per_foundation_table_in_paginated_mode(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_seed_transport' => 'paginated']);

            $peer = $this->makeTrustedPeer();
            $membership = ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_SYNCING,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
            ]);

            FoundationSyncCursor::create([
                'peer_id' => $peer->id, 'table_name' => 'jurisdictions',
                'rows_applied' => 100, 'total_rows' => 1000, 'pages_applied' => 1,
                'status' => FoundationSyncCursor::STATUS_OPEN,
            ]);
            FoundationSyncCursor::create([
                'peer_id' => $peer->id, 'table_name' => 'cosmic_addresses',
                'rows_applied' => 8, 'total_rows' => 8, 'pages_applied' => 1,
                'status' => FoundationSyncCursor::STATUS_COMPLETE,
            ]);

            $progress = app(SyncProgressService::class)->progressFor($membership);
            $keys = array_column($progress['bars'], 'key');

            // One bar per foundation table + the audit-history bar.
            $this->assertContains('foundation:cosmic_addresses', $keys);
            $this->assertContains('foundation:jurisdictions', $keys);
            $this->assertContains('foundation:worldpop_rasters', $keys);
            $this->assertContains('foundation:constitutional_settings', $keys);
            $this->assertContains(SyncProgressService::PHASE_DRAIN, $keys);
            // NOT the tarball bars.
            $this->assertNotContains(SyncProgressService::PHASE_DOWNLOAD, $keys);

            $byKey = collect($progress['bars'])->keyBy('key');
            $jur = $byKey['foundation:jurisdictions'];
            $this->assertSame(100, $jur['current']);
            $this->assertSame(1000, $jur['total']);
            $this->assertSame('running', $jur['status']);
            $this->assertFalse($jur['indeterminate']);          // a real denominator ⇒ a real %/ETA

            $this->assertSame('done', $byKey['foundation:cosmic_addresses']['status']);
            $this->assertSame('pending', $byKey['foundation:worldpop_rasters']['status']); // no cursor yet
        });
    }

    public function test_paginated_seed_is_gated_on_every_table_completing(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_seed_transport' => 'paginated']);

            // Mock the drain so NO real page pull / 951k authority stamp runs — one table left OPEN.
            $this->mock(FoundationDrainService::class, function ($mock) {
                $mock->shouldReceive('drain')->andReturn([
                    'cosmic_addresses' => ['status' => FoundationSyncCursor::STATUS_COMPLETE, 'rows_applied' => 8, 'total_rows' => 8],
                    'jurisdictions' => ['status' => FoundationSyncCursor::STATUS_OPEN, 'rows_applied' => 5, 'total_rows' => 1000],
                ]);
            });

            $peer = $this->makeTrustedPeer();
            $membership = ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_SYNCING,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
            ]);

            try {
                app(MirrorService::class)->seedFromHost($peer, $membership);
                $this->fail('Expected the per-table-complete gate to throw on an incomplete drain.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('did not complete', $e->getMessage());
            }

            // Fail-closed — seeded_at must NOT be stamped when a table is unfinished.
            $this->assertNull($membership->refresh()->seeded_at);
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
