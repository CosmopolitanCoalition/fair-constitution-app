<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\CommitteePreferenceRanking;
use App\Models\CommitteePreference;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The actual preference handler over private SQLite fixtures; no engine/audit/world writes. */
final class CommitteePreferenceRevisionTest extends TestCase
{
    use \Tests\Concerns\AchievementSchema;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.preference_revision_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('preference_revision_fixture');
        $this->createAchievementTables(); // AC-1: the wired handlers read the ledger before they award
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach (['legislatures' => [], 'legislature_members' => ['legislature_id', 'user_id', 'status'], 'committees' => ['legislature_id', 'status']] as $name => $columns) {
            DB::connection()->getSchemaBuilder()->create($name, function (Blueprint $t) use ($columns): void {
                $t->string('id')->primary();
                foreach ($columns as $column) $t->string($column);
                $t->softDeletes();
            });
        }
        DB::connection()->getSchemaBuilder()->create('committee_preferences', function (Blueprint $t): void {
            $t->string('id')->primary(); $t->string('legislature_id'); $t->string('member_id');
            $t->text('rankings'); $t->timestamp('submitted_at'); $t->timestamps();
            $t->unique(['legislature_id', 'member_id']);
        });
        DB::table('legislatures')->insert(['id' => 'chamber']);
        DB::table('legislature_members')->insert([
            ['id' => 'member', 'legislature_id' => 'chamber', 'user_id' => 'viewer', 'status' => 'seated'],
            ['id' => 'outsider', 'legislature_id' => 'other', 'user_id' => 'outsider', 'status' => 'seated'],
        ]);
        DB::table('committees')->insert([
            ['id' => 'first', 'legislature_id' => 'chamber', 'status' => 'created'],
            ['id' => 'second', 'legislature_id' => 'chamber', 'status' => 'seated'],
            ['id' => 'foreign', 'legislature_id' => 'other', 'status' => 'created'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('preference_revision_fixture'); DB::setDefaultConnection($this->original); parent::tearDown();
    }

    public function test_current_member_can_revise_a_submitted_preference_without_duplicate_rows(): void
    {
        $handler = new CommitteePreferenceRanking;
        $actor = (new User)->forceFill(['id' => 'viewer']);
        $handler->handle($actor, ['legislature_id' => 'chamber', 'rankings' => ['first', 'second']]);
        $id = CommitteePreference::query()->firstOrFail()->id;
        $handler->handle($actor, ['legislature_id' => 'chamber', 'rankings' => ['second', 'first']]);
        self::assertSame(1, CommitteePreference::query()->count());
        self::assertSame($id, CommitteePreference::query()->firstOrFail()->id);
        self::assertSame(['second', 'first'], CommitteePreference::query()->firstOrFail()->rankings);
    }

    public function test_revision_keeps_membership_and_committee_scope_boundaries(): void
    {
        foreach ([['outsider', ['first']], ['viewer', ['foreign']], ['viewer', ['first', 'first']]] as [$actorId, $rankings]) {
            try {
                (new CommitteePreferenceRanking)->handle((new User)->forceFill(['id' => $actorId]), ['legislature_id' => 'chamber', 'rankings' => $rankings]);
                self::fail('Invalid preference filing accepted.');
            } catch (ConstitutionalViolation) {
                self::assertSame(0, CommitteePreference::query()->count());
            }
        }
        DB::table('legislature_members')->where('id', 'member')->update(['status' => 'vacated']);
        $this->expectException(ConstitutionalViolation::class);
        (new CommitteePreferenceRanking)->handle((new User)->forceFill(['id' => 'viewer']), ['legislature_id' => 'chamber', 'rankings' => ['first']]);
    }
}
