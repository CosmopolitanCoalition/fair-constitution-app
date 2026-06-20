<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\FederationPeer;
use App\Models\PublicRecord;
use App\Models\SyncLogEntry;
use App\Models\User;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (closeout): a sealed testimony rides Full Faith & Credit. The K-1
 * civic-record plane and the F2 federation plane meet here: an F-SOC-002 testimony is an ordinary
 * append-only public_record (kind='testimony', audit_seq set, source_server_id NULL), so it is
 * SELECTED by buildAuditTail and replicates untouched — pseudonymously (actor_display is the handle,
 * never the legal name; the actor is a uuid). The inbound half mirrors a peer's testimony under
 * authoritative-wins + ledgers it. The LOCAL-ONLY social graph (social_posts) never enters the tail —
 * only the sealed public_records do.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class TestimonyFederationTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_k1_testimony_fed';

    public function test_a_sealed_testimony_rides_the_ff_and_c_tail_pseudonymously(): void
    {
        $this->onLivePg(function () use (&$pseudonym) {
            $jur = $this->aJurisdiction();
            $resident = $this->resident($jur, 'Federation LEGALNAME Tester');
            $pseudonym = 'Resident-fedpin01';

            // Seal a real testimony via the F-SOC-002 Matrix-origin path (the bridge's engine call).
            $result = app(ConstitutionalEngine::class)->file('F-SOC-002', $resident, [
                'matrix_event_id'  => '$fedtestimony1',
                'matrix_room_id'   => '!halls:localhost',
                'body_snapshot'    => 'For the record: I support the plaza budget.',
                'actor_display'    => $pseudonym,
                'jurisdiction_id'  => $jur,
                'origin_server_ts' => 1700000000000,
            ]);

            $record = PublicRecord::query()->where('id', $result->recorded['record_id'])->first();
            $this->assertSame('testimony', $record->kind);
            $this->assertNotNull($record->audit_seq, 'sealed to the chain');

            // buildAuditTail (the FF&C exporter) SELECTS the testimony record by its audit_seq.
            $tail = app(FederationSyncService::class)->buildAuditTail((int) $record->audit_seq - 1);
            $shipped = collect($tail['records'])->firstWhere('id', $record->id);

            $this->assertNotNull($shipped, 'a sealed testimony is selected by the FF&C tail');
            $this->assertSame('testimony', $shipped['kind']);
            $this->assertSame('F-SOC-002', $shipped['via_form']);
            $this->assertSame($pseudonym, $shipped['actor_display'], 'the exported display is the pseudonym');
            $this->assertStringNotContainsString('LEGALNAME', (string) $shipped['actor_display'], 'never the legal name');
            $this->assertStringNotContainsString('LEGALNAME', (string) ($shipped['body'] ?? ''));

            // The local-only social graph never federates — buildAuditTail ships public_records only.
            foreach ($tail['records'] as $r) {
                $this->assertArrayNotHasKey('social_post_id', $r);
            }
        });
    }

    public function test_an_inbound_peer_testimony_mirrors_and_is_ledgered(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();

            // Delegate a jurisdiction to the peer so its record mirrors under authoritative-wins.
            $peerOwns = (string) DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
            if ($peerOwns === '') {
                $this->markTestSkipped('Live DB needs a jurisdiction.');
            }
            DB::table('jurisdictions')->where('id', $peerOwns)->update(['authoritative_server_id' => $peer->server_id]);

            // A peer testimony record — pseudonymous, F-SOC-002, kind='testimony'.
            $record = array_merge($this->record($peerOwns, 'testimony'), [
                'actor_display' => 'Resident-peer42',
                'via_form'      => 'F-SOC-002',
                'body'          => 'Peer testimony: recognized under Full Faith & Credit.',
            ]);
            $tail = $this->signTail($this->craftTail(DB::connection(self::LIVE_CONNECTION), $peer, [$record]));

            $entry = app(FederationSyncService::class)->ingestTail($peer, $tail);
            $this->assertSame(SyncLogEntry::RESULT_APPLIED, $entry->result, 'the peer testimony applied');

            $mirrored = PublicRecord::query()->where('id', $record['id'])->first();
            $this->assertNotNull($mirrored, 'the peer testimony is mirrored');
            $this->assertSame('testimony', $mirrored->kind);
            $this->assertSame($peer->server_id, $mirrored->source_server_id, 'mirrored as foreign');
            $this->assertStringNotContainsString('LEGALNAME', (string) $mirrored->actor_display);

            // Ledgered as an inbound APPLIED exchange.
            $this->assertTrue(
                SyncLogEntry::query()->where('peer_id', $peer->id)
                    ->where('direction', SyncLogEntry::DIRECTION_INBOUND)
                    ->where('result', SyncLogEntry::RESULT_APPLIED)->exists(),
                'the exchange is on the sync ledger'
            );
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        return (string) $id;
    }

    private function resident(string $jurisdictionId, string $legalName): User
    {
        $user = User::create([
            'name'              => $legalName,
            'email'             => 'k1fed-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        DB::table('residency_confirmations')->insert([
            'id'              => (string) Str::uuid(),
            'user_id'         => $user->id,
            'jurisdiction_id' => $jurisdictionId,
            'days_confirmed'  => 30,
            'confirmed_at'    => now(),
            'is_active'       => true,
            'depth'           => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        app(RoleService::class)->flush();

        return $user;
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
