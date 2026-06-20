<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Legislature;
use App\Models\MatrixCarveoutLog;
use App\Models\OperatorAccount;
use App\Models\PublicRecord;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\Matrix\CarveoutEmitterService;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\ModerationFlipService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-I.3), the Plane-B (Matrix) carve-out emitter. A Matrix removal is
 * exactly as constrained as a public-square removal: it requires the F-SOC-003 SHAPE (a named carve-out
 * + a justifying reference; viewpoint is unrepresentable) AND the flip AUTHORITY (operator-relay in
 * bootstrap vs. live R-19/R-20 judicial attestation once seated). M-3 per-user block has no path here.
 * The redaction is best-effort — a down homeserver never voids the sealed carve-out log. The Matrix
 * client is mocked; the seal is real (live-pg).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MatrixCarveoutEmitterTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_emit';
    private const ROOM = '!commons:localhost';

    public function test_a_matrix_carveout_requires_the_f_soc_003_shape(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);
            $this->mockClient();
            $svc = app(CarveoutEmitterService::class);
            $att = $this->judicialAttestation();

            // A viewpoint "carve-out" is structurally unrepresentable (reuses checkSocialRemoval).
            $this->assertRefused(fn () => $svc->emit($jur, self::ROOM, '$e1', 'values', 'we dislike it', $att));
            // A removal with no justifying order reference is refused.
            $this->assertRefused(fn () => $svc->emit($jur, self::ROOM, '$e2', 'judicial_order', '', $att));

            // Nothing was logged — a refused carve-out is never an action.
            $this->assertSame(0, MatrixCarveoutLog::query()->where('matrix_room_id', self::ROOM)->count());
        });
    }

    public function test_post_flip_a_judicial_attestation_redacts_with_the_right_action_class(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);
            $this->mockClient(expectRedact: true);
            $svc = app(CarveoutEmitterService::class);
            $att = $this->judicialAttestation();

            // M-2 rights protection → hard redact (content-stripping).
            $m2 = $svc->emit($jur, self::ROOM, '$rights', 'rights_protection', 'order:DOXX-1', $att);
            $this->assertSame('m2_rights', $m2->carve_out);
            $this->assertSame(MatrixCarveoutLog::ACTION_HARD_REDACT, $m2->action);
            $this->assertSame((string) $att->id, (string) $m2->attestation_id, 'a judicial order records its attestation');
            $this->assertTrue((bool) $m2->is_seated_at_time);
            $this->assertSame('moderation_flip', PublicRecord::query()->where('id', $m2->public_records_id)->value('kind'));

            // M-1 judicial order → soft fail (reversible).
            $m1 = $svc->emit($jur, self::ROOM, '$order', 'judicial_order', 'case:2026-007', $att);
            $this->assertSame('m1_judicial', $m1->carve_out);
            $this->assertSame(MatrixCarveoutLog::ACTION_SOFT_FAIL, $m1->action);
        });
    }

    public function test_bootstrap_operator_relays_m2_but_m1_is_unavailable(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $jur = $this->aJurisdiction();          // unseated
            $this->mockClient();
            $svc = app(CarveoutEmitterService::class);
            $op = $this->operator();

            // M-2 rights → operator-board relay; redacts + logs, but attestation_id stays NULL.
            $m2 = $svc->emit($jur, self::ROOM, '$boot', 'rights_protection', 'doxx report #4', null, $op);
            $this->assertSame('m2_rights', $m2->carve_out);
            $this->assertNull($m2->attestation_id, 'an operator relay can never be mistaken for a judicial order');
            $this->assertFalse((bool) $m2->is_seated_at_time);

            // M-1 judicial → unavailable before a government is seated (no judge).
            $this->assertRefused(fn () => $svc->emit($jur, self::ROOM, '$noJudge', 'judicial_order', 'case:x', null, $op));
        });
    }

    public function test_m3_per_user_block_is_never_an_appservice_action(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);

            // There is no carve-out class that maps an m.ignored_user_list to an appservice removal —
            // the flip resolver refuses it outright as client-side.
            $decision = app(ModerationFlipService::class)->resolve($jur, 'm3_block', $this->judicialAttestation());
            $this->assertFalse($decision->permitted);
            $this->assertSame('client_side', $decision->basis);
        });
    }

    public function test_a_down_homeserver_never_voids_the_sealed_carveout(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);
            // The homeserver is unreachable — redact throws.
            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('redact')->andThrow(new \RuntimeException('homeserver down'));
            });
            app(RoleService::class)->flush();
            $svc = app(CarveoutEmitterService::class);

            $log = $svc->emit($jur, self::ROOM, '$down', 'judicial_order', 'case:99', $this->judicialAttestation());

            // The seal landed even though the bytes did not disappear — the log is the durable artifact.
            $this->assertNotNull($log->id);
            $this->assertNotNull(PublicRecord::query()->where('id', $log->public_records_id)->value('audit_seq'));
        });
    }

    public function test_m4_antispam_is_content_neutral_on_either_side_of_the_flip(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $this->mockClient();
            $svc = app(CarveoutEmitterService::class);

            $bootJur = $this->aJurisdiction();
            $boot = $svc->emitAntispam($bootJur, self::ROOM, '$spam1', $this->operator());
            $this->assertSame('m4_antispam', $boot->carve_out);
            $this->assertSame(MatrixCarveoutLog::ACTION_SOFT_FAIL, $boot->action);
            $this->assertNull($boot->attestation_id, 'anti-spam is behaviour-based, never a judicial order');

            $seatedJur = $this->aSecondJurisdiction($bootJur);
            $this->seatLegislature($seatedJur);
            $seated = $svc->emitAntispam($seatedJur, self::ROOM, '$spam2');
            $this->assertSame(MatrixCarveoutLog::ACTION_SOFT_FAIL, $seated->action);
            $this->assertTrue((bool) $seated->is_seated_at_time, 'the knobs are owned by the legislature once seated');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assertRefused(callable $fn): void
    {
        $threw = false;
        try {
            $fn();
        } catch (ConstitutionalViolation $e) {
            $threw = true;
            $this->assertSame('Art. I', $e->citation);
        }
        $this->assertTrue($threw, 'the carve-out should have been refused');
    }

    private function mockClient(bool $expectRedact = false): void
    {
        $this->mock(MatrixClientService::class, function ($m) use ($expectRedact) {
            $exp = $m->shouldReceive('redact')->andReturn(['event_id' => '$redaction']);
            if ($expectRedact) {
                $exp->atLeast()->once();
            }
        });
        app(RoleService::class)->flush();
    }

    private function judicialAttestation(): StandingAttestation
    {
        $identity = app(InstanceIdentityService::class);
        $svc = app(AttestationService::class);
        $user = $this->localUser();

        $att = new StandingAttestation([
            'id'                => (string) Str::uuid(),
            'subject_user_id'   => (string) $user->getKey(),
            'device_public_key' => 'dpk-'.Str::random(8),
            'issuer_server_id'  => $identity->serverId(),
            'roles'             => ['R-19'],
            'issued_at'         => now(),
            'expires_at'        => now()->addHour(),
        ]);
        $att->signature = $identity->sign($svc->attestationCanonical($att));
        $att->save();

        return $att;
    }

    private function seatLegislature(string $jurisdictionId): void
    {
        Legislature::create([
            'id'              => (string) Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'term_number'     => 1,
            'status'          => Legislature::STATUS_ACTIVE,
            'total_seats'     => 5,
            'type_a_seats'    => 5,
            'type_b_seats'    => 0,
            'quorum_required' => 3,
        ]);
    }

    private function operator(): OperatorAccount
    {
        return OperatorAccount::create([
            'server_id' => (string) Str::uuid(),
            'username'  => 'op-'.Str::random(8),
            'password'  => Str::random(32),
            'status'    => OperatorAccount::STATUS_ACTIVE,
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

    private function aSecondJurisdiction(string $notThis): string
    {
        $id = DB::table('jurisdictions')->whereNull('deleted_at')->where('id', '!=', $notThis)->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has only one jurisdiction.');
        }

        return (string) $id;
    }

    private function localUser(): User
    {
        $user = User::create([
            'name'              => 'K3 Emit '.Str::uuid(),
            'email'             => 'k3emit-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
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
