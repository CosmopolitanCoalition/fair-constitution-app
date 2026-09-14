<?php
/** Opt-in W-0299 additive-migration probe: a nonce-guarded disposable database, never the world database. */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('60000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_economy_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }
function connectionConfig(string $name): array
{
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'economy_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_economy_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='20s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='20s'");
    return $pdo;
}
function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM economy_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
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
    foreach (["SET statement_timeout='20s'", "SET lock_timeout='8s'", "SET idle_in_transaction_session_timeout='20s'"] as $setting) DB::statement($setting);
    identity(DB::connection()->getPdo(), $name, $nonce);
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'economy_fixture', 'Unexpected schema/search path');
}

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/budget_enactment_proposal_kind_check.php --run\n"; exit(0); }
$name = 'cga_economy_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;
try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA economy_fixture; CREATE TABLE economy_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO economy_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null; bootFixture($name, $nonce);

    // The baseline shape: a proposal_kind CHECK as an ARRAY = ANY expression that
    // does NOT yet admit budget_enactment. The migration preserves this expression
    // verbatim and ORs the new kind in, so this mirrors the installed constraint.
    DB::statement("CREATE TABLE chamber_vote_proposals (
        id uuid PRIMARY KEY, legislature_id uuid, proposed_by_member_id uuid,
        proposal_kind varchar(32) NOT NULL, payload jsonb, status text,
        CONSTRAINT chamber_vote_proposals_kind_check CHECK (((proposal_kind)::text = ANY ((ARRAY['committee_creation'::character varying, 'disintermediation'::character varying])::text[]))))");
    DB::table('chamber_vote_proposals')->insert(['id' => uid(1), 'proposal_kind' => 'committee_creation', 'payload' => '{}', 'status' => 'open']);

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_14_000200_allow_budget_enactment_proposal_kind.php';
    $migration->up(); $migration->up(); // rerunnable — the second call is a no-op

    // The prior row survives; the prior kind still inserts (the old expression is preserved).
    check(DB::table('chamber_vote_proposals')->where('id', uid(1))->value('proposal_kind') === 'committee_creation', 'Existing proposal lost');
    DB::table('chamber_vote_proposals')->insert(['id' => uid(2), 'proposal_kind' => 'committee_creation', 'payload' => '{}', 'status' => 'open']);
    emit(['prior_kind_still_admitted' => true]);

    // budget_enactment is now admitted.
    DB::table('chamber_vote_proposals')->insert(['id' => uid(3), 'proposal_kind' => 'budget_enactment', 'payload' => '{}', 'status' => 'open']);
    emit(['budget_enactment_admitted' => true]);

    // An unknown kind is still rejected (SQLSTATE 23514).
    try {
        DB::table('chamber_vote_proposals')->insert(['id' => uid(4), 'proposal_kind' => 'invalid_kind', 'payload' => '{}', 'status' => 'open']);
        throw new RuntimeException('The CHECK accepted an unknown kind');
    } catch (Illuminate\Database\QueryException $e) {
        check($e->getCode() === '23514', 'Unexpected failure code: '.$e->getCode());
        emit(['unknown_kind_rejected' => true]);
    }

    emit(['rerunnable_migration' => true, 'proposal_kind_extended' => true]);
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
