<?php
/** Opt-in Setup·schema probe: a virgin, nonce-named disposable PostgreSQL database, never the world database.
 * Fresh-install journey: create empty DB from template0 -> php artisan migrate --force (auto-loads the
 * baseline database/schema/pgsql-schema.sql, then applies the additive migration files) -> migrate again
 * (must be a no-op) -> probe the singletons (instance_settings, clocks, audit genesis) -> federation:init
 * twice (identity must persist). The DB is DROPped in a finally block. The Compose-project restart criterion
 * is NOT exercised here (this pass uses a disposable database on box E's postgres, not a disposable Compose
 * project) and is recorded as not established. */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

function emit(array $m): void { echo json_encode($m, JSON_THROW_ON_ERROR)."\n"; flush(); }
function check(bool $c, string $m): void { if (! $c) throw new RuntimeException($m); }
function fixtureName(string $n): void { check((bool) preg_match('/\Acga_schema_[0-9]{8}_[a-f0-9]{16}\z/', $n), 'Refusing non-fixture database name: '.$n); }

function envcfg(): array
{
    $file = dirname(__DIR__, 2).'/.env';
    $vals = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($k, $d = '') => getenv($k) !== false ? getenv($k) : ($vals[$k] ?? $d);
    return ['host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'user' => $read('DB_USERNAME', 'fc_user'), 'pass' => $read('DB_PASSWORD'),
        'world' => $read('DB_DATABASE', 'fair_constitution')];
}

function pdo(string $db): PDO
{
    check($db === 'postgres' || preg_match('/\Acga_schema_[0-9]{8}_[a-f0-9]{16}\z/', $db), 'Only maintenance or fixture connections allowed');
    $c = envcfg();
    $p = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$db}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->exec("SET statement_timeout='120s'; SET lock_timeout='15s'");
    return $p;
}

/** Run an artisan command against the fixture DB by overriding DB_DATABASE in the child environment.
 * Laravel's Dotenv is immutable (createImmutable): it never overwrites a variable already present in the
 * process environment, so the override wins over the .env world value. */
function artisan(string $db, string $cmd): array
{
    fixtureName($db);
    $env = getenv();
    $env['DB_DATABASE'] = $db;
    $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open('php artisan '.$cmd, $descr, $pipes, dirname(__DIR__, 2), $env);
    check(is_resource($proc), 'proc_open failed for: '.$cmd);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return ['exit' => $code, 'out' => $out, 'err' => $err];
}

function serverIdOf(string $out): ?string
{
    return preg_match('/server_id\s*:\s*([0-9a-fA-F-]{36})/', $out, $mm) ? $mm[1] : null;
}

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/schema_baseline_migration.php --run\n"; exit(0); }

$c = envcfg();
$name = 'cga_schema_'.date('Ymd').'_'.bin2hex(random_bytes(8));
fixtureName($name);
check($name !== $c['world'], 'Fixture name collides with world database');
$admin = pdo('postgres');
$created = false; $failures = 0;

try {
    // 1. Virgin disposable database from template0 (never the world DB).
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0');
    $created = true;
    emit(['step' => 'created', 'database' => $name, 'world_untouched' => $c['world']]);

    $v = pdo($name);
    check($v->query("SELECT to_regclass('public.organizations')")->fetchColumn() === null, 'Fresh DB unexpectedly contains public.organizations');
    check((int) $v->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn() === 0, 'Fresh DB public schema is not empty');
    $v = null;
    emit(['step' => 'virgin_verified', 'public_tables' => 0]);

    // 2. Fresh install: baseline schema load + additive migrations.
    $m1 = artisan($name, 'migrate --force --no-interaction');
    emit(['step' => 'migrate_first', 'exit' => $m1['exit'],
        'loaded_baseline_schema' => str_contains($m1['out'], 'Loading stored database schema'),
        'tail' => array_slice(array_values(array_filter(array_map('trim', explode("\n", $m1['out'])), fn ($l) => $l !== '')), -25)]);
    if ($m1['err'] !== '') emit(['step' => 'migrate_first_stderr', 'stderr' => substr($m1['err'], 0, 2000)]);
    check($m1['exit'] === 0, 'First migrate did not exit 0');

    $v = pdo($name);
    $tableCount = (int) $v->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn();
    $ranCount = (int) $v->query('SELECT count(*) FROM public.migrations')->fetchColumn();
    emit(['step' => 'after_first_migrate', 'public_tables' => $tableCount, 'migration_rows' => $ranCount]);
    check($tableCount > 100, 'Baseline did not create the expected table population');
    check($ranCount > 0, 'No migration rows recorded after first migrate');
    $v = null;

    // 3. Second migrate must be a no-op.
    $m2 = artisan($name, 'migrate --force --no-interaction');
    $noop = str_contains($m2['out'], 'Nothing to migrate');
    emit(['step' => 'migrate_second', 'exit' => $m2['exit'], 'nothing_to_migrate' => $noop,
        'tail' => array_slice(array_values(array_filter(array_map('trim', explode("\n", $m2['out'])), fn ($l) => $l !== '')), -12)]);
    check($m2['exit'] === 0, 'Second migrate did not exit 0');
    check($noop, 'Second migrate was not a no-op ("Nothing to migrate" absent)');

    // 4. Singleton / default-record probes.
    $v = pdo($name);
    $instanceCount = (int) $v->query('SELECT count(*) FROM public.instance_settings')->fetchColumn();
    $instance = $v->query('SELECT id, instance_name, server_id, federation_enabled FROM public.instance_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $genesis = $v->query("SELECT seq, module, event, prev_hash, hash FROM public.audit_log WHERE event='genesis' ORDER BY seq LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $genesisTotal = (int) $v->query("SELECT count(*) FROM public.audit_log WHERE event='genesis'")->fetchColumn();
    $clockCount = (int) $v->query('SELECT count(*) FROM public.clocks')->fetchColumn();
    emit(['step' => 'singletons', 'instance_settings_rows' => $instanceCount, 'instance_name' => $instance['instance_name'] ?? null,
        'server_id_before_init' => $instance['server_id'] ?? null, 'genesis_rows' => $genesisTotal,
        'genesis_seq' => $genesis['seq'] ?? null, 'genesis_prev_hash' => $genesis['prev_hash'] ?? null, 'clocks_rows' => $clockCount]);
    check($instanceCount === 1, 'instance_settings is not a singleton (expected exactly 1 row), got '.$instanceCount);
    check($genesisTotal === 1, 'audit genesis is not unique (expected exactly 1), got '.$genesisTotal);
    check(($genesis['prev_hash'] ?? '') === str_repeat('0', 64), 'audit genesis prev_hash is not the all-zero GENESIS_PREV_HASH');
    check($clockCount > 0, 'No clock reference rows present');
    $v = null;

    // 5. Federation identity mint, then persistence across a second process invocation.
    $f1 = artisan($name, 'federation:init');
    $id1 = serverIdOf($f1['out']);
    emit(['step' => 'federation_init_first', 'exit' => $f1['exit'], 'server_id' => $id1,
        'enabled_line' => trim(strtok($f1['out'], "\n"))]);
    check($f1['exit'] === 0, 'First federation:init did not exit 0');
    check($id1 !== null, 'First federation:init printed no server_id');

    $f2 = artisan($name, 'federation:init');
    $id2 = serverIdOf($f2['out']);
    emit(['step' => 'federation_init_second', 'exit' => $f2['exit'], 'server_id' => $id2]);
    check($f2['exit'] === 0, 'Second federation:init did not exit 0');
    check($id2 === $id1, 'Federation identity changed on re-run (expected idempotent persistence): '.$id1.' -> '.$id2);

    $v = pdo($name);
    $persisted = $v->query('SELECT server_id, federation_enabled FROM public.instance_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    emit(['step' => 'federation_persisted', 'server_id' => $persisted['server_id'] ?? null, 'federation_enabled' => $persisted['federation_enabled'] ?? null]);
    check(($persisted['server_id'] ?? null) === $id1, 'Persisted server_id does not match the minted identity');
    check(in_array($persisted['federation_enabled'] ?? null, [true, 't', '1', 1], true), 'federation_enabled not set true after init');
    $v = null;

    emit(['step' => 'compose_restart_criterion', 'established' => false,
        'note' => 'This pass uses a disposable database on box E postgres, not a disposable Compose project; process-level re-invocation of federation:init proxied identity persistence, but a full Compose project/network/storage restart was not exercised.']);
    emit(['result' => 'all_criteria_held']);
} catch (Throwable $e) {
    $failures++;
    emit(['failure' => $e->getMessage()]);
} finally {
    if ($created) {
        try {
            fixtureName($name);
            // Terminate any lingering backend connections to the fixture DB, then drop it.
            $admin->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ".$admin->quote($name)." AND pid <> pg_backend_pid()");
            $admin->exec('DROP DATABASE IF EXISTS "'.$name.'"');
            emit(['cleanup' => 'fixture_database_removed', 'database' => $name]);
        } catch (Throwable $e) {
            $failures++;
            emit(['cleanup_failed' => $e->getMessage(), 'database' => $name]);
        }
    }
}
emit(['failures' => $failures, 'live_world_used' => false]);
exit($failures === 0 ? 0 : 1);
