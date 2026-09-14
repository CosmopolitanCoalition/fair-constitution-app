<?php

namespace Tests\Unit;

use App\Models\AuditEntry;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\Judiciary;
use App\Models\JudicialSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Term;
use App\Services\AuditService;
use App\Services\CertificationService;
use App\Services\ElectionCertificationReconciliationService;
use App\Services\ElectionLifecycleService;
use App\Services\Executive\ExecutiveFormationService;
use App\Services\ReferendumService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * S1 · rollover — Second term and recurring elected offices.
 *
 * Journey builder for the demo internal review register row "S1 · rollover".
 * Named private SQLite only; no world writes, no live-PG helpers. Drives the
 * REAL CertificationService and ElectionCertificationReconciliationService
 * against a hand-built schema, with only the collaborators that drag in
 * unrelated table graphs doubled (AuditService, SettingsResolver,
 * ElectionLifecycleService, ReferendumService, and the four ctor deps of
 * ExecutiveFormationService that closeDelegatedMembersOnTurnover never uses).
 * ClockService, RoleService and ExecutiveFormationService's own logic run
 * real, so seat/term state is written by the code under review.
 *
 * Pass criteria (verbatim from the register):
 *  - departed officers lose old authority; retained people get correct
 *    successor records;
 *  - Speaker, committees and delegated executives transition correctly;
 *  - Elected executive/court cycles recur and expired seats do not retain
 *    authority;
 *  - Corrected same-election counts preserve the original term/cycle and
 *    reconcile only affected seats.
 */
class SecondTermRolloverJourneyTest extends TestCase
{
    private string $original;

    /** @var \Mockery\MockInterface|ElectionLifecycleService */
    private $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake(); // EvaluateSocialStructureJob (advanceLegislatureTerm) and any RunCountbackJob stay inert.

        $this->original = DB::getDefaultConnection();
        config(['database.connections.rollover_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('rollover_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->buildSchema();
        $this->seedClocks();
        $this->bindDoubles();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->original);
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Criterion 1 & 2 — general certification rolls the chamber and offices
    // =========================================================================

    public function test_general_certification_rolls_over_chamber_and_offices(): void
    {
        $jur = $this->uuid();
        $leg = $this->uuid();
        $general1 = $this->uuid();
        $general2 = $this->uuid();
        $race1 = $this->uuid();
        $race2 = $this->uuid();

        $t0 = '2020-01-01';
        $t0End = '2024-01-01';

        $this->seedLegislature($leg, $jur, 1, $t0, $t0End);
        $this->makeElection($general1, $jur, $leg, Election::KIND_GENERAL, Election::STATUS_CERTIFIED, ['certified_at' => $t0]);

        // First-term chamber: A(speaker) B(chair) C(delegated exec) D(committee) E(retained).
        $users = ['A' => $this->uuid(), 'B' => $this->uuid(), 'C' => $this->uuid(), 'D' => $this->uuid(), 'E' => $this->uuid()];
        $members = [];
        $terms = [];
        $seatNo = 0;
        foreach ($users as $k => $uid) {
            $this->makeUser($uid);
            $mid = $this->uuid();
            $tid = $this->uuid();
            $members[$k] = $mid;
            $terms[$k] = $tid;
            $this->seatLegislator($mid, $leg, $uid, ++$seatNo, $race1, $general1, $tid, $k === 'A');
            $this->openLockstepTerm($tid, $mid, $uid, $jur, $leg, $general1, $t0, $t0End);
            $this->armClk10($tid, $jur, $t0End);
        }
        DB::table('legislatures')->where('id', $leg)->update(['speaker_id' => $members['A']]);

        // Committee: chair = B, committee member = D.
        $committee = $this->uuid();
        DB::table('committees')->insert($this->row([
            'id' => $committee, 'legislature_id' => $leg, 'name' => 'Rules', 'seats' => 3,
            'chair_member_id' => $members['B'], 'status' => 'seated',
        ]));
        DB::table('committee_seats')->insert($this->row([
            'id' => $this->uuid(), 'committee_id' => $committee, 'member_id' => $members['B'], 'status' => 'seated', 'seated_at' => now(),
        ]));
        DB::table('committee_seats')->insert($this->row([
            'id' => $this->uuid(), 'committee_id' => $committee, 'member_id' => $members['D'], 'status' => 'seated', 'seated_at' => now(),
        ]));

        // Delegated executive: C holds a delegated_proportional ex-officio seat.
        $exec = $this->uuid();
        DB::table('executives')->insert($this->row([
            'id' => $exec, 'jurisdiction_id' => $jur, 'type' => 'committee', 'term_number' => 1,
            'status' => Executive::STATUS_DELEGATED, 'source_legislature_id' => $leg,
        ]));
        $cExecMember = $this->uuid();
        DB::table('executive_members')->insert($this->row([
            'id' => $cExecMember, 'executive_id' => $exec, 'user_id' => $users['C'], 'role' => 'principal', 'rank' => 0,
            'joined_at' => $t0, 'legislature_member_id' => $members['C'],
            'selection' => ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL, 'status' => ExecutiveMember::STATUS_SEATED,
        ]));

        // An open speaker ballot from the outgoing chamber must be voided by turnover.
        $openVote = $this->uuid();
        DB::table('chamber_votes')->insert($this->row([
            'id' => $openVote, 'body_type' => 'legislature', 'body_id' => $leg, 'legislature_id' => $leg,
            'vote_type' => 'speaker_elect', 'status' => 'open',
        ]));

        // A standing ELECTED executive (P,Q) and ELECTED court (R,S) anchored to
        // this legislature. The EO-2 defect (ELECTION_OFFICE_CHECKS.md:47) was
        // that a general chamber rollover completed the lockstep terms but left
        // these elected seat rows seated, so authority persisted. The repaired
        // turnOverChamber retires them through retireElectedTermSeat.
        $electedExec = $this->uuid();
        DB::table('executives')->insert($this->row([
            'id' => $electedExec, 'jurisdiction_id' => $jur, 'type' => 'committee', 'term_number' => 1,
            'status' => Executive::STATUS_ELECTED, 'converted_at' => $t0, 'source_legislature_id' => $leg,
        ]));
        $electedExecElection = $this->uuid();
        $this->makeElection($electedExecElection, $jur, $leg, Election::KIND_EXECUTIVE, Election::STATUS_CERTIFIED, ['executive_id' => $electedExec, 'certified_at' => $t0]);
        $standingExec = [];
        foreach (['P' => $this->uuid(), 'Q' => $this->uuid()] as $label => $uid) {
            $this->makeUser($uid);
            $mid = $this->uuid();
            $tid = $this->uuid();
            $standingExec[$label] = ['member' => $mid, 'user' => $uid];
            DB::table('executive_members')->insert($this->row([
                'id' => $mid, 'executive_id' => $electedExec, 'user_id' => $uid, 'role' => 'principal', 'rank' => 0,
                'joined_at' => $t0, 'term_id' => $tid, 'selection' => ExecutiveMember::SELECTION_ELECTED_STV, 'status' => ExecutiveMember::STATUS_SEATED,
            ]));
            $this->openExecTerm($tid, $mid, $uid, $jur, $leg, $electedExecElection, $t0, $t0End);
            $this->armClk10($tid, $jur, $t0End);
        }
        $electedCourt = $this->uuid();
        DB::table('judiciaries')->insert($this->row([
            'id' => $electedCourt, 'jurisdiction_id' => $jur, 'court_name' => 'Court', 'type' => Judiciary::TYPE_ELECTED,
            'min_judges' => 5, 'term_years' => 4, 'status' => Judiciary::STATUS_ELECTED, 'converted_at' => $t0, 'judge_count' => 2, 'source_legislature_id' => $leg,
        ]));
        $electedCourtElection = $this->uuid();
        $this->makeElection($electedCourtElection, $jur, $leg, Election::KIND_JUDICIAL, Election::STATUS_CERTIFIED, ['judiciary_id' => $electedCourt, 'certified_at' => $t0]);
        $standingCourt = [];
        $jn = 0;
        foreach (['R' => $this->uuid(), 'S' => $this->uuid()] as $label => $uid) {
            $this->makeUser($uid);
            $sid = $this->uuid();
            $tid = $this->uuid();
            $standingCourt[$label] = ['seat' => $sid, 'user' => $uid];
            DB::table('judicial_seats')->insert($this->row([
                'id' => $sid, 'judiciary_id' => $electedCourt, 'user_id' => $uid, 'seat_number' => ++$jn, 'seat_class' => JudicialSeat::CLASS_ELECTED,
                'term_id' => $tid, 'term_starts_on' => $t0, 'term_ends_on' => $t0End, 'status' => JudicialSeat::STATUS_SEATED,
            ]));
            $this->openJudicialTerm($tid, $sid, $uid, $jur, $leg, $electedCourtElection, $t0, $t0End);
            $this->armClk10($tid, $jur, $t0End);
        }

        // Second general election: winners E,F,G,H,I on a 5-seat type_a race.
        $incoming = ['E' => $users['E'], 'F' => $this->uuid(), 'G' => $this->uuid(), 'H' => $this->uuid(), 'I' => $this->uuid()];
        foreach ($incoming as $k => $uid) {
            if ($k !== 'E') {
                $this->makeUser($uid);
            }
            $this->seedResidency($uid, $jur);
        }
        $this->makeElection($general2, $jur, $leg, Election::KIND_GENERAL, Election::STATUS_TABULATING, []);
        $this->makeRace($race2, $general2, $jur, 'type_a', 5);
        $tab2 = $this->sealTabulation($race2);
        $sn = 0;
        foreach ($incoming as $uid) {
            $cand = $this->uuid();
            DB::table('candidacies')->insert($this->row([
                'id' => $cand, 'election_id' => $general2, 'race_id' => $race2, 'user_id' => $uid, 'status' => 'finalist',
            ]));
            DB::table('race_results')->insert($this->row([
                'id' => $this->uuid(), 'tabulation_id' => $tab2, 'candidacy_id' => $cand, 'seat_no' => ++$sn, 'vote_share_norm' => '0.2000',
            ], false));
        }

        // ACT — run the real general certification.
        $cert = ElectionCertification::create($this->row([
            'id' => $this->uuid(), 'election_id' => $general2, 'certified_at' => $t0End, 'count_record_hash' => hash('sha256', 'g2'), 'status' => 'certified',
        ], false));
        $this->lifecycle->shouldReceive('armNextGeneralElection')->andReturn(new \App\Models\ClockTimer());
        $this->lifecycle->shouldReceive('openSuccessor')->andReturnUsing(function (Election $e) use ($jur, $leg) {
            return Election::create($this->row([
                'id' => $this->uuid(), 'jurisdiction_id' => $jur, 'legislature_id' => $leg,
                'kind' => Election::KIND_GENERAL, 'status' => Election::STATUS_APPROVAL_OPEN, 'prior_election_id' => $e->id,
            ], false));
        });

        $election = Election::findOrFail($general2);
        $result = app(CertificationService::class)->certify($election, $cert);

        // --- departed officers lose old authority (member rows + terms) ---
        foreach (['A', 'B', 'C', 'D'] as $k) {
            $old = LegislatureMember::findOrFail($members[$k]);
            self::assertSame(LegislatureMember::STATUS_TERM_ENDED, $old->status, "outgoing {$k} member must be term_ended");
            self::assertSame(Term::STATUS_COMPLETED, Term::findOrFail($terms[$k])->status, "outgoing {$k} term must be completed");
        }

        // A: speaker pointer cleared; no R-09/R-10.
        $legRow = Legislature::findOrFail($leg);
        self::assertNull($legRow->speaker_id, 'speaker pointer cleared on turnover');
        self::assertFalse((bool) LegislatureMember::findOrFail($members['A'])->is_speaker, 'is_speaker flag cleared');
        self::assertFalse($this->roleQuery('hasCurrentLegislatureSeat', $users['A']), 'A loses R-09');
        self::assertFalse($this->roleQuery('isSpeaker', $users['A']), 'A loses R-10 (speaker)');

        // B: committee reset, chair pointer null, seats vacated; no R-11/R-12.
        $com = DB::table('committees')->where('id', $committee)->first();
        self::assertSame('created', $com->status, 'committee returns to created for re-organization');
        self::assertNull($com->chair_member_id, 'committee chair pointer cleared');
        $bSeat = DB::table('committee_seats')->where('committee_id', $committee)->where('member_id', $members['B'])->first();
        self::assertSame('vacated', $bSeat->status);
        self::assertSame('chamber_turnover', $bSeat->vacated_reason);
        self::assertFalse($this->roleQuery('hasCommitteeSeat', $users['B']), 'B loses R-11 (committee seat)');
        self::assertFalse($this->roleQuery('isCommitteeChair', $users['B']), 'B loses R-12 (committee chair)');

        // C: delegated executive seat closed; no R-14.
        self::assertSame(ExecutiveMember::STATUS_LEFT, ExecutiveMember::findOrFail($cExecMember)->status, 'delegated exec seat closes on turnover');
        self::assertFalse($this->roleQuery('hasExecutiveSeat', $users['C'], 'delegated_proportional', null, 'delegated'), 'C loses R-14 (delegated exec)');

        // EO-2: standing ELECTED executive/court seats are retired by the general
        // rollover; their authority does not persist past the completed term.
        foreach ($standingExec as $label => $row) {
            self::assertSame(ExecutiveMember::STATUS_TERM_ENDED, ExecutiveMember::findOrFail($row['member'])->status, "standing elected exec {$label} retired on general rollover");
            self::assertFalse($this->roleQuery('hasExecutiveSeat', $row['user'], null, 'committee', 'elected'), "standing exec {$label} loses R-15 on rollover");
        }
        foreach ($standingCourt as $label => $row) {
            self::assertSame(JudicialSeat::STATUS_TERM_ENDED, JudicialSeat::findOrFail($row['seat'])->status, "standing elected judge {$label} retired on general rollover");
            self::assertFalse($this->roleQuery('hasJudicialSeat', $row['user'], 'elected'), "standing judge {$label} loses R-20 on rollover");
        }

        // Open speaker ballot voided.
        self::assertSame('void', DB::table('chamber_votes')->where('id', $openVote)->value('status'));

        // --- retained person E gets a correct successor record ---
        $eNew = LegislatureMember::query()->where('legislature_id', $leg)->where('user_id', $users['E'])
            ->where('election_id', $general2)->where('status', LegislatureMember::STATUS_ELECTED)->first();
        self::assertNotNull($eNew, 'retained member E receives a NEW elected seat row');
        self::assertNotSame($members['E'], (string) $eNew->id, 'the successor record is a new row, not the old seat');
        $eNewTerm = Term::findOrFail($eNew->term_id);
        self::assertSame(Term::STATUS_ACTIVE, $eNewTerm->status);
        self::assertSame($t0End, $eNewTerm->starts_on->toDateString(), 'successor term starts at successor certification');
        self::assertSame('2028-01-01', $eNewTerm->ends_on->toDateString(), 'fresh lockstep window = certified + 48 months');
        self::assertSame(Term::STATUS_COMPLETED, Term::findOrFail($terms['E'])->status, 'E old term completed');
        self::assertTrue($this->roleQuery('hasCurrentLegislatureSeat', $users['E']), 'retained E keeps a current legislative seat via the successor row');

        // All five incoming winners seated; legislature term advanced.
        $seated = LegislatureMember::query()->where('legislature_id', $leg)->where('election_id', $general2)
            ->where('status', LegislatureMember::STATUS_ELECTED)->count();
        self::assertSame(5, $seated, 'five successor seats');
        $legRow->refresh();
        self::assertSame(2, (int) $legRow->term_number, 'legislature term_number advances');
        self::assertSame($t0End, $legRow->term_starts_on->toDateString());
        self::assertSame('2028-01-01', $legRow->term_ends_on->toDateString());
        self::assertSame(5, count($result['winners']));
        self::assertFalse($result['term_window']['inherited'], 'general term is a fresh window, not inherited');
    }

    // =========================================================================
    // Criterion 3a — recurring executive cycle
    // =========================================================================

    public function test_recurring_executive_cycle_retires_prior_and_seats_new(): void
    {
        $jur = $this->uuid();
        $leg = $this->uuid();
        $general = $this->uuid();
        $execElectionPrev = $this->uuid();
        $execElectionNew = $this->uuid();
        $exec = $this->uuid();
        $t0 = '2020-01-01';
        $t0End = '2024-01-01';

        $this->seedLegislature($leg, $jur, 2, $t0, $t0End);
        // The current certified general anchors the recurring cycle window (EO-2/EO-8).
        $this->makeElection($general, $jur, $leg, Election::KIND_GENERAL, Election::STATUS_CERTIFIED, ['certified_at' => $t0]);
        $this->seedGeneralTermAnchor($general, $jur, $leg, $t0, $t0End); // legislature_members + terms source=general

        // An already-elected executive with a prior elected term (J0,K0 seated).
        DB::table('executives')->insert($this->row([
            'id' => $exec, 'jurisdiction_id' => $jur, 'type' => 'committee', 'term_number' => 1,
            'status' => Executive::STATUS_ELECTED, 'converted_at' => $t0, 'source_legislature_id' => $leg,
        ]));
        $this->makeElection($execElectionPrev, $jur, $leg, Election::KIND_EXECUTIVE, Election::STATUS_CERTIFIED, ['executive_id' => $exec, 'certified_at' => $t0]);
        $prior = ['J0' => $this->uuid(), 'K0' => $this->uuid()];
        $priorMembers = [];
        foreach ($prior as $label => $uid) {
            $this->makeUser($uid);
            $mid = $this->uuid();
            $tid = $this->uuid();
            $priorMembers[$label] = $mid;
            DB::table('executive_members')->insert($this->row([
                'id' => $mid, 'executive_id' => $exec, 'user_id' => $uid, 'role' => 'principal', 'rank' => 0,
                'joined_at' => $t0, 'term_id' => $tid, 'selection' => ExecutiveMember::SELECTION_ELECTED_STV, 'status' => ExecutiveMember::STATUS_SEATED,
            ]));
            $this->openExecTerm($tid, $mid, $uid, $jur, $leg, $execElectionPrev, $t0, $t0End);
        }

        // Recurring executive election, LINKED to the current general cycle.
        $this->makeElection($execElectionNew, $jur, $leg, Election::KIND_EXECUTIVE, Election::STATUS_TABULATING, [
            'executive_id' => $exec, 'general_cycle_election_id' => $general,
        ]);
        $raceNew = $this->uuid();
        $this->makeRace($raceNew, $execElectionNew, $jur, 'exec_committee', 2);
        $tab = $this->sealTabulation($raceNew);
        $new = ['J' => $this->uuid(), 'K' => $this->uuid()];
        $sn = 0;
        foreach ($new as $uid) {
            $this->makeUser($uid);
            $cand = $this->uuid();
            DB::table('candidacies')->insert($this->row(['id' => $cand, 'election_id' => $execElectionNew, 'race_id' => $raceNew, 'user_id' => $uid, 'status' => 'finalist']));
            DB::table('race_results')->insert($this->row(['id' => $this->uuid(), 'tabulation_id' => $tab, 'candidacy_id' => $cand, 'seat_no' => ++$sn, 'vote_share_norm' => '0.5000'], false));
        }

        $cert = ElectionCertification::create($this->row([
            'id' => $this->uuid(), 'election_id' => $execElectionNew, 'certified_at' => '2020-06-01', 'count_record_hash' => hash('sha256', 'e'), 'status' => 'certified',
        ], false));

        app(CertificationService::class)->certify(Election::findOrFail($execElectionNew), $cert);

        // Prior principals expired: seats term_ended, no R-15.
        foreach ($prior as $label => $uid) {
            self::assertSame(ExecutiveMember::STATUS_TERM_ENDED, ExecutiveMember::findOrFail($priorMembers[$label])->status, "prior exec {$label} term_ended");
            self::assertFalse($this->roleQuery('hasExecutiveSeat', $uid, null, 'committee', 'elected'), "prior exec {$label} loses R-15");
        }

        // New principals seated on the SAME executive; recurring cycle inherits general expiry.
        foreach ($new as $label => $uid) {
            $m = ExecutiveMember::query()->where('executive_id', $exec)->where('user_id', $uid)->where('status', ExecutiveMember::STATUS_SEATED)->first();
            self::assertNotNull($m, "new exec {$label} seated");
            self::assertSame(ExecutiveMember::SELECTION_ELECTED_STV, $m->selection);
            self::assertTrue($this->roleQuery('hasExecutiveSeat', $uid, null, 'committee', 'elected'), "new exec {$label} derives R-15");
            self::assertSame($t0End, Term::findOrFail($m->term_id)->ends_on->toDateString(), 'recurring exec term inherits the general-cycle expiry');
        }
        self::assertSame(Executive::STATUS_ELECTED, Executive::findOrFail($exec)->status);
    }

    // =========================================================================
    // Criterion 3b — recurring court cycle
    // =========================================================================

    public function test_recurring_judicial_cycle_retires_prior_and_seats_new(): void
    {
        $jur = $this->uuid();
        $leg = $this->uuid();
        $general = $this->uuid();
        $judElectionPrev = $this->uuid();
        $judElectionNew = $this->uuid();
        $jud = $this->uuid();
        $t0 = '2020-01-01';
        $t0End = '2024-01-01';

        $this->seedLegislature($leg, $jur, 2, $t0, $t0End);
        $this->makeElection($general, $jur, $leg, Election::KIND_GENERAL, Election::STATUS_CERTIFIED, ['certified_at' => $t0]);
        $this->seedGeneralTermAnchor($general, $jur, $leg, $t0, $t0End);

        DB::table('judiciaries')->insert($this->row([
            'id' => $jud, 'jurisdiction_id' => $jur, 'court_name' => 'High Court', 'type' => Judiciary::TYPE_ELECTED,
            'min_judges' => 5, 'term_years' => 4, 'status' => Judiciary::STATUS_ELECTED, 'converted_at' => $t0,
            'judge_count' => 2, 'source_legislature_id' => $leg,
        ]));
        $this->makeElection($judElectionPrev, $jur, $leg, Election::KIND_JUDICIAL, Election::STATUS_CERTIFIED, ['judiciary_id' => $jud, 'certified_at' => $t0]);
        $prior = ['L0' => $this->uuid(), 'M0' => $this->uuid()];
        $priorSeats = [];
        $sn0 = 0;
        foreach ($prior as $label => $uid) {
            $this->makeUser($uid);
            $sid = $this->uuid();
            $tid = $this->uuid();
            $priorSeats[$label] = $sid;
            DB::table('judicial_seats')->insert($this->row([
                'id' => $sid, 'judiciary_id' => $jud, 'user_id' => $uid, 'seat_number' => ++$sn0, 'seat_class' => JudicialSeat::CLASS_ELECTED,
                'elected_in_race_id' => null, 'term_id' => $tid, 'term_starts_on' => $t0, 'term_ends_on' => $t0End, 'status' => JudicialSeat::STATUS_SEATED,
            ]));
            $this->openJudicialTerm($tid, $sid, $uid, $jur, $leg, $judElectionPrev, $t0, $t0End);
        }

        $this->makeElection($judElectionNew, $jur, $leg, Election::KIND_JUDICIAL, Election::STATUS_TABULATING, [
            'judiciary_id' => $jud, 'general_cycle_election_id' => $general,
        ]);
        $raceNew = $this->uuid();
        $this->makeRace($raceNew, $judElectionNew, $jur, 'judicial_group', 2);
        $tab = $this->sealTabulation($raceNew);
        $new = ['L' => $this->uuid(), 'M' => $this->uuid()];
        $sn = 0;
        foreach ($new as $uid) {
            $this->makeUser($uid);
            $cand = $this->uuid();
            DB::table('candidacies')->insert($this->row(['id' => $cand, 'election_id' => $judElectionNew, 'race_id' => $raceNew, 'user_id' => $uid, 'status' => 'finalist']));
            DB::table('race_results')->insert($this->row(['id' => $this->uuid(), 'tabulation_id' => $tab, 'candidacy_id' => $cand, 'seat_no' => ++$sn, 'vote_share_norm' => '0.5000'], false));
        }

        $cert = ElectionCertification::create($this->row([
            'id' => $this->uuid(), 'election_id' => $judElectionNew, 'certified_at' => '2020-06-01', 'count_record_hash' => hash('sha256', 'j'), 'status' => 'certified',
        ], false));

        app(CertificationService::class)->certify(Election::findOrFail($judElectionNew), $cert);

        foreach ($prior as $label => $uid) {
            self::assertSame(JudicialSeat::STATUS_TERM_ENDED, JudicialSeat::findOrFail($priorSeats[$label])->status, "prior judge {$label} term_ended");
            self::assertFalse($this->roleQuery('hasJudicialSeat', $uid, 'elected'), "prior judge {$label} loses R-20");
        }
        foreach ($new as $label => $uid) {
            $s = JudicialSeat::query()->where('judiciary_id', $jud)->where('user_id', $uid)->where('status', JudicialSeat::STATUS_SEATED)->first();
            self::assertNotNull($s, "new judge {$label} seated");
            self::assertSame(JudicialSeat::CLASS_ELECTED, $s->seat_class);
            self::assertTrue($this->roleQuery('hasJudicialSeat', $uid, 'elected'), "new judge {$label} derives R-20");
            self::assertSame($t0End, Term::findOrFail($s->term_id)->ends_on->toDateString(), 'recurring court term inherits the general-cycle expiry');
        }
    }

    // =========================================================================
    // Criterion 4 — corrected same-election count preserves the term/cycle
    // =========================================================================

    public function test_corrected_count_preserves_term_and_reconciles_only_affected_seat(): void
    {
        $jur = $this->uuid();
        $leg = $this->uuid();
        $general = $this->uuid();
        $successor = $this->uuid();
        $race = $this->uuid();
        $t0 = '2020-01-01';
        $t0End = '2024-01-01';

        $this->seedLegislature($leg, $jur, 1, $t0, $t0End);
        $this->makeElection($general, $jur, $leg, Election::KIND_GENERAL, Election::STATUS_CERTIFIED, ['certified_at' => $t0]);
        $this->makeElection($successor, $jur, $leg, Election::KIND_GENERAL, Election::STATUS_APPROVAL_OPEN, ['prior_election_id' => $general]);
        $this->makeRace($race, $general, $jur, 'type_a', 5);

        // Five current seated members u1..u5 (the prior count winners), all on the original term.
        $users = [];
        $members = [];
        $terms = [];
        for ($i = 1; $i <= 5; $i++) {
            $uid = $this->uuid();
            $users[$i] = $uid;
            $this->makeUser($uid);
            $this->seedResidency($uid, $jur);
            $mid = $this->uuid();
            $tid = $this->uuid();
            $members[$i] = $mid;
            $terms[$i] = $tid;
            $this->seatLegislator($mid, $leg, $uid, $i, $race, $general, $tid, false, LegislatureMember::STATUS_SEATED);
            $this->openLockstepTerm($tid, $mid, $uid, $jur, $leg, $general, $t0, $t0End);
        }

        // u6 is the corrected winner who displaces u5.
        $u6 = $this->uuid();
        $this->makeUser($u6);
        $this->seedResidency($u6, $jur);

        // Prior tabulation: winners u1..u5. Corrected tabulation: u1..u4 + u6.
        $candidacies = [];
        foreach ($users + [6 => $u6] as $i => $uid) {
            $cid = $this->uuid();
            $candidacies[$i] = $cid;
            DB::table('candidacies')->insert($this->row(['id' => $cid, 'election_id' => $general, 'race_id' => $race, 'user_id' => $uid, 'status' => $i === 6 ? 'finalist' : 'elected']));
        }

        $priorTab = $this->uuid();
        DB::table('tabulations')->insert($this->row([
            'id' => $priorTab, 'race_id' => $race, 'kind' => 'initial', 'status' => 'superseded', 'record_hash' => hash('sha256', 'prior'),
            'completed_at' => '2021-01-01 00:00:00',
        ]));
        for ($i = 1; $i <= 5; $i++) {
            DB::table('race_results')->insert($this->row(['id' => $this->uuid(), 'tabulation_id' => $priorTab, 'candidacy_id' => $candidacies[$i], 'seat_no' => $i, 'vote_share_norm' => '0.2000'], false));
        }

        $correctedTab = $this->uuid();
        DB::table('tabulations')->insert($this->row([
            'id' => $correctedTab, 'race_id' => $race, 'kind' => 'audit_rerun', 'status' => 'complete', 'record_hash' => hash('sha256', 'corrected'),
            'completed_at' => '2021-06-01 00:00:00',
        ]));
        $correctedOrder = [1, 2, 3, 4, 6];
        $sn = 0;
        foreach ($correctedOrder as $i) {
            DB::table('race_results')->insert($this->row(['id' => $this->uuid(), 'tabulation_id' => $correctedTab, 'candidacy_id' => $candidacies[$i], 'seat_no' => ++$sn, 'vote_share_norm' => '0.2000'], false));
        }

        // The prior certification snapshot hash the reconciler recomputes.
        $priorHash = hash('sha256', $race.':'.hash('sha256', 'prior'));
        $previous = ElectionCertification::create($this->row([
            'id' => $this->uuid(), 'election_id' => $general, 'certified_at' => $t0, 'count_record_hash' => $priorHash, 'status' => 'superseded_by_audit',
        ], false));

        // The resolved corrected audit that ordered this superseding count.
        ElectionAudit::create($this->row([
            'id' => $this->uuid(), 'election_id' => $general, 'race_id' => $race, 'outcome' => ElectionAudit::OUTCOME_CORRECTED,
            'ordered_at' => '2021-03-01 00:00:00', 'resolved_at' => '2021-05-01 00:00:00', 'tabulation_id' => $correctedTab,
        ], false));

        $newCert = ElectionCertification::create($this->row([
            'id' => $this->uuid(), 'election_id' => $general, 'certified_at' => '2021-06-15', 'count_record_hash' => hash('sha256', 'newcount'), 'status' => 'certified',
        ], false));

        // ACT — the corrected-count reconciliation.
        $extra = app(ElectionCertificationReconciliationService::class)
            ->reconcile(Election::findOrFail($general), $newCert, $previous, [$race => hash('sha256', 'corrected')], null);

        // Only the affected seat reconciled; the original term/cycle preserved.
        self::assertTrue($extra['correction']);
        self::assertSame($t0.'', $extra['term_window']['starts_on'], 'window preserves the original term start');
        self::assertSame($t0End, $extra['term_window']['ends_on'], 'window preserves the original term expiry');
        self::assertTrue($extra['term_window']['inherited']);
        self::assertSame($successor, $extra['next_election_id'], 'no new cycle created; the original successor is reused');

        self::assertCount(4, $extra['preserved_member_ids'], 'four unaffected seats preserved');
        self::assertCount(1, $extra['displaced_member_ids'], 'one seat displaced');
        self::assertCount(1, $extra['new_member_ids'], 'one replacement seated');

        // u5 displaced (vacated), u1..u4 rows untouched.
        self::assertSame(LegislatureMember::STATUS_VACATED, LegislatureMember::findOrFail($members[5])->status);
        self::assertSame((string) $members[5], $extra['displaced_member_ids'][0]);
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(LegislatureMember::STATUS_SEATED, LegislatureMember::findOrFail($members[$i])->status, "u{$i} seat preserved");
            self::assertSame($terms[$i], LegislatureMember::findOrFail($members[$i])->term_id, "u{$i} keeps its original term");
        }

        // u6 replacement: new seat, inherited window (starts on correction date, original expiry).
        $newMember = LegislatureMember::findOrFail($extra['new_member_ids'][0]);
        self::assertSame($u6, (string) $newMember->user_id);
        self::assertSame(5, (int) $newMember->seat_no, 'replacement inherits the displaced seat number');
        $newTerm = Term::findOrFail($newMember->term_id);
        self::assertSame('2021-06-15', $newTerm->starts_on->toDateString(), 'replacement starts at the correction certification date');
        self::assertSame($t0End, $newTerm->ends_on->toDateString(), 'replacement inherits the original expiry, not a fresh cycle');
    }

    // =========================================================================
    // EO-2 targeted check — one recurring-office election per general cycle
    // =========================================================================

    public function test_eo2_unique_index_blocks_duplicate_recurring_office_per_cycle(): void
    {
        $jur = $this->uuid();
        $leg = $this->uuid();
        $general = $this->uuid();
        $exec = $this->uuid();
        $this->seedLegislature($leg, $jur, 1, '2020-01-01', '2024-01-01');
        $this->makeElection($general, $jur, $leg, Election::KIND_GENERAL, Election::STATUS_CERTIFIED, ['certified_at' => '2020-01-01']);
        DB::table('executives')->insert($this->row(['id' => $exec, 'jurisdiction_id' => $jur, 'type' => 'committee', 'status' => 'elected', 'source_legislature_id' => $leg]));

        $this->makeElection($this->uuid(), $jur, $leg, Election::KIND_EXECUTIVE, Election::STATUS_TABULATING, [
            'executive_id' => $exec, 'general_cycle_election_id' => $general,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeElection($this->uuid(), $jur, $leg, Election::KIND_EXECUTIVE, Election::STATUS_TABULATING, [
            'executive_id' => $exec, 'general_cycle_election_id' => $general,
        ]);
    }

    // =========================================================================
    // Doubles
    // =========================================================================

    private function bindDoubles(): void
    {
        $audit = Mockery::mock(AuditService::class)->shouldIgnoreMissing();
        $audit->shouldReceive('append')->andReturn(new AuditEntry());
        $this->app->instance(AuditService::class, $audit);

        $settings = Mockery::mock(SettingsResolver::class)->shouldIgnoreMissing();
        $settings->shouldReceive('resolveInt')->andReturnUsing(fn ($j, $k, $d) => $k === 'election_interval_months' ? 48 : $d);
        $this->app->instance(SettingsResolver::class, $settings);

        $this->lifecycle = Mockery::mock(ElectionLifecycleService::class)->shouldIgnoreMissing();
        $this->app->instance(ElectionLifecycleService::class, $this->lifecycle);

        $referendum = Mockery::mock(ReferendumService::class)->shouldIgnoreMissing();
        $referendum->shouldReceive('certifyForElection')->andReturn([]);
        $referendum->shouldReceive('releaseShields')->andReturn(0);
        $this->app->instance(ReferendumService::class, $referendum);

        // Real ExecutiveFormationService logic, with its unrelated ctor deps stubbed.
        $exf = new ExecutiveFormationService(
            $audit,
            Mockery::mock(\App\Services\EnactmentService::class),
            Mockery::mock(\App\Services\PublicRecordService::class),
            Mockery::mock(\App\Services\MultiJurisdictionVoteService::class),
            Mockery::mock(\App\Services\ChamberVoteService::class),
            app(RoleService::class),
        );
        $this->app->instance(ExecutiveFormationService::class, $exf);
    }

    // =========================================================================
    // Seed helpers
    // =========================================================================

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function makeUser(string $id): void
    {
        DB::table('users')->insert(['id' => $id, 'name' => 'Legal name', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function userModel(string $id): \App\Models\User
    {
        return \App\Models\User::findOrFail($id);
    }

    /**
     * Invoke a real RoleService seat-derivation query (private, DB-backed) so
     * the authority check reads the same Eloquent path production reads,
     * without the full rolesFor() role graph. Flushes the singleton cache
     * first so a prior derivation never masks the new seat state.
     */
    private function roleQuery(string $method, mixed ...$args): bool
    {
        $svc = app(RoleService::class);
        $svc->flush();
        $m = new \ReflectionMethod($svc, $method);
        $m->setAccessible(true);

        return (bool) $m->invoke($svc, ...$args);
    }

    private function seedResidency(string $userId, string $jur): void
    {
        DB::table('residency_confirmations')->insert(['id' => $this->uuid(), 'user_id' => $userId, 'jurisdiction_id' => $jur, 'is_active' => true, 'depth' => 1]);
    }

    private function seedLegislature(string $id, string $jur, int $termNumber, string $starts, string $ends): void
    {
        DB::table('legislatures')->insert($this->row([
            'id' => $id, 'jurisdiction_id' => $jur, 'term_number' => $termNumber, 'term_starts_on' => $starts, 'term_ends_on' => $ends,
            'status' => Legislature::STATUS_ACTIVE, 'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0,
        ]));
    }

    private function makeElection(string $id, string $jur, string $leg, string $kind, string $status, array $extra): void
    {
        DB::table('elections')->insert($this->row(array_merge([
            'id' => $id, 'jurisdiction_id' => $jur, 'legislature_id' => $leg, 'kind' => $kind, 'status' => $status,
        ], $extra)));
    }

    private function makeRace(string $id, string $election, string $jur, string $seatKind, int $seats): void
    {
        DB::table('election_races')->insert($this->row([
            'id' => $id, 'election_id' => $election, 'jurisdiction_id' => $jur, 'seat_kind' => $seatKind, 'seats' => $seats, 'status' => 'certified',
        ]));
    }

    private function sealTabulation(string $race): string
    {
        $id = $this->uuid();
        DB::table('tabulations')->insert($this->row([
            'id' => $id, 'race_id' => $race, 'kind' => 'initial', 'status' => 'complete', 'record_hash' => hash('sha256', $race),
            'completed_at' => now(),
        ]));

        return $id;
    }

    private function seatLegislator(string $mid, string $leg, string $uid, int $seatNo, string $race, string $election, string $tid, bool $speaker, string $status = LegislatureMember::STATUS_SEATED): void
    {
        DB::table('legislature_members')->insert($this->row([
            'id' => $mid, 'legislature_id' => $leg, 'user_id' => $uid, 'seat_type' => 'a', 'seat_no' => $seatNo,
            'elected_in_race_id' => $race, 'election_id' => $election, 'term_id' => $tid, 'status' => $status,
            'seated_on' => '2020-01-01', 'term_ends_on' => '2024-01-01', 'is_speaker' => $speaker,
        ]));
    }

    private function openLockstepTerm(string $tid, string $mid, string $uid, string $jur, string $leg, string $election, string $starts, string $ends): void
    {
        DB::table('terms')->insert($this->row([
            'id' => $tid, 'office_kind' => 'legislature_seat', 'office_type' => 'legislature_members', 'office_id' => $mid,
            'holder_user_id' => $uid, 'jurisdiction_id' => $jur, 'legislature_id' => $leg, 'term_class' => 'lockstep',
            'starts_on' => $starts, 'ends_on' => $ends, 'source_election_id' => $election, 'status' => 'active',
        ]));
    }

    private function openExecTerm(string $tid, string $mid, string $uid, string $jur, string $leg, string $election, string $starts, string $ends): void
    {
        DB::table('terms')->insert($this->row([
            'id' => $tid, 'office_kind' => 'executive_seat', 'office_type' => 'executive_members', 'office_id' => $mid,
            'holder_user_id' => $uid, 'jurisdiction_id' => $jur, 'legislature_id' => $leg, 'term_class' => 'lockstep',
            'starts_on' => $starts, 'ends_on' => $ends, 'source_election_id' => $election, 'status' => 'active',
        ]));
    }

    private function openJudicialTerm(string $tid, string $sid, string $uid, string $jur, string $leg, string $election, string $starts, string $ends): void
    {
        DB::table('terms')->insert($this->row([
            'id' => $tid, 'office_kind' => 'judicial_seat', 'office_type' => 'judicial_seats', 'office_id' => $sid,
            'holder_user_id' => $uid, 'jurisdiction_id' => $jur, 'legislature_id' => $leg, 'term_class' => 'lockstep',
            'starts_on' => $starts, 'ends_on' => $ends, 'source_election_id' => $election, 'status' => 'active',
        ]));
    }

    /** Legislature_members + terms rows keyed to a general election, matching the legislature term. */
    private function seedGeneralTermAnchor(string $general, string $jur, string $leg, string $starts, string $ends): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $uid = $this->uuid();
            $this->makeUser($uid);
            $mid = $this->uuid();
            $tid = $this->uuid();
            $this->seatLegislator($mid, $leg, $uid, $i, $this->uuid(), $general, $tid, false);
            $this->openLockstepTerm($tid, $mid, $uid, $jur, $leg, $general, $starts, $ends);
        }
    }

    private function armClk10(string $termId, string $jur, string $endsOn): void
    {
        DB::table('clock_timers')->insert($this->row([
            'id' => $this->uuid(), 'clock_id' => 'CLK-10', 'jurisdiction_id' => $jur, 'subject_type' => 'term', 'subject_id' => $termId,
            'armed_at' => now(), 'state' => 'armed', 'payload' => json_encode(['step' => 'lockstep', 'ends_on' => $endsOn]),
        ]));
    }

    /** Fill created_at/updated_at unless the row is a race_result-style (created_at only). */
    private function row(array $attrs, bool $timestamps = true): array
    {
        if ($timestamps) {
            $attrs['created_at'] = $attrs['created_at'] ?? now();
            $attrs['updated_at'] = $attrs['updated_at'] ?? now();
        } else {
            $attrs['created_at'] = $attrs['created_at'] ?? now();
        }

        return $attrs;
    }

    private function seedClocks(): void
    {
        foreach (['CLK-01', 'CLK-04', 'CLK-09', 'CLK-10'] as $id) {
            DB::table('clocks')->insert(['id' => $id, 'name' => $id, 'type' => 'flag', 'amendable' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // =========================================================================
    // Schema
    // =========================================================================

    private function buildSchema(): void
    {
        $s = DB::connection()->getSchemaBuilder();

        $s->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->default('name');
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('residency_confirmations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('jurisdiction_id');
            $t->boolean('is_active')->default(true);
            $t->integer('depth')->nullable();
        });

        $s->create('legislatures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->integer('term_number')->default(0);
            $t->date('term_starts_on')->nullable();
            $t->date('term_ends_on')->nullable();
            $t->string('status')->default('active');
            $t->integer('total_seats')->nullable();
            $t->integer('type_a_seats')->nullable();
            $t->integer('type_b_seats')->nullable();
            $t->uuid('speaker_id')->nullable();
            $t->integer('quorum_required')->nullable();
            $t->date('last_met_on')->nullable();
            $t->date('next_meeting_due_by')->nullable();
            $t->uuid('parent_legislature_id')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('legislature_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('legislature_id');
            $t->uuid('user_id');
            $t->string('seat_type')->default('a');
            $t->integer('seat_no')->nullable();
            $t->uuid('district_id')->nullable();
            $t->uuid('elected_in_race_id')->nullable();
            $t->uuid('term_id')->nullable();
            $t->uuid('election_id')->nullable();
            $t->decimal('vote_share_norm', 8, 4)->nullable();
            $t->date('seated_on')->nullable();
            $t->timestamp('seated_at')->nullable();
            $t->date('term_ends_on')->nullable();
            $t->string('status')->default('elected');
            $t->timestamp('vacated_at')->nullable();
            $t->string('vacancy_reason')->nullable();
            $t->uuid('home_jurisdiction_id')->nullable();
            $t->boolean('is_speaker')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('terms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('office_kind');
            $t->string('office_type');
            $t->uuid('office_id');
            $t->uuid('holder_user_id');
            $t->uuid('jurisdiction_id')->nullable();
            $t->uuid('legislature_id')->nullable();
            $t->string('term_class');
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->uuid('source_election_id')->nullable();
            $t->uuid('source_appointment_id')->nullable();
            $t->string('status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('committees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('legislature_id');
            $t->string('name');
            $t->string('purpose')->nullable();
            $t->integer('seats')->nullable();
            $t->integer('type_a_seats')->nullable();
            $t->integer('type_b_seats')->nullable();
            $t->uuid('created_by_vote_id')->nullable();
            $t->uuid('created_by_law_id')->nullable();
            $t->uuid('chair_member_id')->nullable();
            $t->uuid('alternate_member_id')->nullable();
            $t->string('status')->default('created');
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('committee_seats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('committee_id');
            $t->uuid('member_id');
            $t->string('seat_kind')->nullable();
            $t->string('status')->default('allocated');
            $t->string('assigned_via')->nullable();
            $t->integer('preference_rank_honored')->nullable();
            $t->timestamp('seated_at')->nullable();
            $t->timestamp('vacated_at')->nullable();
            $t->string('vacated_reason')->nullable();
            $t->timestamps();
        });

        $s->create('chamber_votes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('body_type');
            $t->uuid('body_id');
            $t->uuid('legislature_id')->nullable();
            $t->uuid('jurisdiction_id')->nullable();
            $t->string('votable_type')->nullable();
            $t->uuid('votable_id')->nullable();
            $t->string('vote_type');
            $t->string('vote_method')->nullable();
            $t->string('threshold_basis')->nullable();
            $t->string('stage')->nullable();
            $t->boolean('bicameral')->nullable();
            $t->json('serving_snapshot')->nullable();
            $t->uuid('held_in_session_id')->nullable();
            $t->uuid('opened_by_member_id')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('outcome')->nullable();
            $t->boolean('speaker_tiebreak')->nullable();
            $t->json('rcv_record')->nullable();
            $t->string('status')->default('open');
            $t->timestamps();
        });

        $s->create('executives', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->string('type')->nullable();
            $t->integer('term_number')->default(0);
            $t->date('term_starts_on')->nullable();
            $t->date('term_ends_on')->nullable();
            $t->string('status')->default('forming');
            $t->uuid('parent_executive_id')->nullable();
            $t->uuid('source_legislature_id')->nullable();
            $t->uuid('delegation_law_id')->nullable();
            $t->json('delegated_scope')->nullable();
            $t->uuid('conversion_process_id')->nullable();
            $t->uuid('conversion_law_id')->nullable();
            $t->timestamp('converted_at')->nullable();
            $t->integer('delegated_member_count')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('executive_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('executive_id');
            $t->uuid('user_id');
            $t->string('role');
            $t->integer('rank')->default(0);
            $t->date('joined_at')->nullable();
            $t->date('left_at')->nullable();
            $t->uuid('legislature_member_id')->nullable();
            $t->uuid('elected_in_race_id')->nullable();
            $t->uuid('term_id')->nullable();
            $t->string('selection');
            $t->string('status')->default('seated');
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('judiciaries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->string('court_name')->nullable();
            $t->string('type');
            $t->integer('min_judges')->nullable();
            $t->integer('term_years')->nullable();
            $t->string('status')->default('forming');
            $t->uuid('parent_judiciary_id')->nullable();
            $t->uuid('creation_law_id')->nullable();
            $t->string('nomination_mode')->nullable();
            $t->uuid('conversion_process_id')->nullable();
            $t->uuid('conversion_law_id')->nullable();
            $t->timestamp('converted_at')->nullable();
            $t->integer('judge_count')->nullable();
            $t->uuid('source_legislature_id')->nullable();
            $t->uuid('judicial_committee_id')->nullable();
            $t->uuid('judicial_committee_vote_id')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('judicial_seats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('judiciary_id');
            $t->uuid('user_id')->nullable();
            $t->integer('seat_number');
            $t->string('seat_class');
            $t->uuid('nominating_jurisdiction_id')->nullable();
            $t->uuid('appointment_id')->nullable();
            $t->uuid('elected_in_race_id')->nullable();
            $t->uuid('term_id')->nullable();
            $t->date('term_starts_on')->nullable();
            $t->date('term_ends_on')->nullable();
            $t->string('status')->default('vacant');
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('elections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->uuid('legislature_id')->nullable();
            $t->string('kind');
            $t->string('status');
            $t->string('trigger')->nullable();
            $t->string('voting_method')->nullable();
            $t->uuid('district_map_id')->nullable();
            $t->uuid('election_board_id')->nullable();
            $t->timestamp('approval_opens_at')->nullable();
            $t->timestamp('finalist_cutoff_at')->nullable();
            $t->timestamp('ranked_opens_at')->nullable();
            $t->timestamp('ranked_closes_at')->nullable();
            $t->timestamp('certified_at')->nullable();
            $t->uuid('prior_election_id')->nullable();
            $t->uuid('general_cycle_election_id')->nullable();
            $t->uuid('triggered_by_timer_id')->nullable();
            $t->uuid('vacancy_id')->nullable();
            $t->text('ballot_key_wrapped')->nullable();
            $t->uuid('board_id')->nullable();
            $t->uuid('executive_id')->nullable();
            $t->uuid('judiciary_id')->nullable();
            $t->string('constitutional_version')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        // EO-2 SQLite unique-index branch (2026_09_13_110000): one recurring-office
        // election per general cycle per office. SQLite treats NULLs as distinct,
        // so general elections (NULL anchor) are unconstrained.
        DB::statement('CREATE UNIQUE INDEX elections_general_cycle_executive_unique ON elections (general_cycle_election_id, executive_id)');
        DB::statement('CREATE UNIQUE INDEX elections_general_cycle_judiciary_unique ON elections (general_cycle_election_id, judiciary_id)');

        $s->create('election_races', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('election_id');
            $t->uuid('district_id')->nullable();
            $t->uuid('type_b_panel_id')->nullable();
            $t->uuid('jurisdiction_id')->nullable();
            $t->string('seat_kind');
            $t->integer('seats');
            $t->integer('finalist_count')->nullable();
            $t->string('electorate_type')->nullable();
            $t->integer('quota')->nullable();
            $t->integer('total_valid_ballots')->nullable();
            $t->string('status')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('candidacies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('election_id');
            $t->uuid('race_id');
            $t->uuid('user_id');
            $t->string('status');
            $t->text('platform_statement')->nullable();
            $t->json('position_tags')->nullable();
            $t->timestamp('residency_attested_at')->nullable();
            $t->timestamp('validated_at')->nullable();
            $t->uuid('validated_by_member_id')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        $s->create('tabulations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('race_id');
            $t->string('kind');
            $t->uuid('excluded_candidacy_id')->nullable();
            $t->string('engine_version')->nullable();
            $t->integer('total_valid')->nullable();
            $t->integer('quota')->nullable();
            $t->integer('seats')->nullable();
            $t->string('status');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('record_hash')->nullable();
            $t->timestamps();
        });

        $s->create('race_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tabulation_id');
            $t->uuid('candidacy_id');
            $t->integer('round_elected')->nullable();
            $t->integer('seat_no')->nullable();
            $t->decimal('vote_share_norm', 8, 4)->nullable();
            $t->boolean('is_runner_up')->nullable();
            $t->integer('runner_up_rank')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        $s->create('election_certifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('election_id');
            $t->uuid('election_board_id')->nullable();
            $t->uuid('certified_by_member_id')->nullable();
            $t->timestamp('certified_at')->nullable();
            $t->string('count_record_hash')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $s->create('election_audits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('election_id');
            $t->uuid('race_id')->nullable();
            $t->string('cause')->nullable();
            $t->uuid('ordered_by')->nullable();
            $t->timestamp('ordered_at')->nullable();
            $t->uuid('tabulation_id')->nullable();
            $t->string('outcome');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        $s->create('vacancies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('seat_type');
            $t->uuid('seat_id');
            $t->uuid('legislature_id');
            $t->uuid('jurisdiction_id');
            $t->uuid('declared_by')->nullable();
            $t->string('declared_via_form')->nullable();
            $t->string('status');
            $t->timestamp('detected_at')->nullable();
            $t->timestamp('declared_at')->nullable();
            $t->uuid('countback_tabulation_id')->nullable();
            $t->uuid('special_election_id')->nullable();
            $t->uuid('filled_by_user_id')->nullable();
            $t->timestamp('filled_at')->nullable();
            $t->timestamps();
        });

        $s->create('clocks', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name')->nullable();
            $t->string('type')->nullable();
            $t->json('default_value')->nullable();
            $t->boolean('amendable')->nullable();
            $t->string('fires_workflow')->nullable();
            $t->string('basis')->nullable();
            $t->timestamps();
        });

        $s->create('clock_timers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('clock_id');
            $t->uuid('jurisdiction_id')->nullable();
            $t->string('subject_type')->nullable();
            $t->uuid('subject_id')->nullable();
            $t->timestamp('armed_at')->nullable();
            $t->timestamp('fires_at')->nullable();
            $t->string('state')->default('armed');
            $t->json('payload')->nullable();
            $t->json('override_value')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
    }
}
