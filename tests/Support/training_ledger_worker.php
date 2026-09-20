<?php
// Child of TrainingLedgerPerformanceTest; refuses every non-fixture database.
require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
$c = $input['connection'];
if (! preg_match('/^ledger_test_[a-f0-9]{16}$/D', $c['database'])) {
    throw new RuntimeException('Refusing non-fixture database.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/nonexistent-ledger-test-config.php', 'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.ledger_child' => array_replace($c, ['name' => 'ledger_child', 'url' => null])]);
Illuminate\Support\Facades\DB::setDefaultConnection('ledger_child');
$db = Illuminate\Support\Facades\DB::connection();
if ($db->selectOne('SELECT current_database() AS name')->name !== $c['database']) {
    throw new RuntimeException('Wrong test database.');
}
$db->statement("SET lock_timeout = '10s'");
$db->statement("SET statement_timeout = '12s'");
echo json_encode(['ready' => true, 'pid' => $db->selectOne('SELECT pg_backend_pid() AS pid')->pid]).PHP_EOL;
flush();
if (trim(fgets(STDIN)) !== 'GO') { exit(2); }
if (in_array($input['mode'] ?? '', ['training', 'repair_training'], true)) {
    $settings = Mockery::mock(App\Services\SettingsResolver::class);
    $settings->shouldReceive('resolveInt')->once()->andReturn(10);
    $settings->shouldReceive('resolve')->once()->andReturn('minted');
    app()->instance(App\Services\SettingsResolver::class, $settings);
    $learner = new App\Models\User;
    $learner->id = $input['user'];
    $stipend = app(App\Services\Education\TrainingStipendService::class);
    $repair = $input['mode'] === 'repair_training';
    if ($repair) {
        $db->beginTransaction();
        App\Services\Demo\RepairChairAudit::begin();
        app(App\Services\AuditService::class)->append('fixture', 'repair.training', ['user_id' => $input['user']]);
    }
    $stipend->beginBatch();
    $stipend->payOnce($learner);
    $stipend->commitBatch();
    $stipend->commitBatch();
    if ($repair) {
        App\Services\Demo\RepairChairAudit::flush();
        $db->commit();
        App\Services\Demo\RepairChairAudit::end();
    }
    Mockery::close();
} else {
    $accounts = app(App\Services\Economy\AccountService::class);
    for ($i = 0; $i < 12; $i++) {
        $accounts->creditManyFromTreasury($input['treasury'], [['account_id' => $input['wallet'], 'amount' => '1']], $input['currency'], 'stipend');
    }
}
echo "DONE\n";
