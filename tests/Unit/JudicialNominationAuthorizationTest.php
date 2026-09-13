<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Http\Controllers\Judiciary\JudicialNominationController;
use App\Http\Controllers\Judiciary\JudiciaryController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Appointment;
use App\Models\AuditEntry;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ChamberVoteTally;
use App\Models\Clock;
use App\Models\ClockTimer;
use App\Models\Committee;
use App\Models\CommitteeSeat;
use App\Models\InstanceSettings;
use App\Models\JudicialNomination;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jurisdiction;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\PublicRecord;
use App\Models\SocialProfile;
use App\Models\Term;
use App\Models\User;
use App\Models\VoteCast;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\CivilAppointmentService;
use App\Services\ClockService;
use App\Services\ConstitutionalValidator;
use App\Services\Education\TrainingGateService;
use App\Services\EnactmentService;
use App\Services\Judiciary\JudicialNominationService;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\Legislature\ChamberActService;
use App\Services\Legislature\CommitteeService;
use App\Services\Legislature\ElectionBoardTransitionService;
use App\Services\Legislature\EloquentCommitteeRoster;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Services\VoteCountingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual court controller, nomination service, confirmation forms and civil terms. Private SQLite only. */
final class JudicialNominationAuthorizationTest extends TestCase
{
    private string $original;

    private ConstitutionalEngine $engine;

    private JudicialSeatService $seats;

    private ChamberActService $acts;

    private Judiciary $court;

    private array $settingOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.judicial_nomination_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('judicial_nomination_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach ([ChamberVoteProposal::class, Committee::class, CommitteeSeat::class, Appointment::class, ChamberVote::class, ChamberVoteTally::class, Clock::class, ClockTimer::class,
            InstanceSettings::class, JudicialNomination::class, JudicialSeat::class, Judiciary::class, Jurisdiction::class,
            Law::class, Legislature::class, LegislatureMember::class, PublicRecord::class, SocialProfile::class, Term::class, User::class, VoteCast::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                if ($model instanceof PublicRecord) {
                    $t->bigIncrements('seq');
                }
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    if ($column === 'id') {
                        $t->string('id')->unique();
                    } elseif ($column === 'is_tiebreak') {
                        $t->boolean($column)->default(false);
                    } elseif ($model instanceof ChamberVoteTally && in_array($column, ['yes', 'no', 'abstain', 'present'], true)) {
                        $t->integer($column)->default(0);
                    } else {
                        $t->text($column)->nullable();
                    }
                }
            });
        }
        DB::connection()->getSchemaBuilder()->create('residency_confirmations', function (Blueprint $t) {
            $t->string('user_id');
            $t->string('jurisdiction_id');
            $t->boolean('is_active');
        });
        InstanceSettings::create(['instance_name' => 'Private judicial fixture', 'instance_class' => 'production']);
        Clock::create(['id' => 'CLK-09', 'name' => 'Civil expiry', 'type' => 'countdown']);
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'name' => 'Court place']);
        DB::table('jurisdictions')->insert(['id' => $this->id(2), 'name' => 'Constituent place', 'parent_id' => $this->id(1)]);
        // A different chamber in the same jurisdiction must never receive this court's consent.
        Legislature::create(['id' => $this->id(3), 'jurisdiction_id' => $this->id(1), 'status' => 'active', 'total_seats' => 3, 'type_a_seats' => 3, 'type_b_seats' => 0]);
        Legislature::create(['id' => $this->id(4), 'jurisdiction_id' => $this->id(1), 'status' => 'active', 'total_seats' => 3, 'type_a_seats' => 3, 'type_b_seats' => 0]);
        $this->court = Judiciary::create(['id' => $this->id(5), 'jurisdiction_id' => $this->id(1), 'source_legislature_id' => $this->id(4),
            'court_name' => 'Civic court', 'type' => 'appointed', 'status' => 'appointed', 'nomination_mode' => 'constituent', 'judge_count' => 5, 'term_years' => 7]);
        JudicialSeat::create(['id' => $this->id(6), 'judiciary_id' => $this->court->id, 'seat_number' => 1,
            'seat_class' => 'constituent_nominated', 'nominating_jurisdiction_id' => $this->id(2), 'status' => 'vacant']);
        foreach ([10, 11, 12, 13, 14] as $n) {
            (new User)->forceFill(['id' => $this->id($n), 'name' => 'Secret '.$n, 'display_name' => $n === 14 ? null : 'Civic '.$n])->save();
        }
        foreach ([10, 11, 12, 13] as $n) {
            LegislatureMember::create(['id' => $this->id(100 + $n), 'legislature_id' => $this->id($n === 13 ? 3 : 4), 'user_id' => $this->id($n), 'status' => 'seated', 'seat_type' => 'a']);
        }
        SocialProfile::create(['id' => $this->id(30), 'user_id' => $this->id(14), 'visibility' => 'private', 'handle' => 'private_handle', 'display_name' => 'Private name']);
        DB::table('residency_confirmations')->insert(['user_id' => $this->id(14), 'jurisdiction_id' => $this->id(1), 'is_active' => true]);
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(fn ($jurisdiction, $key, $fallback) => $this->settingOverrides[$key] ?? ($key === 'judicial_appointment_years' ? 7 : $fallback));
        $roles = $this->createMock(RoleService::class);
        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-01', 'R-09', 'R-10', 'R-11']);
        $records = new PublicRecordService($audit);
        $votes = new ChamberVoteService($audit, $settings, $records, new EloquentCommitteeRoster, new VoteCountingService);
        $clocks = new ClockService($audit, $settings);
        $this->seats = new JudicialSeatService($votes, new CivilAppointmentService($clocks), $records, $audit, $settings, $clocks, $roles);
        $this->acts = new ChamberActService($votes, $this->createMock(EnactmentService::class), $records, $this->createMock(CommitteeService::class),
            $this->createMock(ElectionBoardTransitionService::class), $settings, $clocks, $roles);
        foreach ([ChamberVoteService::class => $votes, JudicialSeatService::class => $this->seats, ChamberActService::class => $this->acts,
            AuditService::class => $audit, PublicRecordService::class => $records, RoleService::class => $roles, SettingsResolver::class => $settings,
            ClockService::class => $clocks] as $class => $instance) {
            $this->app->instance($class, $instance);
        }
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
        $this->app->instance(CommitteeRoster::class, new EloquentCommitteeRoster);
        $this->travelTo(now()->setDate(2026, 9, 13)->setTime(12, 0));
        foreach ([15, 16] as $n) {
            (new User)->forceFill(['id' => $this->id($n), 'name' => 'Secret '.$n, 'display_name' => 'Civic '.$n])->save();
            LegislatureMember::create(['id' => $this->id(100 + $n), 'legislature_id' => $this->id(4), 'user_id' => $this->id($n), 'status' => 'seated', 'seat_type' => 'a']);
        }
        Legislature::whereKey($this->id(4))->update(['total_seats' => 5, 'type_a_seats' => 5]);
        // Fixture's second legislature is the direct constituent's current chamber.
        Legislature::whereKey($this->id(3))->update(['jurisdiction_id' => $this->id(2)]);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        DB::purge('judicial_nomination_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string
    {
        return sprintf('81000000-0000-4000-8000-%012d', $n);
    }

    private function nomination(): array
    {
        return DB::transaction(fn () => $this->seats->nominate(JudicialSeat::findOrFail($this->id(6)), $this->id(14), $this->id(2), null, 'Public dossier'));
    }

    private function read(?int $viewer = 10, ?string $url = null): array
    {
        $r = Request::create($url ?? '/judiciaries/'.$this->court->id);
        $r->setUserResolver(fn () => $viewer === null ? null : User::findOrFail($this->id($viewer)));
        $r->headers->set('X-Inertia', 'true');
        $r->headers->set('X-Inertia-Partial-Component', 'Judiciary/Home');
        $r->headers->set('X-Inertia-Partial-Data', 'nominationContext,vacantSeats,judicialCommittees,judicialProposals,judicialNominees');

        return (new JudiciaryController(new ChamberVotePresenter))->show($r, $this->court->fresh())->toResponse($r)->getData(true)['props'];
    }

    private function cast(string $vote, int $actor, string $value, bool $tie = false): void
    {
        $this->engine->file($tie ? 'F-SPK-004' : (ChamberVote::findOrFail($vote)->body_type === 'committee' ? 'F-LEG-005' : 'F-LEG-004'), User::findOrFail($this->id($actor)), ['vote_id' => $vote,
            'jurisdiction_id' => $this->id(1), 'value' => $value, 'explanation' => 'Public reasons']);
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            self::fail('Expected constitutional refusal.');
        } catch (ConstitutionalViolation $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    private function propose(int $actor = 13, array $override = []): ChamberVoteProposal
    {
        $out = $this->engine->file('F-LEG-037', User::findOrFail($this->id($actor)), $override + [
            'judiciary_id' => $this->court->id, 'legislature_id' => $this->id(3), 'jurisdiction_id' => $this->id(2),
            'seat_id' => $this->id(6), 'nominee_user_id' => $this->id(14), 'statement' => 'Public reasons']);

        return ChamberVoteProposal::findOrFail($out->recorded['proposal_id']);
    }

    private function committee(): Committee
    {
        $this->court->update(['nomination_mode' => 'committee']);
        JudicialSeat::whereKey($this->id(6))->update(['seat_class' => 'committee_nominated', 'nominating_jurisdiction_id' => null]);
        $committee = Committee::create(['id' => $this->id(50), 'legislature_id' => $this->id(4), 'name' => 'Existing review committee', 'status' => 'seated', 'chair_member_id' => $this->id(110)]);
        foreach ([10, 11, 12, 15, 16] as $n) {
            CommitteeSeat::create(['committee_id' => $committee->id, 'member_id' => $this->id(100 + $n), 'seat_kind' => 'type_a', 'status' => 'seated']);
        }

        return $committee;
    }

    private function designate(?Committee $committee = null): ChamberVoteProposal
    {
        $committee ??= $this->committee();
        $out = $this->engine->file('F-LEG-038', User::findOrFail($this->id(10)), ['judiciary_id' => $this->court->id,
            'legislature_id' => $this->id(4), 'jurisdiction_id' => $this->id(1), 'committee_id' => $committee->id, 'statement' => 'Assign judicial nominations to this committee.']);

        return ChamberVoteProposal::findOrFail($out->recorded['proposal_id']);
    }

    private function sourceVotes(string $vote, int $yes = 5): void
    {
        foreach ([10, 11, 12, 15, 16] as $i => $n) {
            $this->cast($vote, $n, $i < $yes ? 'yes' : 'no');
        }
    }

    public function test_player_controller_authorization_then_separate_confirmation_seats_once(): void
    {
        $request = Request::create('/judiciaries/'.$this->court->id.'/nomination-proposals', 'POST', [
            'legislature_id' => $this->id(3), 'seat_id' => $this->id(6), 'nominee_user_id' => $this->id(14), 'statement' => 'Public reasons']);
        $request->setUserResolver(fn () => User::findOrFail($this->id(13)));
        self::assertStringEndsWith('#judicial-proposals', (new JudicialNominationController($this->engine))->nominate($request, $this->court)->getTargetUrl());
        $p = ChamberVoteProposal::firstOrFail();
        $vote = ChamberVote::findOrFail($p->vote_id);
        self::assertSame($this->id(3), $vote->body_id);
        self::assertSame('majority', $vote->threshold_basis);
        self::assertSame(0, Appointment::count());
        self::assertTrue($this->read(13)['judicialProposals']['rows'][0]['vote']['can_cast']);
        self::assertFalse($this->read(10)['judicialProposals']['rows'][0]['vote']['can_cast']);
        $this->refused(fn () => $this->cast($p->vote_id, 10, 'yes'));
        $this->cast($p->vote_id, 13, 'yes');
        self::assertSame('adopted', $p->refresh()->status);
        self::assertSame('nominated', JudicialSeat::findOrFail($this->id(6))->status);
        $consent = ChamberVote::findOrFail(Appointment::firstOrFail()->consent_vote_id);
        self::assertNotSame($p->vote_id, $consent->id);
        self::assertSame($this->id(4), $consent->body_id);
        self::assertSame(3, $consent->tallies()->firstOrFail()->required_yes);
        $this->sourceVotes($consent->id, 3);
        self::assertSame('seated', JudicialSeat::findOrFail($this->id(6))->status);
        self::assertSame(1, Term::count());
        self::assertSame('2033-09-13', Term::firstOrFail()->ends_on->toDateString());
        $this->acts->resolveProposalVote($vote->refresh(), 'adopted');
        self::assertSame(1, Appointment::count());
        self::assertSame(1, PublicRecord::where('via_form', 'F-LEG-037')->count());
    }

    public function test_authorization_requires_a_majority_of_all_serving_and_leaves_rejected_seat_vacant(): void
    {
        foreach ([10, 11, 12, 15] as $n) {
            LegislatureMember::whereKey($this->id(100 + $n))->update(['legislature_id' => $this->id(3)]);
        }
        $p = $this->propose();
        $vote = ChamberVote::findOrFail($p->vote_id);
        self::assertSame(5, $vote->serving_snapshot);
        self::assertSame(3, $vote->tallies()->firstOrFail()->required_yes);
        $this->cast($vote->id, 13, 'yes');
        $this->cast($vote->id, 10, 'yes');
        DB::transaction(fn () => app(ChamberVoteService::class)->close($vote));
        self::assertSame('rejected', $p->refresh()->status);
        self::assertSame(0, Appointment::count());
        self::assertSame('vacant', JudicialSeat::findOrFail($this->id(6))->status);
        $second = $this->propose();
        foreach ([13, 10, 11, 12, 15] as $i => $n) {
            $this->cast($second->vote_id, $n, $i < 3 ? 'yes' : 'no');
        }
        self::assertSame('adopted', $second->refresh()->status);
        self::assertSame(1, Appointment::count());
    }

    public function test_designation_requires_recorded_supermajority_and_chair_and_ordinary_member_can_propose(): void
    {
        $committee = $this->committee();
        $this->refused(fn () => $this->propose(10, ['legislature_id' => $this->id(4)]));
        $designation = $this->designate($committee);
        self::assertSame(4, ChamberVote::findOrFail($designation->vote_id)->tallies()->firstOrFail()->required_yes);
        $this->sourceVotes($designation->vote_id, 3);
        self::assertNull($this->court->fresh()->judicial_committee_id);
        $designation = $this->designate($committee);
        $this->sourceVotes($designation->vote_id, 4);
        self::assertSame($committee->id, $this->court->fresh()->judicial_committee_id);
        self::assertSame($designation->vote_id, $this->court->fresh()->judicial_committee_vote_id);
        self::assertSame(1, PublicRecord::where('via_form', 'F-LEG-038')->count());
        self::assertTrue($this->read(10)['vacantSeats']['rows'][0]['can_propose']);
        $chair = $this->propose(10, ['legislature_id' => $this->id(4)]);
        $ordinary = $this->propose(11, ['legislature_id' => $this->id(4)]);
        $vote = ChamberVote::findOrFail($chair->vote_id);
        self::assertSame('committee', $vote->body_type);
        self::assertSame($committee->id, $vote->body_id);
        self::assertSame('supermajority', $vote->threshold_basis);
        self::assertSame(4, $vote->tallies()->firstOrFail()->required_yes);
        $this->sourceVotes($chair->vote_id, 4);
        self::assertSame('adopted', $chair->refresh()->status);
        self::assertSame('nominated', JudicialSeat::findOrFail($this->id(6))->status);
        $this->sourceVotes(Appointment::firstOrFail()->consent_vote_id, 3);
        self::assertSame('seated', JudicialSeat::findOrFail($this->id(6))->status);
        self::assertSame(1, Term::count());
        self::assertNotNull(collect($this->read(11)['judicialProposals']['rows'])->firstWhere('id', $ordinary->id)['reason']);
    }

    public function test_configured_supermajority_and_bicameral_lanes_are_used(): void
    {
        $committee = $this->committee();
        foreach ([17, 18] as $n) {
            (new User)->forceFill(['id' => $this->id($n), 'display_name' => 'Civic '.$n])->save();
            LegislatureMember::create(['id' => $this->id(100 + $n), 'legislature_id' => $this->id(4), 'user_id' => $this->id($n), 'status' => 'seated', 'seat_type' => 'a']);
            CommitteeSeat::create(['committee_id' => $committee->id, 'member_id' => $this->id(100 + $n), 'seat_kind' => 'type_a']);
        }
        $this->settingOverrides = ['supermajority_numerator' => 3, 'supermajority_denominator' => 4];
        $d = $this->designate($committee);
        self::assertSame(6, ChamberVote::findOrFail($d->vote_id)->tallies()->firstOrFail()->required_yes);
        foreach ([10, 11, 12, 15, 16, 17, 18] as $n) {
            $this->cast($d->vote_id, $n, 'yes');
        }
        $p = $this->propose(10, ['legislature_id' => $this->id(4)]);
        self::assertSame(6, ChamberVote::findOrFail($p->vote_id)->tallies()->firstOrFail()->required_yes);
        foreach ([10, 11, 12, 15, 16, 17, 18] as $i => $n) {
            $this->cast($p->vote_id, $n, $i < 5 ? 'yes' : 'no');
        }
        self::assertSame('rejected', $p->refresh()->status);
        self::assertSame(0, Appointment::count());
        Legislature::whereKey($this->id(4))->update(['type_b_seats' => 3]);
        CommitteeSeat::where('committee_id', $committee->id)->whereIn('member_id', [$this->id(116), $this->id(117), $this->id(118)])->update(['seat_kind' => 'type_b']);
        $p = $this->propose(10, ['legislature_id' => $this->id(4)]);
        $vote = ChamberVote::findOrFail($p->vote_id);
        self::assertTrue($vote->bicameral);
        self::assertSame(['type_a', 'type_b'], $vote->tallies()->orderBy('lane')->pluck('lane')->all());
    }

    public function test_wrong_constituent_former_member_and_ineligible_nominee_are_refused_without_writes(): void
    {
        $this->refused(fn () => $this->propose(10));
        $this->refused(fn () => $this->propose(10, ['legislature_id' => $this->id(4)]));
        $this->refused(fn () => $this->propose(13, ['nominee_user_id' => $this->id(10)]));
        LegislatureMember::whereKey($this->id(113))->update(['status' => 'expired']);
        $this->refused(fn () => $this->propose());
        self::assertSame(0, ChamberVoteProposal::count());
        self::assertSame(0, ChamberVote::count());
        self::assertSame(0, Appointment::count());
    }

    public function test_no_actor_bypass_and_no_authority_from_former_committee_seat_or_foreign_chamber(): void
    {
        $committee = $this->committee();
        $d = $this->designate($committee);
        $this->sourceVotes($d->vote_id);
        CommitteeSeat::where('committee_id', $committee->id)->where('member_id', $this->id(110))->update(['vacated_at' => now()]);
        $this->refused(fn () => $this->propose(10, ['legislature_id' => $this->id(4)]));
        $this->refused(fn () => app(JudicialNominationService::class)->propose(null, ['judiciary_id' => $this->court->id, 'legislature_id' => $this->id(4),
            'seat_id' => $this->id(6), 'nominee_user_id' => $this->id(14), 'statement' => 'No bypass', 'system_act' => true]));
        self::assertFalse($this->read(10)['vacantSeats']['rows'][0]['can_propose']);
        $this->refused(fn () => $this->engine->file('F-LEG-038', User::findOrFail($this->id(13)), ['judiciary_id' => $this->court->id,
            'legislature_id' => $this->id(3), 'committee_id' => $committee->id, 'statement' => 'Wrong legislature']));
    }

    public function test_reassigned_committee_cannot_finish_old_nomination_and_rolls_back_final_cast(): void
    {
        $d = $this->designate();
        $this->sourceVotes($d->vote_id);
        $p = $this->propose(10, ['legislature_id' => $this->id(4)]);
        foreach ([10, 11, 12, 15] as $n) {
            $this->cast($p->vote_id, $n, 'yes');
        }
        $other = Committee::create(['id' => $this->id(51), 'legislature_id' => $this->id(4), 'name' => 'Replacement', 'status' => 'created']);
        $replacement = $this->designate($other);
        $this->sourceVotes($replacement->vote_id);
        $this->refused(fn () => $this->cast($p->vote_id, 16, 'yes'));
        self::assertSame(4, VoteCast::where('vote_id', $p->vote_id)->count());
        self::assertSame('open', ChamberVote::findOrFail($p->vote_id)->status);
        self::assertSame(0, Appointment::count());
    }

    public function test_old_proposal_cannot_revive_after_confirmation_rejects_another_nominee(): void
    {
        $old = $this->propose();
        $new = $this->propose(13, ['statement' => 'Different proposal']);
        $this->cast($new->vote_id, 13, 'yes');
        $this->sourceVotes(Appointment::firstOrFail()->consent_vote_id, 0);
        self::assertSame('vacant', JudicialSeat::findOrFail($this->id(6))->status);
        $this->refused(fn () => $this->cast($old->vote_id, 13, 'yes'));
        self::assertSame(0, VoteCast::where('vote_id', $old->vote_id)->count());
        $replacement = $this->propose();
        $this->cast($replacement->vote_id, 13, 'yes');
        self::assertSame(2, Appointment::count());
    }

    public static function invalidVotes(): array
    {
        return [['body_id', 'other'], ['votable_id', 'other'], ['jurisdiction_id', 'other'], ['vote_type', 'procedural_motion'],
            ['threshold_basis', 'supermajority'], ['stage', 'committee'], ['vote_method', 'rcv']];
    }

    #[DataProvider('invalidVotes')]
    public function test_unrelated_vote_cannot_authorize_nomination(string $field, string $value): void
    {
        $p = $this->propose();
        $vote = ChamberVote::findOrFail($p->vote_id);
        $vote->forceFill(['status' => 'closed', 'outcome' => 'adopted', $field => $value === 'other' ? $this->id(999) : $value])->save();
        $this->refused(fn () => DB::transaction(fn () => app(JudicialNominationService::class)->adopt($p, $vote)));
        self::assertSame('open', $p->refresh()->status);
        self::assertSame(0, Appointment::count());
    }

    public function test_public_directories_seek_all_records_without_counts_and_keep_one_private_safe_profile(): void
    {
        $p = $this->propose();
        for ($i = 0; $i < 42; $i++) {
            $copy = $p->replicate();
            $copy->id = $this->id(1000 + $i);
            $copy->save();
        }
        DB::connection()->enableQueryLog();
        $first = $this->read(null);
        $second = $this->read(null, $first['judicialProposals']['pages']['next']);
        $third = $this->read(null, $second['judicialProposals']['pages']['next']);
        $queries = DB::getQueryLog();
        DB::connection()->disableQueryLog();
        self::assertCount(20, $first['judicialProposals']['rows']);
        self::assertCount(20, $second['judicialProposals']['rows']);
        self::assertCount(3, $third['judicialProposals']['rows']);
        self::assertNull($third['judicialProposals']['pages']['next']);
        $rows = array_merge($first['judicialProposals']['rows'], $second['judicialProposals']['rows'], $third['judicialProposals']['rows']);
        self::assertCount(43, array_unique(array_column($rows, 'id')));
        self::assertStringStartsWith('Resident-', $rows[0]['nominee']['name']);
        self::assertNull($rows[0]['nominee']['public_handle']);
        self::assertNull($rows[0]['vote']); // copied historical row cannot borrow another proposal's vote
        self::assertFalse(collect($rows)->firstWhere('id', $p->id)['vote']['can_cast']);
        self::assertFalse($first['vacantSeats']['rows'][0]['can_propose']);
        self::assertEmpty(array_filter($queries, fn ($q) => str_contains($q['query'], 'count(*)')));
        foreach (array_filter($queries, fn ($q) => str_contains($q['query'], 'from "chamber_vote_proposals"')) as $q) {
            self::assertStringContainsString('limit 21', $q['query']);
        }
        $search = $this->read(null, '/judiciaries/'.$this->court->id.'?nominee_by=reference&nominee_q='.$this->id(14));
        self::assertSame('/people?who='.$this->id(14), $search['judicialNominees']['candidates'][0]['profile_href']);
    }

    public function test_retried_identical_filing_reuses_one_vote_and_distinct_proposals_remain_possible(): void
    {
        $one = $this->propose();
        $retry = $this->propose();
        self::assertSame($one->id, $retry->id);
        self::assertSame(1, ChamberVote::count());
        $different = $this->propose(13, ['statement' => 'Different public reasons']);
        self::assertNotSame($one->id, $different->id);
        self::assertSame(2, ChamberVote::count());
    }

    public function test_dissolved_court_and_foreign_committee_cannot_be_used_and_stale_vote_controls_are_hidden(): void
    {
        $p = $this->propose();
        $this->court->update(['status' => 'dissolved']);
        $this->refused(fn () => $this->cast($p->vote_id, 13, 'yes'));
        self::assertSame(0, Appointment::count());
        self::assertFalse($this->read(13)['judicialProposals']['rows'][0]['vote']['can_cast']);
        $this->court->update(['status' => 'appointed']);
        ChamberVote::whereKey($p->vote_id)->update(['body_id' => $this->id(4)]);
        self::assertNull($this->read(13)['judicialProposals']['rows'][0]['vote']);
        $committee = $this->committee();
        $committee->update(['legislature_id' => $this->id(3)]);
        $this->refused(fn () => $this->designate($committee));
        self::assertNull($this->court->fresh()->judicial_committee_id);
    }

    public function test_vacancy_and_committee_pickers_page_independently_and_refuse_cross_scope_cursors(): void
    {
        $committee = $this->committee();
        $seat = JudicialSeat::findOrFail($this->id(6));
        for ($i = 0; $i < 42; $i++) {
            $copy = $seat->replicate();
            $copy->id = $this->id(2000 + $i);
            $copy->seat_number = $i + 2;
            $copy->save();
            $copy = $committee->replicate();
            $copy->id = $this->id(3000 + $i);
            $copy->save();
        }
        $workspace = app(\App\Support\JudicialNominationWorkspace::class);
        foreach (['seats' => 'seats_cursor', 'committees' => 'committees_cursor'] as $method => $key) {
            $first = $workspace->$method(Request::create('/judiciaries/'.$this->court->id), $this->court);
            $second = $workspace->$method(Request::create($first['pages']['next']), $this->court);
            $third = $workspace->$method(Request::create($second['pages']['next']), $this->court);
            self::assertSame([20, 20, 3], [count($first['rows']), count($second['rows']), count($third['rows'])]);
            self::assertSame(array_column($first['rows'], 'id'), array_column($workspace->$method(Request::create($second['pages']['previous']), $this->court)['rows'], 'id'));
            $foreign = $this->court->replicate();
            $foreign->id = $this->id(999);
            try {
                $workspace->$method(Request::create($first['pages']['next']), $foreign);
                self::fail('Foreign cursor accepted.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey($key, $e->errors());
            }
        }
    }
}
