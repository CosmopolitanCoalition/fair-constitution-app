<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\EngineResult;
use App\Models\AuditEntry;
use App\Domain\Forms\Handlers\OrganizationProfileManagement;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\User;
use App\Services\Organizations\CoDeterminationService;
use App\Services\Organizations\OrgMembershipService;
use App\Services\Organizations\OrgSettingsService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Support\OrgMembershipReviewDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * IO-4 — organization membership review + agent reassignment (F-ORG-001,
 * operator ruling 2026-09-13 · org-membership-agent-rules = A). Named, private
 * SQLite only; no world writes, no live-PG helpers. Proves:
 *  - the agent pages pending applications (43 → 20/20/3, cursor tokens scoped,
 *    a cross-scope cursor refused);
 *  - accept moves applied → active and stamps accepted_by; decline moves
 *    applied → declined and a fresh F-IND-013 application is then allowed;
 *  - a second decision on the same row is refused (stale);
 *  - a non-agent and the agent of another org are refused with no write;
 *  - a cross-org membership id is refused;
 *  - reassign_agent moves agent_user_id (old agent loses R-23, new gains it),
 *    with both role caches flushed;
 *  - the controller files exactly F-ORG-001 with the right action.
 */
class OrganizationMembershipReviewTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.org_review_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('org_review_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $s = DB::connection()->getSchemaBuilder();
        $s->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name')->default('Secret legal name'); $t->string('display_name')->nullable(); $t->softDeletes();
        });
        $s->create('social_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('user_id'); $t->string('handle')->nullable();
            $t->string('display_name')->nullable(); $t->string('visibility')->default('public'); $t->softDeletes();
        });
        $s->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('type')->default('business');
            $t->string('structure')->nullable(); $t->string('status')->default('active');
            $t->uuid('agent_user_id')->nullable(); $t->boolean('is_active')->default(true);
            $t->boolean('is_cgc')->default(false); $t->boolean('ip_is_public_domain')->default(false);
            $t->integer('worker_count')->default(0); $t->boolean('is_registered')->default(true);
            $t->timestamp('registered_at')->nullable(); $t->timestamp('dissolved_at')->nullable();
            $t->json('settings')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        $s->create('org_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('user_id'); $t->string('kind');
            $t->string('status')->default('applied'); $t->timestamp('applied_at')->nullable();
            $t->timestamp('accepted_at')->nullable(); $t->timestamp('ended_at')->nullable();
            $t->uuid('accepted_by_user_id')->nullable(); $t->string('end_reason')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        // IO-5 — OrganizationProfileManagement now routes each action through
        // OrgDelegationService::mayPerform, which reads this on the non-agent
        // path. Empty here, so a non-agent (or another org's agent) is refused.
        $s->create('org_staff_grants', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['organization_id', 'grantee_user_id', 'task', 'status'] as $field) $t->string($field);
            $t->softDeletes();
        });

        // The agent (user 1), a second org's agent (user 2), an applicant (user 20).
        DB::table('users')->insert([
            ['id' => $this->id(1), 'display_name' => 'Agent One'],
            ['id' => $this->id(2), 'display_name' => 'Agent Two'],
            ['id' => $this->id(20), 'display_name' => 'Applicant'],
        ]);
        // Org 50 (agent = user 1, member-owned) and org 51 (agent = user 2).
        DB::table('organizations')->insert([
            ['id' => $this->id(50), 'name' => 'Guild', 'structure' => Organization::STRUCTURE_MEMBER_OWNED, 'status' => 'active', 'agent_user_id' => $this->id(1), 'is_active' => true],
            ['id' => $this->id(51), 'name' => 'Rival', 'structure' => Organization::STRUCTURE_MEMBER_OWNED, 'status' => 'active', 'agent_user_id' => $this->id(2), 'is_active' => true],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('org_review_fixture'); DB::setDefaultConnection($this->original);
        Mockery::close();
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('90000000-0000-4000-8000-%012d', $n); }

    private function user(int $n): ?User { return User::find($this->id($n)); }

    private function org(int $n): Organization { return Organization::findOrFail($this->id($n)); }

    /** A RoleService that records every flushUser call (proves the R-23 cache flush). */
    private function recordingRoles(): RoleService
    {
        return new class extends RoleService {
            /** @var list<string> */
            public array $flushed = [];

            public function flushUser(string $userId): void
            {
                $this->flushed[] = $userId;
                parent::flushUser($userId);
            }
        };
    }

    private function handlerWith(RoleService $roles): OrganizationProfileManagement
    {
        return new OrganizationProfileManagement(
            new OrgMembershipService($roles, app(CoDeterminationService::class)),
            $roles,
            app(OrgSettingsService::class),
        );
    }

    /** R-23 fact query (the exact private predicate rolesFor reads). */
    private function isAgent(string $userId): bool
    {
        $m = new ReflectionMethod(RoleService::class, 'hasOrgAgency');
        $m->setAccessible(true);

        return (bool) $m->invoke(new RoleService, $userId);
    }

    private function applied(int $orgN, int $userN, string $appliedAt): string
    {
        $id = $this->id(2000 + $userN);
        DB::table('org_memberships')->insert([
            'id' => $id, 'organization_id' => $this->id($orgN), 'user_id' => $this->id($userN),
            'kind' => OrgMembership::KIND_MEMBER, 'status' => OrgMembership::STATUS_APPLIED, 'applied_at' => $appliedAt,
        ]);

        return $id;
    }

    private function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            self::fail('Expected a ConstitutionalViolation containing "'.$needle.'".');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString($needle, $e->getMessage().' '.$e->citation);
        }
    }

    // ── the agent pages the pending queue: 43 → 20 / 20 / 3, scoped tokens ────

    public function test_agent_pages_pending_applications_in_bounded_scoped_pages(): void
    {
        for ($n = 100; $n < 143; $n++) {
            DB::table('users')->insert(['id' => $this->id($n), 'display_name' => 'P'.$n]);
            $this->applied(50, $n, sprintf('2026-09-01 00:%02d:00', $n - 100));
        }

        $dir = new OrgMembershipReviewDirectory;
        $org = $this->org(50);
        $seen = [];

        $token = null;
        $sizes = [];
        do {
            $req = Request::create('/organizations/'.$org->id, 'GET', $token !== null ? ['members_cursor' => $token] : []);
            $out = $dir->page($req, $org);
            $sizes[] = count($out['rows']);
            foreach ($out['rows'] as $row) {
                $seen[] = $row['id'];
                self::assertSame(OrgMembership::KIND_MEMBER, $row['kind']);
            }
            $token = $out['pages']['next'] !== null
                ? $this->cursorParam($out['pages']['next'])
                : null;
        } while ($token !== null);

        self::assertSame([20, 20, 3], $sizes, 'bounded 20-per-page cursor pages');
        self::assertCount(43, array_unique($seen), 'every applied row appears once across the pages');
    }

    public function test_a_cursor_from_another_org_scope_is_refused(): void
    {
        for ($n = 100; $n < 125; $n++) {
            DB::table('users')->insert(['id' => $this->id($n), 'display_name' => 'P'.$n]);
            $this->applied(50, $n, sprintf('2026-09-01 00:%02d:00', $n - 100));
        }
        $dir = new OrgMembershipReviewDirectory;

        // A valid next token minted for org 50.
        $first = $dir->page(Request::create('/organizations/'.$this->id(50), 'GET'), $this->org(50));
        $token = $this->cursorParam($first['pages']['next']);
        self::assertNotNull($token);

        // Presented against org 51 (different scope) → refused, not silently accepted.
        $this->expectException(ValidationException::class);
        $dir->page(Request::create('/organizations/'.$this->id(51), 'GET', ['members_cursor' => $token]), $this->org(51));
    }

    private function cursorParam(string $url): ?string
    {
        $q = parse_url($url, PHP_URL_QUERY) ?: '';
        parse_str($q, $params);

        return $params['members_cursor'] ?? null;
    }

    // ── accept / decline state transitions ───────────────────────────────────

    public function test_accept_moves_applied_to_active_and_stamps_accepted_by(): void
    {
        $mId = $this->applied(50, 20, '2026-09-01 00:00:00');
        $roles = $this->recordingRoles();

        $result = $this->handlerWith($roles)->handle($this->user(1), [
            'action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId,
        ]);

        self::assertSame('accept_member', $result['action']);
        $row = OrgMembership::find($mId);
        self::assertSame(OrgMembership::STATUS_ACTIVE, $row->status);
        self::assertSame($this->id(1), (string) $row->accepted_by_user_id);
        self::assertNotNull($row->accepted_at);
        self::assertContains($this->id(20), $roles->flushed, 'the new member\'s R-24 cache is flushed');
    }

    public function test_decline_moves_applied_to_declined_and_a_fresh_application_is_allowed(): void
    {
        $mId = $this->applied(50, 20, '2026-09-01 00:00:00');
        $roles = $this->recordingRoles();
        $service = new OrgMembershipService($roles, app(CoDeterminationService::class));
        $handler = new OrganizationProfileManagement($service, $roles, app(OrgSettingsService::class));

        $handler->handle($this->user(1), ['action' => 'decline_member', 'organization_id' => $this->id(50), 'membership_id' => $mId]);
        self::assertSame(OrgMembership::STATUS_DECLINED, OrgMembership::find($mId)->status);

        // A declined row is terminal; the applicant may file a fresh F-IND-013.
        $fresh = $service->apply($this->user(20), $this->org(50), null);
        self::assertSame(OrgMembership::STATUS_APPLIED, $fresh->status);
        self::assertSame(2, OrgMembership::query()->where('user_id', $this->id(20))->count(), 'the declined row is kept; a new applied row is added');
    }

    public function test_a_second_decision_on_the_same_row_is_refused(): void
    {
        $mId = $this->applied(50, 20, '2026-09-01 00:00:00');
        $roles = $this->recordingRoles();
        $handler = $this->handlerWith($roles);

        $handler->handle($this->user(1), ['action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId]);
        // Already active — a second accept or a decline is refused.
        $this->refused(fn () => $handler->handle($this->user(1), ['action' => 'decline_member', 'organization_id' => $this->id(50), 'membership_id' => $mId]), 'not pending');
        self::assertSame(OrgMembership::STATUS_ACTIVE, OrgMembership::find($mId)->status);
    }

    // ── access control: only THIS org's agent, no write on refusal ────────────

    public function test_a_non_agent_is_refused_with_no_write(): void
    {
        $mId = $this->applied(50, 20, '2026-09-01 00:00:00');
        $handler = $this->handlerWith($this->recordingRoles());

        // User 20 (the applicant, not an agent of org 50) tries to accept.
        $this->refused(fn () => $handler->handle($this->user(20), ['action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId]), 'agent');
        self::assertSame(OrgMembership::STATUS_APPLIED, OrgMembership::find($mId)->status, 'no state change on refusal');
    }

    public function test_the_agent_of_another_org_is_refused_with_no_write(): void
    {
        $mId = $this->applied(50, 20, '2026-09-01 00:00:00');
        $handler = $this->handlerWith($this->recordingRoles());

        // User 2 is agent of org 51, not org 50.
        $this->refused(fn () => $handler->handle($this->user(2), ['action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId]), 'agent');
        self::assertSame(OrgMembership::STATUS_APPLIED, OrgMembership::find($mId)->status);
    }

    public function test_a_cross_org_membership_id_is_refused(): void
    {
        // Membership belongs to org 51; the agent of org 50 targets it under org 50.
        DB::table('users')->insert(['id' => $this->id(30), 'display_name' => 'Rival applicant']);
        $mId = $this->applied(51, 30, '2026-09-01 00:00:00');
        $handler = $this->handlerWith($this->recordingRoles());

        $this->refused(fn () => $handler->handle($this->user(1), ['action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId]), 'Unknown membership application');
        self::assertSame(OrgMembership::STATUS_APPLIED, OrgMembership::find($mId)->status);
    }

    // ── reassign_agent: agency moves, R-23 flips, both caches flush ───────────

    public function test_reassign_agent_moves_agency_and_flips_r23_with_cache_flush(): void
    {
        DB::table('users')->insert(['id' => $this->id(9), 'display_name' => 'New Agent']);
        self::assertTrue($this->isAgent($this->id(1)));
        self::assertFalse($this->isAgent($this->id(9)));

        $roles = $this->recordingRoles();
        $result = $this->handlerWith($roles)->handle($this->user(1), [
            'action' => 'reassign_agent', 'organization_id' => $this->id(50), 'agent_user_id' => $this->id(9),
        ]);

        self::assertSame($this->id(1), $result['previous_agent']);
        self::assertSame($this->id(9), $result['agent_user_id']);
        self::assertSame($this->id(9), (string) $this->org(50)->agent_user_id);
        self::assertFalse($this->isAgent($this->id(1)), 'the outgoing agent loses R-23');
        self::assertTrue($this->isAgent($this->id(9)), 'the incoming user gains R-23');
        self::assertContains($this->id(1), $roles->flushed, 'the outgoing agent cache is flushed');
        self::assertContains($this->id(9), $roles->flushed, 'the incoming agent cache is flushed');
    }

    // ── the controller files exactly F-ORG-001 with the right action ──────────

    public function test_controller_decide_files_exactly_f_org_001_accept(): void
    {
        $engine = Mockery::mock(ConstitutionalEngine::class);
        $engine->shouldReceive('file')->once()->with('F-ORG-001', Mockery::any(), [
            'action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $this->id(2020),
        ])->andReturn(new EngineResult('F-ORG-001', new AuditEntry, []));
        $controller = new OrganizationController($engine, new RoleService, app(SettingsResolver::class));

        $org = new Organization; $org->id = $this->id(50);
        $membership = new OrgMembership; $membership->id = $this->id(2020); $membership->organization_id = $this->id(50);

        $controller->decideMembership(Request::create('/x', 'POST', ['decision' => 'accept']), $org, $membership);
    }

    public function test_controller_decide_files_decline_for_decline_input(): void
    {
        $engine = Mockery::mock(ConstitutionalEngine::class);
        $engine->shouldReceive('file')->once()->with('F-ORG-001', Mockery::any(), [
            'action' => 'decline_member', 'organization_id' => $this->id(50), 'membership_id' => $this->id(2020),
        ])->andReturn(new EngineResult('F-ORG-001', new AuditEntry, []));
        $controller = new OrganizationController($engine, new RoleService, app(SettingsResolver::class));

        $org = new Organization; $org->id = $this->id(50);
        $membership = new OrgMembership; $membership->id = $this->id(2020); $membership->organization_id = $this->id(50);

        $controller->decideMembership(Request::create('/x', 'POST', ['decision' => 'decline']), $org, $membership);
    }

    public function test_controller_reassign_files_exactly_f_org_001_reassign(): void
    {
        $engine = Mockery::mock(ConstitutionalEngine::class);
        $engine->shouldReceive('file')->once()->with('F-ORG-001', Mockery::any(), [
            'action' => 'reassign_agent', 'organization_id' => $this->id(50), 'agent_user_id' => $this->id(9),
        ])->andReturn(new EngineResult('F-ORG-001', new AuditEntry, []));
        $controller = new OrganizationController($engine, new RoleService, app(SettingsResolver::class));

        $org = new Organization; $org->id = $this->id(50);

        $controller->reassignAgent(Request::create('/x', 'POST', ['agent_user_id' => $this->id(9)]), $org);
    }
}
