<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Organizations\TransferController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Services\Organizations\OrgRestructureService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Tests\TestCase;

/** Explicit SQLite memory fixtures; no migrations, live records, or write actions. */
final class OwnershipWorkspaceTest extends TestCase
{
    private string $originalConnection;
    private TransferController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.ownership_workspace_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('ownership_workspace_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->never())->method('file');
        $this->controller = new TransferController($engine, $this->createMock(ChamberVotePresenter::class));
    }

    protected function tearDown(): void
    {
        DB::disconnect('ownership_workspace_fixture');
        DB::purge('ownership_workspace_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_missing_or_empty_selection_opens_directory_without_reading_registers(): void
    {
        DB::enableQueryLog();
        foreach (['/organizations/transfers-conversions', '/organizations/transfers-conversions?org='] as $url) {
            $response = $this->controller->index(Request::create($url));
            self::assertSame(url('/organizations'), $response->getTargetUrl());
        }
        self::assertSame([], DB::getQueryLog());
    }

    public function test_malformed_selection_is_rejected_before_any_database_read(): void
    {
        DB::enableQueryLog();
        try {
            $this->controller->index(Request::create('/organizations/transfers-conversions?org=not-a-uuid'));
            self::fail('Expected validation failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('org', $e->errors());
        }
        self::assertSame([], DB::getQueryLog());
    }

    public function test_unknown_selection_never_falls_back_to_global_registers(): void
    {
        $this->organizationTable();
        DB::enableQueryLog();
        try {
            $this->controller->index(Request::create('/organizations/transfers-conversions?org='.$this->id(99)));
            self::fail('Expected missing organization');
        } catch (ModelNotFoundException) {
            $queries = DB::getQueryLog();
            self::assertCount(1, $queries);
            self::assertStringContainsString('"organizations"."id" = ?', $queries[0]['query']);
            self::assertContains($this->id(99), $queries[0]['bindings']);
        }
    }

    public function test_dissolved_organization_keeps_its_selected_history_and_action_addresses(): void
    {
        $this->organizationTable();
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('org_transfers', function (Blueprint $table) {
            foreach (['id', 'organization_id', 'to_party_type', 'to_party_id', 'status'] as $column) $table->string($column);
            foreach (['created_at', 'consent_from_at', 'consent_to_at', 'ffc_synced_at'] as $column) $table->timestamp($column)->nullable();
            $table->softDeletes();
        });
        $schema->create('org_conversions', function (Blueprint $table) {
            foreach (['id', 'organization_id', 'via'] as $column) $table->string($column);
            $table->timestamp('created_at')->nullable();
            $table->softDeletes();
        });
        $schema->create('org_restructures', function (Blueprint $table) {
            $table->string('id');
            $table->string('organization_id');
            $table->timestamp('created_at')->nullable();
            $table->softDeletes();
        });
        $this->app->instance(OrgRestructureService::class, $this->createMock(OrgRestructureService::class));
        foreach ([1, 2] as $id) {
            DB::table('organizations')->insert([
                'id' => $this->id($id), 'name' => 'Archived organization '.$id,
                'status' => 'dissolved', 'dissolved_at' => '2026-09-01', 'registration_record_id' => $this->id(20 + $id),
            ]);
            DB::table('org_transfers')->insert([
                'id' => $this->id(10 + $id), 'organization_id' => $this->id($id),
                'to_party_type' => 'users', 'to_party_id' => $this->id(40 + $id),
                'status' => 'completed', 'created_at' => '2026-08-01',
                'consent_from_at' => '2026-08-01', 'consent_to_at' => '2026-08-02',
            ]);
        }
        DB::enableQueryLog();

        $response = $this->controller->index(Request::create('/organizations/transfers-conversions?org='.$this->id(1)));
        $props = (new ReflectionProperty($response, 'props'))->getValue($response);

        self::assertSame($this->id(1), $props['focus']['id']);
        self::assertCount(1, $props['transfers']);
        self::assertSame($this->id(11), $props['transfers'][0]['id']);
        self::assertSame('2026-08-02', $props['transfers'][0]['consent_b_at']);
        self::assertCount(1, $props['dissolutions']);
        self::assertSame('Archived organization 1', $props['dissolutions'][0]['org']['name']);
        self::assertSame('/system/public-records', $props['dissolutions'][0]['archived_record_href']);
        self::assertSame('/organizations/'.$this->id(1).'/transfers', $props['urls']['transfer']);
        self::assertFalse($props['can']['initiateTransfer']);
        foreach (DB::getQueryLog() as $query) {
            self::assertContains($this->id(1), $query['bindings'], 'Every record read remains scoped to the selected organization.');
            self::assertNotContains($this->id(2), $query['bindings']);
        }
    }

    private function organizationTable(): void
    {
        DB::connection()->getSchemaBuilder()->create('organizations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->string('registration_record_id')->nullable();
            $table->timestamp('dissolved_at')->nullable();
            $table->softDeletes();
        });
    }

    private function id(int $id): string
    {
        return sprintf('20000000-0000-4000-8000-%012d', $id);
    }
}
