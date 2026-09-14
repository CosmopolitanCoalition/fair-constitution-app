<?php
/**
 * S1 · cases review journey (register row "S1 · cases").
 *
 * A multi-actor civil/criminal case lifecycle driven through the REAL hardened
 * judiciary domain services (CaseService, PanelService, JuryService,
 * CaseFilingService, AdvocateService, PanelSizing, ConstitutionalValidator,
 * JudicialActor) against a nonce-guarded DISPOSABLE PostgreSQL database — never
 * the live world. Boot/guard/cleanup follow tests/concurrency/judicial_nomination_migration.php.
 *
 * Actors: complainant, accused, advocate, judge, juror, witness, outsider.
 * Stages exercised: filing, acceptance/dismissal, panel conflicts (recusal +
 * re-draw, odd bench), juror screening (voir-dire excuse + replacement),
 * evidence/testimony docket + judge ruling, hearing, hearing-room live floor
 * (RoomFloorService/LiveFloorService: witness raise -> presider recognizes onto
 * the stand -> yield), deliberation, verdict (jury + panel majority), sentencing,
 * opinion, preserved records (audit chain re-verifies). Refusals: non-judge
 * acceptance, non-panel verdict actor, jury-verdict-without-empaneled-jury,
 * panel-vote math, illegal excusal reason, non-presider floor control,
 * closed-case attach-window + illegal transitions + closed hearing-room floor,
 * double-jeopardy re-filing.
 *
 * Opt-in: php tests/concurrency/case_multi_actor_journey.php --run
 */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\Advocate;
use App\Models\CaseFiling;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jury;
use App\Models\JuryMember;
use App\Models\Opinion;
use App\Models\Panel;
use App\Models\PanelJudge;
use App\Models\SentencingOrder;
use App\Models\User;
use App\Models\Verdict;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\Judiciary\AdvocateService;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;
use App\Services\Judiciary\JuryService;
use App\Services\Judiciary\PanelService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\RoomFloorService;
use App\Services\SettingsResolver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('60000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_cases_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }

function connectionConfig(string $name): array
{
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'cases_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}

function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_cases_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='30s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='30s'");
    return $pdo;
}

function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM cases_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
    check($row !== false && $row['db'] === $name && hash_equals($nonce, $row['nonce']), 'Fixture identity check failed');
    check($pdo->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fixture unexpectedly contains public organizations');
}

function bootFixture(string $name, string $nonce): void
{
    $verify = pdo($name); identity($verify, $name, $nonce); $verify = null;
    $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
    $app->instance('config', new Illuminate\Config\Repository([
        'app' => ['key' => '', 'timezone' => 'UTC'],
        'cache' => ['default' => 'array', 'prefix' => 'cases_fixture', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
        'matrix' => ['server_name' => 'fixture.local'],
    ]));
    $events = new Illuminate\Events\Dispatcher($app);
    $app->instance('events', $events); $app->instance(Illuminate\Contracts\Events\Dispatcher::class, $events);
    $capsule = new Capsule($app); $capsule->addConnection(connectionConfig($name), 'fixture');
    $capsule->getDatabaseManager()->setDefaultConnection('fixture'); $capsule->setEventDispatcher($events); $capsule->setAsGlobal(); $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('db.schema', $capsule->getConnection('fixture')->getSchemaBuilder());
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    // now() / model timestamps resolve the Date factory from the container.
    $app->instance('date', new Illuminate\Support\DateFactory);
    $cache = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore);
    $app->instance(Illuminate\Contracts\Cache\Repository::class, $cache);
    // The Cache facade root ('cache' = CacheManager) — LiveFloorService drives the live floor through it.
    $app->instance('cache', new Illuminate\Cache\CacheManager($app));
    $app->instance(Illuminate\Log\Context\Repository::class, new Illuminate\Log\Context\Repository($events));
    $fakeBus = new Illuminate\Support\Testing\Fakes\BusFake(new Illuminate\Bus\Dispatcher($app));
    $app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $fakeBus);
    foreach (["SET statement_timeout='30s'", "SET lock_timeout='8s'", "SET idle_in_transaction_session_timeout='30s'"] as $setting) DB::statement($setting);
    identity(DB::connection()->getPdo(), $name, $nonce);
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'cases_fixture', 'Unexpected schema/search path');
}

/** Assert a callable raises a ConstitutionalViolation (optionally with an exact citation / message substring). */
function refuses(string $label, callable $fn, ?string $citation = null, ?string $contains = null): void
{
    try {
        $fn();
    } catch (ConstitutionalViolation $e) {
        if ($citation !== null) check($e->citation === $citation, "{$label}: wrong citation [{$e->citation}] (message: {$e->getMessage()})");
        if ($contains !== null) check(str_contains($e->getMessage(), $contains), "{$label}: message did not contain [{$contains}] — got [{$e->getMessage()}]");
        emit(['refusal_held' => $label, 'citation' => $e->citation]);
        return;
    }
    throw new RuntimeException("Expected refusal did not occur: {$label}");
}

/** Assert a callable aborts with an HTTP status (the room controllers use abort/abort_unless, not ConstitutionalViolation). */
function refusesHttp(string $label, callable $fn, int $status, ?string $contains = null): void
{
    try {
        $fn();
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
        check($e->getStatusCode() === $status, "{$label}: wrong status [{$e->getStatusCode()}] (expected {$status}; message: {$e->getMessage()})");
        if ($contains !== null) check(str_contains($e->getMessage(), $contains), "{$label}: message did not contain [{$contains}] — got [{$e->getMessage()}]");
        emit(['room_refusal_held' => $label, 'status' => $e->getStatusCode()]);
        return;
    }
    throw new RuntimeException("Expected room refusal did not occur: {$label}");
}

/** Replicate PanelService::deterministicOrder over seat ids for a fixed seed. */
function panelOrder(array $seatIds, string $seed): array
{
    usort($seatIds, fn (string $a, string $b): int => hash('sha256', $seed.'|'.$a) <=> hash('sha256', $seed.'|'.$b));
    return array_values($seatIds);
}

function createSchema(): void
{
    DB::unprepared(<<<'SQL'
    CREATE TABLE users (id uuid PRIMARY KEY, name text, display_name text, email text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE residency_confirmations (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL, jurisdiction_id uuid NOT NULL,
        days_confirmed smallint NOT NULL DEFAULT 30, confirmed_at timestamptz NOT NULL DEFAULT now(),
        voting_right_active boolean NOT NULL DEFAULT true, candidacy_right_active boolean NOT NULL DEFAULT true,
        is_active boolean NOT NULL DEFAULT true, created_at timestamptz, updated_at timestamptz);

    CREATE TABLE judiciaries (
        id uuid PRIMARY KEY, jurisdiction_id uuid NOT NULL, court_name text, type text NOT NULL,
        min_judges smallint NOT NULL DEFAULT 5, term_years smallint, status text NOT NULL,
        parent_judiciary_id uuid, nomination_mode text, judge_count int,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz,
        CONSTRAINT judiciaries_min_judges_check CHECK (min_judges >= 5));

    CREATE TABLE judicial_seats (
        id uuid PRIMARY KEY, judiciary_id uuid NOT NULL, user_id uuid, seat_number int, seat_class text,
        status text NOT NULL, term_starts_on date, term_ends_on date,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE cases (
        id uuid PRIMARY KEY, docket_no text NOT NULL, judiciary_id uuid NOT NULL, jurisdiction_id uuid NOT NULL,
        kind text NOT NULL, title text NOT NULL, statement_of_claim text, claimed_severity text, court_severity text,
        jury_entitled boolean NOT NULL DEFAULT false, jury_waived boolean NOT NULL DEFAULT false,
        filed_via_form text, filed_by_user_id uuid, filed_on_behalf_of_user_id uuid, advocate_id uuid,
        panel_id uuid, jury_id uuid, appeal_of_case_id uuid, status text NOT NULL,
        double_jeopardy_locked boolean NOT NULL DEFAULT false,
        accepted_at timestamptz, decided_at timestamptz, closed_at timestamptz,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
    CREATE UNIQUE INDEX cases_judiciary_docket_unique ON cases (judiciary_id, docket_no) WHERE deleted_at IS NULL;

    CREATE TABLE case_parties (
        id uuid PRIMARY KEY, case_id uuid NOT NULL, party_role text NOT NULL, party_type text,
        party_user_id uuid, party_ref_type text, party_ref_id uuid, represented_by_advocate_id uuid,
        retainer_note text, status text NOT NULL, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE panels (
        id uuid PRIMARY KEY, case_id uuid NOT NULL, judiciary_id uuid NOT NULL, size int NOT NULL,
        is_en_banc boolean NOT NULL DEFAULT false, severity_basis text, presiding_judge_seat_id uuid,
        draw_seed text, status text NOT NULL, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz,
        CONSTRAINT panels_size_odd_check CHECK (size >= 3 AND size % 2 = 1));

    CREATE TABLE panel_judges (
        id uuid PRIMARY KEY, panel_id uuid NOT NULL, judicial_seat_id uuid NOT NULL, user_id uuid,
        is_presiding boolean NOT NULL DEFAULT false, screening_result text, recusal_reason text,
        status text NOT NULL, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE juries (
        id uuid PRIMARY KEY, case_id uuid NOT NULL, selection_order_id uuid, pool_size int, eligible_jurisdiction_id uuid,
        seats int, alternates int, draw_seed text, report_on timestamptz, status text NOT NULL,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE jury_members (
        id uuid PRIMARY KEY, jury_id uuid NOT NULL, user_id uuid NOT NULL, seat_kind text, seat_no int,
        screening_status text, excusal_reason text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE verdicts (
        id uuid PRIMARY KEY, case_id uuid NOT NULL, decided_by text NOT NULL, outcome text NOT NULL,
        panel_vote_for int, panel_vote_against int, jury_unanimous boolean, summary text,
        double_jeopardy_flag boolean NOT NULL DEFAULT false, record_id uuid, decided_at timestamptz,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE advocates (
        id uuid PRIMARY KEY, user_id uuid NOT NULL, judiciary_id uuid NOT NULL, jurisdiction_id uuid,
        status text NOT NULL, qualifications_note text, registered_at timestamptz,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE case_filings (
        seq bigserial PRIMARY KEY, id uuid NOT NULL DEFAULT gen_random_uuid(), case_id uuid NOT NULL,
        filing_form text, filing_kind text NOT NULL, filed_by_user_id uuid, filed_by_role text, advocate_id uuid,
        title text, body text, ruling text, ruling_reason text, accepted_at_state text, record_id uuid,
        audit_seq int, created_at timestamptz);

    CREATE TABLE opinions (
        id uuid PRIMARY KEY, case_id uuid NOT NULL, panel_id uuid, authored_by_seat_id uuid, kind text NOT NULL,
        title text, body text, appeal_outcome text, record_id uuid, published_at timestamptz,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE sentencing_orders (
        id uuid PRIMARY KEY, case_id uuid NOT NULL, verdict_id uuid, issued_by_seat_id uuid, terms text,
        effective_at timestamptz, expires_at timestamptz, status text NOT NULL, record_id uuid,
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);

    CREATE TABLE public_records (
        seq bigserial PRIMARY KEY, id uuid NOT NULL DEFAULT gen_random_uuid(), kind text NOT NULL, title text,
        body text, actor_user_id uuid, actor_display text, jurisdiction_id uuid, legislature_id uuid,
        via_form text, via_workflow text, via_clock text, subject_type text, subject_id uuid, audit_seq int,
        translations jsonb NOT NULL DEFAULT '[]'::jsonb, supersedes_record_id uuid, published_at timestamptz,
        source_server_id uuid, created_at timestamptz);

    CREATE TABLE audit_log (
        seq bigserial PRIMARY KEY, occurred_at timestamptz, actor_user_id uuid, module text, event text,
        ref text, jurisdiction_id uuid, payload jsonb, prev_hash text, hash text,
        rejected boolean NOT NULL DEFAULT false, blocked_reason text, created_at timestamptz);

    -- Hearing-room floor identity tables (RoomFloorService / MatrixIdentityProvisioner read/write these).
    CREATE TABLE matrix_identities (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL,
        matrix_localpart varchar(64) NOT NULL, matrix_user_id varchar(255), device_master_key varchar(255),
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
    CREATE UNIQUE INDEX matrix_identities_localpart_unique ON matrix_identities (lower(matrix_localpart)) WHERE deleted_at IS NULL;

    CREATE TABLE social_profiles (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL, handle varchar(64),
        display_name varchar(120), bio text, visibility varchar(12) NOT NULL DEFAULT 'public',
        created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
    SQL);

    // The audit-chain genesis row, hashed exactly as AuditService recomputes it.
    $canonical = AuditService::canonicalJson([]);
    $genesisHash = AuditService::chainHash(AuditService::GENESIS_PREV_HASH, $canonical);
    DB::table('audit_log')->insert([
        'occurred_at' => now(), 'module' => 'system', 'event' => 'genesis', 'payload' => $canonical,
        'prev_hash' => AuditService::GENESIS_PREV_HASH, 'hash' => $genesisHash, 'rejected' => false, 'created_at' => now(),
    ]);
}

/** Seed the court, 6 seated judges, actors, and the jury pool. */
function seedWorld(): array
{
    $jur = uid(2);
    $now = now();

    $users = [];
    // judges 101-106, complainant 201, accused 202, advocate 203, witness 204, outsider 205
    foreach ([101,102,103,104,105,106,201,202,203,204,205] as $n) {
        $users[] = ['id' => uid($n), 'name' => 'User '.$n, 'display_name' => 'User '.$n, 'created_at' => $now, 'updated_at' => $now];
    }
    // jury pool 301-317 (17 residents)
    $poolIds = range(301, 317);
    foreach ($poolIds as $n) {
        $users[] = ['id' => uid($n), 'name' => 'Resident '.$n, 'display_name' => 'Resident '.$n, 'created_at' => $now, 'updated_at' => $now];
    }
    DB::table('users')->insert($users);

    // Residency: the jury pool + the advocate (advocate needs association with the court's jurisdiction).
    $res = [];
    foreach (array_merge($poolIds, [203]) as $n) {
        $res[] = ['user_id' => uid($n), 'jurisdiction_id' => $jur, 'days_confirmed' => 40, 'confirmed_at' => $now,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
    }
    DB::table('residency_confirmations')->insert($res);

    DB::table('judiciaries')->insert([
        'id' => uid(1), 'jurisdiction_id' => $jur, 'court_name' => 'Fixture District Court', 'type' => Judiciary::TYPE_APPOINTED,
        'min_judges' => 5, 'term_years' => 10, 'status' => Judiciary::STATUS_APPOINTED, 'judge_count' => 6,
        'nomination_mode' => Judiciary::NOMINATION_CONSTITUENT, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $seatIds = [];
    foreach ([101,102,103,104,105,106] as $i => $n) {
        $seatId = uid(1000 + $n);
        $seatIds[] = $seatId;
        DB::table('judicial_seats')->insert([
            'id' => $seatId, 'judiciary_id' => uid(1), 'user_id' => uid($n), 'seat_number' => $i + 1,
            'seat_class' => JudicialSeat::CLASS_CONSTITUENT_NOMINATED, 'status' => JudicialSeat::STATUS_SEATED,
            'term_starts_on' => '2026-01-01', 'term_ends_on' => '2036-01-01', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    return ['jurisdiction' => $jur, 'seatIds' => $seatIds];
}

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/case_multi_actor_journey.php --run\n"; exit(0); }

$name = 'cga_cases_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;
try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA cases_fixture; CREATE TABLE cases_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO cases_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null;
    bootFixture($name, $nonce);
    DB::statement('SET search_path TO cases_fixture');

    createSchema();
    $world = seedWorld();
    $jur = $world['jurisdiction'];

    // Real hardened services (no mocks).
    $audit = new AuditService;
    $records = new PublicRecordService($audit);
    $roles = new RoleService;
    $settings = new SettingsResolver;
    $cases = new CaseService($records, $audit);
    $panels = new PanelService($cases, $audit);
    $juries = new JuryService($cases, $audit, $roles, $settings);
    $advocates = new AdvocateService($records, $roles);
    $filings = new CaseFilingService($records, $audit);
    $validator = new ConstitutionalValidator;

    $judge = User::query()->findOrFail(uid(101));      // seated judge (accept + verdict actor)
    $outsider = User::query()->findOrFail(uid(205));    // no seat — a non-judge
    $advocateUser = User::query()->findOrFail(uid(203));
    $witnessUser = User::query()->findOrFail(uid(204));

    emit(['stage' => 'seed_complete', 'seated_judges' => 6, 'jury_pool_residents' => 18]);

    // =====================================================================
    // CASE 1 — CRIMINAL, moderate, jury path (the main journey)
    // =====================================================================

    // (1) FILING (complainant files; accused + prosecution parties recorded).
    $case1 = $cases->open([
        'judiciary_id' => uid(1), 'jurisdiction_id' => $jur, 'kind' => CourtCase::KIND_CRIMINAL,
        'title' => 'People v. Accused', 'statement_of_claim' => 'Unlawful act alleged.',
        'claimed_severity' => CourtCase::SEVERITY_MODERATE, 'filed_via_form' => 'F-IND-017',
        'filed_by_user_id' => uid(201),
        'parties' => [
            ['party_role' => CaseParty::ROLE_PROSECUTION, 'party_type' => CaseParty::TYPE_INDIVIDUAL, 'party_user_id' => uid(201)],
            ['party_role' => CaseParty::ROLE_ACCUSED, 'party_type' => CaseParty::TYPE_INDIVIDUAL, 'party_user_id' => uid(202)],
        ],
    ]);
    check($case1->status === CourtCase::STATUS_FILED, 'Case 1 not filed');
    check($case1->docket_no === 'case-'.now()->year.'-001', 'Docket number not allocated: '.$case1->docket_no);
    check(DB::table('public_records')->where('subject_id', $case1->id)->where('kind', 'other')->exists(), 'No filing public record');
    check(DB::table('audit_log')->where('event', 'case.filed')->exists(), 'No case.filed audit entry');
    check(CaseParty::query()->where('case_id', $case1->id)->count() === 2, 'Parties not recorded');
    emit(['stage' => 'case1.filed', 'docket' => $case1->docket_no]);

    // (2) ACCEPTANCE — non-judge refused at the actor gate, then a seated judge accepts.
    refuses('non-judge acceptance (outsider)', fn () => JudicialActor::seat($outsider, uid(1), 'court.accept'),
        'CGA Roles & Forms Chart (R-19/R-20)', 'SEATED judge of THIS court');
    refuses('system acceptance (no actor)', fn () => JudicialActor::seat(null, uid(1), 'court.accept'));
    $judgeSeat = JudicialActor::seat($judge, uid(1), 'court.accept'); // positive gate
    $cases->accept($case1->refresh(), CourtCase::SEVERITY_MODERATE, false);
    $case1->refresh();
    check($case1->status === CourtCase::STATUS_ACCEPTED, 'Case 1 not accepted');
    check($case1->court_severity === CourtCase::SEVERITY_MODERATE, 'Severity not classified');
    check($case1->jury_entitled === true, 'Criminal case not jury-entitled');
    emit(['stage' => 'case1.accepted', 'severity' => $case1->court_severity, 'jury_entitled' => $case1->jury_entitled]);

    // (3) PANEL CONFLICTS — recuse the first seat in the draw order, re-draw advances, odd bench of 3.
    $panelSeed = 'cases-panel-seed-000000000001';
    $order1 = panelOrder($world['seatIds'], $panelSeed);
    $recusedSeat = $order1[0];
    $panel1 = $panels->assignPanel($case1->refresh(), $panelSeed, [$recusedSeat => 'Related to the accused party']);
    $case1->refresh();
    check($case1->status === CourtCase::STATUS_PANELED, 'Case 1 not paneled');
    check((int) $panel1->size === 3, 'Moderate panel not sized 3: '.$panel1->size);
    check(((int) $panel1->size) % 2 === 1, 'Panel not odd');
    $recusedRow = PanelJudge::query()->where('panel_id', $panel1->id)->where('judicial_seat_id', $recusedSeat)->first();
    check($recusedRow !== null && $recusedRow->status === PanelJudge::STATUS_RECUSED, 'Recused seat not recorded as recused');
    check($recusedRow->recusal_reason === 'Related to the accused party', 'Recusal reason not stored');
    $seatedCount = PanelJudge::query()->where('panel_id', $panel1->id)->where('status', PanelJudge::STATUS_SEATED)->count();
    check($seatedCount === 3, 'Seated panel judges != 3: '.$seatedCount);
    check($panel1->presiding_judge_seat_id === $order1[1], 'Presiding judge not the first cleared seat');
    check(DB::table('audit_log')->where('event', 'panel.drawn')->exists(), 'No panel.drawn audit entry');
    emit(['stage' => 'case1.paneled', 'size' => $panel1->size, 'recused' => 1, 'seated' => $seatedCount]);

    // (4) ADVOCATE — registration (association-gated), then attach.
    $advocate = $advocates->register(uid(203), uid(1), 'Bar-qualified advocate');
    check($advocate->status === Advocate::STATUS_REGISTERED, 'Advocate not registered');
    check($advocates->requireRegistered(uid(203), uid(1))->id === $advocate->id, 'requireRegistered did not resolve');
    refuses('advocate registration without association', fn () => $advocates->register(uid(205), uid(1)), 'Art. I');
    emit(['stage' => 'case1.advocate_registered', 'advocate_id' => (string) $advocate->id]);

    // (5) JURY DRAW — 12 + 2 from the eligible pool; case advances to jury_empaneled.
    $jury1 = $juries->empanel($case1->refresh(), 'cases-jury-seed-000000000001', 12, 2);
    $case1->refresh();
    check($case1->status === CourtCase::STATUS_JURY_EMPANELED, 'Case 1 not jury_empaneled');
    $drawn = JuryMember::query()->where('jury_id', $jury1->id)->count();
    check($drawn === 14, 'Jury draw != 14: '.$drawn);
    check(JuryMember::query()->where('jury_id', $jury1->id)->where('seat_kind', JuryMember::SEAT_JUROR)->count() === 12, 'Jurors != 12');
    check(JuryMember::query()->where('jury_id', $jury1->id)->where('seat_kind', JuryMember::SEAT_ALTERNATE)->count() === 2, 'Alternates != 2');
    check(DB::table('audit_log')->where('event', 'jury.drawn')->exists(), 'No jury.drawn audit entry');
    emit(['stage' => 'case1.jury_empaneled', 'drawn' => $drawn, 'pool_size' => $jury1->pool_size]);

    // (6) JUROR SCREENING (voir dire) — excuse for conflict + replacement draw; illegal reason refused.
    $member = JuryMember::query()->where('jury_id', $jury1->id)->orderBy('id')->first();
    $replacement = $juries->excuseAndReplace($member, JuryMember::EXCUSAL_CONFLICT);
    $member->refresh();
    check($member->screening_status === JuryMember::SCREENING_EXCUSED, 'Juror not excused');
    check($member->excusal_reason === JuryMember::EXCUSAL_CONFLICT, 'Excusal reason not stored');
    check($replacement !== null && $replacement->screening_status === JuryMember::SCREENING_SUMMONED, 'No replacement summoned');
    check((string) $replacement->user_id !== (string) $member->user_id, 'Replacement is the excused juror');
    check(DB::table('audit_log')->where('event', 'jury.replacement_drawn')->exists(), 'No jury.replacement_drawn audit entry');
    refuses('excusal for opinion/politics', fn () => $juries->excuseAndReplace(
        JuryMember::query()->where('jury_id', $jury1->id)->where('screening_status', JuryMember::SCREENING_SUMMONED)->first(),
        'political_opinion'), 'Art. IV §4', 'CONFLICTS only');
    emit(['stage' => 'case1.voir_dire', 'excused' => 1, 'replacement_drawn' => 1]);

    // (7) EVIDENCE / MOTIONS — the advocate appends to the append-only docket (F-ADV-002 / F-ADV-003).
    $motion = $filings->docket($case1->refresh(), [
        'filing_form' => 'F-ADV-002', 'filing_kind' => CaseFiling::KIND_MOTION, 'filed_by_user_id' => uid(203),
        'filed_by_role' => 'R-21', 'advocate_id' => (string) $advocate->id, 'title' => 'Motion to compel discovery', 'body' => 'Defence motion.',
    ]);
    $evidence = $filings->docket($case1->refresh(), [
        'filing_form' => 'F-ADV-003', 'filing_kind' => CaseFiling::KIND_EVIDENCE, 'filed_by_user_id' => uid(203),
        'filed_by_role' => 'R-21', 'advocate_id' => (string) $advocate->id, 'title' => 'Exhibit A', 'body' => 'Documentary evidence.',
    ]);
    check($motion->case_id === (string) $case1->id && $motion->filing_kind === CaseFiling::KIND_MOTION, 'Motion not docketed');
    check($evidence->filing_kind === CaseFiling::KIND_EVIDENCE, 'Evidence not docketed');
    check(DB::table('public_records')->where('subject_id', $case1->id)->where('kind', 'testimony')->count() >= 2, 'Docket records not published');

    // (8) JUDGE RULING — appends a follow-up filing carrying the ruling + written reason (F-JDG-014 shape).
    $ruling = $filings->docket($case1->refresh(), [
        'filing_form' => 'F-JDG-014', 'filing_kind' => CaseFiling::KIND_MOTION, 'filed_by_user_id' => uid(101),
        'filed_by_role' => 'R-19', 'title' => 'Motion ruling', 'ruling' => CaseFiling::RULING_GRANTED,
        'ruling_reason' => 'Discovery is material to the defence.', 'enforce_attach_window' => false,
    ]);
    check($ruling->ruling === CaseFiling::RULING_GRANTED && $ruling->ruling_reason !== null, 'Ruling not appended with reason');
    emit(['stage' => 'case1.docket', 'motions_evidence' => 2, 'ruling' => $ruling->ruling]);

    // (9) HEARING — arguments open (jury_empaneled -> heard).
    $cases->advanceToHearing($case1->refresh());
    check($case1->refresh()->status === CourtCase::STATUS_HEARD, 'Case 1 not heard');

    // (10) TESTIMONY — the witness gives recorded testimony on the evidence docket (heard window).
    $testimony = $filings->docket($case1->refresh(), [
        'filing_form' => 'F-ADV-003', 'filing_kind' => CaseFiling::KIND_EVIDENCE, 'filed_by_user_id' => uid(204),
        'filed_by_role' => 'witness', 'title' => 'Witness testimony', 'body' => 'Sworn statement of the witness.',
    ]);
    check($testimony->filed_by_role === 'witness', 'Witness testimony not attributed');
    emit(['stage' => 'case1.heard', 'testimony_recorded' => true]);

    // (10b) HEARING-ROOM FLOOR — the courtroom live floor over the REAL services
    // (RoomFloorService / LiveFloorService), scoped to THIS court's case. Witness
    // raises a hand; the presiding judge recognizes them onto the stand; the floor
    // yields. This exercises the "hearing rooms" half of the register row title:
    // the court access gate (presider = the seated presiding panel judge), the
    // witness-stand mechanic, and the presider-only floor control.
    $rooms = app(RoomFloorService::class);
    $floor = app(LiveFloorService::class);
    $floorKey = $floor->key('case', (string) $case1->id);
    $presidingSeat = JudicialSeat::query()->findOrFail((string) $panel1->presiding_judge_seat_id);
    $presider = User::query()->findOrFail((string) $presidingSeat->user_id);

    // The presiding judge holds the presider control on an open court floor; an outsider does not.
    $presiderView = $rooms->view('court', (string) $case1->id, $presider);
    check($presiderView['canPreside'] === true, 'Presiding judge lacks the court floor presider control');
    check($presiderView['canRequest'] === true, 'Open court floor does not accept a request');
    $outsiderView = $rooms->view('court', (string) $case1->id, $outsider);
    check($outsiderView['canPreside'] === false, 'Non-panel viewer wrongly granted the presider control');

    // The witness raises a hand (real identity provisioning + queue write).
    $rooms->act('court', (string) $case1->id, $witnessUser, 'raise');
    $floorState = $floor->state($floorKey);
    check(count($floorState['queue']) === 1, 'Witness hand-raise not queued: '.count($floorState['queue']));
    $witnessHandle = $floorState['queue'][0]['handle'];

    // Floor control is the presider's — a non-presider cannot place a witness on the stand.
    refusesHttp('witness recognition by a non-presider', fn () => $rooms->act('court', (string) $case1->id, $outsider, 'witness', $witnessHandle), 403, 'presiding officer');

    // The presiding judge recognizes the witness onto the stand.
    $rooms->act('court', (string) $case1->id, $presider, 'witness', $witnessHandle);
    $floorState = $floor->state($floorKey);
    check($floorState['activeWitness'] === $witnessHandle, 'Witness not placed on the stand');
    check($floorState['floorHolder'] === $witnessHandle, 'Recognized witness does not hold the floor');
    check($floorState['queue'] === [], 'Recognized witness not removed from the queue');

    // The presider yields — the stand and the floor clear.
    $rooms->act('court', (string) $case1->id, $presider, 'yield');
    $floorState = $floor->state($floorKey);
    check($floorState['activeWitness'] === null && $floorState['floorHolder'] === null, 'Yield did not clear the stand/floor');
    emit(['stage' => 'case1.hearing_room_floor', 'witness_recognized' => true, 'presider_gated' => true]);

    // (11) DELIBERATION (heard -> deliberation).
    $cases->enterDeliberation($case1->refresh());
    check($case1->refresh()->status === CourtCase::STATUS_DELIBERATION, 'Case 1 not in deliberation');

    // (12) VERDICT — refusals first, then the jury verdict.
    $offPanelSeat = JudicialSeat::query()->where('id', $order1[4])->first(); // seated on the court, not on this panel
    refuses('verdict by a judge not on this panel', fn () => $cases->assertActorOnPanel($case1->refresh(), $offPanelSeat), 'Art. IV §4', "THIS case's panel");
    $verdict1 = $cases->recordVerdict($case1->refresh(), [
        'decided_by' => Verdict::BY_JURY, 'outcome' => Verdict::OUTCOME_GUILTY, 'jury_unanimous' => true, 'summary' => 'Guilty on all counts.',
    ]);
    $case1->refresh();
    check($case1->status === CourtCase::STATUS_DECIDED, 'Case 1 not decided');
    check($verdict1->outcome === Verdict::OUTCOME_GUILTY && $verdict1->double_jeopardy_flag === true, 'Criminal verdict lacks double-jeopardy flag');
    check($case1->double_jeopardy_locked === true, 'Case not double-jeopardy locked');
    check(DB::table('audit_log')->where('event', 'case.decided')->exists(), 'No case.decided audit entry');
    emit(['stage' => 'case1.decided', 'outcome' => $verdict1->outcome, 'double_jeopardy_locked' => $case1->double_jeopardy_locked]);

    // (13) SENTENCING (decided -> sentenced) on the guilty criminal verdict.
    $order = SentencingOrder::create([
        'case_id' => (string) $case1->id, 'verdict_id' => (string) $verdict1->id, 'issued_by_seat_id' => $panel1->presiding_judge_seat_id,
        'terms' => 'Custodial term of 24 months.', 'effective_at' => now(), 'status' => SentencingOrder::STATUS_ISSUED,
    ]);
    $cases->sentence($case1->refresh(), $order);
    check($case1->refresh()->status === CourtCase::STATUS_SENTENCED, 'Case 1 not sentenced');

    // (14) OPINION — publish the opinion record + row, then close (sentenced -> closed).
    $opinionRecord = $records->publish('opinion', 'Opinion of the court — People v. Accused', 'Reasoning of the majority.', [
        'jurisdiction_id' => $jur, 'via_form' => 'F-JDG-003', 'subject_type' => 'cases', 'subject_id' => (string) $case1->id,
    ]);
    $opinion = Opinion::create([
        'case_id' => (string) $case1->id, 'panel_id' => (string) $panel1->id, 'authored_by_seat_id' => $panel1->presiding_judge_seat_id,
        'kind' => Opinion::KIND_MAJORITY, 'title' => 'Opinion of the court', 'body' => 'Reasoning of the majority.',
        'record_id' => (string) $opinionRecord->id, 'published_at' => now(),
    ]);
    $cases->close($case1->refresh());
    check($case1->refresh()->status === CourtCase::STATUS_CLOSED, 'Case 1 not closed');
    emit(['stage' => 'case1.closed', 'sentenced' => true, 'opinion_published' => true]);

    // (15) PRESERVED RECORDS — rows survive the close and the audit chain re-verifies.
    $verdict1->refresh();
    check(Verdict::query()->where('case_id', $case1->id)->count() === 1 && $verdict1->double_jeopardy_flag === true, 'Verdict not preserved');
    check(Opinion::query()->where('case_id', $case1->id)->count() === 1, 'Opinion not preserved');
    check(SentencingOrder::query()->where('case_id', $case1->id)->count() === 1, 'Sentencing order not preserved');
    $chain = $audit->verifyChain();
    check($chain === true, 'Audit chain broken at seq '.var_export($chain, true));
    emit(['stage' => 'case1.records_preserved', 'audit_chain_intact' => true, 'audit_entries' => $audit->count()]);

    // (16) CLOSED-CASE BEHAVIOR — attach-window closed + illegal transitions refused.
    refuses('brief on a closed case', fn () => $filings->docket($case1->refresh(), [
        'filing_form' => 'F-ADV-004', 'filing_kind' => CaseFiling::KIND_BRIEF, 'filed_by_user_id' => uid(203), 'title' => 'Post-verdict brief',
    ]), 'Art. IV §4', 'attach-window has closed');
    refuses('accept a closed case', fn () => $cases->accept($case1->refresh(), CourtCase::SEVERITY_MODERATE), 'Art. IV §4', 'Illegal case transition');
    refuses('re-record verdict on a closed case', fn () => $cases->recordVerdict($case1->refresh(), [
        'decided_by' => Verdict::BY_JURY, 'outcome' => Verdict::OUTCOME_GUILTY, 'jury_unanimous' => true,
    ]), 'Art. IV §4', 'Illegal case transition');
    // The hearing-room floor is closed once the case reaches a terminal state.
    refusesHttp('court floor action on a closed case', fn () => $rooms->act('court', (string) $case1->id, $presider, 'raise'), 403, 'closed for live floor actions');
    emit(['stage' => 'case1.closed_case_refusals_held']);

    // (17) DOUBLE JEOPARDY — a criminal re-filing against the same accused is barred pre-commit (real validator).
    refuses('double-jeopardy criminal re-filing', fn () => $validator->check('F-IND-017', [
        'kind' => 'criminal', 'accused_user_id' => uid(202), 'judiciary_id' => uid(1), 'prior_case_id' => (string) $case1->id,
    ]), 'Art. II §8', 'already been prosecuted');
    // A civil re-filing on the same facts is never barred.
    $validator->check('F-IND-017', ['kind' => 'civil', 'accused_user_id' => uid(202), 'judiciary_id' => uid(1)]);
    emit(['stage' => 'double_jeopardy_gate_held', 'civil_refiling_allowed' => true]);

    // =====================================================================
    // CASE 2 — CIVIL, serious, panel path (panel verdict math + jury-without-jury refusal)
    // =====================================================================
    $case2 = $cases->open([
        'judiciary_id' => uid(1), 'jurisdiction_id' => $jur, 'kind' => CourtCase::KIND_CIVIL,
        'title' => 'Petitioner v. Respondent', 'statement_of_claim' => 'Breach of duty alleged.',
        'claimed_severity' => CourtCase::SEVERITY_SERIOUS, 'filed_via_form' => 'F-IND-017', 'filed_by_user_id' => uid(201),
        'parties' => [
            ['party_role' => CaseParty::ROLE_PLAINTIFF, 'party_type' => CaseParty::TYPE_INDIVIDUAL, 'party_user_id' => uid(201)],
            ['party_role' => CaseParty::ROLE_RESPONDENT, 'party_type' => CaseParty::TYPE_INDIVIDUAL, 'party_user_id' => uid(202)],
        ],
    ]);
    check($case2->docket_no === 'case-'.now()->year.'-002', 'Case 2 docket not incremented: '.$case2->docket_no);
    $cases->accept($case2->refresh(), CourtCase::SEVERITY_SERIOUS, false);
    $case2->refresh();
    check($case2->jury_entitled === false, 'Civil case wrongly jury-entitled');
    $seed2 = 'cases-panel-seed-000000000002';
    $order2 = panelOrder($world['seatIds'], $seed2);
    $panel2 = $panels->assignPanel($case2->refresh(), $seed2, []);
    check((int) $panel2->size === 5, 'Serious panel not sized 5: '.$panel2->size);
    $cases->advanceToHearing($case2->refresh());
    $cases->enterDeliberation($case2->refresh());
    check($case2->refresh()->status === CourtCase::STATUS_DELIBERATION, 'Case 2 not in deliberation');
    emit(['stage' => 'case2.deliberation', 'panel_size' => $panel2->size]);

    // Refusal — a jury verdict recorded for a case that never empaneled a jury.
    refuses('jury verdict without empaneled jury', fn () => $cases->assertVerdictRecordable($case2->refresh(), [
        'decided_by' => Verdict::BY_JURY, 'outcome' => Verdict::OUTCOME_LIABLE, 'jury_unanimous' => true,
    ]), 'Art. IV §4', 'empaneled a jury');
    // Refusal — panel votes must sum to the panel size.
    refuses('panel vote does not sum to size', fn () => $cases->assertVerdictRecordable($case2->refresh(), [
        'decided_by' => Verdict::BY_PANEL, 'outcome' => Verdict::OUTCOME_LIABLE, 'panel_vote_for' => 2, 'panel_vote_against' => 2,
    ]), 'Art. IV §4', 'must sum to the panel size');
    // Refusal — the outcome must match the side the majority carried.
    refuses('panel outcome not carried by majority', fn () => $cases->assertVerdictRecordable($case2->refresh(), [
        'decided_by' => Verdict::BY_PANEL, 'outcome' => Verdict::OUTCOME_NOT_LIABLE, 'panel_vote_for' => 3, 'panel_vote_against' => 2,
    ]), 'Art. IV §4', 'not the one the majority carried');

    // The real panel verdict (3-2 for the petitioner => liable), recorded by a panel judge.
    $verdict2 = $cases->recordVerdict($case2->refresh(), [
        'decided_by' => Verdict::BY_PANEL, 'outcome' => Verdict::OUTCOME_LIABLE, 'panel_vote_for' => 3, 'panel_vote_against' => 2, 'summary' => 'Liable.',
    ]);
    $case2->refresh();
    check($case2->status === CourtCase::STATUS_DECIDED, 'Case 2 not decided');
    check($verdict2->double_jeopardy_flag === false, 'Civil verdict wrongly flagged double jeopardy');
    check($case2->double_jeopardy_locked === false, 'Civil case wrongly locked');
    emit(['stage' => 'case2.decided', 'outcome' => $verdict2->outcome, 'panel_vote' => '3-2']);

    // =====================================================================
    // CASE 3 — DISMISSAL (filed -> dismissed)
    // =====================================================================
    $case3 = $cases->open([
        'judiciary_id' => uid(1), 'jurisdiction_id' => $jur, 'kind' => CourtCase::KIND_CIVIL,
        'title' => 'Withdrawn matter', 'filed_via_form' => 'F-IND-017', 'filed_by_user_id' => uid(201),
    ]);
    $cases->dismiss($case3->refresh(), 'Not justiciable — withdrawn by the filer.');
    $case3->refresh();
    check($case3->status === CourtCase::STATUS_DISMISSED, 'Case 3 not dismissed');
    check($case3->closed_at !== null, 'Dismissed case has no closed_at');
    check(DB::table('public_records')->where('subject_id', $case3->id)->where('title', 'like', 'Case dismissed%')->exists(), 'No dismissal record');
    check(DB::table('audit_log')->where('event', 'case.dismissed')->exists(), 'No case.dismissed audit entry');
    emit(['stage' => 'case3.dismissed']);

    // Final chain integrity across all three cases.
    $finalChain = $audit->verifyChain();
    check($finalChain === true, 'Final audit chain broken at seq '.var_export($finalChain, true));
    emit(['stage' => 'journey_complete', 'cases' => 3, 'audit_entries' => $audit->count(), 'audit_chain_intact' => true]);
} catch (Throwable $e) {
    $failures++;
    emit(['failure' => $e->getMessage(), 'class' => $e::class, 'at' => $e->getFile().':'.$e->getLine()]);
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
