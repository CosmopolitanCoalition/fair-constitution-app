<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\CandidateEndorsementGrant;
use App\Http\Controllers\Social\PersonProfileController;
use App\Http\Presenters\CandidacyPanel;
use App\Models\Candidacy;
use App\Models\ElectionRace;
use App\Models\Endorsement;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\JourneyService;
use App\Services\RoleService;
use App\Services\Social\PrivateRoomService;
use App\Support\CandidacyEndorsementDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Named, private SQLite only. No world writes or live-PG test helpers. */
class CandidacyEndorsementDirectoryTest extends TestCase
{
    use \Tests\Concerns\AchievementSchema;

    private string $original;
    private Candidacy $candidate;
    private CandidacyEndorsementDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.candidacy_directory_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('candidacy_directory_fixture');
        $this->createAchievementTables(); // AC-1: the wired handlers read the ledger before they award
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $s = DB::connection()->getSchemaBuilder();
        $s->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name')->default('Secret legal name'); $t->string('display_name')->nullable(); $t->softDeletes();
        });
        $s->create('social_profiles', function (Blueprint $t) {
            $t->uuid('user_id')->unique(); $t->string('display_name')->nullable(); $t->string('handle')->nullable();
            $t->string('visibility')->default('private'); $t->softDeletes();
        });
        $s->create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('user_id'); $t->uuid('election_id'); $t->uuid('race_id');
            $t->string('status')->default('validated'); $t->timestamps(); $t->softDeletes();
        });
        $s->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('type')->default('nonprofit');
            $t->uuid('agent_user_id')->nullable(); $t->boolean('is_active')->default(true); $t->softDeletes();
        });
        $s->create('endorsements', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('candidate_id'); $t->uuid('election_id'); $t->uuid('endorser_id');
            $t->string('endorser_type'); $t->boolean('is_active')->default(true); $t->boolean('is_public')->default(true);
            $t->text('statement')->nullable(); $t->timestamp('endorsed_at')->nullable(); $t->timestamp('withdrawn_at')->nullable(); $t->timestamps();
            $t->unique(['election_id', 'candidate_id', 'endorser_type', 'endorser_id']);
        });
        $s->create('endorsement_requests', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('candidacy_id'); $t->uuid('organization_id'); $t->string('status')->default('pending');
            $t->timestamp('requested_at'); $t->timestamp('decided_at')->nullable(); $t->uuid('endorsement_id')->nullable(); $t->timestamps();
        });
        $s->create('approval_standings', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('race_id'); $t->uuid('candidacy_id'); $t->date('as_of_date');
            $t->integer('rank'); $t->integer('approvals_count'); $t->boolean('is_frozen')->default(false);
        });
        $this->user(1);
        $this->candidate = $this->candidacy(10, 1);
        $this->directory = new CandidacyEndorsementDirectory;
    }

    protected function tearDown(): void
    {
        DB::purge('candidacy_directory_fixture'); DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('70000000-0000-4000-8000-%012d', $n); }
    private function user(int $n): User
    {
        DB::table('users')->insert(['id' => $this->id($n), 'display_name' => 'Public person '.$n]);
        return User::findOrFail($this->id($n));
    }
    private function candidacy(int $n, int $user, array $extra = []): Candidacy
    {
        DB::table('candidacies')->insert($extra + ['id' => $this->id($n), 'user_id' => $this->id($user),
            'election_id' => $this->id(2), 'race_id' => $this->id(3), 'created_at' => '2026-09-13']);
        return Candidacy::findOrFail($this->id($n));
    }
    private function endorse(int $n, int $endorser, string $type = 'user', array $extra = []): void
    {
        DB::table('endorsements')->insert($extra + ['id' => $this->id($n), 'candidate_id' => $this->candidate->id,
            'election_id' => $this->candidate->election_id, 'endorser_id' => $this->id($endorser), 'endorser_type' => $type,
            'endorsed_at' => '2026-09-13']);
    }
    private function request(string $url = '/people', ?User $viewer = null): Request
    {
        $r = Request::create($url); $r->setUserResolver(fn () => $viewer); return $r;
    }

    public function test_all_organization_and_individual_pages_are_reachable_in_both_directions(): void
    {
        for ($i = 100; $i < 145; $i++) {
            $this->user($i);
            DB::table('organizations')->insert(['id' => $this->id($i), 'name' => 'Organization '.$i]);
            $this->endorse($i + 1000, $i, $i % 2 ? 'users' : 'user');
            $this->endorse($i + 2000, $i, $i % 2 ? 'organizations' : 'organization');
        }
        $this->candidacy(999, 144);
        foreach (['organizations' => 'id', 'individuals' => 'user_id'] as $method => $column) {
            $pages = []; $url = '/people'; $rows = [];
            do {
                $page = $this->directory->$method($this->request($url), $this->candidate);
                self::assertLessThanOrEqual(20, count($page['rows']));
                $pages[] = $page; array_push($rows, ...$page['rows']); $url = $page['pages']['next'];
            } while ($url);
            self::assertCount(3, $pages); self::assertCount(45, $rows);
            self::assertCount(45, array_unique(array_column($rows, $column)));
            self::assertSame($pages[0]['rows'], $this->directory->$method($this->request($pages[1]['pages']['previous']), $this->candidate)['rows']);
            if ($method === 'individuals') {
                self::assertTrue($rows[0]['alsoCandidate']); self::assertFalse($rows[1]['alsoCandidate']);
                self::assertSame(['total' => 45, 'public' => 45, 'private' => 0], $page['counts']);
                self::assertArrayNotHasKey('endorses', $rows[0]);
            }
        }
    }

    public function test_canonical_private_or_withdrawn_rows_never_resurrect_public_legacy_duplicates(): void
    {
        foreach ([100, 101, 102, 103] as $id) $this->user($id);
        $this->endorse(1000, 100, 'users'); $this->endorse(1001, 100, 'user', ['is_public' => false]);
        $this->endorse(1002, 101, 'users'); $this->endorse(1003, 101, 'user', ['withdrawn_at' => '2026-09-13']);
        $this->endorse(1004, 102, 'users'); $this->endorse(1005, 102, 'user');
        $this->endorse(1006, 103, 'users', ['is_active' => false]);
        $page = $this->directory->individuals($this->request(), $this->candidate);
        self::assertSame(['total' => 2, 'public' => 1, 'private' => 1], $page['counts']);
        self::assertSame([$this->id(102)], array_column($page['rows'], 'user_id'));
        self::assertStringNotContainsString($this->id(100), json_encode($page));
        foreach ([100, 101, 103] as $id) {
            $web = $this->directory->web($this->request('/people?public_endorser='.$this->id($id)), $this->candidate);
            self::assertNull($web['endorser']); self::assertSame([], $web['rows']);
            self::assertStringNotContainsString($this->id($id), json_encode($web));
        }
        self::assertSame([], $this->directory->given($this->request(), $this->id(100))['rows']);
    }

    public function test_public_names_respect_private_social_fields_and_legal_names(): void
    {
        $this->user(100); DB::table('users')->where('id', $this->id(100))->update(['display_name' => null]);
        DB::table('social_profiles')->insert(['user_id' => $this->id(100), 'display_name' => 'Secret social name', 'handle' => 'secret-handle', 'visibility' => 'private']);
        $this->endorse(1000, 100, 'users');
        $page = $this->directory->individuals($this->request(), $this->candidate);
        self::assertStringStartsWith('Resident-', $page['rows'][0]['name']);
        self::assertStringNotContainsString('Secret', json_encode($page));
        self::assertStringNotContainsString('secret-handle', json_encode($page));
    }

    public function test_only_the_selected_public_endorsers_same_election_connections_expand_and_page(): void
    {
        $this->user(100); $this->user(101); $this->endorse(1000, 100, 'users'); $this->endorse(1001, 101);
        for ($i = 200; $i < 245; $i++) {
            $this->user($i); $this->candidacy($i + 1000, $i);
            $this->endorse($i + 2000, 100, $i % 2 ? 'users' : 'user', ['candidate_id' => $this->id($i + 1000)]);
        }
        $this->candidacy(1300, 101); $this->endorse(2300, 100, 'user', ['candidate_id' => $this->id(1300), 'is_public' => false]);
        $this->candidacy(1301, 101); $this->endorse(2301, 101, 'user', ['candidate_id' => $this->id(1301)]);
        $this->candidacy(1302, 101, ['election_id' => $this->id(9)]);
        $this->endorse(2302, 100, 'users', ['candidate_id' => $this->id(1302), 'election_id' => $this->id(9)]);
        DB::enableQueryLog();
        self::assertNull($this->directory->web($this->request(), $this->candidate));
        self::assertSame([], DB::getQueryLog());
        $url = '/people?public_endorser='.$this->id(100); $rows = []; $pages = [];
        do {
            $page = $this->directory->web($this->request($url), $this->candidate);
            self::assertSame($this->id(100), $page['endorser']['user_id']);
            self::assertLessThanOrEqual(20, count($page['rows']));
            array_push($rows, ...$page['rows']); $pages[] = $page; $url = $page['pages']['next'];
        } while ($url);
        self::assertCount(45, $rows); self::assertCount(45, array_unique(array_column($rows, 'candidacy_id')));
        self::assertSame($pages[0]['rows'], $this->directory->web($this->request($pages[1]['pages']['previous']), $this->candidate)['rows']);
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'join "candidacies"')) {
                self::assertStringContainsString('limit 21', $query['query']);
                self::assertStringContainsString('"endorsements"."endorser_id" = ?', $query['query']);
                self::assertStringContainsString('"endorsements"."election_id" = ?', $query['query']);
            }
        }
        DB::table('endorsements')->where('id', $this->id(1000))->update(['is_public' => false]);
        self::assertNull($this->directory->web($this->request($pages[0]['pages']['next']), $this->candidate)['endorser']);
    }

    public function test_given_public_endorsements_page_across_elections_without_leaking_private_rows(): void
    {
        for ($i = 100; $i < 143; $i++) {
            $this->user($i); $this->candidacy($i + 1000, $i, ['election_id' => $this->id($i + 3000)]);
            $this->endorse($i + 2000, 1, $i % 2 ? 'users' : 'user', ['candidate_id' => $this->id($i + 1000), 'election_id' => $this->id($i + 3000)]);
        }
        $this->endorse(9999, 1, 'users', ['is_public' => false]);
        $url = '/people'; $rows = [];
        do {
            $page = $this->directory->given($this->request($url), $this->id(1));
            array_push($rows, ...$page['rows']); $url = $page['pages']['next'];
        } while ($url);
        self::assertCount(43, $rows); self::assertNotContains($this->candidate->id, array_column($rows, 'candidacy_id'));
    }

    public function test_requests_page_for_the_owner_only(): void
    {
        for ($i = 100; $i < 143; $i++) {
            DB::table('organizations')->insert(['id' => $this->id($i), 'name' => 'Organization '.$i]);
            DB::table('endorsement_requests')->insert(['id' => $this->id($i + 1000), 'candidacy_id' => $this->candidate->id,
                'organization_id' => $this->id($i), 'requested_at' => '2026-09-13']);
        }
        $viewer = $this->user(99); DB::enableQueryLog();
        self::assertNull($this->directory->requests($this->request(), $this->candidate, null));
        self::assertNull($this->directory->requests($this->request(), $this->candidate, $viewer));
        self::assertSame([], DB::getQueryLog());
        $url = '/people'; $rows = [];
        do {
            $page = $this->directory->requests($this->request($url), $this->candidate, User::find($this->id(1)));
            array_push($rows, ...$page['rows']); $url = $page['pages']['next'];
        } while ($url);
        self::assertCount(43, $rows);
    }

    public function test_bookmarks_cannot_cross_candidacies_readers_or_selected_endorsers(): void
    {
        $this->user(100); $this->user(101);
        for ($i = 200; $i < 222; $i++) { $this->user($i); $this->endorse($i + 1000, $i); }
        $first = $this->directory->individuals($this->request(), $this->candidate);
        $other = $this->candidacy(11, 101);
        $page = $this->directory->individuals($this->request($first['pages']['next']), $other);
        self::assertNotNull($page['notice']); self::assertSame([], $page['rows']);
        parse_str(parse_url($first['pages']['next'], PHP_URL_QUERY), $params);
        $switched = $this->directory->given($this->request('/people?endorsement_given_cursor='.$params['endorsement_people_cursor']), $this->id(1));
        self::assertNotNull($switched['notice']);
        foreach (['bad', ['array'], str_repeat('x', 2100)] as $token) {
            $page = $this->directory->individuals($this->request('/people?'.http_build_query(['endorsement_people_cursor' => $token])), $this->candidate);
            self::assertNotNull($page['notice']); self::assertSame($first['rows'], $page['rows']);
        }
        $this->endorse(1500, 100); $this->endorse(1501, 101);
        for ($i = 200; $i < 222; $i++) {
            $this->candidacy($i + 2000, $i); $this->endorse($i + 3000, 100, 'user', ['candidate_id' => $this->id($i + 2000)]);
        }
        $web = $this->directory->web($this->request('/people?public_endorser='.$this->id(100)), $this->candidate);
        $otherUrl = str_replace('public_endorser='.$this->id(100), 'public_endorser='.$this->id(101), $web['pages']['next']);
        $page = $this->directory->web($this->request($otherUrl), $this->candidate);
        self::assertNotNull($page['notice']); self::assertSame([], $page['rows']);
    }

    public function test_bounded_standing_matches_full_snapshot_thresholds_and_frozen_priority(): void
    {
        $race = (new ElectionRace)->forceFill(['id' => $this->candidate->race_id, 'finalist_count' => 9]);
        $approvals = new ApprovalService($this->createMock(AuditService::class));
        $panel = new CandidacyPanel($approvals);
        $read = new \ReflectionMethod($panel, 'standingFor');
        self::assertNull($read->invoke($panel, $this->candidate, $race, 'approval'));
        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $day) {
            for ($rank = 1; $rank <= 45; $rank++) DB::table('approval_standings')->insert([
                'id' => $this->id((int) substr($day, -2) * 100 + $rank), 'race_id' => $race->id,
                'candidacy_id' => $rank === 17 ? $this->candidate->id : $this->id($rank + 500),
                'as_of_date' => $day, 'rank' => $rank, 'approvals_count' => 100 - $rank, 'is_frozen' => $day === '2026-09-11',
            ]);
        }
        foreach ([9, 100] as $finalists) {
            $race->finalist_count = $finalists;
            $full = $approvals->standings($race); $mine = $full->firstWhere('candidacy_id', $this->candidate->id);
            DB::enableQueryLog(); DB::flushQueryLog();
            $summary = $read->invoke($panel, $this->candidate, $race, 'approval');
            self::assertSame(['rank' => 17, 'of' => $full->count(), 'approvals' => 83, 'isFinalist' => 17 <= $finalists,
                'lineApprovals' => $full->firstWhere('rank', min($finalists, $full->count()))->approvals_count,
                'topApprovals' => $full->first()->approvals_count, 'frozen' => true, 'asOf' => '2026-09-11'], $summary);
            foreach (DB::getQueryLog() as $q) self::assertTrue(str_contains($q['query'], 'limit 1') || str_contains($q['query'], 'aggregate'));
        }
        $this->candidate->status = 'elected'; self::assertTrue($read->invoke($panel, $this->candidate, $race, 'closed')['isFinalist']);
        $this->candidate->status = 'withdrawn'; self::assertFalse($read->invoke($panel, $this->candidate, $race, 'closed')['isFinalist']);
        DB::table('approval_standings')->update(['is_frozen' => false]);
        self::assertSame('2026-09-12', $read->invoke($panel, $this->candidate, $race, 'approval')['asOf']);
        $this->candidate->id = $this->id(99999); self::assertNull($read->invoke($panel, $this->candidate, $race, 'approval'));
    }

    public function test_compatibility_writer_reuses_legacy_and_canonical_rows_and_rolls_back(): void
    {
        foreach (['users', 'organizations'] as $index => $type) {
            $id = 100 + $index; $this->user($id);
            DB::table('organizations')->insert(['id' => $this->id($id), 'name' => 'Organization '.$id]);
            $this->endorse(1000 + $index, $id, $type);
            $updated = Endorsement::recordFor($this->candidate, $type, $this->id($id), ['is_public' => false]);
            self::assertSame($this->id(1000 + $index), $updated->id);
            self::assertSame(rtrim($type, 's'), $updated->endorser_type);
            $again = Endorsement::recordFor($this->candidate, rtrim($type, 's'), $this->id($id), ['statement' => 'Updated']);
            self::assertSame($updated->id, $again->id);
            self::assertSame(1, Endorsement::where('endorser_id', $this->id($id))->count());
        }
        $this->endorse(1100, 100, 'users');
        Endorsement::recordFor($this->candidate, 'users', $this->id(100), ['is_public' => false]);
        self::assertSame(2, Endorsement::where('endorser_id', $this->id(100))->count());
        self::assertSame([], $this->directory->individuals($this->request(), $this->candidate)['rows']);
        try { DB::transaction(function () { Endorsement::recordFor($this->candidate, 'user', $this->id(1), ['is_public' => true]); throw new \RuntimeException('abort'); }); }
        catch (\RuntimeException $e) { self::assertSame('abort', $e->getMessage()); }
        self::assertFalse(Endorsement::where('endorser_id', $this->id(1))->exists());
    }

    public function test_existing_org_grant_reuses_simulated_endorsement_and_preserves_authority(): void
    {
        $agent = $this->user(100);
        DB::table('organizations')->insert(['id' => $this->id(200), 'name' => 'Public organization', 'agent_user_id' => $agent->id]);
        $this->endorse(1000, 200, 'organizations', ['withdrawn_at' => '2026-09-12', 'is_active' => false]);
        DB::table('endorsement_requests')->insert(['id' => $this->id(500), 'candidacy_id' => $this->candidate->id, 'organization_id' => $this->id(200), 'requested_at' => '2026-09-13']);
        $roles = $this->createMock(RoleService::class); $roles->expects($this->once())->method('flushUser')->with($this->id(1));
        $handler = new CandidateEndorsementGrant($roles);
        try { $handler->handle(User::find($this->id(1)), ['request_id' => $this->id(500), 'decision' => 'grant']); self::fail('Wrong agent allowed'); }
        catch (ConstitutionalViolation $e) { self::assertStringContainsString('Only the agent', $e->getMessage()); }
        $result = DB::transaction(fn () => $handler->handle($agent, ['request_id' => $this->id(500), 'decision' => 'grant']));
        self::assertSame($this->id(1000), $result['endorsement_id']); self::assertSame(1, Endorsement::count());
        self::assertCount(1, $this->directory->organizations($this->request(), $this->candidate)['rows']);
        self::assertSame('granted', DB::table('endorsement_requests')->value('status'));
        self::assertTrue(Endorsement::first()->is_public);
    }

    public function test_actual_partial_visits_do_not_build_standings_histories_or_other_directories(): void
    {
        $this->user(100); $this->endorse(1000, 100, 'users');
        $panel = $this->createMock(CandidacyPanel::class); $panel->expects($this->never())->method('for');
        $controller = new PersonProfileController($panel, $this->createMock(JourneyService::class),
            $this->createMock(ConstitutionalEngine::class), $this->createMock(PrivateRoomService::class));
        foreach (['endorsementIndividuals', 'endorsementWeb', 'endorsementOrganizations', 'endorsementRequests', 'endorsementsGiven'] as $prop) {
            $r = $this->request('/people?who='.$this->id(1).'&tab=candidacy&candidacy='.$this->candidate->id.'&public_endorser='.$this->id(100));
            foreach (['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Social/PersonProfile', 'X-Inertia-Partial-Data' => $prop] as $key => $value) $r->headers->set($key, $value);
            DB::enableQueryLog(); DB::flushQueryLog();
            $props = $controller->show($r)->toResponse($r)->getData(true)['props'];
            self::assertArrayHasKey($prop, $props); self::assertArrayNotHasKey('candidacyPanel', $props);
            self::assertArrayNotHasKey('record', $props); self::assertArrayNotHasKey('candidacies', $props);
            foreach (DB::getQueryLog() as $q) {
                self::assertStringNotContainsString('approval_standings', $q['query']);
                self::assertStringNotContainsString('audit_log', $q['query']);
                if (str_contains($q['query'], 'from "candidacies"') && ! str_contains($q['query'], 'exists')) {
                    self::assertTrue(str_contains($q['query'], 'limit 1') || str_contains($q['query'], '"user_id" in'));
                }
            }
        }
    }
}
