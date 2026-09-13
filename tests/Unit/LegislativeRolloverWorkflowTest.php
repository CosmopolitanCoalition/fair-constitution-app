<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\{ChamberVote, ClockTimer, Committee, CommitteeSeat, Election, ElectionCertification, Legislature, LegislatureMember, Term};
use App\Services\{AuditService, CertificationService, ClockService, ElectionLifecycleService, ReferendumService, RoleService, SettingsResolver};
use App\Services\Legislature\{CommitteeAssignmentService, CommitteeService, SpeakerService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB};
use Mockery;
use Tests\TestCase;

/**
 * Real certification seating/turnover, clocks and committee assignment on
 * private SQLite. Upstream sealed counts are fixtures. Audit, settings,
 * referendum effects and successor scheduling are doubles, not an election
 * end-to-end claim. Queued social provisioning never executes.
 */
final class LegislativeRolloverWorkflowTest extends TestCase
{
    private string $original;
    private CertificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.rollover_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('rollover_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Bus::fake();
        $this->travelTo(now()->setDate(2026, 9, 13)->startOfDay());

        $tables = [
            'legislatures' => 'jurisdiction_id status speaker_id term_starts_on term_ends_on term_number total_seats type_a_seats type_b_seats',
            'legislature_members' => 'legislature_id user_id status is_speaker seat_type seat_no vote_share_norm term_id district_id elected_in_race_id election_id seated_on seated_at term_ends_on home_jurisdiction_id',
            'terms' => 'legislature_id jurisdiction_id status term_class starts_on ends_on office_kind office_type office_id holder_user_id source_election_id',
            'clock_timers' => 'clock_id jurisdiction_id subject_type subject_id state payload armed_at fires_at',
            'clocks' => '',
            'committees' => 'legislature_id name purpose status chair_member_id alternate_member_id seats type_a_seats type_b_seats',
            'committee_seats' => 'committee_id member_id status seat_kind assigned_via preference_rank_honored seated_at vacated_at vacated_reason',
            'committee_preferences' => 'legislature_id member_id rankings submitted_at',
            'chamber_votes' => 'body_type body_id legislature_id votable_type votable_id vote_type status outcome decided_at rcv_record',
            'elections' => 'legislature_id jurisdiction_id kind vacancy_id',
            'election_races' => 'election_id seat_kind district_id',
            'tabulations' => 'race_id status record_hash completed_at',
            'race_results' => 'tabulation_id candidacy_id seat_no vote_share_norm',
            'candidacies' => 'race_id user_id status',
            'residency_confirmations' => 'user_id jurisdiction_id is_active depth',
            'executives' => 'jurisdiction_id status',
            'vacancies' => 'seat_id status filled_by_user_id filled_at',
        ];
        foreach ($tables as $name => $columns) {
            DB::connection()->getSchemaBuilder()->create($name, function (Blueprint $t) use ($columns): void {
                $t->string('id')->primary();
                foreach (array_filter(explode(' ', $columns)) as $column) $t->text($column)->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        DB::statement('CREATE UNIQUE INDEX fixture_current_member ON legislature_members (legislature_id, user_id) WHERE status IN (\'seated\', \'elected\') AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX fixture_live_committee_seat ON committee_seats (committee_id, member_id) WHERE vacated_at IS NULL');
        DB::table('clocks')->insert(['id' => 'CLK-10']);

        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('append')->andReturn(new \App\Models\AuditEntry);
        $settings = Mockery::mock(SettingsResolver::class);
        $settings->shouldReceive('resolveInt')->with('place', 'election_interval_months', 60)->andReturn(48);
        $lifecycle = Mockery::mock(ElectionLifecycleService::class);
        $lifecycle->shouldReceive('armNextGeneralElection')->andReturn(new ClockTimer);
        $lifecycle->shouldReceive('openSuccessor')->andReturn((new Election)->forceFill(['id' => 'successor']));
        $referendums = Mockery::mock(ReferendumService::class);
        $referendums->shouldReceive('certifyForElection')->andReturn([]);
        $referendums->shouldReceive('releaseShields')->andReturn(0);
        $this->app->instance(AuditService::class, $audit);
        $this->app->instance(SettingsResolver::class, $settings);
        $this->app->instance(ReferendumService::class, $referendums);
        $this->service = new CertificationService($audit, new ClockService($audit, $settings), $settings, $lifecycle, app(RoleService::class));
        $this->seedOutgoing();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('rollover_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_general_certification_retires_old_offices_and_assigns_retained_committees(): void
    {
        $result = $this->certify();
        self::assertCount(5, $result['winners']);
        self::assertSame(['starts_on' => '2026-09-13', 'ends_on' => '2030-09-13', 'inherited' => false], $result['term_window']);
        $chamber = Legislature::findOrFail('chamber');
        self::assertNull($chamber->speaker_id);
        self::assertSame(3, $chamber->term_number);
        self::assertSame(0, LegislatureMember::where('legislature_id', 'chamber')->where('is_speaker', true)->count());
        self::assertSame('term_ended', LegislatureMember::findOrFail('old')->status);
        self::assertSame('completed', Term::findOrFail('old-term')->status);
        self::assertSame('2030-01-01', Term::findOrFail('old-term')->ends_on->toDateString());
        self::assertSame('cancelled', ClockTimer::findOrFail('old-clock')->state);
        self::assertSame(5, ClockTimer::armed()->where('clock_id', 'CLK-10')->count());
        $oldSeat = CommitteeSeat::findOrFail('old-placement');
        self::assertSame('vacated', $oldSeat->status);
        self::assertSame('chamber_turnover', $oldSeat->vacated_reason);
        self::assertNotNull($oldSeat->vacated_at);
        self::assertSame('2025-03-01', CommitteeSeat::findOrFail('historical-placement')->vacated_at->toDateString());
        foreach (['retained', 'pending'] as $id) {
            $committee = Committee::findOrFail($id);
            self::assertSame('created', $committee->status);
            self::assertNull($committee->chair_member_id);
            self::assertNull($committee->alternate_member_id);
        }
        self::assertSame('dissolved', Committee::findOrFail('dissolved')->status);
        self::assertTrue(Committee::withTrashed()->findOrFail('deleted')->trashed());
        self::assertSame('old-foreign', Legislature::findOrFail('foreign')->speaker_id);
        self::assertSame('seated', Committee::findOrFail('foreign-committee')->status);
        self::assertNull(CommitteeSeat::findOrFail('foreign-placement')->vacated_at);

        foreach (['open-speaker', 'open-replacement', 'open-chair'] as $id) self::assertSame('void', ChamberVote::findOrFail($id)->status);
        foreach (['closed-speaker' => 'closed', 'bill-vote' => 'open', 'foreign-vote' => 'open'] as $id => $status) self::assertSame($status, ChamberVote::findOrFail($id)->status);
        self::assertSame(['winner_member_id' => 'old'], ChamberVote::findOrFail('closed-speaker')->rcv_record);
        self::assertNull(app(SpeakerService::class)->openBallotFor($chamber));
        self::assertNull(app(CommitteeService::class)->openChairBallotFor(Committee::findOrFail('retained')));

        $assigned = app(CommitteeAssignmentService::class)->run($chamber);
        self::assertCount(5, $assigned['placements']);
        self::assertArrayNotHasKey('old', $assigned['preferences']);
        $incoming = array_column($result['winners'], 'member_id');
        foreach ($assigned['placements'] as $placement) {
            self::assertContains($placement['member_id'], $incoming);
            self::assertContains($placement['committee_id'], ['retained', 'pending']);
        }
        self::assertSame('seated', Committee::findOrFail('retained')->status);
        self::assertSame('vacated', $oldSeat->refresh()->status);
        self::assertSame(1, DB::table('committee_preferences')->where('member_id', 'old')->count());
        $this->expectException(ConstitutionalViolation::class);
        app(CommitteeAssignmentService::class)->run($chamber);
    }

    public function test_bicameral_rollover_preserves_kind_splits_and_uses_only_incoming_members(): void
    {
        DB::table('legislatures')->where('id', 'chamber')->update(['type_a_seats' => 3, 'type_b_seats' => 2]);
        DB::table('committees')->where('id', 'retained')->update(['type_a_seats' => 2, 'type_b_seats' => 1]);
        DB::table('committees')->where('id', 'pending')->update(['type_a_seats' => 1, 'type_b_seats' => 1]);
        DB::table('election_races')->insert(['id' => 'race-b', 'election_id' => 'election', 'seat_kind' => 'type_b']);
        DB::table('tabulations')->insert(['id' => 'count-b', 'race_id' => 'race-b', 'status' => 'complete', 'record_hash' => 'fixture', 'completed_at' => now()]);
        DB::table('candidacies')->whereIn('id', ['candidate-4', 'candidate-5'])->update(['race_id' => 'race-b']);
        DB::table('race_results')->whereIn('id', ['result-4', 'result-5'])->update(['tabulation_id' => 'count-b']);
        $result = $this->certify();
        $assigned = app(CommitteeAssignmentService::class)->run(Legislature::findOrFail('chamber'));
        self::assertCount(5, $assigned['placements']);
        foreach (['retained' => ['type_a' => 2, 'type_b' => 1], 'pending' => ['type_a' => 1, 'type_b' => 1]] as $id => $kinds) {
            foreach ($kinds as $kind => $count) self::assertSame($count, CommitteeSeat::live()->where('committee_id', $id)->where('seat_kind', $kind)->count());
        }
        self::assertSame([], array_values(array_diff(array_column($assigned['placements'], 'member_id'), array_column($result['winners'], 'member_id'))));
    }

    public function test_special_election_keeps_the_existing_speaker_and_committee_work(): void
    {
        DB::table('elections')->where('id', 'election')->update(['kind' => 'special']);
        DB::table('race_results')->where('id', '!=', 'result-2')->delete();
        $before = CommitteeSeat::findOrFail('old-placement')->getAttributes();
        $result = $this->certify();
        self::assertCount(1, $result['winners']);
        self::assertTrue($result['term_window']['inherited']);
        self::assertSame('2030-01-01', $result['term_window']['ends_on']);
        self::assertSame('old', Legislature::findOrFail('chamber')->speaker_id);
        self::assertTrue(LegislatureMember::findOrFail('old')->is_speaker);
        self::assertSame('seated', Committee::findOrFail('retained')->status);
        self::assertSame($before, CommitteeSeat::findOrFail('old-placement')->getAttributes());
        self::assertSame('open', ChamberVote::findOrFail('open-chair')->status);
        self::assertSame('active', Term::findOrFail('old-term')->status);
        self::assertSame('armed', ClockTimer::findOrFail('old-clock')->state);
    }

    public function test_failed_certification_rolls_back_the_retirement_with_the_outer_transaction(): void
    {
        DB::table('tabulations')->where('id', 'count')->update(['record_hash' => null]);
        try {
            $this->certify();
            self::fail('Missing sealed count accepted.');
        } catch (ConstitutionalViolation) {
            self::assertSame('old', Legislature::findOrFail('chamber')->speaker_id);
            self::assertSame('seated', LegislatureMember::findOrFail('old')->status);
            self::assertSame('active', Term::findOrFail('old-term')->status);
            self::assertSame('armed', ClockTimer::findOrFail('old-clock')->state);
            self::assertSame('seated', Committee::findOrFail('retained')->status);
            self::assertNull(CommitteeSeat::findOrFail('old-placement')->vacated_at);
            self::assertSame('open', ChamberVote::findOrFail('open-speaker')->status);
            self::assertSame(0, LegislatureMember::where('election_id', 'election')->count());
        }
    }

    private function certify(): array
    {
        return DB::transaction(fn () => $this->service->certify(
            Election::findOrFail('election'),
            (new ElectionCertification)->forceFill(['certified_at' => now()]),
        ));
    }

    private function seedOutgoing(): void
    {
        foreach (['chamber' => 'old', 'foreign' => 'old-foreign'] as $id => $speaker) {
            DB::table('legislatures')->insert(['id' => $id, 'jurisdiction_id' => 'place', 'status' => 'active', 'speaker_id' => $speaker, 'term_number' => 2, 'term_starts_on' => '2025-01-01', 'term_ends_on' => '2030-01-01', 'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0]);
            DB::table('legislature_members')->insert(['id' => $speaker, 'legislature_id' => $id, 'user_id' => 'returning-user', 'status' => 'seated', 'is_speaker' => true, 'seat_type' => 'a', 'seat_no' => 1, 'vote_share_norm' => '1.2']);
        }
        DB::table('terms')->insert(['id' => 'old-term', 'legislature_id' => 'chamber', 'status' => 'active', 'term_class' => 'lockstep', 'starts_on' => '2025-01-01', 'ends_on' => '2030-01-01']);
        DB::table('clock_timers')->insert(['id' => 'old-clock', 'clock_id' => 'CLK-10', 'subject_type' => 'term', 'subject_id' => 'old-term', 'state' => 'armed']);
        foreach (['retained' => 'seated', 'pending' => 'created', 'dissolved' => 'dissolved', 'deleted' => 'seated', 'foreign-committee' => 'seated'] as $id => $status) {
            DB::table('committees')->insert(['id' => $id, 'legislature_id' => $id === 'foreign-committee' ? 'foreign' : 'chamber', 'name' => $id, 'status' => $status, 'chair_member_id' => 'old', 'alternate_member_id' => 'old', 'seats' => $id === 'pending' ? 2 : 3, 'created_at' => '2025-01-01', 'deleted_at' => $id === 'deleted' ? '2026-01-01' : null]);
        }
        foreach (['old-placement' => 'retained', 'historical-placement' => 'retained', 'foreign-placement' => 'foreign-committee'] as $id => $committee) {
            DB::table('committee_seats')->insert(['id' => $id, 'committee_id' => $committee, 'member_id' => $id === 'historical-placement' ? 'historical' : 'old', 'status' => $id === 'historical-placement' ? 'vacated' : 'seated', 'vacated_at' => $id === 'historical-placement' ? '2025-03-01' : null]);
        }
        DB::table('committee_preferences')->insert(['id' => 'old-preference', 'legislature_id' => 'chamber', 'member_id' => 'old', 'rankings' => '["retained","pending"]']);
        foreach (['open-speaker' => 'speaker_elect', 'open-replacement' => 'speaker_replace', 'open-chair' => 'committee_chair', 'closed-speaker' => 'speaker_elect', 'bill-vote' => 'bill', 'foreign-vote' => 'speaker_elect'] as $id => $type) {
            DB::table('chamber_votes')->insert(['id' => $id, 'body_type' => 'legislature', 'body_id' => $id === 'foreign-vote' ? 'foreign' : 'chamber', 'vote_type' => $type, 'status' => $id === 'closed-speaker' ? 'closed' : 'open', 'votable_type' => $type === 'committee_chair' ? 'committee' : null, 'votable_id' => $type === 'committee_chair' ? 'retained' : null, 'rcv_record' => $id === 'closed-speaker' ? '{"winner_member_id":"old"}' : null]);
        }
        DB::table('elections')->insert(['id' => 'election', 'legislature_id' => 'chamber', 'jurisdiction_id' => 'place', 'kind' => 'general']);
        DB::table('election_races')->insert(['id' => 'race', 'election_id' => 'election', 'seat_kind' => 'type_a']);
        DB::table('tabulations')->insert(['id' => 'count', 'race_id' => 'race', 'status' => 'complete', 'record_hash' => 'fixture', 'completed_at' => now()]);
        for ($n = 1; $n <= 5; $n++) {
            DB::table('candidacies')->insert(['id' => "candidate-$n", 'race_id' => 'race', 'user_id' => $n === 1 ? 'returning-user' : "new-user-$n", 'status' => 'finalist']);
            DB::table('race_results')->insert(['id' => "result-$n", 'tabulation_id' => 'count', 'candidacy_id' => "candidate-$n", 'seat_no' => $n, 'vote_share_norm' => '1.2']);
        }
    }
}
