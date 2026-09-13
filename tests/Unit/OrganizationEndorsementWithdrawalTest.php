<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\CandidateEndorsementGrant;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\Endorsement;
use App\Models\EndorsementRequest;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * F-ORG-002 organization withdraw / re-endorse (operator ruling 2026-09-13 —
 * an organization may withdraw and re-endorse at ANY time while the candidacy
 * stands). Named, private SQLite only; no world writes, no live-PG helpers.
 * Proves: withdraw sets withdrawn_at and drops the row from scopeActive AND
 * from the R-07 derivation predicate; re-endorse toggles the SAME logical row
 * (one row under the unique key); withdraw with no live row is refused; the
 * default 'grant' path is unchanged; no election-status gate anywhere.
 */
class OrganizationEndorsementWithdrawalTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.org_endorsement_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('org_endorsement_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $s = DB::connection()->getSchemaBuilder();
        $s->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name')->default('Secret legal name'); $t->string('display_name')->nullable(); $t->softDeletes();
        });
        $s->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->uuid('agent_user_id')->nullable(); $t->boolean('is_active')->default(true); $t->softDeletes();
        });
        $s->create('elections', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('status')->default('approval_open'); $t->timestamps(); $t->softDeletes();
        });
        $s->create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('user_id'); $t->uuid('election_id'); $t->uuid('race_id')->nullable();
            $t->string('status')->default('validated'); $t->timestamps(); $t->softDeletes();
        });
        $s->create('endorsements', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('candidate_id'); $t->uuid('election_id'); $t->uuid('endorser_id');
            $t->string('endorser_type'); $t->boolean('is_active')->default(true); $t->boolean('is_public')->default(true);
            $t->text('statement')->nullable(); $t->timestamp('endorsed_at')->nullable(); $t->timestamp('withdrawn_at')->nullable(); $t->timestamps();
            $t->unique(['election_id', 'candidate_id', 'endorser_type', 'endorser_id']);
        });
        $s->create('endorsement_requests', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('candidacy_id'); $t->uuid('organization_id'); $t->text('message')->nullable();
            $t->string('status')->default('pending'); $t->timestamp('requested_at')->nullable(); $t->timestamp('decided_at')->nullable();
            $t->uuid('endorsement_id')->nullable(); $t->timestamps();
        });

        // The agent (user 1), the candidate (user 20), the election (ranked_open —
        // proving no election-status gate), the standing candidacy, the org whose
        // agent is user 1, and a PENDING endorsement request.
        DB::table('users')->insert([['id' => $this->id(1), 'display_name' => 'Agent'], ['id' => $this->id(20), 'display_name' => 'Candidate']]);
        DB::table('elections')->insert(['id' => $this->id(2), 'status' => Election::STATUS_RANKED_OPEN]);
        DB::table('candidacies')->insert(['id' => $this->id(100), 'user_id' => $this->id(20), 'election_id' => $this->id(2),
            'race_id' => $this->id(3), 'status' => Candidacy::STATUS_VALIDATED, 'created_at' => '2026-09-13']);
        DB::table('organizations')->insert(['id' => $this->id(50), 'name' => 'Guild', 'agent_user_id' => $this->id(1), 'is_active' => true]);
        DB::table('endorsement_requests')->insert(['id' => $this->id(70), 'candidacy_id' => $this->id(100),
            'organization_id' => $this->id(50), 'status' => EndorsementRequest::STATUS_PENDING, 'requested_at' => now()]);
    }

    protected function tearDown(): void
    {
        DB::purge('org_endorsement_fixture'); DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('80000000-0000-4000-8000-%012d', $n); }

    private function agent(): ?User { return User::find($this->id(1)); }

    private function handler(): CandidateEndorsementGrant { return new CandidateEndorsementGrant(new RoleService); }

    /** The R-07 derivation predicate for the candidate — the exact private query R-07 reads. */
    private function r07(): bool
    {
        $m = new ReflectionMethod(RoleService::class, 'hasOrgEndorsedCandidacy');
        $m->setAccessible(true);

        return (bool) $m->invoke(new RoleService, $this->id(20));
    }

    private function grant(): array
    {
        return $this->handler()->handle($this->agent(), ['request_id' => $this->id(70), 'decision' => 'grant']);
    }

    // ── the default grant path is unchanged (byte-for-byte) ───────────────────

    public function test_default_grant_path_creates_a_forced_public_active_row(): void
    {
        $result = $this->grant();

        self::assertSame('granted', $result['decision']);
        $row = Endorsement::sole();
        self::assertSame('organization', $row->endorser_type);
        self::assertSame($this->id(50), $row->endorser_id);
        self::assertTrue($row->is_active);
        self::assertTrue($row->is_public, 'org endorsements are forced public');
        self::assertNull($row->withdrawn_at);
        self::assertSame(EndorsementRequest::STATUS_GRANTED, EndorsementRequest::find($this->id(70))->status);
        self::assertTrue($this->r07(), 'a live org endorsement confers R-07');
    }

    // ── withdraw drops the row from active AND from R-07 derivation ────────────

    public function test_withdraw_deactivates_the_row_and_removes_r07(): void
    {
        $this->grant();
        self::assertTrue($this->r07());

        $result = $this->handler()->handle($this->agent(), ['action' => 'withdraw', 'request_id' => $this->id(70)]);

        self::assertTrue($result['withdrawn']);
        $row = Endorsement::sole();
        self::assertFalse($row->is_active);
        self::assertNotNull($row->withdrawn_at);
        self::assertSame(0, Endorsement::query()->active()->count(), 'dropped from scopeActive');
        self::assertFalse($this->r07(), 'a withdrawn org endorsement no longer confers R-07');
        // The request row is left in place so the row can be re-endorsed.
        self::assertSame(EndorsementRequest::STATUS_GRANTED, EndorsementRequest::find($this->id(70))->status);
    }

    // ── re-endorse toggles the SAME logical row (one row under the unique key) ─

    public function test_re_endorse_toggles_the_same_single_row(): void
    {
        $granted = $this->grant();
        $endorsementId = $granted['endorsement_id'];

        $this->handler()->handle($this->agent(), ['action' => 'withdraw', 'request_id' => $this->id(70)]);
        $again = $this->handler()->handle($this->agent(), ['action' => 're-endorse', 'request_id' => $this->id(70)]);

        self::assertSame($endorsementId, $again['endorsement_id'], 'one logical row');
        self::assertSame(1, Endorsement::count());
        $row = Endorsement::sole();
        self::assertTrue($row->is_active);
        self::assertNull($row->withdrawn_at);
        self::assertTrue($row->is_public, 'still forced public');
        self::assertTrue($this->r07(), 're-endorse confers R-07 again');
    }

    public function test_grant_on_an_already_granted_request_re_endorses(): void
    {
        $granted = $this->grant();
        $this->handler()->handle($this->agent(), ['action' => 'withdraw', 'request_id' => $this->id(70)]);

        // A plain 'grant' (default action) on the now-granted request re-activates.
        $again = $this->grant();

        self::assertSame($granted['endorsement_id'], $again['endorsement_id']);
        self::assertSame(1, Endorsement::count());
        self::assertSame(1, Endorsement::query()->active()->count());
        self::assertTrue($this->r07());
    }

    // ── withdraw with no live row is refused ──────────────────────────────────

    public function test_withdraw_with_no_live_row_is_refused(): void
    {
        // Never granted — no endorsement row exists.
        $this->refused(fn () => $this->handler()->handle($this->agent(), ['action' => 'withdraw', 'request_id' => $this->id(70)]), 'no active endorsement');
        self::assertSame(0, Endorsement::count());

        // Granted then withdrawn — a second withdraw is refused.
        $this->grant();
        $this->handler()->handle($this->agent(), ['action' => 'withdraw', 'request_id' => $this->id(70)]);
        $this->refused(fn () => $this->handler()->handle($this->agent(), ['action' => 'withdraw', 'request_id' => $this->id(70)]), 'no active endorsement');
        self::assertSame(0, Endorsement::query()->active()->count());
    }

    // ── no election-status gate anywhere ──────────────────────────────────────

    public function test_no_election_status_gate_on_grant_or_withdraw(): void
    {
        // The election is ranked_open (voting is open). Grant and withdraw both
        // succeed — there is no election-phase window on any org action.
        self::assertSame(Election::STATUS_RANKED_OPEN, Election::find($this->id(2))->status);

        $this->grant();
        self::assertSame(1, Endorsement::query()->active()->count());

        $this->handler()->handle($this->agent(), ['action' => 'withdraw', 'request_id' => $this->id(70)]);
        self::assertSame(0, Endorsement::query()->active()->count());
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
