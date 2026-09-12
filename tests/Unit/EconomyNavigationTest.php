<?php

namespace Tests\Unit;

use App\Http\Controllers\Economy\EconomyController;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\CurrencyTelemetryService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\SettingsResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Isolated in-memory fixtures only; never boots migrations or a live world. */
final class EconomyNavigationTest extends TestCase
{
    private EconomyController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.economy_navigation_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('economy_navigation_fixture');
        self::assertSame(':memory:', DB::connection()->getConfig('database'));
        self::assertSame('sqlite', DB::connection()->getDriverName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('display_name')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('parent_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('organizations', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('type')->default('organization');
            $t->string('structure')->default('stock');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('org_memberships', function (Blueprint $t) {
            $t->string('organization_id');
            $t->string('user_id');
            $t->string('status');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('org_contracts', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('organization_id');
            $t->string('kind')->default('commercial');
            $t->string('counterparty_type')->default('users');
            $t->string('counterparty_id');
            $t->string('signed_by_org_user_id')->nullable();
            $t->text('terms');
            $t->string('status')->default('offered');
            foreach (['signed_by_org_at', 'signed_by_counterparty_at', 'created_at', 'effective_at', 'ended_at', 'deleted_at'] as $name) {
                $t->timestamp($name)->nullable();
            }
        });
        $schema->create('resident_agreements', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('title');
            $t->text('terms');
            $t->string('status')->default('offered');
            $t->string('initiator_user_id');
            $t->timestamp('created_at');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('resident_agreement_signers', function (Blueprint $t) {
            $t->string('agreement_id');
            $t->string('signer_user_id');
            $t->timestamp('signed_at')->nullable();
        });
        $schema->create('clauses', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('subject_type');
            $t->string('subject_id');
            $t->string('heading')->nullable();
            $t->text('body');
            $t->integer('ordinal');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('redlines', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('subject_type');
            $t->string('subject_id');
            $t->string('kind');
            $t->text('body');
            $t->text('rationale')->nullable();
            $t->string('proposer_user_id');
            $t->string('status');
            $t->timestamp('created_at');
        });
        $schema->create('share_offers', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('organization_id');
            $t->string('seller_holder_type');
            $t->string('seller_holder_id');
            $t->string('units');
            $t->string('price_per_unit');
            $t->string('status')->default('open');
            $t->timestamp('created_at');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('org_ownership_stakes', function (Blueprint $t) {
            $t->string('organization_id');
            $t->string('holder_type');
            $t->string('holder_id');
            $t->string('units');
            $t->timestamp('ended_at')->nullable();
        });
        foreach (['viewer', 'other', 'inactive'] as $user) {
            DB::table('users')->insert(['id' => $user, 'name' => $user, 'display_name' => ucfirst($user)]);
        }
        DB::table('organizations')->insert(['id' => 'org', 'name' => 'The organization']);
        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->never())->method('verifyChain');
        $ledger->expects($this->never())->method('imbalanceByCurrency');
        $issuance = $this->createMock(IssuanceService::class);
        $issuance->expects($this->never())->method('supply');
        $telemetry = $this->createMock(CurrencyTelemetryService::class);
        $telemetry->expects($this->never())->method('snapshot');
        $this->controller = new EconomyController($ledger, $issuance, new AccountService($ledger), $this->createMock(SettingsResolver::class), $telemetry);
    }

    public function test_guest_directory_and_legacy_redirect_do_not_read_private_records(): void
    {
        $this->logging();
        self::assertSame([], $this->props($this->controller->agreements($this->request('/economy/agreements', null)))['agreements']);
        $redirect = $this->controller->residentAgreements($this->request('/economy/resident-agreements'));
        self::assertStringEndsWith('/economy/agreements', $redirect->getTargetUrl());
        self::assertSame([], DB::getQueryLog());
    }

    public function test_one_directory_pages_both_families_without_loading_unrelated_terms(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->contract($i);
            $this->resident($i);
        }
        $this->contract(90, 'other');
        $this->resident(90, 'other');
        $this->logging();
        $first = $this->props($this->controller->agreements($this->request('/economy/agreements')));
        self::assertCount(40, $first['agreements']);
        foreach ($first['agreements'] as $row) {
            self::assertNotSame($this->id(90), $row['id']);
            if ($row['family'] === 'resident') {
                self::assertSame('/economy/resident-agreements?agreement='.$row['id'], $row['href']);
                self::assertArrayNotHasKey('terms', $row);
            }
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertStringStartsWith('select ', strtolower($query['query']));
            self::assertDoesNotMatchRegularExpression('/\b(count|sum)\s*\(/i', $query['query']);
            if (str_contains($query['query'], 'select "c".*') || str_contains($query['query'], 'select "id", "title"')) {
                self::assertStringContainsString('limit 21', $query['query']);
                self::assertStringNotContainsString('join "organizations"', $query['query']);
            }
        }
        foreach (['org', 'resident'] as $family) {
            $next = $this->props($this->controller->agreements($this->request($first['pagination'][$family]['next'])));
            $rows = array_values(array_filter($next['agreements'], fn ($row) => $row['family'] === $family));
            self::assertCount(5, $rows);
            $firstIds = array_column(array_filter($first['agreements'], fn ($row) => $row['family'] === $family), 'id');
            self::assertSame([], array_values(array_intersect($firstIds, array_column($rows, 'id'))));
            self::assertNotNull($next['pagination'][$family]['previous']);
        }
    }

    public function test_selected_personal_agreement_keeps_its_signatures_and_negotiation_scope(): void
    {
        $this->resident(1);
        $this->resident(2);
        $this->resident(3, 'other');
        foreach ([1, 2, 3] as $i) {
            DB::table('clauses')->insert(['id' => 'clause-'.$i, 'subject_type' => 'resident', 'subject_id' => $this->id($i), 'body' => 'Terms '.$i, 'ordinal' => 1]);
            DB::table('redlines')->insert(['id' => 'change-'.$i, 'subject_type' => 'resident', 'subject_id' => $this->id($i), 'kind' => 'edit', 'body' => 'Change '.$i, 'proposer_user_id' => 'viewer', 'status' => 'pending', 'created_at' => '2026-01-01']);
        }
        $this->logging();
        $props = $this->props($this->controller->residentAgreements($this->request('/economy/resident-agreements?agreement='.$this->id(2))));
        self::assertFalse($props['compose']);
        self::assertSame([], $props['candidates']);
        self::assertCount(1, $props['agreements']);
        $agreement = $props['agreements'][0];
        self::assertSame($this->id(2), $agreement['id']);
        self::assertTrue($agreement['can_sign']);
        self::assertSame(['clause-2'], array_column($agreement['clauses'], 'id'));
        self::assertSame(['change-2'], array_column($agreement['redlines'], 'id'));
        self::assertCount(4, DB::getQueryLog());
        foreach (DB::getQueryLog() as $query) {
            self::assertContains($this->id(2), $query['bindings']);
        }
    }

    public function test_nonparty_cannot_open_personal_or_organization_terms(): void
    {
        $this->resident(3, 'other');
        $this->contract(3, 'other');
        foreach (['resident', 'org'] as $kind) {
            $this->logging();
            try {
                if ($kind === 'resident') {
                    $this->controller->residentAgreements($this->request('/economy/resident-agreements?agreement='.$this->id(3)));
                } else {
                    $this->controller->agreement($this->request('/economy/agreements/'.$this->id(3)), $this->id(3));
                }
                self::fail('A nonparty must receive 404.');
            } catch (HttpException $error) {
                self::assertSame(404, $error->getStatusCode());
            }
            self::assertCount(1, DB::getQueryLog());
        }
    }

    public function test_active_membership_preserves_organization_visibility_without_inactive_membership(): void
    {
        $this->contract(5, 'other');
        DB::table('org_memberships')->insert([
            ['organization_id' => 'org', 'user_id' => 'viewer', 'status' => 'active'],
            ['organization_id' => 'org', 'user_id' => 'inactive', 'status' => 'inactive'],
        ]);
        $visible = $this->props($this->controller->agreements($this->request('/economy/agreements')));
        self::assertSame([$this->id(5)], array_column($visible['agreements'], 'id'));
        $hidden = $this->props($this->controller->agreements($this->request('/economy/agreements', 'inactive')));
        self::assertSame([], $hidden['agreements']);
    }

    public function test_signed_for_organization_lane_is_preserved_and_duplicate_visibility_is_one_row(): void
    {
        $this->contract(8, 'other');
        DB::table('org_contracts')->where('id', $this->id(8))->update(['signed_by_org_user_id' => 'viewer']);
        DB::table('org_memberships')->insert(['organization_id' => 'org', 'user_id' => 'viewer', 'status' => 'active']);
        $visible = $this->props($this->controller->agreements($this->request('/economy/agreements')));
        self::assertSame([$this->id(8)], array_column($visible['agreements'], 'id'));
        $detail = $this->props($this->controller->agreement($this->request('/economy/agreements/'.$this->id(8)), $this->id(8)));
        self::assertSame('Private organization terms 8', $detail['agreement']['terms_full']);
    }

    public function test_composer_loads_candidates_without_loading_any_agreement(): void
    {
        $this->resident(1);
        $this->logging();
        $props = $this->props($this->controller->residentAgreements($this->request('/economy/resident-agreements?new=1')));
        self::assertTrue($props['compose']);
        self::assertSame([], $props['agreements']);
        self::assertNotContains('viewer', array_column($props['candidates'], 'id'));
        self::assertCount(2, DB::getQueryLog());
        self::assertStringContainsString('limit 50', DB::getQueryLog()[0]['query']);
        self::assertStringContainsString('order by "id"', DB::getQueryLog()[0]['query']);
        self::assertStringNotContainsString('order by "name"', DB::getQueryLog()[0]['query']);
    }

    public function test_exchange_pages_offers_before_metadata_and_never_reads_world_telemetry(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            DB::table('share_offers')->insert(['id' => $this->id($i), 'organization_id' => 'org', 'seller_holder_type' => 'organizations', 'seller_holder_id' => 'org', 'units' => '2.000000', 'price_per_unit' => '3.123456', 'created_at' => '2026-01-01']);
        }
        DB::table('org_ownership_stakes')->insert([
            ['organization_id' => 'org', 'holder_type' => 'users', 'holder_id' => 'viewer', 'units' => '2.000000'],
            ['organization_id' => 'org', 'holder_type' => 'users', 'holder_id' => 'other', 'units' => '100.000000'],
        ]);
        $this->logging();
        $first = $this->props($this->controller->exchange($this->request('/economy/exchange')));
        self::assertCount(20, $first['offers']);
        self::assertSame('3.123456', $first['offers'][0]['price_per_unit']);
        self::assertSame('2', $first['my_holdings'][0]['units']);
        self::assertNull($first['kpis']);
        self::assertSame('not_loaded', $first['telemetry_status']);
        self::assertSame([], $first['instruments']);
        self::assertSame([], $first['shares']);
        self::assertSame([], $first['tape']);
        foreach (DB::getQueryLog() as $query) {
            self::assertStringStartsWith('select ', strtolower($query['query']));
            if (str_contains($query['query'], 'sum(')) {
                self::assertContains('viewer', $query['bindings']);
                self::assertStringContainsString('"s"."holder_id" = ?', $query['query']);
            }
            if (str_contains($query['query'], 'from "share_offers"')) {
                self::assertStringNotContainsString(' join ', $query['query']);
                self::assertStringContainsString('limit 21', $query['query']);
            }
        }
        $next = $this->props($this->controller->exchange($this->request($first['pagination']['next'])));
        self::assertCount(5, $next['offers']);
        self::assertSame([], array_values(array_intersect(array_column($first['offers'], 'id'), array_column($next['offers'], 'id'))));
    }

    public function test_share_page_tolerates_an_organization_removed_between_reads(): void
    {
        DB::table('share_offers')->insert(['id' => $this->id(1), 'organization_id' => 'org', 'seller_holder_type' => 'organizations', 'seller_holder_id' => 'org', 'units' => '2.000000', 'price_per_unit' => '3.000000', 'created_at' => '2026-01-01']);
        $removed = false;
        DB::listen(function ($query) use (&$removed) {
            if (! $removed && str_contains($query->sql, 'from "share_offers"')) {
                $removed = true;
                DB::table('organizations')->where('id', 'org')->delete();
            }
        });
        $props = $this->props($this->controller->exchange($this->request('/economy/exchange', null)));
        self::assertTrue($removed);
        self::assertSame([], $props['offers']);
    }

    public function test_market_selects_listing_ids_before_loading_registered_assets(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('marketplace_listings', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('kind')->default('good');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('price');
            $t->string('quantity');
            $t->string('status')->default('open');
            $t->string('seller_account_id');
            $t->string('asset_id')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('assets', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('kind');
            $t->string('name');
            $t->text('attributes')->nullable();
        });
        DB::table('assets')->insert(['id' => 'asset', 'kind' => 'virtual', 'name' => 'Registered item']);
        for ($i = 1; $i <= 105; $i++) {
            DB::table('marketplace_listings')->insert(['id' => $this->id($i), 'title' => 'Listing '.$i, 'price' => '99.123456', 'quantity' => '1.000000', 'seller_account_id' => 'private-account', 'asset_id' => 'asset', 'created_at' => '2026-01-01']);
        }
        $this->logging();
        $offers = (new \ReflectionMethod($this->controller, 'offers'))->invoke($this->controller);
        self::assertCount(100, $offers);
        self::assertSame($this->id(105), $offers[0]['id']);
        self::assertSame('Registered item', $offers[0]['asset']['name']);
        self::assertSame('99.123456', $offers[0]['price']);
        $queries = DB::getQueryLog();
        self::assertCount(2, $queries);
        self::assertStringContainsString('limit 100', $queries[0]['query']);
        self::assertStringNotContainsString('join', $queries[0]['query']);
        self::assertStringContainsString('"l"."id" in', $queries[1]['query']);
        self::assertCount(101, $queries[1]['bindings']); // 100 IDs plus status.
    }

    private function request(string $url, ?string $user = 'viewer'): Request
    {
        $request = Request::create($url);
        $request->setUserResolver(fn () => $user === null ? null : (new User)->forceFill(['id' => $user]));

        return $request;
    }

    private function props(Response $response): array
    {
        return (new \ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    private function contract(int $id, string $user = 'viewer'): void
    {
        DB::table('org_contracts')->insert(['id' => $this->id($id), 'organization_id' => 'org', 'counterparty_id' => $user, 'terms' => 'Private organization terms '.$id, 'created_at' => '2026-01-01']);
    }

    private function resident(int $id, string $user = 'viewer'): void
    {
        DB::table('resident_agreements')->insert(['id' => $this->id($id), 'title' => 'Agreement '.$id, 'terms' => 'Private personal terms '.$id, 'initiator_user_id' => $user, 'created_at' => '2026-01-01']);
        DB::table('resident_agreement_signers')->insert(['agreement_id' => $this->id($id), 'signer_user_id' => $user]);
    }

    private function logging(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }
}
