<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\CommitteeReportFiling;
use App\Models\CommitteeReport;
use App\Models\PublicRecord;
use App\Models\User;
use App\Services\PublicRecordService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual filing handler against a private SQLite database; publication is a strict mock. */
final class CommitteeReportFilingScopeTest extends TestCase
{
    private const BILL = '50000000-0000-4000-8000-000000000001';

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.committee_filing_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('committee_filing_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $tables = [
            'legislatures' => ['jurisdiction_id'],
            'committees' => ['legislature_id', 'name', 'status', 'chair_member_id', 'alternate_member_id'],
            'legislature_members' => ['legislature_id', 'user_id', 'status'],
            'bills' => ['legislature_id', 'committee_id'],
            'committee_reports' => ['committee_id', 'bill_id', 'filed_by_member_id', 'report_record_id'],
        ];
        foreach ($tables as $table => $columns) {
            DB::connection()->getSchemaBuilder()->create($table, function (Blueprint $schema) use ($columns) {
                $schema->string('id')->primary();
                foreach ($columns as $column) {
                    $schema->string($column)->nullable();
                }
                $schema->timestamps();
                $schema->softDeletes();
            });
        }
        DB::table('legislatures')->insert(['id' => 'leg', 'jurisdiction_id' => 'place']);
        DB::table('committees')->insert(['id' => 'committee', 'legislature_id' => 'leg', 'name' => 'Public works', 'status' => 'seated', 'chair_member_id' => 'chair']);
        DB::table('legislature_members')->insert(['id' => 'chair', 'legislature_id' => 'leg', 'user_id' => 'user', 'status' => 'seated']);
        DB::table('bills')->insert(['id' => self::BILL, 'committee_id' => 'committee', 'legislature_id' => 'leg']);
    }

    protected function tearDown(): void
    {
        DB::purge('committee_filing_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public static function validTargets(): array
    {
        return ['committee-wide report' => [null], 'currently assigned bill' => [self::BILL]];
    }

    #[DataProvider('validTargets')]
    public function test_committee_wide_and_current_bill_reports_publish_and_record_the_exact_target(?string $billId): void
    {
        $records = $this->createMock(PublicRecordService::class);
        $records->expects(self::once())->method('publish')->willReturnCallback(function ($kind, $title, $body, $attrs) {
            self::assertSame('other', $kind);
            self::assertSame('Committee report — Public works: Findings', $title);
            self::assertSame('Public report body', $body);
            self::assertSame('committee', $attrs['subject_id']);
            self::assertSame('committees', $attrs['subject_type']);
            self::assertSame('F-CHR-004', $attrs['via_form']);
            self::assertSame('leg', $attrs['legislature_id']);

            return (new PublicRecord)->forceFill(['id' => 'record']);
        });
        $result = DB::transaction(fn () => (new CommitteeReportFiling($records))->handle($this->actor(), $this->payload($billId)));
        self::assertSame($billId, $result['bill_id']);
        self::assertSame(1, CommitteeReport::count());
        $report = CommitteeReport::findOrFail($result['report_id']);
        self::assertSame($billId, $report->bill_id);
        self::assertSame('committee', $report->committee_id);
        self::assertSame('chair', $report->filed_by_member_id);
        self::assertSame('record', $report->report_record_id);
    }

    public static function invalidTargets(): array
    {
        return [
            'another committee in same legislature' => [['committee_id' => 'foreign'], self::BILL],
            'another legislature despite matching committee id' => [['legislature_id' => 'foreign'], self::BILL],
            'bill no longer assigned to committee' => [['committee_id' => null], self::BILL],
            'deleted bill' => [['deleted_at' => '2026-09-13'], self::BILL],
            'missing bill' => [[], '50000000-0000-4000-8000-000000000099'],
            'malformed direct engine target' => [[], 'not-a-uuid'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_bill_scope_is_rejected_before_publication_or_report_creation(array $changes, string $billId): void
    {
        if ($changes !== []) {
            DB::table('bills')->where('id', self::BILL)->update($changes);
        }
        $records = $this->createMock(PublicRecordService::class);
        $records->expects(self::never())->method('publish');
        try {
            DB::transaction(fn () => (new CommitteeReportFiling($records))->handle($this->actor(), $this->payload($billId)));
            self::fail('Out-of-scope bill was accepted.');
        } catch (ConstitutionalViolation $error) {
            self::assertStringContainsString('assigned to this committee and legislature', $error->getMessage());
            self::assertSame(0, CommitteeReport::count());
        }
    }

    private function actor(): User
    {
        return (new User)->forceFill(['id' => 'user']);
    }

    private function payload(?string $billId): array
    {
        return ['committee_id' => 'committee', 'title' => 'Findings', 'body' => 'Public report body', 'bill_id' => $billId];
    }
}
