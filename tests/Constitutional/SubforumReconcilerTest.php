<?php

namespace Tests\Constitutional;

use App\Jobs\EvaluateSocialStructureJob;
use App\Models\SocialSpace;
use App\Models\SocialSubforum;
use App\Services\Social\SubforumReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (the auto-bind reconciler). One live subforum per live
 * governance object, idempotently: re-running over the same set is a no-op (the partial-unique
 * key), a closed object's subforum is ARCHIVED (never deleted — history stays browsable), a
 * re-opened object's subforum re-opens, and a duplicate is never created. The sweep provisions a
 * jurisdiction's public_square + halls idempotently.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SubforumReconcilerTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k1_reconciler';

    public function test_reconcile_is_idempotent_archives_closed_and_reopens(): void
    {
        $this->onLivePg(function () {
            $halls = SocialSpace::query()->create([
                'jurisdiction_id' => (string) Str::uuid(),
                'space_type'      => SocialSpace::TYPE_HALLS,
                'title'           => 'Halls',
                'status'          => SocialSpace::STATUS_OPEN,
                'is_private'      => false,
            ]);

            $billId = (string) Str::uuid();
            $petId = (string) Str::uuid();
            $live = [
                ['type' => 'bill', 'id' => $billId, 'title' => 'Bill — A'],
                ['type' => 'petition', 'id' => $petId, 'title' => 'Petition — B'],
            ];
            $reconciler = app(SubforumReconciler::class);

            $first = $reconciler->reconcile($halls, $live);
            $this->assertSame(2, $first['created']);

            // Re-run: idempotent — creates nothing, still exactly two live object subforums.
            $second = $reconciler->reconcile($halls, $live);
            $this->assertSame(0, $second['created'], 're-run is a no-op');
            $this->assertSame(2, $this->liveObjectSubforums($halls));

            // Drop the bill from the live set → its subforum is archived (not deleted).
            $reconciler->reconcile($halls, [['type' => 'petition', 'id' => $petId, 'title' => 'Petition — B']]);
            $this->assertSame(
                SocialSubforum::STATUS_ARCHIVED,
                SocialSubforum::query()->where('governing_object_type', 'bill')->where('governing_object_id', $billId)->value('status')
            );
            $this->assertSame(1, $this->liveObjectSubforums($halls));

            // Re-add the bill → its subforum re-opens; never a duplicate.
            $back = $reconciler->reconcile($halls, $live);
            $this->assertSame(1, $back['reopened']);
            $this->assertSame(2, $this->liveObjectSubforums($halls));
            $this->assertSame(
                1,
                SocialSubforum::query()->where('governing_object_type', 'bill')->where('governing_object_id', $billId)->count(),
                'the object-bound subforum is never duplicated'
            );
        });
    }

    public function test_the_sweep_provisions_square_and_halls_idempotently(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = (string) Str::uuid();

            (new EvaluateSocialStructureJob($jurisdictionId))->handle(app(SubforumReconciler::class));

            $this->assertSame(1, SocialSpace::query()->where('jurisdiction_id', $jurisdictionId)->where('space_type', 'public_square')->count());
            $this->assertSame(1, SocialSpace::query()->where('jurisdiction_id', $jurisdictionId)->where('space_type', 'halls')->count());

            // Idempotent — re-running provisions nothing new.
            (new EvaluateSocialStructureJob($jurisdictionId))->handle(app(SubforumReconciler::class));
            $this->assertSame(1, SocialSpace::query()->where('jurisdiction_id', $jurisdictionId)->where('space_type', 'halls')->count());
        });
    }

    private function liveObjectSubforums(SocialSpace $space): int
    {
        return SocialSubforum::query()
            ->where('space_id', (string) $space->id)
            ->whereNotNull('governing_object_type')
            ->where('status', SocialSubforum::STATUS_OPEN)
            ->count();
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
