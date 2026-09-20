<?php

// A second real PHP/database process, restricted to the disposable test DB.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1' || ! preg_match('/^repair_test_[a-f0-9]{16}$/D', $argv[1] ?? '')) { exit(91); }
foreach (array_slice($argv, 2, 3) as $id) { if (! \Illuminate\Support\Str::isUuid($id)) { exit(92); } }
$config = array_replace(config('database.connections.pgsql'), ['database' => $argv[1], 'url' => null]);
config(['database.connections.pgsql' => $config, 'cache.default' => 'array', 'queue.default' => 'sync']);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');
if (\Illuminate\Support\Facades\DB::selectOne('select current_database() as name')->name !== $argv[1]) { exit(93); }
$service = app(\App\Services\Demo\SimRepairService::class);
$result = (new ReflectionMethod($service, 'action'))->invoke($service, \App\Models\SimRun::findOrFail($argv[2]),
    $argv[3], 'chair', $argv[4], fn () => app(\App\Services\Demo\SimChairService::class)->complete($argv[4]));
echo json_encode($result);
exit($result['status'] === 'applied' ? 0 : 94);
