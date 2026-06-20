<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\LegalComplianceRemoval;
use App\Models\Legislature;
use App\Models\MatrixCarveoutLog;
use App\Models\MatrixServerAcl;
use App\Models\OperatorAccount;
use App\Models\PublicRecord;
use App\Models\User;
use App\Services\Matrix\LegalComplianceService;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\Scan\LocalHashListScanProvider;
use App\Services\Matrix\Scan\MediaAdmissionGate;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-I.4), the M-5 PHYSICAL-LAW legal-compliance floor. A content-NEUTRAL
 * removal of ILLEGAL material (CSAM, a specific court order, a true threat) — a different axis from the
 * four VIEWPOINT carve-outs, off the constitutional plane, operator-authenticated, CODE-HARDENED. These
 * pins are the nine §C guardrails of K3I-CARVEOUTS-FLIP-DESIGN: a viewpoint basis is unrepresentable;
 * per-item only; M-5 never writes a server ACL; attestation_id is always NULL; no hash ever rides a log;
 * the scanner is content-neutral; CSAM survives the flip; purge is reserved to CSAM and DELETEs; and
 * every m5_legal log has a matching evidence trail. M-5 flips to BOTH once seated (the disclosure referral).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class LegalComplianceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_legal_floor';
    private const ROOM = '!commons:localhost';

    /** G1 + G4 + G8 + G9: a CSAM removal purges, logs NULL attestation, and writes a matching trail. */
    public function test_a_csam_removal_purges_the_bytes_and_seals_an_immutable_trail(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $op = $this->operator();
            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('purgeEvent')->once()->andReturn(['event_id' => '$purged']); // G8: purge actually fires
                $m->shouldReceive('redact')->never();
            });
            app(RoleService::class)->flush();

            $out = app(LegalComplianceService::class)->remove(
                $op, self::ROOM, '$csam', LegalComplianceRemoval::BASIS_CSAM_HASHMATCH, $jur, null, 'local-hash-list'
            );

            // The immutable evidence trail (no soft-deletes) — the durable §2258A artifact.
            $removal = LegalComplianceRemoval::query()->where('id', $out['removal_id'])->first();
            $this->assertNotNull($removal);
            $this->assertSame('csam_hashmatch', $removal->legal_basis);
            $this->assertSame('purge', $removal->action);                       // G8
            $this->assertSame((string) $op->getKey(), (string) $removal->operator_account_id);
            $this->assertSame('local-hash-list', $removal->matched_list_source);

            // G4: the matrix_carveout_log row is m5_legal with attestation_id ALWAYS NULL (un-forgeable
            // as a judicial M-1), and G9: it has a matching legal_compliance_removals row.
            $log = MatrixCarveoutLog::query()->where('matrix_event_id', '$csam')->first();
            $this->assertSame(MatrixCarveoutLog::CARVE_M5_LEGAL, $log->carve_out);
            $this->assertNull($log->attestation_id, 'an M-5 action can never be forged as a judicial order');
            $this->assertSame(
                1,
                LegalComplianceRemoval::query()->where('matrix_event_id', $log->matrix_event_id)->count(),
                'G9: every m5_legal log has a matching evidence trail (no censorship without a record)'
            );

            // G5: the published transparency record names the basis + list SOURCE, never a hash/locator.
            $record = PublicRecord::query()->where('id', $removal->public_records_id)->first();
            $this->assertSame('legal_compliance_removal', $record->kind);
            $this->assertNotNull($record->audit_seq, 'sealed to the chain');
            $this->assertStringNotContainsString('hash:', strtolower((string) $record->body));
        });
    }

    /** G3: the M-5 floor NEVER writes a server ACL — a jurisdiction's illegal content can't silence it. */
    public function test_m5_never_writes_a_server_acl(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $op = $this->operator();
            $this->mock(MatrixClientService::class, fn ($m) => $m->shouldReceive('purgeEvent')->andReturn([]));
            app(RoleService::class)->flush();

            app(LegalComplianceService::class)->remove(
                $op, self::ROOM, '$x', LegalComplianceRemoval::BASIS_CSAM_HASHMATCH, $jur
            );

            $this->assertSame(0, MatrixServerAcl::query()->where('matrix_room_id', self::ROOM)->count(),
                'removing one illegal item must never ACL-ban a whole server');
        });
    }

    /** G1: a viewpoint / discretionary basis is structurally unrepresentable (the closed enum). */
    public function test_a_viewpoint_basis_is_refused(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator();
            foreach (['hate_speech', 'offensive', 'misinformation', 'values'] as $viewpoint) {
                $this->assertRefused(fn () => $this->fileRaw($op, ['legal_basis' => $viewpoint, 'action' => 'hard_redact']));
            }
        });
    }

    /** G2: per-item only — a removal with no specific event target is refused. */
    public function test_a_classwide_removal_is_refused(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator();
            $this->assertRefused(fn () => $this->fileRaw($op, [
                'legal_basis' => 'true_threat', 'action' => 'hard_redact', 'matrix_event_id' => '',
            ]));
        });
    }

    /** G8: the byte-destroying purge is reserved to a CSAM hash-match. */
    public function test_purge_is_reserved_to_csam(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator();
            $this->assertRefused(fn () => $this->fileRaw($op, [
                'legal_basis' => 'court_order_specific', 'action' => 'purge',
            ]));
        });
    }

    /** G5: a CSAM hash/locator may never ride the filing. */
    public function test_no_hash_may_ride_the_filing(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator();
            foreach (['hash', 'media_hash', 'locator', 'sha256', 'photodna'] as $forbidden) {
                $this->assertRefused(fn () => $this->fileRaw($op, [
                    'legal_basis' => 'csam_hashmatch', 'action' => 'purge', $forbidden => 'deadbeef',
                ]));
            }
        });
    }

    /** G4 (auth): a forged / absent / inactive operator is refused; and no CITIZEN may file F-SOC-004. */
    public function test_only_an_active_operator_on_its_own_plane_may_file(): void
    {
        $this->onLivePg(function () {
            // An unknown operator id is refused.
            $this->assertRefused(fn () => app(ConstitutionalEngine::class)->file('F-SOC-004', null, [
                'operator_account_id' => (string) Str::uuid(),
                'legal_basis' => 'true_threat', 'action' => 'hard_redact', 'matrix_event_id' => '$e',
            ]));

            // A suspended operator is refused.
            $suspended = $this->operator(OperatorAccount::STATUS_SUSPENDED);
            $this->assertRefused(fn () => $this->fileRaw($suspended, [
                'legal_basis' => 'true_threat', 'action' => 'hard_redact',
            ]));

            // A CITIZEN cannot file it at all — the handler is systemOnly (operator plane only).
            $this->assertRefused(fn () => app(ConstitutionalEngine::class)->file('F-SOC-004', $this->localUser(), [
                'operator_account_id' => (string) $this->operator()->getKey(),
                'legal_basis' => 'true_threat', 'action' => 'hard_redact', 'matrix_event_id' => '$e',
            ]));
        });
    }

    /** G6: the scanner is content-neutral — a hash-list membership test, no classifier, fully offline. */
    public function test_the_scanner_is_content_neutral_and_offline(): void
    {
        $gate = new MediaAdmissionGate(new LocalHashListScanProvider(['abc123known']));

        $blocked = $gate->admit('ABC123KNOWN'); // case-insensitive hash match
        $this->assertFalse($blocked->admitted);
        $this->assertSame('local-hash-list', $blocked->matchedListSource, 'the LIST source is recorded, never the hash');

        $admitted = $gate->admit('an-unlisted-hash');
        $this->assertTrue($admitted->admitted, 'anything not on the hash list passes — no meaning is inferred');

        // The default provider ships EMPTY (the privacy rail) — nothing is blocked without a sideloaded list.
        $this->assertTrue((new MediaAdmissionGate(new LocalHashListScanProvider([])))->admit('whatever')->admitted);
    }

    /** G7: CSAM survives the flip (operator liability is permanent), and once seated M-5 flips to BOTH —
     *  a disclosure referral is filed so the in-game justice can ALSO act. */
    public function test_csam_survives_the_flip_and_seated_removals_disclose_to_constitutional_actors(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $this->seatLegislature($jur);                  // the flip
            $op = $this->operator();
            $this->mock(MatrixClientService::class, fn ($m) => $m->shouldReceive('purgeEvent')->andReturn([]));
            app(RoleService::class)->flush();

            // CSAM still removable when a government is seated — operator liability is non-negotiable.
            $out = app(LegalComplianceService::class)->remove(
                $op, self::ROOM, '$seatedcsam', LegalComplianceRemoval::BASIS_CSAM_HASHMATCH, $jur
            );

            $removal = LegalComplianceRemoval::query()->where('id', $out['removal_id'])->first();
            $this->assertTrue((bool) $removal->is_seated_at_time);

            // M-5 flips to BOTH: a mandatory disclosure referral to the seated bodies exists.
            $this->assertNotNull($removal->referral_record_id, 'a seated removal discloses to constitutional actors');
            $referral = PublicRecord::query()->where('id', $removal->referral_record_id)->first();
            $this->assertSame('legal_compliance_removal', $referral->kind);
            $this->assertStringContainsString('seated', strtolower((string) $referral->title));
        });
    }

    /** The bootstrap counterpart of G7: with no seated government there is no one to disclose to. */
    public function test_a_bootstrap_removal_has_no_referral(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();                 // unseated
            $op = $this->operator();
            $this->mock(MatrixClientService::class, fn ($m) => $m->shouldReceive('redact')->andReturn([]));
            app(RoleService::class)->flush();

            $out = app(LegalComplianceService::class)->remove(
                $op, self::ROOM, '$boot', LegalComplianceRemoval::BASIS_TRUE_THREAT, $jur, 'order #5'
            );

            $removal = LegalComplianceRemoval::query()->where('id', $out['removal_id'])->first();
            $this->assertFalse((bool) $removal->is_seated_at_time);
            $this->assertNull($removal->referral_record_id, 'no seated bodies ⇒ no referral');
            $this->assertSame('hard_redact', $removal->action, 'a non-CSAM basis redacts, never purges');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** File F-SOC-004 directly through the engine with a (deliberately mis-shaped) payload. */
    private function fileRaw(OperatorAccount $op, array $overrides): void
    {
        app(ConstitutionalEngine::class)->file('F-SOC-004', null, array_merge([
            'operator_account_id' => (string) $op->getKey(),
            'matrix_event_id'     => '$e',
            'matrix_room_id'      => self::ROOM,
            'legal_basis'         => 'true_threat',
            'action'              => 'hard_redact',
        ], $overrides));
    }

    private function assertRefused(callable $fn): void
    {
        $threw = false;
        try {
            $fn();
        } catch (ConstitutionalViolation $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the legal-compliance filing should have been refused');
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

    private function operator(string $status = OperatorAccount::STATUS_ACTIVE): OperatorAccount
    {
        return OperatorAccount::create([
            'server_id' => (string) Str::uuid(),
            'username'  => 'op-'.Str::random(8),
            'password'  => Str::random(32),
            'status'    => $status,
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
            'name'              => 'K3 Legal '.Str::uuid(),
            'email'             => 'k3legal-'.Str::uuid().'@test.invalid',
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
