<?php
/**
 * S1 · challenges — the three Art. IV §5 challenge OUTCOMES, end to end on a
 * nonce-guarded disposable PostgreSQL database (never the world database).
 *
 * The review register row (IO-3): "All three constitutional challenge outcomes.
 * Pass criterion: findings/recommendations lead to legislative response, valid
 * override or permitted direct remedy. Preserve law versions and clock rules;
 * refuse premature actions and repeated application after legislative remedy or
 * override."
 *
 * Every outcome is driven through the REAL ConstitutionalEngine + the real
 * judiciary/enactment services (no mocked domain logic):
 *
 *   Path 3 (permitted direct remedy, LAW C? no — LAW A):
 *     F-IND-016 (inhabitant) -> case heard -> F-JDG-004 finding ->
 *     F-JDG-005 recommend (arms CLK-11 + CLK-12) -> [premature + wrong-actor
 *     refused while the windows are open] -> Carbon::setTestNow past both windows
 *     -> F-JDG-006 -> EnactmentService::amendLaw appends a law_versions row
 *     source='judicial_remedy'; v1 preserved. Repeated F-JDG-006 refused.
 *
 *   Path 2 (valid override, LAW B):
 *     ... -> F-JDG-005 -> F-LEG-035 (member) opens the judiciary_override vote ->
 *     supermajority adopts -> the finding is overruled, the law stands UNCHANGED
 *     (no law_version appended), timers cancelled. Repeated F-JDG-006 refused.
 *
 *   Path 1 (legislative response, LAW C):
 *     ... -> F-JDG-005 -> the legislature modifies the offending law in time
 *     (EnactmentService::amendLaw, legislative source) and the tagged-bill hook
 *     ConstitutionalChallengeService::onRemedialEnactment closes the challenge
 *     amended_by_legislature + cancels the timers. A repeated OVERRIDE
 *     (F-LEG-035) after the Path-1 remedy is refused.
 *     NOTE: the F-LEG-003 -> floor -> enactment wiring that TRIGGERS
 *     onRemedialEnactment is pinned separately (Art4Section5Test on the world DB
 *     and the SQLite bill-prefill test). This journey drives the real Path-1
 *     closure service and its DB effects, not the bill floor-vote plumbing.
 *
 * Modeled on tests/concurrency/judiciary_seating_journey.php and
 * tests/concurrency/case_multi_actor_journey.php (same disposable-PG protocol).
 */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\ClockTimer;
use App\Models\ConstitutionalChallenge;
use App\Models\CourtCase;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\LegislatureMember;
use App\Models\RemedyRecommendation;
use App\Services\AchievementService;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\Education\TrainingGateService;
use App\Services\EnactmentService;
use App\Services\Judiciary\CaseService;
use App\Services\Judiciary\ConstitutionalChallengeService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('7d000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_challenge_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }

function connectionConfig(string $name): array
{
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);

    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'challenge_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}

function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_challenge_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='40s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='40s'");

    return $pdo;
}

function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM challenge_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
    check($row !== false && $row['db'] === $name && hash_equals($nonce, $row['nonce']), 'Fixture identity check failed');
    check($pdo->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fixture unexpectedly contains public organizations');
}

/**
 * Boot a minimal Laravel application bound to the private fixture. Real audit /
 * record / clock / enactment / challenge / remedy / vote services; only the
 * environment leaves (settings resolver, achievements, training gate, role
 * gate) are hand-rolled doubles.
 */
function bootFixture(string $name, string $nonce, array &$deps): void
{
    $verify = pdo($name); identity($verify, $name, $nonce); $verify = null;

    $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
    $app->instance('config', new Illuminate\Config\Repository([
        'app' => ['key' => '', 'timezone' => 'UTC'],
        'cache' => ['default' => 'array'],
        'cga' => ['demo_session_capture' => false, 'election_demo_compression' => 0],
        'constitution' => [
            'vote_types' => require dirname(__DIR__, 2).'/config/constitution/vote_types.php',
        ],
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

    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en');
    $app->instance('translator', $translator);
    $validationFactory = new Illuminate\Validation\Factory($translator, $app);
    $app->instance('validator', $validationFactory);
    $app->instance(Illuminate\Validation\Factory::class, $validationFactory);
    $app->instance(Illuminate\Contracts\Validation\Factory::class, $validationFactory);

    foreach (["SET statement_timeout='40s'", "SET lock_timeout='8s'", "SET idle_in_transaction_session_timeout='40s'"] as $setting) {
        DB::statement($setting);
    }
    identity(DB::connection()->getPdo(), $name, $nonce);
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'challenge_fixture', 'Unexpected schema/search path');

    // ── Real hardened services (audit chain, records, clocks, enactment) ─────
    $audit = new AuditService;
    $records = new PublicRecordService($audit);
    $app->instance(AuditService::class, $audit);
    $app->instance(PublicRecordService::class, $records);

    // ── Environment leaves (hand-rolled, signature-compatible doubles) ───────
    $settingsDouble = new class extends SettingsResolver {
        public function __construct() {}
        public function resolveInt(string $jurisdictionId, string $column, int $default = 0): int
        {
            return match ($column) {
                'supermajority_numerator' => 2,
                'supermajority_denominator' => 3,
                default => $default,
            };
        }
        public function resolve(string $jurisdictionId, string $column): mixed
        { return $column === 'voting_method' ? 'stv_droop' : null; }
    };
    $achievementsDouble = new class extends AchievementService {
        public function __construct() {}
        public function awardSelf(\App\Models\User $holder, string $awardKey): bool { return true; }
        public function awardState(\App\Models\User $holder, string $awardKey): bool { return true; }
        public function awardSubject(\App\Models\User $holder, string $awardKey, array $ctx = []): bool { return true; }
    };
    $trainingGate = new class extends TrainingGateService {
        public function __construct() {}
        public function assertMayAct(?\App\Models\User $actor, string $canonicalId): void {}
    };
    // A single role gate that GRANTS the challenge/judicial/legislative roles to
    // everyone. Actor IDENTITY is enforced downstream by the REAL gates
    // (JudicialActor::seat reads judicial_seats; the override reads
    // legislature_members) — the wrong-actor refusals below prove them.
    $roleGate = new class implements ResolvesRoles {
        public function rolesFor(?\App\Models\User $user): array { return ['R-03', 'R-09', 'R-19', 'R-20']; }
    };

    $app->instance(SettingsResolver::class, $settingsDouble);
    $app->instance(AchievementService::class, $achievementsDouble);
    $app->instance(TrainingGateService::class, $trainingGate);
    $app->instance(CommitteeRoster::class, new \App\Services\Legislature\EloquentCommitteeRoster);

    $engine = new ConstitutionalEngine($audit, new \App\Services\ConstitutionalValidator, $roleGate, $trainingGate);
    $app->instance(ConstitutionalEngine::class, $engine);

    $deps = [
        'app' => $app,
        'engine' => $engine,
        'cases' => $app->make(CaseService::class),
        'votes' => $app->make(ChamberVoteService::class),
        'enact' => $app->make(EnactmentService::class),
        'challenges' => $app->make(ConstitutionalChallengeService::class),
    ];
}

/** The tables this journey touches, exact baseline column shapes (public. stripped). */
function createSchema(): void
{
    DB::unprepared(<<<'SQL'
    CREATE TABLE jurisdictions (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text, parent_id uuid,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE users (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text, display_name text,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE residency_confirmations (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL,
        jurisdiction_id uuid NOT NULL, days_confirmed smallint NOT NULL DEFAULT 30, confirmed_at timestamptz NOT NULL DEFAULT now(),
        voting_right_active boolean NOT NULL DEFAULT true, candidacy_right_active boolean NOT NULL DEFAULT true,
        is_active boolean NOT NULL DEFAULT true, created_at timestamptz, updated_at timestamptz);

    CREATE TABLE laws (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), jurisdiction_id uuid NOT NULL, legislature_id uuid NOT NULL,
        act_number varchar(255) NOT NULL, title varchar(255) NOT NULL, kind varchar(24) NOT NULL, scale jsonb NOT NULL,
        scope_judiciary_id uuid, origin varchar(20) NOT NULL, enacting_bill_id uuid, origin_ref_type varchar(32), origin_ref_id uuid,
        referendum_passed_by_supermajority boolean, shield_expires_with_election_id uuid,
        status varchar(12) NOT NULL DEFAULT 'in_force', current_version_no smallint NOT NULL DEFAULT 1,
        effective_at timestamptz NOT NULL, enacted_at timestamptz NOT NULL,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE law_versions (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), law_id uuid NOT NULL, version_no smallint NOT NULL,
        text text NOT NULL, text_hash char(64) NOT NULL, source varchar(24) NOT NULL, source_ref_type varchar(32), source_ref_id uuid,
        created_at timestamptz NOT NULL DEFAULT now());

    CREATE TABLE judiciaries (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), jurisdiction_id uuid NOT NULL,
        court_name varchar(128) NOT NULL DEFAULT 'Superior Court', type varchar(16) NOT NULL DEFAULT 'appointed',
        min_judges smallint NOT NULL DEFAULT 5, term_years smallint NOT NULL DEFAULT 10, status varchar(16) NOT NULL DEFAULT 'forming',
        parent_judiciary_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz,
        creation_law_id uuid, nomination_mode varchar(20), conversion_process_id uuid, conversion_law_id uuid,
        converted_at timestamptz, judge_count smallint, source_legislature_id uuid);

    CREATE TABLE judicial_seats (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), judiciary_id uuid NOT NULL, user_id uuid,
        seat_number smallint NOT NULL, term_starts_on date, term_ends_on date, status varchar(16) NOT NULL DEFAULT 'vacant',
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz,
        seat_class varchar(24) NOT NULL DEFAULT 'committee_nominated', nominating_jurisdiction_id uuid, appointment_id uuid,
        elected_in_race_id uuid, term_id uuid);

    CREATE TABLE constitutional_challenges (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), jurisdiction_id uuid NOT NULL,
        judiciary_id uuid NOT NULL, challenged_law_id uuid NOT NULL, challenged_version_no smallint NOT NULL, filed_by_user_id uuid NOT NULL,
        claim_text text NOT NULL, claimed_basis varchar(20) NOT NULL, cited_authority_law_id uuid, constitutional_citation varchar(64),
        case_id uuid, status varchar(28) NOT NULL, finding_id uuid, remedy_id uuid, resolution_path varchar(24),
        resolution_ref_type varchar(40), resolution_ref_id uuid, filed_at timestamptz, heard_at timestamptz, finding_at timestamptz,
        closed_at timestamptz, record_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE constitutional_findings (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), challenge_id uuid NOT NULL, judiciary_id uuid NOT NULL,
        case_id uuid, full_court boolean NOT NULL DEFAULT false, finds_contradiction boolean NOT NULL, contradiction_against varchar(20) NOT NULL,
        superior_authority_law_id uuid, constitutional_citation varchar(64), offending_law_id uuid NOT NULL, offending_version_no smallint NOT NULL,
        opinion_text text NOT NULL, panel_snapshot jsonb NOT NULL DEFAULT '[]'::jsonb, record_id uuid, issued_at timestamptz NOT NULL,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE remedy_recommendations (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), finding_id uuid NOT NULL, challenge_id uuid NOT NULL,
        judiciary_id uuid NOT NULL, remedy_kind varchar(16) NOT NULL, recommended_text text, rationale_text text NOT NULL,
        remedy_timeframe_days smallint NOT NULL, veto_window_days smallint NOT NULL, remedy_due_at timestamptz NOT NULL, veto_closes_at timestamptz NOT NULL,
        clk11_timer_id uuid, clk12_timer_id uuid, record_id uuid, issued_at timestamptz NOT NULL,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE cases (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), docket_no varchar(24) NOT NULL, judiciary_id uuid NOT NULL,
        jurisdiction_id uuid NOT NULL, kind varchar(16) NOT NULL, title varchar(255) NOT NULL, statement_of_claim text, claimed_severity varchar(12),
        court_severity varchar(20), jury_entitled boolean NOT NULL DEFAULT false, jury_waived boolean NOT NULL DEFAULT false,
        filed_via_form varchar(16) NOT NULL, filed_by_user_id uuid, filed_on_behalf_of_user_id uuid, advocate_id uuid, panel_id uuid, jury_id uuid,
        appeal_of_case_id uuid, status varchar(20) NOT NULL, double_jeopardy_locked boolean NOT NULL DEFAULT false,
        accepted_at timestamptz, decided_at timestamptz, closed_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
    CREATE UNIQUE INDEX cases_judiciary_docket_unique ON cases (judiciary_id, docket_no) WHERE deleted_at IS NULL;

    CREATE TABLE case_parties (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), case_id uuid NOT NULL, party_role varchar(16) NOT NULL,
        party_type varchar(16) NOT NULL, party_user_id uuid, party_ref_type varchar(32), party_ref_id uuid, represented_by_advocate_id uuid,
        retainer_note text, status varchar(12) NOT NULL DEFAULT 'active', created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE panels (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), case_id uuid NOT NULL, judiciary_id uuid NOT NULL, size smallint NOT NULL,
        is_en_banc boolean NOT NULL DEFAULT false, severity_basis varchar(20) NOT NULL, presiding_judge_seat_id uuid, draw_seed varchar(64),
        status varchar(16) NOT NULL, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE clocks (id varchar(8) PRIMARY KEY, name varchar(64) NOT NULL, type varchar(12) NOT NULL, default_value jsonb NOT NULL DEFAULT '{}'::jsonb,
        amendable boolean NOT NULL DEFAULT false, fires_workflow varchar(64), basis text, created_at timestamptz, updated_at timestamptz);

    CREATE TABLE clock_timers (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), clock_id varchar(8) NOT NULL, jurisdiction_id uuid,
        subject_type varchar(64), subject_id uuid, armed_at timestamptz NOT NULL, fires_at timestamptz, state varchar(12) NOT NULL DEFAULT 'armed',
        payload jsonb NOT NULL DEFAULT '{}'::jsonb, override_value jsonb, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE legislatures (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), jurisdiction_id uuid NOT NULL, term_number smallint NOT NULL DEFAULT 1,
        term_starts_on date, term_ends_on date, status varchar(255) NOT NULL DEFAULT 'forming', total_seats smallint NOT NULL DEFAULT 5,
        type_a_seats smallint NOT NULL DEFAULT 5, type_b_seats smallint NOT NULL DEFAULT 0, speaker_id uuid, quorum_required smallint NOT NULL DEFAULT 3,
        last_met_on date, next_meeting_due_by date, parent_legislature_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE legislature_members (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), legislature_id uuid NOT NULL, user_id uuid NOT NULL,
        seat_type char(1) NOT NULL DEFAULT 'a', district_id uuid, seated_on date, term_ends_on date, status varchar(255) NOT NULL DEFAULT 'elected',
        vacated_at timestamptz, vacancy_reason varchar(255), election_id uuid, is_speaker boolean NOT NULL DEFAULT false,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz, seat_no smallint, elected_in_race_id uuid, term_id uuid,
        vote_share_norm numeric(8,4), seated_at timestamptz, home_jurisdiction_id uuid);

    CREATE TABLE chamber_votes (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), body_type varchar(16) NOT NULL, body_id uuid NOT NULL, legislature_id uuid,
        jurisdiction_id uuid NOT NULL, votable_type varchar(32), votable_id uuid, vote_type varchar(40) NOT NULL, vote_method varchar(8) NOT NULL,
        threshold_basis varchar(16) NOT NULL, stage varchar(12), bicameral boolean NOT NULL DEFAULT false, serving_snapshot smallint NOT NULL,
        held_in_session_id uuid, opened_by_member_id uuid, opened_at timestamptz NOT NULL DEFAULT now(), closes_at timestamptz, decided_at timestamptz,
        outcome varchar(12), speaker_tiebreak boolean NOT NULL DEFAULT false, rcv_record jsonb, status varchar(8) NOT NULL DEFAULT 'open',
        created_at timestamptz, updated_at timestamptz);

    CREATE TABLE chamber_vote_proposals (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), legislature_id uuid NOT NULL, proposal_kind varchar(32) NOT NULL,
        vote_id uuid, payload jsonb NOT NULL DEFAULT '{}'::jsonb, proposed_by_member_id uuid, status varchar(12) NOT NULL DEFAULT 'open',
        decided_at timestamptz, result_type varchar(40), result_id uuid, created_at timestamptz, updated_at timestamptz);

    CREATE TABLE chamber_vote_tallies (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), vote_id uuid NOT NULL, lane varchar(8) NOT NULL, serving smallint NOT NULL,
        quorum_required smallint NOT NULL, required_yes smallint NOT NULL, present smallint, yes smallint NOT NULL DEFAULT 0, no smallint NOT NULL DEFAULT 0,
        abstain smallint NOT NULL DEFAULT 0, quorate boolean, passed boolean);

    CREATE TABLE vote_casts (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), vote_id uuid NOT NULL, member_id uuid, lane varchar(8) NOT NULL, value varchar(8),
        rankings jsonb, is_tiebreak boolean NOT NULL DEFAULT false, explanation text, cast_via_form varchar(12) NOT NULL, public_record_id uuid,
        cast_at timestamptz NOT NULL DEFAULT now(), board_seat_id uuid);

    CREATE TABLE bills (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), legislature_id uuid NOT NULL, jurisdiction_id uuid NOT NULL, sponsor_member_id uuid NOT NULL,
        title varchar(255) NOT NULL, act_type varchar(20) NOT NULL, scale jsonb NOT NULL, scope_judiciary_id uuid, targets_setting_key varchar(255),
        proposed_value jsonb, effective_at timestamptz, status varchar(16) NOT NULL DEFAULT 'introduced', committee_id uuid, current_version_no smallint NOT NULL DEFAULT 1,
        introduced_at timestamptz, passed_at timestamptz, failed_at timestamptz, enacted_at timestamptz, enacted_law_id uuid,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz, targets_challenge_id uuid);

    CREATE TABLE public_records (seq bigserial PRIMARY KEY, id uuid NOT NULL DEFAULT gen_random_uuid(), kind varchar(24) NOT NULL, title varchar(255) NOT NULL,
        body text, actor_user_id uuid, actor_display varchar(255), jurisdiction_id uuid, legislature_id uuid, via_form varchar(16), via_workflow varchar(16),
        via_clock varchar(8), subject_type varchar(40), subject_id uuid, audit_seq bigint, translations jsonb NOT NULL DEFAULT '{}'::jsonb,
        supersedes_record_id uuid, published_at timestamptz NOT NULL DEFAULT now(), created_at timestamptz NOT NULL DEFAULT now(), source_server_id uuid);

    CREATE TABLE instance_settings (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), instance_name varchar(255) NOT NULL DEFAULT 'Fixture',
        map_mode varchar(255) NOT NULL DEFAULT 'physical_earth', time_mode varchar(255) NOT NULL DEFAULT 'real', setup_step_completed smallint NOT NULL DEFAULT 0,
        server_id uuid, federation_enabled boolean NOT NULL DEFAULT false, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE audit_log (seq bigserial PRIMARY KEY, id uuid NOT NULL DEFAULT gen_random_uuid(), occurred_at timestamptz NOT NULL DEFAULT now(),
        actor_user_id uuid, module varchar(32) NOT NULL, event varchar(64) NOT NULL, ref varchar(24), jurisdiction_id uuid, payload jsonb NOT NULL DEFAULT '{}'::jsonb,
        prev_hash char(64) NOT NULL, hash char(64) NOT NULL, rejected boolean NOT NULL DEFAULT false, blocked_reason text, created_at timestamptz NOT NULL DEFAULT now());
    SQL);

    // Audit-chain genesis row (hashed exactly as AuditService recomputes it).
    $canonical = AuditService::canonicalJson([]);
    $genesisHash = AuditService::chainHash(AuditService::GENESIS_PREV_HASH, $canonical);
    DB::table('audit_log')->insert([
        'occurred_at' => now(), 'module' => 'system', 'event' => 'genesis', 'payload' => $canonical,
        'prev_hash' => AuditService::GENESIS_PREV_HASH, 'hash' => $genesisHash, 'rejected' => false, 'created_at' => now(),
    ]);
}

/**
 * Seed one jurisdiction J0 with an appointed court (5 seated judges), a
 * legislature L0 (6 seated members), an inhabitant filer, an outsider, and three
 * in-force bill-origin laws A/B/C (each with a v1). Returns the fixture handles.
 */
function seedWorld(): array
{
    $now = now();
    DB::table('instance_settings')->insert(['id' => uid(9), 'instance_name' => 'Challenge fixture', 'created_at' => $now, 'updated_at' => $now]);
    $jur = uid(1);
    DB::table('jurisdictions')->insert(['id' => $jur, 'name' => 'Challenge place', 'created_at' => $now, 'updated_at' => $now]);

    // The clocks the recommendation arms.
    DB::table('clocks')->insert([
        ['id' => 'CLK-11', 'name' => 'Judicial veto window', 'type' => 'countdown', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'CLK-12', 'name' => 'Legislative remedy timeframe', 'type' => 'countdown', 'created_at' => $now, 'updated_at' => $now],
    ]);

    // Legislature L0 with 6 seated type-a members.
    $leg = uid(10);
    DB::table('legislatures')->insert(['id' => $leg, 'jurisdiction_id' => $jur, 'status' => 'active', 'total_seats' => 6, 'type_a_seats' => 6,
        'type_b_seats' => 0, 'quorum_required' => 4, 'created_at' => $now, 'updated_at' => $now]);
    $members = [];
    $memberUsers = [];
    for ($i = 0; $i < 6; $i++) {
        $u = uid(100 + $i);
        DB::table('users')->insert(['id' => $u, 'name' => 'Rep '.$i, 'display_name' => 'Rep '.$i, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('legislature_members')->insert(['id' => uid(200 + $i), 'legislature_id' => $leg, 'user_id' => $u, 'seat_type' => 'a',
            'seat_no' => $i + 1, 'status' => 'seated', 'created_at' => $now, 'updated_at' => $now]);
        $members[] = uid(200 + $i);
        $memberUsers[] = $u;
    }

    // Appointed court with 5 seated judges (residents of J0).
    $court = uid(20);
    DB::table('judiciaries')->insert(['id' => $court, 'jurisdiction_id' => $jur, 'court_name' => 'Constitutional court', 'type' => 'appointed',
        'min_judges' => 5, 'term_years' => 10, 'status' => 'appointed', 'judge_count' => 5, 'nomination_mode' => 'constituent',
        'created_at' => $now, 'updated_at' => $now]);
    $judges = [];
    for ($i = 0; $i < 5; $i++) {
        $u = uid(300 + $i);
        DB::table('users')->insert(['id' => $u, 'name' => 'Judge '.$i, 'display_name' => 'Judge '.$i, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('residency_confirmations')->insert(['user_id' => $u, 'jurisdiction_id' => $jur, 'days_confirmed' => 40, 'confirmed_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('judicial_seats')->insert(['id' => uid(400 + $i), 'judiciary_id' => $court, 'user_id' => $u, 'seat_number' => $i + 1,
            'seat_class' => 'constituent_nominated', 'status' => 'seated', 'term_starts_on' => '2026-01-01', 'term_ends_on' => '2036-01-01',
            'created_at' => $now, 'updated_at' => $now]);
        $judges[] = $u;
    }

    // The inhabitant filer + an outsider (neither a judge nor a member).
    $inhabitant = uid(500);
    DB::table('users')->insert(['id' => $inhabitant, 'name' => 'Inhabitant', 'display_name' => 'Inhabitant', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('residency_confirmations')->insert(['user_id' => $inhabitant, 'jurisdiction_id' => $jur, 'days_confirmed' => 40, 'confirmed_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    $outsider = uid(501);
    DB::table('users')->insert(['id' => $outsider, 'name' => 'Outsider', 'display_name' => 'Outsider', 'created_at' => $now, 'updated_at' => $now]);

    // Three in-force bill-origin laws, each with a v1.
    $laws = [];
    foreach (['A' => 600, 'B' => 610, 'C' => 620] as $tag => $seq) {
        $lawId = uid($seq);
        DB::table('laws')->insert(['id' => $lawId, 'jurisdiction_id' => $jur, 'legislature_id' => $leg, 'act_number' => 'Act 2026-'.$tag,
            'title' => 'Fixture Act '.$tag, 'kind' => 'ordinary', 'scale' => json_encode([$jur]), 'origin' => 'bill', 'status' => 'in_force',
            'current_version_no' => 1, 'effective_at' => $now, 'enacted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $text = 'Original text of Act '.$tag.' v1.';
        DB::table('law_versions')->insert(['id' => uid($seq + 1), 'law_id' => $lawId, 'version_no' => 1, 'text' => $text,
            'text_hash' => hash('sha256', $text), 'source' => 'enactment', 'source_ref_type' => 'bill', 'source_ref_id' => uid($seq + 2), 'created_at' => $now]);
        $laws[$tag] = $lawId;
    }

    return ['jurisdiction' => $jur, 'legislature' => $leg, 'members' => $members, 'member_users' => $memberUsers, 'court' => $court, 'judges' => $judges,
        'inhabitant' => $inhabitant, 'outsider' => $outsider, 'laws' => $laws];
}

/** Assert the callable refuses with a ConstitutionalViolation; emit the message. */
function refused(callable $action, string $label): void
{
    try {
        $action();
        throw new RuntimeException("Expected constitutional refusal: {$label}");
    } catch (ConstitutionalViolation $e) {
        check($e->getMessage() !== '', "Empty refusal message: {$label}");
        emit(['refusal_ok' => $label, 'citation' => $e->citation, 'message' => $e->getMessage()]);
    }
}

/**
 * File F-IND-016, drive the auto-opened case to `heard`, then F-JDG-004
 * (contradiction) + F-JDG-005 (modify remedy, windows days). Returns the
 * challenge id. Uses the REAL engine + real CaseService.
 */
function fileFoundRecommended(array $deps, array $w, string $lawId, string $recommendedText, int $timeframeDays, int $vetoDays): string
{
    $engine = $deps['engine'];
    $inhabitant = \App\Models\User::findOrFail($w['inhabitant']);
    $judge = \App\Models\User::findOrFail($w['judges'][0]);

    $filed = $engine->file('F-IND-016', $inhabitant, [
        'jurisdiction_id' => $w['jurisdiction'],
        'challenged_law_id' => $lawId,
        'claim_text' => 'The law unjustly impedes a right (fixture claim).',
        'claimed_basis' => 'constitution',
        'constitutional_citation' => 'Art. I',
    ]);
    $challengeId = $filed->recorded['challenge_id'];
    $caseId = $filed->recorded['case_id'];
    check($caseId !== null, 'F-IND-016 opened no hearing case (court not operating?)');
    check(ConstitutionalChallenge::findOrFail($challengeId)->status === ConstitutionalChallenge::STATUS_UNDER_REVIEW, 'Challenge not under_review after filing');

    // Drive the case filed -> accepted -> paneled -> heard (real CaseService).
    $cases = $deps['cases'];
    $case = CourtCase::findOrFail($caseId);
    $cases->accept($case, CourtCase::SEVERITY_CONSTITUTIONAL_MAJOR);
    $panelId = uid(9000 + random_int(1, 900000));
    DB::table('panels')->insert(['id' => $panelId, 'case_id' => $caseId, 'judiciary_id' => $w['court'], 'size' => 5,
        'severity_basis' => 'constitutional_major', 'status' => 'seated', 'created_at' => now(), 'updated_at' => now()]);
    $cases->markPaneled($case->refresh(), $panelId);
    $cases->advanceToHearing($case->refresh());
    check(CourtCase::findOrFail($caseId)->status === CourtCase::STATUS_HEARD, 'Case not heard');

    // F-JDG-004 finding of contradiction (a seated judge of THIS court).
    $engine->file('F-JDG-004', $judge, [
        'challenge_id' => $challengeId,
        'finds_contradiction' => true,
        'contradiction_against' => 'constitution',
        'offending_law_id' => $lawId,
        'opinion_text' => 'The act contradicts Art. I (fixture finding).',
        'full_court' => true,
    ]);
    check(ConstitutionalChallenge::findOrFail($challengeId)->status === ConstitutionalChallenge::STATUS_FINDING_ISSUED, 'Challenge not finding_issued');

    // F-JDG-005 remedy recommendation (arms CLK-11 + CLK-12).
    $engine->file('F-JDG-005', $judge, [
        'challenge_id' => $challengeId,
        'remedy_kind' => 'modify',
        'recommended_text' => $recommendedText,
        'rationale_text' => 'Striking the conflicting clause restores Art. I.',
        'remedy_timeframe_days' => $timeframeDays,
        'veto_window_days' => $vetoDays,
    ]);
    check(ConstitutionalChallenge::findOrFail($challengeId)->status === ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, 'Challenge not at legislative_window_open');

    return $challengeId;
}

// ═══════════════════════════════════════════════════════════════════════════

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/challenge_direct_remedy.php --run\n"; exit(0); }

$name = 'cga_challenge_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0; $deps = [];

try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA challenge_fixture; SET search_path TO challenge_fixture; CREATE TABLE challenge_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO challenge_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null;

    bootFixture($name, $nonce, $deps);
    createSchema();
    $w = seedWorld();
    $engine = $deps['engine'];

    // ═════════════════════════════════════════════════════════════════════
    // PATH 3 — permitted DIRECT judicial remedy (Law A)
    // ═════════════════════════════════════════════════════════════════════
    $lawA = $w['laws']['A'];
    $remedyText = 'Corrected text of Act A (conflicting clause removed).';
    $challengeA = fileFoundRecommended($deps, $w, $lawA, $remedyText, 60, 30);
    $judge = \App\Models\User::findOrFail($w['judges'][0]);
    $outsider = \App\Models\User::findOrFail($w['outsider']);

    // Clock rule: EXACTLY two armed timers, CLK-11 fires at max(veto, remedy).
    $timers = ClockTimer::query()->where('subject_type', 'constitutional_challenges')->where('subject_id', $challengeA)
        ->where('state', 'armed')->get()->keyBy('clock_id');
    check($timers->count() === 2, 'F-JDG-005 must arm exactly 2 timers, got '.$timers->count());
    check((int) $timers['CLK-11']->override_value['days'] === 30, 'CLK-11 carries the judge-set veto window (30)');
    check((int) $timers['CLK-12']->override_value['days'] === 60, 'CLK-12 carries the judge-set remedy timeframe (60)');
    check($timers['CLK-11']->fires_at->equalTo($timers['CLK-12']->fires_at), 'CLK-11 fires at max(veto, remedy) = the remedy deadline');
    emit(['step' => 'path3_windows_armed', 'challenge' => $challengeA, 'clk11_days' => 30, 'clk12_days' => 60]);

    // REFUSAL — premature remedy while both windows are open.
    refused(fn () => $engine->file('F-JDG-006', $judge, ['challenge_id' => $challengeA]), 'premature F-JDG-006 (windows open)');
    check(ConstitutionalChallenge::findOrFail($challengeA)->status === ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, 'A refused premature remedy wrote a transition');
    check((int) Law::findOrFail($lawA)->current_version_no === 1, 'A refused premature remedy amended the law');

    // REFUSAL — wrong actor (not a seated judge of this court).
    refused(fn () => $engine->file('F-JDG-006', $outsider, ['challenge_id' => $challengeA]), 'wrong-actor F-JDG-006 (non-judge)');
    check((int) Law::findOrFail($lawA)->current_version_no === 1, 'A refused wrong-actor remedy amended the law');

    // Advance the clock past BOTH windows, then apply the remedy for real.
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(61));
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::now('UTC')->addDays(61));

    $applied = $engine->file('F-JDG-006', $judge, ['challenge_id' => $challengeA]);
    emit(['step' => 'path3_remedy_applied', 'result' => $applied->recorded]);

    // ── PRESERVE LAW VERSIONS + permitted direct remedy asserted ─────────────
    $lawARow = Law::findOrFail($lawA);
    check((int) $lawARow->current_version_no === 2, 'Path 3: Law A must advance to v2, got '.$lawARow->current_version_no);
    check((string) $lawARow->status === Law::STATUS_AMENDED, 'Path 3: a modify remedy leaves the law amended (in force)');
    $versions = LawVersion::query()->where('law_id', $lawA)->orderBy('version_no')->get();
    check($versions->count() === 2, 'Path 3: exactly two versions after the remedy, got '.$versions->count());
    check((string) $versions[0]->text === 'Original text of Act A v1.', 'Path 3: v1 text preserved unchanged');
    check((string) $versions[0]->source === 'enactment', 'Path 3: v1 source preserved (enactment)');
    check((string) $versions[1]->text === $remedyText, 'Path 3: v2 carries the judge-recommended text');
    check((string) $versions[1]->source === LawVersion::SOURCE_JUDICIAL_REMEDY, 'Path 3: v2 source is judicial_remedy');
    check((string) $versions[1]->source_ref_type === 'constitutional_challenges', 'Path 3: v2 traces the challenge');
    $chA = ConstitutionalChallenge::findOrFail($challengeA);
    check((string) $chA->status === ConstitutionalChallenge::STATUS_CLOSED, 'Path 3: the challenge closes');
    check((string) $chA->resolution_path === ConstitutionalChallenge::PATH_JUDICIAL_REMEDY, 'Path 3: resolution_path is judicial_remedy');
    check((string) $chA->resolution_ref_type === 'law_versions', 'Path 3: resolution references the appended law version');
    // Clock rule: both timers cancelled by the applied remedy.
    check(ClockTimer::query()->where('subject_id', $challengeA)->where('state', 'armed')->count() === 0, 'Path 3: both timers cancelled after the remedy');
    emit(['step' => 'path3_verified', 'law_a_version' => 2, 'v2_source' => 'judicial_remedy', 'v1_preserved' => true]);

    // REFUSAL — repeated application on the now-closed challenge.
    refused(fn () => $engine->file('F-JDG-006', $judge, ['challenge_id' => $challengeA]), 'repeated F-JDG-006 after direct remedy');
    check((int) Law::findOrFail($lawA)->current_version_no === 2, 'A repeated remedy appended a third version');
    check(LawVersion::query()->where('law_id', $lawA)->count() === 2, 'A repeated remedy wrote another law version');

    CarbonImmutable::setTestNow();
    \Carbon\Carbon::setTestNow();

    // ═════════════════════════════════════════════════════════════════════
    // PATH 2 — valid supermajority OVERRIDE (Law B), the law stands unchanged
    // ═════════════════════════════════════════════════════════════════════
    $lawB = $w['laws']['B'];
    $challengeB = fileFoundRecommended($deps, $w, $lawB, 'Corrected text of Act B.', 60, 30);

    // F-LEG-035 — a member opens the judiciary_override vote; supermajority adopts.
    $proposed = $engine->file('F-LEG-035', \App\Models\User::findOrFail($w['member_users'][0]), [
        'challenge_id' => $challengeB,
        'dissent_text' => 'The legislature disagrees with the finding.',
    ]);
    $voteId = $proposed->recorded['vote_id'];
    $vote = ChamberVote::findOrFail($voteId);
    check((string) $vote->threshold_basis === ChamberVote::BASIS_SUPERMAJORITY, 'Path 2: the override threshold is supermajority (PROTECTED)');
    $members = LegislatureMember::query()->where('legislature_id', $w['legislature'])->get();
    $deps['votes']->castManyYes($vote, $members);

    $chB = ConstitutionalChallenge::findOrFail($challengeB);
    check((string) $chB->status === ConstitutionalChallenge::STATUS_CLOSED, 'Path 2: an adopted override closes the challenge, got '.$chB->status);
    check((string) $chB->resolution_path === ConstitutionalChallenge::PATH_LEGISLATURE_OVERRIDE, 'Path 2: resolution_path is legislature_override');
    check((string) $chB->resolution_ref_type === 'chamber_votes', 'Path 2: resolution references the override vote');
    // The overruled law stands UNCHANGED — no version appended.
    check((int) Law::findOrFail($lawB)->current_version_no === 1, 'Path 2: the overruled law is NOT edited (still v1)');
    check(LawVersion::query()->where('law_id', $lawB)->count() === 1, 'Path 2: no law version appended on override');
    check((string) Law::findOrFail($lawB)->status === Law::STATUS_IN_FORCE, 'Path 2: the overruled law stays in force');
    check(ClockTimer::query()->where('subject_id', $challengeB)->where('state', 'armed')->count() === 0, 'Path 2: both timers cancelled on adoption');
    emit(['step' => 'path2_override_carried', 'challenge' => $challengeB, 'vote' => $voteId, 'law_b_version' => 1]);

    // REFUSAL — repeated application after the override (F-JDG-006 barred).
    refused(fn () => $engine->file('F-JDG-006', $judge, ['challenge_id' => $challengeB]), 'F-JDG-006 after override (repeated application)');
    check((int) Law::findOrFail($lawB)->current_version_no === 1, 'A post-override remedy amended the law');

    // ═════════════════════════════════════════════════════════════════════
    // PATH 1 — legislative RESPONSE in time (Law C), the challenge closes amended
    // ═════════════════════════════════════════════════════════════════════
    // The bill floor-vote wiring that triggers onRemedialEnactment is pinned
    // separately (Art4Section5Test on the world DB; the SQLite bill-prefill
    // test). Here the real Path-1 closure service + the real amendLaw are
    // exercised on the disposable DB.
    $lawC = $w['laws']['C'];
    $challengeC = fileFoundRecommended($deps, $w, $lawC, 'Corrected text of Act C.', 60, 30);

    $lawCRow = Law::findOrFail($lawC);
    // The legislature modifies the offending law within the window.
    $deps['enact']->amendLaw(
        law: $lawCRow,
        text: 'Act C amended by the legislature within the remedy window.',
        source: LawVersion::SOURCE_LEGISLATIVE_AMENDMENT,
        sourceRefType: 'bill',
        sourceRefId: uid(700),
        viaForm: 'F-LEG-003',
    );
    // The tagged remedial bill, then the real Path-1 closure hook.
    $bill = Bill::create([
        'id' => uid(701), 'legislature_id' => $w['legislature'], 'jurisdiction_id' => $w['jurisdiction'], 'sponsor_member_id' => $w['members'][0],
        'title' => 'Remedial amendment (fixture)', 'act_type' => 'ordinary', 'scale' => [$w['jurisdiction']], 'status' => 'enacted',
        'current_version_no' => 1, 'enacted_law_id' => $lawC, 'targets_challenge_id' => $challengeC, 'enacted_at' => now(),
    ]);
    $deps['challenges']->onRemedialEnactment($bill, $lawCRow->refresh());

    $chC = ConstitutionalChallenge::findOrFail($challengeC);
    check((string) $chC->status === ConstitutionalChallenge::STATUS_CLOSED, 'Path 1: a timely legislative amendment closes the challenge, got '.$chC->status);
    check((string) $chC->resolution_path === ConstitutionalChallenge::PATH_LEGISLATIVE_AMENDMENT, 'Path 1: resolution_path is legislative_amendment');
    $vC = LawVersion::query()->where('law_id', $lawC)->orderBy('version_no')->get();
    check($vC->count() === 2, 'Path 1: the offending law advances to v2 (history preserved)');
    check((string) $vC[0]->text === 'Original text of Act C v1.', 'Path 1: v1 text preserved unchanged');
    check((string) $vC[1]->source === LawVersion::SOURCE_LEGISLATIVE_AMENDMENT, 'Path 1: v2 source is legislative_amendment (NOT judicial_remedy)');
    check(ClockTimer::query()->where('subject_id', $challengeC)->where('state', 'armed')->count() === 0, 'Path 1: both timers cancelled by the legislative remedy');
    emit(['step' => 'path1_legislative_remedy', 'challenge' => $challengeC, 'law_c_version' => 2, 'v2_source' => 'legislative_amendment']);

    // REFUSAL — a repeated OVERRIDE after the Path-1 remedy is refused.
    refused(fn () => $engine->file('F-LEG-035', \App\Models\User::findOrFail($w['member_users'][0]), [
        'challenge_id' => $challengeC,
        'dissent_text' => 'Too late.',
    ]), 'F-LEG-035 override after legislative remedy (repeated application)');

    // ── Audit chain intact across every write ────────────────────────────────
    $intact = (new AuditService)->verifyChain();
    check($intact === true, 'Audit chain broke at seq '.(is_int($intact) ? $intact : '?'));
    emit(['step' => 'audit_chain_intact', 'entries' => (int) DB::table('audit_log')->count()]);

    emit(['journey' => 'challenge_three_outcomes', 'passed' => true,
        'path3_direct_remedy' => 'judicial_remedy v2 appended, v1 preserved',
        'path2_override' => 'law unchanged (v1), challenge overridden',
        'path1_legislative' => 'law amended v2 (legislative), challenge closed']);
} catch (Throwable $e) {
    $failures++;
    emit(['failure' => $e->getMessage(), 'class' => $e::class, 'at' => $e->getFile().':'.$e->getLine()]);
} finally {
    CarbonImmutable::setTestNow();
    \Carbon\Carbon::setTestNow();
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
