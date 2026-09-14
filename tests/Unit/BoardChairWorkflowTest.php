<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Domain\Forms\FormRegistry;
use App\Http\Controllers\Organizations\BoardElectionController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Http\Presenters\StvRoundPresenter;
use App\Models\{AuditEntry, Board, BoardSeat, ChamberVote, ChamberVoteTally, InstanceSettings, Organization, PublicRecord, User, VoteCast};
use App\Services\{AuditService, ChamberVoteService, ConstitutionalValidator, PublicRecordService, RoleService, SettingsResolver, VoteCountingService};
use App\Services\Education\TrainingGateService;
use App\Services\Organizations\OrgBoardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/** Real form engine/open/cast/count/seat/publication on disposable SQLite.
 * Audit transport, global role lookup and settings lookup are doubles;
 * PostgreSQL chain hashing and concurrent row locking are not claimed here.
 */
final class BoardChairWorkflowTest extends TestCase
{
    private string $original;
    private ConstitutionalEngine $engine;
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.chair_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('chair_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        // Only this workflow's disposable tables; no migrations touch the world.
        foreach ([Board::class, BoardSeat::class, ChamberVote::class, ChamberVoteTally::class,
            Organization::class, User::class, VoteCast::class, PublicRecord::class, InstanceSettings::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                if ($model instanceof PublicRecord) $t->bigIncrements('seq');
                foreach (array_unique(array_merge($model->getFillable(), ['id', 'created_at', 'updated_at', 'deleted_at'])) as $column) {
                    if ($column === 'is_tiebreak') $t->boolean($column)->default(false);
                    elseif ($column === 'id') $t->string('id')->unique();
                    else $t->text($column)->nullable();
                }
            });
        }
        InstanceSettings::create(['instance_name' => 'Private test', 'instance_class' => 'production']);
        Organization::create(['id' => $this->id(1), 'name' => 'Fixture organization', 'status' => 'active',
            'jurisdiction_id' => $this->id(2), 'board_id' => $this->id(3), 'agent_user_id' => $this->id(199)]);
        Board::create(['id' => $this->id(3), 'boardable_type' => 'organizations', 'boardable_id' => $this->id(1),
            'status' => 'forming', 'composition_valid' => false, 'owner_seats' => 2, 'worker_seats' => 1]);
        foreach (['owner_elected', 'worker_elected', 'governor'] as $n => $class) {
            (new User)->forceFill(['id' => $this->id(100 + $n), 'name' => 'Internal '.$n, 'display_name' => 'Member '.$n])->save();
            BoardSeat::create(['id' => $this->id(10 + $n), 'board_id' => $this->id(3), 'seat_class' => $class,
                'seat_no' => $n + 1, 'holder_user_id' => $this->id(100 + $n), 'status' => 'seated', 'is_chair' => false]);
        }
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturnCallback(function (...$args) {
            $this->audit[] = $args;
            return (new AuditEntry)->forceFill(['seq' => count($this->audit)]);
        });
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(fn ($jurisdiction, $key, $fallback) => $fallback);
        $records = new PublicRecordService($audit);
        $roles = $this->createMock(RoleService::class);
        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-01']);
        $votes = new ChamberVoteService($audit, $settings, $records, $this->createMock(CommitteeRoster::class), new VoteCountingService);
        $this->app->instance(ChamberVoteService::class, $votes);
        $this->app->instance(OrgBoardService::class, new OrgBoardService($audit, $records, $roles));
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate,
            $this->createMock(TrainingGateService::class));
    }

    protected function tearDown(): void
    {
        DB::purge('chair_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_simulated_owner_worker_and_governor_elect_and_seat_the_chair(): void
    {
        $vote = $this->open();
        self::assertSame('rcv', $vote->vote_method);
        self::assertSame(3, $vote->serving_snapshot);
        self::assertSame(2, $vote->tallies->sole()->required_yes);
        foreach ([0, 1, 2] as $n) $this->cast($vote, $n, [11, 10, 12]);
        self::assertSame('adopted', $vote->refresh()->outcome);
        self::assertSame($this->id(11), Board::findOrFail($this->id(3))->chair_seat_id);
        self::assertTrue(BoardSeat::findOrFail($this->id(11))->is_chair);
        self::assertSame('active', Board::findOrFail($this->id(3))->status);
        self::assertSame(3, VoteCast::count());
        self::assertSame(4, PublicRecord::count()); // three casts plus certification
        self::assertSame(3, PublicRecord::where('via_form', 'F-ORG-010')->count());
        self::assertSame(0, PublicRecord::whereNull('audit_seq')->count());
        $rounds = (new ChamberVotePresenter)->rcvRounds($vote);
        self::assertSame('Member 1', $rounds['winner']);
        foreach ($rounds['rounds'] as $round) foreach ($round['tallies'] as $tally) self::assertStringStartsWith('Member ', $tally['name']);
        self::assertContains('board.chair.participation', array_column($this->audit, 1));
    }

    public function test_failed_ballot_can_be_retried_without_rewriting_it(): void
    {
        $vote = $this->open();
        foreach ([0, 1, 2] as $n) $this->cast($vote, $n, [10 + $n]);
        self::assertSame('failed', $vote->refresh()->outcome);
        self::assertNull(Board::findOrFail($this->id(3))->chair_seat_id);
        $next = $this->open();
        self::assertNotSame($vote->id, $next->id);
        self::assertSame('closed', $vote->refresh()->status);
        foreach ([0, 1, 2] as $n) $this->cast($next, $n, [12, 11, 10]);
        self::assertSame($this->id(12), Board::findOrFail($this->id(3))->chair_seat_id);
    }

    public function test_open_is_idempotent_and_never_discards_an_unfinished_ballot(): void
    {
        $vote = $this->open();
        $this->cast($vote, 0, [11]);
        self::assertSame($vote->id, $this->open()->id);
        self::assertSame(1, ChamberVote::count());
        self::assertSame(1, VoteCast::count());
    }

    public function test_composition_change_supersedes_old_ballots_and_clears_the_chair(): void
    {
        $vote = $this->open();
        $this->cast($vote, 0, [11]);
        BoardSeat::whereKey($this->id(12))->update(['status' => 'removed']);
        $new = DB::transaction(fn () => app(OrgBoardService::class)->onCompositionChange(Board::findOrFail($this->id(3))));
        self::assertSame('void', $vote->refresh()->status);
        self::assertSame(2, $new->serving_snapshot);
        $this->refused(fn () => $this->cast($vote, 1, [11]));
        foreach ([0, 1] as $n) $this->cast($new, $n, [11, 10]);
        self::assertSame($this->id(11), Board::findOrFail($this->id(3))->chair_seat_id);
        DB::transaction(fn () => app(OrgBoardService::class)->onCompositionChange(Board::findOrFail($this->id(3))));
        self::assertNull(Board::findOrFail($this->id(3))->chair_seat_id);
        self::assertFalse(BoardSeat::findOrFail($this->id(11))->is_chair);
    }

    public function test_duplicate_and_closed_ballots_cannot_publish_another_cast(): void
    {
        $vote = $this->open();
        $this->cast($vote, 0, [11]);
        $this->refused(fn () => $this->cast($vote, 0, [10]));
        self::assertSame(1, PublicRecord::count());
        foreach ([1, 2] as $n) $this->cast($vote, $n, [11]);
        $this->refused(fn () => $this->cast($vote, 1, [10]));
        $this->refused(fn () => $this->open());
        self::assertSame(4, PublicRecord::count());
    }

    public function test_legacy_older_open_ballot_cannot_replace_a_newer_failed_result(): void
    {
        $old = $this->open();
        $old->forceFill(['opened_at' => now()->subDay()])->save();
        $closed = $old->replicate();
        $closed->forceFill(['id' => $this->id(888), 'opened_at' => now(), 'status' => 'closed', 'outcome' => 'failed'])->save();
        $this->refused(fn () => $this->cast($old, 0, [11]));
        $fresh = $this->open();
        self::assertNotSame($old->id, $fresh->id);
        self::assertNotSame($closed->id, $fresh->id);
        self::assertSame('void', $old->refresh()->status);
        self::assertSame('failed', $closed->refresh()->outcome);
    }

    public static function invalidRankings(): array
    {
        return [[[]], [[10, 10]], [[999]], [[10, 11, 12, 999]], ['invalid'], [[['nested']]]];
    }

    #[DataProvider('invalidRankings')]
    public function test_invalid_rankings_are_refused_before_publication(mixed $rankings): void
    {
        $vote = $this->open();
        $rankings = is_array($rankings) ? array_map(fn ($id) => is_int($id) ? $this->id($id) : $id, $rankings) : $rankings;
        $this->refused(fn () => $this->file(0, ['action' => 'cast', 'vote_id' => $vote->id, 'rankings' => $rankings]));
        self::assertSame(0, PublicRecord::count());
        self::assertSame(0, VoteCast::count());
    }

    public function test_wrong_board_removed_seat_agent_and_system_cannot_vote(): void
    {
        $vote = $this->open();
        $this->refused(fn () => $this->file(99, ['action' => 'open']));
        $this->refused(fn () => $this->engine->file('F-ORG-010', null, ['organization_id' => $this->id(1), 'action' => 'open']));
        BoardSeat::whereKey($this->id(10))->update(['board_id' => $this->id(999)]);
        $this->refused(fn () => $this->cast($vote, 0, [11]));
        BoardSeat::whereKey($this->id(11))->update(['status' => 'removed']);
        $this->refused(fn () => $this->cast($vote, 1, [12]));
        self::assertSame(0, VoteCast::count());
    }

    public function test_dissolved_or_replaced_board_and_mirror_refuse(): void
    {
        $this->open();
        Board::whereKey($this->id(3))->update(['status' => 'dissolved']);
        $this->refused(fn () => $this->open());
        Board::whereKey($this->id(3))->update(['status' => 'forming', 'boardable_id' => $this->id(999)]);
        $this->refused(fn () => $this->open());
        Board::whereKey($this->id(3))->update(['boardable_id' => $this->id(1)]);
        InstanceSettings::query()->update(['mirror_of_server_id' => $this->id(555)]);
        $this->refused(fn () => $this->open());
        self::assertSame(1, ChamberVote::count());
    }

    public function test_controller_keeps_route_scope_and_member_receipt(): void
    {
        $vote = $this->open();
        $controller = new BoardElectionController($this->engine, new StvRoundPresenter, new ChamberVotePresenter);
        $read = new ReflectionMethod($controller, 'chair');
        $props = $read->invoke($controller, Board::with('seats')->findOrFail($this->id(3)), $this->user(1));
        self::assertTrue($props['canCast']);
        self::assertSame('Member 1', $props['candidates'][1]['name']);
        $request = Request::create('/organizations/'.$this->id(1).'/board-chair', 'POST', [
            'action' => 'cast', 'vote_id' => $vote->id, 'rankings' => [$this->id(11)],
            'organization_id' => $this->id(999), 'board_seat_id' => $this->id(11),
        ]);
        $request->setUserResolver(fn () => $this->user(1));
        self::assertTrue($controller->chairAction($request, Organization::findOrFail($this->id(1)))->isRedirect());
        self::assertSame($this->id(11), VoteCast::sole()->board_seat_id);
        $props = $read->invoke($controller, Board::with('seats')->findOrFail($this->id(3)), $this->user(1));
        self::assertFalse($props['canCast']);
        self::assertTrue($props['submitted']);
        self::assertSame([$this->id(11)], $props['memberSeats'][0]['rankings']);
        self::assertCount(132, FormRegistry::FORMS); // + F-LEG-039 Budget Act (W-0299) // EO-4 adds two nomination authority forms; EO-5 adds F-IND-025/026 (individual endorsement + withdrawal); IO-1 adds F-JDG-011/012/013/014 (hearing/deliberation/dismissal/ruling; the verdict stays a transition, not a form); IO-2 adds F-IND-027 (Appeal Filing; appeals-workflow-rules = B); IO-5 adds F-ORG-011 (Staff Delegation; org-staff-delegation-model = A; the delegate derives R-31 and holds no office).
    }

    public function test_member_with_two_seats_can_cast_each_seat_and_finish_the_ballot(): void
    {
        BoardSeat::whereKey($this->id(11))->update(['holder_user_id' => $this->id(100)]);
        $vote = $this->open();
        $this->refused(fn () => $this->cast($vote, 0, [12])); // require unambiguous seat
        $this->refused(fn () => $this->file(0, ['action' => 'cast', 'vote_id' => $vote->id,
            'board_seat_id' => $this->id(12), 'rankings' => [$this->id(12)]])); // another person's seat
        $controller = new BoardElectionController($this->engine, new StvRoundPresenter, new ChamberVotePresenter);
        $read = new ReflectionMethod($controller, 'chair');
        foreach ([10, 11] as $seatId) {
            $props = $read->invoke($controller, Board::with('seats')->findOrFail($this->id(3)), $this->user(0));
            self::assertTrue($props['canCast']);
            self::assertCount(2, $props['memberSeats']);
            $this->file(0, ['action' => 'cast', 'vote_id' => $vote->id, 'board_seat_id' => $this->id($seatId),
                'rankings' => [$this->id(12)]]);
        }
        $props = $read->invoke($controller, Board::with('seats')->findOrFail($this->id(3)), $this->user(0));
        self::assertFalse($props['canCast']);
        self::assertTrue($props['memberSeats'][0]['submitted']);
        self::assertTrue($props['memberSeats'][1]['submitted']);
        $this->cast($vote, 2, [12]);
        self::assertSame('adopted', $vote->refresh()->outcome);
        self::assertSame(3, VoteCast::count());
    }

    private function open(): ChamberVote
    {
        return ChamberVote::findOrFail($this->file(0, ['action' => 'open'])->recorded['vote_id']);
    }
    private function cast(ChamberVote $vote, int $member, array $rankings): void
    {
        $this->file($member, ['action' => 'cast', 'vote_id' => (string) $vote->id,
            'rankings' => array_map(fn ($id) => $this->id($id), $rankings)]);
    }
    private function file(int $member, array $payload): \App\Domain\Engine\EngineResult
    {
        return $this->engine->file('F-ORG-010', $this->user($member), $payload + [
            'organization_id' => $this->id(1), 'jurisdiction_id' => $this->id(2)]);
    }
    private function user(int $member): User
    {
        return User::find($this->id(100 + $member)) ?? (new User)->forceFill(['id' => $this->id(100 + $member)]);
    }
    private function refused(callable $action): void
    {
        try { $action(); self::fail('Expected refusal.'); }
        catch (ConstitutionalViolation $e) { self::assertNotEmpty($e->getMessage()); }
    }
    private function id(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
}
