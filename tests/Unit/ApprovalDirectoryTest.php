<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Elections\ApprovalController;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Support\ApprovalDirectory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Private SQLite fixtures only; no live PostgreSQL, rollup, or world mutation. */
final class ApprovalDirectoryTest extends TestCase
{
    private string $originalConnection;

    private ApprovalDirectory $directory;

    private ElectionRace $race;

    private User $viewer;

    private string $electionId;

    private string $raceId;

    private string $jurisdictionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.approval_directory_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('approval_directory_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('display_name')->nullable();
            $t->softDeletes();
        });
        $schema->create('social_profiles', function (Blueprint $t) {
            $t->string('user_id')->unique();
            $t->string('display_name')->nullable();
            $t->string('handle')->nullable();
            $t->string('visibility')->default('private');
            $t->softDeletes();
        });
        $schema->create('elections', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id');
            $t->string('legislature_id')->nullable();
            $t->string('status');
            $t->softDeletes();
        });
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->softDeletes();
        });
        $schema->create('election_races', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('election_id');
            $t->string('jurisdiction_id');
            $t->string('district_id')->nullable();
            $t->string('type_b_panel_id')->nullable();
            $t->softDeletes();
        });
        $schema->create('candidacies', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('race_id');
            $t->string('election_id');
            $t->string('user_id');
            $t->string('status');
            $t->text('platform_statement')->nullable();
            $t->text('position_tags')->nullable();
            $t->timestamp('validated_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('approval_standings', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('race_id');
            $t->string('candidacy_id');
            $t->date('as_of_date');
            $t->integer('rank');
            $t->integer('approvals_count');
            $t->integer('delta')->default(0);
            $t->boolean('is_frozen')->default(false);
            $t->unique(['candidacy_id', 'as_of_date']);
        });
        $schema->create('approvals', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('election_id');
            $t->string('candidacy_id');
            $t->string('user_id');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
        });
        $schema->create('organizations', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('type');
            $t->softDeletes();
        });
        $schema->create('endorsements', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('candidate_id');
            $t->string('election_id');
            $t->string('endorser_type');
            $t->string('endorser_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_public')->default(false);
            $t->timestamp('withdrawn_at')->nullable();
        });
        $schema->create('legislature_members', function (Blueprint $t) {
            $t->string('legislature_id');
            $t->string('user_id');
            $t->string('status');
            $t->softDeletes();
        });
        foreach (['legislature_district_jurisdictions' => 'district_id', 'legislature_type_b_panel_jurisdictions' => 'panel_id'] as $table => $key) {
            $schema->create($table, function (Blueprint $t) use ($key) {
                $t->string($key);
                $t->string('jurisdiction_id');
            });
        }
        $schema->create('residency_confirmations', function (Blueprint $t) {
            $t->string('user_id');
            $t->string('jurisdiction_id');
            $t->boolean('is_active');
            $t->integer('depth');
        });
        $this->electionId = $this->id(1);
        $this->raceId = $this->id(2);
        $this->jurisdictionId = $this->id(3);
        DB::table('elections')->insert(['id' => $this->electionId, 'jurisdiction_id' => $this->jurisdictionId,
            'legislature_id' => $this->id(4), 'status' => Election::STATUS_APPROVAL_OPEN]);
        DB::table('election_races')->insert(['id' => $this->raceId, 'election_id' => $this->electionId, 'jurisdiction_id' => $this->jurisdictionId]);
        DB::table('users')->insert(['id' => $this->id(5), 'name' => 'Private Viewer']);
        $this->viewer = User::findOrFail($this->id(5));
        $this->race = ElectionRace::with('election')->findOrFail($this->raceId);
        $this->directory = new ApprovalDirectory;
    }

    protected function tearDown(): void
    {
        DB::purge('approval_directory_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_every_candidate_is_reachable_in_constant_pages_and_full_cannot_remove_the_limit(): void
    {
        for ($i = 1; $i <= 97; $i++) {
            $this->candidate($i, $i <= 90 ? $i : null);
        }
        $first = $this->page(['full' => 1]);
        self::assertCount(20, $first['standings']);
        self::assertSame(97, $first['total']);
        $seen = array_column($first['standings'], 'candidacy_id');
        $current = $first;
        for ($page = 0; $current['pagination']['next'] !== null; $page++) {
            self::assertLessThan(6, $page, 'Cursor must advance.');
            $current = $this->follow($current['pagination']['next']);
            self::assertLessThanOrEqual(20, count($current['standings']));
            array_push($seen, ...array_column($current['standings'], 'candidacy_id'));
        }
        self::assertCount(97, array_unique($seen));
        self::assertSame(array_map(fn ($i) => $this->id(1000 + $i), range(1, 97)), $seen);
        self::assertNull($current['standings'][16]['rank']);
        $second = $this->follow($first['pagination']['next']);
        self::assertSame($first['standings'], $this->follow($second['pagination']['previous'])['standings']);
    }

    public function test_search_covers_candidates_and_endorsements_beyond_the_initial_page(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->candidate($i, $i);
        }
        DB::table('candidacies')->where('id', $this->id(1044))->update(['platform_statement' => 'Safe 100% water', 'position_tags' => '["biodiversity"]']);
        DB::table('users')->where('id', $this->id(2044))->update(['display_name' => 'River Representative']);
        for ($i = 1; $i <= 30; $i++) {
            $this->organization(44, $i, 'Organization '.$i);
        }
        $this->organization(44, 31, 'Beyond Preview');
        foreach (['River Representative', 'biodiversity', '100%'] as $q) {
            self::assertSame([$this->id(1044)], array_column($this->page(['q' => $q])['standings'], 'candidacy_id'));
        }
        self::assertSame([], $this->page(['q' => '100_'])['standings'], 'LIKE wildcards are literal input.');
        $result = $this->page(['organization' => 'Beyond Preview']);
        self::assertSame([$this->id(1044)], array_column($result['standings'], 'candidacy_id'));
        self::assertCount(3, $result['standings'][0]['candidacy']['endorsements']['orgs']);
        self::assertTrue($result['standings'][0]['candidacy']['endorsements']['more_organizations']);
    }

    public function test_endorsement_pages_expose_only_active_public_organizations_and_preserve_candidate_search(): void
    {
        $candidate = $this->candidate(1, 1);
        for ($i = 1; $i <= 43; $i++) {
            $this->organization(1, $i, 'Organization '.$i);
        }
        $this->organization(1, 44, 'Private organization', ['is_public' => false]);
        $this->organization(1, 45, 'Withdrawn organization', ['withdrawn_at' => '2026-09-01']);
        $this->organization(1, 46, 'Inactive organization', ['is_active' => false]);
        $this->organization(1, 47, 'Deleted organization');
        DB::table('organizations')->where('id', $this->id(5047))->update(['deleted_at' => '2026-09-01']);
        DB::table('endorsements')->insert(['id' => $this->id(6099), 'candidate_id' => $candidate, 'election_id' => $this->electionId,
            'endorser_type' => 'user', 'endorser_id' => $this->id(9999), 'is_active' => true, 'is_public' => false]);
        $first = $this->directory->endorsements($this->request(['endorsements_for' => $candidate, 'q' => 'Candidate']), $this->race);
        self::assertCount(20, $first['organizations']);
        self::assertStringContainsString('q=Candidate', $first['next']);
        $second = $this->directory->endorsements($this->request($this->parameters($first['next'])), $this->race);
        $third = $this->directory->endorsements($this->request($this->parameters($second['next'])), $this->race);
        self::assertCount(3, $third['organizations']);
        self::assertNull($third['next']);
        self::assertSame($first['organizations'], $this->directory->endorsements($this->request($this->parameters($second['previous'])), $this->race)['organizations']);
        $all = [...$first['organizations'], ...$second['organizations'], ...$third['organizations']];
        self::assertCount(43, array_unique(array_column($all, 'id')));
        self::assertStringNotContainsString($this->id(9999), json_encode($all));
        $row = $this->page()['standings'][0];
        self::assertSame(1, $row['candidacy']['endorsements']['individual_count']);
        self::assertStringNotContainsString($this->id(9999), json_encode($row));
        self::assertCount(1, $this->page(['endorser' => 'individuals'])['standings']);
        self::assertCount(0, $this->page(['endorser' => 'none'])['standings']);
    }

    public function test_private_totals_span_pages_but_private_ids_are_scoped_to_the_page_and_viewer(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->candidate($i, $i);
            $this->approval($i, $this->viewer->id);
        }
        $this->approval(3, $this->id(9000));
        DB::table('approvals')->where('candidacy_id', $this->id(1002))->update(['revoked_at' => '2026-09-01']);
        $this->candidate(50, null, $this->id(9998));
        $this->approval(50, $this->viewer->id);
        $first = $this->page();
        $second = $this->follow($first['pagination']['next']);
        self::assertSame(44, $first['myActiveApprovals']);
        self::assertCount(19, $first['myApprovals']);
        self::assertSame(44, $second['myActiveApprovals']);
        self::assertCount(20, $second['myApprovals']);
        self::assertSame([], array_intersect($first['myApprovals'], $second['myApprovals']));
        self::assertSame([], $this->directory->page($this->request(), $this->race, null)['myApprovals']);
        self::assertSame(0, $this->directory->page($this->request(), $this->race, null)['myActiveApprovals']);
        $approved = $this->page(['approved' => 1]);
        self::assertNotContains($this->id(1002), array_column($approved['standings'], 'candidacy_id'));
        self::assertSame(array_column($approved['standings'], 'candidacy_id'), $approved['myApprovals']);
        self::assertSame([], $this->directory->page($this->request(['approved' => 1]), $this->race, null)['standings']);
    }

    public function test_filters_keep_global_ranks_and_exclude_ineligible_or_removed_records(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->candidate($i, $i);
        }
        DB::table('legislature_members')->insert(['legislature_id' => $this->id(4), 'user_id' => $this->id(2005), 'status' => 'seated']);
        $result = $this->page(['incumbents' => 1]);
        self::assertCount(1, $result['standings']);
        self::assertSame(5, $result['standings'][0]['rank']);
        self::assertTrue($result['standings'][0]['candidacy']['incumbent']);
        DB::table('candidacies')->where('id', $this->id(1001))->update(['deleted_at' => '2026-09-01']);
        DB::table('candidacies')->where('id', $this->id(1002))->update(['status' => 'rejected']);
        $this->organization(3, 1, 'Not public', ['is_public' => false]);
        self::assertCount(4, $this->page()['standings']);
        self::assertCount(4, $this->page(['endorser' => 'none'])['standings']);
        self::assertCount(0, $this->page(['endorser' => 'organizations'])['standings']);
    }

    public function test_repeated_public_names_remain_distinguishable_across_pages_without_disclosing_private_identity(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->candidate($i, $i);
        }
        DB::table('users')->where('id', '!=', $this->viewer->id)->update(['display_name' => 'Same public name']);
        DB::table('social_profiles')->insert([
            ['user_id' => $this->id(2001), 'handle' => 'public-candidate', 'visibility' => 'public'],
            ['user_id' => $this->id(2021), 'handle' => 'private-social-handle', 'visibility' => 'private'],
        ]);
        $first = $this->page(['q' => 'Same public name']);
        $second = $this->follow($first['pagination']['next']);
        $candidates = array_column([...$first['standings'], ...$second['standings']], 'candidacy');
        self::assertCount(22, array_unique(array_column($candidates, 'profile_reference')));
        self::assertCount(22, array_unique(array_column($candidates, 'profile_href')));
        foreach ($candidates as $candidate) {
            self::assertSame('/candidates/'.$candidate['profile_reference'], $candidate['profile_href']);
            self::assertArrayNotHasKey('user_id', $candidate);
            self::assertArrayNotHasKey('residence', $candidate);
            self::assertArrayNotHasKey('wallet', $candidate);
        }
        self::assertSame('@public-candidate', $first['standings'][0]['candidacy']['public_handle']);
        self::assertNull($second['standings'][0]['candidacy']['public_handle']);
        self::assertStringNotContainsString('private-social-handle', json_encode($candidates));
        self::assertStringNotContainsString('Legal name', json_encode($candidates));
        self::assertSame([], $this->page(['q' => 'Legal name'])['standings']);
        self::assertSame([], $this->page(['q' => 'private-social-handle'])['standings']);
        self::assertCount(1, $this->page(['q' => 'public-candidate'])['standings']);
        self::assertCount(1, $this->page(['q' => '@public-candidate'])['standings']);
        self::assertCount(1, $this->page(['q' => $this->id(1021)])['standings']);
    }

    public function test_public_name_fallback_keeps_private_social_handles_and_legal_names_out_of_search(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->candidate($i, $i);
        }
        DB::table('users')->where('id', '!=', $this->viewer->id)->update(['display_name' => null]);
        DB::table('social_profiles')->insert([
            ['user_id' => $this->id(2001), 'display_name' => 'Social pseudonym', 'handle' => 'alternate', 'visibility' => 'public'],
            ['user_id' => $this->id(2002), 'display_name' => null, 'handle' => 'chosen-pseudonym', 'visibility' => 'private'],
        ]);
        DB::table('users')->where('id', $this->id(2004))->update(['deleted_at' => '2026-09-13']);
        $page = $this->page();
        self::assertCount(3, $page['standings']);
        self::assertSame('Social pseudonym', $page['standings'][0]['candidacy']['name']);
        self::assertSame('Resident-'.substr(hash('sha256', $this->id(2002)), 0, 8), $page['standings'][1]['candidacy']['name']);
        self::assertSame('Resident-'.substr(hash('sha256', $this->id(2003)), 0, 8), $page['standings'][2]['candidacy']['name']);
        self::assertSame([], $this->page(['q' => 'Legal name'])['standings']);
        self::assertSame([], $this->page(['q' => 'chosen-pseudonym'])['standings']);
    }

    public function test_frozen_snapshot_takes_precedence_and_public_counts_never_read_live_approvals(): void
    {
        $candidate = $this->candidate(1, 1);
        DB::table('approval_standings')->update(['is_frozen' => true]);
        DB::table('approval_standings')->insert(['id' => $this->id(8000), 'race_id' => $this->raceId, 'candidacy_id' => $candidate,
            'as_of_date' => '2026-09-14', 'rank' => 99, 'approvals_count' => 500]);
        $before = $this->page();
        $this->approval(1, $this->viewer->id);
        $after = $this->page();
        self::assertSame('2026-09-13', $after['asOf']);
        self::assertSame($before['standings'], $after['standings']);
        self::assertSame(1, $after['standings'][0]['rank']);
        self::assertSame(99, $after['standings'][0]['approvals']);
        self::assertSame(0, $before['myActiveApprovals']);
        self::assertSame(1, $after['myActiveApprovals']);
    }

    public function test_cursor_cannot_be_reused_for_different_filters_or_snapshot(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->candidate($i, $i);
        }
        $next = $this->parameters($this->page()['pagination']['next']);
        $changed = $this->page([...$next, 'q' => 'different']);
        self::assertNotNull($changed['notice']);
        self::assertNull($changed['pagination']['previous']);
        DB::table('approval_standings')->update(['as_of_date' => '2026-09-14']);
        $updated = $this->page($next);
        self::assertNotNull($updated['notice']);
        self::assertSame(1, $updated['standings'][0]['rank']);
        self::assertSame($updated['standings'], $this->page(['cursor' => 'malformed'])['standings']);
    }

    public function test_queries_select_fixed_pages_and_never_materialize_race_wide_private_or_endorsement_rows(): void
    {
        for ($i = 1; $i <= 75; $i++) {
            $this->candidate($i, $i);
            $this->approval($i, $this->viewer->id);
        }
        for ($i = 1; $i <= 30; $i++) {
            $this->organization(1, $i, 'Organization '.$i);
        }
        DB::connection()->enableQueryLog();
        $page = $this->page(['full' => 1]);
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        self::assertCount(20, $page['standings']);
        foreach ($queries as $query) {
            $sql = $query['query'];
            if (str_contains($sql, 'from "candidacies" as "c"') && ! str_contains($sql, 'count(')) {
                self::assertStringContainsString('limit 21', $sql);
                self::assertContains($this->raceId, $query['bindings']);
            }
            if (str_contains($sql, 'from "approvals" as "a"')) {
                self::assertContains($this->viewer->id, $query['bindings']);
                if (! str_contains($sql, 'count(')) {
                    self::assertStringContainsString('"a"."candidacy_id" in (', $sql);
                }
            }
            if (str_contains($sql, 'from "endorsements" as "e"')) {
                self::assertStringContainsString('limit 4', $sql);
            }
            if (str_contains($sql, 'from "endorsements" where')) {
                self::assertStringContainsString('"candidate_id" in (', $sql);
                self::assertLessThanOrEqual(22, count($query['bindings']));
            }
        }
    }

    public function test_controller_cast_and_revoke_keep_actor_identity_private_and_refresh_page_switches(): void
    {
        $candidate = $this->candidate(1, 1);
        DB::table('residency_confirmations')->insert(['user_id' => $this->viewer->id, 'jurisdiction_id' => $this->jurisdictionId, 'is_active' => true, 'depth' => 0]);
        $audit = $this->createMock(AuditService::class);
        $audit->expects(self::never())->method('append');
        $controller = new ApprovalController(new ApprovalService($audit));
        $request = $this->request(['candidacy_id' => $candidate, 'user_id' => $this->id(9000)]);
        $request->setUserResolver(fn () => $this->viewer);
        $publicBefore = $this->page()['standings'];
        $controller->store($request, $this->electionId);
        $controller->store($request, $this->electionId);
        self::assertSame(1, DB::table('approvals')->count());
        self::assertSame($this->viewer->id, DB::table('approvals')->value('user_id'));
        self::assertSame([$candidate], $this->page()['myApprovals']);
        $this->approval(1, $this->id(9000));
        $controller->destroy($request, $this->electionId, $candidate);
        self::assertSame([], $this->page()['myApprovals']);
        self::assertSame(0, $this->page()['myActiveApprovals']);
        self::assertSame(1, DB::table('approvals')->where('user_id', $this->id(9000))->whereNull('revoked_at')->count());
        self::assertSame($publicBefore, $this->page()['standings']);
    }

    public function test_controller_serves_page_scoped_private_props_and_loads_endorsements_only_when_requested(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->candidate($i, $i);
            $this->approval($i, $this->viewer->id);
        }
        $this->organization(1, 1, 'Public organization');
        $audit = $this->createMock(AuditService::class);
        $controller = new ApprovalController(new ApprovalService($audit));
        $request = $this->request(['full' => 1, 'endorsements_for' => $this->id(1001)]);
        $request->headers->set('X-Inertia', 'true');
        $request->setUserResolver(fn () => $this->viewer);
        $props = $controller->show($request, $this->electionId)->toResponse($request)->getData(true)['props'];
        self::assertCount(20, $props['standings']);
        self::assertCount(20, $props['myApprovals']);
        self::assertSame(25, $props['stats']['myActiveApprovals']);
        self::assertArrayNotHasKey('endorsementDetails', $props);
        self::assertArrayNotHasKey('standingsTruncated', $props);
        $request->headers->set('X-Inertia-Partial-Component', 'Elections/OpenBallot');
        $request->headers->set('X-Inertia-Partial-Data', 'endorsementDetails');
        $partial = $controller->show($request, $this->electionId)->toResponse($request)->getData(true)['props'];
        self::assertSame(['endorsementDetails'], array_keys($partial));
        self::assertSame('Public organization', $partial['endorsementDetails']['organizations'][0]['name']);
    }

    public function test_endorsement_details_cannot_be_loaded_from_another_race(): void
    {
        $candidate = $this->candidate(1, null, $this->id(9998));
        $this->expectException(ModelNotFoundException::class);
        $this->directory->endorsements($this->request(['endorsements_for' => $candidate]), $this->race);
    }

    public function test_approval_write_rejects_foreign_election_and_missing_footprint_and_closed_phase(): void
    {
        $candidate = $this->candidate(1, 1);
        $audit = $this->createMock(AuditService::class);
        $audit->expects(self::never())->method('append');
        $controller = new ApprovalController(new ApprovalService($audit));
        $request = $this->request(['candidacy_id' => $candidate]);
        $request->setUserResolver(fn () => $this->viewer);
        try {
            $controller->store($request, $this->electionId);
            self::fail('No footprint must reject.');
        } catch (ConstitutionalViolation $error) {
            self::assertStringContainsString('jurisdictional association', $error->getMessage());
        }
        DB::table('residency_confirmations')->insert(['user_id' => $this->viewer->id, 'jurisdiction_id' => $this->jurisdictionId, 'is_active' => true, 'depth' => 0]);
        DB::table('elections')->where('id', $this->electionId)->update(['status' => Election::STATUS_RANKED_OPEN]);
        foreach (['store', 'destroy'] as $action) {
            try {
                $controller->$action($request, $this->electionId, $candidate);
                self::fail('Closed phase must reject.');
            } catch (ConstitutionalViolation $error) {
                self::assertStringContainsString('approval phase is open', $error->getMessage());
            }
        }
        DB::table('candidacies')->where('id', $candidate)->update(['election_id' => $this->id(9000)]);
        try {
            $controller->store($request, $this->electionId);
            self::fail('Foreign election candidacy must reject.');
        } catch (ModelNotFoundException) {
            self::assertSame(0, DB::table('approvals')->count());
        }
    }

    private function id(int $n): string
    {
        return sprintf('10000000-0000-4000-8000-%012d', $n);
    }

    private function request(array $parameters = []): Request
    {
        return Request::create('/elections/'.$this->electionId.'/open-ballot', 'GET', $parameters);
    }

    private function page(array $parameters = []): array
    {
        return $this->directory->page($this->request($parameters), $this->race, $this->viewer);
    }

    private function parameters(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    private function follow(string $url): array
    {
        return $this->page($this->parameters($url));
    }

    private function candidate(int $number, ?int $rank, ?string $race = null): string
    {
        $id = $this->id(1000 + $number);
        DB::table('users')->insert(['id' => $this->id(2000 + $number), 'name' => 'Legal name '.$number, 'display_name' => 'Candidate '.$number]);
        DB::table('candidacies')->insert(['id' => $id, 'race_id' => $race ?? $this->raceId, 'election_id' => $this->electionId,
            'user_id' => $this->id(2000 + $number), 'status' => 'validated', 'created_at' => '2026-09-12 00:00:00', 'validated_at' => '2026-09-12 00:00:00']);
        if ($rank !== null) {
            DB::table('approval_standings')->insert(['id' => $this->id(3000 + $number), 'race_id' => $race ?? $this->raceId,
                'candidacy_id' => $id, 'as_of_date' => '2026-09-13', 'rank' => $rank, 'approvals_count' => 100 - $rank]);
        }

        return $id;
    }

    private function approval(int $candidate, string $user): void
    {
        DB::table('approvals')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'election_id' => $this->electionId,
            'candidacy_id' => $this->id(1000 + $candidate), 'user_id' => $user]);
    }

    private function organization(int $candidate, int $number, string $name, array $overrides = []): void
    {
        DB::table('organizations')->insert(['id' => $this->id(5000 + $number), 'name' => $name, 'type' => 'business']);
        DB::table('endorsements')->insert($overrides + ['id' => $this->id(6000 + $number), 'candidate_id' => $this->id(1000 + $candidate),
            'election_id' => $this->electionId, 'endorser_id' => $this->id(5000 + $number), 'endorser_type' => 'organization', 'is_public' => true]);
    }
}
