<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\ElectionResultsCertification;
use App\Models\Election;
use App\Models\InstanceSettings;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Models\OperatorAccount;
use App\Models\PeerUpgradeProposal;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — G-VER the upgrade-agreement protocol. A constitutional_version
 * bump must clear THREE hardened gates before it re-rules how a jurisdiction counts:
 *
 *   1. the HARDENED admissibility floor — a bump that decreases proportionality or
 *      weakens the supermajority floor is REJECTED outright (Art. VII), ungateable by
 *      any consent;
 *   2. the CONSENT leg, by seatedness — the operator board's scaling attestation in
 *      bootstrap (Meter A), SUPERSEDED by the seated government's supermajority the
 *      moment one exists (Meter B);
 *   3. the FREEZE — never apply over a live process (Art. II §7).
 *
 * Plus the certification-boundary belt: a count is sealed only under the version the
 * election was pinned to. If an edit breaks these, the edit is the violation.
 */
class PeerUpgradeAgreementTest extends TestCase
{
    use FederationSyncSupport;

    public function test_the_hardened_admissibility_filter_rejects_a_regressive_bump(): void
    {
        $this->onLivePg(function () {
            $svc = app(PeerUpgradeAgreementService::class);
            $root = $this->jurisdiction(null, 5);

            // Proportionality ratchet (Art. II §2 / Art. VII): no method below stv_droop.
            try {
                $svc->propose(
                    PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP,
                    $root->id,
                    'cv1.regressive',
                    null, null,
                    ['voting_method' => 'fptp'],
                );
                $this->fail('a proportionality-decreasing bump must be inadmissible');
            } catch (ConstitutionalViolation) {
                // expected — the protected validator refuses it
            }

            // Supermajority floor (Art. VII): a fraction at/below 1/2 is inadmissible.
            try {
                $svc->propose(
                    PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP,
                    $root->id,
                    'cv1.regressive',
                    null, null,
                    ['supermajority_numerator' => 1, 'supermajority_denominator' => 2],
                );
                $this->fail('a supermajority-floor-weakening bump must be inadmissible');
            } catch (ConstitutionalViolation) {
                // expected
            }

            // No proposal row survived an inadmissible attempt.
            $this->assertSame(0, PeerUpgradeProposal::query()->count());
        });
    }

    public function test_meter_a_operator_board_attestation_ratifies_in_bootstrap(): void
    {
        $this->onLivePg(function () {
            $svc = app(PeerUpgradeAgreementService::class);
            $root = $this->jurisdiction(null, 5); // unseated → bootstrap

            $this->assertSame('operator', $svc->applicableConsentLeg($root->id));

            $operator = $this->operator();
            $proposal = $svc->propose(PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP, $root->id, 'cv1.agreedtarget');

            // Refuse to apply before the board attests (the finalize discipline).
            try {
                $svc->ratify($proposal);
                $this->fail('ratify must refuse before the operator board attests');
            } catch (ConstitutionalViolation) {
                // expected
            }

            $svc->recordOperatorConsent($proposal, $operator, true);
            $this->assertTrue($svc->meterAPassed($proposal->refresh()));

            $ratified = $svc->ratify($proposal);
            $this->assertSame(PeerUpgradeProposal::STATUS_RATIFIED, $ratified->status);
            $this->assertSame('cv1.agreedtarget', InstanceSettings::current()->fresh()->constitutional_version);
        });
    }

    public function test_a_seated_government_supersedes_the_board_and_meter_b_ratifies(): void
    {
        $this->onLivePg(function () {
            $svc = app(PeerUpgradeAgreementService::class);
            $root = $this->jurisdiction(null, 5);
            $this->seatedLegislature($root);

            $this->assertSame('seated', $svc->applicableConsentLeg($root->id));

            $operator = $this->operator();
            $proposal = $svc->propose(PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP, $root->id, 'cv1.seatedtarget');

            // An operator CANNOT attest on a seated government's behalf (Art. II §2).
            try {
                $svc->recordOperatorConsent($proposal, $operator, true);
                $this->fail('a seated government supersedes the operator board');
            } catch (ConstitutionalViolation) {
                // expected
            }

            $mjv = $svc->openSeatedLeg($proposal);
            $this->assertSame(MultiJurisdictionVote::STATUS_OPEN, $mjv->status);

            // No constituents → the root's own seated legislature consents as one body
            // (UNANIMITY of 1; its internal supermajority chamber vote is the driver).
            $svc->recordSeatedConsent($proposal, $root->id, true);
            $this->assertTrue($svc->meterBPassed($proposal->refresh()));

            $ratified = $svc->ratify($proposal);
            $this->assertSame(PeerUpgradeProposal::STATUS_RATIFIED, $ratified->status);
            $this->assertSame('cv1.seatedtarget', InstanceSettings::current()->fresh()->constitutional_version);
        });
    }

    public function test_the_freeze_blocks_ratify_until_the_subtree_thaws(): void
    {
        $this->onLivePg(function () {
            $svc = app(PeerUpgradeAgreementService::class);
            $root = $this->jurisdiction(null, 5);
            $operator = $this->operator();

            $proposal = $svc->propose(PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP, $root->id, 'cv1.frozentarget');
            $svc->recordOperatorConsent($proposal, $operator, true);

            // A live election in the subtree freezes the bump (Art. II §7).
            $election = Election::create([
                'jurisdiction_id' => $root->id,
                'kind' => Election::KIND_GENERAL,
                'status' => Election::STATUS_APPROVAL_OPEN,
            ]);

            try {
                $svc->ratify($proposal);
                $this->fail('a live election must freeze the upgrade');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
            }

            // Thaw — the contest certifies — and the consented bump applies.
            $election->forceFill(['status' => Election::STATUS_CERTIFIED])->save();
            $this->assertSame(
                PeerUpgradeProposal::STATUS_RATIFIED,
                $svc->ratify($proposal)->status,
            );
        });
    }

    public function test_certification_refuses_when_the_pinned_version_moved(): void
    {
        $this->onLivePg(function () {
            $root = $this->jurisdiction(null, 5);
            $handler = app(ElectionResultsCertification::class);

            // An election whose pinned version no longer matches the deployed one —
            // certifying would seal a count under rules that moved mid-contest.
            $stale = Election::create([
                'jurisdiction_id' => $root->id,
                'kind' => Election::KIND_GENERAL,
                'status' => Election::STATUS_TABULATING,
            ]);
            $stale->forceFill(['constitutional_version' => 'cv1.staleoldversion'])->save();

            try {
                $handler->handle(null, ['election_id' => (string) $stale->id]);
                $this->fail('certification must refuse a version that moved mid-count');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
                $this->assertStringContainsString('constitutional_version', $e->getMessage());
            }

            // A matching-version election clears the version guard (it then fails later,
            // on the missing tabulation — NOT on Art. II §7, proving the guard passed).
            $matching = Election::create([
                'jurisdiction_id' => $root->id,
                'kind' => Election::KIND_GENERAL,
                'status' => Election::STATUS_TABULATING,
            ]);

            try {
                $handler->handle(null, ['election_id' => (string) $matching->id]);
                $this->fail('a freshly created election has no races to certify');
            } catch (ConstitutionalViolation $e) {
                $this->assertNotSame('Art. II §7', $e->citation);
            }
        });
    }

    // -------------------------------------------------------------------------
    // fixtures
    // -------------------------------------------------------------------------

    private function jurisdiction(?string $parentId, int $admLevel): Jurisdiction
    {
        // Jurisdiction relies on the DB gen_random_uuid() default (no HasUuids).
        $j = new Jurisdiction();
        $j->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'Upgrade '.Str::random(6),
            'slug' => 'upgrade-'.Str::lower(Str::random(12)),
            'adm_level' => $admLevel,
            'parent_id' => $parentId,
            'source' => 'user_defined',
        ])->save();

        return $j;
    }

    private function seatedLegislature(Jurisdiction $jurisdiction): Legislature
    {
        return Legislature::create([
            'jurisdiction_id' => $jurisdiction->id,
            'status' => Legislature::STATUS_ACTIVE,
            'total_seats' => 5,
            'type_a_seats' => 5,
            'type_b_seats' => 0,
            'quorum_required' => 3,
        ]);
    }

    private function operator(): OperatorAccount
    {
        return OperatorAccount::create([
            'server_id' => (string) Str::uuid(),
            'username' => 'op-'.Str::lower(Str::random(10)),
            'password' => 'secret-'.Str::random(8),
            'status' => OperatorAccount::STATUS_ACTIVE,
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg('pgsql_upgrade');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_upgrade');
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
