<?php

// Independent maintenance worker. Refuse every database except the nonce fixture.
require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
$config = $input['connection'];
if (! preg_match('/^sim_board_test_[a-f0-9]{16}$/D', $config['database'])) {
    throw new RuntimeException('Refusing non-fixture database.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/nonexistent-statistics-fixture-config.php',
    'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.board_child' => array_replace($config, ['name' => 'board_child', 'url' => null])]);
Illuminate\Support\Facades\DB::setDefaultConnection('board_child');
$db = Illuminate\Support\Facades\DB::connection();
if ($db->selectOne('SELECT current_database() AS name')->name !== $config['database']) {
    throw new RuntimeException('Wrong fixture database.');
}
Illuminate\Support\Carbon::setTestNow($input['now']);
$analyzes = 0;
$db->listen(function ($query) use (&$analyzes) {
    if (str_starts_with($query->sql, 'ANALYZE ')) {
        $analyzes++;
    }
});
$service = new class($input['hold']) extends App\Services\Demo\SimulationStatisticsMaintenance
{
    public ?array $result = null;

    public function __construct(private bool $hold) {}

    protected function analyze(Illuminate\Database\Connection $db, array $budgets): void
    {
        if ($this->hold) {
            echo "HOLDING\n";
            flush();
            if (trim(fgets(STDIN)) !== 'CONTINUE') {
                throw new RuntimeException('Unexpected fixture input.');
            }
        }
        parent::analyze($db, $budgets);
    }

    public function check(string $target = self::TARGET): array
    {
        return $this->result = parent::check($target);
    }
};
echo json_encode(['ready' => true, 'pid' => $db->selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n";
flush();
if (trim(fgets(STDIN)) !== 'GO') {
    exit(2);
}
$job = unserialize(serialize(new App\Jobs\MaintainSimulationStatisticsJob));
$job->handle($service);
echo json_encode(['done' => true, 'result' => $service->result, 'analyzes' => $analyzes])."\n";
