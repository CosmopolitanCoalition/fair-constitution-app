<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Http\Controllers\Legislature\InstitutionActController;
use App\Http\Controllers\Legislature\SessionController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\AuditEntry;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ChamberVoteTally;
use App\Models\ConstituentConsent;
use App\Models\Department;
use App\Models\Executive;
use App\Models\InstanceSettings;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MultiJurisdictionVote;
use App\Models\PublicRecord;
use App\Models\User;
use App\Models\VoteCast;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\ClockService;
use App\Services\ConstitutionalValidator;
use App\Services\Education\TrainingGateService;
use App\Services\EnactmentService;
use App\Services\Legislature\ChamberActService;
use App\Services\Legislature\CommitteeService;
use App\Services\Legislature\ElectionBoardTransitionService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Services\VoteCountingService;
use App\Support\InstitutionActWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual controller -> engine -> six handlers -> proposal/vote rows in private SQLite.
 * Global role lookup, training, settings and audit transport are doubles; authority is the real ChamberActor.
 * No adoption success is mocked or claimed: rejection and constituent vote opening use real services.
 */
final class InstitutionActWorkspaceTest extends TestCase
{
    private string $original;

    private ConstitutionalEngine $engine;

    private InstitutionActController $controller;

    private InstitutionActWorkspace $reader;

    private Legislature $leg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.institution_acts_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cga.demo_session_capture' => false, 'session.driver' => 'array']);
        DB::setDefaultConnection('institution_acts_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Bus::fake();
        Http::preventStrayRequests();
        foreach ([ChamberVote::class, ChamberVoteProposal::class, ChamberVoteTally::class, ConstituentConsent::class, Department::class,
            Executive::class, InstanceSettings::class, Judiciary::class, Legislature::class, LegislatureMember::class,
            MultiJurisdictionVote::class, PublicRecord::class, User::class, VoteCast::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                if ($model instanceof PublicRecord) {
                    $t->bigIncrements('seq');
                }
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    if ($column === 'id') {
                        $t->string('id')->unique();
                    } elseif ($column === 'is_tiebreak') {
                        $t->boolean($column)->default(false);
                    } elseif ($model instanceof ChamberVoteTally && in_array($column, ['yes', 'no', 'abstain', 'present'], true)) {
                        $t->integer($column)->default(0);
                    } else {
                        $t->text($column)->nullable();
                    }
                }
            });
        }
        DB::connection()->getSchemaBuilder()->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('slug');
            $t->string('parent_id')->nullable();
            $t->integer('adm_level')->default(0);
            $t->softDeletes();
        });
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'name' => 'Fixture region', 'slug' => 'fixture']);
        InstanceSettings::create(['instance_name' => 'Private institution acts', 'instance_class' => 'production']);
        $this->leg = Legislature::create(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1), 'status' => 'active', 'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0]);
        Executive::create(['id' => $this->id(3), 'jurisdiction_id' => $this->id(1), 'source_legislature_id' => $this->leg->id, 'status' => 'forming', 'type' => 'committee']);
        Judiciary::create(['id' => $this->id(4), 'jurisdiction_id' => $this->id(1), 'source_legislature_id' => $this->leg->id, 'status' => 'forming', 'court_name' => 'Fixture court', 'min_judges' => 5]);
        foreach (range(101, 106) as $n) {
            (new User)->forceFill(['id' => $this->id($n), 'name' => 'Fixture '.$n])->save();
            if ($n <= 105) {
                LegislatureMember::create(['id' => $this->id(200 + $n), 'legislature_id' => $this->leg->id, 'user_id' => $this->id($n), 'status' => 'seated', 'seat_type' => 'a']);
            }
        }
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(fn ($jurisdiction, $key, $fallback) => $fallback);
        $roles = $this->createMock(RoleService::class);
        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-01', 'R-09', 'R-10']);
        $records = new PublicRecordService($audit);
        $votes = new ChamberVoteService($audit, $settings, $records, $this->createMock(CommitteeRoster::class), new VoteCountingService);
        $enactments = $this->createMock(EnactmentService::class);
        $enactments->expects(self::never())->method('enactDirect');
        $clocks = new ClockService($audit, $settings);
        $acts = new ChamberActService($votes, $enactments, $records, $this->createMock(CommitteeService::class), $this->createMock(ElectionBoardTransitionService::class), $settings, $clocks, $roles);
        foreach ([AuditService::class => $audit, SettingsResolver::class => $settings, RoleService::class => $roles, PublicRecordService::class => $records,
            ChamberVoteService::class => $votes, EnactmentService::class => $enactments, ClockService::class => $clocks, ChamberActService::class => $acts] as $class => $instance) {
            $this->app->instance($class, $instance);
        }
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
        $this->reader = new InstitutionActWorkspace(new ChamberVotePresenter);
        $this->controller = new InstitutionActController($this->engine, $this->reader);
    }

    protected function tearDown(): void
    {
        DB::purge('institution_acts_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public static function acts(): array
    {
        return [
            'delegation' => ['delegate-executive', ['delegated_scope' => 'Deliver public services', 'member_count' => 5, 'interested' => true], 'exec_delegation', 'exec_delegate', 'forming', 'forming'],
            'executive conversion' => ['elect-executive', ['target_type' => 'individual', 'charter_text' => 'Public elected office'], 'exec_conversion', 'exec_office_create', 'forming', 'forming'],
            'department' => ['create-department', ['name' => 'Public transport', 'kind' => 'other', 'function_text' => 'Operate transit', 'owner_seats' => 2], 'department_creation', 'procedural_motion', 'delegated', 'forming'],
            'appointed court' => ['create-court', ['court_name' => 'Public court', 'function_text' => 'Hear cases', 'committee_judge_count' => 5], 'judiciary_creation', 'judiciary_create', 'forming', 'forming'],
            'court conversion' => ['elect-court', ['judge_count' => 5, 'charter_text' => 'Public elected court'], 'judiciary_conversion', 'judiciary_convert', 'forming', 'appointed'],
            'CGC' => ['create-cgc', ['name' => 'Public network', 'charter' => 'Serve the public', 'owner_seats' => 2], 'cgc_creation', 'procedural_motion', 'delegated', 'forming'],
        ];
    }

    #[DataProvider('acts')]
    public function test_each_actual_filing_opens_its_exact_public_proposal_and_can_be_rejected(string $action, array $payload, string $kind, string $voteType, string $execStatus, string $courtStatus): void
    {
        Executive::whereKey($this->id(3))->update(['status' => $execStatus]);
        Judiciary::whereKey($this->id(4))->update(['status' => $courtStatus]);
        $response = $this->controller->store($this->request(101, 'POST', ['action' => $action, ...$payload,
            'jurisdiction_id' => $this->id(999), 'legislature_id' => $this->id(999), 'executive_id' => $this->id(999),
            'oversight_executive_id' => $this->id(999), 'nominees' => [$this->id(999)], 'system_act' => true]), $this->leg);
        self::assertStringEndsWith('?action='.$action, $response->getTargetUrl());
        $proposal = ChamberVoteProposal::sole();
        $vote = ChamberVote::sole();
        self::assertSame($kind, $proposal->proposal_kind);
        self::assertSame($voteType, $vote->vote_type);
        self::assertSame($this->leg->id, $proposal->legislature_id);
        self::assertSame($this->leg->id, $vote->body_id);
        self::assertSame($this->id(1), $vote->jurisdiction_id);
        self::assertSame($this->id(301), $proposal->proposed_by_member_id);
        self::assertSame($proposal->id, $vote->votable_id);
        self::assertSame('open', $vote->status);
        self::assertSame($voteType === 'procedural_motion' ? 3 : 4, $vote->tallies->sole()->required_yes);
        self::assertStringNotContainsString($this->id(999), json_encode($proposal->payload));
        if ($action === 'delegate-executive') {
            self::assertSame([$this->id(301)], $proposal->payload['interest']);
        }
        if ($action === 'create-department') {
            self::assertSame([], $proposal->payload['nominees']);
            self::assertSame($this->id(3), $proposal->payload['executive_id']);
        }
        if ($action === 'create-cgc') {
            self::assertSame($this->id(3), $proposal->payload['oversight_executive_id']);
        }
        $row = $this->reader->proposals($this->request(), $this->leg)['records'][0];
        self::assertSame($proposal->id, $row['id']);
        self::assertSame('/votes/'.$vote->id.'/cast', $row['vote']['cast_url']);
        foreach (range(101, 105) as $actor) {
            $this->cast($vote, $actor, 'no');
        }
        self::assertSame('failed', $vote->refresh()->outcome);
        self::assertSame('rejected', $proposal->refresh()->status);
        self::assertSame(5, VoteCast::count());
        self::assertSame(5, PublicRecord::where('via_form', 'F-LEG-004')->count());
        self::assertNull($proposal->result_id);
        Bus::assertNothingDispatched();
    }

    public function test_public_preview_and_cross_chamber_or_retired_members_never_gain_filing_authority(): void
    {
        self::assertFalse($this->reader->context($this->leg, null)['canFile']);
        self::assertCount(6, $this->reader->context($this->leg, null)['actions']);
        self::assertSame('Public preview', $this->reader->context($this->leg, User::find($this->id(106)))['role']);
        LegislatureMember::create(['legislature_id' => $this->id(999), 'user_id' => $this->id(106), 'status' => 'seated']);
        foreach ([null, 106, 101] as $actor) {
            if ($actor === 101) {
                LegislatureMember::whereKey($this->id(301))->update(['status' => 'term_ended']);
            }
            $this->refused(fn () => $this->controller->store($this->request($actor, 'POST', ['action' => 'create-cgc', 'name' => 'Unauthorized', 'charter' => 'No authority', 'owner_seats' => 1]), $this->leg));
        }
        self::assertSame(0, ChamberVoteProposal::count());
        self::assertSame(0, ChamberVote::count());
    }

    public function test_public_read_and_authenticated_write_routes_resolve_to_the_exact_workspace(): void
    {
        $routes = \Illuminate\Support\Facades\Route::getRoutes();
        $read = $routes->match(Request::create($this->reader->base($this->leg)));
        self::assertSame('institution-acts.show', $read->getName());
        self::assertContains('auth', $read->excludedMiddleware());
        foreach (['institution-acts.store', 'institution-acts.consent'] as $name) {
            $route = $routes->getByName($name);
            self::assertContains('POST', $route->methods());
            self::assertContains('auth', $route->gatherMiddleware());
            self::assertNotContains('auth', $route->excludedMiddleware());
        }
        $url = $this->reader->base($this->leg).'/consents/'.$this->id(9000);
        self::assertSame('institution-acts.consent', $routes->match(Request::create($url, 'POST'))->getName());
    }

    public function test_state_and_configured_court_minimum_are_checked_by_the_existing_handler(): void
    {
        Judiciary::whereKey($this->id(4))->update(['min_judges' => 8]);
        $payload = ['action' => 'create-court', 'court_name' => 'Public court', 'function_text' => 'Hear cases', 'committee_judge_count' => 5];
        $this->refused(fn () => $this->controller->store($this->request(101, 'POST', $payload), $this->leg));
        $this->controller->store($this->request(101, 'POST', array_replace($payload, ['committee_judge_count' => 8])), $this->leg);
        self::assertSame(8, ChamberVoteProposal::sole()->payload['committee_judge_count']);
        Judiciary::whereKey($this->id(4))->update(['status' => 'appointed']);
        $this->refused(fn () => $this->controller->store($this->request(101, 'POST', array_replace($payload, ['committee_judge_count' => 8])), $this->leg));
        self::assertSame(1, ChamberVoteProposal::count());
    }

    public function test_paged_public_proposals_are_scoped_reversible_and_partial_without_total_queries(): void
    {
        foreach (range(1, 43) as $n) {
            ChamberVoteProposal::create(['id' => $this->id(1000 + $n), 'legislature_id' => $this->leg->id, 'proposal_kind' => 'cgc_creation', 'payload' => ['name' => 'Fixture '.$n, 'charter' => 'Public act'], 'status' => 'open']);
        }
        ChamberVoteProposal::create(['id' => $this->id(2000), 'legislature_id' => $this->id(99), 'proposal_kind' => 'cgc_creation', 'payload' => ['name' => 'Foreign'], 'status' => 'open']);
        DB::connection()->enableQueryLog();
        $first = $this->page(null, 'proposals', '?action=create-cgc')['proposals'];
        self::assertCount(20, $first['records']);
        self::assertSame('Fixture 43', $first['records'][0]['name']);
        $second = $this->page(null, 'proposals', $first['pagination']['next'])['proposals'];
        $third = $this->page(null, 'proposals', $second['pagination']['next'])['proposals'];
        self::assertCount(20, $second['records']);
        self::assertCount(3, $third['records']);
        self::assertNull($third['pagination']['next']);
        self::assertSame($first, $this->page(null, 'proposals', $second['pagination']['previous'])['proposals']);
        self::assertStringContainsString('action=create-cgc', $second['pagination']['first']);
        $sql = strtolower(implode(' ', array_column(DB::getQueryLog(), 'query')));
        self::assertStringNotContainsString('count(', $sql);
        self::assertStringNotContainsString('multi_jurisdiction_votes', $sql);
        self::assertStringNotContainsString('offset ', $sql);
        self::assertStringContainsString('limit 21', $sql);
        DB::disableQueryLog();
        foreach (['?acts_cursor=invalid', '?action[]=bad'] as $url) {
            try {
                $this->page(null, 'proposals', $url);
                self::fail('Bad query accepted');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
        $other = Legislature::create(['id' => $this->id(999), 'jurisdiction_id' => $this->id(1), 'status' => 'active']);
        try {
            $this->reader->proposals($this->request(null, 'GET', [], $first['pagination']['next']), $other);
            self::fail('Cross-scope cursor accepted');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors());
        }
    }

    public static function processKinds(): array
    {
        return ['executive' => ['exec_office_create'], 'court' => ['judiciary_convert']];
    }

    #[DataProvider('processKinds')]
    public function test_constituent_process_browsing_pages_and_opens_only_the_local_real_consent_once(string $kind): void
    {
        $process = $this->process();
        $process->forceFill(['kind' => $kind])->save();
        foreach (range(1, 43) as $n) {
            $place = $n === 43 ? $this->id(1) : $this->id(4000 + $n);
            if ($n !== 43) {
                DB::table('jurisdictions')->insert(['id' => $place, 'name' => 'Constituent '.$n, 'slug' => 'constituent-'.$n]);
            }
            ConstituentConsent::create(['id' => $this->id(5000 + $n), 'process_id' => $process->id, 'jurisdiction_id' => $place, 'result' => 'pending']);
        }
        $first = $this->page(101, 'processes,constituents', '?process='.$process->id);
        self::assertTrue($first['processes']['records'][0]['canOpen']);
        self::assertCount(20, $first['constituents']['records']);
        self::assertSame($this->reader->base($this->leg).'?process='.$process->id, $first['constituents']['records'][0]['href']);
        self::assertCount(20, $this->page(101, 'constituents', $first['constituents']['pagination']['next'])['constituents']['records']);
        $this->controller->consent($this->request(101, 'POST'), $this->leg, $process);
        $vote = ChamberVote::sole();
        self::assertSame('procedural_motion', $vote->vote_type);
        self::assertSame('constituent_consent', $vote->votable_type);
        self::assertSame($this->leg->id, $vote->body_id);
        self::assertSame($this->leg->id, ConstituentConsent::where('jurisdiction_id', $this->id(1))->sole()->legislature_id);
        self::assertFalse($this->page(101, 'processes')['processes']['records'][0]['canOpen']);
        $this->refused(fn () => $this->controller->consent($this->request(101, 'POST'), $this->leg, $process));
        self::assertSame(1, ChamberVote::count());
        Bus::assertNothingDispatched();
        $foreign = $this->process(9001);
        $foreign->forceFill(['initiating_legislature_id' => $this->id(99)])->save();
        try {
            $this->controller->consent($this->request(101, 'POST'), $this->leg, $foreign);
            self::fail('Foreign process accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    public function test_exact_vote_references_and_existing_constituent_chamber_links_are_preserved(): void
    {
        $this->controller->store($this->request(101, 'POST', ['action' => 'create-cgc', 'name' => 'Public garden', 'charter' => 'Public space', 'owner_seats' => 1]), $this->leg);
        $vote = ChamberVote::sole();
        $row = fn () => $this->reader->proposals($this->request(101), $this->leg)['records'][0];
        self::assertTrue($row()['vote']['can_cast']);
        foreach (['body_type' => 'board', 'body_id' => $this->id(99), 'legislature_id' => $this->id(99), 'jurisdiction_id' => $this->id(99), 'stage' => 'committee', 'votable_id' => $this->id(99), 'votable_type' => 'bill'] as $key => $bad) {
            $old = $vote->getAttribute($key);
            $vote->forceFill([$key => $bad])->save();
            self::assertNull($row()['vote']);
            $vote->forceFill([$key => $old])->save();
        }
        ChamberVoteProposal::query()->update(['status' => 'rejected']);
        self::assertFalse($row()['vote']['can_cast']);
        $process = $this->process();
        $exact = Legislature::create(['id' => $this->id(8000), 'jurisdiction_id' => $this->id(1), 'status' => 'active']);
        ConstituentConsent::create(['id' => $this->id(6000), 'process_id' => $process->id, 'jurisdiction_id' => $this->id(1), 'legislature_id' => $exact->id, 'result' => 'yes']);
        self::assertSame($this->reader->base($exact).'?process='.$process->id, $this->page(101, 'constituents', '?process='.$process->id)['constituents']['records'][0]['href']);
    }

    public function test_conversion_history_pages_are_scoped_and_do_not_recompute_constituent_totals(): void
    {
        foreach (range(1, 43) as $n) {
            $this->process(9000 + $n);
        }
        $foreign = $this->process(9999);
        $foreign->forceFill(['initiating_legislature_id' => $this->id(99)])->save();
        $first = $this->page(null, 'processes')['processes'];
        self::assertCount(20, $first['records']);
        self::assertSame($this->id(9043), $first['records'][0]['id']);
        self::assertSame(43, $first['records'][0]['total']);
        $second = $this->page(null, 'processes', $first['pagination']['next'])['processes'];
        self::assertCount(20, $second['records']);
        self::assertCount(3, $this->page(null, 'processes', $second['pagination']['next'])['processes']['records']);
        self::assertSame($first, $this->page(null, 'processes', $second['pagination']['previous'])['processes']);
    }

    public function test_an_older_linked_consent_is_immediately_actionable_in_its_own_constituent_chamber(): void
    {
        foreach (range(1, 25) as $n) {
            $this->process(9000 + $n);
        }
        $older = $this->process(8000);
        $older->forceFill(['initiating_legislature_id' => $this->id(99)])->save();
        ConstituentConsent::create(['id' => $this->id(6000), 'process_id' => $older->id, 'jurisdiction_id' => $this->leg->jurisdiction_id, 'result' => 'pending']);
        $selected = $this->page(101, 'processes,constituents', '?process='.$older->id);
        self::assertCount(1, $selected['processes']['records']);
        self::assertSame($older->id, $selected['processes']['records'][0]['id']);
        self::assertTrue($selected['processes']['records'][0]['canOpen']);
        $this->controller->consent($this->request(101, 'POST'), $this->leg, $older);
        self::assertSame($this->id(6000), ChamberVote::sole()->votable_id);
        $older->delete();
        self::assertCount(0, $this->page(101, 'processes', '?process='.$older->id)['processes']['records']);
    }

    public function test_speaker_controls_match_the_real_resolvable_lane_and_tie_rejection(): void
    {
        $this->leg->forceFill(['speaker_id' => $this->id(305)])->save();
        $this->controller->store($this->request(101, 'POST', ['action' => 'create-cgc', 'name' => 'Public garden', 'charter' => 'Public green space', 'owner_seats' => 1]), $this->leg);
        $vote = ChamberVote::sole();
        foreach ([101 => 'yes', 102 => 'yes', 103 => 'no', 104 => 'no'] as $actor => $value) {
            $this->cast($vote, $actor, $value);
        }
        self::assertSame('tied', $vote->refresh()->outcome);
        self::assertTrue($this->reader->proposals($this->request(105), $this->leg)['records'][0]['vote']['can_tiebreak']);
        self::assertFalse($this->reader->context($this->leg, User::find($this->id(105)))['canCast']);
        $this->refused(fn () => $this->engine->file('F-SPK-004', User::find($this->id(101)), ['vote_id' => $vote->id, 'jurisdiction_id' => $this->id(1), 'value' => 'no']));
        $this->engine->file('F-SPK-004', User::find($this->id(105)), ['vote_id' => $vote->id, 'jurisdiction_id' => $this->id(1), 'value' => 'no']);
        self::assertSame('failed', $vote->refresh()->outcome);
        self::assertSame('rejected', ChamberVoteProposal::sole()->status);
        self::assertSame(1, VoteCast::where('is_tiebreak', true)->count());
        self::assertFalse($this->reader->proposals($this->request(105), $this->leg)['records'][0]['vote']['can_tiebreak']);
    }

    private function process(int $n = 9000): MultiJurisdictionVote
    {
        return MultiJurisdictionVote::create(['id' => $this->id($n), 'kind' => 'exec_office_create', 'initiating_legislature_id' => $this->leg->id,
            'subject_type' => 'executives', 'subject_id' => $this->id(3), 'status' => 'open', 'constituent_total' => 43, 'required' => 29, 'yes_count' => 0, 'no_count' => 0]);
    }

    private function cast(ChamberVote $vote, int $actor, string $value): void
    {
        $controller = new SessionController($this->engine, new ChamberVotePresenter, app(RoleService::class));
        $controller->cast($this->request($actor, 'POST', ['value' => $value]), $vote);
    }

    private function page(?int $actor, string $only, string $url = ''): array
    {
        $request = $this->request($actor, 'GET', [], str_starts_with($url, '/') ? $url : $this->reader->base($this->leg).$url);
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Component', 'Legislature/InstitutionActs');
        $request->headers->set('X-Inertia-Partial-Data', $only);

        return $this->controller->show($request, $this->leg)->toResponse($request)->getData(true)['props'];
    }

    private function request(?int $actor = 101, string $method = 'GET', array $payload = [], string $url = '/'): Request
    {
        $request = Request::create($url, $method, $payload);
        $request->setUserResolver(fn () => $actor === null ? null : User::findOrFail($this->id($actor)));

        return $request;
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            self::fail('Invalid action accepted');
        } catch (ConstitutionalViolation $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    private function id(int $n): string
    {
        return sprintf('80000000-0000-4000-8000-%012d', $n);
    }
}
