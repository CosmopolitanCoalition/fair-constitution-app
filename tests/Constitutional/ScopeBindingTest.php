<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\ElectionSchedulingOrder;
use App\Domain\Forms\Handlers\ManualDistrictDraw;
use App\Domain\Forms\Handlers\MonopolyAcquisitionVote;
use App\Domain\Forms\Handlers\PublicPrivateConversionRequest;
use App\Domain\Forms\Handlers\SubdivisionBoundaryDrawing;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureDistrictMap;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Models\OrgConversion;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — K3-I jurisdiction SCOPE-BINDING. The engine's role gate is jurisdiction-blind: it
 * proves a player holds R-08 / R-09 SOMEWHERE (and an attested forwarded write carries that GLOBAL role
 * snapshot). Specific-jurisdiction scope is enforced by the HANDLER. These pins close four handlers that
 * forgot it — a board member of jurisdiction A could schedule elections / activate or draw district maps
 * in jurisdiction B; a legislator of chamber X could complete jurisdiction Y's monopoly acquisition; an
 * organization's agent could request a DIFFERENT org's conversion. Each must now refuse: the filing must
 * come from a member of THIS body (the sibling BoardProvenance / ChamberActor pattern).
 *
 * The system/clock/bootstrap path (null actor) is deliberately NOT gated here — it bypasses the role gate
 * by engine rule and is covered by the existing election/districting suite.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ScopeBindingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_scope_binding';

    public function test_f_elb_001_a_board_member_of_a_cannot_schedule_jurisdiction_bs_election(): void
    {
        $this->onLivePg(function () {
            [$jurA, $jurB] = $this->twoBoardFreeJurisdictions();
            $member = $this->user('A board member');
            $this->boardWithMember($jurA, $member);          // U sits on A's board
            $boardB = $this->board($jurB);                   // B's board — U is NOT on it

            $electionB = Election::create([
                'jurisdiction_id' => $jurB, 'kind' => Election::KIND_GENERAL,
                'status' => Election::STATUS_SCHEDULED, 'trigger' => 'manual',
                'election_board_id' => $boardB->id,
            ]);

            $payload = [
                'election_id' => (string) $electionB->id,
                'approval_opens_at' => now()->toIso8601String(),
                'finalist_cutoff_at' => now()->addDays(31)->toIso8601String(),
                'ranked_opens_at' => now()->addDays(31)->toIso8601String(),
                'ranked_closes_at' => now()->addDays(45)->toIso8601String(),
            ];

            $this->assertScopeRefused('R-08', fn () => app(ElectionSchedulingOrder::class)->handle($member, $payload));
        });
    }

    public function test_f_elb_003_a_board_member_of_a_cannot_activate_jurisdiction_bs_district_map(): void
    {
        $this->onLivePg(function () {
            [$jurA, $jurB] = $this->twoBoardFreeJurisdictions();
            $member = $this->user('A board member');
            $this->boardWithMember($jurA, $member);
            $this->board($jurB);                              // B's board exists; U is not on it

            $legB = $this->legislature($jurB);
            $mapB = LegislatureDistrictMap::create([
                'legislature_id' => (string) $legB->id, 'name' => 'B draft', 'status' => LegislatureDistrictMap::STATUS_DRAFT,
            ]);

            $this->assertScopeRefused('R-08', fn () => app(SubdivisionBoundaryDrawing::class)->handle($member, ['map_id' => (string) $mapB->id]));
        });
    }

    public function test_f_elb_008_a_board_member_of_a_cannot_draw_districts_for_jurisdiction_bs_legislature(): void
    {
        $this->onLivePg(function () {
            [$jurA, $jurB] = $this->twoBoardFreeJurisdictions();
            $member = $this->user('A board member');
            $this->boardWithMember($jurA, $member);
            $this->board($jurB);

            $legB = $this->legislature($jurB);
            $mapB = LegislatureDistrictMap::create([
                'legislature_id' => (string) $legB->id, 'name' => 'B draft', 'status' => LegislatureDistrictMap::STATUS_DRAFT,
            ]);

            // geojson is a non-empty string (the top-of-handler check); the scope check fires before geometry.
            $payload = ['legislature_id' => (string) $legB->id, 'map_id' => (string) $mapB->id, 'scope_id' => $jurB, 'geojson' => '{"type":"Polygon"}'];
            $this->assertScopeRefused('R-08', fn () => app(ManualDistrictDraw::class)->handle($member, $payload));
        });
    }

    public function test_f_leg_026_a_legislator_of_chamber_x_cannot_complete_chamber_ys_acquisition(): void
    {
        $this->onLivePg(function () {
            [$jurA, $jurB] = $this->twoBoardFreeJurisdictions();
            $legA = $this->legislature($jurA);
            $legB = $this->legislature($jurB);
            $member = $this->user('X legislator');
            LegislatureMember::create([
                'legislature_id' => (string) $legA->id, 'user_id' => (string) $member->id, 'status' => 'seated',
            ]);

            $lawB = Law::create([
                'jurisdiction_id' => $jurB, 'legislature_id' => (string) $legB->id, 'act_number' => 'B-'.Str::random(5),
                'title' => 'Authorizing act', 'kind' => 'ordinary', 'scale' => [$jurB], 'origin' => 'bill',
                'effective_at' => now(), 'enacted_at' => now(), 'status' => 'in_force',
            ]);
            $org = $this->organization($jurB);
            $conversion = OrgConversion::create([
                'organization_id' => (string) $org->id, 'direction' => OrgConversion::DIRECTION_PRIVATE_TO_CGC,
                'via' => OrgConversion::VIA_MONOPOLY_ACQUISITION,
                'authorizing_law_id' => (string) $lawB->id, 'status' => OrgConversion::STATUS_COMPENSATION_PENDING,
            ]);

            $payload = ['action' => 'record_compensation', 'conversion_id' => (string) $conversion->id, 'compensation' => 1_000_000];
            $this->assertScopeRefused('R-09', fn () => app(MonopolyAcquisitionVote::class)->handle($member, $payload));
        });
    }

    public function test_f_org_006_an_orgs_agent_cannot_request_a_different_orgs_conversion(): void
    {
        $this->onLivePg(function () {
            [$jurA, $jurB] = $this->twoBoardFreeJurisdictions();
            $agentOfY = $this->user('Agent of Y');
            $orgY = $this->organization($jurA, $agentOfY);    // U is org Y's agent (R-23)
            $orgX = $this->organization($jurB);               // a DIFFERENT org; U is not its agent

            app(RoleService::class)->flush();
            $this->assertNotContains('R-09', app(RoleService::class)->rolesFor($agentOfY), 'precondition: not a legislator');

            $payload = ['organization_id' => (string) $orgX->id, 'direction' => OrgConversion::DIRECTION_PRIVATE_TO_CGC];
            $this->assertScopeRefused('R-23', fn () => app(PublicPrivateConversionRequest::class)->handle($agentOfY, $payload));
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assertScopeRefused(string $roleTag, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected a {$roleTag} scope ConstitutionalViolation");
        } catch (ConstitutionalViolation $e) {
            $this->assertStringContainsString($roleTag, (string) $e->citation, "the refusal is the {$roleTag} scope gate");
        }
    }

    private function board(string $jurisdictionId): ElectionBoard
    {
        return ElectionBoard::create([
            'jurisdiction_id' => $jurisdictionId, 'is_bootstrap' => true, 'status' => 'active',
        ]);
    }

    private function boardWithMember(string $jurisdictionId, User $member): ElectionBoard
    {
        $board = $this->board($jurisdictionId);
        ElectionBoardMember::create([
            'election_board_id' => (string) $board->id, 'user_id' => (string) $member->id, 'status' => 'seated',
        ]);

        return $board;
    }

    private function legislature(string $jurisdictionId): Legislature
    {
        return Legislature::create([
            'jurisdiction_id' => $jurisdictionId, 'status' => Legislature::STATUS_FORMING,
            'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'term_number' => 1, 'quorum_required' => 3,
        ]);
    }

    private function organization(string $jurisdictionId, ?User $agent = null): Organization
    {
        return Organization::create([
            'jurisdiction_id' => $jurisdictionId, 'type' => 'business', 'name' => 'Org '.Str::random(5),
            'slug' => 'org-'.Str::lower(Str::random(10)), 'status' => 'active', 'is_active' => true,
            'agent_user_id' => $agent !== null ? (string) $agent->id : null,
        ]);
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name.' '.Str::uuid(), 'email' => 'scope-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32), 'terms_accepted_at' => now(),
        ]);
    }

    /** @return array{0:string,1:string} two DISTINCT board-free jurisdiction ids. */
    private function twoBoardFreeJurisdictions(): array
    {
        $ids = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereNotIn('id', fn ($q) => $q->select('jurisdiction_id')->from('election_boards')
                ->where('status', 'active')->whereNull('deleted_at'))
            ->limit(2)->pluck('id')->all();

        if (count($ids) < 2) {
            $this->markTestSkipped('Live DB needs two board-free jurisdictions.');
        }

        return [(string) $ids[0], (string) $ids[1]];
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
