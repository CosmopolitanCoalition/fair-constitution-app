<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\EngineResult;
use App\Http\Controllers\Organizations\OrgEconomyController;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Actual Inertia partials and controller input, never the live economy. */
final class OrgShareSurfaceTest extends TestCase
{
    private string $originalConnection;
    private OrgEconomyController $controller;
    private ConstitutionalEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.org_share_surface_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'session.driver' => 'array']);
        DB::setDefaultConnection('org_share_surface_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        foreach (['users', 'organizations'] as $table) {
            $schema->create($table, function (Blueprint $t) use ($table) {
                $t->uuid('id')->primary(); $t->string('name'); $t->softDeletes();
                if ($table === 'users') $t->string('display_name')->nullable();
            });
        }
        $schema->create('board_seats', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('board_id'); $t->uuid('holder_user_id');
            $t->string('status'); $t->softDeletes();
        });
        $schema->create('social_profiles', function (Blueprint $t) {
            $t->uuid('user_id')->primary(); $t->string('handle'); $t->string('visibility'); $t->softDeletes();
        });
        $schema->create('org_ownership_stakes', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('holder_id');
            $t->string('holder_type'); $t->string('units'); $t->string('pct');
            $t->string('acquired_via'); $t->timestamp('ended_at')->nullable();
        });
        foreach (range(1, 25) as $n) {
            DB::table('users')->insert(['id' => $this->id(100 + $n), 'name' => 'Fallback '.$n, 'display_name' => 'Member '.sprintf('%02d', $n)]);
            DB::table('org_ownership_stakes')->insert([
                'id' => $this->id(200 + $n), 'organization_id' => $this->id(1), 'holder_type' => 'users',
                'holder_id' => $this->id(100 + $n), 'units' => '1.000000', 'pct' => '4.0000', 'acquired_via' => 'issue',
            ]);
        }
        DB::table('organizations')->insert(['id' => $this->id(2), 'name' => 'Recipient organization']);
        DB::table('social_profiles')->insert([
            ['user_id' => $this->id(101), 'handle' => 'public-recipient', 'visibility' => 'public'],
            ['user_id' => $this->id(102), 'handle' => 'private-recipient', 'visibility' => 'private'],
        ]);
        DB::table('board_seats')->insert(['id' => $this->id(300), 'board_id' => $this->id(10), 'holder_user_id' => $this->id(102), 'status' => 'seated']);
        // All currency, ledger, account-binding, levy and conversion tables are
        // deliberately absent: partial navigation must not resolve those props.
        $settings = $this->createMock(OrgSettingsService::class);
        $settings->expects(self::never())->method('get');
        $this->controller = new OrgEconomyController($settings);
        $this->engine = $this->createMock(ConstitutionalEngine::class);
    }

    protected function tearDown(): void
    {
        DB::purge('org_share_surface_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_share_pagination_resolves_only_public_ownership_without_private_money_reads(): void
    {
        $first = $this->partial($this->path(), 'shares');
        self::assertSame(['shares'], array_keys($first));
        self::assertCount(20, $first['shares']['holders']);
        $second = $this->partial($first['shares']['next'], 'shares');
        self::assertCount(5, $second['shares']['holders']);
        self::assertSame($first, $this->partial($second['shares']['previous'], 'shares'));
        self::assertStringNotContainsString('holder_id', json_encode($first));
    }

    public function test_recipient_search_resolves_alone_and_keeps_public_names(): void
    {
        $first = $this->partial($this->path().'?issue=1&recipient_type=users&recipient_q=Member', 'recipient_directory');
        self::assertSame(['recipient_directory'], array_keys($first));
        self::assertCount(20, $first['recipient_directory']['candidates']);
        self::assertSame('Member 01', $first['recipient_directory']['candidates'][0]['name']);
        self::assertSame($this->id(101), $first['recipient_directory']['candidates'][0]['id']);
        self::assertSame('@public-recipient', $first['recipient_directory']['candidates'][0]['public_handle']);
        self::assertNull($first['recipient_directory']['candidates'][1]['public_handle']);
        self::assertStringNotContainsString('private-recipient', json_encode($first));
        self::assertCount(5, $this->partial($first['recipient_directory']['next'], 'recipient_directory')['recipient_directory']['candidates']);
    }

    public function test_board_ledger_access_never_grants_agent_issuance_or_settings_controls(): void
    {
        $flags = 'can_steer,can_update_dues,can_issue_shares,recipient_directory';
        $agent = $this->partial($this->path(), $flags);
        self::assertTrue($agent['can_steer']);
        self::assertTrue($agent['can_update_dues']);
        self::assertTrue($agent['can_issue_shares']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $board = $this->partial($this->path().'?recipient_q=Member', $flags, 102);
        self::assertTrue($board['can_steer']);
        self::assertFalse($board['can_update_dues']);
        self::assertFalse($board['can_issue_shares']);
        self::assertFalse($board['recipient_directory']['searched']);
        foreach (DB::getQueryLog() as $query) self::assertStringNotContainsString('from "users"', $query['query']);
        DB::disableQueryLog();
        $nonStock = $this->partial($this->path(), $flags, 101, 'nonprofit');
        self::assertFalse($nonStock['can_issue_shares']);
        self::assertTrue($nonStock['can_update_dues']);
    }

    public function test_share_submission_uses_the_route_organization_exact_actor_and_decimal_text(): void
    {
        $actor = $this->user();
        $request = $this->postRequest([
            'organization_id' => $this->id(999), 'actor_id' => $this->id(102),
            'holder_type' => 'organizations', 'holder_id' => $this->id(2), 'units' => '99999999999999.123456',
        ], $actor);
        $this->engine->expects(self::once())->method('file')->with('F-ORG-008', $actor, [
            'action' => 'issue_shares', 'organization_id' => $this->id(1), 'holder_type' => 'organizations',
            'holder_id' => $this->id(2), 'units' => '99999999999999.123456',
        ])->willReturn(new EngineResult('F-ORG-008', new AuditEntry, ['stake_id' => $this->id(888)]));
        $result = $this->controller->issueShares($request, $this->organization(), $this->engine);
        self::assertSame($this->path(), parse_url($result->getTargetUrl(), PHP_URL_PATH));
        self::assertStringContainsString('Shares issued', session('status'));
        self::assertStringContainsString('99999999999999.123456 units to Recipient organization', session('status'));
    }

    public function test_non_agent_and_non_stock_actions_stop_before_engine(): void
    {
        $this->engine->expects(self::never())->method('file');
        foreach ([[102, 'stock', 403], [101, 'nonprofit', 422]] as [$actor, $structure, $status]) {
            try {
                $this->controller->issueShares($this->postRequest([], $this->user($actor)), $this->organization($structure), $this->engine);
                self::fail('Expected authorization refusal.');
            } catch (HttpExceptionInterface $error) { self::assertSame($status, $error->getStatusCode()); }
        }
    }

    public function test_missing_deleted_and_malformed_recipients_are_rejected_before_engine(): void
    {
        $this->engine->expects(self::never())->method('file');
        DB::table('users')->where('id', $this->id(101))->update(['deleted_at' => now()]);
        foreach ([$this->id(999), $this->id(101), 'not-a-uuid'] as $holder) {
            try {
                $this->controller->issueShares($this->postRequest(['holder_id' => $holder]), $this->organization(), $this->engine);
                self::fail('Expected missing recipient refusal.');
            } catch (ValidationException $error) { self::assertArrayHasKey('holder_id', $error->errors()); }
        }
    }

    public function test_dissolved_organization_cannot_issue_more_shares(): void
    {
        $this->engine->expects(self::never())->method('file');
        $org = $this->organization()->forceFill(['status' => Organization::STATUS_DISSOLVED]);
        try {
            $this->controller->issueShares($this->postRequest([]), $org, $this->engine);
            self::fail('Expected dissolved organization refusal.');
        } catch (HttpExceptionInterface $error) { self::assertSame(422, $error->getStatusCode()); }
        $request = Request::create($this->path());
        $request->setUserResolver(fn () => $this->user());
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Component', 'Economy/OrgSettings');
        $request->headers->set('X-Inertia-Partial-Data', 'can_issue_shares');
        $response = $this->controller->show($request, $org)->toResponse($request);
        self::assertFalse($response->getData(true)['props']['can_issue_shares']);
    }

    public function test_invalid_precision_zero_negative_and_exponent_units_never_reach_engine(): void
    {
        $this->engine->expects(self::never())->method('file');
        foreach (['0', '-1', '0.0000001', '1e6', '100000000000000', '2x', 2] as $units) {
            try {
                $this->controller->issueShares($this->postRequest(['units' => $units]), $this->organization(), $this->engine);
                self::fail('Expected units validation refusal.');
            } catch (ValidationException $error) { self::assertArrayHasKey('units', $error->errors()); }
        }
    }

    private function partial(string $url, string $only, int $viewer = 101, string $structure = 'stock'): array
    {
        $request = Request::create($url);
        $request->setUserResolver(fn () => $this->user($viewer));
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Component', 'Economy/OrgSettings');
        $request->headers->set('X-Inertia-Partial-Data', $only);
        $response = $this->controller->show($request, $this->organization($structure))->toResponse($request);
        self::assertSame(200, $response->getStatusCode());
        return $response->getData(true)['props'];
    }

    private function postRequest(array $data, ?User $actor = null): Request
    {
        $request = Request::create('/organizations/'.$this->id(1).'/shares', 'POST', $data + [
            'holder_type' => 'users', 'holder_id' => $this->id(101), 'units' => '1.25',
        ]);
        $request->setUserResolver(fn () => $actor ?? $this->user());
        return $request;
    }

    private function organization(string $structure = 'stock'): Organization
    {
        return (new Organization)->forceFill(['id' => $this->id(1), 'name' => 'Selected enterprise', 'type' => 'business',
            'structure' => $structure, 'agent_user_id' => $this->id(101), 'board_id' => $this->id(10), 'is_cgc' => false]);
    }

    private function user(int $n = 101): User { return (new User)->forceFill(['id' => $this->id($n)]); }
    private function path(): string { return '/organizations/'.$this->id(1).'/economy'; }
    private function id(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
}
