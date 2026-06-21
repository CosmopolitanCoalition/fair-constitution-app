<?php

namespace Tests\Constitutional;

use App\Models\InstanceSettings;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — a first-boot federation identity mint MUST persist to the database in one pass.
 *
 * instance_settings.id carries a gen_random_uuid() DB DEFAULT. Without HasUuids on the model, the
 * create() branch of current() inserted a row whose generated id Eloquent never read back, leaving the
 * in-memory model with id=NULL — so the follow-up mint()/setEnabled() saves compiled to
 * `UPDATE … WHERE id IS NULL` and silently matched 0 rows. A cold-deploy federation:init then printed a
 * real server_id (from the in-memory object) and exited SUCCESS while the DB row stayed identity-less and
 * disabled — the node came up "not ready to federate" with /api/federation/* 404ing every peer. This pins
 * the contract directly against the DB: after a first-boot mint+enable, the row actually HOLDS the identity.
 *
 * If an edit breaks this, the edit is the violation — fix the edit (restore HasUuids), not the test.
 */
class FederationIdentityPersistenceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_identity_persist';

    public function test_a_first_boot_identity_mint_and_enable_persist_to_the_database(): void
    {
        $this->onLivePg(function () {
            // Cold-deploy state: no settings row yet, so the first current() call hits create() — the
            // exact path that lost its writes. forceDelete clears even a soft-deleted row so create() runs.
            InstanceSettings::withTrashed()->get()->each->forceDelete();

            $identity = app(InstanceIdentityService::class);
            $minted = $identity->ensureIdentity(); // create() → mint() on a brand-new row
            $identity->setEnabled(true);

            $this->assertNotNull(
                $minted->id,
                'HasUuids must assign the PK before insert — otherwise every save() UPDATEs WHERE id IS NULL',
            );

            // Assert the DB TRUTH via the raw connection, not the in-memory model (which holds the values
            // regardless of whether the UPDATE matched a row).
            $row = DB::connection(self::LIVE_CONNECTION)->table('instance_settings')
                ->whereNull('deleted_at')->first();

            $this->assertNotNull($row, 'exactly one live settings row must exist');
            $this->assertNotNull($row->server_id, 'the minted server_id must PERSIST to the DB');
            $this->assertNotNull($row->public_key, 'the public key must persist');
            $this->assertNotNull($row->private_key_encrypted, 'the encrypted secret must persist');
            $this->assertTrue((bool) $row->federation_enabled, 'federation_enabled must PERSIST');
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
