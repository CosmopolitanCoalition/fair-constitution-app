<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\BoardElectionAdministration;
use App\Domain\Forms\Handlers\WorkerBoardElectionAdministration;
use App\Models\Board;
use App\Models\Election;
use App\Models\User;
use App\Services\Organizations\OrgBoardElectionService;
use App\Services\Organizations\OrgBoardSeatingService;
use App\Services\Organizations\OrgBoardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real handler queries on private SQLite fixtures; certification is a guarded mock. */
final class BoardCertificationScopeTest extends TestCase
{
    use \Tests\Concerns\AchievementSchema;

    private string $originalConnection;
    private OrgBoardSeatingService $seating;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.board_certification_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('board_certification_fixture');
        $this->createAchievementTables(); // AC-1: the wired handlers read the ledger before they award
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('agent_user_id');
            $t->uuid('board_id')->nullable();
            $t->softDeletes();
        });
        $schema->create('boards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('boardable_type');
            $t->uuid('boardable_id');
            $t->softDeletes();
        });
        $schema->create('elections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('board_id');
            $t->string('kind');
            $t->string('status');
            $t->softDeletes();
        });
        foreach ([1, 2] as $n) {
            DB::table('organizations')->insert([
                'id' => $this->id($n), 'agent_user_id' => $this->id(100 + $n), 'board_id' => $this->id(10 + $n),
            ]);
            DB::table('boards')->insert([
                'id' => $this->id(10 + $n), 'boardable_type' => Board::BOARDABLE_ORGANIZATIONS,
                'boardable_id' => $this->id($n),
            ]);
        }
        $this->seating = $this->createMock(OrgBoardSeatingService::class);
    }

    protected function tearDown(): void
    {
        DB::purge('board_certification_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public static function tracksAndActors(): array
    {
        return [
            'owner agent' => ['owner', false], 'owner system' => ['owner', true],
            'worker agent' => ['worker', false], 'worker system' => ['worker', true],
        ];
    }

    #[DataProvider('tracksAndActors')]
    public function test_an_election_of_another_board_never_reaches_seating(string $track, bool $system): void
    {
        $this->election($this->kind($track), 12);
        $this->seating->expects(self::never())->method('certify');
        $this->expectException(ConstitutionalViolation::class);
        $this->expectExceptionMessage('Select a');
        $this->handler($track)->handle($system ? null : $this->agent(), $this->payload($track));
    }

    #[DataProvider('tracksAndActors')]
    public function test_the_other_track_of_the_same_board_never_reaches_seating(string $track, bool $system): void
    {
        $this->election($this->kind($track === 'owner' ? 'worker' : 'owner'));
        $this->seating->expects(self::never())->method('certify');
        $this->expectException(ConstitutionalViolation::class);
        $this->expectExceptionMessage('Select a');
        $this->handler($track)->handle($system ? null : $this->agent(), $this->payload($track));
    }

    #[DataProvider('tracksAndActors')]
    public function test_the_selected_track_and_board_reach_the_existing_seating_service(string $track, bool $system): void
    {
        $this->election($this->kind($track));
        $this->seating->expects(self::once())->method('certify')
            ->with(self::callback(fn (Election $election) =>
                (string) $election->id === $this->id(200)
                && (string) $election->board_id === $this->id(11)
                && $election->kind === $this->kind($track)
                && $election->status === Election::STATUS_VOTING_CLOSED
            ))->willReturn(['election_id' => $this->id(200), 'seated' => 3]);
        $result = $this->handler($track)->handle($system ? null : $this->agent(), $this->payload($track));
        self::assertSame($this->id(200), $result['election_id']);
        self::assertSame(3, $result['seated']);
    }

    public static function tracks(): array
    {
        return [['owner'], ['worker']];
    }

    #[DataProvider('tracks')]
    public function test_another_organizations_agent_is_still_refused(string $track): void
    {
        $this->election($this->kind($track));
        $this->seating->expects(self::never())->method('certify');
        $this->expectException(ConstitutionalViolation::class);
        $this->expectExceptionMessage('agent');
        $this->handler($track)->handle($this->agent(102), $this->payload($track));
    }

    public function test_a_stale_organization_board_pointer_cannot_authorize_another_organizations_board(): void
    {
        DB::table('organizations')->where('id', $this->id(1))->update(['board_id' => $this->id(12)]);
        $this->election(Election::KIND_ORG_BOARD_OWNER, 12);
        $this->seating->expects(self::never())->method('certify');
        $this->expectException(ConstitutionalViolation::class);
        $this->expectExceptionMessage('Select an owner-seat election');
        $this->handler('owner')->handle($this->agent(), $this->payload('owner'));
    }

    private function handler(string $track): FormHandler
    {
        $elections = $this->createMock(OrgBoardElectionService::class);
        return $track === 'owner'
            ? new BoardElectionAdministration($this->createMock(OrgBoardService::class), $elections, $this->seating)
            : new WorkerBoardElectionAdministration($elections, $this->seating);
    }

    private function payload(string $track): array
    {
        return ['action' => 'certify', 'election_id' => $this->id(200)] + ($track === 'owner'
            ? ['organization_id' => $this->id(1)] : ['board_id' => $this->id(11)]);
    }

    private function kind(string $track): string
    {
        return $track === 'owner' ? Election::KIND_ORG_BOARD_OWNER : Election::KIND_ORG_BOARD_WORKER;
    }

    private function election(string $kind, int $board = 11): void
    {
        DB::table('elections')->insert([
            'id' => $this->id(200), 'board_id' => $this->id($board), 'kind' => $kind,
            'status' => Election::STATUS_VOTING_CLOSED,
        ]);
    }

    private function agent(int $id = 101): User
    {
        $user = new User;
        $user->setRawAttributes(['id' => $this->id($id)]);
        return $user;
    }

    private function id(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }
}
