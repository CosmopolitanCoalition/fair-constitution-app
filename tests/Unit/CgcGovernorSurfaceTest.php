<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\EngineResult;
use App\Http\Controllers\Legislature\SessionController;
use App\Http\Controllers\Organizations\BoardElectionController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Http\Presenters\StvRoundPresenter;
use App\Models\Appointment;
use App\Models\AuditEntry;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\ChamberVoteTally;
use App\Models\Election;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\OrgWorker;
use App\Models\PublicRecord;
use App\Models\SocialProfile;
use App\Models\Term;
use App\Models\User;
use App\Models\VoteCast;
use App\Services\Organizations\OrgSettingsService;
use App\Services\RoleService;
use App\Services\Rooms\BoardRoomAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Actual controller, public-name readers and tally presenter; explicit private SQLite only. */
final class CgcGovernorSurfaceTest extends TestCase
{
    private string $original;

    private BoardElectionController $controller;

    private ConstitutionalEngine $engine;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.cgc_surface_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('cgc_surface_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        foreach ([User::class, Organization::class, Board::class, BoardSeat::class, Executive::class, ExecutiveMember::class, Legislature::class,
            LegislatureMember::class, Jurisdiction::class, Appointment::class, Term::class, SocialProfile::class, ChamberVote::class, ChamberVoteTally::class,
            VoteCast::class, Election::class, OrgMembership::class, OrgWorker::class, PublicRecord::class] as $class) {
            $model = new $class;
            $schema->create($model->getTable(), function (Blueprint $t) use ($model) {
                foreach (array_unique(array_merge($model->getFillable(), ['id', 'created_at', 'updated_at', 'deleted_at'], $model instanceof PublicRecord ? ['seq'] : [])) as $field) {
                    if ($field === 'id') {
                        $t->uuid($field)->primary();
                    } else {
                        $t->text($field)->nullable();
                    }
                }
            });
        }
        $schema->create('residency_confirmations', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('jurisdiction_id');
            $t->boolean('is_active');
        });
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'name' => 'Fixture place']);
        DB::table('executives')->insert(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1), 'status' => 'delegated']);
        DB::table('legislatures')->insert(['id' => $this->id(3), 'jurisdiction_id' => $this->id(1), 'status' => 'active', 'speaker_id' => $this->id(25)]);
        DB::table('boards')->insert(['id' => $this->id(5), 'boardable_type' => 'organizations', 'boardable_id' => $this->id(4), 'status' => 'active', 'owner_seats' => 3, 'worker_seats' => 0]);
        DB::table('board_seats')->insert(['id' => $this->id(6), 'board_id' => $this->id(5), 'seat_class' => 'governor', 'status' => 'vacant', 'seat_no' => 1]);
        $this->org = (new Organization)->forceFill(['id' => $this->id(4), 'name' => 'Public garden', 'is_cgc' => true, 'type' => 'common_good_corp',
            'status' => 'active', 'is_active' => true, 'jurisdiction_id' => $this->id(1), 'board_id' => $this->id(5), 'overseen_by_executive_id' => $this->id(2), 'created_by_legislature_id' => $this->id(3)]);
        foreach ([10, 11, 12, 13, 14] as $n) {
            $this->person($n, 'Viewer '.$n);
        }
        DB::table('executive_members')->insert(['id' => $this->id(20), 'executive_id' => $this->id(2), 'user_id' => $this->id(10), 'status' => 'seated', 'role' => 'principal']);
        DB::table('executive_members')->insert(['id' => $this->id(21), 'executive_id' => $this->id(99), 'user_id' => $this->id(11), 'status' => 'seated', 'role' => 'principal']);
        DB::table('executive_members')->insert(['id' => $this->id(22), 'executive_id' => $this->id(2), 'user_id' => $this->id(11), 'status' => 'seated', 'role' => ExecutiveMember::ROLE_ADVISOR]);
        foreach ([12 => 24, 13 => 25, 14 => 26] as $user => $member) {
            DB::table('legislature_members')->insert([
                'id' => $this->id($member), 'legislature_id' => $this->id($user === 14 ? 99 : 3), 'user_id' => $this->id($user), 'status' => 'seated', 'seat_type' => 'a']);
        }
        $settings = $this->createMock(OrgSettingsService::class);
        $settings->method('get')->willReturn(null);
        $this->app->instance(OrgSettingsService::class, $settings);
        $rooms = $this->createMock(BoardRoomAccess::class);
        $rooms->method('allows')->willReturn(false);
        $this->app->instance(BoardRoomAccess::class, $rooms);
        $this->engine = $this->createMock(ConstitutionalEngine::class);
        $this->controller = new BoardElectionController($this->engine, $this->createMock(StvRoundPresenter::class), new ChamberVotePresenter);
    }

    protected function tearDown(): void
    {
        DB::purge('cgc_surface_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string
    {
        return sprintf('70000000-0000-4000-8000-%012d', $n);
    }

    private function person(int $n, ?string $name, bool $active = true): void
    {
        DB::table('users')->insert(['id' => $this->id($n), 'display_name' => $name, 'name' => 'Secret legal '.$n]);
        DB::table('residency_confirmations')->insert(['user_id' => $this->id($n), 'jurisdiction_id' => $this->id(1), 'is_active' => $active]);
    }

    private function request(?int $actor, string $url, ?string $only = null, string $method = 'GET', array $data = []): Request
    {
        $r = Request::create($url, $method, $data);
        $r->setUserResolver(fn () => $actor === null ? null : User::findOrFail($this->id($actor)));
        $r->headers->set('X-Inertia', 'true');
        if ($only !== null) {
            $r->headers->set('X-Inertia-Partial-Component', 'Organizations/BoardElections');
            $r->headers->set('X-Inertia-Partial-Data', $only);
        }

        return $r;
    }

    private function page(?int $actor = 10, ?string $only = 'appointmentContext', ?string $url = null): array
    {
        $r = $this->request($actor, $url ?? '/organizations/'.$this->org->id.'/board-elections', $only);

        return $this->controller->show($r, $this->org)->toResponse($r)->getData(true)['props'];
    }

    private function appointment(int $n, bool $current = true): void
    {
        DB::table('appointments')->insert(['id' => $this->id(1000 + $n), 'appointable_type' => 'board_seats', 'appointable_id' => $this->id(6),
            'nominee_user_id' => $this->id(10), 'consent_vote_id' => $this->id(2000 + $n), 'nominated_via_form' => 'F-EXE-001', 'status' => 'nominated', 'created_at' => '2026-09-13 12:00:00']);
        if ($current) {
            DB::table('board_seats')->where('id', $this->id(6))->update(['appointment_id' => $this->id(1000 + $n), 'status' => 'nominated']);
        }
        DB::table('chamber_votes')->insert(['id' => $this->id(2000 + $n), 'body_type' => 'legislature', 'body_id' => $this->id(3), 'legislature_id' => $this->id(3),
            'jurisdiction_id' => $this->id(1), 'votable_type' => 'appointment_consent', 'votable_id' => $this->id(1000 + $n), 'vote_type' => 'bog_consent', 'status' => 'open',
            'threshold_basis' => 'majority', 'vote_method' => 'yes_no', 'stage' => 'floor', 'serving_snapshot' => 7, 'bicameral' => false]);
        DB::table('chamber_vote_tallies')->insert(['id' => $this->id(3000 + $n), 'vote_id' => $this->id(2000 + $n), 'lane' => 'all',
            'required_yes' => 4, 'serving' => 7, 'quorum_required' => 4, 'present' => 2, 'yes' => 1, 'no' => 0, 'abstain' => 0]);
    }

    public function test_preview_and_action_context_follow_the_exact_overseer_creator_and_current_board(): void
    {
        $p = $this->page()['appointmentContext'];
        self::assertTrue($p['canNominate']);
        self::assertFalse($p['preview']);
        self::assertSame('Viewer 10', $p['actor_name']);
        self::assertSame($this->id(3), $p['legislature_id']);
        self::assertSame(1, $p['vacancies']);
        foreach ([null, 11, 12, 14] as $actor) {
            $p = $this->page($actor)['appointmentContext'];
            self::assertTrue($p['preview']);
            self::assertFalse($p['canNominate']);
        }
        DB::table('executive_members')->where('id', $this->id(20))->update(['role' => ExecutiveMember::ROLE_ADVISOR]);
        self::assertFalse($this->page()['appointmentContext']['canNominate']);
        self::assertFalse($this->page(only: 'nomineeDirectory', url: '/?nominee_q=Viewer')['nomineeDirectory']['searched']);
        DB::table('executive_members')->where('id', $this->id(20))->update(['role' => 'principal']);
        foreach (['appointment_id', 'holder_user_id', 'term_id'] as $field) {
            DB::table('board_seats')->update([$field => $this->id(99)]);
            self::assertSame(0, $this->page()['appointmentContext']['vacancies']);
            DB::table('board_seats')->update([$field => null]);
        }
        foreach (['dissolved', 'forming', 'reverted'] as $state) {
            DB::table('executives')->update(['status' => $state]);
            self::assertFalse($this->page()['appointmentContext']['ready']);
        }
        DB::table('executives')->update(['status' => 'elected', 'jurisdiction_id' => $this->id(99)]);
        self::assertFalse($this->page()['appointmentContext']['ready']);
        DB::table('executives')->update(['jurisdiction_id' => $this->id(1)]);
        DB::table('legislatures')->update(['jurisdiction_id' => $this->id(99)]);
        self::assertFalse($this->page()['appointmentContext']['ready']);
        DB::table('legislatures')->update(['jurisdiction_id' => $this->id(1)]);
        DB::table('boards')->update(['boardable_id' => $this->id(99)]);
        self::assertNull($this->page()['appointmentContext']['board_id']);
    }

    public function test_full_entry_does_not_load_nominee_roster_and_partial_search_does_not_load_appointments(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $p = $this->page(only: null);
        self::assertArrayNotHasKey('nomineeDirectory', $p);
        self::assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'residency_confirmations')));
        DB::flushQueryLog();
        $p = $this->page(only: 'nomineeDirectory');
        self::assertSame(['nomineeDirectory'], array_keys($p));
        self::assertFalse($p['nomineeDirectory']['searched']);
        self::assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'residency_confirmations') || str_contains($q['query'], 'from "appointments"')));
        $p = $this->page(11, 'nomineeDirectory', '/?nominee_q=Viewer');
        self::assertFalse($p['nomineeDirectory']['searched']);
    }

    public function test_public_name_prefix_seek_reaches_repeated_names_and_retains_private_profile_exclusions(): void
    {
        for ($n = 100; $n < 147; $n++) {
            $this->person($n, $n % 2 ? 'Same Name' : null);
        }
        for ($n = 100; $n < 147; $n += 2) {
            DB::table('social_profiles')->insert(['id' => $this->id(500 + $n), 'user_id' => $this->id($n), 'visibility' => 'public', 'display_name' => 'Same Name', 'handle' => 'same-'.$n]);
        }
        foreach ([150 => 'private', 151 => 'jurisdiction'] as $n => $visibility) {
            $this->person($n, null);
            DB::table('social_profiles')->insert(['id' => $this->id(500 + $n), 'user_id' => $this->id($n), 'visibility' => $visibility, 'display_name' => 'Same Name', 'handle' => 'private-'.$n]);
        }
        $this->person(152, 'Same Name', false);
        $this->person(153, 'Same Name');
        DB::table('users')->where('id', $this->id(153))->update(['deleted_at' => '2026-09-13']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $one = $this->page(only: 'nomineeDirectory', url: '/?nominee_q=Same')['nomineeDirectory'];
        self::assertCount(20, $one['candidates']);
        $queries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "users" as "u"'));
        self::assertCount(2, $queries);
        foreach ($queries as $q) {
            self::assertStringContainsString('limit 21', $q['query']);
            self::assertStringNotContainsString('"u"."name"', $q['query']);
        }
        $two = $this->page(only: 'nomineeDirectory', url: $one['next'])['nomineeDirectory'];
        $three = $this->page(only: 'nomineeDirectory', url: $two['next'])['nomineeDirectory'];
        self::assertCount(20, $two['candidates']);
        self::assertCount(7, $three['candidates']);
        self::assertNull($three['next']);
        $all = array_merge($one['candidates'], $two['candidates'], $three['candidates']);
        self::assertCount(47, array_unique(array_column($all, 'id')));
        self::assertSame(array_column($one['candidates'], 'id'), array_column($this->page(only: 'nomineeDirectory', url: $two['previous'])['nomineeDirectory']['candidates'], 'id'));
        foreach ($all as $person) {
            self::assertSame('/people?who='.$person['id'], $person['profile_href']);
            self::assertSame('Same Name', $person['name']);
        }
        self::assertSame([], $this->page(only: 'nomineeDirectory', url: '/?nominee_q=Secret')['nomineeDirectory']['candidates']);
        self::assertSame([], $this->page(only: 'nomineeDirectory', url: '/?nominee_q=%25')['nomineeDirectory']['candidates']);
    }

    public function test_public_handles_and_reference_lookup_are_available_without_leaking_private_names(): void
    {
        $this->person(160, null);
        DB::table('social_profiles')->insert(['id' => $this->id(660), 'user_id' => $this->id(160), 'visibility' => 'public', 'display_name' => null, 'handle' => 'visible']);
        $p = $this->page(only: 'nomineeDirectory', url: '/?nominee_q=%40vis')['nomineeDirectory']['candidates'];
        self::assertSame('@visible', $p[0]['name']);
        DB::table('users')->where('id', $this->id(160))->update(['display_name' => 'Chosen name']);
        $p = $this->page(only: 'nomineeDirectory', url: '/?nominee_q=%40vis')['nomineeDirectory']['candidates'];
        self::assertSame('Chosen name', $p[0]['name']);
        $this->person(161, null);
        DB::table('social_profiles')->insert(['id' => $this->id(661), 'user_id' => $this->id(161), 'visibility' => 'private', 'display_name' => 'Private name', 'handle' => 'hidden']);
        $p = $this->page(only: 'nomineeDirectory', url: '/?nominee_by=reference&nominee_q='.$this->id(161))['nomineeDirectory']['candidates'];
        self::assertStringStartsWith('Resident-', $p[0]['name']);
        self::assertNull($p[0]['public_handle']);
        DB::table('residency_confirmations')->where('user_id', $this->id(161))->update(['is_active' => false]);
        self::assertSame([], $this->page(only: 'nomineeDirectory', url: '/?nominee_by=reference&nominee_q='.$this->id(161))['nomineeDirectory']['candidates']);
    }

    public function test_appointment_history_pages_current_board_and_exact_consent_authority(): void
    {
        for ($n = 1; $n <= 43; $n++) {
            $this->appointment($n, $n === 43);
        }
        $one = $this->page(12, 'governorAppointments,governorPages');
        $two = $this->page(12, 'governorAppointments,governorPages', $one['governorPages']['next']);
        $three = $this->page(12, 'governorAppointments,governorPages', $two['governorPages']['next']);
        self::assertCount(20, $one['governorAppointments']);
        self::assertCount(20, $two['governorAppointments']);
        self::assertCount(3, $three['governorAppointments']);
        self::assertSame($one['governorAppointments'], $this->page(12, 'governorAppointments,governorPages', $two['governorPages']['previous'])['governorAppointments']);
        $current = $one['governorAppointments'][0];
        self::assertTrue($current['consent']['can_cast']);
        self::assertSame(4, $current['consent']['tally']['requiredYes']);
        self::assertSame('/votes/'.$this->id(2043).'/cast', $current['consent']['cast_url']);
        self::assertFalse($one['governorAppointments'][1]['consent']['can_cast']);
        foreach ([null, 10, 13, 14] as $actor) {
            self::assertFalse($this->page($actor, 'governorAppointments')['governorAppointments'][0]['consent']['can_cast']);
        }
        DB::table('vote_casts')->insert(['id' => $this->id(4000), 'vote_id' => $this->id(2043), 'member_id' => $this->id(24), 'value' => 'yes']);
        $p = $this->page(12, 'governorAppointments')['governorAppointments'][0];
        self::assertFalse($p['consent']['can_cast']);
        self::assertSame('yes', $p['consent']['my_cast']);
        DB::table('chamber_votes')->where('id', $this->id(2043))->update(['legislature_id' => $this->id(99)]);
        self::assertNull($this->page(12, 'governorAppointments')['governorAppointments'][0]['consent']);
    }

    public function test_controller_posts_derived_cgc_scope_to_existing_nomination_and_vote_forms(): void
    {
        $nominee = $this->id(12);
        $this->engine->expects(self::once())->method('file')->with('F-EXE-001', self::callback(fn ($u) => $u->id === $this->id(10)), [
            'organization_id' => $this->org->id, 'jurisdiction_id' => $this->id(1), 'nominee_user_id' => $nominee, 'dossier' => 'Public statement',
        ])->willReturn(new EngineResult('F-EXE-001', new AuditEntry, []));
        $r = $this->request(10, '/organizations/'.$this->org->id.'/governor-nominations', null, 'POST', [
            'nominee_user_id' => $nominee, 'dossier' => 'Public statement', 'organization_id' => $this->id(99), 'department_id' => $this->id(99), 'jurisdiction_id' => $this->id(99),
        ]);
        self::assertStringContainsString('/board-elections#governor-appointments', $this->controller->nominateGovernor($r, $this->org)->getTargetUrl());
        $castEngine = $this->createMock(ConstitutionalEngine::class);
        $castEngine->expects(self::once())->method('file')->with('F-LEG-004', self::callback(fn ($u) => $u->id === $this->id(12)), [
            'vote_id' => $this->id(2001), 'jurisdiction_id' => $this->id(1), 'value' => 'yes', 'rankings' => null, 'explanation' => 'Suitable nominee',
        ])->willReturn(new EngineResult('F-LEG-004', new AuditEntry, []));
        $this->appointment(1);
        $controller = new SessionController($castEngine, new ChamberVotePresenter, $this->createMock(RoleService::class));
        self::assertTrue($controller->cast($this->request(12, '/votes/'.$this->id(2001).'/cast', null, 'POST', ['value' => 'yes', 'explanation' => 'Suitable nominee']), ChamberVote::find($this->id(2001)))->isRedirect());
    }

    public function test_malformed_votes_and_already_termed_nominations_never_offer_a_consent_cast(): void
    {
        $this->appointment(1);
        foreach (['votable_type' => 'appointments', 'votable_id' => $this->id(99), 'vote_type' => 'other', 'body_type' => 'board',
            'body_id' => $this->id(99), 'jurisdiction_id' => $this->id(99), 'stage' => 'committee'] as $field => $bad) {
            $original = DB::table('chamber_votes')->where('id', $this->id(2001))->value($field);
            DB::table('chamber_votes')->where('id', $this->id(2001))->update([$field => $bad]);
            $row = $this->page(12, 'governorAppointments')['governorAppointments'][0];
            self::assertNull($row['consent']);
            self::assertNotNull($row['consent_notice']);
            DB::table('chamber_votes')->where('id', $this->id(2001))->update([$field => $original]);
        }
        foreach (['term_id' => $this->id(99), 'nominated_via_form' => 'F-LEG-013'] as $field => $bad) {
            $original = DB::table('appointments')->where('id', $this->id(1001))->value($field);
            DB::table('appointments')->where('id', $this->id(1001))->update([$field => $bad]);
            self::assertFalse($this->page(12, 'governorAppointments')['governorAppointments'][0]['consent']['can_cast']);
            DB::table('appointments')->where('id', $this->id(1001))->update([$field => $original]);
        }
    }

    public function test_seated_governor_and_chair_choices_use_the_same_public_identity_as_nomination(): void
    {
        $this->person(160, null);
        DB::table('social_profiles')->insert(['id' => $this->id(660), 'user_id' => $this->id(160), 'visibility' => 'private', 'display_name' => 'Private social', 'handle' => 'hidden']);
        DB::table('board_seats')->where('id', $this->id(6))->update(['status' => 'seated', 'holder_user_id' => $this->id(160)]);
        $p = $this->page(only: 'seated,chair');
        self::assertStringStartsWith('Resident-', $p['seated']['seats'][0]['holder']['name']);
        self::assertSame($p['seated']['seats'][0]['holder']['name'], $p['chair']['candidates'][0]['name']);
        self::assertStringNotContainsString('Secret legal', json_encode($p));
        self::assertStringNotContainsString('Private social', json_encode($p));
    }

    public function test_tied_consent_exposes_only_the_exact_speakers_resolvable_recorded_lane(): void
    {
        $this->appointment(1);
        DB::table('chamber_votes')->where('id', $this->id(2001))->update(['status' => 'closed', 'outcome' => 'tied']);
        DB::table('chamber_vote_tallies')->where('vote_id', $this->id(2001))->update(['yes' => 3, 'no' => 3]);
        $p = $this->page(13, 'governorAppointments')['governorAppointments'][0]['consent'];
        self::assertTrue($p['can_tiebreak']);
        self::assertFalse($p['can_cast']);
        self::assertSame('/votes/'.$this->id(2001).'/tiebreak', $p['tiebreak_url']);
        foreach ([null, 10, 12, 14] as $actor) {
            self::assertFalse($this->page($actor, 'governorAppointments')['governorAppointments'][0]['consent']['can_tiebreak']);
        }
        DB::table('chamber_votes')->where('id', $this->id(2001))->update(['bicameral' => true]);
        DB::table('chamber_vote_tallies')->where('vote_id', $this->id(2001))->update(['lane' => 'type_b']);
        self::assertFalse($this->page(13, 'governorAppointments')['governorAppointments'][0]['consent']['can_tiebreak']);
        DB::table('chamber_vote_tallies')->where('vote_id', $this->id(2001))->update(['lane' => 'type_a']);
        self::assertTrue($this->page(13, 'governorAppointments')['governorAppointments'][0]['consent']['can_tiebreak']);
        DB::table('chamber_vote_tallies')->where('vote_id', $this->id(2001))->update(['yes' => 2, 'no' => 2]);
        self::assertFalse($this->page(13, 'governorAppointments')['governorAppointments'][0]['consent']['can_tiebreak']);
        DB::table('chamber_vote_tallies')->where('vote_id', $this->id(2001))->update(['yes' => 3, 'no' => 3]);
        DB::table('board_seats')->where('id', $this->id(6))->update(['appointment_id' => $this->id(99)]);
        self::assertFalse($this->page(13, 'governorAppointments')['governorAppointments'][0]['consent']['can_tiebreak']);
    }

    public function test_malformed_and_cross_context_cursors_are_rejected(): void
    {
        for ($n = 100; $n < 123; $n++) {
            $this->person($n, 'Same Name');
        }
        $next = $this->page(only: 'nomineeDirectory', url: '/?nominee_q=Same')['nomineeDirectory']['next'];
        foreach ([str_replace('Same', 'Different', $next), '/?nominee_q=Same&nominee_cursor=bad', '/?governor_cursor=bad'] as $url) {
            try {
                $this->page(only: str_contains($url, 'governor_cursor') ? 'governorAppointments' : 'nomineeDirectory', url: $url);
                self::fail('Invalid cursor accepted');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
    }
}
