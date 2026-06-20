<?php

namespace Tests\Constitutional;

use App\Models\Legislature;
use App\Models\MatrixCarveoutLog;
use App\Models\OperatorAccount;
use App\Models\PublicRecord;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\Matrix\ModerationFlipService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-I.2), the legitimacy-gated moderation FLIP. The carve-out authority
 * is a FUNCTION OF LOCAL SEATEDNESS, derived live and flipped automatically: below the flip the operator
 * board (R-08) relays M-2 and M-1 is unavailable (no judge); the instant an active legislature governs
 * the jurisdiction, a carve-out is admitted ONLY under a live R-19/R-20 judicial attestation (fails
 * closed) and the operator is no longer honoured. The flip NEVER moves a Matrix power level (the v12
 * appservice creator stays sole holder — the service has no Matrix client). logFlip seals BOTH logs;
 * attestation_id discriminates a real judicial order from an operator relay.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ModerationFlipTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_flip';

    public function test_below_the_flip_the_operator_relays_m2_and_m1_is_unavailable(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $jur = $this->aJurisdiction();                 // no active legislature → bootstrap
            $svc = app(ModerationFlipService::class);
            $operator = $this->operator();

            $this->assertFalse($svc->isSeated($jur), 'no active legislature ⇒ bootstrap');

            // M-2 rights → operator-board relay, NEUTRAL + logged, attestation_id NULL.
            $m2 = $svc->resolve($jur, 'm2_rights', null, $operator);
            $this->assertTrue($m2->permitted);
            $this->assertSame('operator_relay', $m2->basis);
            $this->assertNull($m2->attestationId, 'an operator relay is never a judicial order');
            $this->assertNotNull($m2->operatorAccountId);

            // M-1 judicial → UNAVAILABLE before a government is seated (no judge).
            $m1 = $svc->resolve($jur, 'm1_judicial', null, $operator);
            $this->assertFalse($m1->permitted);
            $this->assertSame('unavailable_no_judge', $m1->basis);

            // An inactive / absent operator cannot relay even M-2.
            $this->assertFalse($svc->resolve($jur, 'm2_rights', null, null)->permitted);
        });
    }

    public function test_the_instant_a_legislature_is_seated_m1_requires_a_judicial_attestation(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);                  // the flip
            $svc = app(ModerationFlipService::class);

            $this->assertTrue($svc->isSeated($jur), 'an active legislature governs ⇒ seated');

            // The operator is NO LONGER honoured for M-1 once seated.
            $this->assertFalse($svc->resolve($jur, 'm1_judicial', null, $this->operator())->permitted,
                'post-flip the operator board cannot order an M-1 removal');

            // A live, valid R-19 judicial attestation IS honoured.
            $att = $this->judicialAttestation($jur);
            $m1 = $svc->resolve($jur, 'm1_judicial', $att, null);
            $this->assertTrue($m1->permitted);
            $this->assertSame('judicial_attested', $m1->basis);
            $this->assertSame((string) $att->id, $m1->attestationId, 'the honoured attestation is recorded');
        });
    }

    public function test_attestation_verification_fails_closed(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $svc = app(ModerationFlipService::class);
            $att = app(AttestationService::class);
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);
            $user = $this->localUser();

            // (a) A non-judicial attestation (no R-19/R-20) is refused even if genuinely signed.
            $civilian = $this->signedAttestation($user, ['R-01'], now()->addHour());
            $this->assertFalse($svc->resolve($jur, 'm1_judicial', $civilian, null)->permitted,
                'a civilian standing snapshot cannot order a judicial removal');

            // (b) A genuinely-signed but EXPIRED judicial attestation fails closed.
            $expired = $this->signedAttestation($user, ['R-19'], now()->subHour(), now()->subHours(2));
            $this->assertFalse($svc->resolve($jur, 'm1_judicial', $expired, null)->permitted, 'expired ⇒ refused');

            // (c) A REVOKED judicial attestation fails closed.
            $revoked = $this->signedAttestation($user, ['R-19'], now()->addHour());
            $att->revoke($revoked, 'test');
            $this->assertFalse($svc->resolve($jur, 'm1_judicial', $revoked, null)->permitted, 'revoked ⇒ refused');

            // (d) A FORGED signature (mutated roles after signing) fails closed.
            $forged = $this->signedAttestation($user, ['R-01'], now()->addHour());
            $forged->roles = ['R-19']; // claim judicial without re-signing
            $this->assertFalse($svc->resolve($jur, 'm1_judicial', $forged, null)->permitted, 'forged ⇒ refused');
        });
    }

    public function test_logflip_seals_both_logs_and_the_power_level_never_moves(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);
            $svc = app(ModerationFlipService::class);

            $att = $this->judicialAttestation($jur);
            $decision = $svc->resolve($jur, 'm1_judicial', $att, null);
            $this->assertTrue($decision->permitted);

            $log = $svc->logFlip($decision, '!room:localhost', '$evt-flip');

            // The machine audit row — attestation_id set (a real judicial order), seated snapshot true.
            $this->assertSame('m1_judicial', $log->carve_out);
            $this->assertSame(MatrixCarveoutLog::ACTION_SOFT_FAIL, $log->action);
            $this->assertSame((string) $att->id, (string) $log->attestation_id);
            $this->assertTrue((bool) $log->is_seated_at_time);

            // The citizen-readable register row, sealed to the hash chain.
            $record = PublicRecord::query()->where('id', $log->public_records_id)->first();
            $this->assertNotNull($record);
            $this->assertSame('moderation_flip', $record->kind);
            $this->assertNotNull($record->audit_seq, 'sealed to the chain');

            // THE GUARDRAIL: the flip is an authority decision only — it can NEVER move a Matrix power
            // level. The service has no Matrix client dependency at all, so a power-level mutation is
            // structurally impossible (the v12 appservice creator stays the sole holder).
            $ctor = (new ReflectionClass(ModerationFlipService::class))->getConstructor();
            foreach ($ctor->getParameters() as $p) {
                $type = $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : '';
                $this->assertStringNotContainsString('MatrixClientService', $type,
                    'ModerationFlipService must hold no Matrix client — it cannot move a power level');
            }
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function signedAttestation(User $user, array $roles, $expiresAt, $issuedAt = null): StandingAttestation
    {
        $identity = app(InstanceIdentityService::class);
        $svc = app(AttestationService::class);

        $att = new StandingAttestation([
            'id'                => (string) Str::uuid(),
            'subject_user_id'   => (string) $user->getKey(),
            'device_public_key' => 'dpk-'.Str::random(8),
            'issuer_server_id'  => $identity->serverId(),
            'roles'             => $roles,
            'issued_at'         => $issuedAt ?? now(),
            'expires_at'        => $expiresAt,
        ]);
        $att->signature = $identity->sign($svc->attestationCanonical($att));
        $att->save();

        return $att;
    }

    private function judicialAttestation(string $jur): StandingAttestation
    {
        return $this->signedAttestation($this->localUser(), ['R-19'], now()->addHour());
    }

    private function seatLegislature(string $jurisdictionId): Legislature
    {
        return Legislature::create([
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

    private function localUser(): User
    {
        $user = User::create([
            'name'              => 'K3 Flip '.Str::uuid(),
            'email'             => 'k3flip-'.Str::uuid().'@test.invalid',
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
