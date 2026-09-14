<?php
/**
 * S1 · judiciary seating — combined journey (review row).
 *
 * Court FORMATION (F-LEG-017, real enactment under pg_advisory_xact_lock) →
 * APPOINTMENTS (F-LEG-037 nomination → F-LEG-021 consent → seat, to `appointed`)
 * → CONVERSION (F-LEG-018 chamber supermajority + constituent dual-supermajority
 * to a carried process). Judged on: appointments rehearsed AFTER an actual
 * created court; conversion carried; PRIOR appointed history preserved and the
 * judge role intact (no seat/term destroyed or backdated by conversion).
 *
 * The two nomination-to-seating paths, actor/state refusals and configured
 * terms are already covered by tests/Unit/JudicialNominationAuthorizationTest;
 * this journey adds only the combined formation→appointment→conversion arc plus
 * the two conversion-stage refusals (foreign-legislator conversion; conversion
 * of a court no longer appointed).
 *
 * Nonce-guarded disposable PostgreSQL, never the world database. Modeled on
 * tests/concurrency/judicial_nomination_migration.php.
 */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Models\Appointment;
use App\Models\AuditEntry;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Election;
use App\Models\Judiciary;
use App\Models\JudicialNomination;
use App\Models\JudicialSeat;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\LegislatureMember;
use App\Models\MultiJurisdictionVote;
use App\Models\Term;
use App\Services\AchievementService;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\Education\TrainingGateService;
use App\Services\Judiciary\JudiciaryFormationService;
use App\Services\Legislature\EloquentCommitteeRoster;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('70000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_judseat_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }

function connectionConfig(string $name): array
{
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);

    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'judseat_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}

function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_judseat_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='30s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='30s'");

    return $pdo;
}

function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM judseat_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
    check($row !== false && $row['db'] === $name && hash_equals($nonce, $row['nonce']), 'Fixture identity check failed');
    check($pdo->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fixture unexpectedly contains public organizations');
}

/**
 * Boot a minimal Laravel application bound to the private fixture, with the
 * REAL judiciary/enactment/vote services and mocked leaves (audit, settings,
 * achievements, training gate, role gate) — the JudicialNominationAuthorizationTest
 * wiring, extended for PostgreSQL and the enactment + conversion services.
 */
function bootFixture(string $name, string $nonce, array &$deps): void
{
    $verify = pdo($name); identity($verify, $name, $nonce); $verify = null;

    $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
    $app->instance('config', new Illuminate\Config\Repository([
        'app' => ['key' => '', 'timezone' => 'UTC'],
        'cache' => ['default' => 'array'],
        'cga' => ['demo_session_capture' => false, 'election_demo_compression' => 0],
        'constitution' => ['vote_types' => require dirname(__DIR__, 2).'/config/constitution/vote_types.php'],
    ]));
    $events = new Illuminate\Events\Dispatcher($app);
    $app->instance('events', $events); $app->instance(Illuminate\Contracts\Events\Dispatcher::class, $events);
    $capsule = new Capsule($app); $capsule->addConnection(connectionConfig($name), 'fixture');
    $capsule->getDatabaseManager()->setDefaultConnection('fixture'); $capsule->setEventDispatcher($events); $capsule->setAsGlobal(); $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('db.schema', $capsule->getConnection('fixture')->getSchemaBuilder());
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    Illuminate\Container\Container::setInstance($app);
    $app->instance(Illuminate\Contracts\Foundation\Application::class, $app);

    $cache = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore);
    $app->instance(Illuminate\Contracts\Cache\Repository::class, $cache);
    $app->instance(Illuminate\Log\Context\Repository::class, new Illuminate\Log\Context\Repository($events));
    $fakeBus = new Illuminate\Support\Testing\Fakes\BusFake(new Illuminate\Bus\Dispatcher($app));
    $app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $fakeBus);

    // Validation factory (JudicialNominationService::propose validates input).
    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en');
    $app->instance('translator', $translator);
    $validationFactory = new Illuminate\Validation\Factory($translator, $app);
    $app->instance('validator', $validationFactory);
    $app->instance(Illuminate\Validation\Factory::class, $validationFactory);
    $app->instance(Illuminate\Contracts\Validation\Factory::class, $validationFactory);

    foreach (["SET statement_timeout='30s'", "SET lock_timeout='8s'", "SET idle_in_transaction_session_timeout='30s'"] as $setting) {
        DB::statement($setting);
    }
    identity(DB::connection()->getPdo(), $name, $nonce);
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'judseat_fixture', 'Unexpected schema/search path');

    // ── Mocked leaves (hand-rolled, signature-compatible doubles) ────────────
    $auditDouble = new class extends AuditService {
        public function __construct() {}
        public function append(string $module, string $event, array $payload = [], ?string $ref = null, ?string $actorId = null, ?string $jurisdictionId = null, bool $rejected = false, ?string $blockedReason = null): AuditEntry
        { return (new AuditEntry)->forceFill(['seq' => 1]); }
        public function isBatching(): bool { return false; }
    };

    $settingsDouble = new class extends SettingsResolver {
        public function __construct() {}
        public function resolveInt(string $jurisdictionId, string $column, int $default = 0): int
        {
            return match ($column) {
                'supermajority_numerator' => 2,
                'supermajority_denominator' => 3,
                'judicial_appointment_years' => 10,
                'civil_appointment_years' => 10,
                'finalist_multiplier' => 3,
                'approval_min_days' => 30,
                default => $default,
            };
        }
        public function resolve(string $jurisdictionId, string $column): mixed
        { return $column === 'voting_method' ? 'stv_droop' : null; }
    };

    $achievementsDouble = new class extends AchievementService {
        public function __construct() {}
        public function awardState(\App\Models\User $holder, string $awardKey): bool { return true; }
    };

    $roleGate = new class implements ResolvesRoles {
        public function rolesFor(?\App\Models\User $user): array { return ['R-01', 'R-09', 'R-10', 'R-11']; }
    };

    $trainingGate = new class extends TrainingGateService {
        public function __construct() {}
        public function assertMayAct(?\App\Models\User $actor, string $canonicalId): void {}
    };

    $app->instance(AuditService::class, $auditDouble);
    $app->instance(SettingsResolver::class, $settingsDouble);
    $app->instance(AchievementService::class, $achievementsDouble);
    $app->instance(CommitteeRoster::class, new EloquentCommitteeRoster);

    $engine = new ConstitutionalEngine($auditDouble, new App\Services\ConstitutionalValidator, $roleGate, $trainingGate);

    $deps = [
        'app' => $app,
        'engine' => $engine,
        'votes' => $app->make(ChamberVoteService::class),
        'roles' => $app->make(RoleService::class),
    ];
}

/** Explicit typed DDL — the tables the journey touches, correct PostgreSQL types. */
function createSchema(): void
{
    $t = static function (string $col): string {
        // suffix-driven typing
        return $col;
    };

    $tables = [
        'jurisdictions' => ['id TEXT PRIMARY KEY', 'name TEXT', 'parent_id TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'legislatures' => ['id TEXT PRIMARY KEY', 'jurisdiction_id TEXT', 'term_number INT', 'term_starts_on DATE', 'term_ends_on DATE', 'status TEXT',
            'total_seats INT', 'type_a_seats INT', 'type_b_seats INT', 'type_b_rep_floor INT', 'type_b_needs_districting BOOLEAN', 'speaker_id TEXT',
            'quorum_required INT', 'last_met_on DATE', 'next_meeting_due_by DATE', 'parent_legislature_id TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'legislature_members' => ['id TEXT PRIMARY KEY', 'legislature_id TEXT', 'user_id TEXT', 'seat_type TEXT', 'seat_no INT', 'district_id TEXT',
            'elected_in_race_id TEXT', 'term_id TEXT', 'election_id TEXT', 'vote_share_norm NUMERIC', 'seated_on DATE', 'seated_at TIMESTAMPTZ',
            'term_ends_on DATE', 'status TEXT', 'vacated_at TIMESTAMPTZ', 'vacancy_reason TEXT', 'home_jurisdiction_id TEXT', 'is_speaker BOOLEAN',
            'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'users' => ['id TEXT PRIMARY KEY', 'name TEXT', 'display_name TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'residency_confirmations' => ['user_id TEXT', 'jurisdiction_id TEXT', 'is_active BOOLEAN'],
        'judiciaries' => ['id TEXT PRIMARY KEY', 'jurisdiction_id TEXT', 'court_name TEXT', 'type TEXT', 'min_judges INT', 'term_years INT', 'status TEXT',
            'parent_judiciary_id TEXT', 'creation_law_id TEXT', 'nomination_mode TEXT', 'conversion_process_id TEXT', 'conversion_law_id TEXT',
            'converted_at TIMESTAMPTZ', 'judge_count INT', 'source_legislature_id TEXT', 'judicial_committee_id TEXT', 'judicial_committee_vote_id TEXT',
            'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'judicial_seats' => ['id TEXT PRIMARY KEY', 'judiciary_id TEXT', 'user_id TEXT', 'seat_number INT', 'seat_class TEXT', 'nominating_jurisdiction_id TEXT',
            'appointment_id TEXT', 'elected_in_race_id TEXT', 'term_id TEXT', 'term_starts_on DATE', 'term_ends_on DATE', 'status TEXT',
            'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'judicial_nominations' => ['id TEXT PRIMARY KEY', 'judiciary_id TEXT', 'seat_id TEXT', 'mode TEXT', 'nominating_jurisdiction_id TEXT', 'nominee_user_id TEXT',
            'appointment_id TEXT', 'dossier_record_id TEXT', 'status TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'appointments' => ['id TEXT PRIMARY KEY', 'appointable_type TEXT', 'appointable_id TEXT', 'nominee_user_id TEXT', 'nominated_by TEXT', 'nominated_via_form TEXT',
            'consent_vote_id TEXT', 'status TEXT', 'term_id TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'terms' => ['id TEXT PRIMARY KEY', 'office_kind TEXT', 'office_type TEXT', 'office_id TEXT', 'holder_user_id TEXT', 'jurisdiction_id TEXT', 'legislature_id TEXT',
            'term_class TEXT', 'starts_on DATE', 'ends_on DATE', 'source_election_id TEXT', 'source_appointment_id TEXT', 'status TEXT',
            'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'laws' => ['id TEXT PRIMARY KEY', 'jurisdiction_id TEXT', 'legislature_id TEXT', 'act_number TEXT', 'title TEXT', 'kind TEXT', 'scale JSONB',
            'scope_judiciary_id TEXT', 'origin TEXT', 'enacting_bill_id TEXT', 'origin_ref_type TEXT', 'origin_ref_id TEXT', 'referendum_passed_by_supermajority BOOLEAN',
            'shield_expires_with_election_id TEXT', 'status TEXT', 'current_version_no INT', 'effective_at TIMESTAMPTZ', 'enacted_at TIMESTAMPTZ',
            'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'law_versions' => ['id TEXT PRIMARY KEY', 'law_id TEXT', 'version_no INT', 'text TEXT', 'text_hash TEXT', 'source TEXT', 'source_ref_type TEXT',
            'source_ref_id TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'chamber_votes' => ['id TEXT PRIMARY KEY', 'body_type TEXT', 'body_id TEXT', 'legislature_id TEXT', 'jurisdiction_id TEXT', 'votable_type TEXT', 'votable_id TEXT',
            'vote_type TEXT', 'vote_method TEXT', 'threshold_basis TEXT', 'stage TEXT', 'bicameral BOOLEAN', 'serving_snapshot INT', 'held_in_session_id TEXT',
            'opened_by_member_id TEXT', 'opened_at TIMESTAMPTZ', 'closes_at TIMESTAMPTZ', 'decided_at TIMESTAMPTZ', 'outcome TEXT', 'speaker_tiebreak BOOLEAN',
            'rcv_record JSONB', 'status TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'chamber_vote_tallies' => ['id TEXT PRIMARY KEY', 'vote_id TEXT', 'lane TEXT', 'serving INT', 'quorum_required INT', 'required_yes INT', 'present INT',
            'yes INT DEFAULT 0', 'no INT DEFAULT 0', 'abstain INT DEFAULT 0', 'quorate BOOLEAN', 'passed BOOLEAN', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'chamber_vote_proposals' => ['id TEXT PRIMARY KEY', 'legislature_id TEXT', 'proposal_kind TEXT', 'vote_id TEXT', 'payload JSONB', 'proposed_by_member_id TEXT',
            'status TEXT', 'decided_at TIMESTAMPTZ', 'result_type TEXT', 'result_id TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'vote_casts' => ['id TEXT PRIMARY KEY', 'vote_id TEXT', 'member_id TEXT', 'board_seat_id TEXT', 'lane TEXT', 'value TEXT', 'rankings JSONB', 'is_tiebreak BOOLEAN DEFAULT FALSE',
            'explanation TEXT', 'cast_via_form TEXT', 'public_record_id TEXT', 'cast_at TIMESTAMPTZ', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'multi_jurisdiction_votes' => ['id TEXT PRIMARY KEY', 'kind TEXT', 'subject_type TEXT', 'subject_id TEXT', 'initiating_legislature_id TEXT', 'initiating_vote_id TEXT',
            'basis TEXT', 'constituent_total INT', 'required INT', 'yes_count INT DEFAULT 0', 'no_count INT DEFAULT 0', 'status TEXT', 'opens_at TIMESTAMPTZ', 'closes_at TIMESTAMPTZ',
            'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'constituent_consents' => ['id TEXT PRIMARY KEY', 'process_id TEXT', 'jurisdiction_id TEXT', 'legislature_id TEXT', 'chamber_vote_id TEXT', 'result TEXT',
            'decided_at TIMESTAMPTZ', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'elections' => ['id TEXT PRIMARY KEY', 'jurisdiction_id TEXT', 'legislature_id TEXT', 'kind TEXT', 'status TEXT', 'trigger TEXT', 'voting_method TEXT',
            'district_map_id TEXT', 'election_board_id TEXT', 'approval_opens_at TIMESTAMPTZ', 'finalist_cutoff_at TIMESTAMPTZ', 'ranked_opens_at TIMESTAMPTZ', 'ranked_closes_at TIMESTAMPTZ',
            'certified_at TIMESTAMPTZ', 'prior_election_id TEXT', 'general_cycle_election_id TEXT', 'triggered_by_timer_id TEXT', 'vacancy_id TEXT', 'ballot_key_wrapped TEXT',
            'board_id TEXT', 'executive_id TEXT', 'judiciary_id TEXT', 'constitutional_version TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'election_races' => ['id TEXT PRIMARY KEY', 'election_id TEXT', 'district_id TEXT', 'jurisdiction_id TEXT', 'seat_kind TEXT', 'seats INT', 'finalist_count INT',
            'quota INT', 'total_valid_ballots INT', 'electorate_type TEXT', 'clump_key TEXT', 'status TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'election_boards' => ['id TEXT PRIMARY KEY', 'jurisdiction_id TEXT', 'legislature_id TEXT', 'is_bootstrap BOOLEAN', 'status TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'clocks' => ['id TEXT PRIMARY KEY', 'name TEXT', 'type TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'clock_timers' => ['id TEXT PRIMARY KEY', 'clock_id TEXT', 'jurisdiction_id TEXT', 'subject_type TEXT', 'subject_id TEXT', 'armed_at TIMESTAMPTZ', 'fires_at TIMESTAMPTZ',
            'state TEXT', 'payload JSONB', 'override_value JSONB', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
        'public_records' => ['seq BIGSERIAL', 'id TEXT PRIMARY KEY', 'kind TEXT', 'title TEXT', 'body TEXT', 'actor_user_id TEXT', 'actor_display TEXT', 'jurisdiction_id TEXT',
            'legislature_id TEXT', 'via_form TEXT', 'via_workflow TEXT', 'via_clock TEXT', 'subject_type TEXT', 'subject_id TEXT', 'audit_seq INT', 'translations JSONB',
            'supersedes_record_id TEXT', 'published_at TIMESTAMPTZ', 'created_at TIMESTAMPTZ'],
        'instance_settings' => ['id TEXT', 'instance_name TEXT', 'instance_class TEXT', 'created_at TIMESTAMPTZ', 'updated_at TIMESTAMPTZ', 'deleted_at TIMESTAMPTZ'],
    ];

    foreach ($tables as $name => $cols) {
        DB::statement('CREATE TABLE '.$name.' ('.implode(', ', $cols).')');
    }
}

/**
 * Seed a constituent-mode world: root jurisdiction J0 with its creating chamber
 * L0 (7 members), five direct child constituents J1..J5 each with a legislature
 * L1..L5 (3 members each), a judiciary in J0 awaiting creation (forming,
 * min_judges 5), and five residents of J0 to be seated as judges.
 *
 * @return array{root:string, source_leg:string, constituents:array<int,array{j:string,leg:string}>, nominees:array<int,string>, judiciary:string, foreign_leg:string}
 */
function seedWorld(): array
{
    $now = now();
    DB::table('instance_settings')->insert(['id' => uid(900), 'instance_name' => 'Judiciary seating fixture', 'instance_class' => 'production', 'created_at' => $now, 'updated_at' => $now]);
    foreach (['CLK-09' => 'Civil/judicial term expiry', 'CLK-18' => 'Finalist cutoff', 'CLK-01' => 'Ranked window'] as $id => $label) {
        DB::table('clocks')->insert(['id' => $id, 'name' => $label, 'type' => 'countdown', 'created_at' => $now, 'updated_at' => $now]);
    }

    // Root jurisdiction + creating chamber.
    DB::table('jurisdictions')->insert(['id' => uid(1), 'name' => 'Court place', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('legislatures')->insert(['id' => uid(10), 'jurisdiction_id' => uid(1), 'status' => 'active', 'total_seats' => 7, 'type_a_seats' => 7, 'type_b_seats' => 0, 'created_at' => $now, 'updated_at' => $now]);

    $memberSeq = 100;
    $userSeq = 500;
    $sourceMembers = [];
    for ($i = 0; $i < 7; $i++) {
        DB::table('users')->insert(['id' => uid($userSeq), 'name' => 'User '.$userSeq, 'display_name' => 'Rep '.$userSeq, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('legislature_members')->insert(['id' => uid($memberSeq), 'legislature_id' => uid(10), 'user_id' => uid($userSeq), 'seat_type' => 'a', 'status' => 'seated', 'created_at' => $now, 'updated_at' => $now]);
        $sourceMembers[] = ['member' => uid($memberSeq), 'user' => uid($userSeq)];
        $memberSeq++; $userSeq++;
    }

    // Five constituents J1..J5, each a direct child of J0 with its own chamber.
    $constituents = [];
    for ($c = 1; $c <= 5; $c++) {
        $jid = uid($c + 1);           // J1..J5 => uid(2)..uid(6)
        $leg = uid(10 + $c);          // L1..L5 => uid(11)..uid(15)
        DB::table('jurisdictions')->insert(['id' => $jid, 'name' => 'Constituent '.$c, 'parent_id' => uid(1), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('legislatures')->insert(['id' => $leg, 'jurisdiction_id' => $jid, 'status' => 'active', 'total_seats' => 3, 'type_a_seats' => 3, 'type_b_seats' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $members = [];
        for ($i = 0; $i < 3; $i++) {
            DB::table('users')->insert(['id' => uid($userSeq), 'name' => 'User '.$userSeq, 'display_name' => 'Rep '.$userSeq, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('legislature_members')->insert(['id' => uid($memberSeq), 'legislature_id' => $leg, 'user_id' => uid($userSeq), 'seat_type' => 'a', 'status' => 'seated', 'created_at' => $now, 'updated_at' => $now]);
            $members[] = ['member' => uid($memberSeq), 'user' => uid($userSeq)];
            $memberSeq++; $userSeq++;
        }
        $constituents[$c] = ['j' => $jid, 'leg' => $leg, 'members' => $members];
    }

    // Five nominees, residents of the COURT jurisdiction J0 (Art. I association).
    $nominees = [];
    for ($n = 1; $n <= 5; $n++) {
        DB::table('users')->insert(['id' => uid($userSeq), 'name' => 'Judge '.$n, 'display_name' => 'Judge '.$n, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('residency_confirmations')->insert(['user_id' => uid($userSeq), 'jurisdiction_id' => uid(1), 'is_active' => true]);
        $nominees[$n] = uid($userSeq);
        $userSeq++;
    }

    // A wholly foreign jurisdiction + chamber + member (unrelated to J0).
    DB::table('jurisdictions')->insert(['id' => uid(800), 'name' => 'Foreign place', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('legislatures')->insert(['id' => uid(810), 'jurisdiction_id' => uid(800), 'status' => 'active', 'total_seats' => 3, 'type_a_seats' => 3, 'type_b_seats' => 0, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('users')->insert(['id' => uid(820), 'name' => 'Foreign rep', 'display_name' => 'Foreign rep', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('legislature_members')->insert(['id' => uid(821), 'legislature_id' => uid(810), 'user_id' => uid(820), 'seat_type' => 'a', 'status' => 'seated', 'created_at' => $now, 'updated_at' => $now]);

    // The judiciary awaiting creation (forming). Mode is DERIVED at adoption.
    DB::table('judiciaries')->insert(['id' => uid(50), 'jurisdiction_id' => uid(1), 'court_name' => 'Civic court', 'type' => 'appointed', 'min_judges' => 5,
        'status' => 'forming', 'source_legislature_id' => uid(10), 'created_at' => $now, 'updated_at' => $now]);

    return [
        'root' => uid(1), 'source_leg' => uid(10), 'source_members' => $sourceMembers,
        'constituents' => $constituents, 'nominees' => $nominees, 'judiciary' => uid(50),
        'foreign_leg' => uid(810), 'foreign_user' => uid(820),
    ];
}

/** Cast every serving member of $legId 'yes' on $voteId; the vote auto-closes and dispatches. */
function passVote(array $deps, string $voteId, string $legId): void
{
    $vote = ChamberVote::query()->findOrFail($voteId);
    $members = LegislatureMember::query()->where('legislature_id', $legId)->get();
    $deps['votes']->castManyYes($vote, $members);
}

function refused(callable $action, string $label): void
{
    try {
        $action();
        throw new RuntimeException("Expected constitutional refusal: {$label}");
    } catch (ConstitutionalViolation $e) {
        check($e->getMessage() !== '', "Empty refusal message: {$label}");
        emit(['refusal_ok' => $label, 'message' => $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/judiciary_seating_journey.php --run\n"; exit(0); }

$name = 'cga_judseat_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0; $deps = [];

try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA judseat_fixture; SET search_path TO judseat_fixture; CREATE TABLE judseat_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO judseat_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null;

    bootFixture($name, $nonce, $deps);
    createSchema();
    $w = seedWorld();
    $engine = $deps['engine'];

    // ── STEP 1: actual court CREATION (F-LEG-017, real enactment) ────────────
    check(Judiciary::findOrFail($w['judiciary'])->status === 'forming', 'Judiciary should start forming');
    $out = $engine->file('F-LEG-017', \App\Models\User::findOrFail($w['source_members'][0]['user']), [
        'legislature_id' => $w['source_leg'], 'jurisdiction_id' => $w['root'],
        'court_name' => 'Civic court', 'function_text' => 'Hears civic disputes for the court place.',
        'judges_per_constituent' => 1,
    ]);
    passVote($deps, $out->recorded['vote_id'], $w['source_leg']);

    $court = Judiciary::findOrFail($w['judiciary']);
    check($court->status === 'creating', 'After F-LEG-017 adoption the court must be creating, got '.$court->status);
    check($court->nomination_mode === 'constituent', 'Mode must derive constituent, got '.(string) $court->nomination_mode);
    check((int) $court->judge_count === 5, 'judge_count must be 5 (1 per 5 constituents), got '.$court->judge_count);
    check($court->creation_law_id !== null, 'Creation charter law must be recorded');
    $creationLaw = Law::findOrFail($court->creation_law_id);
    check($creationLaw->status === 'in_force' && (string) $creationLaw->scope_judiciary_id === (string) $court->id, 'Charter law in force + scoped to court');
    check(str_starts_with((string) $creationLaw->act_number, 'Act '), 'Charter law carries a pg-locked act number: '.$creationLaw->act_number);
    check(LawVersion::where('law_id', $creationLaw->id)->where('version_no', 1)->exists(), 'Charter law version v1 present');
    $vacant = JudicialSeat::where('judiciary_id', $court->id)->get();
    check($vacant->count() === 5, 'Five seats allocated, got '.$vacant->count());
    check($vacant->every(fn ($s) => $s->status === 'vacant'), 'All allocated seats vacant');
    $byConstituent = $vacant->groupBy('nominating_jurisdiction_id')->map->count();
    check($byConstituent->every(fn ($n) => $n === 1) && $byConstituent->count() === 5, 'Exactly one seat per constituent (equal invariant)');
    emit(['step' => 'court_created', 'judiciary' => (string) $court->id, 'seats' => 5, 'charter_act' => $creationLaw->act_number]);

    // ── STEP 2: APPOINTMENTS after the actual court (F-LEG-037 → F-LEG-021) ──
    foreach ($w['constituents'] as $c => $con) {
        $seat = JudicialSeat::where('judiciary_id', $court->id)->where('nominating_jurisdiction_id', $con['j'])->firstOrFail();
        $nomineeUser = $w['nominees'][$c];
        // Constituent chamber authorizes the nomination (F-LEG-037, majority).
        $out = $engine->file('F-LEG-037', \App\Models\User::findOrFail($con['members'][0]['user']), [
            'judiciary_id' => (string) $court->id, 'legislature_id' => $con['leg'], 'jurisdiction_id' => $con['j'],
            'seat_id' => (string) $seat->id, 'nominee_user_id' => $nomineeUser, 'statement' => 'Nominating constituent '.$c.' judge.',
        ]);
        passVote($deps, $out->recorded['vote_id'], $con['leg']);
        check(JudicialSeat::findOrFail($seat->id)->status === 'nominated', 'Seat '.$c.' must be nominated after authorization');
        // Source chamber consent (F-LEG-021 via bog_consent, majority) → seat.
        $appt = Appointment::where('appointable_type', 'judicial_seats')->where('appointable_id', (string) $seat->id)
            ->where('status', 'nominated')->orderByDesc('created_at')->firstOrFail();
        passVote($deps, (string) $appt->consent_vote_id, $w['source_leg']);
        $seatted = JudicialSeat::findOrFail($seat->id);
        check($seatted->status === 'seated', 'Seat '.$c.' must be seated after consent, got '.$seatted->status);
        check((string) $seatted->user_id === (string) $nomineeUser, 'Seat '.$c.' holder is the nominee');
        check($seatted->term_id !== null, 'Seat '.$c.' has a term');
    }

    $court->refresh();
    check($court->status === 'appointed', 'After every seat consents the court advances to appointed, got '.$court->status);
    $seatedCount = JudicialSeat::where('judiciary_id', $court->id)->where('status', 'seated')->count();
    check($seatedCount === 5, 'Five seated judges, got '.$seatedCount);
    $terms = Term::where('office_type', 'judicial_seats')->where('term_class', 'civil_appointment')->get();
    check($terms->count() === 5, 'Five civil-appointment terms, got '.$terms->count());
    $expectedEnd = \Carbon\CarbonImmutable::now('UTC')->startOfDay()->addYears(10)->toDateString();
    check($terms->every(fn ($t) => $t->ends_on->toDateString() === $expectedEnd), 'Each term ends 10 years out (configured judicial term)');
    emit(['step' => 'court_appointed', 'seated' => $seatedCount, 'term_end' => $expectedEnd]);

    // Snapshot the appointed history BEFORE conversion (id => [status,user,term,starts,ends]).
    $preSeats = JudicialSeat::where('judiciary_id', $court->id)->get()
        ->mapWithKeys(fn ($s) => [(string) $s->id => [
            'status' => $s->status, 'user' => (string) $s->user_id, 'term' => (string) $s->term_id,
            'starts' => $s->term_starts_on?->toDateString(), 'ends' => $s->term_ends_on?->toDateString(),
        ]])->all();
    $preTerms = Term::where('office_type', 'judicial_seats')->get()
        ->mapWithKeys(fn ($t) => [(string) $t->id => [
            'status' => $t->status, 'starts' => $t->starts_on?->toDateString(), 'ends' => $t->ends_on?->toDateString(),
        ]])->all();
    $preApptSeated = Appointment::where('appointable_type', 'judicial_seats')->where('status', 'seated')->count();

    // Role derivation for a seated judge (the real RoleService pure function).
    $roleMethod = new ReflectionMethod(RoleService::class, 'hasJudicialSeat');
    $roleMethod->setAccessible(true);
    $roleService = $deps['roles'];
    $sampleJudge = (string) JudicialSeat::where('judiciary_id', $court->id)->where('status', 'seated')->firstOrFail()->user_id;
    check($roleMethod->invoke($roleService, $sampleJudge, 'appointed') === true, 'Seated judge derives the appointed-judge role (R-19) before conversion');
    check($roleMethod->invoke($roleService, $w['foreign_user'], 'appointed') === false, 'A non-judge derives no appointed-judge role');

    // ── Conversion-stage REFUSALS (not covered by the EO-4 nomination checks) ─
    // Foreign legislator cannot file the creating chamber's conversion act.
    refused(fn () => $engine->file('F-LEG-018', \App\Models\User::findOrFail($w['foreign_user']), [
        'legislature_id' => $w['source_leg'], 'jurisdiction_id' => $w['root'], 'judge_count' => 5, 'charter_text' => 'x',
    ]), 'foreign legislator conversion');

    // ── STEP 3: CONVERSION (F-LEG-018 chamber supermajority + constituent dual) ─
    $out = $engine->file('F-LEG-018', \App\Models\User::findOrFail($w['source_members'][0]['user']), [
        'legislature_id' => $w['source_leg'], 'jurisdiction_id' => $w['root'], 'judge_count' => 5,
        'charter_text' => 'Convert the civic court to an elected bench of five.',
    ]);
    passVote($deps, $out->recorded['vote_id'], $w['source_leg']);

    $court->refresh();
    check($court->status === 'conversion_voted', 'After F-LEG-018 chamber adoption the court is conversion_voted, got '.$court->status);
    check($court->conversion_process_id !== null, 'A constituent dual-supermajority process opened');
    check($court->conversion_law_id !== null && (string) $court->conversion_law_id !== (string) $court->creation_law_id, 'Conversion enacts a NEW charter law, distinct from creation');
    $process = MultiJurisdictionVote::findOrFail($court->conversion_process_id);
    check($process->status === 'open' && (int) $process->constituent_total === 5, 'Open process over 5 constituents');
    $required = (int) $process->required;
    check($required === 4, 'Supermajority of 5 constituents is 4, got '.$required);

    // Each constituent chamber consents (F-LEG-018 open_constituent_consent → majority).
    $consented = 0;
    foreach ($w['constituents'] as $c => $con) {
        if ($consented >= $required) { break; }
        $out = $engine->file('F-LEG-018', \App\Models\User::findOrFail($con['members'][0]['user']), [
            'action' => 'open_constituent_consent', 'process_id' => (string) $process->id,
            'legislature_id' => $con['leg'], 'jurisdiction_id' => $con['j'],
        ]);
        passVote($deps, $out->recorded['consent_vote_id'], $con['leg']);
        $consented++;
    }

    $process->refresh();
    check($process->status === 'passed', 'The constituent dual-supermajority carried, got '.$process->status);
    check((int) $process->yes_count >= $required, 'yes_count reached the required supermajority');
    $election = Election::where('judiciary_id', $court->id)->where('kind', 'judicial')->first();
    check($election !== null, 'A judicial election is scheduled on the carried conversion');
    check($election->status === 'approval_open', 'Scheduled judicial election opened its approval phase, got '.(string) $election?->status);
    emit(['step' => 'conversion_carried', 'process' => (string) $process->id, 'yes' => (int) $process->yes_count, 'election' => (string) $election->id]);

    // ── STEP 4: PRIOR HISTORY PRESERVED + CORRECT ROLES after conversion ─────
    $court->refresh();
    check($court->type === 'appointed' && $court->status === 'conversion_voted', 'The court keeps its appointed footing through conversion (elected only after the election certifies)');

    $postSeats = JudicialSeat::where('judiciary_id', $court->id)->get()
        ->mapWithKeys(fn ($s) => [(string) $s->id => [
            'status' => $s->status, 'user' => (string) $s->user_id, 'term' => (string) $s->term_id,
            'starts' => $s->term_starts_on?->toDateString(), 'ends' => $s->term_ends_on?->toDateString(),
        ]])->all();
    check($postSeats == $preSeats, 'Every appointed seat row is preserved unchanged across conversion (no destroy, no re-key, no backdate)');

    $postTerms = Term::where('office_type', 'judicial_seats')->get()
        ->mapWithKeys(fn ($t) => [(string) $t->id => [
            'status' => $t->status, 'starts' => $t->starts_on?->toDateString(), 'ends' => $t->ends_on?->toDateString(),
        ]])->all();
    check($postTerms == $preTerms, 'Every judicial term is preserved unchanged (status/starts/ends), none backdated');
    check(Term::where('office_type', 'judicial_seats')->min('starts_on') === Term::where('office_type', 'judicial_seats')->max('starts_on'), 'Term start dates unmoved');

    check(Appointment::where('appointable_type', 'judicial_seats')->where('status', 'seated')->count() === $preApptSeated, 'Seated appointment rows preserved');

    // Original charter law preserved; conversion is a separate append.
    check(Law::whereKey($court->creation_law_id)->firstOrFail()->status === 'in_force', 'Original creation charter still in force');
    check(Law::count() === 2, 'Exactly two charter laws (creation + conversion), got '.Law::count());
    check(LawVersion::where('law_id', $court->creation_law_id)->count() === 1
        && LawVersion::where('law_id', $court->conversion_law_id)->count() === 1, 'Both charters keep their own v1 history');

    // Roles: seated judges still derive R-19 (court still appointed-typed).
    check($roleMethod->invoke($roleService, $sampleJudge, 'appointed') === true, 'Seated judge still derives the appointed-judge role after conversion');
    check($roleMethod->invoke($roleService, $sampleJudge, 'elected') === false, 'No elected-judge role yet (the elected bench is unseated)');
    emit(['step' => 'history_preserved', 'seats_unchanged' => count($postSeats), 'terms_unchanged' => count($postTerms), 'laws' => Law::count()]);

    // ── Conversion no longer available once the court has left appointed ─────
    refused(fn () => $engine->file('F-LEG-018', \App\Models\User::findOrFail($w['source_members'][0]['user']), [
        'legislature_id' => $w['source_leg'], 'jurisdiction_id' => $w['root'], 'judge_count' => 5, 'charter_text' => 'again',
    ]), 'conversion of a court no longer appointed');

    emit(['journey' => 'judiciary_seating', 'passed' => true]);
} catch (Throwable $e) {
    $failures++;
    emit(['failure' => $e->getMessage(), 'class' => $e::class, 'at' => $e->getFile().':'.$e->getLine()]);
} finally {
    if ($created) {
        try {
            if (Illuminate\Support\Facades\Facade::getFacadeApplication() !== null) { DB::purge(); }
            $fixture = pdo($name); identity($fixture, $name, $nonce); $fixture = null;
            fixtureName($name); $admin->exec('DROP DATABASE "'.$name.'"'); emit(['cleanup' => 'verified_fixture_database_removed']);
        } catch (Throwable $e) { $failures++; emit(['cleanup_failed' => $e->getMessage(), 'database' => $name]); }
    }
}

emit(['failures' => $failures, 'live_world_used' => false]);
exit($failures === 0 ? 0 : 1);
