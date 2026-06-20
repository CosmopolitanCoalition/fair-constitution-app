<?php

namespace Tests\Constitutional;

use App\Services\Federation\BrokerCredentialService;
use App\Services\Federation\CapabilityProber;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the operator broker-credential store. The Cloudflare DNS-edit token a broker box
 * needs is dropped through the operator console and stored LOCALLY, encrypted at rest, in storage (which
 * the FF&C sync never touches). THE INVARIANTS: the token round-trips for the broker's own use
 * (tokenFor) but status() — the UI surface — NEVER carries the token value; a stored credential makes the
 * box qualify for broker.dns; forget removes it. The token never federates, never renders, never logs.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class BrokerCredentialTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_brokercred';
    private const DOMAIN = 'cred-test.example';
    private const TOKEN = 'cf-secret-token-value-must-never-leak';

    protected function tearDown(): void
    {
        @unlink(storage_path('app/broker/credentials.json')); // the store is a file, not in the rolled-back DB
        parent::tearDown();
    }

    public function test_the_token_stores_for_broker_use_but_never_appears_in_the_ui_status(): void
    {
        $this->onLivePg(function () { // setCredential audits via the hash chain (pg advisory lock)
        $svc = app(BrokerCredentialService::class);
        @unlink(storage_path('app/broker/credentials.json'));

        $svc->setCredential(self::DOMAIN, 'zone-123', self::TOKEN);

        // The broker can read it back (for its own Cloudflare calls).
        $this->assertSame(self::TOKEN, $svc->tokenFor(self::DOMAIN));
        $this->assertSame('zone-123', $svc->zoneFor(self::DOMAIN));
        $this->assertContains(self::DOMAIN, $svc->domains());

        // The UI surface NEVER carries the token — not the value, not the ciphertext.
        $status = $svc->status();
        $encoded = json_encode($status);
        $this->assertStringNotContainsString(self::TOKEN, (string) $encoded, 'the plaintext token must never ride the status');
        $this->assertStringNotContainsString('token', (string) $encoded, 'status exposes no token field at all');
        $this->assertSame(self::DOMAIN, $status[0]['domain']);
        $this->assertTrue($status[0]['configured']);

        // At rest the file holds only ciphertext, never the plaintext token.
        $onDisk = (string) @file_get_contents(storage_path('app/broker/credentials.json'));
        $this->assertStringNotContainsString(self::TOKEN, $onDisk, 'the token is encrypted at rest');

        $svc->forget(self::DOMAIN);
        $this->assertNotContains(self::DOMAIN, $svc->domains());
        });
    }

    public function test_a_dropped_credential_qualifies_the_box_for_broker_dns(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            config(['services.cloudflare.dns_token' => '']); // no env fallback — the store must be what qualifies
            @unlink(storage_path('app/broker/credentials.json'));

            $prober = app(CapabilityProber::class);
            $this->assertFalse($prober->probe('broker.dns')['ok'], 'no credential ⇒ broker.dns does not qualify');

            app(BrokerCredentialService::class)->setCredential(self::DOMAIN, 'zone-123', self::TOKEN);
            $this->assertTrue($prober->probe('broker.dns')['ok'], 'a dropped credential qualifies broker.dns');
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
