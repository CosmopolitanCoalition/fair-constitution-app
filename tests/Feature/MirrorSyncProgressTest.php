<?php

namespace Tests\Feature;

use App\Models\ClusterMembership;
use App\Models\User;
use App\Services\Federation\SyncProgressService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G3b) — live seed/drain progress for a mirror join. The progress is a
 * cosmetic mirror of the already-committed sync state (seed bytes + the cold
 * cursor), surfaced for the operator's "Join a cluster" panel the same way the
 * setup wizard surfaces the ETL import. Pins: the bar contract + lifecycle
 * derivation, that phase markers stamp started_at, and that the poll endpoint is
 * citizen-public mesh state (Art. II §2) carrying no secrets.
 *
 * Live-pg posture.
 */
class MirrorSyncProgressTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_sync_progress';

    public function test_progress_for_reports_three_phases_and_derives_lifecycle_from_state(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $membership = ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_SYNCING,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
                'seed_total_bytes' => 1000,
                'seed_cursor_bytes' => 400,
            ]);

            $out = app(SyncProgressService::class)->progressFor($membership);

            $this->assertSame('running', $out['lifecycle'], 'a SYNCING membership with no cache derives running');
            $keys = array_map(fn ($b) => $b['key'], $out['bars']);
            $this->assertSame(
                [SyncProgressService::PHASE_DOWNLOAD, SyncProgressService::PHASE_IMPORT, SyncProgressService::PHASE_DRAIN],
                $keys,
            );

            $download = $out['bars'][0];
            $this->assertSame(400, $download['current']);
            $this->assertSame(1000, $download['total']);
            $this->assertSame('running', $download['status'], 'cursor_bytes > 0 with no seeded_at ⇒ download running');
            $this->assertFalse($download['indeterminate']);

            // The import + drain phases never advertise a fake total.
            $this->assertNull($out['bars'][1]['total']);
            $this->assertTrue($out['bars'][1]['indeterminate']);
            $this->assertNull($out['bars'][2]['total']);
            $this->assertTrue($out['bars'][2]['indeterminate']);
        });
    }

    public function test_phase_markers_stamp_started_at_and_advance_status(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $membership = ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_SYNCING,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
                'seed_total_bytes' => 0,
                'seed_cursor_bytes' => 0,
            ]);

            $progress = app(SyncProgressService::class);
            $progress->begin($membership);
            $progress->startDownload($membership);

            $download = $progress->progressFor($membership)['bars'][0];
            $this->assertSame('running', $download['status']);
            $this->assertNotNull($download['started_at'], 'starting a phase stamps started_at for the client-side ETA');

            $progress->completeDownload($membership);
            $progress->startImport($membership);
            $bars = $progress->progressFor($membership)['bars'];
            $this->assertSame('done', $bars[0]['status']);
            $this->assertSame('running', $bars[1]['status']);
        });
    }

    public function test_a_failed_phase_flips_the_lifecycle_and_carries_the_reason(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $membership = ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_SYNCING,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
            ]);

            $progress = app(SyncProgressService::class);
            $progress->begin($membership);
            $progress->fail($membership, 'Seed digest mismatch');

            $out = $progress->progressFor($membership);
            $this->assertSame('failed', $out['lifecycle']);
            $this->assertSame('Seed digest mismatch', $out['error']);
        });
    }

    public function test_the_poll_endpoint_is_citizen_public_and_returns_the_bar_contract(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_SYNCING,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
                'seed_total_bytes' => 2048,
                'seed_cursor_bytes' => 512,
            ]);

            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            $resp = $this->be($citizen, 'web')->getJson('/federation/cluster/sync-progress');

            $resp->assertOk()
                ->assertJsonPath('lifecycle', 'running')
                ->assertJsonPath('bars.0.key', SyncProgressService::PHASE_DOWNLOAD)
                ->assertJsonPath('bars.0.current', 512)
                ->assertJsonPath('bars.0.total', 2048);

            // No secret ever rides the progress payload.
            $resp->assertDontSee('token', false)
                ->assertDontSee('secret', false);
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
