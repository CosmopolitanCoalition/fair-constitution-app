<?php
/**
 * S1 · legislature review journey — opt-in disposable-PostgreSQL probe, never
 * the world database. Follows tests/concurrency/judicial_nomination_migration.php:
 * strict cga_legislature_YYYYMMDD_<16hex> name, nonce-guarded fixture_guard row,
 * refusal when public.organizations exists, statement/lock timeouts, DROP in a
 * finally block even on failure. Reads .env only for connection fields.
 *
 * Journey (register row S1 · legislature), all through real handlers/services:
 *   session store (SessionService::call open) -> F-LEG-002 attendance (outsider G refused)
 *   -> F-SPK-002 agenda (non-speaker refused) -> F-SPK-003 quorum (FAILS)
 *   -> F-SPK-008 compel -> F-SPK-003 recount (resume: quorum met, session re-open)
 *   -> committee seated by F-SPK-005 (proper electorate: vote_share extras)
 *   -> chair elected by whole-house RCV (proper electorate, VoteCountingService)
 *   -> F-CHR-001/002/005/006 committee meeting lifecycle (non-chair refused;
 *      no-reconvene gap documented)
 *   -> bill 1: F-LEG-003 -> floor F-LEG-004 (Type-A pass, Type-B NO -> FAILED:
 *      one chamber refusing blocks advance)
 *   -> bill 2: F-LEG-003 -> committee vote (committee_bill) REPORTED
 *      -> F-CHR-003 refer to floor -> floor F-LEG-004 (both chambers pass)
 *      -> EnactmentService::enact writes Law + LawVersion v1 under
 *         pg_advisory_xact_lock act number (inspect versioned law)
 *   -> procedural vote closes TIED -> F-SPK-004 speaker tie-break -> ADOPTED
 *      (non-speaker tie-break refused)
 *   -> F-SPK-009 adjourn (SessionService::adjourn), CLK-02 reset since quorum met.
 *
 * FIDELITY NOTES (deviations from the plan's section-3 literal, each a higher-
 * fidelity real-engine choice, none a criterion miss):
 *   - The enacted law is written by EnactmentService::enact() driven by
 *     BillService::resolveBillVote on the passing bicameral floor vote — the
 *     REAL bill-carry enactment path — not enactDirect() (which the plan named).
 *     enact() writes the same Law + LawVersion v1 under the same
 *     allocateActNumber() pg_advisory_xact_lock. enactDirect on a bill that has
 *     already auto-enacted would double-write; the natural path is the faithful
 *     one and exercises "vote in each required chamber -> enact".
 *   - Committee-stage casts use ChamberVoteService::cast (viaForm F-LEG-005 —
 *     the committee-vote door), because F-LEG-004 rejects committee-stage votes
 *     by design; the protected engine is exercised either way.
 *   - "session-level resume" is the failed-quorum -> compel -> recount -> OPEN
 *     path (plan note; SessionController.php:197-264). F-CHR-006 permanently
 *     closes a meeting (no reconvene door) — documented by a post-adjourn
 *     F-CHR-005 refusal.
 *
 * Doubles — peripheral collaborators only (plan-sanctioned, mirrors the S1 ·
 * elections fixture): AuditService (no chain writes), SettingsResolver
 * (defaults -> supermajority 2/3, quorum via PROTECTED ConstitutionalValidator),
 * ClockService (arm/cancel no-op), AchievementService (award* no-op). Real code
 * under test: the F-LEG/F-SPK/F-CHR handlers, SessionService, BillService,
 * EnactmentService, the PROTECTED ChamberVoteService + VoteCountingService
 * counting core, CommitteeService (chair RCV) + CommitteeAssignmentService,
 * PublicRecordService, and RoleService derivation (R-10 speaker, R-12 chair).
 */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Domain\Forms\Handlers\AgendaSetting;
use App\Domain\Forms\Handlers\AttendanceCompulsionOrder;
use App\Domain\Forms\Handlers\AttendanceRegistration;
use App\Domain\Forms\Handlers\BillIntroduction;
use App\Domain\Forms\Handlers\BillReferralToFloor;
use App\Domain\Forms\Handlers\CommitteeAgendaSetting;
use App\Domain\Forms\Handlers\CommitteeAssignmentAdministration;
use App\Domain\Forms\Handlers\CommitteeMeetingAdjourn;
use App\Domain\Forms\Handlers\CommitteeMeetingCall;
use App\Domain\Forms\Handlers\CommitteeMeetingOpen;
use App\Domain\Forms\Handlers\FloorVoteCast;
use App\Domain\Forms\Handlers\QuorumCountPublication;
use App\Domain\Forms\Handlers\TieBreakingVote;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\LegislatureSession;
use App\Models\SessionAttendance;
use App\Models\User;
use App\Services\BillService;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\EnactmentService;
use App\Services\Legislature\CommitteeAssignmentService;
use App\Services\Legislature\CommitteeService;
use App\Services\Legislature\EloquentCommitteeRoster;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SessionService;
use App\Services\VoteCountingService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('70000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_legislature_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }

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
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'legislature_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_legislature_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}";
    $conn = new PDO($dsn, $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $conn->exec("SET statement_timeout='30s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='30s'");
    return $conn;
}
function identity(PDO $conn, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $conn->query('SELECT current_database() AS db, nonce FROM legislature_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
    check($row !== false && $row['db'] === $name && hash_equals($nonce, $row['nonce']), 'Fixture identity check failed');
    check($conn->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fixture unexpectedly contains public organizations');
}
function bootFixture(string $name, string $nonce): void
{
    $verify = pdo($name); identity($verify, $name, $nonce); $verify = null;
    $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
    // The PROTECTED vote engine resolves vote-type rows from config
    // (constitution.vote_types) — load the real registry so committee_chair /
    // bill_pass / committee_bill / procedural_motion resolve exactly as live.
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
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'legislature_fixture', 'Unexpected schema/search path');
}

function createSchema(): void
{
    $ddl = [
        // ---- Populated / queried tables — REAL baseline DDL (CHECK constraints kept) ----
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
        "CREATE TABLE legislature_sessions (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            legislature_id uuid NOT NULL, session_no integer NOT NULL, called_by_member_id uuid,
            scheduled_for timestamp(0) with time zone, opened_at timestamp(0) with time zone, adjourned_at timestamp(0) with time zone,
            serving_at_open smallint, quorum_required smallint, serving_by_kind jsonb, quorum_required_by_kind jsonb, quorum_met boolean,
            agenda jsonb DEFAULT '[]'::jsonb NOT NULL, minutes_record_id uuid,
            status character varying(16) DEFAULT 'scheduled'::character varying NOT NULL,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT legislature_sessions_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'open'::character varying, 'adjourned'::character varying, 'failed_quorum'::character varying, 'cancelled'::character varying])::text[]))))",
        "CREATE TABLE session_attendance (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            session_id uuid NOT NULL, member_id uuid NOT NULL,
            status character varying(12) DEFAULT 'absent'::character varying NOT NULL,
            recorded_via_form character varying(12), recorded_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            CONSTRAINT session_attendance_status_check CHECK (((status)::text = ANY ((ARRAY['present'::character varying, 'absent'::character varying, 'compelled'::character varying, 'excused'::character varying])::text[]))),
            CONSTRAINT session_attendance_uniq UNIQUE (session_id, member_id))",
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
        "CREATE TABLE committees (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            legislature_id uuid NOT NULL, name character varying(255) NOT NULL, purpose text, seats smallint NOT NULL,
            type_a_seats smallint, type_b_seats smallint, created_by_vote_id uuid, created_by_law_id uuid,
            chair_member_id uuid, alternate_member_id uuid, status character varying(12) DEFAULT 'created'::character varying NOT NULL,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone,
            CONSTRAINT committees_kind_split_check CHECK ((((type_a_seats IS NULL) AND (type_b_seats IS NULL)) OR ((type_a_seats IS NOT NULL) AND (type_b_seats IS NOT NULL) AND ((type_a_seats + type_b_seats) = seats)))),
            CONSTRAINT committees_seats_check CHECK ((seats >= 1)),
            CONSTRAINT committees_status_check CHECK (((status)::text = ANY ((ARRAY['created'::character varying, 'seated'::character varying, 'dissolved'::character varying])::text[]))))",
        "CREATE TABLE committee_seats (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            committee_id uuid NOT NULL, member_id uuid NOT NULL, seat_kind character varying(8),
            status character varying(12) DEFAULT 'assigned'::character varying NOT NULL, assigned_via character varying(16), preference_rank_honored smallint,
            seated_at timestamp(0) with time zone, vacated_at timestamp(0) with time zone, vacated_reason character varying(24),
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone,
            CONSTRAINT committee_seats_assigned_via_check CHECK (((assigned_via IS NULL) OR ((assigned_via)::text = ANY ((ARRAY['algorithm'::character varying, 'tie_break'::character varying, 'whole_house_rcv'::character varying])::text[])))),
            CONSTRAINT committee_seats_kind_check CHECK (((seat_kind IS NULL) OR ((seat_kind)::text = ANY ((ARRAY['type_a'::character varying, 'type_b'::character varying])::text[])))),
            CONSTRAINT committee_seats_status_check CHECK (((status)::text = ANY ((ARRAY['allocated'::character varying, 'assigned'::character varying, 'tie_broken'::character varying, 'seated'::character varying, 'vacated'::character varying])::text[]))))",
        "CREATE TABLE committee_preferences (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            legislature_id uuid NOT NULL, member_id uuid NOT NULL, rankings jsonb DEFAULT '[]'::jsonb NOT NULL,
            submitted_at timestamp(0) with time zone NOT NULL, created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone)",
        "CREATE TABLE committee_meetings (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            committee_id uuid NOT NULL, called_by_member_id uuid NOT NULL, scheduled_for timestamp(0) with time zone NOT NULL,
            agenda jsonb DEFAULT '[]'::jsonb NOT NULL, opened_at timestamp(0) with time zone, adjourned_at timestamp(0) with time zone,
            status character varying(12) DEFAULT 'scheduled'::character varying NOT NULL, minutes_record_id uuid,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone,
            CONSTRAINT committee_meetings_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'open'::character varying, 'adjourned'::character varying])::text[]))))",
        "CREATE TABLE bills (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            legislature_id uuid NOT NULL, jurisdiction_id uuid NOT NULL, sponsor_member_id uuid NOT NULL, title character varying(255) NOT NULL,
            act_type character varying(20) NOT NULL, scale jsonb NOT NULL, scope_judiciary_id uuid, targets_setting_key character varying(255),
            proposed_value jsonb, effective_at timestamp(0) with time zone, status character varying(16) DEFAULT 'introduced'::character varying NOT NULL,
            committee_id uuid, current_version_no smallint DEFAULT '1'::smallint NOT NULL, introduced_at timestamp(0) with time zone,
            passed_at timestamp(0) with time zone, failed_at timestamp(0) with time zone, enacted_at timestamp(0) with time zone, enacted_law_id uuid,
            created_at timestamp(0) with time zone, updated_at timestamp(0) with time zone, deleted_at timestamp(0) with time zone, targets_challenge_id uuid,
            CONSTRAINT bills_act_type_check CHECK (((act_type)::text = ANY ((ARRAY['ordinary'::character varying, 'setting_change'::character varying, 'supermajority'::character varying, 'dual_supermajority'::character varying])::text[]))),
            CONSTRAINT bills_setting_pairing_check CHECK ((((act_type)::text = 'setting_change'::text) = (targets_setting_key IS NOT NULL))),
            CONSTRAINT bills_status_check CHECK (((status)::text = ANY ((ARRAY['introduced'::character varying, 'referred'::character varying, 'in_committee'::character varying, 'reported'::character varying, 'tabled'::character varying, 'on_floor'::character varying, 'passed'::character varying, 'failed'::character varying, 'enacted'::character varying, 'withdrawn'::character varying])::text[]))))",
        "CREATE TABLE bill_versions (
            id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
            bill_id uuid NOT NULL, version_no smallint NOT NULL, law_text text NOT NULL, changed_by_member_id uuid,
            change_kind character varying(24) NOT NULL, created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            CONSTRAINT bill_versions_change_kind_check CHECK (((change_kind)::text = ANY ((ARRAY['introduction'::character varying, 'committee_amendment'::character varying, 'floor_amendment'::character varying])::text[]))))",
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
        "CREATE TABLE terms (id uuid PRIMARY KEY, office_kind text, office_type text, office_id uuid, holder_user_id uuid, jurisdiction_id uuid, legislature_id uuid, term_class text, starts_on date, ends_on date, source_election_id uuid, source_appointment_id uuid, status text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE clock_timers (id uuid PRIMARY KEY, clock_id text, jurisdiction_id uuid, subject_type text, subject_id uuid, armed_at timestamptz, fires_at timestamptz, state text, payload jsonb, override_value jsonb, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        // ---- Empty stub tables the RoleService fact queries touch (never populated for roles) ----
        "CREATE TABLE residency_claims (id uuid PRIMARY KEY, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE candidacies (id uuid PRIMARY KEY, user_id uuid, election_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE organizations (id uuid PRIMARY KEY, jurisdiction_id uuid, type text, name text, slug text, is_active boolean DEFAULT true, is_registered boolean DEFAULT true, agent_user_id uuid, status text, worker_count integer, ip_is_public_domain boolean, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)",
        "CREATE TABLE org_staff_grants (id uuid PRIMARY KEY, grantee_user_id uuid, organization_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE org_memberships (id uuid PRIMARY KEY, user_id uuid, organization_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE org_workers (id uuid PRIMARY KEY, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE boards (id uuid PRIMARY KEY, boardable_type text, status text, deleted_at timestamptz)",
        "CREATE TABLE board_seats (id uuid PRIMARY KEY, board_id uuid, holder_user_id uuid, seat_class text, is_chair boolean DEFAULT false, status text, deleted_at timestamptz)",
        "CREATE TABLE admin_offices (id uuid PRIMARY KEY, status text, deleted_at timestamptz)",
        "CREATE TABLE appointments (id uuid PRIMARY KEY, appointable_type text, appointable_id uuid, nominee_user_id uuid, term_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE judiciaries (id uuid PRIMARY KEY, jurisdiction_id uuid, type text, status text, deleted_at timestamptz)",
        "CREATE TABLE judicial_seats (id uuid PRIMARY KEY, judiciary_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE advocates (id uuid PRIMARY KEY, judiciary_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE juries (id uuid PRIMARY KEY, status text, deleted_at timestamptz)",
        "CREATE TABLE jury_members (id uuid PRIMARY KEY, jury_id uuid, user_id uuid, screening_status text, deleted_at timestamptz)",
        "CREATE TABLE executives (id uuid PRIMARY KEY, jurisdiction_id uuid, type text, status text, deleted_at timestamptz)",
        "CREATE TABLE executive_members (id uuid PRIMARY KEY, executive_id uuid, user_id uuid, role text, rank integer, selection text, status text, deleted_at timestamptz)",
        "CREATE TABLE elections (id uuid PRIMARY KEY, jurisdiction_id uuid, legislature_id uuid, kind text, status text, deleted_at timestamptz)",
        "CREATE TABLE election_boards (id uuid PRIMARY KEY, jurisdiction_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE election_board_members (id uuid PRIMARY KEY, election_board_id uuid, user_id uuid, status text, deleted_at timestamptz)",
        "CREATE TABLE endorsements (id uuid PRIMARY KEY, election_id uuid, candidate_id uuid, endorser_type text, endorser_id uuid, is_active boolean, withdrawn_at timestamptz, deleted_at timestamptz)",
    ];
    foreach ($ddl as $sql) { DB::statement($sql); }
}

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/legislature_journey_fixture.php --run\n"; exit(0); }

$name = 'cga_legislature_'.date('Ymd').'_'.bin2hex(random_bytes(8));
$nonce = bin2hex(random_bytes(16));
fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;

try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA legislature_fixture; CREATE TABLE legislature_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO legislature_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
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

    $records     = new PublicRecordService($fakeAudit);
    $roster      = new EloquentCommitteeRoster();
    $counter     = new VoteCountingService();
    $validator   = new ConstitutionalValidator();
    $roles       = new RoleService();
    $votes       = new ChamberVoteService($fakeAudit, $fakeSettings, $records, $roster, $counter);
    $sessions    = new SessionService($fakeAudit, $fakeSettings, $records, $fakeClock);
    $bills       = new BillService($validator, $records);
    $enact       = new EnactmentService($fakeAudit, $validator, $records, $fakeSettings);
    $committees  = new CommitteeService($votes, $records, $counter, $fakeAudit, $roles);
    $assignments = new CommitteeAssignmentService($fakeAudit);

    // Container bindings the real services reach through app().
    app()->instance(\App\Services\AuditService::class, $fakeAudit);
    app()->instance(\App\Services\SettingsResolver::class, $fakeSettings);
    app()->instance(\App\Services\ClockService::class, $fakeClock);
    app()->instance(\App\Services\AchievementService::class, $fakeAchievements);
    app()->instance(PublicRecordService::class, $records);
    app()->instance(ChamberVoteService::class, $votes);
    app()->instance(SessionService::class, $sessions);
    app()->instance(BillService::class, $bills);
    app()->instance(EnactmentService::class, $enact);
    app()->instance(CommitteeService::class, $committees);
    app()->instance(CommitteeAssignmentService::class, $assignments);
    app()->instance(VoteCountingService::class, $counter);
    app()->instance(ConstitutionalValidator::class, $validator);
    app()->instance(RoleService::class, $roles);
    app()->instance(CommitteeRoster::class, $roster);

    // -------------------------------------------------------------------
    // Seed the world: jurisdiction P, users A..G, an ACTIVE bicameral
    // legislature (5 Type-A seats + 1 Type-B), Speaker A. vote_share_norm
    // is the STV output that F-SPK-005 reads as the proper electorate.
    // -------------------------------------------------------------------
    $P = uid(1);
    DB::table('jurisdictions')->insert(['id' => $P, 'name' => 'Parentland', 'parent_id' => null]);

    $mkUser = function (int $n, string $label) { DB::table('users')->insert(['id' => uid($n), 'name' => $label, 'display_name' => $label, 'is_operator' => false, 'created_at' => now(), 'updated_at' => now()]); return uid($n); };
    $A = $mkUser(11, 'A-speaker'); $B = $mkUser(12, 'B'); $C = $mkUser(13, 'C');
    $Dp = $mkUser(14, 'D'); $Ep = $mkUser(15, 'E'); $Fp = $mkUser(16, 'F-typeb'); $G = $mkUser(17, 'G-outsider');

    foreach ([$A, $B, $C, $Dp, $Ep, $Fp, $G] as $u) {
        DB::table('residency_confirmations')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $u, 'jurisdiction_id' => $P, 'is_active' => true, 'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    $LEG = uid(50);
    DB::table('legislatures')->insert(['id' => $LEG, 'jurisdiction_id' => $P, 'term_number' => 1, 'status' => 'active', 'total_seats' => 6, 'type_a_seats' => 5, 'type_b_seats' => 1, 'quorum_required' => 4, 'created_at' => now(), 'updated_at' => now()]);

    // Members. vote_share order (proper electorate): B>C>D>E>A among Type-A —
    // so F-SPK-005's Type-A extras (top-3) select B,C,D, and A/E stay off the
    // committee. F is the sole Type-B member. seat_no 1..6.
    $mA = uid(60); $mB = uid(61); $mC = uid(62); $mD = uid(63); $mE = uid(64); $mF = uid(65);
    $memberOf = [$A => $mA, $B => $mB, $C => $mC, $Dp => $mD, $Ep => $mE, $Fp => $mF];
    $termEnd = \Carbon\CarbonImmutable::now('UTC')->addYears(5)->toDateString();
    $memberRow = function (string $mid, string $uidUser, string $seatType, int $seatNo, float $share) use ($LEG, $termEnd) {
        DB::table('legislature_members')->insert(['id' => $mid, 'legislature_id' => $LEG, 'user_id' => $uidUser, 'seat_type' => $seatType, 'seat_no' => $seatNo, 'status' => 'seated', 'seated_on' => now()->toDateString(), 'seated_at' => now(), 'term_ends_on' => $termEnd, 'vote_share_norm' => $share, 'created_at' => now(), 'updated_at' => now()]);
    };
    $memberRow($mA, $A, 'a', 1, 0.0500);
    $memberRow($mB, $B, 'a', 2, 0.3000);
    $memberRow($mC, $C, 'a', 3, 0.2500);
    $memberRow($mD, $Dp, 'a', 4, 0.2000);
    $memberRow($mE, $Ep, 'a', 5, 0.1000);
    $memberRow($mF, $Fp, 'b', 6, 1.0000);
    DB::table('legislatures')->where('id', $LEG)->update(['speaker_id' => $mA]);
    DB::table('legislature_members')->where('id', $mA)->update(['is_speaker' => true]);

    $actor = fn (string $id) => User::query()->find($id);
    $legislature = Legislature::query()->find($LEG);
    emit(['stage' => 'seeded', 'legislature' => $LEG, 'type_a' => 5, 'type_b' => 1, 'speaker_member' => $mA]);

    // ROLE electorate check: A derives R-10 (Speaker); everyone R-09.
    $roles->flush();
    $rolesA = $roles->rolesFor($actor($A));
    check(in_array('R-09', $rolesA, true) && in_array('R-10', $rolesA, true), 'Speaker A did not derive R-09 + R-10 (got '.implode(',', $rolesA).')');
    $rolesB = $roles->rolesFor($actor($B));
    check(in_array('R-09', $rolesB, true) && ! in_array('R-10', $rolesB, true), 'Member B roles wrong (got '.implode(',', $rolesB).')');
    emit(['stage' => 'roles_pre', 'A' => $rolesA, 'B_has_R09' => in_array('R-09', $rolesB, true)]);

    // ===================================================================
    // 1. Session lifecycle: attendance, failed quorum, compel, resume.
    // ===================================================================
    $session = $sessions->call($legislature, null, null, true); // opens now
    check($session->status === LegislatureSession::STATUS_OPEN, 'Session did not open');
    check(SessionAttendance::query()->where('session_id', $session->id)->where('status', 'absent')->count() === 6, 'Attendance rows not materialized absent');
    emit(['stage' => 'session_open', 'session' => (string) $session->id, 'session_no' => $session->session_no]);

    $attend = new AttendanceRegistration($sessions);
    // Refusal: outsider G is not a member of this chamber.
    refuses('attendance_outsider', 'holds no current seat', fn () => $attend->handle($actor($G), ['session_id' => (string) $session->id]));

    // Only A and B present at first (2 of 6 — below quorum 4).
    foreach ([$A, $B] as $u) {
        $r = $attend->handle($actor($u), ['session_id' => (string) $session->id]);
        check($r['status'] === 'present', "Attendance for member not present");
    }

    // F-SPK-002 agenda — Speaker only. Non-speaker B refused.
    $agenda = new AgendaSetting($sessions);
    refuses('agenda_non_speaker', 'Only the Speaker sets the agenda', fn () => $agenda->handle($actor($B), ['session_id' => (string) $session->id, 'items' => []]));
    $ag = $agenda->handle($actor($A), ['session_id' => (string) $session->id, 'items' => [['kind' => 'general', 'title' => 'Consider the omnibus bill']]]);
    emit(['stage' => 'agenda_set', 'items' => count($ag['agenda'])]);

    // F-SPK-003 quorum count — present 2 < 4 -> FAILED_QUORUM.
    $quorum = new QuorumCountPublication($sessions);
    $q1 = $quorum->handle($actor($A), ['session_id' => (string) $session->id]);
    check($q1['met'] === false && $q1['present'] === 2, "Expected failed quorum at 2 present, got {$q1['present']} met={$q1['met']}");
    check(LegislatureSession::query()->whereKey($session->id)->value('status') === LegislatureSession::STATUS_FAILED_QUORUM, 'Session not marked failed_quorum');
    emit(['stage' => 'failed_quorum', 'present' => $q1['present'], 'required' => $q1['quorum_required']]);

    // F-SPK-008 compel — flips the 4 still-absent to compelled (WF-LEG-20).
    $compelH = new AttendanceCompulsionOrder($sessions);
    $comp = $compelH->handle($actor($A), ['session_id' => (string) $session->id]);
    check($comp['compelled'] === 4, "Expected 4 compelled, got {$comp['compelled']}");
    emit(['stage' => 'compelled', 'compelled' => $comp['compelled']]);

    // F-SPK-003 recount — compelled count as present (resume): 6 >= 4, per-kind
    // met -> session returns to OPEN.
    $q2 = $quorum->handle($actor($A), ['session_id' => (string) $session->id]);
    check($q2['met'] === true && $q2['present'] === 6, "Recount did not meet quorum (present {$q2['present']} met={$q2['met']})");
    check(LegislatureSession::query()->whereKey($session->id)->value('status') === LegislatureSession::STATUS_OPEN, 'Session did not resume to open after recount');
    check($q2['by_kind']['type_a']['met'] === true && $q2['by_kind']['type_b']['met'] === true, 'Per-kind quorum not met on recount');
    emit(['stage' => 'resume_recount', 'present' => $q2['present'], 'by_kind' => $q2['by_kind']]);

    // ===================================================================
    // 2. Committee assignment (F-SPK-005) — proper electorate = vote_share.
    // ===================================================================
    $COMM = uid(70);
    DB::table('committees')->insert(['id' => $COMM, 'legislature_id' => $LEG, 'name' => 'Rules Committee', 'purpose' => 'Order of business', 'seats' => 4, 'type_a_seats' => 3, 'type_b_seats' => 1, 'status' => 'created', 'created_at' => now(), 'updated_at' => now()]);
    // Preferences: the committee-bound members rank the committee (honored at depth 1).
    foreach ([$mB, $mC, $mD, $mF] as $mid) {
        DB::table('committee_preferences')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'legislature_id' => $LEG, 'member_id' => $mid, 'rankings' => json_encode([$COMM]), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $assignH = new CommitteeAssignmentAdministration($assignments, $records);
    $snapshot = $assignH->handle($actor($A), ['legislature_id' => $LEG]);
    check(Committee::query()->whereKey($COMM)->value('status') === Committee::STATUS_SEATED, 'Committee not seated by F-SPK-005');
    $seatMembers = DB::table('committee_seats')->where('committee_id', $COMM)->pluck('seat_kind', 'member_id');
    check($seatMembers->count() === 4, 'Committee did not seat exactly 4');
    check(isset($seatMembers[$mB], $seatMembers[$mC], $seatMembers[$mD], $seatMembers[$mF]), 'Committee membership not {B,C,D,F}');
    check(! isset($seatMembers[$mA]) && ! isset($seatMembers[$mE]), 'Lowest-vote-share A/E wrongly seated — vote_share electorate not applied');
    check($seatMembers[$mB] === 'type_a' && $seatMembers[$mF] === 'type_b', 'Committee seat kinds wrong');
    // The proper electorate is provable in the snapshot: Type-A extras = the
    // highest-vote_share members (B,C,D), by q-ledger #q2.
    check($snapshot['partitions']['type_a']['extras'] === [$mB, $mC, $mD], 'Type-A extras not the highest vote_share members (proper electorate)');
    emit(['stage' => 'committee_assigned', 'seated' => $seatMembers->keys()->all(), 'type_a_extras' => $snapshot['partitions']['type_a']['extras']]);

    // ===================================================================
    // 3. Committee chair — whole-house RCV (proper electorate, VoteCountingService).
    // ===================================================================
    $committeeModel = Committee::query()->find($COMM);
    $chairVote = $committees->openChairBallot($committeeModel);
    check($chairVote->vote_type === 'committee_chair' && $chairVote->vote_method === ChamberVote::METHOD_RCV, 'Chair ballot not an RCV committee_chair vote');

    // Refusal: a ballot may only rank SEATED committee members (R-12 requires R-11).
    refuses('chair_rank_non_member', 'must name seated members', fn () => $committees->recordChairCast($chairVote, $committeeModel, LegislatureMember::query()->find($mB), [$mA]));

    // All serving members cast (constitutive election, Speaker included), each
    // ranking [B,C,D,F] -> B wins, C is the exclusion-derived alternate.
    $chairRanking = [$mB, $mC, $mD, $mF];
    foreach ([$mA, $mB, $mC, $mD, $mE, $mF] as $mid) {
        $committees->recordChairCast($chairVote->refresh(), $committeeModel->refresh(), LegislatureMember::query()->find($mid), $chairRanking);
    }
    $committeeModel->refresh();
    check((string) $committeeModel->chair_member_id === $mB, 'Chair not elected B by whole-house RCV');
    check((string) $committeeModel->alternate_member_id === $mC, 'Alternate not derived C by sequential exclusion');
    check(ChamberVote::query()->whereKey($chairVote->id)->value('status') === ChamberVote::STATUS_CLOSED, 'Chair ballot did not close');
    $roles->flush();
    $rolesChair = $roles->rolesFor($actor($B));
    check(in_array('R-12', $rolesChair, true), 'Chair B did not derive R-12 (got '.implode(',', $rolesChair).')');
    emit(['stage' => 'chair_elected', 'chair' => $mB, 'alternate' => $mC, 'chair_roles' => $rolesChair]);

    // ===================================================================
    // 4. Committee meeting lifecycle (F-CHR-001/002/005/006).
    // ===================================================================
    $callH = new CommitteeMeetingCall();
    $cAgendaH = new CommitteeAgendaSetting();
    $openH = new CommitteeMeetingOpen($committees);
    $adjournH = new CommitteeMeetingAdjourn($committees);

    // Refusal: a committee member who is not the chair/alternate cannot call.
    refuses('meeting_call_non_chair', "chair or alternate", fn () => $callH->handle($actor($Dp), ['committee_id' => $COMM]));

    $call = $callH->handle($actor($B), ['committee_id' => $COMM, 'agenda' => ['Open hearing']]);
    $MEET = $call['meeting_id'];
    check(CommitteeMeeting::query()->whereKey($MEET)->value('status') === CommitteeMeeting::STATUS_SCHEDULED, 'Meeting not scheduled');
    $cAgendaH->handle($actor($B), ['meeting_id' => $MEET, 'agenda' => ['Item 1', 'Item 2']]);
    $openR = $openH->handle($actor($B), ['meeting_id' => $MEET]);
    check($openR['status'] === CommitteeMeeting::STATUS_OPEN, 'Meeting did not open');
    $adjR = $adjournH->handle($actor($B), ['meeting_id' => $MEET, 'minutes_body' => 'Hearing concluded; bill considered.']);
    check($adjR['status'] === CommitteeMeeting::STATUS_ADJOURNED && $adjR['minutes_record_id'] !== '', 'Meeting did not adjourn with minutes');
    emit(['stage' => 'committee_meeting', 'meeting' => $MEET, 'minutes' => $adjR['minutes_record_id']]);

    // Documented gap (plan note): F-CHR-006 permanently closes — no reconvene
    // door. Re-opening the adjourned meeting is refused.
    refuses('meeting_no_reconvene', 'Only a scheduled meeting opens', fn () => $openH->handle($actor($B), ['meeting_id' => $MEET]));

    // ===================================================================
    // 5. Bill 1 — one chamber refusing blocks advance (bicameral floor vote).
    // ===================================================================
    $introH = new BillIntroduction($bills);
    $floorH = new FloorVoteCast($votes);

    $b1 = $introH->handle($actor($B), ['legislature_id' => $LEG, 'title' => 'Failing Bill', 'act_type' => 'ordinary', 'law_text' => 'Section 1. This bill fails.']);
    $bill1 = Bill::query()->find($b1['bill_id']);
    $vote1 = $bills->moveToFloor($bill1, null, LegislatureMember::query()->find($mB));
    check($vote1 !== null && $vote1->bicameral === true, 'Bill 1 floor vote not bicameral');
    // Type-A pass: B,C,D,E vote yes. Type-B: F votes NO.
    foreach ([$B, $C, $Dp, $Ep] as $u) { $floorH->handle($actor($u), ['vote_id' => (string) $vote1->id, 'value' => 'yes']); }
    $floorH->handle($actor($Fp), ['vote_id' => (string) $vote1->id, 'value' => 'no', 'explanation' => 'The Type-B chamber does not consent.']);
    $vote1->refresh();
    check($vote1->status === ChamberVote::STATUS_CLOSED, 'Bill 1 vote did not auto-close');
    check($vote1->outcome === ChamberVote::OUTCOME_FAILED, "Bill 1 outcome not FAILED (got {$vote1->outcome})");
    check(Bill::query()->whereKey($bill1->id)->value('status') === Bill::STATUS_FAILED, 'Bill 1 not marked failed');
    check(Bill::query()->whereKey($bill1->id)->value('enacted_law_id') === null, 'Failed bill wrongly enacted a law');
    $tA = DB::table('chamber_vote_tallies')->where('vote_id', $vote1->id)->where('lane', 'type_a')->first();
    $tB = DB::table('chamber_vote_tallies')->where('vote_id', $vote1->id)->where('lane', 'type_b')->first();
    check($tA->passed === true && $tB->passed === false, 'Bill 1 lanes wrong: Type-A must pass, Type-B must refuse');
    emit(['stage' => 'bill1_failed', 'outcome' => $vote1->outcome, 'type_a_passed' => $tA->passed, 'type_b_passed' => $tB->passed]);

    // ===================================================================
    // 6. Bill 2 — committee -> REPORTED -> F-CHR-003 -> floor pass -> enact.
    // ===================================================================
    $b2 = $introH->handle($actor($C), ['legislature_id' => $LEG, 'title' => 'Order of Business Act', 'act_type' => 'ordinary', 'law_text' => 'Section 1. The order of business is fixed.']);
    $bill2 = Bill::query()->find($b2['bill_id']);
    $bills->referToCommittee($bill2, $COMM);
    check(Bill::query()->whereKey($bill2->id)->value('status') === Bill::STATUS_IN_COMMITTEE, 'Bill 2 not in committee');

    // Committee-stage vote (committee_bill, per-kind on the committee body).
    // Cast via the protected engine (F-LEG-005 is the committee-vote door;
    // F-LEG-004 rejects committee-stage casts by design).
    $cVote = $bills->openCommitteeVote($bill2->refresh(), $COMM);
    check($cVote->body_type === ChamberVote::BODY_COMMITTEE && $cVote->stage === ChamberVote::STAGE_COMMITTEE, 'Committee vote not committee-stage');
    foreach ([$mB, $mC, $mD] as $mid) { $votes->cast($cVote, LegislatureMember::query()->find($mid), 'yes', null, null, 'F-LEG-005'); }
    $votes->cast($cVote, LegislatureMember::query()->find($mF), 'yes', null, null, 'F-LEG-005');
    $cVote->refresh();
    check($cVote->outcome === ChamberVote::OUTCOME_ADOPTED, "Committee vote not adopted (got {$cVote->outcome})");
    check(Bill::query()->whereKey($bill2->id)->value('status') === Bill::STATUS_REPORTED, 'Committee adoption did not report the bill');
    emit(['stage' => 'bill2_reported', 'committee_vote' => (string) $cVote->id]);

    // F-CHR-003 — chair B refers the reported bill to the floor (opens the
    // bicameral floor vote).
    $referH = new BillReferralToFloor($bills);
    $ref = $referH->handle($actor($B), ['bill_id' => (string) $bill2->id]);
    check($ref['bill_status'] === Bill::STATUS_ON_FLOOR && $ref['floor_vote_id'] !== null, 'F-CHR-003 did not move bill to floor');
    $vote2 = ChamberVote::query()->find($ref['floor_vote_id']);
    check($vote2->bicameral === true, 'Bill 2 floor vote not bicameral');

    // Both chambers pass: Type-A B,C,D,E yes; Type-B F yes.
    foreach ([$B, $C, $Dp, $Ep] as $u) { $floorH->handle($actor($u), ['vote_id' => (string) $vote2->id, 'value' => 'yes', 'explanation' => 'In order.']); }
    $floorH->handle($actor($Fp), ['vote_id' => (string) $vote2->id, 'value' => 'yes', 'explanation' => 'The Type-B chamber consents.']);
    $vote2->refresh();
    check($vote2->outcome === ChamberVote::OUTCOME_ADOPTED, "Bill 2 floor vote not adopted (got {$vote2->outcome})");
    check(Bill::query()->whereKey($bill2->id)->value('status') === Bill::STATUS_ENACTED, 'Adopted bill 2 not enacted');

    // Inspect the versioned law: Law in force + LawVersion v1 written under the
    // pg_advisory_xact_lock act number by EnactmentService::enact (via
    // BillService::resolveBillVote on the closing transaction).
    $lawId = Bill::query()->whereKey($bill2->id)->value('enacted_law_id');
    check($lawId !== null, 'Enacted bill 2 carries no law id');
    $law = Law::query()->find($lawId);
    check($law !== null && $law->status === Law::STATUS_IN_FORCE, 'Law not in force');
    check((bool) preg_match('/\AAct \d{4}-\d+\z/', (string) $law->act_number), "Act number not serial-formatted: {$law->act_number}");
    check((int) $law->current_version_no === 1, 'Law current_version_no not 1');
    $lv = LawVersion::query()->where('law_id', $lawId)->where('version_no', 1)->first();
    check($lv !== null, 'LawVersion v1 not written');
    check($lv->source === LawVersion::SOURCE_ENACTMENT, 'LawVersion v1 source not enactment');
    check($lv->text_hash === hash('sha256', $lv->text), 'LawVersion v1 text_hash mismatch');
    check((string) $lv->source_ref_type === 'bill' && (string) $lv->source_ref_id === (string) $bill2->id, 'LawVersion v1 not sourced to the enacting bill');
    check(LawVersion::query()->where('law_id', $lawId)->count() === 1, 'More than one version on a freshly enacted law');
    emit(['stage' => 'bill2_enacted', 'law' => (string) $law->id, 'act_number' => $law->act_number, 'version_no' => (int) $lv->version_no, 'source' => $lv->source]);

    // ===================================================================
    // 7. Speaker tie-break (F-SPK-004) on a procedural vote that closes TIED.
    // ===================================================================
    $procVote = $votes->open(
        bodyType: ChamberVote::BODY_LEGISLATURE,
        bodyId: $LEG,
        voteType: 'procedural_motion',
        opener: LegislatureMember::query()->find($mB),
    );
    check($procVote->bicameral === true, 'Procedural vote not bicameral');
    // Type-A splits 2-2 (B,C yes; D,E no) -> resolvable tie. Type-B F yes -> pass.
    foreach ([$B, $C] as $u) { $floorH->handle($actor($u), ['vote_id' => (string) $procVote->id, 'value' => 'yes']); }
    foreach ([$Dp, $Ep] as $u) { $floorH->handle($actor($u), ['vote_id' => (string) $procVote->id, 'value' => 'no']); }
    $floorH->handle($actor($Fp), ['vote_id' => (string) $procVote->id, 'value' => 'yes']);
    $procVote->refresh();
    check($procVote->outcome === ChamberVote::OUTCOME_TIED, "Procedural vote did not close TIED (got {$procVote->outcome})");
    emit(['stage' => 'tie_reached', 'vote' => (string) $procVote->id, 'outcome' => $procVote->outcome]);

    // Refusal: only the Speaker may cast the tie-break.
    $tieH = new TieBreakingVote($votes);
    refuses('tiebreak_non_speaker', 'Speaker', fn () => $tieH->handle($actor($B), ['vote_id' => (string) $procVote->id, 'value' => 'yes']));

    // Speaker A breaks the tie (Type-A lane) -> adopted.
    $tieRes = $tieH->handle($actor($A), ['vote_id' => (string) $procVote->id, 'value' => 'yes', 'explanation' => 'The chair resolves the tie.']);
    check($tieRes['outcome'] === ChamberVote::OUTCOME_ADOPTED, "Tie-break did not adopt (got {$tieRes['outcome']})");
    check(ChamberVote::query()->whereKey($procVote->id)->value('speaker_tiebreak') === true, 'speaker_tiebreak flag not set');
    emit(['stage' => 'tiebreak_resolved', 'outcome' => $tieRes['outcome']]);

    // ===================================================================
    // 8. Adjournment — session met quorum, so CLK-02 resets on adjourn.
    // ===================================================================
    $adjourned = $sessions->adjourn(LegislatureSession::query()->find($session->id), 'Session minutes: business concluded.');
    check($adjourned->status === LegislatureSession::STATUS_ADJOURNED, 'Session did not adjourn');
    check($adjourned->minutes_record_id !== null, 'Adjournment published no minutes');
    check(Legislature::query()->whereKey($LEG)->value('last_met_on') !== null, 'CLK-02 last_met_on not stamped on a quorate adjournment');
    emit(['stage' => 'adjourned', 'session' => (string) $adjourned->id, 'last_met_on' => Legislature::query()->whereKey($LEG)->value('last_met_on')]);

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
