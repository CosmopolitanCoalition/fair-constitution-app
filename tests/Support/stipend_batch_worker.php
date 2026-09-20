<?php
// Child process of StipendBatchTest. Refuse all non-fixture connections.
require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
$c = $input['connection'];
if (! preg_match('/^phase10_test_[a-f0-9]{16}$/D', $c['database'])) { throw new RuntimeException('Refusing non-fixture database.'); }
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/nonexistent-stipend-batch-config.php', 'DB_URL' => '',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.batch_child' => array_replace($c, ['name' => 'batch_child', 'url' => null])]);
Illuminate\Support\Facades\DB::setDefaultConnection('batch_child');
$db = Illuminate\Support\Facades\DB::connection();
if ($db->selectOne('SELECT current_database() AS name')->name !== $c['database']) { throw new RuntimeException('Wrong fixture database.'); }
$db->statement("SET statement_timeout='10s'");
$sim = Mockery::mock(App\Services\Demo\SimEconomyService::class,
    [app(App\Services\Economy\AccountService::class), app(App\Services\Economy\StipendService::class)])->makePartial();
$sim->shouldReceive('ensureCurrency')->andReturn(['currency' => App\Models\Economy\Currency::findOrFail($input['currency']), 'treasury_id' => $input['treasury']]);
app()->instance(App\Services\Demo\SimEconomyService::class, $sim);
$run = App\Models\SimRun::findOrFail($input['run']);
$items = App\Support\SimClaims::stipendBatch($run, $input['token'], 4);
echo json_encode(['pid' => $db->selectOne('SELECT pg_backend_pid() AS pid')->pid, 'items' => array_column($items, 'id')])."\n"; flush();
if (trim(fgets(STDIN)) !== 'GO') { exit(2); }
$mode = $input['mode'] ?? 'normal';
if ($mode !== 'normal') {
    $event = $mode === 'before' ? Illuminate\Database\Events\TransactionCommitting::class : Illuminate\Database\Events\TransactionCommitted::class;
    app('events')->listen($event, function ($event) use ($mode) {
        if ($event->connection->transactionLevel() === ($mode === 'before' ? 1 : 0)) {
            echo ($mode === 'before' ? 'COMMITTING' : 'COMMITTED')."\n"; flush();
            fgets(STDIN); // parent kills this process at the exact crash boundary
        }
    });
}
$result = app(App\Services\Demo\SimStipendBatch::class)->run($run, $items, $input['token']);
echo json_encode($result)."\n"; flush();
