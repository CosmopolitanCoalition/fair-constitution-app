<?php

namespace Tests\Constitutional;

use App\Models\Law;
use App\Models\LawMergeResolution;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Services\Jurisdictions\DisintermediationService;
use App\Services\MultiJurisdictionVoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. V §8 law-merge. When an intermediary dissolves, its
 * Acts are INCORPORATED into the encompassing jurisdiction with their full
 * version history PRESERVED — the merge APPENDS a `merge_incorporation` version
 * (EnactmentService::amendLaw), never overwriting v1. Each incorporation is
 * recorded in law_merge_resolutions.
 *
 * If an edit breaks this test, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class LawMergePreservesHistoryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_law_merge';

    public function test_merge_incorporates_laws_preserving_their_version_history(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $svc = app(DisintermediationService::class);
            $mjvSvc = app(MultiJurisdictionVoteService::class);

            $legislature = Legislature::query()->whereNotNull('jurisdiction_id')->whereNull('deleted_at')->first();
            $rows = DB::table('jurisdictions')->whereNull('deleted_at')->limit(5)->pluck('id')->map('strval')->all();

            if ($legislature === null || count($rows) < 5) {
                $this->markTestSkipped('Live DB needs a legislature and ≥5 jurisdictions.');
            }

            $intermediary = $rows[0];
            $encompassing = $rows[1];
            $constituents = [$rows[2], $rows[3], $rows[4]];

            // A throwaway intermediary Act with a single original version.
            $law = Law::create([
                'jurisdiction_id' => $intermediary,
                'legislature_id' => (string) $legislature->id,
                'act_number' => 'F5-MERGE-'.Str::upper(Str::random(6)),
                'title' => 'Throwaway intermediary act',
                'kind' => Law::KIND_ORDINARY,
                'scale' => ['level' => 'local'],
                'origin' => Law::ORIGIN_BILL,
                'status' => Law::STATUS_IN_FORCE,
                'current_version_no' => 1,
                'effective_at' => now(),
                'enacted_at' => now(),
            ]);
            LawVersion::create([
                'law_id' => (string) $law->id,
                'version_no' => 1,
                'text' => 'Original intermediary text v1',
                'text_hash' => hash('sha256', 'Original intermediary text v1'),
                'source' => 'enactment',
                'source_ref_type' => 'bill',
                'source_ref_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);

            // Drive disintermediation to MERGED.
            $process = $svc->open($legislature, $intermediary, $encompassing, $constituents);
            foreach ($constituents as $c) {
                $mjvSvc->recordConsent($process->constituentProcess->refresh(), $c, true);
            }
            $svc->recordEncompassingConsent($process->refresh(), true);
            $svc->finalize($process->refresh());

            // History preserved: v1 intact, a merge_incorporation version appended.
            $versions = LawVersion::query()->where('law_id', (string) $law->id)->orderBy('version_no')->get();
            $this->assertCount(2, $versions, 'the merge appends a version, never overwrites');
            $this->assertSame('Original intermediary text v1', (string) $versions[0]->text, 'v1 is preserved verbatim');
            $this->assertSame('merge_incorporation', (string) $versions[1]->source, 'the appended version is a merge incorporation');

            // The Act now lives under the encompassing jurisdiction, recorded.
            $this->assertSame($encompassing, (string) $law->refresh()->jurisdiction_id);
            $this->assertTrue(
                LawMergeResolution::query()->where('law_id', (string) $law->id)
                    ->where('decision', LawMergeResolution::DECISION_INCORPORATE)->exists(),
                'the incorporation is recorded in law_merge_resolutions'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }
}
