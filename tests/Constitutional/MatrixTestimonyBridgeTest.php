<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixEventSnapshot;
use App\Models\MatrixRoom;
use App\Models\PublicRecord;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\TestimonyBridgeService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-H), the testimony bridge (Plane B → Plane A). Filing a Matrix
 * #halls message as testimony seals it into the APPEND-ONLY public_records via F-SOC-002 (audit_seq
 * set), records the matrix_event_snapshots back-pointer (the record UUID, never the seq), and is
 * pseudonymous (the frozen actor_display is never the legal name). Gates checked against the REAL
 * event: own-post only (Art. I), halls only (Art. II §2). The Matrix client is mocked; the seal +
 * snapshot are real (live-pg).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MatrixTestimonyBridgeTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_testimony';

    public function test_filing_a_matrix_message_seals_it_into_the_append_only_record(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $filer = $this->resident($jur);
            $this->room($jur, MatrixRoom::SPACE_HALLS, '!halls:localhost');
            $senderMxid = app(MatrixPostingGateService::class)->matrixUserId($filer);
            $body = 'For the record: I support the plaza budget.';

            $this->mock(MatrixClientService::class, function ($m) use ($senderMxid, $body) {
                $m->shouldReceive('getEvent')->andReturn([
                    'sender'           => $senderMxid,
                    'content'          => ['body' => $body],
                    'origin_server_ts' => 1700000000000,
                ]);
                $m->shouldReceive('sendStateEvent')->andReturn(['event_id' => '$x']);
            });
            app(RoleService::class)->flush();

            $rec = app(TestimonyBridgeService::class)->fileTestimony($filer, '!halls:localhost', '$evt1');

            // Sealed, append-only public_records row (id is the UUID; seq is the int primary key).
            $record = PublicRecord::query()->where('id', $rec['record_id'])->first();
            $this->assertNotNull($record);
            $this->assertSame('testimony', $record->kind);
            $this->assertSame('F-SOC-002', $record->via_form);
            $this->assertNotNull($record->audit_seq, 'sealed to the chain');
            $this->assertSame($body, $record->body);

            // The matrix_event_snapshots back-pointer is the record UUID (not seq).
            $snap = MatrixEventSnapshot::query()->where('matrix_event_id', '$evt1')->first();
            $this->assertNotNull($snap);
            $this->assertSame($record->id, $snap->published_record_id);
            $this->assertSame((string) $rec['published_record_id'], $record->id);

            // Pseudonymity — the frozen display is never the legal name.
            $this->assertStringNotContainsString($filer->name, (string) $record->actor_display);
            $this->assertStringStartsWith('Resident-', (string) $record->actor_display);
        });
    }

    public function test_a_resident_cannot_file_anothers_matrix_message(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $filer = $this->resident($jur);
            $this->room($jur, MatrixRoom::SPACE_HALLS, '!halls:localhost');

            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('getEvent')->andReturn([
                    'sender'  => '@u-someoneelse:localhost',
                    'content' => ['body' => 'not my statement'],
                ]);
            });
            app(RoleService::class)->flush();

            $threw = false;
            try {
                app(TestimonyBridgeService::class)->fileTestimony($filer, '!halls:localhost', '$evt');
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }
            $this->assertTrue($threw, 'a resident may file only their OWN message as testimony');
        });
    }

    public function test_testimony_belongs_to_the_halls_not_the_open_square(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $filer = $this->resident($jur);
            $this->room($jur, MatrixRoom::SPACE_PUBLIC_SQUARE, '!square:localhost');
            app(RoleService::class)->flush();

            $threw = false;
            try {
                app(TestimonyBridgeService::class)->fileTestimony($filer, '!square:localhost', '$evt');
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. II §2', $e->citation);
            }
            $this->assertTrue($threw, 'testimony is filed in the halls, not the open square');
        });
    }

    private function room(string $jur, string $spaceType, string $matrixRoomId): void
    {
        MatrixRoom::query()->create([
            'matrix_room_id' => $matrixRoomId,
            'room_type'      => MatrixRoom::ROOM_COMMONS,
            'room_version'   => '12',
            'entity_type'    => 'jurisdiction',
            'entity_id'      => $jur,
            'space_type'     => $spaceType,
            'is_public'      => true,
        ]);
    }

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        return (string) $id;
    }

    private function resident(string $jurisdictionId): User
    {
        $user = User::create([
            'name'              => 'K3 Halls Resident '.Str::uuid(),
            'email'             => 'k3h-'.Str::uuid().'@test.invalid',
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
