<?php
/** Opt-in W-0443 probe: the drawn-seat triggers, on a nonce-guarded disposable database, never the world database. */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;

function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function emit(array $message): void { echo json_encode($message, JSON_THROW_ON_ERROR)."\n"; flush(); }
function fixtureName(string $name): void { check((bool) preg_match('/\Acga_maps_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Refusing non-fixture database name'); }
function connectionConfig(string $name): array
{
    $file = dirname(__DIR__, 2).'/.env';
    $values = is_file($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
    $read = static fn ($key, $default = '') => getenv($key) !== false ? getenv($key) : ($values[$key] ?? $default);
    return ['driver' => 'pgsql', 'host' => $read('DB_HOST', 'postgres'), 'port' => $read('DB_PORT', '5432'),
        'username' => $read('DB_USERNAME', 'fc_user'), 'password' => $read('DB_PASSWORD'), 'database' => $name,
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'maps_fixture,public', 'sslmode' => $read('DB_SSLMODE', 'prefer')];
}
function pdo(string $name): PDO
{
    check($name === 'postgres' || preg_match('/\Acga_maps_[0-9]{8}_[a-f0-9]{16}\z/', $name), 'Only maintenance or private fixture connections are allowed');
    $c = connectionConfig($name);
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$name};sslmode={$c['sslmode']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET statement_timeout='20s'; SET lock_timeout='8s'; SET idle_in_transaction_session_timeout='20s'");
    return $pdo;
}
function identity(PDO $pdo, string $name, string $nonce): void
{
    fixtureName($name);
    $row = $pdo->query('SELECT current_database() AS db, nonce FROM maps_fixture.fixture_guard')->fetch(PDO::FETCH_ASSOC);
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
    check(DB::selectOne('SELECT current_schema() AS schema')->schema === 'maps_fixture', 'Unexpected schema/search path');
}
function mapRow(string $id): object { return DB::selectOne('SELECT drawn_seats, seat_gap FROM legislature_district_maps WHERE id = ?', [$id]); }

if (($argv[1] ?? '') !== '--run') { echo "Opt-in: php tests/concurrency/map_drawn_seats_trigger_check.php --run\n"; exit(0); }
$name = 'cga_maps_'.date('Ymd').'_'.bin2hex(random_bytes(8)); $nonce = bin2hex(random_bytes(16)); fixtureName($name);
$admin = pdo('postgres'); $created = false; $failures = 0;
try {
    $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0'); $created = true;
    $fixture = pdo($name);
    $fixture->exec('CREATE SCHEMA maps_fixture; CREATE TABLE maps_fixture.fixture_guard (nonce text NOT NULL)');
    $fixture->prepare('INSERT INTO maps_fixture.fixture_guard VALUES (?)')->execute([$nonce]);
    identity($fixture, $name, $nonce); $fixture = null; bootFixture($name, $nonce);

    // The three shapes the triggers touch, as the baseline ships them (columns the triggers read).
    DB::statement('CREATE TABLE legislatures (id uuid PRIMARY KEY, jurisdiction_id uuid, type_a_seats int NOT NULL DEFAULT 0, type_b_seats int NOT NULL DEFAULT 0, deleted_at timestamptz)');
    DB::statement("CREATE TABLE legislature_district_maps (id uuid PRIMARY KEY, legislature_id uuid NOT NULL, status varchar(16) NOT NULL DEFAULT 'draft', deleted_at timestamptz)");
    DB::statement('CREATE TABLE legislature_districts (id uuid PRIMARY KEY, legislature_id uuid NOT NULL, map_id uuid, seats smallint NOT NULL DEFAULT 0, bonus_seats smallint NOT NULL DEFAULT 0, deleted_at timestamptz)');

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_14_223000_drawn_seats_on_district_maps.php';
    $migration->up(); $migration->up(); // rerunnable — the second call is a no-op
    emit(['rerunnable_migration' => true]);

    $leg = '70000000-0000-4000-8000-000000000001'; $mapA = '70000000-0000-4000-8000-00000000000a'; $mapB = '70000000-0000-4000-8000-00000000000b';
    DB::table('legislatures')->insert(['id' => $leg, 'type_a_seats' => 12]);
    DB::table('legislature_district_maps')->insert([['id' => $mapA, 'legislature_id' => $leg, 'status' => 'active'], ['id' => $mapB, 'legislature_id' => $leg, 'status' => 'draft']]);
    check(mapRow($mapA)->drawn_seats === null, 'a map with no districts written yet stays NULL (not yet computed)');

    // One multi-row INSERT across both maps: one statement, both maps recomputed.
    DB::table('legislature_districts')->insert([
        ['id' => '70000000-0000-4000-8000-000000000101', 'legislature_id' => $leg, 'map_id' => $mapA, 'seats' => 5],
        ['id' => '70000000-0000-4000-8000-000000000102', 'legislature_id' => $leg, 'map_id' => $mapA, 'seats' => 7],
        ['id' => '70000000-0000-4000-8000-000000000201', 'legislature_id' => $leg, 'map_id' => $mapB, 'seats' => 9],
    ]);
    $a = mapRow($mapA); $b = mapRow($mapB);
    check((int) $a->drawn_seats === 12 && (int) $a->seat_gap === 0, "insert: map A expected 12/0, got {$a->drawn_seats}/{$a->seat_gap}");
    check((int) $b->drawn_seats === 9 && (int) $b->seat_gap === 3, "insert: map B expected 9/3, got {$b->drawn_seats}/{$b->seat_gap}");
    emit(['insert_recomputes_every_touched_map' => true]);

    // The step-8 identity: a bonus seat is not drawn against the budget.
    DB::table('legislature_districts')->insert(['id' => '70000000-0000-4000-8000-000000000103', 'legislature_id' => $leg, 'map_id' => $mapA, 'seats' => 2, 'bonus_seats' => 1]);
    $a = mapRow($mapA);
    check((int) $a->drawn_seats === 13 && (int) $a->seat_gap === -1, "bonus: expected 13/-1, got {$a->drawn_seats}/{$a->seat_gap}");
    emit(['bonus_seats_netted' => true]);

    // A soft delete is an UPDATE: the retired district leaves the total.
    DB::table('legislature_districts')->where('id', '70000000-0000-4000-8000-000000000103')->update(['deleted_at' => now()]);
    $a = mapRow($mapA);
    check((int) $a->drawn_seats === 12 && (int) $a->seat_gap === 0, "soft delete: expected 12/0, got {$a->drawn_seats}/{$a->seat_gap}");
    emit(['soft_delete_recomputes' => true]);

    // Moving a district between maps recomputes both.
    DB::table('legislature_districts')->where('id', '70000000-0000-4000-8000-000000000201')->update(['map_id' => $mapA]);
    $a = mapRow($mapA); $b = mapRow($mapB);
    check((int) $a->drawn_seats === 21 && (int) $b->drawn_seats === 0, "move: expected A 21 / B 0, got {$a->drawn_seats} / {$b->drawn_seats}");
    emit(['move_recomputes_both_maps' => true]);

    // A hard DELETE recomputes.
    DB::table('legislature_districts')->where('id', '70000000-0000-4000-8000-000000000201')->delete();
    check((int) mapRow($mapA)->drawn_seats === 12, 'delete: map A back to 12');
    emit(['delete_recomputes' => true]);

    // The chamber resizes: the gap follows type_a_seats.
    DB::table('legislatures')->where('id', $leg)->update(['type_a_seats' => 14]);
    $a = mapRow($mapA);
    check((int) $a->seat_gap === 2 && (int) $a->drawn_seats === 12, "resize: expected gap 2, got {$a->seat_gap}");
    emit(['type_a_change_recomputes_gap' => true]);

    // The backfill function fills a NULL row from its districts.
    $mapC = '70000000-0000-4000-8000-00000000000c';
    DB::statement('ALTER TABLE legislature_districts DISABLE TRIGGER cga_map_drawn_seats_ins');
    DB::table('legislature_district_maps')->insert(['id' => $mapC, 'legislature_id' => $leg, 'status' => 'active']);
    DB::table('legislature_districts')->insert(['id' => '70000000-0000-4000-8000-000000000301', 'legislature_id' => $leg, 'map_id' => $mapC, 'seats' => 6]);
    DB::statement('ALTER TABLE legislature_districts ENABLE TRIGGER cga_map_drawn_seats_ins');
    check(mapRow($mapC)->drawn_seats === null, 'pre-backfill row is NULL');
    DB::statement('SELECT public.cga_map_drawn_seats_recompute(?::uuid[])', ['{'.$mapC.'}']);
    $c = mapRow($mapC);
    check((int) $c->drawn_seats === 6 && (int) $c->seat_gap === 8, "backfill: expected 6/8, got {$c->drawn_seats}/{$c->seat_gap}");
    emit(['backfill_function_fills_null_rows' => true]);

    // The drift partial index exists and answers the rail's predicate.
    $idx = DB::selectOne("SELECT 1 AS present FROM pg_indexes WHERE indexname = 'legislature_district_maps_drift_idx'");
    check($idx !== null, 'drift index present');
    $drifted = DB::select("SELECT id FROM legislature_district_maps WHERE status = 'active' AND deleted_at IS NULL AND seat_gap IS NOT NULL AND seat_gap <> 0 ORDER BY id");
    check(count($drifted) === 2, 'two active maps drift (A gap 2, C gap 8), got '.count($drifted));
    emit(['drift_index_present' => true, 'drifted_active_maps' => count($drifted)]);
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
