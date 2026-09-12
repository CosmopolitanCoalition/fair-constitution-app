<?php

namespace Tests\Unit;

use App\Http\Controllers\Rooms\RoomParticipantController;
use App\Models\Board;
use App\Models\CourtCase;
use App\Models\Legislature;
use App\Models\User;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\PublicRoomNames;
use App\Services\Rooms\RoomParticipantRoster;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** All fixtures are memory-only. No Matrix services, migrations or live data. */
final class RoomParticipantRosterTest extends TestCase
{
    private string $original;
    private RoomParticipantRoster $roster;
    private RoomParticipantController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.participant_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('participant_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $tables = [
            'matrix_identities' => ['user_id', 'matrix_user_id'],
            'users' => ['display_name', 'name', 'email'], 'social_profiles' => ['user_id', 'display_name', 'handle'],
            'legislatures' => ['jurisdiction_id'], 'legislature_members' => ['legislature_id', 'user_id', 'status', 'seat_no'],
            'board_seats' => ['board_id', 'holder_user_id', 'status', 'seat_no'],
            'panels' => ['case_id', 'status'], 'panel_judges' => ['panel_id', 'user_id', 'status', 'screening_result', 'is_presiding'],
            'case_parties' => ['case_id', 'party_user_id', 'party_role', 'status', 'represented_by_advocate_id'],
            'advocates' => ['user_id', 'status'], 'juries' => ['case_id', 'status'],
            'jury_members' => ['jury_id', 'user_id', 'screening_status', 'seat_no'],
            'matrix_rooms' => ['entity_type', 'entity_id', 'matrix_room_id', 'room_type', 'space_type', 'is_public', 'is_encrypted', 'tombstoned_at'],
        ];
        foreach ($tables as $table => $columns) DB::connection()->getSchemaBuilder()->create($table, function (Blueprint $t) use ($columns): void {
            $t->string('id')->primary();
            foreach ($columns as $column) $t->string($column)->nullable();
            $t->softDeletes();
        });
        $this->roster = new RoomParticipantRoster(new PublicRoomNames());
        $this->controller = new RoomParticipantController($this->roster, new BoardRoomAccess(),
            new PublicVoiceRoomAccess($this->createMock(MatrixPostingGateService::class)));
    }

    protected function tearDown(): void
    {
        DB::purge('participant_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_members_beyond_preview_are_scoped_to_supplied_handles_and_refresh_removes_former_offices(): void
    {
        for ($i = 1; $i <= 125; $i++) {
            $this->identity('user'.$i);
            $this->row('legislature_members', 'seat'.$i, ['legislature_id' => 'leg', 'user_id' => 'user'.$i, 'status' => 'seated', 'seat_no' => $i]);
        }
        $leg = (new Legislature())->forceFill(['id' => 'leg', 'speaker_id' => 'seat125']);
        DB::enableQueryLog();
        $rows = $this->roster->forInstitution($leg, ['@user125:fixture', '@user124:fixture', '@unknown:remote']);
        self::assertSame(['speaker', 'legislator', 'guest'], array_column($rows, 'role'));
        self::assertSame('Public user125', $rows[0]['display_name']);
        self::assertArrayNotHasKey('user_id', $rows[0]);
        self::assertStringNotContainsString('SECRET', json_encode($rows));
        $queries = DB::getQueryLog();
        self::assertCount(4, $queries);
        self::assertSame(['leg', 'elected', 'seated', 'user124', 'user125', 'seat125'], $queries[1]['bindings']);
        DB::disableQueryLog();
        DB::table('legislature_members')->where('id', 'seat125')->update(['status' => 'term_ended']);
        $rows = $this->roster->forInstitution($leg, ['@user125:fixture']);
        self::assertSame('guest', $rows[0]['role']);
    }

    public function test_court_resolves_current_judges_parties_counsel_and_jurors_only_for_selected_case(): void
    {
        foreach (['judge', 'party', 'counsel', 'juror', 'former', 'elsewhere'] as $id) $this->identity($id);
        $this->row('panels', 'panel', ['case_id' => 'case', 'status' => 'seated']);
        $this->row('panels', 'otherpanel', ['case_id' => 'other', 'status' => 'seated']);
        foreach (['judge' => ['panel', 'seated'], 'former' => ['panel', 'replaced'], 'elsewhere' => ['otherpanel', 'seated']] as $id => [$panel, $status]) {
            $this->row('panel_judges', $id, ['panel_id' => $panel, 'user_id' => $id, 'status' => $status, 'screening_result' => 'cleared', 'is_presiding' => '1']);
        }
        $this->row('advocates', 'advocate', ['user_id' => 'counsel', 'status' => 'registered']);
        // The advocate must resolve even though their represented party was not supplied.
        $this->row('case_parties', 'client', ['case_id' => 'case', 'party_user_id' => 'not-connected', 'party_role' => 'plaintiff', 'status' => 'active', 'represented_by_advocate_id' => 'advocate']);
        $this->row('case_parties', 'party', ['case_id' => 'case', 'party_user_id' => 'party', 'party_role' => 'accused', 'status' => 'active']);
        $this->row('juries', 'jury', ['case_id' => 'case', 'status' => 'empaneled']);
        $this->row('jury_members', 'juror', ['jury_id' => 'jury', 'user_id' => 'juror', 'screening_status' => 'empaneled', 'seat_no' => 2]);
        $case = (new CourtCase())->forceFill(['id' => 'case']);
        $rows = $this->roster->forInstitution($case, array_map(fn ($id) => '@'.$id.':fixture', ['judge', 'party', 'counsel', 'juror', 'former', 'elsewhere']));
        self::assertSame(['presiding_judge', 'defense', 'advocate', 'juror', 'guest', 'guest'], array_column($rows, 'role'));
        DB::table('case_parties')->where('id', 'client')->update(['status' => 'withdrawn']);
        DB::table('juries')->where('id', 'jury')->update(['status' => 'discharged']);
        DB::table('panels')->where('id', 'panel')->update(['status' => 'dissolved']);
        self::assertSame('guest', $this->roster->forInstitution($case, ['@judge:fixture'])[0]['role']);
        self::assertSame(['guest', 'guest'], array_column($this->roster->forInstitution($case, ['@counsel:fixture', '@juror:fixture']), 'role'));
    }

    public function test_public_endpoint_derives_room_ignores_client_roles_and_rejects_private_or_wrong_institution(): void
    {
        $this->identity('member');
        $this->row('legislatures', 'leg', ['jurisdiction_id' => 'poland']);
        $this->room('legislature', 'leg', true);
        $leg = (new Legislature())->forceFill(['id' => 'leg', 'jurisdiction_id' => 'poland']);
        $result = $this->controller->chamber($this->request('viewer', ['handles' => ['@member:fixture'], 'role' => 'speaker', 'room_id' => '!other:fixture', 'jurisdiction_id' => 'elsewhere']), $leg)->getData(true);
        self::assertSame('!leg:fixture', $result['roomId']);
        self::assertSame('guest', $result['roster'][0]['role']);
        foreach ([['is_public' => '0'], ['is_encrypted' => '1'], ['entity_id' => 'other']] as $change) {
            DB::table('matrix_rooms')->update(['is_public' => '1', 'is_encrypted' => '0', 'entity_id' => 'leg', ...$change]);
            $this->denied(fn () => $this->controller->chamber($this->request('viewer'), $leg));
        }
    }

    public function test_board_requires_current_exact_board_membership_and_resolves_chair_without_preview(): void
    {
        $this->identity('member');
        $this->identity('former');
        $this->row('board_seats', 'chair', ['board_id' => 'board', 'holder_user_id' => 'member', 'status' => 'seated', 'seat_no' => 125]);
        $this->row('board_seats', 'former', ['board_id' => 'board', 'holder_user_id' => 'former', 'status' => 'term_ended']);
        $this->row('board_seats', 'other', ['board_id' => 'other', 'holder_user_id' => 'other', 'status' => 'seated']);
        $this->room('board', 'board', false);
        $board = (new Board())->forceFill(['id' => 'board', 'chair_seat_id' => 'chair', 'status' => 'active']);
        foreach ([null, 'outsider', 'former', 'other'] as $viewer) $this->denied(fn () => $this->controller->board($this->request($viewer), $board));
        $result = $this->controller->board($this->request('member', ['handles' => ['@member:fixture', '@former:fixture']]), $board)->getData(true);
        self::assertSame(['chair', 'guest'], array_column($result['roster'], 'role'));
        DB::table('board_seats')->where('id', 'chair')->update(['status' => 'removed']);
        $this->denied(fn () => $this->controller->board($this->request('member'), $board));
    }

    public function test_invalid_or_oversized_requests_fail_before_database_and_deleted_identity_never_resolves(): void
    {
        DB::enableQueryLog();
        foreach ([array_fill(0, 101, '@person:fixture'), ['not-a-handle']] as $handles) {
            try { $this->controller->chamber($this->request('viewer', ['handles' => $handles]), new Legislature()); self::fail('Invalid handles accepted.'); }
            catch (ValidationException) { self::assertSame([], DB::getQueryLog()); }
        }
        self::assertSame([], $this->roster->forInstitution(new Legislature(), []));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->identity('deleted');
        DB::table('matrix_identities')->update(['deleted_at' => '2026-09-12']);
        self::assertSame([['handle' => '@deleted:fixture', 'display_name' => null, 'role' => 'guest', 'seat' => null]],
            $this->roster->forInstitution(new Legislature(), ['@deleted:fixture']));
    }

    private function identity(string $user): void
    {
        $this->row('matrix_identities', $user, ['user_id' => $user, 'matrix_user_id' => '@'.$user.':fixture']);
        $this->row('users', $user, ['display_name' => 'Public '.$user, 'name' => 'SECRET legal name', 'email' => 'SECRET@example.invalid']);
    }

    private function row(string $table, string $id, array $data): void { DB::table($table)->insert(['id' => $id, ...$data]); }

    private function room(string $type, string $id, bool $public): void
    {
        $this->row('matrix_rooms', 'room', ['entity_type' => $type, 'entity_id' => $id, 'matrix_room_id' => '!'.$id.':fixture',
            'room_type' => $public ? 'institution' : 'org_private', 'is_public' => $public ? '1' : '0', 'is_encrypted' => '0']);
    }

    private function request(?string $viewer, array $data = []): Request
    {
        $request = Request::create('/room/participants', 'POST', ['handles' => [], ...$data]);
        $request->setUserResolver(fn () => $viewer === null ? null : (new User())->forceFill(['id' => $viewer]));
        return $request;
    }

    private function denied(callable $operation): void
    {
        try { $operation(); self::fail('Expected exact room access denial.'); }
        catch (HttpException $error) { self::assertSame(403, $error->getStatusCode()); }
    }
}
