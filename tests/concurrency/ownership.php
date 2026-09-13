<?php

/**
 * Opt-in PostgreSQL concurrency probe. Run: php tests/concurrency/ownership.php --run
 * Creates one random, empty database; never boots the application's providers,
 * copies production data, runs migrations, connects to the app DB, or executes jobs.
 */
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Forms\Handlers\OrganizationMarketParticipation;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\PublicRecord;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\LedgerService;
use App\Services\Economy\ShareTradeService;
use App\Services\Organizations\OrgOwnershipService;
use App\Services\Organizations\OrgRegistryService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function uid(int $n): string { return sprintf('60000000-0000-4000-8000-%012d', $n); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_ownership_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }
function connectionConfig(string $name): array
{
    // Read only connection fields; do not load application settings/providers or print secrets.
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'ownership_fixture', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_ownership_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='20s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='20s'");
    return $pdo;
}
function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM ownership_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
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
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'ownership_fixture', 'Unexpected schema/search path');
}
function schema(PDO $pdo, string $nonce): void
{
    $pdo->exec('CREATE SCHEMA ownership_fixture; SET search_path=ownership_fixture,pg_catalog; CREATE TABLE fixture_guard (nonce text PRIMARY KEY)');
    $pdo->prepare('INSERT INTO fixture_guard VALUES (?)')->execute([$nonce]);
    $pdo->exec(<<<'SQL'
CREATE TABLE users (id uuid PRIMARY KEY, deleted_at timestamptz);
CREATE TABLE jurisdictions (id uuid PRIMARY KEY, parent_id uuid, deleted_at timestamptz);
CREATE TABLE currencies (id uuid PRIMARY KEY, jurisdiction_id uuid, name text, code text, symbol text, precision smallint DEFAULT 6, deleted_at timestamptz);
CREATE TABLE organizations (id uuid PRIMARY KEY, agent_user_id uuid, structure text, status text DEFAULT 'active', name text,
    jurisdiction_id uuid, board_id uuid, is_cgc boolean DEFAULT false, is_active boolean DEFAULT true, is_registered boolean DEFAULT true,
    dissolution_reason text, dissolved_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
CREATE TABLE org_ownership_stakes (id uuid PRIMARY KEY, organization_id uuid REFERENCES organizations(id), holder_type text, holder_id uuid,
    units numeric(20,6) NOT NULL CHECK (units>0), pct numeric(7,4), acquired_via text, source_transfer_id uuid, as_of timestamptz,
    ended_at timestamptz, created_at timestamptz, updated_at timestamptz);
CREATE INDEX stakes_org_open ON org_ownership_stakes (organization_id) WHERE ended_at IS NULL;
CREATE INDEX stakes_holder_open ON org_ownership_stakes (holder_type,holder_id) WHERE ended_at IS NULL;
CREATE TABLE org_memberships (id uuid PRIMARY KEY, organization_id uuid REFERENCES organizations(id), user_id uuid REFERENCES users(id),
    kind text, status text, applied_at timestamptz, accepted_at timestamptz, ended_at timestamptz, end_reason text,
    created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
CREATE UNIQUE INDEX memberships_one_open ON org_memberships(organization_id,user_id,kind) WHERE status IN ('applied','active') AND deleted_at IS NULL;
CREATE TABLE org_contracts (organization_id uuid, status text, deleted_at timestamptz);
CREATE TABLE org_workers (employer_id uuid, employer_type text, status text, ended_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
CREATE TABLE org_document_packages (organization_id uuid, status text, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
CREATE TABLE fixture_records (id uuid PRIMARY KEY, subject_id uuid);
CREATE TABLE economic_accounts (id uuid PRIMARY KEY, currency_id uuid, kind text DEFAULT 'user', status text DEFAULT 'open', balance numeric(24,6) DEFAULT 0,
    created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
CREATE TABLE economic_account_bindings (id uuid PRIMARY KEY, account_id uuid REFERENCES economic_accounts(id), owner_type text, owner_id uuid, created_at timestamptz, updated_at timestamptz);
CREATE UNIQUE INDEX account_binding_unique ON economic_account_bindings (owner_type,owner_id);
CREATE TABLE share_offers (id uuid PRIMARY KEY, organization_id uuid, seller_holder_type text DEFAULT 'users', seller_holder_id uuid, units numeric(20,6),
    price_per_unit numeric(24,6), currency_id uuid, status text DEFAULT 'open', buyer_holder_type text, buyer_holder_id uuid, money_transfer_id uuid,
    settled_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
CREATE INDEX offers_open_org ON share_offers (organization_id,seller_holder_id) WHERE status='open' AND deleted_at IS NULL;
CREATE TABLE ledger_entries (id uuid PRIMARY KEY, seq bigserial UNIQUE, entry_group uuid, account_type text, account_id uuid, currency_id uuid,
    direction text, amount numeric(24,6), kind text, ref_type text, ref_id uuid, prev_hash char(64), hash char(64), created_at timestamptz);
CREATE TABLE market_transactions (id uuid PRIMARY KEY, from_account_id uuid, to_account_id uuid, currency_id uuid, amount numeric(24,6), kind text, memo text, entry_group uuid, created_at timestamptz);
SQL);
}
function seedOrg(int $org, string $units = '100', int $holders = 1): array
{
    $base = $org * 100000; $ids = ['org' => uid($org), 'agent' => uid($base + 1), 'seller' => uid($base + 2),
        'buyer' => uid($base + 3), 'buyer2' => uid($base + 4), 'recipient' => uid($base + 5)];
    foreach (array_slice($ids, 1) as $id) DB::table('users')->insert(['id' => $id]);
    DB::table('organizations')->insert(['id' => $ids['org'], 'agent_user_id' => $ids['agent'], 'structure' => 'stock', 'name' => 'Synthetic ownership fixture', 'jurisdiction_id' => uid(999)]);
    foreach (['seller', 'buyer', 'buyer2'] as $key) {
        $accountId = uid($base + ($key === 'seller' ? 11 : ($key === 'buyer' ? 12 : 13)));
        DB::table('economic_accounts')->insert(['id' => $accountId, 'currency_id' => uid(998), 'balance' => $key === 'seller' ? '0' : '100']);
        DB::table('economic_account_bindings')->insert(['id' => uid($base + ($key === 'seller' ? 21 : ($key === 'buyer' ? 22 : 23))), 'account_id' => $accountId, 'owner_type' => 'users', 'owner_id' => $ids[$key]]);
        $ids[$key.'_account'] = $accountId;
    }
    if ($holders === 1) {
        (new OrganizationMarketParticipation(new OrgOwnershipService))->handle((new User)->forceFill(['id' => $ids['agent']]),
            ['organization_id' => $ids['org'], 'action' => 'issue_shares', 'holder_type' => 'users', 'holder_id' => $ids['seller'], 'units' => $units]);
    } else {
        // Bounded synthetic cohort; no existing identities, geometry, or institution fixtures.
        foreach (array_chunk(range(1, $holders), 100) as $chunk) {
            $users = []; $stakes = []; $members = [];
            foreach ($chunk as $n) {
                $holder = uid($base + 100 + $n); $users[] = ['id' => $holder];
                $stakes[] = ['id' => uid($base + 20000 + $n), 'organization_id' => $ids['org'], 'holder_type' => 'users', 'holder_id' => $holder,
                    'units' => '1.000000', 'pct' => '0.0000', 'acquired_via' => 'founding', 'as_of' => '2026-09-13'];
                $members[] = ['id' => uid($base + 40000 + $n), 'organization_id' => $ids['org'], 'user_id' => $holder, 'kind' => 'shareholder', 'status' => 'active'];
            }
            DB::table('users')->insert($users); DB::table('org_ownership_stakes')->insert($stakes); DB::table('org_memberships')->insert($members);
        }
    }
    return $ids;
}
function offer(array $ids, int $number, string $units, string $price = '1'): string
{
    $id = uid(90000000 + $number);
    DB::table('share_offers')->insert(['id' => $id, 'organization_id' => $ids['org'], 'seller_holder_id' => $ids['seller'],
        'units' => $units, 'price_per_unit' => $price, 'currency_id' => uid(998)]);
    return $id;
}
function operation(array $job): mixed
{
    $ownership = new OrgOwnershipService;
    if (($job['failure'] ?? '') === 'membership') OrgMembership::creating(fn () => throw new RuntimeException('Synthetic membership failure'));
    $actor = (new User)->forceFill(['id' => $job['actor'] ?? null]);
    $trades = new ShareTradeService(new AccountService(new LedgerService), $ownership);
    return match ($job['op']) {
        'issue' => (new OrganizationMarketParticipation($ownership))->handle($actor, ['action' => 'issue_shares', 'organization_id' => $job['org'],
            'holder_type' => 'users', 'holder_id' => $job['holder'], 'units' => $job['units']]),
        'buy' => $trades->buy($actor, $job['offer']),
        'offer' => (new App\Domain\Forms\Handlers\ShareTrade($trades))->handle($actor, ['action' => 'offer_shares',
            'organization_id' => $job['org'], 'units' => $job['units'], 'price_per_unit' => '1']),
        'recompute' => $ownership->recomputePct($job['org']),
        'dissolve' => (new OrgRegistryService(new class(($job['failure'] ?? '') === 'record') extends PublicRecordService {
            public function __construct(private bool $fail) {}
            public function publish(string $kind, string $title, ?string $body = null, array $attrs = []): PublicRecord
            {
                if ($this->fail) throw new RuntimeException('Synthetic record failure');
                $id = (string) Illuminate\Support\Str::uuid();
                DB::table('fixture_records')->insert(['id' => $id, 'subject_id' => $attrs['subject_id']]);
                return (new PublicRecord)->forceFill(['id' => $id]);
            }
        }, new RoleService, $ownership))->dissolve(Organization::findOrFail($job['org']), $actor, 'Synthetic fixture dissolution'),
        default => throw new RuntimeException('Unknown fixture operation'),
    };
}
function worker(string $name, string $nonce, array $job): never
{
    bootFixture($name, $nonce);
    $pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid; $paused = false; $queries = 0;
    DB::listen(function ($event) use ($job, $pid, &$paused, &$queries) {
        $queries++;
        if (! $paused && isset($job['pause']) && preg_match($job['pause'], $event->sql)) {
            $paused = true; emit(['event' => 'paused', 'pid' => $pid]);
            $read = [STDIN]; $write = $except = [];
            check(stream_select($read, $write, $except, 12) === 1 && trim((string) fgets(STDIN)) === 'go', 'Fixture barrier timed out');
        }
    });
    emit(['event' => 'started', 'pid' => $pid]);
    memory_reset_peak_usage(); $before = memory_get_usage(true); $start = hrtime(true);
    try { $result = operation($job); $out = ['ok' => true, 'result' => $result]; }
    catch (Throwable $e) { $out = ['ok' => false, 'exception' => get_class($e), 'message' => $e->getMessage(), 'sqlstate' => $e instanceof PDOException ? $e->getCode() : null]; }
    emit(['event' => 'result', 'pid' => $pid, 'duration_ms' => round((hrtime(true) - $start) / 1e6, 3), 'queries' => $queries,
        'peak_mb' => memory_get_peak_usage(true) / 1048576, 'growth_mb' => (memory_get_peak_usage(true) - $before) / 1048576] + $out);
    DB::disconnect(); exit(0);
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
function state(string $org): array
{
    $stakes = DB::table('org_ownership_stakes')->where('organization_id', $org)->whereNull('ended_at')->get(['holder_id', 'units', 'pct']);
    $members = DB::table('org_memberships')->where('organization_id', $org)->whereIn('status', ['active', 'applied'])->whereNull('deleted_at')->pluck('user_id')->all();
    $total = '0.000000'; $byHolder = [];
    foreach ($stakes as $stake) { $total = bcadd($total, $stake->units, 6); $byHolder[$stake->holder_id] = bcadd($byHolder[$stake->holder_id] ?? '0', $stake->units, 6); }
    $holders = array_keys($byHolder); sort($holders); sort($members);
    check($holders === $members, 'Open user stakes and owner memberships diverged');
    foreach ($stakes as $stake) {
        $expected = bcadd(bcdiv(bcmul($stake->units, '100', 6), $total, 5), '0.00005', 4);
        check($expected === $stake->pct, 'Published percentage is incoherent with committed units');
    }
    return ['units' => $total, 'stakes' => count($stakes), 'holders' => count($holders), 'by_holder' => $byHolder,
        'status' => DB::table('organizations')->where('id', $org)->value('status')];
}

if (($argv[1] ?? '') === 'worker') worker($argv[2], $argv[3], json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR));
if (($argv[1] ?? '') !== '--run') { echo "Opt-in disposable PostgreSQL ownership tests: php tests/concurrency/ownership.php --run\n"; exit(0); }

$name = 'cga_ownership_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $fixture = null; $failures = 0;
try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name); schema($fixture, $nonce); identity($fixture, $name, $nonce); $fixture = null;
    bootFixture($name, $nonce);
    DB::table('jurisdictions')->insert(['id' => uid(999)]); DB::table('currencies')->insert(['id' => uid(998), 'jurisdiction_id' => uid(999), 'name' => 'Synthetic unit', 'code' => 'FIX', 'symbol' => 'F']);
    $lock = '/select .*from "organizations".*for update/i';
    $run = function (string $label, callable $test) use (&$failures) {
        try { $evidence = $test(); emit(['check' => $label, 'passed' => true] + ($evidence ?? [])); }
        catch (Throwable $e) { $failures++; emit(['check' => $label, 'passed' => false, 'message' => $e->getMessage()]); }
    };
    $issue = fn ($ids, $units, $holder = null) => ['op' => 'issue', 'org' => $ids['org'], 'actor' => $ids['agent'], 'holder' => $holder ?? $ids['recipient'], 'units' => $units];
    $buy = fn ($ids, $offer, $buyer = null) => ['op' => 'buy', 'org' => $ids['org'], 'actor' => $buyer ?? $ids['buyer'], 'offer' => $offer];
    $dissolve = fn ($ids) => ['op' => 'dissolve', 'org' => $ids['org'], 'actor' => $ids['agent']];
    $run('concurrent issuance to one holder', function () use ($name, $nonce, $lock, $issue) {
        $ids = seedOrg(1); $result = pair($name, $nonce, $issue($ids, '10') + ['pause' => $lock], $issue($ids, '20'));
        check($result['first']['ok'] && $result['second']['ok'], 'Both permitted issuances must commit'); $s = state($ids['org']);
        check($s['units'] === '130.000000' && $s['holders'] === 2, 'Issuance lost units or duplicated membership'); return $result + ['state' => $s];
    });
    foreach ([false, true] as $tradeFirst) $run($tradeFirst ? 'resale then concurrent issuance' : 'issuance then concurrent resale', function () use ($name, $nonce, $lock, $issue, $buy, $tradeFirst) {
        $ids = seedOrg($tradeFirst ? 3 : 2); $off = offer($ids, $tradeFirst ? 3 : 2, '100', '0');
        $a = $tradeFirst ? $buy($ids, $off) : $issue($ids, '20', $ids['seller']); $b = $tradeFirst ? $issue($ids, '20', $ids['seller']) : $buy($ids, $off);
        $result = pair($name, $nonce, $a + ['pause' => $lock], $b); check($result['first']['ok'] && $result['second']['ok'], 'Both operations should serialize');
        $s = state($ids['org']); check($s['units'] === '120.000000' && $s['by_holder'][$ids['seller']] === '20.000000', 'Resale/issuance units diverged'); return $result + ['state' => $s];
    });
    $run('two buyers race the same paid offer', function () use ($name, $nonce, $buy) {
        $ids = seedOrg(4); $off = offer($ids, 4, '100');
        $result = pair($name, $nonce, $buy($ids, $off) + ['pause' => '/select .*from "share_offers".*for update/i'], $buy($ids, $off, $ids['buyer2']));
        check($result['first']['ok'] && ! $result['second']['ok'], 'Same offer must settle once'); $s = state($ids['org']);
        check($s['units'] === '100.000000' && $s['holders'] === 1 && isset($s['by_holder'][$ids['buyer']]), 'Full divestment membership mismatch');
        check(DB::table('economic_accounts')->where('id', $ids['buyer2_account'])->value('balance') === '100.000000', 'Losing buyer paid'); return $result + ['state' => $s];
    });
    $run('contended oversubscribed offers refuse a second settlement', function () use ($name, $nonce, $lock, $buy) {
        $ids = seedOrg(5); $a = offer($ids, 51, '80'); $b = offer($ids, 52, '80');
        $result = pair($name, $nonce, $buy($ids, $a) + ['pause' => $lock], $buy($ids, $b, $ids['buyer2']));
        check($result['first']['ok'] && ! $result['second']['ok'], 'Oversubscribed settlement must refuse');
        $s = state($ids['org']); check($s['units'] === '100.000000', 'Oversubscription minted units');
        check(DB::table('share_offers')->where('id', $b)->value('status') === 'open', 'Losing offer was not rolled back');
        check(DB::table('economic_accounts')->where('id', $ids['buyer2_account'])->value('balance') === '100.000000', 'Losing buyer paid'); return $result + ['state' => $s];
    });
    $run('same buyer cannot overdraft across concurrent paid offers', function () use ($name, $nonce, $lock, $buy) {
        $ids = seedOrg(6); $a = offer($ids, 61, '40', '2'); $b = offer($ids, 62, '40', '2');
        $result = pair($name, $nonce, $buy($ids, $a) + ['pause' => $lock], $buy($ids, $b));
        check($result['first']['ok'] && ! $result['second']['ok'], 'Underfunded concurrent buy must refuse'); $s = state($ids['org']);
        check($s['by_holder'][$ids['buyer']] === '40.000000', 'Failed paid buy moved equity');
        check(DB::table('economic_accounts')->where('id', $ids['buyer_account'])->value('balance') === '20.000000', 'Wallet overdrew'); return $result + ['state' => $s];
    });
    foreach (['issue', 'buy'] as $op) foreach ([false, true] as $dissolveFirst) $run(($dissolveFirst ? 'dissolve then ' : $op.' then ').($dissolveFirst ? $op : 'dissolve'), function () use ($name, $nonce, $lock, $issue, $buy, $dissolve, $op, $dissolveFirst) {
        $n = ($op === 'issue' ? 7 : 9) + (int) $dissolveFirst; $ids = seedOrg($n);
        $work = $op === 'issue' ? $issue($ids, '20') : $buy($ids, offer($ids, $n, '10'));
        $a = $dissolveFirst ? $dissolve($ids) : $work; $b = $dissolveFirst ? $work : $dissolve($ids);
        $result = pair($name, $nonce, $a + ['pause' => $lock], $b);
        check($result['first']['ok'] && ($dissolveFirst ? ! $result['second']['ok'] : $result['second']['ok']), 'Dissolution ordering produced an unexpected result');
        $s = state($ids['org']); check($s['status'] === 'dissolved' && $s['stakes'] === 0 && $s['holders'] === 0, 'Dissolution left ownership active'); return $result + ['state' => $s];
    });
    $run('failed issuance rolls back before waiting issuance commits', function () use ($name, $nonce, $lock, $issue) {
        $ids = seedOrg(11); $a = $issue($ids, '10') + ['pause' => $lock, 'failure' => 'membership'];
        $result = pair($name, $nonce, $a, $issue($ids, '20'));
        check(! $result['first']['ok'] && $result['second']['ok'], 'Injected membership failure must roll back');
        $s = state($ids['org']); check($s['units'] === '120.000000', 'Failed issuance retained a stake'); return $result + ['state' => $s];
    });
    $run('failed dissolution rolls back before waiting resale commits', function () use ($name, $nonce, $lock, $buy, $dissolve) {
        $ids = seedOrg(12); $off = offer($ids, 12, '100', '0');
        $result = pair($name, $nonce, $dissolve($ids) + ['pause' => $lock, 'failure' => 'record'], $buy($ids, $off));
        check(! $result['first']['ok'] && $result['second']['ok'], 'Injected publication failure must roll back');
        $s = state($ids['org']); check($s['status'] === 'active' && $s['units'] === '100.000000' && $s['holders'] === 1, 'Failed dissolution was partially committed'); return $result + ['state' => $s];
    });
    $run('concurrent offers cannot reserve more units than held', function () use ($name, $nonce) {
        $ids = seedOrg(13); $job = ['op' => 'offer', 'org' => $ids['org'], 'actor' => $ids['seller'], 'units' => '80', 'pause' => '/select sum\("units"\).*from "share_offers"/i'];
        $second = $job; unset($second['pause']);
        $result = pair($name, $nonce, $job, $second);
        $reserved = (string) DB::table('share_offers')->where('organization_id', $ids['org'])->where('status', 'open')->sum('units');
        emit(['diagnostic' => 'offer_reservation', 'reserved_units' => $reserved, 'held_units' => state($ids['org'])['units'], 'first_ok' => $result['first']['ok'], 'second_ok' => $result['second']['ok']]);
        check($result['first']['ok'] && ! $result['second']['ok'], 'Only the first competing reservation should succeed');
        check($result['second']['exception'] === App\Domain\Engine\ConstitutionalViolation::class, 'The losing reservation must receive a civic refusal, not a timeout');
        check(bccomp($reserved, '100', 6) <= 0, 'Concurrent open offers reserve '.$reserved.' units against 100.000000 held'); return $result;
    });
    $run('resale preserves exact units at supported large decimal scale', function () use ($name, $nonce, $buy) {
        $ids = seedOrg(14, '10000000000000.123456'); $off = offer($ids, 14, '0.000001', '0'); $result = one($name, $nonce, $buy($ids, $off));
        $s = state($ids['org']); emit(['diagnostic' => 'large_decimal_resale', 'operation' => $result, 'remaining_units' => $s['units']]);
        check($result['ok'] && $s['units'] === '10000000000000.123456', 'Resale changed exact units: '.$s['units']); return ['operation' => $result];
    });
    foreach ([['0.000001', '0.000001', '0.000000'], ['0.300000', '0.100000', '0.200000'],
        ['99999999999999.999999', '99999999999999.999998', '0.000001']] as $index => [$held, $sold, $remaining]) {
        $run('exact decimal resale boundary '.$index, function () use ($name, $nonce, $buy, $index, $held, $sold, $remaining) {
            $ids = seedOrg(20 + $index, $held); $off = offer($ids, 20 + $index, $sold, '0');
            $result = one($name, $nonce, $buy($ids, $off)); $s = state($ids['org']);
            check($result['ok'] && $s['units'] === $held, 'Boundary resale failed or changed units');
            check(($s['by_holder'][$ids['seller']] ?? '0.000000') === $remaining && $s['by_holder'][$ids['buyer']] === $sold, 'Boundary split lost precision');
            return ['operation' => $result, 'state' => $s];
        });
    }
    $run('handler and offer insertion preserve the original large decimal string', function () use ($name, $nonce) {
        $ids = seedOrg(23, '99999999999999.999999'); $units = '99999999999999.123456';
        $result = one($name, $nonce, ['op' => 'offer', 'org' => $ids['org'], 'actor' => $ids['seller'], 'units' => $units]);
        check($result['ok'], 'Valid exact offer was refused');
        check(DB::table('share_offers')->where('organization_id', $ids['org'])->value('units') === $units, 'Handler or insertion coerced the decimal');
        return ['operation' => $result];
    });
    $run('fractional quantity beyond supported precision is refused without a reservation', function () use ($name, $nonce) {
        $ids = seedOrg(24); $result = one($name, $nonce, ['op' => 'offer', 'org' => $ids['org'], 'actor' => $ids['seller'], 'units' => '0.0000001']);
        check(! $result['ok'] && $result['exception'] === App\Domain\Engine\ConstitutionalViolation::class, 'Excess precision needs a clear refusal');
        check(! DB::table('share_offers')->where('organization_id', $ids['org'])->exists(), 'Invalid quantity left an offer'); return ['operation' => $result];
    });
    $run('real cash settlement retains its append-only balanced ledger chain', function () {
        check((new LedgerService)->verifyChain() === true, 'Fixture ledger chain verification failed');
        $rows = DB::table('ledger_entries')->selectRaw('entry_group, sum(case when direction=\'debit\' then amount else -amount end) as residual')->groupBy('entry_group')->get();
        foreach ($rows as $row) check(bccomp($row->residual, '0', 6) === 0, 'Paid trade has an unbalanced money posting');
        return ['postings_checked' => count($rows)];
    });
    foreach ([100, 1000, 5000] as $size) $run('percentage recomputation for '.$size.' holders', function () use ($name, $nonce, $size) {
        $ids = seedOrg(100 + $size, '1', $size); $result = one($name, $nonce, ['op' => 'recompute', 'org' => $ids['org']]);
        check($result['ok'], 'Percentage benchmark failed'); $s = state($ids['org']); check($s['units'] === $size.'.000000' && $s['holders'] === $size, 'Benchmark state mismatch');
        return ['holders' => $size, 'operation' => $result];
    });
} catch (Throwable $e) { $failures++; emit(['infrastructure_failure' => $e->getMessage()]); }
finally {
    if ($created) {
        try {
            if (isset($app)) unset($app);
            if (Illuminate\Support\Facades\Facade::getFacadeApplication() !== null) DB::purge();
            $fixture = pdo($name); identity($fixture, $name, $nonce); $fixture = null;
            fixtureName($name); $admin->exec('DROP DATABASE "'.$name.'"');
            emit(['cleanup' => 'verified_fixture_database_removed', 'database' => $name]);
        } catch (Throwable $e) { $failures++; emit(['cleanup_failed' => $e->getMessage(), 'database' => $name]); }
    }
}
emit(['summary' => ['failures' => $failures, 'database' => $name, 'live_world_used' => false, 'real_jobs_executed' => false]]);
exit($failures === 0 ? 0 : 1);
