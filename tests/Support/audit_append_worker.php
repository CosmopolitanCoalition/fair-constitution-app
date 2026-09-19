<?php
// Child of AuditAppendPerformanceTest. It can only connect to a nonce test DB.
require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
$c = $input['connection'];
if (! preg_match('/^audit_test_[a-f0-9]{16}$/D', $c['database'])) {
    throw new RuntimeException('Refusing non-fixture database.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/nonexistent-audit-test-config.php', 'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.audit_child' => array_replace($c, ['name' => 'audit_child', 'url' => null])]);
Illuminate\Support\Facades\DB::setDefaultConnection('audit_child');
$db = Illuminate\Support\Facades\DB::connection();
if ($db->selectOne('SELECT current_database() AS name')->name !== $c['database']) {
    throw new RuntimeException('Wrong test database.');
}
$db->statement("SET lock_timeout = '10s'");
$db->statement("SET statement_timeout = '12s'");
echo json_encode(['ready' => true, 'pid' => $db->selectOne('SELECT pg_backend_pid() AS pid')->pid]).PHP_EOL;
flush();
if (trim(fgets(STDIN)) !== 'GO') { exit(2); }
$audit = app(App\Services\AuditService::class);
for ($i = 0; $i < 20; $i++) {
    $audit->append('fixture', 'concurrent', ['worker' => $input['worker'], 'item' => $i, 'place' => 'Łódź 日本語']);
}
echo "DONE\n";
