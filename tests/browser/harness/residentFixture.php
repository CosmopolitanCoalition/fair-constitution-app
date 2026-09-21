<?php

// Local browser fixture only. Never migrate, seed or clean an installed world.
if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') { throw new RuntimeException('Explicit fixture opt-in required.'); }
putenv('APP_CONFIG_CACHE=/tmp/resident-fixture-no-config.php');
putenv('APP_ROUTES_CACHE=/tmp/resident-fixture-no-routes.php');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never { fwrite(STDERR, $error->getMessage()."\n"); exit(1); });
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Support\Str;

$statePath = storage_path('framework/testing/resident-browser.json');
$base = array_replace(config('database.connections.pgsql'), ['database' => 'postgres', 'url' => null]);
config(['database.connections.browser_admin' => $base, 'cache.default' => 'array', 'queue.default' => 'sync']);
$admin = DB::connection('browser_admin');
if ($admin->selectOne('SELECT current_database() AS name')->name !== 'postgres') { throw new RuntimeException('Unsafe admin connection'); }
if (($argv[1] ?? '') === 'drop') {
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    if (!preg_match('/^resident_browser_[a-f0-9]{16}$/D', $state['database'])) { throw new RuntimeException('Unsafe fixture name'); }
    $admin->statement('DROP DATABASE '.$state['database'].' WITH (FORCE)');
    unlink($statePath);
    echo "Disposable browser fixture removed.\n";
    exit;
}
if (in_array($argv[1] ?? '', ['open', 'concurrency'], true)) {
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    if (!preg_match('/^resident_browser_[a-f0-9]{16}$/D', $state['database'])) { throw new RuntimeException('Unsafe fixture name'); }
    DB::purge('pgsql'); config(['database.connections.pgsql' => array_replace($base, ['database' => $state['database']])]); DB::setDefaultConnection('pgsql');
    if (DB::selectOne('SELECT current_database() AS name')->name !== $state['database']) { throw new RuntimeException('Unsafe fixture connection'); }
    if ($argv[1] === 'open') {
        $id = DB::transaction(function () use ($argv) {
            $wallet = app(App\Services\Economy\ResidentWalletService::class)->ensure($argv[2]);
            usleep(200000);
            return $wallet->id;
        });
        echo $id;
        exit;
    }
    $id = (string) Str::uuid();
    $u = App\Models\User::forceCreate(['id' => $id, 'name' => 'Concurrent fixture', 'email' => $id.'@browser.test', 'password' => 'unused', 'terms_accepted_at' => now()]);
    $scope = DB::table('currencies')->value('jurisdiction_id');
    DB::table('residency_confirmations')->insert(['user_id' => $id, 'jurisdiction_id' => $scope, 'days_confirmed' => 1, 'is_active' => true, 'confirmed_at' => now()]);
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $child = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, 'open', $id]);
        $child->setTimeout(30); $child->start(); $children[] = $child;
    }
    foreach ($children as $child) { $child->wait(); if (!$child->isSuccessful()) throw new RuntimeException($child->getErrorOutput()); }
    $a = trim($children[0]->getOutput()); $b = trim($children[1]->getOutput());
    if (!Str::isUuid($a) || $a !== $b || DB::table('economic_account_bindings')->where('owner_id', $id)->count() !== 1) {
        throw new RuntimeException('Concurrent requests must return exactly one wallet.');
    }
    echo "PASS two concurrent real PostgreSQL wallet openings return the same single account.\n";
    exit;
}
if (file_exists($statePath)) { throw new RuntimeException('Existing browser fixture must be cleaned first.'); }
$db = 'resident_browser_'.bin2hex(random_bytes(8));
$admin->statement('CREATE DATABASE '.$db.' TEMPLATE template0');
@mkdir(dirname($statePath), 0775, true);
file_put_contents($statePath, json_encode(['database' => $db], JSON_THROW_ON_ERROR));
$fixture = array_replace($base, ['database' => $db]);
DB::purge('pgsql'); config(['database.connections.pgsql' => $fixture]); DB::setDefaultConnection('pgsql');
if (DB::selectOne('SELECT current_database() AS name')->name !== $db) { throw new RuntimeException('Unsafe fixture connection'); }
$p = new Symfony\Component\Process\Process(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-h', $base['host'], '-p', (string) $base['port'],
    '-U', $base['username'], '-d', $db, '-f', base_path('database/schema/pgsql-schema.sql')], null, ['PGPASSWORD' => $base['password']]);
$p->setTimeout(120); $p->mustRun();
Artisan::call('migrate', ['--force' => true]);
$id = (string) Str::uuid();
DB::table('jurisdictions')->insert(['id' => $id, 'name' => 'Browser Test Earth', 'slug' => 'browser-test-earth', 'adm_level' => 0, 'population' => 1000,
    'geom' => DB::raw("ST_Multi(ST_MakeEnvelope(-179,-80,179,80,4326))"), 'created_at' => now(), 'updated_at' => now()]);
App\Models\InstanceSettings::updateOrCreate([], ['instance_name' => 'Disposable browser test', 'setup_completed_at' => now(), 'setup_step_completed' => 7]);
App\Models\Economy\Currency::create(['jurisdiction_id' => $id, 'name' => 'Test credits', 'code' => 'TST', 'symbol' => 'T']);
foreach (['new', 'confirmed'] as $kind) {
    $u = App\Models\User::forceCreate(['name' => 'Browser '.$kind, 'email' => $kind.'@browser.test', 'password' => Illuminate\Support\Facades\Hash::make('fixture-password-only'), 'terms_accepted_at' => now()]);
    if ($kind === 'confirmed') DB::table('residency_confirmations')->insert(['user_id' => $u->id, 'jurisdiction_id' => $id, 'days_confirmed' => 1, 'is_active' => true, 'confirmed_at' => now()]);
}
echo json_encode(['database' => $db, 'scope' => $id])."\n";
