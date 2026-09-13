<?php
/** Opt-in EO-4 additive-migration probe: a nonce-guarded disposable database, never the world database. */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('60000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_judicial_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }
function connectionConfig(string $name): array
{
    // Read only connection fields; do not load application settings/providers or print secrets.
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'judicial_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_judicial_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='20s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='20s'");
    return $pdo;
}
function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM judicial_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
    check($row !== false && $row['db'] === $name && hash_equals($nonce, $row['nonce']), 'Fixture identity check failed');
    check($pdo->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fixture unexpectedly contains public organizations');
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
    // Both queue dispatch and unique-job locks stay strictly in this process's memory.
    $cache = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore);
    $app->instance(Illuminate\Contracts\Cache\Repository::class, $cache);
    $app->instance(Illuminate\Log\Context\Repository::class, new Illuminate\Log\Context\Repository($events));
    $fakeBus = new Illuminate\Support\Testing\Fakes\BusFake(new Illuminate\Bus\Dispatcher($app));
    $app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $fakeBus);
    foreach (["SET statement_timeout='20s'", "SET lock_timeout='8s'", "SET idle_in_transaction_session_timeout='20s'"] as $setting) DB::statement($setting);
    identity(DB::connection()->getPdo(), $name, $nonce);
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'judicial_fixture', 'Unexpected schema/search path');
}

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/judicial_nomination_migration.php --run\n"; exit(0); }
$name = 'cga_judicial_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;
try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA judicial_fixture; CREATE TABLE judicial_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO judicial_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null; bootFixture($name, $nonce);
    DB::statement('CREATE TABLE judiciaries (id uuid PRIMARY KEY, court_name text)');
    DB::statement("CREATE TABLE chamber_vote_proposals (id uuid PRIMARY KEY, legislature_id uuid, proposed_by_member_id uuid, proposal_kind varchar(32), payload jsonb, status text,
        CONSTRAINT chamber_vote_proposals_kind_check CHECK (proposal_kind IN ('committee_creation', 'prior_extension')))");
    DB::statement('CREATE TABLE judicial_seats (id uuid PRIMARY KEY, judiciary_id uuid, status text, deleted_at timestamp)');
    DB::statement('CREATE TABLE judicial_nominations (id uuid PRIMARY KEY, seat_id uuid, deleted_at timestamp)');
    DB::statement('CREATE TABLE committees (id uuid PRIMARY KEY, legislature_id uuid, status text, deleted_at timestamp)');
    DB::table('judiciaries')->insert(['id' => uid(1), 'court_name' => 'Existing fixture court']);
    DB::table('chamber_vote_proposals')->insert(['id' => uid(1), 'proposal_kind' => 'prior_extension', 'payload' => '{}']);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_13_150000_judicial_nomination_authorization.php';
    $migration->up(); $migration->up();
    check(DB::table('judiciaries')->where('id', uid(1))->value('court_name') === 'Existing fixture court', 'Existing court changed');
    check(DB::table('judiciaries')->where('id', uid(1))->value('judicial_committee_id') === null, 'A committee was silently assigned');
    check(DB::table('chamber_vote_proposals')->where('id', uid(1))->value('proposal_kind') === 'prior_extension', 'Existing act lost');
    DB::table('chamber_vote_proposals')->insert(['id' => uid(2), 'proposal_kind' => 'judicial_committee_designation', 'payload' => '{}']);
    try { DB::table('chamber_vote_proposals')->insert(['id' => uid(3), 'proposal_kind' => 'invalid_kind', 'payload' => '{}']); throw new RuntimeException('Constraint accepted invalid kind'); }
    catch (Illuminate\Database\QueryException $e) { check($e->getCode() === '23514', 'Unexpected constraint failure'); }
    foreach (array_chunk(range(100, 3099), 100) as $chunk) {
        $proposals = $seats = $committees = $nominations = [];
        foreach ($chunk as $i) {
            $scope = uid(10 + $i % 30);
            $proposals[] = ['id' => uid($i), 'proposal_kind' => 'judicial_nomination', 'payload' => json_encode(['judiciary_id' => $scope]), 'status' => 'open'];
            $seats[] = ['id' => uid($i), 'judiciary_id' => $scope, 'status' => 'vacant'];
            $committees[] = ['id' => uid($i), 'legislature_id' => $scope, 'status' => 'created'];
            $nominations[] = ['id' => uid($i), 'seat_id' => $scope];
        }
        DB::table('chamber_vote_proposals')->insert($proposals); DB::table('judicial_seats')->insert($seats);
        DB::table('committees')->insert($committees); DB::table('judicial_nominations')->insert($nominations);
    }
    foreach (['chamber_vote_proposals', 'judicial_seats', 'committees', 'judicial_nominations'] as $table) DB::statement('ANALYZE '.$table);
    $queries = [
        'judicial_proposal_court_seek_idx' => DB::table('chamber_vote_proposals')->whereIn('proposal_kind', ['judicial_nomination', 'judicial_committee_designation'])->where('payload->judiciary_id', uid(10)),
        'judicial_vacancy_court_seek_idx' => DB::table('judicial_seats')->where('judiciary_id', uid(10))->where('status', 'vacant')->whereNull('deleted_at'),
        'judicial_nomination_seat_seek_idx' => DB::table('judicial_nominations')->where('seat_id', uid(10)),
        'judicial_committee_selection_idx' => DB::table('committees')->where('legislature_id', uid(10))->where('status', '!=', 'dissolved')->whereNull('deleted_at'),
    ];
    foreach ($queries as $index => $q) {
        $q->orderByDesc('id')->limit(21);
        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$q->toSql(), $q->getBindings());
        $encoded = json_encode($plan);
        check(str_contains($encoded, $index), 'Expected scoped index was not used: '.$index);
        check((bool) DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$index])->indisvalid, 'Index invalid');
        emit(['query_index' => $index, 'passed' => true]);
    }
    emit(['preserved_existing_rows_and_prior_constraint_extension' => true, 'rerunnable_migration' => true]);
} catch (Throwable $e) { $failures++; emit(['failure' => $e->getMessage()]); }
finally {
    if ($created) {
        try {
            if (Illuminate\Support\Facades\Facade::getFacadeApplication() !== null) DB::purge();
            $fixture = pdo($name); identity($fixture, $name, $nonce); $fixture = null;
            fixtureName($name); $admin->exec('DROP DATABASE "'.$name.'"'); emit(['cleanup' => 'verified_fixture_database_removed']);
        } catch (Throwable $e) { $failures++; emit(['cleanup_failed' => $e->getMessage(), 'database' => $name]); }
    }
}
emit(['failures' => $failures, 'live_world_used' => false]); exit($failures === 0 ? 0 : 1);
