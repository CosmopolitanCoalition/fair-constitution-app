<?php
/** Opt-in B6 PostgreSQL probe: a nonce-guarded disposable database, never the world database. */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
use App\Models\Candidacy;
use App\Models\Endorsement;
use App\Support\CandidacyEndorsementDirectory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('60000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_endorsement_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }
function connectionConfig(string $name): array
{
    // Read only connection fields; do not load application settings/providers or print secrets.
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'endorsement_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_endorsement_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='20s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='20s'");
    return $pdo;
}
function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM endorsement_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
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
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    // Both queue dispatch and unique-job locks stay strictly in this process's memory.
    $cache = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore);
    $app->instance(Illuminate\Contracts\Cache\Repository::class, $cache);
    $app->instance(Illuminate\Log\Context\Repository::class, new Illuminate\Log\Context\Repository($events));
    $fakeBus = new Illuminate\Support\Testing\Fakes\BusFake(new Illuminate\Bus\Dispatcher($app));
    $app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $fakeBus);
    foreach (["SET statement_timeout='20s'", "SET lock_timeout='8s'", "SET idle_in_transaction_session_timeout='20s'"] as $setting) DB::statement($setting);
    identity(DB::connection()->getPdo(), $name, $nonce);
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'endorsement_fixture', 'Unexpected schema/search path');
}
function spawn(string $name, string $nonce, array $job): array
{
    $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $name, $nonce, base64_encode(json_encode($job))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($proc), 'Could not start fixture worker'); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    return ['proc' => $proc, 'pipes' => $pipes, 'buffer' => ''];
}
function event(array &$child, string $wanted, float $timeout = 15): array
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $child['buffer'] .= stream_get_contents($child['pipes'][1]);
        while (($pos = strpos($child['buffer'], "\n")) !== false) {
            $line = substr($child['buffer'], 0, $pos); $child['buffer'] = substr($child['buffer'], $pos + 1);
            $row = json_decode($line, true);
            check(is_array($row), 'Non-JSON fixture worker output: '.$line);
            if ($row['event'] === $wanted) return $row;
            check($row['event'] !== 'result', 'Worker finished before requested barrier: '.json_encode($row));
        }
        if (! proc_get_status($child['proc'])['running']) throw new RuntimeException('Fixture worker exited before '.$wanted.': '.stream_get_contents($child['pipes'][2]));
        usleep(10000);
    }
    throw new RuntimeException('Timed out waiting for fixture '.$wanted);
}
function closeChild(array &$child): void
{
    foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (proc_get_status($child['proc'])['running']) proc_terminate($child['proc']);
    proc_close($child['proc']);
}
function pair(string $name, string $nonce, array $first, array $second, bool $reservationRace = false): array
{
    $a = spawn($name, $nonce, $first); $b = null;
    try {
        $aPid = event($a, 'started')['pid']; event($a, 'paused');
        $b = spawn($name, $nonce, $second); $bPid = event($b, 'started')['pid'];
        check($aPid !== $bPid, 'Concurrent workers share a PostgreSQL connection');
        if ($reservationRace) { event($b, 'paused'); $blocked = false; }
        else {
            $deadline = microtime(true) + 5; $blocked = false;
            do {
                $row = DB::selectOne('SELECT wait_event_type, pg_blocking_pids(pid)::text AS blockers FROM pg_catalog.pg_stat_activity WHERE pid=? AND datname=?', [$bPid, $name]);
                $blocked = $row !== null && $row->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $aPid);
                if (! $blocked) usleep(10000);
            } while (! $blocked && microtime(true) < $deadline);
            check($blocked, 'Second PostgreSQL connection did not demonstrably wait for the first writer');
        }
        fwrite($a['pipes'][0], "go\n");
        if ($reservationRace) fwrite($b['pipes'][0], "go\n");
        return ['distinct_connections' => true, 'observed_lock_wait' => $blocked, 'first' => event($a, 'result'), 'second' => event($b, 'result')];
    } finally { closeChild($a); if ($b !== null) closeChild($b); }
}
function one(string $name, string $nonce, array $job): array
{
    $child = spawn($name, $nonce, $job);
    try { event($child, 'started'); return event($child, 'result', 60); }
    finally { closeChild($child); }
}

function schema(PDO $pdo, string $nonce): void
{
    $pdo->exec('CREATE SCHEMA endorsement_fixture; SET search_path=endorsement_fixture,pg_catalog; CREATE TABLE fixture_guard (nonce text PRIMARY KEY)');
    $pdo->prepare('INSERT INTO fixture_guard VALUES (?)')->execute([$nonce]);
    $pdo->exec(<<<'SQL'
CREATE TABLE users (id uuid PRIMARY KEY, display_name text, name text, deleted_at timestamptz);
CREATE TABLE social_profiles (user_id uuid PRIMARY KEY, display_name text, handle text, visibility text, deleted_at timestamptz);
CREATE TABLE organizations (id uuid PRIMARY KEY, name text, type text DEFAULT 'nonprofit', deleted_at timestamptz);
CREATE TABLE candidacies (id uuid PRIMARY KEY, user_id uuid, election_id uuid, race_id uuid, status text DEFAULT 'validated', deleted_at timestamptz);
CREATE INDEX fixture_candidacy_person ON candidacies(user_id, election_id);
CREATE TABLE endorsements (id uuid PRIMARY KEY, election_id uuid, candidate_id uuid, endorser_type text, endorser_id uuid,
    statement text, is_active boolean DEFAULT true, is_public boolean DEFAULT true, endorsed_at timestamptz, withdrawn_at timestamptz, created_at timestamptz, updated_at timestamptz,
    UNIQUE(election_id, candidate_id, endorser_type, endorser_id));
CREATE TABLE endorsement_requests (id uuid PRIMARY KEY, candidacy_id uuid, organization_id uuid, requested_at timestamptz, status text);
CREATE TABLE approval_standings (id uuid PRIMARY KEY, race_id uuid, candidacy_id uuid, as_of_date date, approvals_count integer, rank integer, is_frozen boolean);
SQL);
}

function worker(string $name, string $nonce, array $job): void
{
    bootFixture($name, $nonce);
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $paused = false;
    DB::listen(function ($event) use ($job, $pid, &$paused) {
        if (! $paused && ($job['pause'] ?? false) && preg_match('/select .*from "(?:users|organizations)".*for update/i', $event->sql)) {
            $paused = true; emit(['event' => 'paused', 'pid' => $pid]);
            $read = [STDIN]; $write = $except = [];
            check(stream_select($read, $write, $except, 12) === 1 && trim((string) fgets(STDIN)) === 'go', 'Fixture barrier timed out');
        }
    });
    emit(['event' => 'started', 'pid' => $pid]);
    try {
        $result = DB::transaction(function () use ($job) {
            $row = Endorsement::recordFor(Candidacy::findOrFail(uid(10)), $job['type'], $job['endorser'], ['is_public' => $job['public']]);
            if ($job['fail'] ?? false) throw new RuntimeException('Synthetic rollback');
            return $row->id;
        });
        emit(['event' => 'result', 'ok' => true, 'id' => $result]);
    } catch (Throwable $e) { emit(['event' => 'result', 'ok' => false, 'message' => $e->getMessage()]); }
    DB::disconnect(); exit(0);
}

function planNodes(array $plan): array
{
    $nodes = [$plan]; foreach ($plan['Plans'] ?? [] as $child) array_push($nodes, ...planNodes($child)); return $nodes;
}

if (($argv[1] ?? '') === 'worker') worker($argv[2], $argv[3], json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR));
if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/endorsements.php --run\n"; exit(0); }
$name = 'cga_endorsement_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;
try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name); schema($fixture, $nonce); identity($fixture, $name, $nonce); $fixture = null;
    bootFixture($name, $nonce);
    DB::table('users')->insert(['id' => uid(1), 'display_name' => 'Synthetic candidate']);
    DB::table('candidacies')->insert(['id' => uid(10), 'user_id' => uid(1), 'election_id' => uid(2), 'race_id' => uid(3)]);
    $case = 20;
    foreach (['user', 'organization'] as $type) foreach (['missing', 'legacy', 'rollback'] as $state) {
        $id = uid($case++); DB::table($type === 'user' ? 'users' : 'organizations')->insert(['id' => $id]);
        if ($state === 'legacy') DB::table('endorsements')->insert(['id' => uid($case + 100), 'candidate_id' => uid(10), 'election_id' => uid(2), 'endorser_type' => $type.'s', 'endorser_id' => $id]);
        $result = pair($name, $nonce, ['type' => $type.'s', 'endorser' => $id, 'public' => true, 'pause' => true, 'fail' => $state === 'rollback'],
            ['type' => $type, 'endorser' => $id, 'public' => false]);
        check($result['first']['ok'] === ($state !== 'rollback') && $result['second']['ok'], 'Unexpected writer result: '.json_encode($result));
        $rows = Endorsement::where('endorser_id', $id)->get();
        check($rows->count() === 1 && $rows[0]->endorser_type === $type && ! $rows[0]->is_public, 'Duplicate or leaked logical endorsement');
        if ($state === 'legacy') check($rows[0]->id === uid($case + 100), 'Legacy identity changed');
        emit(['case' => $type.' '.$state, 'passed' => true, 'observed_lock_wait' => $result['observed_lock_wait']]);
    }
    // Build a populated, bounded synthetic cohort. No production identities or records are copied.
    foreach (array_chunk(range(200, 1199), 100) as $chunk) {
        $users = $orgs = $candidates = $endorsements = $requests = [];
        foreach ($chunk as $i) {
            $users[] = ['id' => uid($i), 'display_name' => 'Synthetic supporter '.$i];
            $orgs[] = ['id' => uid($i), 'name' => 'Synthetic organization '.$i];
            $candidates[] = ['id' => uid($i + 5000), 'user_id' => uid($i), 'election_id' => uid(2), 'race_id' => uid(3)];
            foreach (['user', 'organization'] as $t) $endorsements[] = ['id' => uid($i + ($t === 'user' ? 10000 : 20000)),
                'candidate_id' => uid(10), 'election_id' => uid(2), 'endorser_type' => $t.($i % 2 ? 's' : ''),
                'endorser_id' => uid($i), 'is_public' => $t === 'organization' || $i % 5 !== 0];
            $endorsements[] = ['id' => uid($i + 30000), 'candidate_id' => uid($i + 5000), 'election_id' => uid(2),
                'endorser_type' => $i % 2 ? 'users' : 'user', 'endorser_id' => uid(201), 'is_public' => true];
            $requests[] = ['id' => uid($i + 40000), 'candidacy_id' => uid(10), 'organization_id' => uid($i), 'status' => 'pending'];
        }
        DB::table('users')->insert($users); DB::table('organizations')->insert($orgs); DB::table('candidacies')->insert($candidates);
        DB::table('endorsements')->insert($endorsements); DB::table('endorsement_requests')->insert($requests);
    }
    (require dirname(__DIR__, 2).'/database/migrations/2026_09_13_140000_candidacy_profile_directory_indexes.php')->up();
    foreach (['endorsements', 'candidacies', 'organizations', 'users', 'endorsement_requests'] as $table) DB::statement('ANALYZE '.$table);
    $directory = new CandidacyEndorsementDirectory; $candidate = Candidacy::findOrFail(uid(10));
    $request = Illuminate\Http\Request::create('/people?public_endorser='.uid(201));
    DB::enableQueryLog(); DB::flushQueryLog();
    $orgs = $directory->organizations($request, $candidate); $people = $directory->individuals($request, $candidate);
    $web = $directory->web($request, $candidate); $given = $directory->given($request, uid(201));
    $requests = $directory->requests($request, $candidate, App\Models\User::findOrFail(uid(1)));
    foreach (['organizations' => $orgs, 'individuals' => $people, 'web' => $web] as $method => $page) {
        check(count($page['rows']) === 20 && $page['pages']['next'] !== null, 'Expected first page');
        $second = $directory->$method(Illuminate\Http\Request::create($page['pages']['next']), $candidate);
        check(count($second['rows']) === 20 && $second['rows'] !== $page['rows'], 'Expected next page');
    }
    check($people['counts'] === ['total' => 1003, 'public' => 800, 'private' => 203], 'Unexpected logical/privacy totals');
    $queries = DB::getQueryLog(); DB::disableQueryLog(); $plans = [];
    foreach ($queries as $q) {
        if (! str_contains($q['query'], 'from "endorsements"') && ! str_contains($q['query'], 'from "endorsement_requests"')) continue;
        $result = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$q['query'], $q['bindings']);
        $plan = json_decode($result[0]->{'QUERY PLAN'}, true)[0]; $nodes = planNodes($plan['Plan']);
        $indexes = array_values(array_unique(array_filter(array_column($nodes, 'Index Name'))));
        $scans = array_values(array_map(fn ($node) => ['table' => $node['Relation Name'], 'rows' => $node['Actual Rows'], 'loops' => $node['Actual Loops']],
            array_filter($nodes, fn ($node) => $node['Node Type'] === 'Seq Scan')));
        $evidence = ['ms' => $plan['Execution Time'], 'rows' => $plan['Plan']['Actual Rows'], 'indexes' => $indexes, 'sequential_scans' => $scans];
        emit(['plan' => $evidence]); $plans[] = $evidence;
        foreach ($nodes as $node) check(! ($node['Node Type'] === 'Seq Scan' && ($node['Relation Name'] ?? '') === 'endorsements' && $node['Actual Loops'] > 0), 'Endorsements performed a full relation scan');
    }
    emit(['readers' => 'passed', 'synthetic_endorsements' => 3006, 'plans_checked' => count($plans)]);
} catch (Throwable $e) { $failures++; emit(['failure' => $e->getMessage()]); }
finally {
    if ($created) {
        try {
            if (Illuminate\Support\Facades\Facade::getFacadeApplication() !== null) DB::purge();
            $fixture = pdo($name); identity($fixture, $name, $nonce); $fixture = null;
            fixtureName($name); $admin->exec('DROP DATABASE "'.$name.'"');
            emit(['cleanup' => 'verified_fixture_database_removed']);
        } catch (Throwable $e) { $failures++; emit(['cleanup_failed' => $e->getMessage(), 'database' => $name]); }
    }
}
emit(['failures' => $failures, 'live_world_used' => false]); exit($failures === 0 ? 0 : 1);
