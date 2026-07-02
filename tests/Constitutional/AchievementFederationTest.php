<?php

namespace Tests\Constitutional;

use App\Models\SyncLogEntry;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase 4 close-out: achievements ride Full Faith & Credit under
 * APPEND-ANY-VERIFIED (operator-settled 2026-07-02, PHASE_4_DESIGN_peerage §5.2).
 * A medal is a per-USER fact about play wherever it happened: export ships only
 * locally-originated sealed rows (source_server_id NULL + audit_seq set); ingest
 * applies any such row from a signature/chain-verified tail with NO jurisdiction
 * authority check and NO users.home_server_id gate, idempotent on both the origin id
 * and the (user_id, journey_id) pair. Medals grant no power — nothing here feeds any
 * capability, vote, or seat.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AchievementFederationTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_achievement_fed';

    public function test_a_sealed_medal_rides_the_tail_and_a_mirrored_one_never_re_exports(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $user = $this->player();

            // Mint exactly as JourneyService::recordAchievement does — seal first,
            // then the ledger row carrying the seal's seq.
            $entry = app(AuditService::class)->append(
                module: 'journeys',
                event: 'achievement/earned',
                payload: ['journey_id' => 'fed-pin-journey', 'title' => 'Federation pin'],
                ref: null,
                actorId: (string) $user->id,
            );
            DB::table('achievements')->insertOrIgnore([
                'user_id' => (string) $user->id,
                'journey_id' => 'fed-pin-journey',
                'title' => 'Federation pin',
                'audit_seq' => $entry->seq,
                'earned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // A FOREIGN medal already mirrored here (audit_seq NULL) must never re-export.
            $foreignId = (string) Str::uuid();
            DB::table('achievements')->insertOrIgnore([
                'id' => $foreignId,
                'user_id' => (string) Str::uuid(),
                'journey_id' => 'fed-pin-foreign',
                'title' => 'Foreign medal',
                'audit_seq' => null,
                'source_server_id' => (string) Str::uuid(),
                'earned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tail = app(FederationSyncService::class)->buildAuditTail((int) $entry->seq - 1);

            $shipped = collect($tail['achievements'])->firstWhere('user_id', (string) $user->id);
            $this->assertNotNull($shipped, 'a sealed medal is selected by the FF&C tail');
            $this->assertSame('fed-pin-journey', $shipped['journey_id']);
            $this->assertSame('Federation pin', $shipped['title']);
            $this->assertArrayNotHasKey('audit_seq', $shipped, 'seal seq is exporter-local');
            $this->assertArrayNotHasKey('source_server_id', $shipped, 'origin marking is exporter-local');

            $this->assertNull(
                collect($tail['achievements'])->firstWhere('id', $foreignId),
                'a mirrored foreign medal never re-exports (no ping-pong)'
            );
        });
    }

    public function test_an_inbound_peer_medal_appends_with_no_authority_gate(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();

            // Deliberately NO jurisdiction delegation and a user id that does not
            // exist locally — append-any-verified means neither matters.
            $medal = [
                'id' => (string) Str::uuid(),
                'user_id' => (string) Str::uuid(),
                'journey_id' => 'peer-journey',
                'title' => 'Earned on Peer A',
                'earned_at' => (string) now(),
            ];

            $tail = $this->craftTail(DB::connection(self::LIVE_CONNECTION), $peer, []);
            $tail['achievements'] = [$medal];
            $tail = $this->signTail($tail);

            $log = app(FederationSyncService::class)->ingestTail($peer, $tail);

            $this->assertSame(SyncLogEntry::RESULT_APPLIED, $log->result, 'a pure-medal tail is an APPLIED exchange');
            $this->assertContains($medal['id'], $log->detail['achievements_applied'] ?? [], 'ledgered on the sync log');

            $row = DB::table('achievements')->where('id', $medal['id'])->first();
            $this->assertNotNull($row, 'the peer medal landed');
            $this->assertSame($peer->server_id, $row->source_server_id, 'marked foreign');
            $this->assertNull($row->audit_seq, 'a foreign medal is not in OUR chain');

            // Replay of the identical signed tail: nothing doubles, nothing aborts.
            $replay = app(FederationSyncService::class)->ingestTail($peer, $tail);
            $this->assertNotSame(SyncLogEntry::RESULT_REJECTED_TAMPER, $replay->result);
            $this->assertSame([], $replay->detail['achievements_applied'] ?? [], 'replay applies nothing new');
            $this->assertSame(1, DB::table('achievements')->where('id', $medal['id'])->count());

            // A DIFFERENT origin id for the SAME (user, journey) — the partial-unique
            // pair collapses cross-node double-earns to first-arrival-wins.
            $double = array_merge($medal, ['id' => (string) Str::uuid()]);
            $tail2 = $this->craftTail(DB::connection(self::LIVE_CONNECTION), $peer, []);
            $tail2['achievements'] = [$double];
            app(FederationSyncService::class)->ingestTail($peer, $this->signTail($tail2));

            $this->assertSame(
                1,
                DB::table('achievements')
                    ->where('user_id', $medal['user_id'])->where('journey_id', 'peer-journey')
                    ->whereNull('deleted_at')->count(),
                'one medal per person per journey, mesh-wide'
            );
        });
    }

    public function test_a_tampered_medal_tail_lands_nothing(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();

            $medal = [
                'id' => (string) Str::uuid(),
                'user_id' => (string) Str::uuid(),
                'journey_id' => 'tamper-journey',
                'title' => 'Legit title',
                'earned_at' => (string) now(),
            ];

            $tail = $this->craftTail(DB::connection(self::LIVE_CONNECTION), $peer, []);
            $tail['achievements'] = [$medal];
            $tail = $this->signTail($tail);

            // Mutate AFTER signing — the signature covers the achievements key.
            $tail['achievements'][0]['title'] = 'Forged title';

            $log = app(FederationSyncService::class)->ingestTail($peer, $tail);

            $this->assertSame(SyncLogEntry::RESULT_REJECTED_TAMPER, $log->result);
            $this->assertSame(0, DB::table('achievements')->where('id', $medal['id'])->count(), 'nothing landed');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function player(): User
    {
        return User::create([
            'name' => 'Achievement Fed Tester',
            'email' => 'achfed-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
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
