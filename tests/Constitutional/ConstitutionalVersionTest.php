<?php

namespace Tests\Constitutional;

use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Services\ConstitutionalVersionService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — G-VER `constitutional_version` is DERIVED from the
 * hardened-compute surface, so it cannot drift from reality: a change to HOW the
 * constitution counts changes the version automatically (no separate "bump" to
 * forget — the R1 mitigation). Pins: the derivation is stable + deterministic +
 * covers the real hardened files; the version rides the handshake; peers pin it.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ConstitutionalVersionTest extends TestCase
{
    use FederationSyncSupport;

    public function test_version_is_derived_stable_and_covers_the_hardened_surface(): void
    {
        $svc = app(ConstitutionalVersionService::class);

        $v = $svc->derive();
        $this->assertStringStartsWith('cv1.', $v);
        $this->assertSame(36, strlen($v), 'cv1. + 32 hex');

        // Deterministic: forgetting the memo and recomputing yields the same hash.
        $svc->forget();
        $this->assertSame($v, $svc->derive(), 'derivation is stable across recompute');

        // The manifest resolves to the real hardened files (a rename/typo would drop
        // a file from the hash → a silent false-negative; this catches it).
        $files = $svc->surfaceFiles();
        $this->assertGreaterThanOrEqual(6, count($files));
        $this->assertContains('app/Services/VoteCountingService.php', $files);
        $this->assertContains('app/Services/ConstitutionalValidator.php', $files);
        $this->assertNotEmpty(array_filter($files, fn ($f) => str_starts_with($f, 'app/Domain/Counting/')), 'the counting core is in the surface');
        foreach ($files as $f) {
            $this->assertFileExists(base_path($f), "hardened-surface file missing: {$f}");
        }
    }

    public function test_handshake_and_settings_carry_the_version(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $derived = app(ConstitutionalVersionService::class)->derive();

            $payload = $identity->handshakePayload();
            $this->assertArrayHasKey('schema_version', $payload);
            $this->assertArrayHasKey('app_release', $payload);
            $this->assertSame($derived, $payload['constitutional_version'], 'handshake carries the derived version');

            // Unpinned → derived; pinned → the agreed value.
            $this->assertSame($derived, InstanceSettings::current()->constitutionalVersion());
            InstanceSettings::current()->pinConstitutionalVersion('cv1.pinnedfortest');
            $this->assertSame('cv1.pinnedfortest', InstanceSettings::current()->constitutionalVersion());
        });
    }

    public function test_upsert_trusted_peer_pins_the_peer_version(): void
    {
        $this->onLivePg(function () {
            $serverId = (string) Str::uuid();
            $pub = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL);

            $peer = app(PeerService::class)->upsertTrustedPeer($serverId, $pub, [
                'url' => 'https://peer.example',
                'schema_version' => '1',
                'constitutional_version' => 'cv1.peervalue',
                'app_release' => 'v2.3.4',
            ]);

            $fresh = FederationPeer::query()->where('server_id', $serverId)->firstOrFail();
            $this->assertSame('cv1.peervalue', $fresh->constitutional_version);
            $this->assertSame('v2.3.4', $fresh->app_release);
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg('pgsql_cv');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_cv');
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
