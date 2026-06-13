<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Department;
use App\Models\Law;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FE-D2..D9 page smoke — every Phase D surface RENDERS for an authenticated
 * resident (the controller executes end to end, Inertia resolves the right
 * component) across BOTH the empty-state and the board-exists branches.
 * Catches controller-body runtime errors that `php -l` and the Vite compile
 * cannot — a bad query, a non-existent method/column, a null deref.
 *
 * Runs against the live Postgres (BallotSecrecyTest posture — the default
 * test connection is sqlite:memory which has no schema): a guarded
 * connection, set as default so the HTTP requests share it, inside ONE
 * transaction that is ALWAYS rolled back. Zero residue.
 */
class PhaseDPageSmokeTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_phase_d_smoke';

    public function test_every_phase_d_surface_renders_for_a_resident(): void
    {
        $conn = $this->livePg();

        $executive = $conn->table('executives')->whereNotNull('jurisdiction_id')->whereNull('deleted_at')->first();

        if ($executive === null) {
            $this->markTestSkipped('No executive seeded — run the activation/demo seed first.');
        }

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $executiveId = (string) $executive->id;
            $jurisdictionId = (string) $executive->jurisdiction_id;
            $legislatureId = $conn->table('legislatures')->where('jurisdiction_id', $jurisdictionId)->whereNull('deleted_at')->value('id');

            $user = User::create([
                'name' => 'Phase D Smoke '.Str::random(5),
                'email' => 'phase-d-smoke-'.Str::uuid().'@test.invalid',
                'password' => Str::random(32),
                'terms_accepted_at' => now(),
            ]);

            DB::table('residency_confirmations')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => (string) $user->getKey(),
                'jurisdiction_id' => $jurisdictionId,
                'days_confirmed' => 30,
                'confirmed_at' => now(),
                'is_active' => true,
                'depth' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $org = $this->throwawayOrg($jurisdictionId, $user, isCgc: false);
            $cgc = $this->throwawayOrg($jurisdictionId, $user, isCgc: true);
            $dept = $this->throwawayDepartment($jurisdictionId, $executiveId, $legislatureId !== null ? (string) $legislatureId : null, $user);

            $this->actingAs($user);

            $cases = [
                // [url, expected status, expected Inertia component | null for the resolver redirect]
                ['/executive', 302, null],
                ["/executives/{$executiveId}", 200, 'Executive/Home'],
                ["/executives/{$executiveId}/departments", 200, 'Executive/Departments'],
                ["/executives/{$executiveId}/actions", 200, 'Executive/Actions'],
                ["/departments/{$dept->id}", 200, 'Executive/DepartmentDetail'],
                ["/departments/{$dept->id}/reporting", 200, 'Executive/DepartmentReporting'],
                ['/organizations', 200, 'Organizations/Registry'],
                ["/organizations/{$org->id}", 200, 'Organizations/OrgDetail'],
                ['/organizations/co-determination', 200, 'Organizations/CoDetermination'],
                ['/organizations/transfers-conversions', 200, 'Organizations/TransfersConversions'],
                ["/organizations/{$org->id}/board-elections", 200, 'Organizations/BoardElections'],
                ["/organizations/{$cgc->id}/cgc", 200, 'Organizations/CgcDetail'],
            ];

            foreach ($cases as [$url, $status, $component]) {
                $response = $this->get($url);

                if ($response->getStatusCode() !== $status) {
                    $ex = $response->exception;
                    $this->fail(sprintf(
                        'GET %s → %d (expected %d).%s',
                        $url,
                        $response->getStatusCode(),
                        $status,
                        $ex !== null ? ' '.get_class($ex).': '.$ex->getMessage().' @ '.$ex->getFile().':'.$ex->getLine() : ' (no exception captured)'
                    ));
                }

                if ($component !== null) {
                    $response->assertInertia(fn (Assert $page) => $page->component($component));
                }
            }
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }

    private function throwawayOrg(string $jurisdictionId, User $agent, bool $isCgc): Organization
    {
        $org = Organization::create([
            'jurisdiction_id' => $jurisdictionId,
            'type' => $isCgc ? Organization::TYPE_COMMON_GOOD_CORP : Organization::TYPE_BUSINESS,
            'structure' => Organization::STRUCTURE_STOCK,
            'name' => ($isCgc ? 'Smoke CGC ' : 'Smoke Org ').Str::random(6),
            'slug' => ($isCgc ? 'smoke-cgc-' : 'smoke-org-').strtolower(Str::random(8)),
            'status' => Organization::STATUS_ACTIVE,
            'is_active' => true,
            'is_registered' => true,
            'is_cgc' => $isCgc,
            'registered_at' => now(),
            'agent_user_id' => (string) $agent->getKey(),
            'registered_by_user_id' => (string) $agent->getKey(),
            'registered_via_form' => $isCgc ? 'F-LEG-019' : 'F-IND-012',
            'worker_count' => 0,
        ]);

        $this->throwawayBoard(Board::BOARDABLE_ORGANIZATIONS, (string) $org->id, $agent, $org);

        return $org->refresh();
    }

    private function throwawayDepartment(string $jurisdictionId, string $executiveId, ?string $legislatureId, User $agent): Department
    {
        $charterLawId = null;

        if ($legislatureId !== null) {
            $charterLawId = (string) Law::create([
                'id' => (string) Str::uuid(),
                'jurisdiction_id' => $jurisdictionId,
                'legislature_id' => $legislatureId,
                'act_number' => 'SMK-'.strtoupper(Str::random(8)),
                'title' => 'Smoke Charter '.Str::random(5),
                'kind' => Law::KIND_CHARTER,
                'scale' => ['scope' => 'smoke'],
                'origin' => Law::ORIGIN_BILL,
                'status' => Law::STATUS_IN_FORCE,
                'current_version_no' => 1,
                'effective_at' => now(),
                'enacted_at' => now(),
            ])->id;
        }

        $department = Department::create([
            'jurisdiction_id' => $jurisdictionId,
            'executive_id' => $executiveId,
            'kind' => Department::KIND_OTHER,
            'name' => 'Smoke Department '.Str::random(6),
            'charter_law_id' => $charterLawId,
            'status' => Department::STATUS_OPERATING,
        ]);

        $this->throwawayBoard(Board::BOARDABLE_DEPARTMENTS, (string) $department->id, $agent, $department);

        return $department->refresh();
    }

    /** A 3-owner-seat board (one seated) so the board-exists render branch runs. */
    private function throwawayBoard(string $boardableType, string $boardableId, User $seatHolder, Organization|Department $attachTo): Board
    {
        $board = Board::create([
            'boardable_type' => $boardableType,
            'boardable_id' => $boardableId,
            'owner_seats' => 3,
            'worker_seats' => 0,
            'worker_headcount' => 0,
            'composition_valid' => true,
            'status' => Board::STATUS_ACTIVE,
        ]);

        BoardSeat::create([
            'board_id' => (string) $board->id,
            'seat_class' => $boardableType === Board::BOARDABLE_DEPARTMENTS ? BoardSeat::CLASS_GOVERNOR : BoardSeat::CLASS_OWNER_ELECTED,
            'seat_no' => 1,
            'holder_user_id' => (string) $seatHolder->getKey(),
            'status' => BoardSeat::STATUS_SEATED,
        ]);

        $attachTo->forceFill(['board_id' => (string) $board->id])->save();

        return $board;
    }

    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — page smoke runs inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
