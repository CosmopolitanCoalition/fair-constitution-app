<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Election;
use App\Models\Jurisdiction;
use App\Services\ConstitutionalVersionService;
use App\Services\UpgradeFreezeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — G-VER "game in progress" freeze (Art. II §7). A
 * constitutional_version bump must NEVER re-rule a contest in flight. Pins: a live
 * (uncertified) election freezes its jurisdiction SUBTREE (assertThawed throws,
 * cited Art. II §7); a descendant's election freezes the ancestor; certifying thaws;
 * and every election is PINNED to the then-current constitutional_version at
 * creation (so its count + any countback run under those rules).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class UpgradeFreezeTest extends TestCase
{
    use FederationSyncSupport;

    public function test_a_live_election_freezes_the_subtree_and_certifying_thaws_it(): void
    {
        $this->onLivePg(function () {
            $svc = app(UpgradeFreezeService::class);

            // A fresh, isolated subtree (no parent, no children, no processes).
            $root = $this->jurisdiction(null, 5);

            // Empty subtree → thawed.
            $this->assertSame([], $svc->detectLiveProcesses($root->id));
            $svc->assertThawed($root->id); // must not throw

            // A live election pins its version + freezes the subtree.
            $election = Election::create([
                'jurisdiction_id' => $root->id,
                'kind' => Election::KIND_GENERAL,
                'status' => Election::STATUS_APPROVAL_OPEN,
            ]);
            $this->assertSame(
                app(ConstitutionalVersionService::class)->derive(),
                $election->constitutional_version,
                'an election is pinned to the current constitutional_version at creation',
            );

            $this->assertTrue($svc->isFrozen($root->id));
            $refs = array_map(fn ($l) => $l['ref'], $svc->detectLiveProcesses($root->id));
            $this->assertContains((string) $election->id, $refs);

            try {
                $svc->assertThawed($root->id);
                $this->fail('a live election must freeze the subtree');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §7', $e->citation);
            }

            // Certifying the election thaws the subtree.
            $election->forceFill(['status' => Election::STATUS_CERTIFIED])->save();
            $this->assertFalse($svc->isFrozen($root->id));
            $svc->assertThawed($root->id); // must not throw
        });
    }

    public function test_a_descendant_election_freezes_the_ancestor_subtree(): void
    {
        $this->onLivePg(function () {
            $svc = app(UpgradeFreezeService::class);

            $root = $this->jurisdiction(null, 5);
            $child = $this->jurisdiction($root->id, 6);

            Election::create([
                'jurisdiction_id' => $child->id,
                'kind' => Election::KIND_GENERAL,
                'status' => Election::STATUS_RANKED_OPEN,
            ]);

            $this->assertTrue($svc->isFrozen($root->id), 'a descendant election freezes the ancestor subtree');
            $this->assertTrue($svc->isFrozen($child->id), 'and the child itself');
        });
    }

    private function jurisdiction(?string $parentId, int $admLevel): Jurisdiction
    {
        // Jurisdiction relies on the DB gen_random_uuid() default (no HasUuids), so
        // create() would not capture the id in memory — assign it explicitly.
        $j = new Jurisdiction();
        $j->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'Freeze '.Str::random(6),
            'slug' => 'freeze-'.Str::lower(Str::random(12)),
            'adm_level' => $admLevel,
            'parent_id' => $parentId,
            'source' => 'user_defined',
        ])->save();

        return $j;
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg('pgsql_freeze');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_freeze');
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
