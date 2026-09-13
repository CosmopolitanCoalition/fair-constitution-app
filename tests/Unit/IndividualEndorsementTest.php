<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\EngineResult;
use App\Models\AuditEntry;
use App\Domain\Forms\Handlers\IndividualEndorsement;
use App\Domain\Forms\Handlers\IndividualEndorsementWithdrawal;
use App\Http\Controllers\Elections\CandidacyController;
use App\Http\Presenters\CandidacyPanel;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\Endorsement;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\RoleService;
use App\Support\CandidacyEndorsementDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EO-5 — the individual endorsement pair (F-IND-025/026). Named, private
 * SQLite only; no world writes, no live-PG helpers. Every authority rule the
 * handler owns is proven here, and the secret-approval table is never read or
 * written by this path.
 */
class IndividualEndorsementTest extends TestCase
{
    private string $original;
    private CandidacyEndorsementDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.individual_endorsement_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('individual_endorsement_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $s = DB::connection()->getSchemaBuilder();
        $s->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name')->default('Secret legal name'); $t->string('display_name')->nullable(); $t->softDeletes();
        });
        $s->create('social_profiles', function (Blueprint $t) {
            $t->uuid('user_id')->unique(); $t->string('display_name')->nullable(); $t->string('handle')->nullable();
            $t->string('visibility')->default('private'); $t->softDeletes();
        });
        $s->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name')->default('Test place'); $t->softDeletes();
        });
        $s->create('elections', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('jurisdiction_id')->nullable(); $t->uuid('legislature_id')->nullable();
            $t->string('status')->default('approval_open'); $t->timestamp('finalist_cutoff_at')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        $s->create('election_races', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('election_id'); $t->uuid('district_id')->nullable(); $t->uuid('type_b_panel_id')->nullable();
            $t->uuid('jurisdiction_id')->nullable(); $t->integer('seats')->default(5); $t->integer('finalist_count')->default(5);
            $t->string('seat_kind')->default('general'); $t->softDeletes();
        });
        $s->create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('user_id'); $t->uuid('election_id'); $t->uuid('race_id')->nullable();
            $t->string('status')->default('validated'); $t->text('platform_statement')->nullable(); $t->text('position_tags')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        $s->create('residency_confirmations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('user_id'); $t->uuid('jurisdiction_id'); $t->boolean('is_active')->default(true); $t->integer('depth')->default(0);
        });
        $s->create('legislature_district_jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('district_id'); $t->uuid('jurisdiction_id');
        });
        $s->create('legislature_type_b_panel_jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('panel_id'); $t->uuid('jurisdiction_id');
        });
        $s->create('approval_standings', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('race_id'); $t->uuid('candidacy_id'); $t->date('as_of_date');
            $t->integer('rank'); $t->integer('approvals_count'); $t->boolean('is_frozen')->default(false);
        });
        $s->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->boolean('is_active')->default(true); $t->softDeletes();
        });
        $s->create('endorsements', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('candidate_id'); $t->uuid('election_id'); $t->uuid('endorser_id');
            $t->string('endorser_type'); $t->boolean('is_active')->default(true); $t->boolean('is_public')->default(true);
            $t->text('statement')->nullable(); $t->timestamp('endorsed_at')->nullable(); $t->timestamp('withdrawn_at')->nullable(); $t->timestamps();
            $t->unique(['election_id', 'candidate_id', 'endorser_type', 'endorser_id']);
        });

        // The place, the election (approval phase — before voting), the at-large
        // race in it, the candidate (owner user 1), and a resident of the race.
        DB::table('jurisdictions')->insert(['id' => $this->id(500), 'name' => 'Test place']);
        DB::table('elections')->insert(['id' => $this->id(2), 'jurisdiction_id' => $this->id(500), 'status' => Election::STATUS_APPROVAL_OPEN]);
        DB::table('election_races')->insert(['id' => $this->id(3), 'election_id' => $this->id(2), 'jurisdiction_id' => $this->id(500), 'seats' => 5, 'finalist_count' => 5, 'seat_kind' => 'general']);
        $this->user(1);   // the candidate
        $this->user(10);  // a resident of the race
        $this->resident(10);
        $this->candidacy(100, 1);
        $this->directory = new CandidacyEndorsementDirectory;
    }

    protected function tearDown(): void
    {
        DB::purge('individual_endorsement_fixture'); DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('70000000-0000-4000-8000-%012d', $n); }

    private function user(int $n): User
    {
        DB::table('users')->insert(['id' => $this->id($n), 'display_name' => 'Public person '.$n]);
        return User::findOrFail($this->id($n));
    }

    private function resident(int $n): void
    {
        DB::table('residency_confirmations')->insert(['id' => $this->id(9000 + $n), 'user_id' => $this->id($n),
            'jurisdiction_id' => $this->id(500), 'is_active' => true, 'depth' => 0]);
    }

    private function candidacy(int $n, int $user, array $extra = []): Candidacy
    {
        DB::table('candidacies')->insert($extra + ['id' => $this->id($n), 'user_id' => $this->id($user),
            'election_id' => $this->id(2), 'race_id' => $this->id(3), 'status' => Candidacy::STATUS_VALIDATED, 'created_at' => '2026-09-13']);
        return Candidacy::findOrFail($this->id($n));
    }

    private function candidate(): Candidacy { return Candidacy::findOrFail($this->id(100)); }

    private function request(?User $viewer = null): Request
    {
        $r = Request::create('/people'); $r->setUserResolver(fn () => $viewer); return $r;
    }

    // ── the write path ──────────────────────────────────────────────────────

    public function test_resident_endorses_and_the_row_is_private_by_default(): void
    {
        $result = (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);

        $row = Endorsement::sole();
        self::assertSame('user', $row->endorser_type);
        self::assertSame($this->id(10), $row->endorser_id);
        self::assertFalse($row->is_public, 'default private (D-3)');
        self::assertTrue($row->is_active);
        self::assertNull($row->withdrawn_at);
        self::assertNotNull($row->endorsed_at);
        self::assertFalse($result['is_public']);
        self::assertFalse($result['withdrawn']);
        // The private row does not surface in the public individuals() reader,
        // but the anonymous count sees it.
        $page = $this->directory->individuals($this->request(), $this->candidate());
        self::assertSame([], $page['rows']);
        self::assertSame(['total' => 1, 'public' => 0, 'private' => 1], $page['counts']);
    }

    public function test_public_opt_in_surfaces_in_the_individuals_reader(): void
    {
        (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100), 'is_public' => true]);

        $row = Endorsement::sole();
        self::assertTrue($row->is_public);
        $page = $this->directory->individuals($this->request(), $this->candidate());
        self::assertSame([$this->id(10)], array_column($page['rows'], 'user_id'));
        self::assertSame(['total' => 1, 'public' => 1, 'private' => 0], $page['counts']);
    }

    public function test_withdraw_drops_the_row_from_active_and_from_the_reader(): void
    {
        (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100), 'is_public' => true]);
        (new IndividualEndorsementWithdrawal)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);

        $row = Endorsement::sole();
        self::assertNotNull($row->withdrawn_at);
        self::assertFalse($row->is_active);
        self::assertSame(0, Endorsement::query()->active()->count());
        self::assertSame([], $this->directory->individuals($this->request(), $this->candidate())['rows']);
    }

    public function test_re_endorse_toggles_the_same_single_row(): void
    {
        $first = (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
        (new IndividualEndorsementWithdrawal)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
        $again = (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100), 'is_public' => true]);

        self::assertSame($first['endorsement_id'], $again['endorsement_id'], 'one logical row (D-2)');
        self::assertSame(1, Endorsement::count());
        $row = Endorsement::sole();
        self::assertNull($row->withdrawn_at);
        self::assertTrue($row->is_active);
        self::assertTrue($row->is_public);
    }

    // ── the refusals the handler owns ─────────────────────────────────────────

    public function test_non_resident_is_refused_with_no_write(): void
    {
        $this->user(20); // no residency confirmation
        $this->refused(fn () => (new IndividualEndorsement)->handle(User::find($this->id(20)), ['candidacy_id' => $this->id(100)]), 'Art. I');
        self::assertSame(0, Endorsement::count());
    }

    public function test_candidacy_no_longer_standing_is_refused_with_no_write(): void
    {
        DB::table('candidacies')->where('id', $this->id(100))->update(['status' => Candidacy::STATUS_ELECTED]);
        $this->refused(fn () => (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]), 'no longer standing');
        self::assertSame(0, Endorsement::count());
    }

    public function test_endorse_and_withdraw_succeed_in_any_election_status(): void
    {
        // No election-phase window (operator ruling 2026-09-13): the pair works
        // in ranked_open, voting_closed, certified, or any status while the
        // candidacy stands. Endorse, withdraw, endorse again — any time.
        foreach ([Election::STATUS_RANKED_OPEN, Election::STATUS_VOTING_CLOSED, Election::STATUS_CERTIFIED, Election::STATUS_FINAL] as $status) {
            DB::table('endorsements')->delete();
            DB::table('elections')->where('id', $this->id(2))->update(['status' => $status]);

            $made = (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
            self::assertFalse($made['withdrawn'], "endorse succeeds in {$status}");
            self::assertSame(1, Endorsement::query()->active()->count(), "active after endorse in {$status}");

            $gone = (new IndividualEndorsementWithdrawal)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
            self::assertTrue($gone['withdrawn'], "withdraw succeeds in {$status}");
            self::assertSame(0, Endorsement::query()->active()->count(), "withdrawn in {$status}");

            // And re-endorse the same logical row, still no window.
            (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
            self::assertSame(1, Endorsement::count(), "one logical row in {$status}");
            self::assertSame(1, Endorsement::query()->active()->count(), "re-endorsed in {$status}");
        }
    }

    public function test_owner_cannot_endorse_their_own_candidacy(): void
    {
        $this->resident(1); // even a resident owner is refused
        $this->refused(fn () => (new IndividualEndorsement)->handle(User::find($this->id(1)), ['candidacy_id' => $this->id(100)]), 'your own candidacy');
        self::assertSame(0, Endorsement::count());
    }

    public function test_withdraw_without_an_active_row_of_your_own_is_refused(): void
    {
        // User 10 holds the only endorsement; user 30 (a resident) holds none.
        (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
        $this->user(30); $this->resident(30);
        $this->refused(fn () => (new IndividualEndorsementWithdrawal)->handle(User::find($this->id(30)), ['candidacy_id' => $this->id(100)]), 'no active endorsement');
        // User 10's row is untouched.
        self::assertSame(1, Endorsement::query()->active()->count());
    }

    // ── the controller wires the exact form ids through the engine ────────────

    public function test_controller_files_the_exact_form_ids_through_the_engine(): void
    {
        $engine = $this->createMock(ConstitutionalEngine::class);
        $seen = [];
        $engine->method('file')->willReturnCallback(function (string $formId, ?User $actor, array $payload) use (&$seen) {
            $seen[] = [$formId, $payload]; return new EngineResult($formId, new AuditEntry, $payload);
        });
        $controller = new CandidacyController($engine, $this->createMock(RoleService::class));

        $endorseReq = Request::create('/candidates/'.$this->id(100).'/endorsement', 'POST', ['is_public' => '1']);
        $endorseReq->setUserResolver(fn () => User::find($this->id(10)));
        self::assertTrue($controller->endorse($endorseReq, $this->id(100))->isRedirect());

        $withdrawReq = Request::create('/candidates/'.$this->id(100).'/endorsement/withdraw', 'POST');
        $withdrawReq->setUserResolver(fn () => User::find($this->id(10)));
        self::assertTrue($controller->withdrawEndorsement($withdrawReq, $this->id(100))->isRedirect());

        self::assertSame('F-IND-025', $seen[0][0]);
        self::assertSame($this->id(100), $seen[0][1]['candidacy_id']);
        self::assertTrue($seen[0][1]['is_public']);
        self::assertSame($this->id(500), $seen[0][1]['jurisdiction_id']);
        self::assertSame('F-IND-026', $seen[1][0]);
        self::assertSame($this->id(100), $seen[1][1]['candidacy_id']);
    }

    // ── the secret approval table is never touched by this path ───────────────

    public function test_the_secret_approvals_table_is_never_queried(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100), 'is_public' => true]);
        (new IndividualEndorsementWithdrawal)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
        foreach (DB::getQueryLog() as $q) {
            self::assertStringNotContainsString('approvals', $q['query'], 'the individual endorsement path must never read or write the secret approvals table');
        }
    }

    // ── the panel exposes the viewer's own endorsement + can flags ────────────

    public function test_panel_exposes_can_endorse_and_the_viewers_own_endorsement(): void
    {
        $panel = new CandidacyPanel(new ApprovalService($this->createMock(AuditService::class)));

        // A resident non-owner may endorse; no endorsement yet.
        $before = $panel->for($this->candidate(), User::find($this->id(10)));
        self::assertTrue($before['can']['endorse']);
        self::assertFalse($before['can']['withdraw_endorsement']);
        self::assertNull($before['viewerEndorsement']);

        // After a private endorsement: withdraw is offered, endorse is not,
        // and the viewer's own state is visible to them (private).
        (new IndividualEndorsement)->handle(User::find($this->id(10)), ['candidacy_id' => $this->id(100)]);
        $after = $panel->for($this->candidate(), User::find($this->id(10)));
        self::assertFalse($after['can']['endorse']);
        self::assertTrue($after['can']['withdraw_endorsement']);
        self::assertSame(['is_public' => false, 'withdrawn' => false], [
            'is_public' => $after['viewerEndorsement']['is_public'], 'withdrawn' => $after['viewerEndorsement']['withdrawn'],
        ]);
        self::assertNotNull($after['viewerEndorsement']['endorsed_at']);

        // The candidate (owner) gets no control.
        $owner = $panel->for($this->candidate(), User::find($this->id(1)));
        self::assertTrue($owner['isOwner']);
        self::assertFalse($owner['can']['endorse']);
        self::assertFalse($owner['can']['withdraw_endorsement']);

        // A signed-out viewer gets no control and no viewer state.
        $guest = $panel->for($this->candidate(), null);
        self::assertFalse($guest['can']['endorse']);
        self::assertNull($guest['viewerEndorsement']);
    }

    public function test_panel_still_offers_endorse_when_voting_is_open(): void
    {
        // The panel mirrors the handler: no election-status term (operator
        // ruling 2026-09-13). A resident non-owner may still endorse after
        // voting opens.
        DB::table('elections')->where('id', $this->id(2))->update(['status' => Election::STATUS_RANKED_OPEN]);
        $panel = new CandidacyPanel(new ApprovalService($this->createMock(AuditService::class)));
        $view = $panel->for($this->candidate(), User::find($this->id(10)));
        self::assertTrue($view['can']['endorse']);
        self::assertFalse($view['can']['withdraw_endorsement']);
    }

    private function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            self::fail('Expected a ConstitutionalViolation containing "'.$needle.'".');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString($needle, $e->getMessage().' '.$e->citation);
        }
    }
}
