<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\EngineResult;
use App\Domain\Forms\FormRegistry;
use App\Domain\Forms\Handlers\OrganizationProfileManagement;
use App\Domain\Forms\Handlers\OrganizationStaffDelegation;
use App\Domain\Organizations\StaffTask;
use App\Http\Controllers\Organizations\OrgDelegationController;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\OrgStaffGrant;
use App\Models\User;
use App\Services\Organizations\CoDeterminationService;
use App\Services\Organizations\OrgDelegationService;
use App\Services\Organizations\OrgMembershipService;
use App\Services\Organizations\OrgSettingsService;
use App\Services\RoleService;
use App\Support\OrgStaffGrantDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * IO-5 — scoped organization staff delegation (F-ORG-011, operator ruling
 * 2026-09-13 · org-staff-delegation-model = A). Named, private SQLite only; no
 * world writes, no live-PG helpers. Proves:
 *  - the agent grants a bucket (row created, R-31 derives for the grantee);
 *  - a re-grant is a no-op returning the same row; revoke deactivates and R-31
 *    drops; a dissolved org drops R-31 and refuses;
 *  - a membership delegate accepts an application through the REAL handler,
 *    while a profile-only delegate is refused with no write;
 *  - a delegate can never reassign the agent, nor grant/revoke;
 *  - a non-agent cannot grant;
 *  - a grant revoked between render and submit is refused AT ACT TIME;
 *  - grants persist across reassign_agent and the new agent can revoke them;
 *  - hiring and share buckets are honored by mayPerform;
 *  - R-31 appears in no office form's roles and no office surface's availableTo;
 *  - the controller files exactly F-ORG-011 through a mock engine;
 *  - the grants directory is bounded (20 per page).
 */
class OrgStaffDelegationTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.org_delegation_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('org_delegation_fixture');
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
            $t->boolean('is_cgc')->default(false); $t->integer('worker_count')->default(0);
            $t->json('settings')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        $s->create('org_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('user_id'); $t->string('kind');
            $t->string('status')->default('applied'); $t->timestamp('applied_at')->nullable();
            $t->timestamp('accepted_at')->nullable(); $t->timestamp('ended_at')->nullable();
            $t->uuid('accepted_by_user_id')->nullable(); $t->string('end_reason')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        $s->create('org_staff_grants', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('grantee_user_id'); $t->string('task');
            $t->string('status')->default('active'); $t->uuid('granted_by_user_id')->nullable();
            $t->timestamp('granted_at')->nullable(); $t->timestamp('revoked_at')->nullable();
            $t->uuid('revoked_by_user_id')->nullable(); $t->string('end_reason')->nullable();
            $t->timestamps(); $t->softDeletes();
        });

        DB::table('users')->insert([
            ['id' => $this->id(1), 'display_name' => 'Agent One'],      // agent of org 50
            ['id' => $this->id(2), 'display_name' => 'Agent Two'],      // agent of org 51
            ['id' => $this->id(9), 'display_name' => 'New Agent'],
            ['id' => $this->id(20), 'display_name' => 'Delegate M'],    // membership delegate
            ['id' => $this->id(21), 'display_name' => 'Delegate P'],    // profile-only delegate
            ['id' => $this->id(30), 'display_name' => 'Applicant'],
        ]);
        DB::table('organizations')->insert([
            ['id' => $this->id(50), 'name' => 'Guild', 'structure' => Organization::STRUCTURE_MEMBER_OWNED, 'status' => 'active', 'agent_user_id' => $this->id(1), 'is_active' => true],
            ['id' => $this->id(51), 'name' => 'Rival', 'structure' => Organization::STRUCTURE_MEMBER_OWNED, 'status' => 'active', 'agent_user_id' => $this->id(2), 'is_active' => true],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('org_delegation_fixture'); DB::setDefaultConnection($this->original);
        Mockery::close();
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('a0000000-0000-4000-8000-%012d', $n); }

    private function user(int $n): ?User { return User::find($this->id($n)); }

    private function org(int $n): Organization { return Organization::findOrFail($this->id($n)); }

    private function delegation(): OrgDelegationService { return new OrgDelegationService(new RoleService); }

    private function profileHandler(): OrganizationProfileManagement
    {
        $roles = new RoleService;

        return new OrganizationProfileManagement(
            new OrgMembershipService($roles, app(CoDeterminationService::class)),
            $roles,
            app(OrgSettingsService::class),
        );
    }

    /** R-31 fact predicate (the exact private query rolesFor reads). */
    private function hasDelegation(string $userId): bool
    {
        $m = new ReflectionMethod(RoleService::class, 'hasOrgDelegation');
        $m->setAccessible(true);

        return (bool) $m->invoke(new RoleService, $userId);
    }

    private function applied(int $orgN, int $userN): string
    {
        $id = $this->id(2000 + $userN);
        DB::table('org_memberships')->insert([
            'id' => $id, 'organization_id' => $this->id($orgN), 'user_id' => $this->id($userN),
            'kind' => OrgMembership::KIND_MEMBER, 'status' => OrgMembership::STATUS_APPLIED, 'applied_at' => '2026-09-01 00:00:00',
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

    // ── grant / re-grant / revoke and R-31 derivation ────────────────────────

    public function test_agent_grants_a_bucket_and_the_grantee_derives_r31(): void
    {
        self::assertFalse($this->hasDelegation($this->id(20)));

        $grant = $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);

        self::assertSame(OrgStaffGrant::STATUS_ACTIVE, $grant->status);
        self::assertSame($this->id(1), (string) $grant->granted_by_user_id);
        self::assertSame(1, OrgStaffGrant::query()->where('grantee_user_id', $this->id(20))->where('status', 'active')->count());
        self::assertTrue($this->hasDelegation($this->id(20)), 'R-31 derives from the active grant');
    }

    public function test_a_re_grant_of_the_same_active_bucket_is_a_no_op_returning_the_same_row(): void
    {
        $del = $this->delegation();
        $first = $del->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);
        $second = $del->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);

        self::assertSame((string) $first->id, (string) $second->id, 'idempotent — the same row is returned');
        self::assertSame(1, OrgStaffGrant::query()->where('grantee_user_id', $this->id(20))->where('task', 'membership')->count());
    }

    public function test_revoke_deactivates_the_grant_and_r31_drops(): void
    {
        $del = $this->delegation();
        $del->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);
        self::assertTrue($this->hasDelegation($this->id(20)));

        $revoked = $del->revoke($this->org(50), $this->id(20), StaffTask::MEMBERSHIP, $this->user(1), 'left the desk');

        self::assertNotNull($revoked);
        self::assertSame(OrgStaffGrant::STATUS_REVOKED, $revoked->status);
        self::assertSame($this->id(1), (string) $revoked->revoked_by_user_id);
        self::assertFalse($this->hasDelegation($this->id(20)), 'R-31 drops once the grant is revoked');

        // Idempotent: a second revoke is a no-op returning null.
        self::assertNull($del->revoke($this->org(50), $this->id(20), StaffTask::MEMBERSHIP, $this->user(1)));
    }

    public function test_grant_refuses_a_non_delegable_bucket(): void
    {
        $this->refused(fn () => $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), 'reassign_agent'), 'not delegable');
        $this->refused(fn () => $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), 'dedicate_ip'), 'not delegable');
        self::assertSame(0, OrgStaffGrant::query()->count(), 'no row is written for a refused bucket');
    }

    // ── the delegate acts only within the granted bucket, through the handler ─

    public function test_a_membership_delegate_accepts_an_application_through_the_real_handler(): void
    {
        $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);
        $mId = $this->applied(50, 30);

        $result = $this->profileHandler()->handle($this->user(20), [
            'action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId,
        ]);

        self::assertSame('accept_member', $result['action']);
        self::assertSame(OrgMembership::STATUS_ACTIVE, OrgMembership::find($mId)->status);
    }

    public function test_a_profile_only_delegate_cannot_accept_a_membership_and_writes_nothing(): void
    {
        $this->delegation()->grant($this->org(50), $this->user(1), $this->id(21), StaffTask::PROFILE);
        $mId = $this->applied(50, 30);

        $this->refused(fn () => $this->profileHandler()->handle($this->user(21), [
            'action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId,
        ]), 'delegate');

        self::assertSame(OrgMembership::STATUS_APPLIED, OrgMembership::find($mId)->status, 'no state change on refusal');
    }

    public function test_a_delegate_can_never_reassign_the_agent(): void
    {
        // Even a broad grant (profile) does not reach reassign_agent — it maps
        // to no bucket and stays agent-only.
        $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::PROFILE);

        $this->refused(fn () => $this->profileHandler()->handle($this->user(20), [
            'action' => 'reassign_agent', 'organization_id' => $this->id(50), 'agent_user_id' => $this->id(9),
        ]), 'never delegable');
        self::assertSame($this->id(1), (string) $this->org(50)->agent_user_id, 'agency unchanged');
    }

    public function test_a_delegate_can_never_grant_or_revoke(): void
    {
        $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);
        $handler = new OrganizationStaffDelegation;

        // The delegate (user 20) is not the agent — the F-ORG-011 handler's
        // agent-equality gate refuses grant and revoke alike.
        $this->refused(fn () => $handler->handle($this->user(20), [
            'action' => 'grant_task', 'organization_id' => $this->id(50), 'grantee_user_id' => $this->id(21), 'bucket' => StaffTask::PROFILE,
        ]), 'agent');
        $this->refused(fn () => $handler->handle($this->user(20), [
            'action' => 'revoke_task', 'organization_id' => $this->id(50), 'grantee_user_id' => $this->id(20), 'bucket' => StaffTask::MEMBERSHIP,
        ]), 'agent');
        self::assertSame(1, OrgStaffGrant::query()->where('status', 'active')->count(), 'no grant added or revoked');
    }

    public function test_a_non_agent_cannot_grant(): void
    {
        $handler = new OrganizationStaffDelegation;
        // User 2 is the agent of org 51, not org 50.
        $this->refused(fn () => $handler->handle($this->user(2), [
            'action' => 'grant_task', 'organization_id' => $this->id(50), 'grantee_user_id' => $this->id(20), 'bucket' => StaffTask::MEMBERSHIP,
        ]), 'agent');
        self::assertSame(0, OrgStaffGrant::query()->count());
    }

    // ── act-time enforcement, not render-time ────────────────────────────────

    public function test_a_grant_revoked_between_render_and_submit_is_refused_at_act_time(): void
    {
        $del = $this->delegation();
        $del->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);
        $mId = $this->applied(50, 30);

        // The page rendered while the grant was active; the agent revokes it
        // before the delegate submits.
        $del->revoke($this->org(50), $this->id(20), StaffTask::MEMBERSHIP, $this->user(1));

        $this->refused(fn () => $this->profileHandler()->handle($this->user(20), [
            'action' => 'accept_member', 'organization_id' => $this->id(50), 'membership_id' => $mId,
        ]), 'delegate');
        self::assertSame(OrgMembership::STATUS_APPLIED, OrgMembership::find($mId)->status);
    }

    public function test_a_dissolved_organization_refuses_a_delegate_and_drops_r31(): void
    {
        $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);
        self::assertTrue($this->hasDelegation($this->id(20)));

        DB::table('organizations')->where('id', $this->id(50))->update(['status' => Organization::STATUS_DISSOLVED]);

        self::assertFalse($this->hasDelegation($this->id(20)), 'R-31 does not derive on a dissolved org');
        self::assertFalse($this->delegation()->mayPerform($this->org(50), $this->user(20), StaffTask::MEMBERSHIP), 'a delegate cannot act on a dissolved org');
    }

    // ── grants persist across a reassignment; the new agent can revoke ───────

    public function test_grants_persist_across_reassign_agent_and_the_new_agent_can_revoke(): void
    {
        $this->delegation()->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::MEMBERSHIP);

        // The old agent transfers agency to user 9 (F-ORG-001 reassign_agent).
        $this->profileHandler()->handle($this->user(1), [
            'action' => 'reassign_agent', 'organization_id' => $this->id(50), 'agent_user_id' => $this->id(9),
        ]);
        self::assertSame($this->id(9), (string) $this->org(50)->agent_user_id);

        // The delegation still stands, and the delegate still holds R-31.
        self::assertTrue($this->hasDelegation($this->id(20)), 'the grant survives the agent change');

        // The NEW agent (user 9) sees and can revoke it through the handler.
        $handler = new OrganizationStaffDelegation;
        $handler->handle($this->user(9), [
            'action' => 'revoke_task', 'organization_id' => $this->id(50), 'grantee_user_id' => $this->id(20), 'bucket' => StaffTask::MEMBERSHIP,
        ]);
        self::assertFalse($this->hasDelegation($this->id(20)), 'the new agent revoked the inherited grant');
    }

    // ── hiring and share buckets are honored by the one rail ─────────────────

    public function test_hiring_and_shares_buckets_are_honored_by_may_perform(): void
    {
        $del = $this->delegation();
        $del->grant($this->org(50), $this->user(1), $this->id(20), StaffTask::HIRING);

        self::assertTrue($del->mayPerform($this->org(50), $this->user(20), StaffTask::HIRING));
        self::assertFalse($del->mayPerform($this->org(50), $this->user(20), StaffTask::SHARES), 'a hiring grant does not confer share issuance');

        // The agent may perform every bucket without a grant row.
        self::assertTrue($del->mayPerform($this->org(50), $this->user(1), StaffTask::SHARES));
        self::assertTrue($del->mayPerform($this->org(50), $this->user(1), StaffTask::HIRING));

        // mayPerform also resolves an org passed by id (LaborBoardService shape).
        self::assertTrue($del->mayPerform($this->id(50), $this->user(20), StaffTask::HIRING));
    }

    // ── R-31 confers no constitutional office ────────────────────────────────

    public function test_r31_appears_in_no_office_form_and_no_office_surface(): void
    {
        $officePrefixes = ['F-LEG-', 'F-BOG-', 'F-JDG-', 'F-EXE-', 'F-SPK-', 'F-ELB-'];

        foreach (FormRegistry::FORMS as $id => $meta) {
            foreach ($officePrefixes as $prefix) {
                if (str_starts_with($id, $prefix)) {
                    self::assertNotContains('R-31', $meta['roles'] ?? [], "R-31 must not gate the office form {$id}");
                }
            }
        }

        foreach (config('cga.surfaces', []) as $surfaceId => $surface) {
            foreach ($surface['forms'] ?? [] as $entry) {
                $formId = $entry['id'] ?? '';
                foreach ($officePrefixes as $prefix) {
                    if (str_starts_with($formId, $prefix)) {
                        self::assertNotContains('R-31', $entry['availableTo'] ?? [], "R-31 must not appear in office form {$formId} on surface {$surfaceId}");
                    }
                }
            }
        }
    }

    // ── the controller files exactly F-ORG-011 through a mock engine ─────────

    public function test_controller_store_files_exactly_f_org_011_grant(): void
    {
        $engine = Mockery::mock(ConstitutionalEngine::class);
        $engine->shouldReceive('file')->once()->with('F-ORG-011', Mockery::any(), [
            'action' => 'grant_task', 'organization_id' => $this->id(50),
            'grantee_user_id' => $this->id(20), 'bucket' => StaffTask::MEMBERSHIP, 'reason' => null,
        ])->andReturn(new EngineResult('F-ORG-011', new AuditEntry, []));
        $controller = new OrgDelegationController($engine);

        $controller->store(
            Request::create('/x', 'POST', ['grantee_user_id' => $this->id(20), 'bucket' => StaffTask::MEMBERSHIP]),
            $this->org(50),
        );
    }

    public function test_controller_destroy_files_exactly_f_org_011_revoke(): void
    {
        $grant = OrgStaffGrant::create([
            'organization_id' => $this->id(50), 'grantee_user_id' => $this->id(20),
            'task' => StaffTask::HIRING, 'status' => 'active', 'granted_by_user_id' => $this->id(1), 'granted_at' => now(),
        ]);

        $engine = Mockery::mock(ConstitutionalEngine::class);
        $engine->shouldReceive('file')->once()->with('F-ORG-011', Mockery::any(), [
            'action' => 'revoke_task', 'organization_id' => $this->id(50),
            'grantee_user_id' => $this->id(20), 'bucket' => StaffTask::HIRING, 'reason' => null,
        ])->andReturn(new EngineResult('F-ORG-011', new AuditEntry, []));
        $controller = new OrgDelegationController($engine);

        $controller->destroy(Request::create('/x', 'DELETE'), $this->org(50), (string) $grant->id);
    }

    // ── the grants directory is bounded (20 per page) ────────────────────────

    public function test_the_grants_directory_is_bounded_to_twenty_per_page(): void
    {
        for ($n = 100; $n < 143; $n++) {
            DB::table('users')->insert(['id' => $this->id($n), 'display_name' => 'P'.$n]);
            OrgStaffGrant::create([
                'organization_id' => $this->id(50), 'grantee_user_id' => $this->id($n),
                'task' => StaffTask::MEMBERSHIP, 'status' => 'active', 'granted_by_user_id' => $this->id(1),
                'granted_at' => sprintf('2026-09-01 00:%02d:00', $n - 100),
            ]);
        }

        $dir = new OrgStaffGrantDirectory;
        $org = $this->org(50);
        $sizes = [];
        $seen = [];
        $token = null;
        do {
            $req = Request::create('/organizations/'.$org->id, 'GET', $token !== null ? ['grants_cursor' => $token] : []);
            $out = $dir->page($req, $org);
            $sizes[] = count($out['rows']);
            foreach ($out['rows'] as $row) {
                $seen[] = $row['id'];
                self::assertSame(StaffTask::MEMBERSHIP, $row['bucket']);
            }
            $q = parse_url((string) $out['pages']['next'], PHP_URL_QUERY) ?: '';
            parse_str($q, $params);
            $token = $params['grants_cursor'] ?? null;
        } while ($token !== null);

        self::assertSame([20, 20, 3], $sizes, 'bounded 20-per-page cursor pages');
        self::assertCount(43, array_unique($seen), 'every active grant appears once across the pages');
    }
}
