<?php

namespace Tests\Constitutional;

use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — K2_ENGINE_PLAN §4/§6.2: `education_progress` is
 * node-local resume state and NEVER federates or publishes. What travels is
 * the filed F-EDU-001 completion (a public constitutional act under Full
 * Faith & Credit) — which is also the only thing the training gate reads,
 * so federation never needs this table.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class EducationProgressNeverFederatesTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_edu_progress_fed';

    /** The shape itself refuses federation: no origin column, no soft-delete history. */
    public function test_the_table_carries_no_federation_or_history_columns(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        try {
            $columns = Schema::getColumnListing('education_progress');

            $this->assertNotEmpty($columns);
            $this->assertNotContains('source_server_id', $columns, 'education_progress must never carry a federation origin.');
            $this->assertNotContains('deleted_at', $columns, 'education_progress mirrors journey_progress — no soft-delete history.');
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    public function test_the_public_register_refuses_education_progress(): void
    {
        $this->assertContains(
            'education_progress',
            PublicRecordService::FORBIDDEN_SUBJECT_TYPES,
            'education_progress must be a forbidden public-record subject.'
        );
    }

    /** No federation/export/sync code path names the table. */
    public function test_no_federation_code_reaches_the_table(): void
    {
        $root = str_replace('\\', '/', \dirname(__DIR__, 2).'/app');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // Only federation/sync/export planes are scanned — the education
            // plane itself (handler, controller, seeder) legitimately writes
            // its own local table.
            if (! preg_match('!/(Federation|Sync|Export)!i', $path)) {
                continue;
            }

            $code = preg_replace('!/\*.*?\*/!s', '', file_get_contents($path));
            $code = preg_replace('!//.*$!m', '', $code);

            $this->assertStringNotContainsString(
                'education_progress',
                $code,
                "{$path} touches education_progress — the learner's resume state never crosses a node boundary."
            );
        }

        $this->addToAssertionCount(1);
    }
}
