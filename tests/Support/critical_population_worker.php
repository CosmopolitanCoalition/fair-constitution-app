<?php
// Independent sweep process; only disposable test databases are accepted.
require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
$config = $input['connection'];
if (! preg_match('/^population_test_[a-f0-9]{16}$/D', $config['database'])) {
    throw new RuntimeException('Refusing non-fixture database.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/tmp/nonexistent-population-fixture-config.php',
    'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.population_child' => array_replace($config, ['name' => 'population_child', 'url' => null])]);
Illuminate\Support\Facades\DB::setDefaultConnection('population_child');
$db = Illuminate\Support\Facades\DB::connection();
if ($db->selectOne('SELECT current_database() AS name')->name !== $config['database']) {
    throw new RuntimeException('Wrong fixture database.');
}
$db->statement("SET statement_timeout = '15s'");
$scans = 0;
$db->listen(function ($query) use (&$scans) {
    if (str_contains($query->sql, 'FROM residency_confirmations rc')) { $scans++; }
});
$service = new class($input['hold'] ?? false) extends App\Services\ActivationService {
    public int $calls = 0;
    public function __construct(private bool $hold) {}
    public function thresholdFor(string $jurisdictionId, ?int $population, App\Services\SettingsResolver $settings): int
    {
        if ($this->hold) {
            // The execution lock must survive ordinary operation commits.
            Illuminate\Support\Facades\DB::transaction(static fn () => Illuminate\Support\Facades\DB::selectOne('SELECT 1'));
            echo "HOLDING\n"; flush();
            if (trim(fgets(STDIN)) !== 'CONTINUE') { throw new RuntimeException('Unexpected fixture input.'); }
            $this->hold = false;
        }
        return 1;
    }
    public function onCriticalPopulation(string $jurisdictionId, int $verifiedResidents, int $threshold): App\Models\JurisdictionActivation
    {
        $this->calls++;
        return new App\Models\JurisdictionActivation;
    }
};
echo json_encode(['ready' => true, 'pid' => $db->selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n"; flush();
if (trim(fgets(STDIN)) !== 'GO') { exit(2); }
// Constructor-less jobs represent old queued payloads with no queue assignment.
$queued = ($input['legacy'] ?? false)
    ? (new ReflectionClass(App\Jobs\Clocks\EvaluateCriticalPopulationJob::class))->newInstanceWithoutConstructor()
    : new App\Jobs\Clocks\EvaluateCriticalPopulationJob;
$job = unserialize(serialize($queued));
$job->handle($service, new App\Services\SettingsResolver);
echo json_encode(['done' => true, 'scans' => $scans, 'crossings' => $service->calls])."\n";
