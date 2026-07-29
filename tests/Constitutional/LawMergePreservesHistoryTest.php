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
 * CONSTITUTIONAL PIN — Art. V §8 law-fold. When an intermediary dissolves, its
 * Acts are incorporated "into its former Constituent Jurisdictions" — the
 * constitution's own words, and the operator's ruling 2026-07-28
 * (V3_SYNTHESIS_PLAN §10 item 2). The CONSTITUENTS inherit, never the
 * encompassing jurisdiction: each constituent receives its OWN copy carrying
 * the FULL version history (v1 verbatim, a `merge_incorporation` version
 * appended), so it can amend or repeal the inherited act independently. One
 * law_merge_resolutions row per act per constituent points at the copy. The
 * original row stays under the dissolved intermediary as the archival record,
 * marked superseded, its own history untouched.
 *
 * An earlier revision of this pin asserted the ENCOMPASSING jurisdiction
 * inherited — that was the defect, ruled against on 2026-07-28. If an edit
 * breaks this test, that edit is a constitutional violation — fix the edit,
 * never the test.
 */
class LawMergePreservesHistoryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_law_merge';

    public function test_fold_incorporates_laws_into_constituents_preserving_history(): void
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

            // ── EVERY constituent inherits its OWN copy, history intact ──────
            foreach ($constituents as $constituentId) {
                $resolution = LawMergeResolution::query()
                    ->where('process_id', (string) $process->id)
                    ->where('law_id', (string) $law->id)
                    ->where('target_jurisdiction_id', $constituentId)
                    ->where('decision', LawMergeResolution::DECISION_INCORPORATE)
                    ->first();
                $this->assertNotNull($resolution, 'each constituent gets an incorporate resolution row');
                $this->assertNotNull($resolution->resulting_law_id, 'the resolution points at the inherited copy');
                $this->assertNotSame((string) $law->id, (string) $resolution->resulting_law_id,
                    'the copy is a NEW law row — inheritance is per-constituent, never a shared move');

                $copy = Law::query()->find((string) $resolution->resulting_law_id);
                $this->assertNotNull($copy);
                $this->assertSame($constituentId, (string) $copy->jurisdiction_id,
                    'the inherited copy lives under the CONSTITUENT jurisdiction');

                $versions = LawVersion::query()->where('law_id', (string) $copy->id)->orderBy('version_no')->get();
                $this->assertCount(2, $versions, 'the copy carries the full history plus the incorporation marker');
                $this->assertSame('Original intermediary text v1', (string) $versions[0]->text, 'v1 rides verbatim');
                $this->assertSame('merge_incorporation', (string) $versions[1]->source,
                    'the appended version marks the inheritance');
            }

            // ── The ENCOMPASSING jurisdiction receives NOTHING ───────────────
            $this->assertSame(0, LawMergeResolution::query()
                ->where('process_id', (string) $process->id)
                ->where('target_jurisdiction_id', $encompassing)
                ->count(), 'Art. V §8 folds to the constituents — never the encompassing jurisdiction');

            // ── The original stays put as the archival record, untouched ─────
            $law->refresh();
            $this->assertSame($intermediary, (string) $law->jurisdiction_id,
                'the original row never moves — it is the dissolved jurisdiction\'s record');
            $this->assertSame(Law::STATUS_SUPERSEDED, (string) $law->status,
                'the original is superseded by its per-constituent successors');
            $this->assertSame(1, LawVersion::query()->where('law_id', (string) $law->id)->count(),
                'the original\'s own history is never appended to or rewritten');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }
}
