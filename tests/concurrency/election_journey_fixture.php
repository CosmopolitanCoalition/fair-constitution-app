<?php
/**
 * S1 · elections review journey — opt-in disposable-PostgreSQL probe, never
 * the world database. Follows tests/concurrency/judicial_nomination_migration.php:
 * strict cga_election_YYYYMMDD_<16hex> name, nonce-guarded fixture_guard row,
 * refusal when public.organizations exists, statement/lock timeouts, DROP in
 * a finally block.
 *
 * Journey (register row S1 · elections), all through real handlers/services:
 *   F-IND-011 x3 (C4 nonresident, C5 wrong-jurisdiction refused)
 *   -> board validation (race bind) -> F-CAN-002 -> F-ORG-002
 *   -> F-IND-025 / F-IND-026 / re-endorse (+ own-candidacy refusal, V2 public)
 *   -> approvals cast/revoke/recast (V3)
 *   -> applyFinalistCutoff -> openRanked
 *   -> F-IND-007 x10 (+ late / duplicate / wrong-race refusals)
 *   -> closeRanked -> markTabulating
 *   -> VoteCountingService::countStv (pre-sealed rankings) -> tabulation + results
 *   -> F-ELB-004 certification (real CertificationService seating, GENERAL)
 *   -> VacancyService::declare -> VoteCountingService::countback
 *      -> CertificationService::certifyCountback (C3 at original expiry)
 *
 * Pre-sealed tabulation (plan constraint): the BallotBox decrypt path is
 * PG-JSON-specific and out of scope; a NoopBallotBoxDelegate records only the
 * envelope (so the double-vote barrier is live), and CountInput is built from
 * the fixture's own controlled rankings. Peripheral cycle collaborators are
 * doubled per the plan (AuditService / SettingsResolver / ReferendumService),
 * plus ClockService, ConstitutionalVersionService, AchievementService,
 * ExecutiveFormationService and the ElectionLifecycleService successor/arm
 * methods (out-of-scope cycle continuation). Real code under test: the six
 * form handlers, ApprovalService, the lifecycle phase machine, the protected
 * VoteCountingService counting core, and the protected CertificationService
 * seating + countback.
 */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Forms\Contracts\BallotBoxDelegate;
use App\Domain\Forms\Handlers\BallotSubmission;
use App\Domain\Forms\Handlers\CandidacyRegistration;
use App\Domain\Forms\Handlers\CandidateEndorsementGrant;
use App\Domain\Forms\Handlers\ElectionResultsCertification;
use App\Domain\Forms\Handlers\EndorsementRequest as EndorsementRequestHandler;
use App\Domain\Forms\Handlers\IndividualEndorsement;
use App\Domain\Forms\Handlers\IndividualEndorsementWithdrawal;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\LegislatureMember;
use App\Models\RaceResult;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\ApprovalService;
use App\Services\CertificationService;
use App\Services\ElectionLifecycleService;
use App\Services\VacancyService;
use App\Services\VoteCountingService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('60000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_election_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }

/** Assert a callable refuses with a ConstitutionalViolation whose message contains $needle. */
function refuses(string $label, string $needle, callable $fn): void
{
    try {
        $fn();
    } catch (ConstitutionalViolation $e) {
        check(str_contains($e->getMessage(), $needle),
            "Refusal [$label] wrong message: {$e->getMessage()} (wanted: $needle)");
        emit(['refusal' => $label, 'held' => true, 'reason' => substr($e->getMessage(), 0, 90)]);
        return;
    } catch (Throwable $e) {
        throw new RuntimeException("Refusal [$label] threw a non-constitutional error: ".$e->getMessage());
    }
    throw new RuntimeException("Refusal [$label] did NOT refuse — the guard is missing.");
}

function connectionConfig(string $name): array
{
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'election_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_election_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}";
    $conn = new PDO($dsn, $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $conn->exec("SET statement_timeout='30s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='30s'");
    return $conn;
}
function identity(PDO $conn, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $conn->query('SELECT current_database() AS db, nonce FROM election_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
    check($row !== false && $row['db'] === $name && hash_equals($nonce, $row['nonce']), 'Fixture identity check failed');
    check($conn->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fixture unexpectedly contains public organizations');
}
function bootFixture(string $name, string $nonce): void
{
    $verify = pdo($name); identity($verify, $name, $nonce); $verify = null;
    $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
    $app->instance('config', new Illuminate\Config\Repository(['app' => ['key' => '', 'timezone' => 'UTC'], 'cache' => ['default' => 'array']]));
    $events = new Illuminate\Events\Dispatcher($app);
    $app->instance('events', $events); $app->instance(Illuminate\Contracts\Events\Dispatcher::class, $events);
    $capsule = new Capsule($app); $capsule->addConnection(connectionConfig($name), 'fixture');
    $capsule->getDatabaseManager()->setDefaultConnection('fixture'); $capsule->setEventDispatcher($events); $capsule->setAsGlobal(); $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('db.schema', $capsule->getConnection('fixture')->getSchemaBuilder());
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $cache = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore);
    $app->instance(Illuminate\Contracts\Cache\Repository::class, $cache);
    $app->instance('cache', $cache); $app->instance('cache.store', $cache);
    $app->instance(Illuminate\Log\Context\Repository::class, new Illuminate\Log\Context\Repository($events));
    $fakeBus = new Illuminate\Support\Testing\Fakes\BusFake(new Illuminate\Bus\Dispatcher($app));
    $app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $fakeBus);
    foreach (["SET statement_timeout='30s'", "SET lock_timeout='8s'", "SET idle_in_transaction_session_timeout='30s'"] as $setting) DB::statement($setting);
    identity(DB::connection()->getPdo(), $name, $nonce);
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'election_fixture', 'Unexpected schema/search path');
}

function createSchema(): void
{
    $ddl = [
        "CREATE TABLE users (id uuid PRIMARY KEY, name text, display_name text, is_operator boolean DEFAULT false, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE jurisdictions (id uuid PRIMARY KEY, name text, parent_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE residency_confirmations (id uuid PRIMARY KEY, user_id uuid, jurisdiction_id uuid, is_active boolean DEFAULT true, depth integer, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE legislatures (id uuid PRIMARY KEY, jurisdiction_id uuid, term_number integer DEFAULT 0, term_starts_on date, term_ends_on date, status text, total_seats integer, type_a_seats integer, type_b_seats integer, speaker_id uuid, quorum_required integer, last_met_on date, next_meeting_due_by date, parent_legislature_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE legislature_districts (id uuid PRIMARY KEY, legislature_id uuid, name text, seats integer, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE legislature_district_jurisdictions (id uuid PRIMARY KEY, district_id uuid, jurisdiction_id uuid, created_at timestamptz, updated_at timestamptz)",
        "CREATE TABLE legislature_type_b_panel_jurisdictions (id uuid PRIMARY KEY, panel_id uuid, jurisdiction_id uuid, created_at timestamptz, updated_at timestamptz)",
        "CREATE TABLE elections (id uuid PRIMARY KEY, jurisdiction_id uuid, legislature_id uuid, kind text, status text, trigger text, voting_method text, district_map_id uuid, election_board_id uuid, approval_opens_at timestamptz, finalist_cutoff_at timestamptz, ranked_opens_at timestamptz, ranked_closes_at timestamptz, certified_at timestamptz, prior_election_id uuid, general_cycle_election_id uuid, triggered_by_timer_id uuid, vacancy_id uuid, ballot_key_wrapped text, board_id uuid, executive_id uuid, judiciary_id uuid, constitutional_version text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE election_races (id uuid PRIMARY KEY, election_id uuid, district_id uuid, type_b_panel_id uuid, jurisdiction_id uuid, seat_kind text, seats integer, finalist_count integer, electorate_type text DEFAULT 'residents', quota integer, total_valid_ballots integer, status text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE candidacies (id uuid PRIMARY KEY, election_id uuid, race_id uuid, user_id uuid, status text, platform_statement text, position_tags jsonb, residency_attested_at timestamptz, validated_at timestamptz, validated_by_member_id uuid, rejection_reason text, withdrawn_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE ballot_envelopes (id uuid PRIMARY KEY, race_id uuid, user_id uuid, kind text, referendum_question_id uuid, committed_at timestamptz, created_at timestamptz)",
        "CREATE TABLE endorsements (id uuid PRIMARY KEY, election_id uuid, candidate_id uuid, endorser_type text, endorser_id uuid, statement text, endorsed_at timestamptz, withdrawn_at timestamptz, is_active boolean, is_public boolean, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE organizations (id uuid PRIMARY KEY, jurisdiction_id uuid, type text, name text, slug text, is_active boolean DEFAULT true, is_registered boolean DEFAULT true, agent_user_id uuid, status text, worker_count integer, ip_is_public_domain boolean, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE endorsement_requests (id uuid PRIMARY KEY, candidacy_id uuid, organization_id uuid, message text, status text, requested_at timestamptz, decided_at timestamptz, endorsement_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE approvals (id uuid PRIMARY KEY, election_id uuid, candidacy_id uuid, user_id uuid, created_at timestamptz, revoked_at timestamptz, updated_at timestamptz)",
        "CREATE TABLE approval_standings (id uuid PRIMARY KEY, race_id uuid, candidacy_id uuid, as_of_date date, approvals_count integer, rank integer, delta integer, is_frozen boolean, created_at timestamptz, updated_at timestamptz, CONSTRAINT approval_standings_unique UNIQUE (candidacy_id, as_of_date))",
        "CREATE TABLE tabulations (id uuid PRIMARY KEY, race_id uuid, kind text, excluded_candidacy_id uuid, engine_version text, total_valid integer, quota integer, seats integer, status text, started_at timestamptz, completed_at timestamptz, record_hash text, created_at timestamptz, updated_at timestamptz)",
        "CREATE TABLE race_results (id uuid PRIMARY KEY, tabulation_id uuid, candidacy_id uuid, round_elected integer, seat_no integer, vote_share_norm numeric, is_runner_up boolean, runner_up_rank integer, created_at timestamptz, updated_at timestamptz)",
        "CREATE TABLE legislature_members (id uuid PRIMARY KEY, legislature_id uuid, user_id uuid, seat_type text, seat_no integer, district_id uuid, elected_in_race_id uuid, term_id uuid, election_id uuid, vote_share_norm numeric, seated_on date, seated_at timestamptz, term_ends_on date, status text, vacated_at timestamptz, vacancy_reason text, home_jurisdiction_id uuid, is_speaker boolean DEFAULT false, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE terms (id uuid PRIMARY KEY, office_kind text, office_type text, office_id uuid, holder_user_id uuid, jurisdiction_id uuid, legislature_id uuid, term_class text, starts_on date, ends_on date, source_election_id uuid, source_appointment_id uuid, status text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE vacancies (id uuid PRIMARY KEY, seat_type text, seat_id uuid, legislature_id uuid, jurisdiction_id uuid, declared_by uuid, declared_via_form text, status text, detected_at timestamptz, declared_at timestamptz, countback_tabulation_id uuid, special_election_id uuid, filled_by_user_id uuid, filled_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE election_certifications (id uuid PRIMARY KEY, election_id uuid, election_board_id uuid, certified_by_member_id uuid, certified_at timestamptz, count_record_hash text, status text, created_at timestamptz, updated_at timestamptz)",
        "CREATE TABLE election_audits (id uuid PRIMARY KEY, election_id uuid, race_id uuid, outcome text, status text, created_at timestamptz, updated_at timestamptz)",
        "CREATE TABLE election_boards (id uuid PRIMARY KEY, jurisdiction_id uuid, status text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE election_board_members (id uuid PRIMARY KEY, election_board_id uuid, user_id uuid, appointment_id uuid, status text, term_starts_on date, term_ends_on date, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE committees (id uuid PRIMARY KEY, legislature_id uuid, status text, chair_member_id uuid, alternate_member_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE committee_seats (id uuid PRIMARY KEY, committee_id uuid, member_id uuid, status text, vacated_at timestamptz, vacated_reason text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE chamber_votes (id uuid PRIMARY KEY, body_type text, body_id uuid, vote_type text, status text, decided_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE clock_timers (id uuid PRIMARY KEY, clock_id text, jurisdiction_id uuid, subject_type text, subject_id uuid, armed_at timestamptz, fires_at timestamptz, state text, payload jsonb, override_value jsonb, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE executives (id uuid PRIMARY KEY, jurisdiction_id uuid, type text, status text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE executive_members (id uuid PRIMARY KEY, executive_id uuid, user_id uuid, role text, rank integer, selection text, status text, joined_at date, left_at date, term_id uuid, elected_in_race_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        // tabulation_rounds — written by the REAL TabulationRecorder::complete
        // when the countback runs through VacancyService::runCountback.
        "CREATE TABLE tabulation_rounds (id uuid PRIMARY KEY, tabulation_id uuid, round_no integer, action text, candidacy_id uuid, transfer jsonb, tallies jsonb, created_at timestamptz)",
        // Empty tables the RoleService fact queries touch — present (never
        // populated) so rolesFor() resolves R-09 without a missing-relation
        // error. Each join returns no rows, so C1/CB derive only the
        // association + legislature-seat roles.
        "CREATE TABLE residency_claims (id uuid PRIMARY KEY, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE org_staff_grants (id uuid PRIMARY KEY, grantee_user_id uuid, organization_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE org_memberships (id uuid PRIMARY KEY, user_id uuid, organization_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE org_workers (id uuid PRIMARY KEY, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE boards (id uuid PRIMARY KEY, boardable_type text, status text, deleted_at timestamptz)",
        "CREATE TABLE board_seats (id uuid PRIMARY KEY, board_id uuid, holder_user_id uuid, seat_class text, is_chair boolean DEFAULT false, status text, deleted_at timestamptz)",
        "CREATE TABLE admin_offices (id uuid PRIMARY KEY, status text, deleted_at timestamptz)",
        "CREATE TABLE appointments (id uuid PRIMARY KEY, appointable_type text, appointable_id uuid, nominee_user_id uuid, term_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE judiciaries (id uuid PRIMARY KEY, type text, status text, deleted_at timestamptz)",
        "CREATE TABLE judicial_seats (id uuid PRIMARY KEY, judiciary_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE advocates (id uuid PRIMARY KEY, judiciary_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE juries (id uuid PRIMARY KEY, status text, deleted_at timestamptz)",
        "CREATE TABLE jury_members (id uuid PRIMARY KEY, jury_id uuid, user_id uuid, screening_status text, deleted_at timestamptz)",
    ];
    foreach ($ddl as $sql) { DB::statement($sql); }
}

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/election_journey_fixture.php --run\n"; exit(0); }

$name = 'cga_election_'.date('Ymd').'_'.bin2hex(random_bytes(8));
$nonce = bin2hex(random_bytes(16));
fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;

try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA election_fixture; CREATE TABLE election_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO election_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null;
    bootFixture($name, $nonce);
    createSchema();

    // -------------------------------------------------------------------
    // Doubles — peripheral cycle collaborators only (plan-sanctioned).
    // -------------------------------------------------------------------
    $fakeAudit = new class extends \App\Services\AuditService {
        public function append(string $module, string $event, array $payload, ?string $ref = null, ?string $actorId = null, ?string $jurisdictionId = null, bool $rejected = false, ?string $blockedReason = null): \App\Models\AuditEntry {
            return (new \App\Models\AuditEntry)->forceFill(['module' => $module, 'event' => $event, 'seq' => null, 'hash' => '', 'rejected' => $rejected]);
        }
    };
    $fakeSettings = new class extends \App\Services\SettingsResolver {
        public function resolveInt(string $jurisdictionId, string $column, int $default): int { return $default; }
        public function resolve(string $jurisdictionId, string $column): mixed { return null; }
    };
    $fakeClock = new class($fakeAudit, $fakeSettings) extends \App\Services\ClockService {
        public function arm(string $clockId, ?string $jurisdictionId = null, ?string $subjectType = null, ?string $subjectId = null, ?\DateTimeInterface $firesAt = null, array $payload = []): \App\Models\ClockTimer { return new \App\Models\ClockTimer(); }
        public function cancel(\App\Models\ClockTimer $timer, ?string $reason = null): bool { return true; }
    };
    $fakeReferendum = new class extends \App\Services\ReferendumService {
        public function __construct() {}
        public function attachQueued(Election $election): int { return 0; }
        public function certifyForElection(Election $election, ?string $shieldElectionId): array { return []; }
        public function releaseShields(Election $certified): int { return 0; }
    };
    $fakeVersion = new class extends \App\Services\ConstitutionalVersionService {
        public function derive(): string { return 'vtest'; }
    };
    $fakeAchievements = new class($fakeAudit) extends \App\Services\AchievementService {
        public function awardSelf(User $filer, string $awardKey): bool { return true; }
        public function awardSubject(User $subject, string $awardKey): bool { return true; }
    };
    $fakeExecFormation = new class extends \App\Services\Executive\ExecutiveFormationService {
        public function __construct() {}
        public function closeDelegatedMembersOnTurnover(\App\Models\Legislature $legislature): void {}
    };
    // NoopBallotBoxDelegate: records the voter-linked envelope only (so the
    // double-vote barrier is live), never the sealed ballot pair.
    $ballotBox = new class implements BallotBoxDelegate {
        public function commit(?User $actor, ElectionRace $race, array $rankings): array {
            $envelopeId = (string) \Illuminate\Support\Str::uuid();
            DB::table('ballot_envelopes')->insert([
                'id' => $envelopeId, 'race_id' => (string) $race->id, 'user_id' => (string) $actor->getKey(),
                'kind' => 'ranked', 'committed_at' => now(), 'created_at' => now(),
            ]);
            return ['race_id' => (string) $race->id, 'envelope_id' => $envelopeId];
        }
    };

    $roles = new \App\Services\RoleService();
    $approvals = new ApprovalService($fakeAudit);
    $counter = new VoteCountingService();

    // Container bindings the real handlers/services reach through app().
    app()->instance(\App\Services\AuditService::class, $fakeAudit);
    app()->instance(\App\Services\SettingsResolver::class, $fakeSettings);
    app()->instance(\App\Services\ClockService::class, $fakeClock);
    app()->instance(\App\Services\ReferendumService::class, $fakeReferendum);
    app()->instance(\App\Services\ConstitutionalVersionService::class, $fakeVersion);
    app()->instance(\App\Services\AchievementService::class, $fakeAchievements);
    app()->instance(\App\Services\Executive\ExecutiveFormationService::class, $fakeExecFormation);
    app()->instance(\App\Services\RoleService::class, $roles);
    app()->instance(ApprovalService::class, $approvals);

    // ElectionLifecycleService with the successor/arm cycle methods doubled.
    $lifecycle = new class($fakeAudit, $fakeClock, $fakeSettings, $approvals) extends ElectionLifecycleService {
        public function openSuccessor(Election $certified): Election { return $certified; }
        public function armNextGeneralElection(\App\Models\Legislature $legislature, \Carbon\CarbonInterface $certifiedAt): \App\Models\ClockTimer { return new \App\Models\ClockTimer(); }
    };
    app()->instance(ElectionLifecycleService::class, $lifecycle);

    $certification = new CertificationService($fakeAudit, $fakeClock, $fakeSettings, $lifecycle, $roles);
    app()->instance(CertificationService::class, $certification);

    // Countback recorder: the REAL TabulationRecorder (so begin()/complete()
    // run the state machine + tabulations/tabulation_rounds/race_results
    // persistence), with ONLY countInput() overridden. The sealed BallotBox
    // decrypt is out of scope per the plan, so the re-run reads the same
    // pre-sealed rankings the initial count used — set through inputFactory
    // once the candidacy ids exist. begin()/complete()/audit stay real.
    $cbRecorder = new class($fakeAudit, new \App\Domain\Ballots\BallotBox($fakeAudit)) extends \App\Services\TabulationRecorder {
        /** @var null|\Closure(ElectionRace):CountInput */
        public $inputFactory = null;
        public function countInput(ElectionRace $race): CountInput { return ($this->inputFactory)($race); }
    };
    $vacancies = new VacancyService($fakeAudit, $fakeClock, $fakeSettings, $lifecycle, $certification, $cbRecorder, $counter, $roles);

    // -------------------------------------------------------------------
    // Seed the world (parent P + child CH1; users; legislature; races).
    // -------------------------------------------------------------------
    $P = uid(1);   // legislature jurisdiction (Type A)
    $CH1 = uid(2); // child (Type B per-child)
    $X = uid(3);   // unrelated jurisdiction (C5 wrong-jurisdiction)
    DB::table('jurisdictions')->insert([
        ['id' => $P, 'name' => 'Parentland', 'parent_id' => null],
        ['id' => $CH1, 'name' => 'Childtown', 'parent_id' => $P],
        ['id' => $X, 'name' => 'Elsewhere', 'parent_id' => null],
    ]);

    // Users: C1..C3 resident candidates, C4 nonresident, C5 wrong-jurisdiction,
    // CB Type-B candidate (CH1), V1..V10 residents of P, VB1/VB2 residents of
    // CH1, B1 board member, OAG org agent.
    $mk = function (int $n, string $label) { DB::table('users')->insert(['id' => uid($n), 'name' => $label, 'display_name' => $label, 'is_operator' => false, 'created_at' => now(), 'updated_at' => now()]); return uid($n); };
    $C1 = $mk(11, 'C1'); $C2 = $mk(12, 'C2'); $C3 = $mk(13, 'C3');
    $C4 = $mk(14, 'C4-nonresident'); $C5 = $mk(15, 'C5-wrongjuris');
    $CB = $mk(16, 'CB-typeb');
    $B1 = $mk(17, 'B1-board'); $OAG = $mk(18, 'OA-agent');
    for ($i = 1; $i <= 10; $i++) { $mk(20 + $i, "V$i"); }
    $VB1 = $mk(41, 'VB1'); $VB2 = $mk(42, 'VB2');
    $V = fn (int $i) => uid(20 + $i);

    // Residency confirmations (depth 0 own row; child residents also hold the
    // ancestor P row at depth 1 — the F-IND-006 sweep posture).
    $rc = [];
    $rcRow = function (int $n, string $userId, string $jur, int $depth) use (&$rc) { $rc[] = ['id' => uid($n), 'user_id' => $userId, 'jurisdiction_id' => $jur, 'is_active' => true, 'depth' => $depth, 'created_at' => now(), 'updated_at' => now()]; };
    $seq = 100;
    foreach ([$C1, $C2, $C3] as $u) { $rcRow($seq++, $u, $P, 0); }
    // C4 nonresident: NO confirmation row at all.
    $rcRow($seq++, $C5, $X, 0); // wrong jurisdiction only
    for ($i = 1; $i <= 10; $i++) { $rcRow($seq++, $V($i), $P, 0); }
    // CH1 residents: own row on CH1 (depth 0) + ancestor P (depth 1).
    foreach ([$CB, $VB1, $VB2] as $u) { $rcRow($seq++, $u, $CH1, 0); $rcRow($seq++, $u, $P, 1); }
    DB::table('residency_confirmations')->insert($rc);

    // Legislature (forming) + Type-A district covering P + Type-B panel table.
    $LEG = uid(50); $DIST = uid(51);
    DB::table('legislatures')->insert(['id' => $LEG, 'jurisdiction_id' => $P, 'term_number' => 0, 'status' => 'forming', 'total_seats' => 3, 'type_a_seats' => 2, 'type_b_seats' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('legislature_districts')->insert(['id' => $DIST, 'legislature_id' => $LEG, 'name' => 'District 1', 'seats' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('legislature_district_jurisdictions')->insert(['id' => uid(52), 'district_id' => $DIST, 'jurisdiction_id' => $P, 'created_at' => now(), 'updated_at' => now()]);

    // Election board + seated member B1.
    $BOARD = uid(60);
    DB::table('election_boards')->insert(['id' => $BOARD, 'jurisdiction_id' => $P, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('election_board_members')->insert(['id' => uid(61), 'election_board_id' => $BOARD, 'user_id' => $B1, 'status' => 'seated', 'created_at' => now(), 'updated_at' => now()]);

    // Organization (active) with agent OAG.
    $ORG = uid(70);
    DB::table('organizations')->insert(['id' => $ORG, 'jurisdiction_id' => $P, 'type' => 'political_party', 'name' => 'Blue Party', 'slug' => 'blue', 'is_active' => true, 'is_registered' => true, 'agent_user_id' => $OAG, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    // Election E1 (GENERAL) scheduled, with a Type-A race RA and a Type-B race RB.
    $E1 = uid(80); $RA = uid(81); $RB = uid(82);
    Election::query()->create([
        'id' => $E1, 'jurisdiction_id' => $P, 'legislature_id' => $LEG, 'kind' => Election::KIND_GENERAL,
        'status' => Election::STATUS_SCHEDULED, 'election_board_id' => $BOARD, 'constitutional_version' => 'vtest',
    ]);
    ElectionRace::query()->create(['id' => $RA, 'election_id' => $E1, 'district_id' => $DIST, 'jurisdiction_id' => $P, 'seat_kind' => ElectionRace::SEAT_KIND_TYPE_A, 'seats' => 2, 'finalist_count' => 3, 'electorate_type' => 'residents', 'status' => Election::STATUS_SCHEDULED]);
    ElectionRace::query()->create(['id' => $RB, 'election_id' => $E1, 'jurisdiction_id' => $CH1, 'seat_kind' => ElectionRace::SEAT_KIND_TYPE_B, 'seats' => 1, 'finalist_count' => 2, 'electorate_type' => 'residents', 'status' => Election::STATUS_SCHEDULED]);

    emit(['stage' => 'seeded', 'election' => $E1, 'type_a_race' => $RA, 'type_b_race' => $RB]);

    // -------------------------------------------------------------------
    // Phase: approval opens.
    // -------------------------------------------------------------------
    $lifecycle->openApproval(Election::query()->find($E1));
    check(Election::query()->whereKey($E1)->value('status') === Election::STATUS_APPROVAL_OPEN, 'openApproval did not open the approval phase');

    $candReg = new CandidacyRegistration($roles);
    $actor = fn (string $id) => User::query()->find($id);

    // F-IND-011 x3 (C1,C2,C3 on RA jurisdiction P) — success.
    $candOf = [];
    foreach ([$C1, $C2, $C3] as $u) {
        $res = $candReg->handle($actor($u), ['election_id' => $E1, 'residency_attested' => true, 'position_tags' => []]);
        $candOf[$u] = $res['candidacy_id'];
    }
    // Type-B candidate CB on RB (jurisdiction CH1) — CB is a CH1 resident.
    $res = $candReg->handle($actor($CB), ['election_id' => $E1, 'residency_attested' => true, 'position_tags' => []]);
    $candOf[$CB] = $res['candidacy_id'];
    check(Candidacy::query()->where('election_id', $E1)->count() === 4, 'Expected 4 registered candidacies');
    emit(['stage' => 'registration', 'candidacies' => count($candOf)]);

    // Refusals: nonresident (C4), wrong-jurisdiction (C5).
    refuses('candidacy_nonresident', 'not in your association chain', fn () => $candReg->handle($actor($C4), ['election_id' => $E1, 'residency_attested' => true, 'position_tags' => []]));
    refuses('candidacy_wrong_jurisdiction', 'not in your association chain', fn () => $candReg->handle($actor($C5), ['election_id' => $E1, 'residency_attested' => true, 'position_tags' => []]));
    // Duplicate candidacy (C1 again).
    refuses('candidacy_duplicate', 'one per election', fn () => $candReg->handle($actor($C1), ['election_id' => $E1, 'residency_attested' => true, 'position_tags' => []]));

    // -------------------------------------------------------------------
    // Board validation (simulates F-ELB-002): bind race_id + status VALIDATED.
    // Not a plan-listed form; done directly so approvals/cutoff/ballots have a
    // race-bound validated pool. Recorded as a fixture action, not a run form.
    // -------------------------------------------------------------------
    foreach ([$C1, $C2, $C3] as $u) { Candidacy::query()->whereKey($candOf[$u])->update(['race_id' => $RA, 'status' => Candidacy::STATUS_VALIDATED, 'validated_at' => now(), 'updated_at' => now()]); }
    Candidacy::query()->whereKey($candOf[$CB])->update(['race_id' => $RB, 'status' => Candidacy::STATUS_VALIDATED, 'validated_at' => now(), 'updated_at' => now()]);
    emit(['stage' => 'validated', 'note' => 'race_id bound + VALIDATED (simulated board validation)']);

    // -------------------------------------------------------------------
    // Endorsements: F-CAN-002 (request) -> F-ORG-002 (grant); F-IND-025/026/re-endorse.
    // -------------------------------------------------------------------
    $reqHandler = new EndorsementRequestHandler();
    $reqRes = $reqHandler->handle($actor($C1), ['candidacy_id' => $candOf[$C1], 'organization_id' => $ORG, 'message' => 'seeking endorsement']);
    check(DB::table('endorsement_requests')->where('id', $reqRes['request_id'])->value('status') === 'pending', 'Endorsement request not pending');

    $grantHandler = new CandidateEndorsementGrant($roles);
    $grantRes = $grantHandler->handle($actor($OAG), ['request_id' => $reqRes['request_id'], 'decision' => 'grant']);
    check(($grantRes['decision'] ?? '') === 'granted', 'Org endorsement not granted');
    check(DB::table('endorsements')->where('candidate_id', $candOf[$C1])->where('endorser_type', 'organization')->where('is_active', true)->where('is_public', true)->exists(), 'Org endorsement row not public/active');
    emit(['stage' => 'org_endorsement', 'endorsement' => $grantRes['endorsement_id']]);

    // Wrong-agent refusal on F-ORG-002 (someone other than the org agent).
    $req2 = $reqHandler->handle($actor($C2), ['candidacy_id' => $candOf[$C2], 'organization_id' => $ORG]);
    refuses('org_endorsement_wrong_agent', 'agent of the requested organization', fn () => $grantHandler->handle($actor($C1), ['request_id' => $req2['request_id'], 'decision' => 'grant']));

    // Individual endorsement (V2 public), withdraw (F-IND-026), re-endorse (same row toggled).
    $indHandler = new IndividualEndorsement();
    $wdHandler = new IndividualEndorsementWithdrawal();
    $e1 = $indHandler->handle($actor($V(2)), ['candidacy_id' => $candOf[$C1], 'is_public' => true, 'statement' => 'I support C1']);
    check($e1['is_public'] === true, 'Individual endorsement not public');
    $rowId = $e1['endorsement_id'];
    $wd = $wdHandler->handle($actor($V(2)), ['candidacy_id' => $candOf[$C1]]);
    check($wd['endorsement_id'] === $rowId && $wd['withdrawn'] === true, 'Withdrawal did not toggle the same row');
    check(DB::table('endorsements')->where('id', $rowId)->value('is_active') === false, 'Withdrawn endorsement still active');
    $re = $indHandler->handle($actor($V(2)), ['candidacy_id' => $candOf[$C1], 'is_public' => true]);
    check($re['endorsement_id'] === $rowId && DB::table('endorsements')->where('id', $rowId)->value('is_active') === true, 'Re-endorse did not reactivate the same row');
    emit(['stage' => 'individual_endorsement', 'row' => $rowId, 'toggled' => true]);

    // Own-candidacy endorsement refusal (C1 endorsing own candidacy).
    refuses('endorse_own_candidacy', 'endorse your own candidacy', fn () => $indHandler->handle($actor($C1), ['candidacy_id' => $candOf[$C1], 'is_public' => true]));

    // -------------------------------------------------------------------
    // Approvals: cast/revoke/recast (V3). Standings underpin the finalist cut.
    // -------------------------------------------------------------------
    // Establish an approval order so the finalist ranking is deterministic:
    // C1 gets the most approvals, then C2, then C3.
    $castApproval = function (int $vi, string $candidacyId) use ($approvals, $actor) { $approvals->cast($actor(uid(20 + $vi)), Candidacy::query()->find($candidacyId)); };
    foreach ([1,2,3,4,5,6,7,8,9,10] as $vi) { $castApproval($vi, $candOf[$C1]); }
    foreach ([1,2,3,4,5,6,7,8] as $vi) { $castApproval($vi, $candOf[$C2]); }
    foreach ([1,2,3] as $vi) { $castApproval($vi, $candOf[$C3]); }
    // V3 recast: revoke the C3 approval, then re-cast it (revocation + recast).
    $revoked = $approvals->revoke($actor($V(3)), Candidacy::query()->find($candOf[$C3]));
    check($revoked === true, 'V3 revoke of C3 approval failed');
    check(DB::table('approvals')->where('candidacy_id', $candOf[$C3])->where('user_id', $V(3))->whereNull('revoked_at')->count() === 0, 'Revoked approval still active');
    $recast = $approvals->cast($actor($V(3)), Candidacy::query()->find($candOf[$C3]));
    check(DB::table('approvals')->where('candidacy_id', $candOf[$C3])->where('user_id', $V(3))->whereNull('revoked_at')->count() === 1, 'Recast did not restore one active approval');
    emit(['stage' => 'approvals', 'c1' => 10, 'c2' => 8, 'c3' => 3, 'v3_revoked_then_recast' => true]);

    // -------------------------------------------------------------------
    // Finalist cutoff -> ranked open.
    // -------------------------------------------------------------------
    $lifecycle->applyFinalistCutoff(Election::query()->find($E1));
    check(Election::query()->whereKey($E1)->value('status') === Election::STATUS_FINALIST_CUTOFF, 'Finalist cutoff phase not reached');
    // finalist_count on RA is 3, so all three become finalists (write-in-eligible pool preserved).
    $finalists = Candidacy::query()->where('race_id', $RA)->where('status', Candidacy::STATUS_FINALIST)->count();
    check($finalists === 3, "Expected 3 finalists on RA, got $finalists");
    check(DB::table('approval_standings')->where('race_id', $RA)->where('is_frozen', true)->count() === 3, 'Frozen standings snapshot missing');
    emit(['stage' => 'finalist_cutoff', 'ra_finalists' => $finalists]);

    // Approval after cutoff is refused (window closed).
    refuses('approval_after_cutoff', 'approval phase', fn () => $approvals->cast($actor($V(9)), Candidacy::query()->find($candOf[$C2])));

    $lifecycle->openRanked(Election::query()->find($E1));
    check(Election::query()->whereKey($E1)->value('status') === Election::STATUS_RANKED_OPEN, 'Ranked window not open');

    // -------------------------------------------------------------------
    // Ranked ballots (F-IND-007) x10 on RA. Pre-sealed rankings drive both the
    // real handler (footprint + double-vote + phase gates) and the count.
    // Design: 5x[C1,C2,C3], 4x[C2,C1,C3], 1x[C3,C1,C2] -> C1,C2 win 2 seats.
    // -------------------------------------------------------------------
    $ballotHandler = new BallotSubmission($ballotBox);
    $ra1 = strtolower($candOf[$C1]); $ra2 = strtolower($candOf[$C2]); $ra3 = strtolower($candOf[$C3]);
    $raRankingByVoter = [
        1 => [$ra1, $ra2, $ra3], 2 => [$ra1, $ra2, $ra3], 3 => [$ra1, $ra2, $ra3], 4 => [$ra1, $ra2, $ra3], 5 => [$ra1, $ra2, $ra3],
        6 => [$ra2, $ra1, $ra3], 7 => [$ra2, $ra1, $ra3], 8 => [$ra2, $ra1, $ra3], 9 => [$ra2, $ra1, $ra3],
        10 => [$ra3, $ra1, $ra2],
    ];
    foreach ($raRankingByVoter as $vi => $ranking) {
        $r = $ballotHandler->handle($actor($V($vi)), ['race_id' => $RA, 'rankings' => $ranking]);
        check(isset($r['envelope_id']), "Ballot V$vi produced no envelope");
    }
    check(DB::table('ballot_envelopes')->where('race_id', $RA)->where('kind', 'ranked')->count() === 10, 'Expected 10 ranked envelopes on RA');
    emit(['stage' => 'ranked_ballots', 'ra_ballots' => 10]);

    // Refusal: duplicate ballot (V1 votes RA again).
    refuses('ballot_duplicate', 'one person, one vote', fn () => $ballotHandler->handle($actor($V(1)), ['race_id' => $RA, 'rankings' => [$ra1, $ra2, $ra3]]));
    // Refusal: wrong-race / not in footprint (a P resident voting the CH1 Type-B race RB).
    refuses('ballot_wrong_race', 'does not resolve into this race', fn () => $ballotHandler->handle($actor($V(1)), ['race_id' => $RB, 'rankings' => [strtolower($candOf[$CB])]]));

    // Type-B ballots (RB) cast by CH1 residents so the Type-B chamber can seat.
    foreach ([$VB1, $VB2] as $vb) {
        $r = $ballotHandler->handle($actor($vb), ['race_id' => $RB, 'rankings' => [strtolower($candOf[$CB])]]);
        check(isset($r['envelope_id']), 'Type-B ballot produced no envelope');
    }

    // -------------------------------------------------------------------
    // Close voting; late ballot refused.
    // -------------------------------------------------------------------
    $lifecycle->closeRanked(Election::query()->find($E1));
    check(Election::query()->whereKey($E1)->value('status') === Election::STATUS_VOTING_CLOSED, 'Voting did not close');
    refuses('ballot_late', 'ranked window is not open', fn () => $ballotHandler->handle($actor($V(1)), ['race_id' => $RA, 'rankings' => [$ra1, $ra2, $ra3]]));

    $lifecycle->markTabulating(Election::query()->find($E1));
    check(Election::query()->whereKey($E1)->value('status') === Election::STATUS_TABULATING, 'Election not tabulating');

    // -------------------------------------------------------------------
    // Tabulation — the protected VoteCountingService counting core over the
    // pre-sealed rankings; persist tabulation + race_results per race.
    // -------------------------------------------------------------------
    $persistCount = function (string $raceId, array $candidacyIds, int $seats, array $rankings) use ($counter) {
        $ballots = BallotSet::fromRankings($rankings);
        $in = new CountInput(array_values($candidacyIds), $seats, $ballots, [], hash('sha256', $raceId));
        $result = $counter->countStv($in);
        $tabId = (string) \Illuminate\Support\Str::uuid();
        DB::table('tabulations')->insert(['id' => $tabId, 'race_id' => $raceId, 'kind' => Tabulation::KIND_INITIAL, 'engine_version' => $result->engineVersion, 'total_valid' => $result->totalValid, 'quota' => $result->quota, 'seats' => $seats, 'status' => Tabulation::STATUS_COMPLETE, 'started_at' => now(), 'completed_at' => now(), 'record_hash' => $result->recordHash(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($result->elected as $e) {
            DB::table('race_results')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tabulation_id' => $tabId, 'candidacy_id' => $e['candidacy_id'], 'round_elected' => $e['round'], 'seat_no' => $e['seat_no'], 'vote_share_norm' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
        }
        return [$tabId, $result];
    };
    $raRankings = array_values($raRankingByVoter);
    [$tabRA, $resRA] = $persistCount($RA, [$candOf[$C1], $candOf[$C2], $candOf[$C3]], 2, $raRankings);
    [$tabRB, $resRB] = $persistCount($RB, [$candOf[$CB]], 1, [[strtolower($candOf[$CB])], [strtolower($candOf[$CB])]]);

    $raWinners = array_map(fn ($e) => $e['candidacy_id'], $resRA->elected);
    check(count($raWinners) === 2, 'RA did not elect exactly 2 winners');
    check(in_array($ra1, $raWinners, true) && in_array($ra2, $raWinners, true), 'RA winners are not C1 and C2 (got '.implode(',', $raWinners).')');
    check(! in_array($ra3, $raWinners, true), 'RA wrongly elected C3');
    emit(['stage' => 'tabulation', 'ra_quota' => $resRA->quota, 'ra_winners' => $raWinners, 'record_hash_ra' => substr($resRA->recordHash(), 0, 12)]);

    // -------------------------------------------------------------------
    // Certification (F-ELB-004) — real seating pipeline (GENERAL).
    // -------------------------------------------------------------------
    $certHandler = new ElectionResultsCertification($certification);
    $certRes = $certHandler->handle($actor($B1), ['election_id' => $E1]);
    check(($certRes['races_certified'] ?? 0) === 2, 'Certification did not certify both races');
    check(Election::query()->whereKey($E1)->value('status') === Election::STATUS_CERTIFIED, 'Election not certified');
    emit(['stage' => 'certification', 'certification' => $certRes['certification_id'], 'winners' => count($certRes['winners'] ?? [])]);

    // Assert member/term/role state in BOTH chamber types.
    $seatedIds = array_column($certRes['winners'], 'user_id');
    check(in_array($C1, $seatedIds, true) && in_array($C2, $seatedIds, true), 'C1/C2 not seated by certification');
    $mC1 = LegislatureMember::query()->where('election_id', $E1)->where('user_id', $C1)->first();
    $mC2 = LegislatureMember::query()->where('election_id', $E1)->where('user_id', $C2)->first();
    $mCB = LegislatureMember::query()->where('election_id', $E1)->where('user_id', $CB)->first();
    check($mC1 && $mC1->seat_type === 'a' && $mC1->status === LegislatureMember::STATUS_ELECTED, 'C1 not seated as an elected Type-A member');
    check($mC2 && $mC2->seat_type === 'a', 'C2 not a Type-A member');
    check($mCB && $mCB->seat_type === 'b' && $mCB->status === LegislatureMember::STATUS_ELECTED, 'Type-B winner CB not seated as an elected Type-B member');
    // Term lockstep: fresh window ends 60 months after certification (SettingsResolver double = 60).
    $expectedEnd = \Carbon\CarbonImmutable::now('UTC')->startOfDay()->addMonthsNoOverflow(60)->toDateString();
    $tC1 = Term::query()->whereKey($mC1->term_id)->first();
    check($tC1 && $tC1->term_class === Term::CLASS_LOCKSTEP && $tC1->status === Term::STATUS_ACTIVE, 'C1 term not an active lockstep term');
    check($tC1->ends_on->toDateString() === $expectedEnd, "C1 term end {$tC1->ends_on->toDateString()} != expected fresh window $expectedEnd");
    check((string) $mC1->term_ends_on->toDateString() === $expectedEnd, 'C1 member term_ends_on out of lockstep with term');
    check(Candidacy::query()->whereKey($candOf[$C1])->value('status') === Candidacy::STATUS_ELECTED, 'C1 candidacy not flipped to elected');
    check(Candidacy::query()->whereKey($candOf[$C3])->value('status') === Candidacy::STATUS_DEFEATED, 'C3 candidacy not marked defeated');
    check(Election::query()->whereKey($E1)->value('status') === Election::STATUS_CERTIFIED, 'Election status regressed');
    $legStatus = DB::table('legislatures')->where('id', $LEG)->value('status');
    check($legStatus === 'active', "Legislature not advanced to active (got $legStatus)");
    emit(['stage' => 'seating_asserts', 'type_a' => [$mC1->seat_type, $mC2->seat_type], 'type_b' => $mCB->seat_type, 'term_window_end' => $expectedEnd]);

    // ROLE state — the DERIVED R-09 Legislator (RoleService::rolesFor, a pure
    // function of a current legislature_members row) in BOTH chamber types.
    $roles->flush();
    $rolesC1 = $roles->rolesFor(User::query()->find($C1));
    $rolesCB = $roles->rolesFor(User::query()->find($CB));
    check(in_array('R-09', $rolesC1, true), 'Type-A member C1 did not derive R-09 Legislator (got '.implode(',', $rolesC1).')');
    check(in_array('R-09', $rolesCB, true), 'Type-B member CB did not derive R-09 Legislator (got '.implode(',', $rolesCB).')');
    emit(['stage' => 'role_state', 'c1_roles' => $rolesC1, 'cb_roles' => $rolesCB]);

    // C3-not-seated: before the vacancy, C3 holds no member row.
    check(LegislatureMember::query()->where('user_id', $C3)->doesntExist(), 'C3 unexpectedly holds a seat before the vacancy');
    emit(['refusal' => 'c3_not_seated_pre_vacancy', 'held' => true]);

    // -------------------------------------------------------------------
    // Vacancy + countback continuation THROUGH THE ORCHESTRATION OWNER.
    // VacancyService::runCountback drives the whole continuation: the
    // COUNTBACK_RUNNING state move, the cumulative struck/sitting derivation
    // from member statuses, the TabulationRecorder persistence (begin +
    // complete → tabulations / tabulation_rounds / race_results), the
    // firstEligibleReplacement eligibility gate, and CertificationService::
    // certifyCountback seating with the inherited expiry. ONLY the recorder's
    // countInput is overridden (sealed BallotBox decrypt is out of scope per
    // the plan) so the re-run reads the same pre-sealed rankings the initial
    // count used.
    // -------------------------------------------------------------------
    $c2OriginalEnd = $tC1->ends_on->toDateString(); // same lockstep schedule as C1
    $cbRecorder->inputFactory = fn (ElectionRace $race) => new CountInput(
        [$candOf[$C1], $candOf[$C2], $candOf[$C3]], 2, BallotSet::fromRankings($raRankings), [], hash('sha256', (string) $race->id)
    );

    $vac = $vacancies->declare($mC2->refresh(), 'resigned', null, 'dev', false);
    check(Vacancy::query()->whereKey($vac->id)->value('status') === Vacancy::STATUS_DECLARED, 'Vacancy not declared');
    check(LegislatureMember::query()->whereKey($mC2->id)->value('status') === LegislatureMember::STATUS_VACATED, 'C2 seat not vacated');
    check(Term::query()->whereKey($mC2->term_id)->value('status') === Term::STATUS_VACATED, 'C2 term not vacated');
    emit(['stage' => 'vacancy_declared', 'vacancy' => (string) $vac->id, 'seat' => (string) $mC2->id]);

    $vac = $vacancies->runCountback($vac->refresh());
    // The recorder actually persisted a COMPLETE countback tabulation with C2
    // struck, and its rounds — proof the state machine + recorder path ran.
    $cbTab = Tabulation::query()->where('race_id', $RA)->where('kind', Tabulation::KIND_COUNTBACK)->where('status', Tabulation::STATUS_COMPLETE)->orderByDesc('completed_at')->first();
    check($cbTab !== null && (string) $cbTab->excluded_candidacy_id === (string) $candOf[$C2], 'Countback tabulation not persisted with C2 struck');
    check(DB::table('tabulation_rounds')->where('tabulation_id', $cbTab->id)->exists(), 'Countback rounds not persisted by the recorder');
    check(Vacancy::query()->whereKey($vac->id)->value('status') === Vacancy::STATUS_FILLED, 'runCountback did not fill the vacancy');
    emit(['stage' => 'countback_run', 'tabulation' => (string) $cbTab->id, 'excluded' => (string) $cbTab->excluded_candidacy_id]);

    $mC3 = LegislatureMember::query()->where('user_id', $C3)->first();
    check($mC3 !== null, 'C3 not seated by countback');
    check($mC3->seat_type === 'a' && $mC3->seat_no === $mC2->seat_no, 'C3 did not inherit the vacated Type-A seat number');
    $tC3 = Term::query()->whereKey($mC3->term_id)->first();
    check($tC3->ends_on->toDateString() === $c2OriginalEnd, "C3 replacement term end {$tC3->ends_on->toDateString()} != original expiry $c2OriginalEnd (inheritance broken)");
    check($tC3->starts_on->toDateString() === \Carbon\CarbonImmutable::now('UTC')->startOfDay()->toDateString(), 'C3 replacement term did not start on the countback date');
    check(Candidacy::query()->whereKey($candOf[$C3])->value('status') === Candidacy::STATUS_ELECTED, 'C3 candidacy not elected on countback');
    emit(['stage' => 'countback_seated', 'member' => (string) $mC3->id, 'inherited_end' => $tC3->ends_on->toDateString(), 'original_end' => $c2OriginalEnd]);

    // -------------------------------------------------------------------
    // Special-election continuation: an EXHAUSTED countback. C1 (the last
    // remaining original winner) vacates. The cumulative strike now removes
    // C1 and C2, leaving only C3 — who already sits — so
    // firstEligibleReplacement finds no eligible new winner. runCountback
    // routes to failCountback → ElectionLifecycleService::scheduleSpecial
    // (the real owner), scheduling the KIND_SPECIAL by-election for exactly
    // the one vacant seat inside the constitutional window.
    // -------------------------------------------------------------------
    $mC1now = LegislatureMember::query()->where('election_id', $E1)->where('user_id', $C1)->where('status', LegislatureMember::STATUS_ELECTED)->first();
    check($mC1now !== null, 'C1 not currently seated before the exhausting vacancy');
    $vac2 = $vacancies->declare($mC1now->refresh(), 'resigned', null, 'dev', false);
    $vac2 = $vacancies->runCountback($vac2->refresh());
    $vac2Status = Vacancy::query()->whereKey($vac2->id)->value('status');
    check($vac2Status === Vacancy::STATUS_SPECIAL_SCHEDULED, "Exhausted countback did not schedule a special election (status $vac2Status)");
    $specialId = Vacancy::query()->whereKey($vac2->id)->value('special_election_id');
    check($specialId !== null, 'No special_election_id recorded on the exhausted vacancy');
    $special = Election::query()->find($specialId);
    check($special !== null && $special->kind === Election::KIND_SPECIAL, 'Scheduled continuation is not a special election');
    $specialRaceSeats = ElectionRace::query()->where('election_id', $specialId)->value('seats');
    check((int) $specialRaceSeats === 1, 'Special election race is not for exactly the one vacant seat');
    emit(['stage' => 'special_scheduled', 'vacancy' => (string) $vac2->id, 'special_election' => (string) $specialId, 'kind' => $special->kind, 'race_seats' => (int) $specialRaceSeats]);

    emit(['result' => 'all_pass']);
} catch (Throwable $e) {
    $failures++;
    emit(['failure' => $e->getMessage(), 'class' => get_class($e), 'at' => $e->getFile().':'.$e->getLine()]);
} finally {
    if ($created) {
        try {
            if (Illuminate\Support\Facades\Facade::getFacadeApplication() !== null) DB::purge();
            $fixture = pdo($name); identity($fixture, $name, $nonce); $fixture = null;
            fixtureName($name); $admin->exec('DROP DATABASE "'.$name.'"'); emit(['cleanup' => 'verified_fixture_database_removed']);
        } catch (Throwable $e) { $failures++; emit(['cleanup_failed' => $e->getMessage(), 'database' => $name]); }
    }
}
emit(['failures' => $failures, 'live_world_used' => false]); exit($failures === 0 ? 0 : 1);
