<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Http\Controllers\Judiciary\JudiciaryController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\{Appointment, AuditEntry, ChamberVote, ChamberVoteTally, Clock, ClockTimer, InstanceSettings, JudicialNomination, JudicialSeat, Judiciary, Jurisdiction, Law, Legislature, LegislatureMember, PublicRecord, SocialProfile, Term, User, VoteCast};
use App\Services\{AuditService, ChamberVoteService, CivilAppointmentService, ClockService, ConstitutionalValidator, EnactmentService, PublicRecordService, RoleService, SettingsResolver, VoteCountingService};
use App\Services\Education\TrainingGateService;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\Legislature\{ChamberActService, CommitteeService, ElectionBoardTransitionService};
use App\Support\JudicialConfirmationDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual court controller, nomination service, confirmation forms and civil terms. Private SQLite only. */
final class JudicialConfirmationSurfaceTest extends TestCase
{
    use \Tests\Concerns\AchievementSchema;

    private string $original;
    private ConstitutionalEngine $engine;
    private JudicialSeatService $seats;
    private ChamberActService $acts;
    private Judiciary $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.judicial_confirmation_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('judicial_confirmation_fixture');
        $this->createAchievementTables(); // AC-1: the wired handlers read the ledger before they award
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach ([Appointment::class, ChamberVote::class, ChamberVoteTally::class, Clock::class, ClockTimer::class,
            InstanceSettings::class, JudicialNomination::class, JudicialSeat::class, Judiciary::class, Jurisdiction::class,
            Law::class, Legislature::class, LegislatureMember::class, PublicRecord::class, SocialProfile::class, Term::class, User::class, VoteCast::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                if ($model instanceof PublicRecord) $t->bigIncrements('seq');
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    if ($column === 'id') $t->string('id')->unique();
                    elseif ($column === 'is_tiebreak') $t->boolean($column)->default(false);
                    elseif ($model instanceof ChamberVoteTally && in_array($column, ['yes', 'no', 'abstain', 'present'], true)) $t->integer($column)->default(0);
                    else $t->text($column)->nullable();
                }
            });
        }
        DB::connection()->getSchemaBuilder()->create('residency_confirmations', function (Blueprint $t) {
            $t->string('user_id'); $t->string('jurisdiction_id'); $t->boolean('is_active');
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
        foreach ([10, 11, 12, 13, 14] as $n) (new User)->forceFill(['id' => $this->id($n), 'name' => 'Secret '.$n, 'display_name' => $n === 14 ? null : 'Civic '.$n])->save();
        foreach ([10, 11, 12, 13] as $n) LegislatureMember::create(['id' => $this->id(100 + $n), 'legislature_id' => $this->id($n === 13 ? 3 : 4), 'user_id' => $this->id($n), 'status' => 'seated', 'seat_type' => 'a']);
        SocialProfile::create(['id' => $this->id(30), 'user_id' => $this->id(14), 'visibility' => 'private', 'handle' => 'private_handle', 'display_name' => 'Private name']);
        DB::table('residency_confirmations')->insert(['user_id' => $this->id(14), 'jurisdiction_id' => $this->id(1), 'is_active' => true]);
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(fn ($jurisdiction, $key, $fallback) => $key === 'judicial_appointment_years' ? 7 : $fallback);
        $roles = $this->createMock(RoleService::class);
        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-01', 'R-09', 'R-10']);
        $records = new PublicRecordService($audit);
        $votes = new ChamberVoteService($audit, $settings, $records, $this->createMock(CommitteeRoster::class), new VoteCountingService);
        $clocks = new ClockService($audit, $settings);
        $this->seats = new JudicialSeatService($votes, new CivilAppointmentService($clocks), $records, $audit, $settings, $clocks, $roles);
        $this->acts = new ChamberActService($votes, $this->createMock(EnactmentService::class), $records, $this->createMock(CommitteeService::class),
            $this->createMock(ElectionBoardTransitionService::class), $settings, $clocks, $roles);
        foreach ([ChamberVoteService::class => $votes, JudicialSeatService::class => $this->seats, ChamberActService::class => $this->acts,
            AuditService::class => $audit, PublicRecordService::class => $records, RoleService::class => $roles, SettingsResolver::class => $settings,
            ClockService::class => $clocks] as $class => $instance) $this->app->instance($class, $instance);
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
        $this->travelTo(now()->setDate(2026, 9, 13)->setTime(12, 0));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        DB::purge('judicial_confirmation_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('81000000-0000-4000-8000-%012d', $n); }

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
        $r->headers->set('X-Inertia-Partial-Data', 'nominations,confirmationPages,confirmationContext');
        return (new JudiciaryController(new ChamberVotePresenter))->show($r, $this->court->fresh())->toResponse($r)->getData(true)['props'];
    }

    private function cast(string $vote, int $actor, string $value, bool $tie = false): void
    {
        $this->engine->file($tie ? 'F-SPK-004' : 'F-LEG-004', User::findOrFail($this->id($actor)), ['vote_id' => $vote,
            'jurisdiction_id' => $this->id(1), 'value' => $value, 'explanation' => 'Public reasons']);
    }

    private function refused(callable $action): void
    {
        try { $action(); self::fail('Expected constitutional refusal.'); }
        catch (ConstitutionalViolation $e) { self::assertNotSame('', $e->getMessage()); }
    }

    public function test_real_nomination_controller_confirmation_and_seating_preserve_exact_scope_and_public_name(): void
    {
        $out = $this->nomination();
        $p = $this->read(); $row = $p['nominations'][0];
        self::assertSame($out['nomination_id'], $row['id']);
        self::assertSame('Constituent place', $row['nominated_by']);
        self::assertStringStartsWith('Resident-', $row['nominee']['name']);
        self::assertNull($row['nominee']['public_handle']);
        self::assertSame('/people?who='.$this->id(14), $row['nominee']['profile_href']);
        self::assertSame('Public dossier', $row['dossier']);
        self::assertTrue($row['consent']['can_cast']);
        self::assertSame('/votes/'.$out['consent_vote_id'].'/cast', $row['consent']['cast_url']);
        foreach ([null, 13] as $actor) {
            self::assertFalse($this->read($actor)['nominations'][0]['consent']['can_cast']);
            self::assertTrue($this->read($actor)['confirmationContext']['preview']);
        }
        $this->refused(fn () => $this->cast($out['consent_vote_id'], 13, 'yes'));
        foreach ([10, 11, 12] as $actor) $this->cast($out['consent_vote_id'], $actor, 'yes');
        self::assertSame('seated', JudicialSeat::findOrFail($this->id(6))->status);
        self::assertSame('consented', JudicialNomination::findOrFail($out['nomination_id'])->status);
        self::assertSame('2033-09-13', $this->read()['nominations'][0]['term']['ends']);
        self::assertFalse($this->read()['nominations'][0]['consent']['can_cast']);
        self::assertSame(1, Term::count()); self::assertSame(1, ClockTimer::count());
        $this->refused(fn () => $this->cast($out['consent_vote_id'], 10, 'yes'));
        $this->refused(fn () => DB::transaction(fn () => $this->seats->seat(Appointment::findOrFail($out['appointment_id']))));
        self::assertSame(1, Term::count());
    }

    public static function tieChoices(): array { return [['yes', 'seated', 'consented'], ['no', 'vacant', 'rejected']]; }

    #[DataProvider('tieChoices')]
    public function test_actual_confirmation_tie_is_resolved_by_exact_speaker_once(string $choice, string $seatStatus, string $nominationStatus): void
    {
        Legislature::whereKey($this->id(4))->update(['speaker_id' => $this->id(112)]);
        $out = $this->nomination();
        $this->cast($out['consent_vote_id'], 10, 'yes'); $this->cast($out['consent_vote_id'], 11, 'no');
        self::assertSame('tied', ChamberVote::findOrFail($out['consent_vote_id'])->outcome);
        self::assertTrue($this->read(12)['nominations'][0]['consent']['can_tiebreak']);
        self::assertFalse($this->read(12)['nominations'][0]['consent']['can_cast']);
        self::assertFalse($this->read(13)['nominations'][0]['consent']['can_tiebreak']);
        $this->refused(fn () => $this->cast($out['consent_vote_id'], 13, $choice, true));
        $this->cast($out['consent_vote_id'], 12, $choice, true);
        self::assertSame($seatStatus, JudicialSeat::findOrFail($this->id(6))->status);
        self::assertSame($nominationStatus, JudicialNomination::findOrFail($out['nomination_id'])->status);
        self::assertSame($choice === 'yes' ? 1 : 0, Term::count());
        self::assertFalse($this->read(12)['nominations'][0]['consent']['can_tiebreak']);
        $this->refused(fn () => $this->cast($out['consent_vote_id'], 12, $choice, true));
        self::assertSame(3, VoteCast::count());
        self::assertSame(1, PublicRecord::where('via_form', 'F-SPK-004')->count());
    }

    public function test_rejection_reopens_same_constituent_seat_without_changing_allocation(): void
    {
        $out = $this->nomination();
        foreach ([10, 11, 12] as $actor) $this->cast($out['consent_vote_id'], $actor, 'no');
        $seat = JudicialSeat::findOrFail($this->id(6));
        self::assertSame('vacant', $seat->status); self::assertNull($seat->appointment_id);
        self::assertSame($this->id(2), $seat->nominating_jurisdiction_id); self::assertSame(1, JudicialSeat::count());
        $replacement = $this->nomination();
        self::assertNotSame($out['appointment_id'], $replacement['appointment_id']);
        self::assertSame($out['seat_id'], $replacement['seat_id']);
    }

    public function test_setup_slate_seating_still_runs_and_its_closed_record_is_readable_without_individual_controls(): void
    {
        DB::transaction(fn () => $this->seats->stageSlateNomination(JudicialSeat::findOrFail($this->id(6)), $this->id(14), 'constituent', $this->id(2)));
        $vote = DB::transaction(fn () => $this->seats->openSlateConsent($this->court));
        foreach ([10, 11, 12] as $actor) $this->cast($vote->id, $actor, 'yes');
        self::assertSame('adopted', $vote->refresh()->outcome);
        self::assertSame(1, $this->seats->seatSlateOnAdoption($this->court));
        self::assertSame(0, $this->seats->seatSlateOnAdoption($this->court));
        $row = $this->read()['nominations'][0];
        self::assertSame('consented', $row['status']);
        self::assertSame('2033-09-13', $row['term']['ends']);
        self::assertSame($vote->id, $row['consent']['tally']['vote_id']);
        self::assertFalse($row['consent']['can_cast']); self::assertFalse($row['consent']['can_tiebreak']);
        self::assertSame('The recorded bench slate was confirmed together.', $row['consent']['read_only_reason']);
        self::assertSame(1, Term::count());
    }

    public function test_pagination_reaches_every_nomination_and_rejects_cross_court_cursor(): void
    {
        $out = $this->nomination();
        $nomination = JudicialNomination::findOrFail($out['nomination_id']);
        for ($i = 0; $i < 42; $i++) { $copy = $nomination->replicate(); $copy->id = $this->id(1000 + $i); $copy->save(); }
        DB::connection()->enableQueryLog();
        $first = $this->read(); $second = $this->read(url: $first['confirmationPages']['next']); $third = $this->read(url: $second['confirmationPages']['next']);
        $queries = DB::getQueryLog(); DB::connection()->disableQueryLog();
        $rootReads = array_filter($queries, fn ($q) => str_contains($q['query'], 'from "judicial_nominations"'));
        self::assertCount(3, $rootReads);
        foreach ($rootReads as $query) { self::assertStringContainsString('limit 21', $query['query']); self::assertContains($this->court->id, $query['bindings']); }
        self::assertEmpty(array_filter($queries, fn ($q) => str_contains($q['query'], 'count(*)')), 'A confirmation-only visit does not rebuild header counts.');
        self::assertCount(20, $first['nominations']); self::assertCount(20, $second['nominations']); self::assertCount(3, $third['nominations']);
        self::assertNull($third['confirmationPages']['next']);
        $all = array_merge(array_column($first['nominations'], 'id'), array_column($second['nominations'], 'id'), array_column($third['nominations'], 'id'));
        self::assertCount(43, array_unique($all));
        self::assertSame(array_column($first['nominations'], 'id'), array_column($this->read(url: $second['confirmationPages']['previous'])['nominations'], 'id'));
        $foreign = $this->court->replicate(); $foreign->id = $this->id(99);
        $this->expectException(ValidationException::class);
        (new JudicialConfirmationDirectory(new ChamberVotePresenter))->page(Request::create($first['confirmationPages']['next']), $foreign, null);
    }

    public static function corruptions(): array
    {
        return [
            ['vote', 'body_id', 'other'], ['vote', 'votable_id', 'other'], ['vote', 'jurisdiction_id', 'other'], ['vote', 'stage', 'committee'],
            ['vote', 'votable_type', 'judiciary'], ['appointment', 'consent_vote_id', 'other'], ['appointment', 'nominee_user_id', 'other'],
            ['seat', 'appointment_id', 'other'], ['seat', 'status', 'retired'], ['seat', 'user_id', 'other'], ['seat', 'term_id', 'other'],
            ['court', 'status', 'dissolved'], ['court', 'source_legislature_id', 'other'], ['nomination', 'status', 'withdrawn'],
        ];
    }

    public static function ordinaryChoices(): array { return [['yes'], ['no']]; }

    #[DataProvider('ordinaryChoices')]
    public function test_actual_vote_close_rolls_back_when_the_nomination_no_longer_owns_the_seat(string $choice): void
    {
        $out = $this->nomination();
        $this->cast($out['consent_vote_id'], 10, $choice);
        $this->cast($out['consent_vote_id'], 11, $choice);
        JudicialSeat::whereKey($this->id(6))->update(['appointment_id' => $this->id(999)]);
        $this->refused(fn () => $this->cast($out['consent_vote_id'], 12, $choice));
        self::assertSame($this->id(999), JudicialSeat::findOrFail($this->id(6))->appointment_id);
        self::assertSame('nominated', Appointment::findOrFail($out['appointment_id'])->status);
        self::assertSame('open', ChamberVote::findOrFail($out['consent_vote_id'])->status);
        self::assertSame(2, VoteCast::count()); self::assertSame(0, Term::count());
    }

    #[DataProvider('corruptions')]
    public function test_stale_or_malformed_confirmation_cannot_change_a_seat(string $kind, string $column, string $value): void
    {
        $out = $this->nomination();
        $model = match ($kind) { 'vote' => ChamberVote::findOrFail($out['consent_vote_id']), 'appointment' => Appointment::findOrFail($out['appointment_id']),
            'seat' => JudicialSeat::findOrFail($out['seat_id']), 'court' => $this->court, 'nomination' => JudicialNomination::findOrFail($out['nomination_id']) };
        $model->forceFill([$column => $value === 'other' ? $this->id(999) : $value])->save();
        $row = $this->read()['nominations'][0];
        self::assertFalse($row['consent']['can_cast'] ?? false);
        $vote = ChamberVote::findOrFail($out['consent_vote_id']);
        $vote->forceFill(['status' => 'closed', 'outcome' => 'adopted'])->save();
        $this->refused(fn () => DB::transaction(fn () => $this->seats->assertConsentVote(Appointment::findOrFail($out['appointment_id']), $vote, 'adopted')));
        self::assertSame(0, Term::count());
    }
}
