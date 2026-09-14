<?php
/**
 * S1 · executive/CGC review journey — opt-in disposable-PostgreSQL probe,
 * never the world database. Follows
 * tests/concurrency/judicial_nomination_migration.php: strict
 * cga_executive_YYYYMMDD_<16hex> name, nonce-guarded fixture_guard row,
 * refusal when public.organizations exists, statement/lock timeouts, DROP
 * in a finally block even on failure. Reads .env only for connection
 * fields.
 *
 * Journey (register row "S1 · executive/CGC": formation, delegation,
 * governors, orders and oversight), all through real handlers/services:
 *   F-LEG-014 delegation act -> supermajority chamber vote (F-LEG-004
 *     casts) -> ChamberActService dispatch -> ExecutiveFormationService
 *     seats delegated principals (proportional selection); executive
 *     forming -> delegated (C is a seated principal, derives R-14)
 *   F-LEG-016 department creation act -> ordinary-majority vote ->
 *     DepartmentService charters the department, its distinct board and a
 *     vacant governor seat, and seeds the first periodic report DUE
 *   F-EXE-001 department governor nomination (by a seated principal) ->
 *     F-LEG-020 bog_consent vote (F-LEG-004 casts) -> ChamberActService
 *     -> BoardGovernorService::seat -> CivilAppointmentService opens the
 *     10-year civil term (Art. II §9); the department advances OPERATING;
 *     the governor derives R-18
 *   F-BOG-002 department report filed by the seated governor (R-18) ->
 *     the DUE periodic report FILED; the department advances REPORTING
 *     (this is the reporting/oversight leg of the register criterion)
 *   F-LEG-019 CGC creation act -> ordinary-majority vote -> CgcService
 *     provisions the CGC org (is_cgc, active), the 100% jurisdiction
 *     stake, the DISTINCT governor board with a vacant seat, the genesis
 *     public-domain IP dedication, and arms the co-determination watchers
 *   F-EXE-001 CGC governor nomination (organization path) -> F-LEG-020
 *     bog_consent -> BoardGovernorService::seat (organization) -> the
 *     10-year civil term; the CGC governor is seated on its own board
 *   F-EXE-005 executive order within the enabling law's scope -> issued
 *     with an EO-YYYY-NN number allocated under pg_advisory_xact_lock
 *     (ExecutiveOrderService::allocateOrderNo); a second order increments
 *     the serial; an out-of-scope (protected civic domain) attempt is
 *     rejected pre-issuance ON RECORD (the Phase D exit-criterion row)
 *
 * REFUSALS (register: cross-institution, former-officer, out-of-scope):
 *   - outsider O at nomination (F-EXE-001) and at order (F-EXE-005)
 *   - former delegated member (status left) at nomination and at order
 *   - foreign-executive principal (a principal of another jurisdiction's
 *     executive) nominating a governor of THIS institution
 *   - out-of-scope executive order (electoral_process — the hardened
 *     civic-process shield), with the rejected_pre_issuance row asserted
 *   - outsider at report filing (F-BOG-002)
 *
 * FIDELITY NOTES (deviations from the plan's section-3 literal, each a
 * higher-fidelity real-engine choice, none a criterion miss):
 *   - The plan named the executive-order form "F-EXO-001". No such form
 *     exists. The FormRegistry maps the executive order to F-EXE-005
 *     (Handlers\ExecutiveOrder), which this journey drives. THE CODE IS
 *     THE AUTHORITY.
 *   - The plan's section-3 line for this row centered the CGC governor.
 *     The register PASS CRITERION additionally requires "distinct
 *     department/CGC boards" and "complete reporting/oversight"; report
 *     filing (F-BOG-002) is department-scoped and needs a seated governor
 *     on a department board. This journey therefore also charters a
 *     department (F-LEG-016), seats its governor and files its report, so
 *     every clause of the criterion is exercised with real actors.
 *   - CLK-09 arming is stubbed (fake ClockService, plan-sanctioned
 *     doubles). The 10-year civil-appointment Term row that
 *     CivilAppointmentService writes is the real seating evidence.
 *
 * Doubles — peripheral collaborators only (plan-sanctioned, mirrors the
 * S1 legislature fixture): AuditService (no chain writes), SettingsResolver
 * (defaults -> supermajority 2/3, majority, civil_appointment_years 10),
 * ClockService (arm/cancel/fire no-op), AchievementService (award* no-op).
 * Real code under test: the F-LEG/F-EXE/F-BOG handlers, ChamberVoteService
 * + VoteCountingService counting core (PROTECTED), EnactmentService,
 * ChamberActService, ExecutiveActService, ExecutiveFormationService,
 * DepartmentService, BoardGovernorService, CivilAppointmentService,
 * ExecutiveOrderService (+ EnablingInstruments + ConstitutionalValidator
 * scope shield), CgcService (+ OrgOwnershipService, CgcIpRegisterService,
 * CoDeterminationService), PublicRecordService, RoleService derivation.
 */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\BoardGovernorNomination;
use App\Domain\Forms\Handlers\CgcCreationAct;
use App\Domain\Forms\Handlers\DepartmentCreationAct;
use App\Domain\Forms\Handlers\DepartmentReportFiling;
use App\Domain\Forms\Handlers\ExecutiveDelegationAct;
use App\Domain\Forms\Handlers\ExecutiveOrder as ExecutiveOrderHandler;
use App\Domain\Forms\Handlers\FloorVoteCast;
use App\Models\Appointment;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Department;
use App\Models\DepartmentReport;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\ExecutiveOrder;
use App\Models\Law;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\Executive\ExecutiveOrderService;
use App\Services\RoleService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('80000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_executive_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }

/** Assert a callable refuses with a ConstitutionalViolation whose message contains $needle. */
function refuses(string $label, string $needle, callable $fn): void
{
    try {
        $fn();
    } catch (ConstitutionalViolation $e) {
        check(str_contains($e->getMessage(), $needle),
            "Refusal [$label] wrong message: {$e->getMessage()} (wanted: $needle)");
        emit(['refusal' => $label, 'held' => true, 'reason' => substr($e->getMessage(), 0, 100)]);
        return;
    } catch (Throwable $e) {
        throw new RuntimeException("Refusal [$label] threw a non-constitutional error: ".$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
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
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'executive_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_executive_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}";
    $conn = new PDO($dsn, $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $conn->exec("SET statement_timeout='30s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='30s'");
    return $conn;
}
function identity(PDO $conn, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $conn->query('SELECT current_database() AS db, nonce FROM executive_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
    check($row !== false && $row['db'] === $name && hash_equals($nonce, $row['nonce']), 'Fixture identity check failed');
    check($conn->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fixture unexpectedly contains public organizations');
}
function bootFixture(string $name, string $nonce): void
{
    $verify = pdo($name); identity($verify, $name, $nonce); $verify = null;
    $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
    // The PROTECTED vote engine resolves vote-type rows from config
    // (constitution.vote_types) — load the real registry so exec_delegate /
    // procedural_motion / bog_consent resolve exactly as live.
    $voteTypes = require dirname(__DIR__, 2).'/config/constitution/vote_types.php';
    $app->instance('config', new Illuminate\Config\Repository(['app' => ['key' => '', 'timezone' => 'UTC'], 'cache' => ['default' => 'array'], 'constitution' => ['vote_types' => $voteTypes]]));
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
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'executive_fixture', 'Unexpected schema/search path');
}

function createSchema(): void
{
    $ddl = [
        // ---- Core populated tables (REAL baseline DDL; CHECK constraints kept) ----
        "CREATE TABLE users (id uuid PRIMARY KEY, name text, display_name text, is_operator boolean DEFAULT false, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE jurisdictions (id uuid PRIMARY KEY, name text, parent_id uuid, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE residency_confirmations (id uuid PRIMARY KEY, user_id uuid, jurisdiction_id uuid, is_active boolean DEFAULT true, depth integer, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE legislatures (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            jurisdiction_id uuid NOT NULL,
            term_number smallint DEFAULT '1'::smallint NOT NULL,
            term_starts_on date, term_ends_on date,
            status character varying(255) DEFAULT 'forming'::character varying NOT NULL,
            total_seats smallint DEFAULT '5'::smallint NOT NULL,
            type_a_seats smallint DEFAULT '5'::smallint NOT NULL,
            type_b_seats smallint DEFAULT '0'::smallint NOT NULL,
            speaker_id uuid, quorum_required smallint DEFAULT '3'::smallint NOT NULL,
            last_met_on date, next_meeting_due_by date, parent_legislature_id uuid,
            created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone, deleted_at timestamp(0) without time zone)",
        "CREATE TABLE legislature_members (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            legislature_id uuid NOT NULL, user_id uuid NOT NULL,
            seat_type character(1) DEFAULT 'a'::bpchar NOT NULL,
            district_id uuid, seated_on date, term_ends_on date,
            status character varying(255) DEFAULT 'elected'::character varying NOT NULL,
            vacated_at timestamp(0) without time zone, vacancy_reason character varying(255),
            election_id uuid, is_speaker boolean DEFAULT false NOT NULL,
            created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone, deleted_at timestamp(0) without time zone,
            seat_no smallint, elected_in_race_id uuid, term_id uuid, vote_share_norm numeric(8,4),
            seated_at timestamp(0) with time zone, home_jurisdiction_id uuid,
            CONSTRAINT legislature_members_status_check CHECK (((status)::text = ANY ((ARRAY['elected'::character varying, 'seated'::character varying, 'vacated'::character varying, 'removed'::character varying, 'term_ended'::character varying])::text[]))))",
        "CREATE TABLE chamber_votes (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            body_type character varying(16) NOT NULL, body_id uuid NOT NULL, legislature_id uuid, jurisdiction_id uuid NOT NULL,
            votable_type character varying(32), votable_id uuid, vote_type character varying(40) NOT NULL,
            vote_method character varying(8) NOT NULL, threshold_basis character varying(16) NOT NULL, stage character varying(12),
            bicameral boolean DEFAULT false NOT NULL, serving_snapshot smallint NOT NULL, held_in_session_id uuid, opened_by_member_id uuid,
            opened_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL, closes_at timestamp(0) with time zone,
            decided_at timestamp(0) with time zone, outcome character varying(12), speaker_tiebreak boolean DEFAULT false NOT NULL,
            rcv_record jsonb, status character varying(8) DEFAULT 'open'::character varying NOT NULL,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone,
            CONSTRAINT chamber_votes_body_type_check CHECK (((body_type)::text = ANY ((ARRAY['legislature'::character varying, 'committee'::character varying, 'board'::character varying])::text[]))),
            CONSTRAINT chamber_votes_outcome_check CHECK (((outcome IS NULL) OR ((outcome)::text = ANY ((ARRAY['adopted'::character varying, 'failed'::character varying, 'tied'::character varying])::text[])))),
            CONSTRAINT chamber_votes_stage_check CHECK (((stage IS NULL) OR ((stage)::text = ANY ((ARRAY['committee'::character varying, 'floor'::character varying])::text[])))),
            CONSTRAINT chamber_votes_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'closed'::character varying, 'void'::character varying])::text[]))),
            CONSTRAINT chamber_votes_threshold_basis_check CHECK (((threshold_basis)::text = ANY ((ARRAY['majority'::character varying, 'supermajority'::character varying])::text[]))),
            CONSTRAINT chamber_votes_vote_method_check CHECK (((vote_method)::text = ANY ((ARRAY['yes_no'::character varying, 'rcv'::character varying])::text[]))))",
        "CREATE TABLE chamber_vote_tallies (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            vote_id uuid NOT NULL, lane character varying(8) NOT NULL, serving smallint NOT NULL, quorum_required smallint NOT NULL, required_yes smallint NOT NULL,
            present smallint, yes smallint DEFAULT '0'::smallint NOT NULL, no smallint DEFAULT '0'::smallint NOT NULL, abstain smallint DEFAULT '0'::smallint NOT NULL,
            quorate boolean, passed boolean,
            CONSTRAINT chamber_vote_tallies_counts_check CHECK ((((yes + no) + abstain) <= serving)),
            CONSTRAINT chamber_vote_tallies_lane_check CHECK (((lane)::text = ANY ((ARRAY['all'::character varying, 'type_a'::character varying, 'type_b'::character varying])::text[]))))",
        "CREATE TABLE vote_casts (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            vote_id uuid NOT NULL, member_id uuid, lane character varying(8) NOT NULL, value character varying(8), rankings jsonb,
            is_tiebreak boolean DEFAULT false NOT NULL, explanation text, cast_via_form character varying(12) NOT NULL,
            public_record_id uuid, cast_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL, board_seat_id uuid,
            CONSTRAINT vote_casts_caster_xor CHECK (((member_id IS NOT NULL) <> (board_seat_id IS NOT NULL))),
            CONSTRAINT vote_casts_lane_check CHECK (((lane)::text = ANY ((ARRAY['all'::character varying, 'type_a'::character varying, 'type_b'::character varying])::text[]))),
            CONSTRAINT vote_casts_value_check CHECK (((value IS NULL) OR ((value)::text = ANY ((ARRAY['yes'::character varying, 'no'::character varying, 'abstain'::character varying])::text[])))),
            CONSTRAINT vote_casts_value_xor_rankings CHECK (((value IS NOT NULL) <> (rankings IS NOT NULL))))",
        "CREATE TABLE chamber_vote_proposals (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            legislature_id uuid NOT NULL, proposal_kind character varying(32) NOT NULL, vote_id uuid,
            payload jsonb DEFAULT '{}'::jsonb NOT NULL, proposed_by_member_id uuid,
            status character varying(12) DEFAULT 'open'::character varying NOT NULL, decided_at timestamp(0) with time zone,
            result_type character varying(40), result_id uuid, created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone,
            CONSTRAINT chamber_vote_proposals_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'adopted'::character varying, 'rejected'::character varying])::text[]))))",
        "CREATE TABLE laws (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            jurisdiction_id uuid NOT NULL, legislature_id uuid NOT NULL, act_number character varying(255) NOT NULL, title character varying(255) NOT NULL,
            kind character varying(24) NOT NULL, scale jsonb NOT NULL, scope_judiciary_id uuid, origin character varying(20) NOT NULL, enacting_bill_id uuid,
            origin_ref_type character varying(32), origin_ref_id uuid, referendum_passed_by_supermajority boolean, shield_expires_with_election_id uuid,
            status character varying(12) DEFAULT 'in_force'::character varying NOT NULL, current_version_no smallint DEFAULT '1'::smallint NOT NULL,
            effective_at timestamp(0) with time zone NOT NULL, enacted_at timestamp(0) with time zone NOT NULL,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT laws_kind_check CHECK (((kind)::text = ANY ((ARRAY['ordinary'::character varying, 'setting_change'::character varying, 'rules_of_order'::character varying, 'ethics_code'::character varying, 'charter'::character varying, 'creation_act'::character varying, 'referendum_act'::character varying, 'constitutional_article'::character varying])::text[]))),
            CONSTRAINT laws_origin_check CHECK (((origin)::text = ANY ((ARRAY['bill'::character varying, 'referendum'::character varying, 'petition_initiative'::character varying, 'judicial_remedy'::character varying, 'founding'::character varying])::text[]))),
            CONSTRAINT laws_status_check CHECK (((status)::text = ANY ((ARRAY['in_force'::character varying, 'amended'::character varying, 'repealed'::character varying, 'superseded'::character varying, 'struck'::character varying])::text[]))))",
        "CREATE TABLE law_versions (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            law_id uuid NOT NULL, version_no smallint NOT NULL, text text NOT NULL, text_hash character(64) NOT NULL, source character varying(24) NOT NULL,
            source_ref_type character varying(32), source_ref_id uuid, created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            CONSTRAINT law_versions_source_check CHECK (((source)::text = ANY ((ARRAY['enactment'::character varying, 'legislative_amendment'::character varying, 'judicial_remedy'::character varying, 'referendum_modification'::character varying, 'merge_incorporation'::character varying])::text[]))))",
        "CREATE TABLE public_records (
            seq bigserial PRIMARY KEY,
            id uuid DEFAULT gen_random_uuid() NOT NULL UNIQUE,
            kind character varying(24) NOT NULL, title character varying(255) NOT NULL, body text, actor_user_id uuid, actor_display character varying(255),
            jurisdiction_id uuid, legislature_id uuid, via_form character varying(16), via_workflow character varying(16), via_clock character varying(8),
            subject_type character varying(40), subject_id uuid, audit_seq bigint, translations jsonb DEFAULT '{}'::jsonb NOT NULL, supersedes_record_id uuid,
            published_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL, created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL, source_server_id uuid,
            CONSTRAINT public_records_kind_check CHECK (((kind)::text = ANY ((ARRAY['registration'::character varying, 'residency'::character varying, 'participation'::character varying, 'statement'::character varying, 'vote'::character varying, 'bill'::character varying, 'act'::character varying, 'minutes'::character varying, 'opinion'::character varying, 'certification'::character varying, 'testimony'::character varying, 'violation'::character varying, 'correction'::character varying, 'other'::character varying, 'moderation_flip'::character varying, 'legal_compliance_removal'::character varying])::text[]))))",
        "CREATE TABLE terms (id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY, office_kind text, office_type text, office_id uuid, holder_user_id uuid, jurisdiction_id uuid, legislature_id uuid, term_class text, starts_on date, ends_on date, source_election_id uuid, source_appointment_id uuid, status text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE clock_timers (id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY, clock_id text, jurisdiction_id uuid, subject_type text, subject_id uuid, armed_at timestamptz, fires_at timestamptz, state text, payload jsonb, override_value jsonb, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",

        // ---- Executive / department / board / governor tables (REAL baseline DDL) ----
        "CREATE TABLE executives (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            jurisdiction_id uuid NOT NULL, type character varying(16) DEFAULT 'committee'::character varying NOT NULL,
            term_number smallint DEFAULT '1'::smallint NOT NULL, term_starts_on date, term_ends_on date,
            status character varying(16) DEFAULT 'forming'::character varying NOT NULL, parent_executive_id uuid, source_legislature_id uuid,
            created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone, deleted_at timestamp(0) without time zone,
            delegation_law_id uuid, delegated_scope text, conversion_process_id uuid, conversion_law_id uuid, converted_at timestamp(0) with time zone, delegated_member_count smallint,
            CONSTRAINT executives_delegated_member_count_check CHECK (((delegated_member_count IS NULL) OR (delegated_member_count >= 5))),
            CONSTRAINT executives_status_check CHECK (((status)::text = ANY ((ARRAY['forming'::character varying, 'delegated'::character varying, 'conversion_voted'::character varying, 'elected'::character varying, 'dissolved'::character varying, 'reverted'::character varying])::text[]))),
            CONSTRAINT executives_type_check CHECK (((type)::text = ANY ((ARRAY['committee'::character varying, 'individual'::character varying])::text[]))))",
        "CREATE TABLE executive_members (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            executive_id uuid NOT NULL, user_id uuid, role character varying(16) DEFAULT 'principal'::character varying NOT NULL,
            rank smallint DEFAULT '0'::smallint NOT NULL, joined_at date, left_at date,
            created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone, deleted_at timestamp(0) without time zone,
            legislature_member_id uuid, elected_in_race_id uuid, term_id uuid,
            selection character varying(24) DEFAULT 'delegated_proportional'::character varying NOT NULL, status character varying(16) DEFAULT 'seated'::character varying NOT NULL,
            CONSTRAINT executive_members_rank_check CHECK (((rank >= 0) AND (rank <= 4))),
            CONSTRAINT executive_members_role_check CHECK (((role)::text = ANY ((ARRAY['principal'::character varying, 'advisor'::character varying])::text[]))),
            CONSTRAINT executive_members_selection_check CHECK (((selection)::text = ANY ((ARRAY['delegated_proportional'::character varying, 'elected_stv'::character varying, 'elected_rcv'::character varying, 'advisor_derivation'::character varying, 'succession'::character varying])::text[]))),
            CONSTRAINT executive_members_status_check CHECK (((status)::text = ANY ((ARRAY['seated'::character varying, 'left'::character varying, 'removed'::character varying, 'succeeded'::character varying, 'term_ended'::character varying])::text[]))))",
        "CREATE TABLE executive_orders (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            executive_id uuid NOT NULL, issued_by_member_id uuid NOT NULL, department_id uuid, order_no character varying(20),
            title character varying(255) NOT NULL, body text NOT NULL, enabling_type character varying(20) NOT NULL, enabling_id uuid NOT NULL,
            target_domain character varying(24) NOT NULL, status character varying(24) DEFAULT 'drafted'::character varying NOT NULL,
            rejection_citation character varying(255), rejection_reason text, record_id uuid, judicial_review_case_id uuid,
            issued_at timestamp(0) with time zone, created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT executive_orders_enabling_type_check CHECK (((enabling_type)::text = ANY ((ARRAY['law'::character varying, 'emergency_power'::character varying, 'charter'::character varying])::text[]))),
            CONSTRAINT executive_orders_rejection_citation_check CHECK ((((status)::text = 'rejected_pre_issuance'::text) = (rejection_citation IS NOT NULL))),
            CONSTRAINT executive_orders_status_check CHECK (((status)::text = ANY ((ARRAY['drafted'::character varying, 'scope_validated'::character varying, 'issued'::character varying, 'rejected_pre_issuance'::character varying, 'under_review'::character varying, 'struck'::character varying, 'revoked'::character varying])::text[]))),
            CONSTRAINT executive_orders_target_domain_check CHECK (((target_domain)::text = ANY ((ARRAY['department_operations'::character varying, 'public_works'::character varying, 'emergency_response'::character varying, 'administration'::character varying, 'other'::character varying, 'electoral_process'::character varying, 'judicial_process'::character varying, 'legislative_process'::character varying])::text[]))))",
        "CREATE TABLE departments (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            jurisdiction_id uuid NOT NULL, executive_id uuid NOT NULL, kind character varying(20) NOT NULL, name character varying(255) NOT NULL,
            charter_law_id uuid NOT NULL, reporting_interval_months smallint, board_id uuid, worker_count integer DEFAULT 0 NOT NULL,
            status character varying(24) DEFAULT 'chartered'::character varying NOT NULL,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT departments_kind_check CHECK (((kind)::text = ANY ((ARRAY['chief_executive'::character varying, 'treasury'::character varying, 'defense'::character varying, 'state'::character varying, 'justice'::character varying, 'other'::character varying])::text[]))),
            CONSTRAINT departments_status_check CHECK (((status)::text = ANY ((ARRAY['chartered'::character varying, 'oversight_assigned'::character varying, 'governors_nominated'::character varying, 'consented'::character varying, 'operating'::character varying, 'reporting'::character varying, 'rechartered'::character varying, 'dissolved'::character varying])::text[]))))",
        "CREATE TABLE department_reports (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            department_id uuid NOT NULL, kind character varying(8) DEFAULT 'periodic'::character varying NOT NULL, period_label character varying(255),
            due_on date NOT NULL, filed_at timestamp(0) with time zone, filed_by_seat_id uuid,
            recipients jsonb DEFAULT '[\"executive\", \"legislature\"]'::jsonb NOT NULL, record_id uuid,
            status character varying(8) DEFAULT 'due'::character varying NOT NULL, created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone,
            CONSTRAINT department_reports_kind_check CHECK (((kind)::text = ANY ((ARRAY['periodic'::character varying, 'special'::character varying])::text[]))),
            CONSTRAINT department_reports_status_check CHECK (((status)::text = ANY ((ARRAY['due'::character varying, 'filed'::character varying, 'overdue'::character varying])::text[]))))",
        "CREATE TABLE boards (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            boardable_type character varying(32) NOT NULL, boardable_id uuid NOT NULL, owner_seats smallint NOT NULL,
            worker_seats smallint DEFAULT '0'::smallint NOT NULL, worker_headcount integer DEFAULT 0 NOT NULL, chair_seat_id uuid,
            composition_valid boolean DEFAULT true NOT NULL, cycle_months smallint DEFAULT '60'::smallint NOT NULL,
            status character varying(12) DEFAULT 'forming'::character varying NOT NULL,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT boards_boardable_type_check CHECK (((boardable_type)::text = ANY ((ARRAY['departments'::character varying, 'organizations'::character varying])::text[]))),
            CONSTRAINT boards_owner_seats_check CHECK ((owner_seats >= 1)),
            CONSTRAINT boards_status_check CHECK (((status)::text = ANY ((ARRAY['forming'::character varying, 'active'::character varying, 'dissolved'::character varying])::text[]))),
            CONSTRAINT boards_worker_seats_check CHECK ((worker_seats >= 0)))",
        "CREATE TABLE board_seats (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            board_id uuid NOT NULL, seat_class character varying(16) NOT NULL, seat_no smallint NOT NULL, holder_user_id uuid, appointment_id uuid,
            elected_in_race_id uuid, term_id uuid, is_chair boolean DEFAULT false NOT NULL, status character varying(20) DEFAULT 'vacant'::character varying NOT NULL,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT board_seats_seat_class_check CHECK (((seat_class)::text = ANY ((ARRAY['governor'::character varying, 'owner_elected'::character varying, 'worker_elected'::character varying])::text[]))),
            CONSTRAINT board_seats_status_check CHECK (((status)::text = ANY ((ARRAY['vacant'::character varying, 'nominated'::character varying, 'seated'::character varying, 'removal_requested'::character varying, 'removed'::character varying, 'term_ended'::character varying])::text[]))))",
        "CREATE TABLE appointments (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            appointable_type character varying(64) NOT NULL, appointable_id uuid NOT NULL, nominee_user_id uuid NOT NULL, nominated_by uuid,
            nominated_via_form character varying(16), consent_vote_id uuid, status character varying(12) DEFAULT 'nominated'::character varying NOT NULL, term_id uuid,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT appointments_status_check CHECK (((status)::text = ANY ((ARRAY['nominated'::character varying, 'consented'::character varying, 'rejected'::character varying, 'seated'::character varying, 'ended'::character varying])::text[]))))",

        // ---- Organizations / CGC tables (REAL baseline DDL) ----
        "CREATE TABLE organizations (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            jurisdiction_id uuid NOT NULL, type character varying(255) DEFAULT 'informal'::character varying NOT NULL, name character varying(255) NOT NULL, slug character varying(255) NOT NULL,
            abbreviation character varying(255), color character varying(7), description text, website_url character varying(255), parent_organization_id uuid,
            is_cgc boolean DEFAULT false NOT NULL, created_by_legislature_id uuid, overseen_by_executive_id uuid,
            ownership_type character varying(255) DEFAULT 'private'::character varying NOT NULL, worker_count integer DEFAULT 0 NOT NULL,
            ip_is_public_domain boolean DEFAULT false NOT NULL, is_active boolean DEFAULT true NOT NULL, is_registered boolean DEFAULT false NOT NULL,
            registered_at timestamp(0) without time zone, dissolved_at timestamp(0) without time zone, dissolution_reason character varying(255),
            created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone, deleted_at timestamp(0) without time zone,
            agent_user_id uuid, structure character varying(20), status character varying(16) DEFAULT 'registered'::character varying NOT NULL,
            registered_by_user_id uuid, registered_via_form character varying(12), purpose text, created_by_law_id uuid, board_id uuid, registration_record_id uuid,
            CONSTRAINT organizations_status_check CHECK (((status)::text = ANY ((ARRAY['registered'::character varying, 'active'::character varying, 'transfer_pending'::character varying, 'transferred'::character varying, 'converted'::character varying, 'dissolved'::character varying])::text[]))),
            CONSTRAINT organizations_structure_check CHECK (((structure IS NULL) OR ((structure)::text = ANY ((ARRAY['stock'::character varying, 'partnership'::character varying, 'equal_partnership'::character varying, 'member_owned'::character varying, 'worker_owned'::character varying, 'nonprofit'::character varying])::text[])))))",
        "CREATE TABLE org_ownership_stakes (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            organization_id uuid NOT NULL, holder_type character varying(16) NOT NULL, holder_id uuid NOT NULL, units numeric(20,6) NOT NULL, pct numeric(7,4),
            acquired_via character varying(12) NOT NULL, source_transfer_id uuid, as_of timestamp(0) with time zone NOT NULL, ended_at timestamp(0) with time zone,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone,
            CONSTRAINT org_ownership_stakes_acquired_via_check CHECK (((acquired_via)::text = ANY ((ARRAY['founding'::character varying, 'issue'::character varying, 'transfer'::character varying, 'conversion'::character varying])::text[]))),
            CONSTRAINT org_ownership_stakes_holder_type_check CHECK (((holder_type)::text = ANY ((ARRAY['users'::character varying, 'organizations'::character varying, 'jurisdictions'::character varying])::text[]))),
            CONSTRAINT org_ownership_stakes_units_check CHECK ((units > (0)::numeric)))",
        "CREATE TABLE org_memberships (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            organization_id uuid NOT NULL, user_id uuid NOT NULL, kind character varying(12) NOT NULL, status character varying(10) DEFAULT 'applied'::character varying NOT NULL,
            applied_at timestamp(0) with time zone NOT NULL, accepted_at timestamp(0) with time zone, ended_at timestamp(0) with time zone, accepted_by_user_id uuid, end_reason character varying(24),
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT org_memberships_kind_check CHECK (((kind)::text = ANY ((ARRAY['member'::character varying, 'shareholder'::character varying, 'partner'::character varying])::text[]))),
            CONSTRAINT org_memberships_status_check CHECK (((status)::text = ANY ((ARRAY['applied'::character varying, 'active'::character varying, 'ended'::character varying, 'declined'::character varying])::text[]))))",
        "CREATE TABLE cgc_ip_register (
            seq bigserial PRIMARY KEY, id uuid DEFAULT gen_random_uuid() NOT NULL, organization_id uuid NOT NULL, asset character varying(255) NOT NULL, kind character varying(24) NOT NULL,
            description text, status character varying(13) DEFAULT 'public_domain'::character varying NOT NULL, dedicated_via_form character varying(12) NOT NULL, dedicated_by_user_id uuid,
            published_record_id uuid, audit_seq bigint, published_at timestamp with time zone, created_at timestamp with time zone DEFAULT now() NOT NULL,
            CONSTRAINT cgc_ip_register_kind_check CHECK (((kind)::text = ANY ((ARRAY['software'::character varying, 'patentable_invention'::character varying, 'copyrightable_work'::character varying, 'design'::character varying, 'data'::character varying, 'process'::character varying, 'other'::character varying])::text[]))),
            CONSTRAINT cgc_ip_register_status_public_domain CHECK (((status)::text = 'public_domain'::text)))",

        // ---- Empty stub tables the RoleService fact queries touch (never populated for these roles) ----
        "CREATE TABLE residency_claims (id uuid PRIMARY KEY, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE candidacies (id uuid PRIMARY KEY, user_id uuid, election_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE elections (id uuid PRIMARY KEY, jurisdiction_id uuid, legislature_id uuid, kind text, status text, deleted_at timestamptz)",
        "CREATE TABLE endorsements (id uuid PRIMARY KEY, election_id uuid, candidate_id uuid, endorser_type text, endorser_id uuid, is_active boolean, withdrawn_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE election_boards (id uuid PRIMARY KEY, jurisdiction_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE election_board_members (id uuid PRIMARY KEY, election_board_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE org_staff_grants (id uuid PRIMARY KEY, grantee_user_id uuid, organization_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE org_workers (id uuid PRIMARY KEY, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE admin_offices (id uuid PRIMARY KEY, status text, deleted_at timestamptz)",
        "CREATE TABLE judiciaries (id uuid PRIMARY KEY, jurisdiction_id uuid, type text, status text, deleted_at timestamptz)",
        "CREATE TABLE judicial_seats (id uuid PRIMARY KEY, judiciary_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE advocates (id uuid PRIMARY KEY, judiciary_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE juries (id uuid PRIMARY KEY, status text, deleted_at timestamptz)",
        "CREATE TABLE jury_members (id uuid PRIMARY KEY, jury_id uuid, user_id uuid, screening_status text, deleted_at timestamptz)",
        "CREATE TABLE committees (id uuid PRIMARY KEY, legislature_id uuid, chair_member_id uuid, alternate_member_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE committee_seats (id uuid PRIMARY KEY, committee_id uuid, member_id uuid, status text, vacated_at timestamptz, deleted_at timestamptz)",
    ];
    foreach ($ddl as $sql) { DB::statement($sql); }
}

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/executive_cgc_journey_fixture.php --run\n"; exit(0); }

$name = 'cga_executive_'.date('Ymd').'_'.bin2hex(random_bytes(8));
$nonce = bin2hex(random_bytes(16));
fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;

try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA executive_fixture; CREATE TABLE executive_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO executive_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null;
    bootFixture($name, $nonce);
    createSchema();

    // -------------------------------------------------------------------
    // Doubles — peripheral collaborators only (plan-sanctioned).
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
    $fakeAchievements = new class($fakeAudit) extends \App\Services\AchievementService {
        public function awardSelf(User $filer, string $awardKey): bool { return true; }
        public function awardSubject(User $subject, string $awardKey): bool { return true; }
        public function awardState(User $holder, string $awardKey): bool { return true; }
    };

    // The fakes are the only things the real services must share. Bind them
    // and let the Foundation container auto-resolve the executive/org layer
    // exactly as it wires it live (fakes injected wherever those deps land).
    $records = new \App\Services\PublicRecordService($fakeAudit);
    app()->instance(\App\Services\AuditService::class, $fakeAudit);
    app()->instance(\App\Services\SettingsResolver::class, $fakeSettings);
    app()->instance(\App\Services\ClockService::class, $fakeClock);
    app()->instance(\App\Services\AchievementService::class, $fakeAchievements);
    app()->instance(\App\Services\PublicRecordService::class, $records);
    app()->bind(\App\Domain\Forms\Contracts\CommitteeRoster::class, \App\Services\Legislature\EloquentCommitteeRoster::class);

    $roles = app(RoleService::class);
    $floorH = app(FloorVoteCast::class);

    // Cast every non-Speaker member "yes" on a whole-chamber vote (the
    // Speaker is structurally barred from casting; the vote auto-closes at
    // full non-Speaker participation).
    $castAllYes = function (string $voteId, array $userIds) use ($floorH, &$actor) {
        foreach ($userIds as $u) {
            $floorH->handle($actor($u), ['vote_id' => $voteId, 'value' => 'yes', 'explanation' => 'In order.']);
        }
    };

    // -------------------------------------------------------------------
    // Seed the world.
    //   Jurisdiction P — an ACTIVE single-chamber legislature (6 Type-A
    //   seats, Speaker A) and a FORMING executive awaiting delegation.
    //   Jurisdiction Q — a foreign delegated executive with one seated
    //   principal (the cross-institution actor).
    // -------------------------------------------------------------------
    $P = uid(1); $Q = uid(2);
    DB::table('jurisdictions')->insert([
        ['id' => $P, 'name' => 'Parentland', 'parent_id' => null],
        ['id' => $Q, 'name' => 'Foreignland', 'parent_id' => null],
    ]);

    $mkUser = function (int $n, string $label) { DB::table('users')->insert(['id' => uid($n), 'name' => $label, 'display_name' => $label, 'is_operator' => false, 'created_at' => now(), 'updated_at' => now()]); return uid($n); };
    $A = $mkUser(11, 'A-speaker'); $B = $mkUser(12, 'B'); $C = $mkUser(13, 'C-principal');
    $Dp = $mkUser(14, 'D'); $Ep = $mkUser(15, 'E'); $Xp = $mkUser(16, 'X');
    $O = $mkUser(17, 'O-outsider'); $Nd = $mkUser(18, 'Nd-dept-governor'); $Nc = $mkUser(19, 'Nc-cgc-governor');
    $Ff = $mkUser(20, 'Ff-foreign-principal');

    foreach ([$A, $B, $C, $Dp, $Ep, $Xp, $O, $Nd, $Nc] as $u) {
        DB::table('residency_confirmations')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $u, 'jurisdiction_id' => $P, 'is_active' => true, 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('residency_confirmations')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $Ff, 'jurisdiction_id' => $Q, 'is_active' => true, 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $LEG = uid(50);
    DB::table('legislatures')->insert(['id' => $LEG, 'jurisdiction_id' => $P, 'term_number' => 1, 'status' => 'active', 'total_seats' => 6, 'type_a_seats' => 6, 'type_b_seats' => 0, 'quorum_required' => 4, 'created_at' => now(), 'updated_at' => now()]);

    // vote_share order: C > B > D > E > A > X. Delegation member_count 5
    // selects the top 5 (C,B,D,E,A) and excludes X (lowest); C is a
    // delegated principal.
    $mA = uid(60); $mB = uid(61); $mC = uid(62); $mD = uid(63); $mE = uid(64); $mX = uid(65);
    $termEnd = \Carbon\CarbonImmutable::now('UTC')->addYears(5)->toDateString();
    $memberRow = function (string $mid, string $uidUser, int $seatNo, float $share) use ($LEG, $termEnd) {
        DB::table('legislature_members')->insert(['id' => $mid, 'legislature_id' => $LEG, 'user_id' => $uidUser, 'seat_type' => 'a', 'seat_no' => $seatNo, 'status' => 'seated', 'seated_on' => now()->toDateString(), 'seated_at' => now(), 'term_ends_on' => $termEnd, 'vote_share_norm' => $share, 'created_at' => now(), 'updated_at' => now()]);
    };
    $memberRow($mA, $A, 1, 0.1000);
    $memberRow($mB, $B, 2, 0.2500);
    $memberRow($mC, $C, 3, 0.3000);
    $memberRow($mD, $Dp, 4, 0.1800);
    $memberRow($mE, $Ep, 5, 0.1300);
    $memberRow($mX, $Xp, 6, 0.0400);
    DB::table('legislatures')->where('id', $LEG)->update(['speaker_id' => $mA]);
    DB::table('legislature_members')->where('id', $mA)->update(['is_speaker' => true]);

    // P's executive — FORMING, awaiting F-LEG-014 delegation.
    $EXE_P = uid(70);
    DB::table('executives')->insert(['id' => $EXE_P, 'jurisdiction_id' => $P, 'type' => 'committee', 'status' => 'forming', 'created_at' => now(), 'updated_at' => now()]);

    // Q's foreign executive — already delegated, with one seated principal Ff.
    $EXE_Q = uid(71);
    DB::table('executives')->insert(['id' => $EXE_Q, 'jurisdiction_id' => $Q, 'type' => 'committee', 'status' => 'delegated', 'delegated_member_count' => 5, 'delegated_scope' => 'Foreign scope', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('executive_members')->insert(['id' => uid(72), 'executive_id' => $EXE_Q, 'user_id' => $Ff, 'role' => 'principal', 'rank' => 0, 'joined_at' => now()->toDateString(), 'selection' => 'delegated_proportional', 'status' => 'seated', 'created_at' => now(), 'updated_at' => now()]);

    $actor = fn (string $id) => User::query()->find($id);
    $castersNonSpeaker = [$B, $C, $Dp, $Ep, $Xp]; // 5 non-Speaker Type-A members
    emit(['stage' => 'seeded', 'legislature' => $LEG, 'executive_p' => $EXE_P, 'executive_q' => $EXE_Q, 'speaker_member' => $mA]);

    // ===================================================================
    // 1. F-LEG-014 — executive delegation act (formation + delegation).
    // ===================================================================
    $delegateH = app(ExecutiveDelegationAct::class);
    $prop = $delegateH->handle($actor($B), ['legislature_id' => $LEG, 'delegated_scope' => 'Administer the treasury and public works of Parentland.', 'member_count' => 5, 'interest' => []]);
    check(isset($prop['vote_id']), 'F-LEG-014 opened no vote');
    $delegVote = ChamberVote::query()->find($prop['vote_id']);
    check($delegVote->vote_type === 'exec_delegate' && $delegVote->threshold_basis === ChamberVote::BASIS_SUPERMAJORITY, 'Delegation vote not a supermajority exec_delegate');
    $castAllYes((string) $delegVote->id, $castersNonSpeaker);
    $delegVote->refresh();
    check($delegVote->status === ChamberVote::STATUS_CLOSED && $delegVote->outcome === ChamberVote::OUTCOME_ADOPTED, "Delegation vote not adopted (status {$delegVote->status} outcome {$delegVote->outcome})");

    $execP = Executive::query()->find($EXE_P);
    check($execP->status === Executive::STATUS_DELEGATED, "Executive not delegated (status {$execP->status})");
    check((int) $execP->delegated_member_count === 5, 'Delegated member count not 5');
    $principals = ExecutiveMember::query()->where('executive_id', $EXE_P)->where('status', 'seated')->where('role', 'principal')->get();
    check($principals->count() === 5, 'Delegation did not seat exactly 5 principals');
    $cMember = ExecutiveMember::query()->where('executive_id', $EXE_P)->where('user_id', $C)->where('status', 'seated')->first();
    check($cMember !== null && $cMember->role === 'principal', 'C is not a seated delegated principal');
    check(ExecutiveMember::query()->where('executive_id', $EXE_P)->where('user_id', $Xp)->exists() === false, 'Lowest vote_share X was wrongly selected (proportional selection not applied)');
    $delegationLaw = Law::query()->where('id', $execP->delegation_law_id)->first();
    check($delegationLaw !== null && $delegationLaw->status === Law::STATUS_IN_FORCE && $delegationLaw->kind === 'creation_act', 'Delegation creation-act law not in force');
    $roles->flush();
    $rolesC = $roles->rolesFor($actor($C));
    check(in_array('R-14', $rolesC, true), 'Principal C did not derive R-14 (got '.implode(',', $rolesC).')');
    emit(['stage' => 'delegated', 'principals' => $principals->count(), 'law' => (string) $delegationLaw->id, 'act_number' => $delegationLaw->act_number, 'C_roles' => $rolesC]);

    // ===================================================================
    // 2. F-LEG-016 — department creation act (distinct department board).
    // ===================================================================
    $deptCreateH = app(DepartmentCreationAct::class);
    $dprop = $deptCreateH->handle($actor($C), [
        'legislature_id' => $LEG, 'kind' => 'treasury', 'name' => 'Department of the Treasury', 'executive_id' => $EXE_P,
        'charter' => ['function_text' => 'Manage public revenue and works.', 'powers_text' => 'Collect, disburse, report.', 'reporting_interval_months' => 6],
        'owner_seats' => 1, 'nominees' => [],
    ]);
    $deptVote = ChamberVote::query()->find($dprop['vote_id']);
    check($deptVote->vote_type === 'procedural_motion' && $deptVote->threshold_basis === ChamberVote::BASIS_MAJORITY, 'Department creation vote not an ordinary-majority procedural_motion');
    $castAllYes((string) $deptVote->id, $castersNonSpeaker);
    $deptVote->refresh();
    check($deptVote->outcome === ChamberVote::OUTCOME_ADOPTED, "Department creation vote not adopted (got {$deptVote->outcome})");

    $dept = Department::query()->where('jurisdiction_id', $P)->where('kind', 'treasury')->first();
    check($dept !== null && (string) $dept->executive_id === $EXE_P, 'Department not chartered under P executive');
    $deptBoard = Board::query()->find($dept->board_id);
    check($deptBoard !== null && $deptBoard->boardable_type === Board::BOARDABLE_DEPARTMENTS && $deptBoard->status === Board::STATUS_FORMING, 'Department board not forming');
    $deptVacant = BoardSeat::query()->where('board_id', $deptBoard->id)->where('seat_class', 'governor')->where('status', 'vacant')->count();
    check($deptVacant === 1, "Department board did not open exactly 1 vacant governor seat (got {$deptVacant})");
    $dueReport = DepartmentReport::query()->where('department_id', $dept->id)->where('status', 'due')->where('kind', 'periodic')->first();
    check($dueReport !== null, 'No DUE periodic report seeded at department chartering');
    emit(['stage' => 'department_chartered', 'department' => (string) $dept->id, 'board' => (string) $deptBoard->id, 'status' => $dept->status, 'due_report' => (string) $dueReport->id]);

    // ===================================================================
    // 3. F-EXE-001 — department governor nomination + refusals.
    // ===================================================================
    $nominateH = app(BoardGovernorNomination::class);

    // Refusal: outsider O holds no seat on this executive.
    refuses('outsider_nomination', 'seated member of THIS executive', fn () => $nominateH->handle($actor($O), ['department_id' => (string) $dept->id, 'nominee_user_id' => $Nd, 'dossier' => 'x']));

    // A former officer: mark E's executive membership LEFT (post-term). E
    // stays a serving legislator, so it never affects the chamber votes.
    ExecutiveMember::query()->where('executive_id', $EXE_P)->where('user_id', $Ep)->update(['status' => 'left', 'left_at' => now()->toDateString()]);
    $roles->flush();
    refuses('former_member_nomination', 'seated member of THIS executive', fn () => $nominateH->handle($actor($Ep), ['department_id' => (string) $dept->id, 'nominee_user_id' => $Nd, 'dossier' => 'x']));

    // Cross-institution: a foreign executive's principal cannot nominate here.
    refuses('foreign_principal_nomination', 'seated member of THIS executive', fn () => $nominateH->handle($actor($Ff), ['department_id' => (string) $dept->id, 'nominee_user_id' => $Nd, 'dossier' => 'x']));

    // Authorized nomination by seated principal C.
    $dnom = $nominateH->handle($actor($C), ['department_id' => (string) $dept->id, 'nominee_user_id' => $Nd, 'dossier' => 'Treasurer nominee dossier.']);
    check(isset($dnom['consent_vote_id']), 'Department nomination opened no consent vote');
    $dConsent = ChamberVote::query()->find($dnom['consent_vote_id']);
    check($dConsent->vote_type === 'bog_consent' && $dConsent->threshold_basis === ChamberVote::BASIS_MAJORITY, 'Department consent vote not a majority bog_consent');
    check(Appointment::query()->find($dnom['appointment_id'])->status === Appointment::STATUS_NOMINATED, 'Department appointment not nominated');
    check(BoardSeat::query()->find($dnom['seat_id'])->status === BoardSeat::STATUS_NOMINATED, 'Department seat not marked nominated');
    emit(['stage' => 'department_governor_nominated', 'appointment' => $dnom['appointment_id'], 'consent_vote' => $dnom['consent_vote_id']]);

    // ===================================================================
    // 4. F-LEG-020 (bog_consent via F-LEG-004) — consent + seat.
    // ===================================================================
    $castAllYes((string) $dConsent->id, $castersNonSpeaker);
    $dConsent->refresh();
    check($dConsent->outcome === ChamberVote::OUTCOME_ADOPTED, "Department consent not adopted (got {$dConsent->outcome})");

    $dSeat = BoardSeat::query()->find($dnom['seat_id']);
    check($dSeat->status === BoardSeat::STATUS_SEATED && (string) $dSeat->holder_user_id === $Nd, 'Department governor seat not seated to Nd');
    $dApp = Appointment::query()->find($dnom['appointment_id']);
    check($dApp->status === Appointment::STATUS_SEATED && $dApp->term_id !== null, 'Department appointment not seated with a term');
    $dTerm = Term::query()->find($dApp->term_id);
    check($dTerm !== null && $dTerm->term_class === Term::CLASS_CIVIL_APPOINTMENT && $dTerm->status === Term::STATUS_ACTIVE, 'Department governor term not an active civil appointment');
    $startY = \Carbon\CarbonImmutable::parse($dTerm->starts_on); $endY = \Carbon\CarbonImmutable::parse($dTerm->ends_on);
    check($startY->addYears(10)->toDateString() === $endY->toDateString(), "Civil appointment not 10 years ({$dTerm->starts_on} -> {$dTerm->ends_on})");
    check(Department::query()->find($dept->id)->status === Department::STATUS_OPERATING, 'Department did not advance to OPERATING after seating its only governor');
    $roles->flush();
    $rolesNd = $roles->rolesFor($actor($Nd));
    check(in_array('R-18', $rolesNd, true), 'Department governor Nd did not derive R-18 (got '.implode(',', $rolesNd).')');
    emit(['stage' => 'department_governor_seated', 'seat' => (string) $dSeat->id, 'term' => (string) $dTerm->id, 'ends_on' => $dTerm->ends_on, 'dept_status' => 'operating', 'Nd_roles' => $rolesNd]);

    // ===================================================================
    // 5. F-BOG-002 — department report (reporting / oversight) + refusal.
    // ===================================================================
    $reportH = app(DepartmentReportFiling::class);

    // Refusal: outsider O holds no seat on THIS department's board.
    refuses('outsider_report', "seated member of THIS department's board", fn () => $reportH->handle($actor($O), ['department_id' => (string) $dept->id, 'kind' => 'periodic', 'body' => 'x']));

    $rep = $reportH->handle($actor($Nd), ['department_id' => (string) $dept->id, 'kind' => 'periodic', 'period_label' => 'H1', 'body' => 'Revenue and works report for the period.']);
    check(DepartmentReport::query()->find($rep['report_id'])->status === DepartmentReport::STATUS_FILED, 'Report not FILED');
    check((string) $rep['filed_by_seat'] === (string) $dSeat->id, 'Report not filed by the seated governor seat');
    check(Department::query()->find($dept->id)->status === Department::STATUS_REPORTING, 'Department did not advance to REPORTING after filing');
    emit(['stage' => 'department_report_filed', 'report' => $rep['report_id'], 'filed_by_seat' => $rep['filed_by_seat'], 'dept_status' => 'reporting']);

    // ===================================================================
    // 6. F-LEG-019 — CGC creation act (distinct CGC board).
    // ===================================================================
    $cgcCreateH = app(CgcCreationAct::class);
    $cprop = $cgcCreateH->handle($actor($C), [
        'legislature_id' => $LEG, 'name' => 'Parentland Water Works', 'charter' => 'Provide clean water as a common good.',
        'goods_services' => 'Municipal water', 'oversight_executive_id' => $EXE_P, 'owner_seats' => 1,
    ]);
    $cgcVote = ChamberVote::query()->find($cprop['vote_id']);
    check($cgcVote->vote_type === 'procedural_motion', 'CGC creation vote not a procedural_motion');
    $castAllYes((string) $cgcVote->id, $castersNonSpeaker);
    $cgcVote->refresh();
    check($cgcVote->outcome === ChamberVote::OUTCOME_ADOPTED, "CGC creation vote not adopted (got {$cgcVote->outcome})");

    $cgc = Organization::query()->where('created_by_legislature_id', $LEG)->where('is_cgc', true)->first();
    check($cgc !== null, 'CGC organization not created');
    check($cgc->type === Organization::TYPE_COMMON_GOOD_CORP && $cgc->status === Organization::STATUS_ACTIVE && (bool) $cgc->is_active === true, 'CGC not active');
    check((string) $cgc->overseen_by_executive_id === $EXE_P, 'CGC not overseen by P executive');
    check((string) $cgc->created_by_legislature_id === $LEG, 'CGC created_by_legislature_id wrong');
    check((bool) $cgc->ip_is_public_domain === true, 'CGC IP not marked public domain');
    $cgcBoard = Board::query()->find($cgc->board_id);
    check($cgcBoard !== null && $cgcBoard->boardable_type === Board::BOARDABLE_ORGANIZATIONS && $cgcBoard->status === Board::STATUS_FORMING, 'CGC board not forming');
    check(BoardSeat::query()->where('board_id', $cgcBoard->id)->where('seat_class', 'governor')->where('status', 'vacant')->count() === 1, 'CGC board did not open 1 vacant governor seat');
    $stake = DB::table('org_ownership_stakes')->where('organization_id', $cgc->id)->whereNull('ended_at')->first();
    check($stake !== null && $stake->holder_type === 'jurisdictions' && (float) $stake->units === 100.0, 'CGC jurisdiction 100% stake not opened');
    check(DB::table('cgc_ip_register')->where('organization_id', $cgc->id)->count() === 1, 'CGC genesis IP dedication not written');
    emit(['stage' => 'cgc_chartered', 'cgc' => (string) $cgc->id, 'board' => (string) $cgcBoard->id, 'stake_units' => (float) $stake->units]);

    // ===================================================================
    // 7. F-EXE-001 (organization path) — CGC governor nominate/consent/seat.
    // ===================================================================
    // Cross-institution refusal on the CGC too.
    refuses('foreign_principal_cgc_nomination', 'seated member of THIS executive', fn () => $nominateH->handle($actor($Ff), ['organization_id' => (string) $cgc->id, 'nominee_user_id' => $Nc, 'dossier' => 'x']));

    $cnom = $nominateH->handle($actor($C), ['organization_id' => (string) $cgc->id, 'nominee_user_id' => $Nc, 'dossier' => 'CGC governor dossier.']);
    $cConsent = ChamberVote::query()->find($cnom['consent_vote_id']);
    check($cConsent->vote_type === 'bog_consent', 'CGC consent vote not bog_consent');
    $castAllYes((string) $cConsent->id, $castersNonSpeaker);
    $cConsent->refresh();
    check($cConsent->outcome === ChamberVote::OUTCOME_ADOPTED, "CGC consent not adopted (got {$cConsent->outcome})");
    $cSeat = BoardSeat::query()->find($cnom['seat_id']);
    check($cSeat->status === BoardSeat::STATUS_SEATED && (string) $cSeat->holder_user_id === $Nc, 'CGC governor seat not seated to Nc');
    $cApp = Appointment::query()->find($cnom['appointment_id']);
    check($cApp->status === Appointment::STATUS_SEATED && $cApp->term_id !== null, 'CGC appointment not seated with a term');
    $cTerm = Term::query()->find($cApp->term_id);
    check($cTerm !== null && $cTerm->term_class === Term::CLASS_CIVIL_APPOINTMENT, 'CGC governor term not a civil appointment');
    emit(['stage' => 'cgc_governor_seated', 'seat' => (string) $cSeat->id, 'term' => (string) $cTerm->id]);

    // ===================================================================
    // 8. F-EXE-005 — executive order (authorized + refusals).
    // ===================================================================
    $orderH = app(ExecutiveOrderHandler::class);
    $enablingLawId = (string) $execP->delegation_law_id; // in-force, jurisdiction P covers P

    // Authorized in-scope order by principal C, naming the overseen department.
    $ord1 = $orderH->handle($actor($C), [
        'action' => 'issue', 'executive_id' => $EXE_P, 'department_id' => (string) $dept->id,
        'enabling_type' => 'law', 'enabling_id' => $enablingLawId, 'target_domain' => 'department_operations',
        'title' => 'Treasury operating directive', 'body' => 'Direct the Treasury to publish quarterly accounts.',
    ]);
    $order1 = ExecutiveOrder::query()->find($ord1['order_id']);
    check($order1->status === ExecutiveOrder::STATUS_ISSUED, 'Order 1 not issued');
    check((bool) preg_match('/\AEO-\d{4}-01\z/', (string) $order1->order_no), "Order 1 number not EO-YYYY-01 (got {$order1->order_no})");
    check($order1->record_id !== null, 'Order 1 published no record');
    emit(['stage' => 'order_issued', 'order' => (string) $order1->id, 'order_no' => $order1->order_no, 'domain' => $order1->target_domain]);

    // A second order increments the advisory-locked serial.
    $ord2 = $orderH->handle($actor($C), [
        'action' => 'issue', 'executive_id' => $EXE_P, 'enabling_type' => 'law', 'enabling_id' => $enablingLawId,
        'target_domain' => 'administration', 'title' => 'Administrative directive', 'body' => 'Standardize departmental correspondence.',
    ]);
    $order2 = ExecutiveOrder::query()->find($ord2['order_id']);
    check((string) $order2->order_no === sprintf('EO-%d-02', now()->year), "Order 2 serial did not increment (got {$order2->order_no})");
    emit(['stage' => 'order_serial_increment', 'order_no' => $order2->order_no]);

    // Refusal: outsider O cannot issue.
    refuses('outsider_order', 'seated member of THIS executive', fn () => $orderH->handle($actor($O), ['action' => 'issue', 'executive_id' => $EXE_P, 'enabling_type' => 'law', 'enabling_id' => $enablingLawId, 'target_domain' => 'administration', 'title' => 't', 'body' => 'b']));

    // Refusal: former delegated member E (status left) cannot issue.
    refuses('former_member_order', 'seated member of THIS executive', fn () => $orderH->handle($actor($Ep), ['action' => 'issue', 'executive_id' => $EXE_P, 'enabling_type' => 'law', 'enabling_id' => $enablingLawId, 'target_domain' => 'administration', 'title' => 't', 'body' => 'b']));

    // Refusal: out-of-scope order (hardened civic-process shield). The
    // handler refuses AND the pre-issuance rejection is recorded.
    $outOfScope = ['action' => 'issue', 'executive_id' => $EXE_P, 'issued_by_member_id' => (string) $cMember->id, 'enabling_type' => 'law', 'enabling_id' => $enablingLawId, 'target_domain' => 'electoral_process', 'title' => 'Change the election', 'body' => 'Attempt to touch the electoral process.'];
    refuses('out_of_scope_order', 'electoral process', fn () => $orderH->handle($actor($C), $outOfScope));
    // Drive the pre-issuance rejection-on-record path explicitly.
    refuses('out_of_scope_preflight', 'electoral process', fn () => app(ExecutiveOrderService::class)->preflight($outOfScope));
    $rejected = ExecutiveOrder::query()->where('executive_id', $EXE_P)->where('status', ExecutiveOrder::STATUS_REJECTED_PRE_ISSUANCE)->first();
    check($rejected !== null && $rejected->rejection_citation !== null && $rejected->order_no === null, 'Out-of-scope order left no rejected_pre_issuance record');
    emit(['stage' => 'out_of_scope_rejected_on_record', 'rejected_order' => (string) $rejected->id, 'citation' => $rejected->rejection_citation]);

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
